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
	 * The last D1 session bookmark received from the server.
	 *
	 * @var string|null
	 */
	private $bookmark;

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
	 * Execute a single SQL statement. See the transport interface.
	 *
	 * @param  string $sql    The SQL statement to execute.
	 * @param  array  $params Positional query parameters.
	 * @return array          The result.
	 * @throws WP_SQLite_D1_Exception When the execution or transport fails.
	 */
	public function query( string $sql, array $params = array() ): array {
		$body = $this->request(
			'/v1/query',
			array(
				'sql'    => $sql,
				'params' => WP_SQLite_D1_Response::encode_params( $params ),
			)
		);
		return WP_SQLite_D1_Response::decode_result( $body );
	}

	/**
	 * Execute a batch of SQL statements atomically. See the transport interface.
	 *
	 * @param  array<int, array{0: string, 1?: array}> $statements The statements to execute.
	 * @return array[] One result per statement.
	 * @throws WP_SQLite_D1_Exception When the execution or transport fails.
	 */
	public function batch( array $statements ): array {
		$encoded = array();
		foreach ( $statements as $statement ) {
			$encoded[] = array(
				'sql'    => $statement[0],
				'params' => WP_SQLite_D1_Response::encode_params( $statement[1] ?? array() ),
			);
		}

		$body = $this->request( '/v1/batch', array( 'statements' => $encoded ) );
		if ( ! is_array( $body['results'] ?? null ) ) {
			throw WP_SQLite_D1_Exception::from_transport_failure(
				'The D1 proxy returned a batch response with an invalid shape.'
			);
		}

		$results = array();
		foreach ( $body['results'] as $result ) {
			$results[] = WP_SQLite_D1_Response::decode_result( $result );
		}
		return $results;
	}

	/**
	 * Get the last D1 session bookmark received from the server.
	 *
	 * @return string|null The last session bookmark, if any.
	 */
	public function get_bookmark(): ?string {
		return $this->bookmark;
	}

	/**
	 * Seed the D1 session bookmark for subsequent requests.
	 *
	 * @param string|null $bookmark A session bookmark.
	 */
	public function set_bookmark( ?string $bookmark ): void {
		$this->bookmark = $bookmark;
	}

	/**
	 * Send a request to the D1 proxy and return the decoded response body.
	 *
	 * @param  string $path    The endpoint path, e.g. "/v1/query".
	 * @param  array  $payload The JSON-encodable request payload.
	 * @return array           The decoded successful response body.
	 * @throws WP_SQLite_D1_Exception When the request or the statement fails.
	 */
	private function request( string $path, array $payload ): array {
		// Request a D1 session for sequential read consistency.
		$payload['session'] = null !== $this->bookmark
			? array( 'bookmark' => $this->bookmark )
			: array( 'constraint' => 'first-unconstrained' );

		$json = json_encode( $payload );
		if ( false === $json ) {
			throw WP_SQLite_D1_Exception::from_transport_failure(
				'Failed to encode the request payload as JSON: ' . json_last_error_msg()
			);
		}

		list( $status, $response_body ) = $this->send( $path, $json );

		$body = json_decode( $response_body, true );
		if ( ! is_array( $body ) ) {
			throw WP_SQLite_D1_Exception::from_transport_failure(
				sprintf( 'The D1 proxy returned a non-JSON response with HTTP status %d.', $status )
			);
		}

		if ( isset( $body['bookmark'] ) && is_string( $body['bookmark'] ) ) {
			$this->bookmark = $body['bookmark'];
		}

		if ( 200 !== $status || true !== ( $body['success'] ?? false ) ) {
			throw WP_SQLite_D1_Response::decode_error( $body, $status );
		}
		return $body;
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
	private function send( string $path, string $json ): array {
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
