# Turso backend

A connection backend that runs the MySQL-on-SQLite driver against a
[Turso](https://github.com/tursodatabase/turso) database, in either of two shapes.

**All primary.** Every statement goes to the primary over "SQL over HTTP". This is
the control plane — wp-admin, cron, deployment tooling — which has to read its own
writes immediately. Co-locate the primary: at ~156 µs per statement a
200-statement admin page lands around 31 ms, which a WAN round trip per statement
would not.

**Snapshot reads, primary writes.** Reads come from a local SQLite file and writes
go to the primary. The first write (or the first transactional statement) latches
the rest of the PHP request to the primary, so it reads its own writes. This is
the public front end: rendering a page never touches the network. Measured at
22 ms per page and ~41 requests/second per vCPU, indistinguishable from reading
the database file directly.

## Why a snapshot rather than the replica itself

Turso's embedded replica cannot be read by `pdo_sqlite`. Turso holds a POSIX write
lock on the whole replica file for the lifetime of its connection — not just while
syncing — so SQLite reports `database is locked (5)`, and neither a busy timeout
nor `mode=ro` helps. There is no protocol to negotiate either: turso_core
coordinates its WAL across processes through a `.tshm` file while SQLite uses
`-shm`. Disabling turso_core's lock (`LIMBO_DISABLE_FILE_LOCK`) makes it worse —
the reader then serves silently stale data forever, with `integrity_check ok`
throughout.

So a separate publisher process keeps a private replica and periodically hands
over a consistent standalone database: `sync`, then `VACUUM INTO` a temporary
file, then `rename` it into place. Readers holding the old inode finish
undisturbed. The front end is stale by at most one publish interval.

Publish in **rollback-journal** mode. A WAL-mode snapshot makes SQLite create
`-wal`/`-shm` beside it, and those are keyed by path rather than inode, so they
would outlive the rename and describe the previous snapshot. The snapshot can
then also be read-only: verified that every public path renders with the file
`chmod 444` (the plugin does want a writable *directory*, for its `index.php`
and `.htaccess`).

## What Turso gives us, and what it does not

Everything here was established by probing `tursodb --sync-server`, not assumed.

| | |
|---|---|
| Write metadata | **Missing.** Every statement reports `affected_row_count: 0` and `last_insert_rowid: null`. The transport asks for `changes()` and `last_insert_rowid()` in the *same* pipeline request, which costs no extra round trip and is consistent because the server holds its connection for the whole request. |
| Named parameters | **Bind silently to NULL.** Positional `?` only, which is what the driver emits. |
| Batches | **Not atomic** on their own, unlike D1's: steps that ran before a failure keep their effects. The transport spells the transaction out as `BEGIN` / conditional `COMMIT` / conditional `ROLLBACK` steps. |
| Interactive transactions | **Unusable.** A `BEGIN` in one HTTP request stays open on the connection the server shares between clients, and every later `BEGIN` anywhere fails with "cannot start a transaction within a transaction". Reported as unsupported; atomicity comes from `execute_batch()`. |
| Temporary tables | Reported unsupported — they would live on that same shared connection. |
| User-defined functions | Impossible remotely; the driver's UDF-less rewrites are used. |
| PRAGMA | **Works**, `foreign_keys` included, and persists across requests. Passed straight through rather than emulated as the D1 backend has to. Note that two clients of one server share the setting. |
| Bound parameters | Not capped the way D1's are: 1000 in one statement is fine. Inlining remains only as a safety valve. |

The replica connection reports the **primary's** capabilities, not the snapshot's.
The snapshot could offer transactions, savepoints, temporary tables and
user-defined functions, but a statement may be routed to either side, so the
driver has to emit SQL that works on both.

## Files

| | |
|---|---|
| `class-wp-sqlite-turso-connection.php` | The primary connection, implementing `WP_SQLite_Connection_Interface`. |
| `class-wp-sqlite-turso-replica-connection.php` | Snapshot reads, primary writes, and the latch. |
| `trait-wp-sqlite-turso-protocol.php` | The pipeline protocol: the `changes()` ride-along and batch atomicity. |
| `class-wp-sqlite-turso-http-transport.php` | Pure-PHP cURL transport, one reusable handle. |
| `class-wp-sqlite-turso-response.php` | The value codec. |
| `class-wp-sqlite-turso-exception.php` | Errors reshaped to PDO SQLite's. |
| `db.copy` | The `wp-content/db.php` drop-in. |

## Usage

```php
require_once __DIR__ . '/src/turso/load.php';

// All-primary, for the control plane.
$connection = wp_sqlite_turso_create_connection( 'http://127.0.0.1:8080' );

// Snapshot reads and primary writes, for the front end.
$connection = wp_sqlite_turso_create_connection(
    'libsql://db-org.turso.io',
    $token,
    '/var/lib/wordpress/snapshot.db'
);

$driver = new WP_SQLite_Driver( $connection, 'wordpress' );
```

For WordPress, copy `db.copy` to `wp-content/db.php` and set `WP_TURSO_URL`
(plus `WP_TURSO_SNAPSHOT` on the front end). The drop-in's header lists every
setting.

## Tests

```
WP_SQLITE_TEST_BACKEND=turso composer run test
```

This runs the driver suite against `WP_SQLite_Turso_Connection` over
`WP_SQLite_Turso_Fake_Transport`, which enforces each of the behaviours in the
table above against a local SQLite database — and round-trips values through the
real protocol codec, so its type handling is exercised rather than approximated.

The Turso backend's failures are currently a **strict subset** of the D1
backend's: 60 tests fail on both, one fails only on D1, and none fail only on
Turso. Those 60 are pre-existing gaps in the remote-backend path rather than
anything Turso-specific, and they group into: tests needing a real PDO SQLite
handle, `WP_PDO_Array_Statement` methods that raise "Not implemented"
(`bindColumn`, iteration, `errorInfo`), transaction and savepoint tests that are
not on the skip list, temporary-table tests likewise, and the UDF-less rewrite
path calling `wp_die()` where WordPress is not loaded.
