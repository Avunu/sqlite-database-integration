//! wp_d1_client: a native HTTP client for the Cloudflare D1 proxy protocol.
//!
//! The extension pre-declares the `WP_SQLite_D1_Native_Client` PHP class,
//! which the SQLite driver's D1 backend extends when the extension is
//! loaded (see `packages/mysql-on-sqlite/src/d1/load.php`).
//!
//! Its purpose is to own what PHP userland cannot: a process-global HTTP
//! connection pool (TCP keep-alive, TLS session reuse, and HTTP/2) that
//! persists across PHP requests, amortizing connection handshakes over all
//! requests a worker process serves.
//!
//! # Lifecycle and safety
//!
//! The pool is initialized lazily on the first request — never at module
//! startup (MINIT). Process managers like PHP-FPM fork workers after MINIT,
//! and background threads (such as the connection pool's I/O driver) do not
//! survive a fork. A process ID guard additionally rebuilds the pool if a
//! fork is detected after initialization.
//!
//! Under threaded runtimes (ZTS, e.g. FrankenPHP), the pool is shared by
//! all PHP threads; `reqwest::blocking::Client` is `Send + Sync`.

use ext_php_rs::exception::PhpException;
use ext_php_rs::prelude::*;
use std::collections::HashMap;
use std::process;
use std::sync::{Mutex, OnceLock};
use std::time::Duration;

/// The process-global connection pool, keyed by client configuration.
static POOL: OnceLock<Mutex<HashMap<PoolKey, PoolEntry>>> = OnceLock::new();

#[derive(Clone, Hash, PartialEq, Eq)]
struct PoolKey {
    timeout_ms: u64,
    connect_timeout_ms: u64,
}

struct PoolEntry {
    /// The process ID that created the client. When it differs from the
    /// current process ID, the process was forked and the client (whose
    /// I/O driver thread did not survive the fork) must be rebuilt.
    pid: u32,
    client: reqwest::blocking::Client,
}

/// Get a pooled HTTP client for the given configuration, creating it on
/// first use in the current process.
fn pooled_client(timeout_ms: u64, connect_timeout_ms: u64) -> Result<reqwest::blocking::Client, String> {
    let pool = POOL.get_or_init(|| Mutex::new(HashMap::new()));
    let mut entries = pool.lock().map_err(|_| "connection pool lock is poisoned".to_string())?;

    let pid = process::id();
    let key = PoolKey {
        timeout_ms,
        connect_timeout_ms,
    };

    if let Some(entry) = entries.get(&key) {
        if entry.pid == pid {
            return Ok(entry.client.clone());
        }
    }

    let client = reqwest::blocking::Client::builder()
        .timeout(Duration::from_millis(timeout_ms))
        .connect_timeout(Duration::from_millis(connect_timeout_ms))
        .pool_idle_timeout(Duration::from_secs(90))
        .pool_max_idle_per_host(4)
        .tcp_keepalive(Duration::from_secs(60))
        .build()
        .map_err(|e| format!("failed to build the HTTP client: {e}"))?;

    entries.insert(
        key,
        PoolEntry {
            pid,
            client: client.clone(),
        },
    );
    Ok(client)
}

fn client_error(message: String) -> PhpException {
    PhpException::default(format!("wp_d1_client: {message}"))
}

/// The native D1 proxy HTTP client.
///
/// This is a thin, protocol-agnostic transport: requests and responses are
/// opaque strings (JSON is encoded and decoded in PHP), keeping the
/// PHP-to-native boundary a single string in each direction.
#[php_class]
#[php(name = "WP_SQLite_D1_Native_Client")]
pub struct WpSqliteD1NativeClient {
    endpoint: String,
    token: Option<String>,
    timeout_ms: u64,
    connect_timeout_ms: u64,
    last_status: u16,
}

#[php_impl]
#[php(change_method_case = "snake_case")]
impl WpSqliteD1NativeClient {
    pub fn __construct(
        endpoint: String,
        token: Option<String>,
        timeout_ms: Option<i64>,
        connect_timeout_ms: Option<i64>,
    ) -> Self {
        Self {
            endpoint: endpoint.trim_end_matches('/').to_string(),
            token,
            timeout_ms: timeout_ms.unwrap_or(30_000).max(1) as u64,
            connect_timeout_ms: connect_timeout_ms.unwrap_or(3_000).max(1) as u64,
            last_status: 0,
        }
    }

    /// POST a JSON body to an endpoint path and return the raw response
    /// body. Throws on transport failures; HTTP error statuses return the
    /// body normally and are reported by `response_status()`.
    pub fn request(&mut self, path: String, json_body: String) -> PhpResult<String> {
        let client = pooled_client(self.timeout_ms, self.connect_timeout_ms).map_err(client_error)?;

        let mut request = client
            .post(format!("{}{}", self.endpoint, path))
            .header("Content-Type", "application/json")
            .header("Accept", "application/json")
            .body(json_body);
        if let Some(token) = &self.token {
            request = request.bearer_auth(token);
        }

        let response = request
            .send()
            .map_err(|e| client_error(format!("request failed: {e}")))?;
        self.last_status = response.status().as_u16();

        response
            .text()
            .map_err(|e| client_error(format!("failed to read the response body: {e}")))
    }

    /// The HTTP status code of the last request.
    pub fn response_status(&self) -> i64 {
        i64::from(self.last_status)
    }

    /// The extension version.
    pub fn version() -> String {
        env!("CARGO_PKG_VERSION").to_string()
    }
}

#[php_module]
pub fn get_module(module: ModuleBuilder) -> ModuleBuilder {
    module.class::<WpSqliteD1NativeClient>()
}
