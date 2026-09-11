//! Publishes a readable SQLite snapshot of a Turso database.
//!
//! A live Turso embedded replica cannot be read by anything but Turso. It holds
//! an exclusive POSIX write lock on its database file for the lifetime of the
//! connection -- not just while syncing -- and coordinates its WAL across
//! processes through a `.tshm` file that SQLite knows nothing about. So
//! `pdo_sqlite` opening a live replica gets `database is locked`, and with
//! turso_core's lock disabled it silently reads a stale snapshot forever, which
//! is worse.
//!
//! This process owns the replica and hands PHP a plain SQLite file instead:
//!
//! ```text
//! pull  ->  VACUUM INTO <tmp>  ->  rollback-journal  ->  read-only  ->  rename
//! ```
//!
//! `VACUUM INTO` writes a consistent, standalone, compacted database. The rename
//! is atomic, so a reader sees either the whole old snapshot or the whole new
//! one, and a reader holding the old inode finishes undisturbed.
//!
//! The journal-mode conversion matters: `VACUUM INTO` emits a WAL-mode file, and
//! a WAL reader creates `-wal`/`-shm` beside it. SQLite keys those by path rather
//! than inode, so they would outlive the rename and describe the *previous*
//! snapshot. A rollback-journal database needs no sidecars at all.

use std::env;
use std::fs;
use std::io::{Seek, SeekFrom, Write};
use std::path::{Path, PathBuf};
use std::process::ExitCode;
use std::time::{Duration, Instant};

/// Byte offsets of the file-format write and read versions in the SQLite header.
///
/// See <https://sqlite.org/fileformat2.html#file_format_version_numbers>. The
/// value is 1 for a legacy rollback journal and 2 for WAL.
const HEADER_VERSION_OFFSET: u64 = 18;
const FORMAT_LEGACY_JOURNAL: u8 = 1;

/// Permissions for a published snapshot: readable by everyone, writable by none.
///
/// The reader never needs to write -- verified against a real WordPress front
/// end, where every public path renders with the file read-only. Note that the
/// *directory* must stay writable: the SQLite integration plugin drops an
/// `index.php` and `.htaccess` beside the database and health-checks for them.
const SNAPSHOT_MODE: u32 = 0o444;

struct Config {
    replica: PathBuf,
    published: PathBuf,
    url: String,
    token: Option<String>,
    interval: Option<Duration>,
}

fn usage() -> &'static str {
    "\
usage: turso-snapshot-publisher --replica <path> --published <path> --url <url>
                               [--token <token>] [--interval <seconds>] [--once]

  --replica    Where to keep the private embedded replica. Persisting it across
               runs is what keeps each pull incremental.
  --published  The snapshot PHP reads. Replaced atomically.
  --url        The Turso database URL.
  --token      Auth token, if the server requires one. TURSO_AUTH_TOKEN is also
               read, which keeps it out of the process list.
  --interval   Seconds between publishes. Omit, or pass --once, to publish once
               and exit.
"
}

fn parse_args() -> Result<Config, String> {
    let mut replica = None;
    let mut published = None;
    let mut url = None;
    let mut token = env::var("TURSO_AUTH_TOKEN").ok();
    let mut interval = None;
    let mut once = false;

    let mut args = env::args().skip(1);
    while let Some(arg) = args.next() {
        let mut value = |name: &str| -> Result<String, String> {
            args.next()
                .ok_or_else(|| format!("{name} needs a value"))
        };
        match arg.as_str() {
            "--replica" => replica = Some(PathBuf::from(value("--replica")?)),
            "--published" => published = Some(PathBuf::from(value("--published")?)),
            "--url" => url = Some(value("--url")?),
            "--token" => token = Some(value("--token")?),
            "--interval" => {
                let seconds: u64 = value("--interval")?
                    .parse()
                    .map_err(|_| "--interval must be a whole number of seconds".to_string())?;
                interval = Some(Duration::from_secs(seconds));
            }
            "--once" => once = true,
            "-h" | "--help" => return Err(usage().to_string()),
            other => return Err(format!("unknown argument: {other}\n\n{}", usage())),
        }
    }

    Ok(Config {
        replica: replica.ok_or("--replica is required")?,
        published: published.ok_or("--published is required")?,
        url: url.ok_or("--url is required")?,
        token: token.filter(|t| !t.is_empty()),
        interval: if once { None } else { interval },
    })
}

/// Turn a freshly vacuumed database into a rollback-journal one.
///
/// Safe precisely because the file comes from `VACUUM INTO`: it has no WAL to
/// lose, so the two header bytes are the whole of the conversion. This is what
/// `PRAGMA journal_mode = DELETE` writes, and Turso will not run that pragma.
fn set_rollback_journal(path: &Path) -> std::io::Result<()> {
    let mut file = fs::OpenOptions::new().write(true).open(path)?;
    file.seek(SeekFrom::Start(HEADER_VERSION_OFFSET))?;
    file.write_all(&[FORMAT_LEGACY_JOURNAL, FORMAT_LEGACY_JOURNAL])?;
    file.sync_all()
}

async fn publish(db: &turso::sync::Database, config: &Config) -> Result<(String, u64), String> {
    let started = Instant::now();

    let pulled = db.pull().await.map_err(|e| format!("pull failed: {e}"))?;
    let pull_ms = started.elapsed().as_secs_f64() * 1000.0;

    // Write beside the published path so the rename stays within one filesystem.
    let mut tmp = config.published.clone().into_os_string();
    tmp.push(".new");
    let tmp = PathBuf::from(tmp);
    for path in [&tmp, &PathBuf::from(format!("{}-wal", tmp.display()))] {
        let _ = fs::remove_file(path);
    }

    let vacuum_started = Instant::now();
    let conn = db
        .connect()
        .await
        .map_err(|e| format!("could not connect to the replica: {e}"))?;
    conn.execute(
        // The path is ours, not user input, but quote it the way SQL requires.
        &format!("VACUUM INTO '{}'", tmp.display().to_string().replace('\'', "''")),
        (),
    )
    .await
    .map_err(|e| format!("VACUUM INTO failed: {e}"))?;
    let vacuum_ms = vacuum_started.elapsed().as_secs_f64() * 1000.0;

    // VACUUM INTO can leave an empty -wal beside its output.
    let _ = fs::remove_file(format!("{}-wal", tmp.display()));
    let _ = fs::remove_file(format!("{}-shm", tmp.display()));

    set_rollback_journal(&tmp).map_err(|e| format!("could not set the journal mode: {e}"))?;

    #[cfg(unix)]
    {
        use std::os::unix::fs::PermissionsExt;
        fs::set_permissions(&tmp, fs::Permissions::from_mode(SNAPSHOT_MODE))
            .map_err(|e| format!("could not set permissions: {e}"))?;
    }

    let size = fs::metadata(&tmp).map_err(|e| format!("could not stat the snapshot: {e}"))?.len();
    fs::rename(&tmp, &config.published)
        .map_err(|e| format!("could not publish the snapshot: {e}"))?;

    Ok((
        format!(
            "pull {:.1} ms{}, vacuum {:.1} ms, total {:.1} ms, {} bytes",
            pull_ms,
            if pulled { "" } else { " (no change)" },
            vacuum_ms,
            started.elapsed().as_secs_f64() * 1000.0,
            size
        ),
        size,
    ))
}

#[tokio::main(flavor = "current_thread")]
async fn main() -> ExitCode {
    let config = match parse_args() {
        Ok(config) => config,
        Err(message) => {
            eprintln!("{message}");
            return ExitCode::from(2);
        }
    };

    if let Some(parent) = config.replica.parent() {
        if !parent.as_os_str().is_empty() {
            let _ = fs::create_dir_all(parent);
        }
    }

    let mut builder = turso::sync::Builder::new_remote(
        &config.replica.display().to_string(),
    )
    .with_remote_url(config.url.clone())
    .with_client_name("turso-snapshot-publisher")
    .bootstrap_if_empty(true)
    // VACUUM is gated behind this; it is how the snapshot is written.
    .experimental_vacuum(true);
    if let Some(token) = &config.token {
        builder = builder.with_auth_token(token.clone());
    }

    let db = match builder.build().await {
        Ok(db) => db,
        Err(e) => {
            eprintln!("could not open the replica at {}: {e}", config.replica.display());
            return ExitCode::FAILURE;
        }
    };

    println!(
        "publisher: replica={} published={} url={}",
        config.replica.display(),
        config.published.display(),
        config.url
    );

    let Some(interval) = config.interval else {
        return match publish(&db, &config).await {
            Ok((report, _)) => {
                println!("published: {report}");
                ExitCode::SUCCESS
            }
            Err(message) => {
                eprintln!("{message}");
                ExitCode::FAILURE
            }
        };
    };

    // A failed publish must not end the loop: the snapshot already in place stays
    // servable, so the right response to a blip is to keep trying.
    let mut cycle: u64 = 0;
    loop {
        cycle += 1;
        match publish(&db, &config).await {
            Ok((report, _)) => println!("cycle {cycle}: {report}"),
            Err(message) => eprintln!("cycle {cycle}: {message}"),
        }

        tokio::select! {
            _ = tokio::time::sleep(interval) => {}
            _ = tokio::signal::ctrl_c() => {
                println!("publisher: stopping");
                return ExitCode::SUCCESS;
            }
        }
    }
}
