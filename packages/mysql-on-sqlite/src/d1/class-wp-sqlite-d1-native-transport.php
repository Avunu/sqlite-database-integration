<?php declare(strict_types = 1);

/**
 * A D1 transport backed by the native client PHP extension.
 *
 * The "wp_d1_client" extension pre-declares the WP_SQLite_D1_Native_Client
 * class, which sends requests through a process-global HTTP connection pool
 * that persists across PHP requests. This amortizes TCP and TLS handshakes
 * over all requests a worker process serves, which the pure-PHP cURL
 * transport cannot do.
 *
 * This file is loaded only when the extension is present.
 */
class WP_SQLite_D1_Native_Transport extends WP_SQLite_D1_Native_Client implements WP_SQLite_D1_Transport_Interface {
	use WP_SQLite_D1_Protocol;

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
		parent::__construct(
			rtrim( $url, '/' ),
			$token,
			(int) ( $options['timeout_ms'] ?? WP_SQLite_D1_HTTP_Transport::DEFAULT_TIMEOUT_MS ),
			(int) ( $options['connect_timeout_ms'] ?? WP_SQLite_D1_HTTP_Transport::DEFAULT_CONNECT_TIMEOUT_MS )
		);
	}

	/**
	 * Send an HTTP POST request using the native client.
	 *
	 * @param  string $path The endpoint path.
	 * @param  string $json The JSON request body.
	 * @return array{0: int, 1: string} The HTTP status and response body.
	 * @throws WP_SQLite_D1_Exception When the request fails.
	 */
	protected function send( string $path, string $json ): array {
		try {
			$response_body = $this->request( $path, $json );
		} catch ( WP_SQLite_D1_Exception $e ) {
			throw $e;
		} catch ( Exception $e ) {
			throw WP_SQLite_D1_Exception::from_transport_failure( $e->getMessage(), $e );
		}
		return array( $this->response_status(), $response_body );
	}
}
