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

Established by probing both `tursodb --sync-server` and a real Turso Cloud
database, not assumed. Where the two differ, the table says so; the backend is
correct against both.

| | |
|---|---|
| Write metadata | **Missing on the CLI** (`affected_row_count: 0`, `last_insert_rowid: null`); Cloud reports it. The transport asks for `changes()` and `last_insert_rowid()` in the *same* pipeline request either way — no extra round trip, correct on both. |
| Named parameters | **Bind silently to NULL on the CLI**; Cloud binds them. Positional `?` only, which is what the driver emits and works everywhere. |
| Batches | **Not atomic on either**, unlike D1's: steps that ran before a failure keep their effects. The transport spells the transaction out as `BEGIN` / conditional `COMMIT` / conditional `ROLLBACK` steps. Verified atomic on both. |
| Interactive transactions | **Unusable.** On the CLI a `BEGIN` stays open on the connection shared between clients and wedges every later `BEGIN`; on Cloud it is simply discarded with the request. Reported as unsupported either way; atomicity comes from `execute_batch()`. |
| Sessions | **The CLI shares one connection between all clients; Cloud gives each request its own** — except that a kept-alive connection pinned to one node sometimes keeps state, so persistence is nondeterministic. `foreign_keys`, which the driver relies on, is therefore tracked by the connection and sent ahead of every request, always stated explicitly. Verified enforced across requests on Cloud. |
| Errors | Cloud wraps messages as `Tursodb error: <stage> error: <message>`, the CLI as `<stage> error: <message>`. Both layers are stripped so the driver's MySQL identity mapping fires (`no such table` → `42S02` / `1146`, verified on Cloud). |
| Temporary tables | Reported unsupported — on the CLI they would live on the shared connection; on Cloud they would vanish with the request. |
| User-defined functions | Impossible remotely; the driver's UDF-less rewrites are used. |
| Bound parameters | Not capped the way D1's are: 1000 in one statement is fine. Inlining remains only as a safety valve. |

Latency from a home connection to `aws-us-east-1`: **32 ms per warm round trip,
~176 ms per write**. A 26-statement page issued one statement at a time is
~840 ms; a query-heavy admin page was measured at 6.4 s. That is the whole case
for the snapshot on the front end and a co-located primary for the control
plane — same WordPress, same Cloud database, the front page went from 1,302 ms
reading over the wire to **22.6 ms** reading the snapshot.

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

`WP_SQLITE_TEST_BACKEND=turso composer run test` is **green**: 1084 tests, 0
failures, 102 skipped. So is `d1`, and so is the default `pdo` backend — the
remote-backend gaps that used to fail were shared, and closing them fixed both.

The 102 skips are the tests a remote backend genuinely cannot run, and the skip
list names a reason for each: transactions and savepoints (a `BEGIN` cannot
outlive its HTTP request), temporary tables, `REGEXP` and seeded `RAND(N)` and
the other PHP-evaluated functions (no user-defined functions), detailed column
metadata, the two tests that reach past the connection to a PDO SQLite handle
that is not there, and `PDO::FETCH_NAMED`, which returns numeric column names as
*string* array keys — something PDO builds below the language and a pure-PHP
array cannot represent.
