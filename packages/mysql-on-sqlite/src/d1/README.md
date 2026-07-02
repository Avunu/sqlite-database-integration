# Cloudflare D1 Backend

This directory implements a [Cloudflare D1](https://developers.cloudflare.com/d1/)
connection backend for the MySQL-on-SQLite driver, letting WordPress (and other
MySQL-based applications using this driver) run against a D1 database.

The MySQL parsing and translation pipeline is unchanged — D1 *is* SQLite. Only
the execution layer differs: instead of a local PDO SQLite connection, SQL is
executed remotely through the [D1 proxy worker](../../../d1-proxy-worker/)
protocol.

```
MySQL query ─→ lexer/parser ─→ translator ─→ WP_SQLite_D1_Connection
                                                    │ HTTP+JSON (/v1/query, /v1/batch)
                              d1-proxy-worker handler ── D1 binding ── D1
```

## Components

| File | Role |
| --- | --- |
| `class-wp-sqlite-d1-connection.php` | `WP_SQLite_Connection_Interface` implementation over a D1 transport |
| `interface-wp-sqlite-d1-transport.php` | The transport contract (query, atomic batch, session bookmarks) |
| `trait-wp-sqlite-d1-protocol.php` | Shared protocol encoding used by all transports |
| `class-wp-sqlite-d1-http-transport.php` | Pure-PHP cURL transport (portable fallback) |
| `class-wp-sqlite-d1-native-transport.php` | Transport backed by the optional [`wp_d1_client`](../../../php-ext-wp-d1-client/) extension (persistent connection pool) |
| `class-wp-sqlite-d1-response.php` | Value encoding (BLOB wrapping, columnar rows) |
| `class-wp-sqlite-d1-exception.php` | PDO-SQLite-shaped errors |
| `db.copy` | A `wp-content/db.php` drop-in connecting WordPress to D1 |

This backend is **not loaded by default**; applications load it explicitly via
`src/d1/load.php` (the WordPress drop-in does this).

## WordPress setup

1. Deploy the D1 proxy: either embedded in your Cloudflare Containers Worker
   (recommended; see the [d1-proxy-worker README](../../../d1-proxy-worker/README.md))
   or as a standalone Worker with a bearer secret.
2. Install the SQLite Database Integration plugin files.
3. Copy `db.copy` to `wp-content/db.php`.
4. Configure via `wp-config.php` constants or environment variables:

| Name | Meaning |
| --- | --- |
| `WP_D1_PROXY_URL` | Required. E.g. `http://d1.internal` (Containers) or a Worker URL |
| `WP_D1_PROXY_TOKEN` | Bearer token of a standalone proxy deployment |
| `WP_D1_SCHEMA_CACHE` | Cache schema information reads per request (default: true) |
| `WP_D1_HTTP_TIMEOUT_MS` | HTTP request timeout (default: 30000) |
| `WP_D1_TRANSACTION_FALLBACK` | `warn` (default), `error`, or `ignore` |

## How D1 constraints are handled

D1 is a stateless, per-request SQLite service. The connection reports no
optional capabilities, and the driver adapts:

- **No interactive transactions.** Single statements are atomic on D1, and
  multi-statement DDL procedures (e.g. ALTER TABLE table recreation) execute
  as atomic [batches](https://developers.cloudflare.com/d1/worker-api/d1-database/#batch).
  MySQL `START TRANSACTION`/`COMMIT`/`ROLLBACK` follow the
  `WP_D1_TRANSACTION_FALLBACK` policy — WordPress core never uses them.
- **No user-defined functions.** MySQL functions normally emulated with PHP
  callbacks are rewritten to plain SQLite expressions (date/time functions,
  `IF`, `FIELD`, `LEAST`/`GREATEST`, `LOCATE`, …), or evaluated in PHP for
  constant arguments (`MD5`, `TO_BASE64`, `LIKE BINARY` patterns, …).
- **`PRAGMA foreign_keys`** is emulated: D1 always enforces foreign keys;
  when the driver disables them for table recreation, enforcement is
  deferred with `PRAGMA defer_foreign_keys` inside the batch.
- **Bound parameter limit.** D1 accepts at most 100 bound parameters per
  statement; statements beyond a threshold have parameters inlined as
  literals (WordPress `IN (...)` lists can be large).
- **Schema information reads are memoized** within a request (the driver
  consults its information schema tables constantly; over a remote transport
  each read is a round trip). Disable with `WP_D1_SCHEMA_CACHE`.
- **Session bookmarks** are threaded through all requests for
  [read-replication consistency](https://developers.cloudflare.com/d1/best-practices/read-replication/).

### Known limitations

- `REGEXP`/`RLIKE` and seeded `RAND(N)` are not supported (clear errors).
- PHP-evaluated functions (`MD5`, `TO_BASE64`, `FROM_BASE64`, `REVERSE`,
  `INET_ATON`, `LIKE BINARY`) require constant arguments.
- `CREATE TEMPORARY TABLE` is not supported.
- Strict-mode rejections work, but exact MySQL error message texts are not
  reproduced (a malformed-JSON error raises the rejection instead).
- Detailed column metadata (`wpdb::get_col_info()`) is degraded: the D1
  protocol does not carry per-column table origins.
- SQLite `INTEGER` values beyond ±2^53 lose precision in JSON transport.

## Testing

```sh
# Unit + semantics tests (fake D1 transport, no network):
composer run test tests/WP_SQLite_D1_Connection_Tests.php \
    tests/WP_SQLite_D1_Connection_Conformance_Tests.php \
    tests/WP_SQLite_Driver_No_UDF_Tests.php

# The full driver suites against the D1 backend:
WP_SQLITE_TEST_BACKEND=d1 composer run test

# End to end against a real local D1 (no Cloudflare credentials):
( cd ../../d1-proxy-worker && npx wrangler dev )  # terminal 1
php ../../../.github/workflows/d1-transport-smoke.php http://127.0.0.1:8787 curl
```
