# Turso snapshot publisher

Publishes a readable SQLite snapshot of a Turso database, for the Turso backend's
read path (`packages/mysql-on-sqlite/src/turso/`).

```
turso-snapshot-publisher --replica /var/lib/wp/private/replica.db \
                         --published /var/lib/wp/database/snapshot.db \
                         --url libsql://db-org.turso.io \
                         --interval 10
```

`TURSO_AUTH_TOKEN` is read from the environment so the token stays out of the
process list; `--token` also works. Omit `--interval` (or pass `--once`) to
publish once and exit, which is what a build or a deploy hook wants.

## Why this exists

A live Turso embedded replica cannot be read by anything but Turso. It holds an
exclusive POSIX write lock on its database file for the lifetime of the
connection — not only while syncing — and coordinates its WAL across processes
through a `.tshm` file that SQLite knows nothing about. So `pdo_sqlite` opening a
live replica gets `database is locked`, and with turso_core's lock disabled
(`LIMBO_DISABLE_FILE_LOCK`) it silently reads a stale snapshot forever, which is
worse than an error.

This process owns the replica and hands PHP a plain file instead:

```
pull  ->  VACUUM INTO <tmp>  ->  rollback-journal  ->  read-only  ->  rename
```

Each step earns its place:

- **`pull`** is incremental as long as the `--replica` directory persists across
  runs. Keep it on durable storage, private to this process.
- **`VACUUM INTO`** writes a consistent, standalone, compacted database. Closing
  the connection is *not* an alternative: with pooling on it does not checkpoint,
  and snapshots taken that way fail `integrity_check` while the `-wal` grows
  without bound.
- **Rollback-journal** mode, by writing the two file-format version bytes in the
  SQLite header. `VACUUM INTO` emits a WAL-mode file, and a WAL reader creates
  `-wal`/`-shm` beside it — SQLite keys those by *path*, not inode, so they would
  outlive the rename and describe the previous snapshot. Turso will not run
  `PRAGMA journal_mode = DELETE`, and after a vacuum there is no WAL to lose, so
  the two bytes are the whole conversion.
- **Read-only** (mode 444) because the reader never writes. The *directory* must
  stay writable: the SQLite integration plugin drops an `index.php` and
  `.htaccess` beside the database and health-checks for them.
- **`rename`** is atomic, so a reader sees either the whole old snapshot or the
  whole new one, and one holding the old inode finishes undisturbed.

A failed cycle is logged and the loop continues: the snapshot already in place
stays servable, so the right response to a blip is to keep trying.

## Cost and freshness

On the installed WordPress database used for development (467 KB, 36 tables):
**10–15 ms per cycle**, of which the vacuum is ~11 ms and an incremental pull
0.4–4 ms. The vacuum is O(database size), so the interval sets both the staleness
window and the steady-state cost.

The front end is stale by at most one interval. That is a real semantic change
and belongs in a site's documentation; it composes with the page cache, which
already means the public site lags the database by a bounded amount.
