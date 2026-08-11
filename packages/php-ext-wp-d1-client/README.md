# WP D1 Client PHP Extension

`wp_d1_client` is an optional native PHP extension for the SQLite Database
Integration project's Cloudflare D1 backend. It provides a native HTTP client
for the D1 proxy protocol, while the pure-PHP cURL transport remains the
portable fallback.

When the extension is loaded before `packages/mysql-on-sqlite/src/d1/load.php`,
it registers the native `WP_SQLite_D1_Native_Client` base class, and the D1
backend prefers the `WP_SQLite_D1_Native_Transport` over the pure-PHP
`WP_SQLite_D1_HTTP_Transport`.

## Why a native client

The extension owns what PHP userland cannot: a **process-global HTTP
connection pool** (TCP keep-alive, TLS session reuse, and HTTP/2) that
persists across PHP requests. With the pure-PHP cURL transport, every PHP
request pays new TCP/TLS handshakes to the D1 proxy; with the native client,
they are amortized over all requests a worker process serves.

The client is deliberately a thin transport: requests and responses cross the
PHP-to-native boundary as single strings, and all protocol encoding/decoding
stays in shared PHP code — so the native and cURL transports are behaviorally
identical by construction.

## Lifecycle and safety

- The pool is initialized lazily on the first request — never at module
  startup (MINIT). Process managers like PHP-FPM fork workers after MINIT,
  and background threads do not survive a fork.
- A process ID guard rebuilds the pool if a fork is detected later.
- Under threaded runtimes (ZTS, e.g. FrankenPHP), the pool is safely shared
  by all PHP threads.

## Build the native extension locally

Requirements:

- Rust toolchain.
- PHP development headers and `php-config` (NTS or ZTS).
- libclang, with `LIBCLANG_PATH` pointing at the directory containing the
  libclang shared library when auto-detection is not enough.

```bash
(
	cd packages/php-ext-wp-d1-client
	PHP_CONFIG="$(command -v php-config)" \
	LIBCLANG_PATH=/path/to/libclang \
	cargo build --release
)
```

The resulting shared object is written under `target/release/` as
`libwp_d1_client.so` on Linux or `libwp_d1_client.dylib` on macOS.

From the repository root, load it for verification:

```bash
php -d extension=/absolute/path/to/libwp_d1_client.so packages/mysql-on-sqlite/tests/tools/verify-native-d1-client-extension.php
```

TLS uses rustls (no OpenSSL system dependency).

## End-to-end testing

With a local D1 proxy running (`npx wrangler dev` in
`packages/d1-proxy-worker`), the D1 transport test suites exercise both
transports against a real local D1 database. See the d1-proxy-worker README.

## No WASM/Playground build

Unlike `wp_mysql_parser`, this extension has no WASM build: it exists solely
to own OS sockets and background threads, neither of which the Playground
WASM environment provides. Playground uses the plain local SQLite driver.
