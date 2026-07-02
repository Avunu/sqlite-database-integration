<?php declare(strict_types = 1);

/**
 * Shared implementation of the D1 proxy protocol for remote transports.
 *
 * The trait implements the transport interface methods (query, batch, and
 * session bookmark handling) on top of an abstract "send()" method, so that
 * transport implementations only differ in how the bytes are moved.
 */
trait WP_SQLite_D1_Protocol {
	/**
	 * The last D1 session bookmark received from the server.
	 *
	 * @var string|null
	 */
	private $bookmark;

	/**
	 * Send an HTTP POST request to the D1 proxy.
	 *
	 * @param  string $path The endpoint path, e.g. "/v1/query".
	 * @param  string $json The JSON request body.
	 * @return array{0: int, 1: string} The HTTP status and response body.
	 * @throws WP_SQLite_D1_Exception When the request fails.
	 */
	abstract protected function send( string $path, string $json ): array;

	/**
	 * Execute a single SQL statement. See the transport interface.
	 *
	 * @param  string $sql    The SQL statement to execute.
	 * @param  array  $params Positional query parameters.
	 * @return array          The result.
	 * @throws WP_SQLite_D1_Exception When the execution or transport fails.
	 */
	public function query( string $sql, array $params = array() ): array {
		$body = $this->send_payload(
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

		$body = $this->send_payload( '/v1/batch', array( 'statements' => $encoded ) );
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
	private function send_payload( string $path, array $payload ): array {
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
}
