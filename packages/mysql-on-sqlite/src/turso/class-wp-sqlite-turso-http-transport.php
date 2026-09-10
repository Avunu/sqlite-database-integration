<?php declare(strict_types = 1);

/**
 * A Turso transport speaking "SQL over HTTP" using cURL.
 *
 * The transport keeps a single cURL handle for its lifetime, reusing the
 * connection for every statement in one PHP request. That matters more here
 * than it might appear: a statement that has to open its own TCP connection
 * pays the handshake, and against Turso's CLI sync server it used to pay a
 * 10 ms accept sleep as well.
 *
 * This is the portable pure-PHP transport. When the native client extension is
 * loaded, a native transport with a pool that persists across PHP requests is
 * used instead.
 */
class WP_SQLite_Turso_HTTP_Transport implements WP_SQLite_Turso_Transport_Interface {
	use WP_SQLite_Turso_Protocol;

	/**
	 * The default request timeout, in milliseconds.
	 *
	 * @var int
	 */
	const DEFAULT_TIMEOUT_MS = 30000;

	/**
	 * The default connection timeout, in milliseconds.
	 *
	 * @var int
	 */
	const DEFAULT_CONNECT_TIMEOUT_MS = 3000;

	/**
	 * The base URL of the Turso server, e.g. "http://127.0.0.1:8080".
	 *
	 * @var string
	 */
	private $url;

	/**
	 * The bearer token, if the server requires one.
	 *
	 * @var string|null
	 */
	private $token;

	/**
	 * The request timeout, in milliseconds.
	 *
	 * @var int
	 */
	private $timeout_ms;

	/**
	 * The connection timeout, in milliseconds.
	 *
	 * @var int
	 */
	private $connect_timeout_ms;

	/**
	 * The reusable cURL handle.
	 *
	 * @var resource|CurlHandle|null
	 */
	private $curl;

	/**
	 * Constructor.
	 *
	 * @param string      $url     The base URL of the Turso server. A
	 *                             "libsql://" scheme is rewritten to HTTPS.
	 * @param string|null $token   Optional. A bearer token.
	 * @param array       $options {
	 *     Optional. An array of options.
	 *
	 *     @type int    $timeout_ms         The request timeout, in milliseconds.
	 *     @type int    $connect_timeout_ms The connection timeout, in milliseconds.
	 *     @type string $pipeline_path      The pipeline endpoint path.
	 * }
	 */
	public function __construct( string $url, ?string $token = null, array $options = array() ) {
		$this->url                = self::normalize_url( $url );
		$this->token              = $token;
		$this->timeout_ms         = (int) ( $options['timeout_ms'] ?? self::DEFAULT_TIMEOUT_MS );
		$this->connect_timeout_ms = (int) ( $options['connect_timeout_ms'] ?? self::DEFAULT_CONNECT_TIMEOUT_MS );
		if ( isset( $options['pipeline_path'] ) ) {
			$this->pipeline_path = (string) $options['pipeline_path'];
		}
	}

	/**
	 * Normalize a Turso database URL to one cURL can use.
	 *
	 * Turso publishes database URLs with "libsql://" and "turso://" schemes,
	 * which are HTTPS endpoints under another name.
	 *
	 * @param  string $url The configured URL.
	 * @return string      The HTTP(S) URL, without a trailing slash.
	 */
	public static function normalize_url( string $url ): string {
		$url = trim( $url );
		if ( 1 === preg_match( '#^(?:libsql|turso|wss)://#i', $url ) ) {
			$url = preg_replace( '#^[a-z]+://#i', 'https://', $url );
		} elseif ( 1 === preg_match( '#^ws://#i', $url ) ) {
			$url = preg_replace( '#^ws://#i', 'http://', $url );
		} elseif ( 1 !== preg_match( '#^https?://#i', $url ) ) {
			$url = 'https://' . $url;
		}
		return rtrim( $url, '/' );
	}

	/**
	 * Send an HTTP POST request using the reusable cURL handle.
	 *
	 * Requests that fail before a connection is established are retried, as no
	 * statement can have been executed yet. Requests failing after that point
	 * are not retried -- a write may already have been applied.
	 *
	 * @param  string $path The endpoint path.
	 * @param  string $json The JSON request body.
	 * @return array{0: int, 1: string} The HTTP status and response body.
	 * @throws WP_SQLite_Turso_Exception When the request fails.
	 */
	protected function send( string $path, string $json ): array {
		$headers = array(
			'Content-Type: application/json',
			'Accept: application/json',
		);
		if ( null !== $this->token ) {
			$headers[] = 'Authorization: Bearer ' . $this->token;
		}

		$attempts = 0;
		$errno    = 0;
		$message  = '';
		do {
			++$attempts;

			$curl = $this->get_curl_handle();
			curl_setopt( $curl, CURLOPT_URL, $this->url . $path );
			curl_setopt( $curl, CURLOPT_POST, true );
			curl_setopt( $curl, CURLOPT_POSTFIELDS, $json );
			curl_setopt( $curl, CURLOPT_HTTPHEADER, $headers );

			$response_body = curl_exec( $curl );
			if ( false !== $response_body ) {
				return array( (int) curl_getinfo( $curl, CURLINFO_RESPONSE_CODE ), (string) $response_body );
			}

			$errno   = curl_errno( $curl );
			$message = curl_error( $curl );

			// Reset the handle after a failure to get a clean connection.
			$this->curl = null;

			$is_retryable = in_array(
				$errno,
				array( CURLE_COULDNT_CONNECT, CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_RESOLVE_PROXY ),
				true
			);
		} while ( $is_retryable && $attempts < 3 );

		throw WP_SQLite_Turso_Exception::from_transport_failure(
			sprintf( 'The request to Turso failed: %s (cURL error %d).', $message, $errno )
		);
	}

	/**
	 * Get the reusable cURL handle, creating it when needed.
	 *
	 * @return resource|CurlHandle The cURL handle.
	 */
	private function get_curl_handle() {
		if ( null === $this->curl ) {
			$this->curl = curl_init();
			curl_setopt( $this->curl, CURLOPT_RETURNTRANSFER, true );
			curl_setopt( $this->curl, CURLOPT_TIMEOUT_MS, $this->timeout_ms );
			curl_setopt( $this->curl, CURLOPT_CONNECTTIMEOUT_MS, $this->connect_timeout_ms );
			curl_setopt( $this->curl, CURLOPT_TCP_KEEPALIVE, 1 );
			curl_setopt( $this->curl, CURLOPT_TCP_NODELAY, 1 );
		}
		return $this->curl;
	}
}
