<?php declare(strict_types = 1);

/**
 * Shared implementation of Turso's "SQL over HTTP" pipeline protocol.
 *
 * The trait implements the transport interface on top of an abstract "send()"
 * method, so transports differ only in how the bytes are moved.
 *
 * Two Turso behaviours shape this code.
 *
 * Write metadata is missing. Turso reports "affected_row_count": 0 and
 * "last_insert_rowid": null for every statement, so a write that needs either
 * carries "SELECT changes(), last_insert_rowid()" in the *same* pipeline
 * request. The server holds its database connection for the whole request, so
 * those values belong to the write that preceded them, and they cost no extra
 * round trip.
 *
 * Batches are not atomic. Turso's "batch" request type keeps the effects of
 * steps that succeeded before one failed, unlike D1's. Atomicity therefore has
 * to be spelled out: BEGIN as the first step, COMMIT conditional on the last
 * statement succeeding, and ROLLBACK conditional on the COMMIT not running.
 * The transaction cannot outlive the request either -- a BEGIN left open wedges
 * the server's shared connection, and every later BEGIN fails with "cannot
 * start a transaction within a transaction".
 */
trait WP_SQLite_Turso_Protocol {
	/**
	 * The pipeline endpoint path.
	 *
	 * Turso's CLI sync server implements /v2/pipeline; Turso Cloud serves both
	 * v2 and v3. The requests used here are identical under both.
	 *
	 * @var string
	 */
	protected $pipeline_path = '/v2/pipeline';

	/**
	 * Send an HTTP POST request to the Turso server.
	 *
	 * @param  string $path The endpoint path.
	 * @param  string $json The JSON request body.
	 * @return array{0: int, 1: string} The HTTP status and response body.
	 * @throws WP_SQLite_Turso_Exception When the request fails.
	 */
	abstract protected function send( string $path, string $json ): array;

	/**
	 * Execute a single SQL statement. See the transport interface.
	 *
	 * @param  string $sql    The SQL statement to execute.
	 * @param  array  $params Positional query parameters.
	 * @return array          The result.
	 * @throws WP_SQLite_Turso_Exception When the execution or transport fails.
	 */
	public function query( string $sql, array $params = array() ): array {
		$requests = array( $this->execute_request( $sql, $params ) );

		// Writes need metadata Turso does not report; ask for it in the same trip.
		$wants_meta = $this->statement_changes_data( $sql );
		if ( $wants_meta ) {
			$requests[] = $this->execute_request( 'SELECT changes(), last_insert_rowid()' );
		}

		$results = $this->pipeline( $requests );
		$result  = WP_SQLite_Turso_Response::decode_result( $results[0] );

		if ( $wants_meta && isset( $results[1] ) ) {
			$meta = WP_SQLite_Turso_Response::decode_result( $results[1] );
			if ( isset( $meta['rows'][0][0], $meta['rows'][0][1] ) ) {
				$result['meta']['changes']     = (int) $meta['rows'][0][0];
				$result['meta']['last_row_id'] = (int) $meta['rows'][0][1];
			}
		}
		return $result;
	}

	/**
	 * Execute a batch of SQL statements atomically. See the transport interface.
	 *
	 * @param  array<int, array{0: string, 1?: array}> $statements The statements to execute.
	 * @return array[] One result per statement.
	 * @throws WP_SQLite_Turso_Exception When the execution of any statement fails.
	 */
	public function batch( array $statements ): array {
		if ( array() === $statements ) {
			return array();
		}

		// BEGIN, the statements chained on success, then COMMIT or ROLLBACK.
		$steps = array( array( 'stmt' => array( 'sql' => 'BEGIN' ) ) );
		foreach ( array_values( $statements ) as $index => $statement ) {
			$steps[] = array(
				'stmt'      => $this->stmt( $statement[0], $statement[1] ?? array() ),
				// Each step runs only if the one before it did.
				'condition' => array(
					'type' => 'ok',
					'step' => $index,
				),
			);
		}

		$last_index   = count( $steps ) - 1;
		$commit_index = $last_index + 1;
		$steps[]      = array(
			'stmt'      => array( 'sql' => 'COMMIT' ),
			'condition' => array(
				'type' => 'ok',
				'step' => $last_index,
			),
		);
		$steps[]      = array(
			'stmt'      => array( 'sql' => 'ROLLBACK' ),
			'condition' => array(
				'type' => 'not',
				'cond' => array(
					'type' => 'ok',
					'step' => $commit_index,
				),
			),
		);

		$body = $this->send_pipeline(
			array(
				array(
					'type'  => 'batch',
					'batch' => array( 'steps' => $steps ),
				),
			)
		);

		$batch        = $this->unwrap_result( $body, 0, 'batch' );
		$step_results = is_array( $batch['step_results'] ?? null ) ? $batch['step_results'] : array();
		$step_errors  = is_array( $batch['step_errors'] ?? null ) ? $batch['step_errors'] : array();

		// Report the first failing step. The rollback already happened server-side.
		foreach ( $step_errors as $error ) {
			if ( null !== $error ) {
				throw WP_SQLite_Turso_Response::decode_error( $error );
			}
		}

		$results = array();
		for ( $i = 1, $count = count( $statements ); $i <= $count; $i++ ) {
			$step = $step_results[ $i ] ?? null;
			if ( ! is_array( $step ) ) {
				throw WP_SQLite_Turso_Exception::from_transport_failure(
					sprintf( 'Turso returned no result for batch statement %d.', $i )
				);
			}
			$results[] = WP_SQLite_Turso_Response::decode_result( $step );
		}
		return $results;
	}

	/**
	 * Execute a list of pipeline requests and return their raw results.
	 *
	 * @param  array $requests The pipeline requests.
	 * @return array[]         The raw "result" objects, in order.
	 * @throws WP_SQLite_Turso_Exception When the request or any statement fails.
	 */
	private function pipeline( array $requests ): array {
		$body    = $this->send_pipeline( $requests );
		$results = array();
		foreach ( array_keys( $requests ) as $index ) {
			$results[] = $this->unwrap_result( $body, $index, 'execute' );
		}
		return $results;
	}

	/**
	 * Build an "execute" pipeline request.
	 *
	 * @param  string $sql    The SQL statement.
	 * @param  array  $params Positional query parameters.
	 * @return array          The request.
	 */
	private function execute_request( string $sql, array $params = array() ): array {
		return array(
			'type' => 'execute',
			'stmt' => $this->stmt( $sql, $params ),
		);
	}

	/**
	 * Build a protocol "stmt" object.
	 *
	 * @param  string $sql    The SQL statement.
	 * @param  array  $params Positional query parameters.
	 * @return array          The statement object.
	 */
	private function stmt( string $sql, array $params = array() ): array {
		$stmt = array( 'sql' => $sql );
		if ( array() !== $params ) {
			$stmt['args'] = WP_SQLite_Turso_Response::encode_params( $params );
		}
		return $stmt;
	}

	/**
	 * Whether a statement needs changes() and last_insert_rowid() fetched.
	 *
	 * @param  string $sql The SQL statement.
	 * @return bool        True for statements that may report write metadata.
	 */
	private function statement_changes_data( string $sql ): bool {
		return 1 === preg_match(
			'/^\s*(?:\/\*.*?\*\/\s*)*(?:INSERT|REPLACE|UPDATE|DELETE|UPSERT)\b/is',
			$sql
		);
	}

	/**
	 * Pull one request's "result" object out of a pipeline response.
	 *
	 * @param  array  $body     The decoded response body.
	 * @param  int    $index    The index of the request.
	 * @param  string $expected The expected response type, "execute" or "batch".
	 * @return array            The raw result object.
	 * @throws WP_SQLite_Turso_Exception When the request failed or the shape is wrong.
	 */
	private function unwrap_result( array $body, int $index, string $expected ): array {
		$entry = $body['results'][ $index ] ?? null;
		if ( ! is_array( $entry ) || ! isset( $entry['type'] ) ) {
			throw WP_SQLite_Turso_Exception::from_transport_failure(
				sprintf( 'Turso returned no result for pipeline request %d.', $index )
			);
		}
		if ( 'error' === $entry['type'] ) {
			throw WP_SQLite_Turso_Response::decode_error( $entry['error'] ?? null );
		}

		$response = is_array( $entry['response'] ?? null ) ? $entry['response'] : array();
		if ( ( $response['type'] ?? null ) !== $expected || ! is_array( $response['result'] ?? null ) ) {
			throw WP_SQLite_Turso_Exception::from_transport_failure(
				sprintf( 'Turso returned an unexpected response for a "%s" request.', $expected )
			);
		}
		return $response['result'];
	}

	/**
	 * POST a pipeline body and return the decoded response.
	 *
	 * @param  array $requests The pipeline requests.
	 * @return array           The decoded response body.
	 * @throws WP_SQLite_Turso_Exception When the request fails.
	 */
	private function send_pipeline( array $requests ): array {
		$json = json_encode( array( 'requests' => $requests ) );
		if ( false === $json ) {
			throw WP_SQLite_Turso_Exception::from_transport_failure(
				'Failed to encode the request payload as JSON: ' . json_last_error_msg()
			);
		}

		list( $status, $response_body ) = $this->send( $this->pipeline_path, $json );

		$body = json_decode( $response_body, true );
		if ( ! is_array( $body ) ) {
			throw WP_SQLite_Turso_Exception::from_transport_failure(
				sprintf( 'Turso returned a non-JSON response with HTTP status %d.', $status )
			);
		}
		if ( 200 !== $status ) {
			throw WP_SQLite_Turso_Response::decode_error( $body['error'] ?? $body, $status );
		}
		if ( ! is_array( $body['results'] ?? null ) ) {
			throw WP_SQLite_Turso_Exception::from_transport_failure(
				'Turso returned a pipeline response without results.'
			);
		}
		return $body;
	}
}
