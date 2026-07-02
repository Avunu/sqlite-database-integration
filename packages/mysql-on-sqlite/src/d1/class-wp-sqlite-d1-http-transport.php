<?php declare(strict_types = 1);

/**
 * A D1 transport speaking the proxy protocol over HTTP using cURL.
 *
 * The transport keeps a single cURL handle for its lifetime, reusing the
 * connection (keep-alive, and HTTP/2 when available) for all statements
 * executed within one PHP request.
 *
 * This is the portable pure-PHP transport. When the native client PHP
 * extension is loaded, a native transport with a connection pool that
 * persists across PHP requests is used instead.
 */
class WP_SQLite_D1_HTTP_Transport implements WP_SQLite_D1_Transport_Interface {
	use WP_SQLite_D1_Protocol;

	/**
	 * The default request timeout, in milliseconds.
	 *
	 * This aligns with the 30 second limit of D1 queries.
	 */
	const DEFAULT_TIMEOUT_MS = 30000;

	/**
	 * The default connection timeout, in milliseconds.
	 */
	const DEFAULT_CONNECT_TIMEOUT_MS = 3000;

	/**
	 * The base URL of the D1 proxy, e.g. "http://d1.internal".
	 *
	 * @var string
	 */
	private $url;

	/**
	 * The bearer token for the standalone proxy deployment, if any.
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
	 * @param string      $url     The base URL of the D1 proxy.
	 * @param string|null $token   Optional. A bearer token for the proxy.
	 * @param array       $options {
	 *     Optional. An array of options.
	 *
	 *     @type int $timeout_ms         The request timeout, in milliseconds.
	 *     @type int $connect_timeout_ms The connection timeout, in milliseconds.
	 * }
	 */
	public function __construct( string $url, ?string $token = null, array $options = array() ) {
		$this->url                = rtrim( $url, '/' );
		$this->token              = $token;
		$this->timeout_ms         = (int) ( $options['timeout_ms'] ?? self::DEFAULT_TIMEOUT_MS );
		$this->connect_timeout_ms = (int) ( $options['connect_timeout_ms'] ?? self::DEFAULT_CONNECT_TIMEOUT_MS );
	}

	/**
	 * Send an HTTP POST request using the reusable cURL handle.
	 *
	 * Requests that fail before a connection is established are retried,
	 * as no statement can have been executed yet. Requests failing after
	 * that point are not retried — a write may already have been applied.
	 *
	 * @param  string $path The endpoint path.
	 * @param  string $json The JSON request body.
	 * @return array{0: int, 1: string} The HTTP status and response body.
	 * @throws WP_SQLite_D1_Exception When the request fails.
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
		do {
			$attempts += 1;

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

			// Retry only failures that occur before a connection is made.
			$is_retryable = in_array(
				$errno,
				array( CURLE_COULDNT_CONNECT, CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_RESOLVE_PROXY ),
				true
			);
		} while ( $is_retryable && $attempts < 3 );

		throw WP_SQLite_D1_Exception::from_transport_failure(
			sprintf( 'The request to the D1 proxy failed: %s (cURL error %d).', $message, $errno )
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
			if ( defined( 'CURL_HTTP_VERSION_2TLS' ) ) {
				curl_setopt( $this->curl, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2TLS );
			}
		}
		return $this->curl;
	}
}
