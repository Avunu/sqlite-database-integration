# D1 Proxy Worker

A small Cloudflare Worker handler that exposes a [D1](https://developers.cloudflare.com/d1/)
database over a JSON-over-HTTP protocol, serving as the server-side counterpart
of the SQLite driver's D1 connection backend.

D1 databases are only reachable through Workers bindings. PHP applications
running elsewhere — including in [Cloudflare Containers](https://developers.cloudflare.com/containers/) —
reach D1 through this proxy.

## Deployment modes

### Embedded (recommended for Cloudflare Containers)

Register the handler as an [outbound handler](https://developers.cloudflare.com/containers/platform-details/workers-connections/)
on a virtual hostname of your Container class. The container reaches D1 with
plain HTTP requests to `http://d1.internal`; traffic never leaves the Workers
runtime, the endpoint is unreachable from the internet, and no secret is
required:

```js
import { Container } from '@cloudflare/containers';
import { createD1ProxyHandler } from '@wp-sqlite/d1-proxy-worker';

export class WordPressContainer extends Container {
	defaultPort = 80;

	static outboundByHost = {
		'd1.internal': ( request, env ) =>
			createD1ProxyHandler( () => env.DB, { allowInsecure: true } )( request ),
	};
}
```

### Standalone Worker

For local development, CI, and WordPress hosted outside of Cloudflare
Containers, deploy `src/worker.js` as a dedicated Worker:

```sh
wrangler secret put WP_D1_PROXY_TOKEN   # shared secret; required in production
wrangler deploy
```

Clients authenticate with `Authorization: Bearer <token>`. When no token is
configured, the worker refuses all requests, unless the
`WP_D1_PROXY_ALLOW_INSECURE` variable is `"true"` (local development only).

### Local development

`npx wrangler dev` runs the standalone worker against a real local D1 database
(SQLite under `.wrangler/state`) — no Cloudflare credentials needed.

## Protocol (v1)

All bodies are JSON. SQLite BLOB values are carried as
`{ "$type": "blob", "b64": "<base64>" }` in both directions. JavaScript's
number precision limits integers to ±2^53.

### `POST /v1/query`

```json
{ "sql": "SELECT id, name FROM t WHERE id = ?", "params": [ 1 ] }
```

Response:

```json
{
	"success": true,
	"columns": [ "id", "name" ],
	"rows": [ [ 1, "Alice" ] ],
	"meta": { "changes": 0, "last_row_id": 0, "rows_read": 1, "rows_written": 0, "duration": 0.4, "served_by_primary": true }
}
```

Rows are columnar (`columns` + positional `rows`), preserving duplicate column
names and column order. Reads (`SELECT`, `PRAGMA`, `EXPLAIN`, `WITH`) report
accurate column names, including for empty results, but zeroed write meta.
Writes report accurate `meta.changes` and `meta.last_row_id`.

An optional `"session"` field (`{ "bookmark": "..." }` or
`{ "constraint": "first-unconstrained" }`) executes the statement in a
[D1 session](https://developers.cloudflare.com/d1/best-practices/read-replication/);
the response then includes a `"bookmark"` for read-your-writes consistency
across subsequent requests.

### `POST /v1/batch`

```json
{
	"statements": [
		{ "sql": "INSERT INTO t (name) VALUES (?)", "params": [ "Alice" ] },
		{ "sql": "DROP TABLE old" }
	]
}
```

The statements are executed **atomically** — when any statement fails, D1
rolls back the whole batch. The response carries one result per statement:
`{ "success": true, "results": [ { "columns", "rows", "meta" }, ... ] }`.
The batch size is capped by `WP_D1_PROXY_MAX_BATCH_STATEMENTS` (default 100).

### `GET /v1/health`

Returns `{ "ok": true, "version": "...", "contract": 1 }`. With `?deep=1`,
also executes `SELECT 1` against the database.

### Errors

```json
{
	"success": false,
	"error": { "code": "SQLITE_CONSTRAINT", "message": "D1_ERROR: UNIQUE constraint failed: t.name: SQLITE_CONSTRAINT", "statement_index": 0 }
}
```

- `400` — protocol validation errors (`PROXY_*` codes).
- `401` / `503` — authentication errors.
- `409` — SQL execution errors. The `SQLITE_*` constant embedded in D1 error
  messages is extracted into `code`; the original message is preserved.
- `413` — request body too large.

Note that D1 rejects transaction control statements (`BEGIN`, `COMMIT`,
`ROLLBACK`, savepoints). Batches are the only unit of multi-statement
atomicity.

## Tests

```sh
npm install
npm test    # vitest, runs against a real local D1 database in workerd
```
