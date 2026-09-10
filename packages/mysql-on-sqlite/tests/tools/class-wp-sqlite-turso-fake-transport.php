<?php

/**
 * A fake Turso transport backed by a local SQLite database.
 *
 * This emulates the observable behaviors of a real Turso database behind the
 * "SQL over HTTP" pipeline protocol, so the suite can run without a server.
 * Every behaviour below was established by probing "tursodb --sync-server":
 *
 *   - Transaction control statements are rejected. A BEGIN sent on its own
 *     outlives its HTTP request on the server's shared connection, and every
 *     later BEGIN then fails; the connection never issues one, and the
 *     transport refuses to carry one.
 *   - Temporary tables are rejected: they would live on that same shared
 *     connection.
 *   - No user-defined functions are registered; SQL using them fails.
 *   - Batches are atomic. The real transport spells the transaction out as
 *     BEGIN / conditional COMMIT / conditional ROLLBACK steps, because Turso's
 *     own batch keeps the effects of steps that ran before one failed.
 *   - Writes report accurate changes and last_row_id. Turso itself reports
 *     neither, so the protocol trait asks for changes() and
 *     last_insert_rowid() in the same pipeline request; the fake simply
 *     reports them directly.
 *   - Reads report accurate column names and zeroed write meta; writes report
 *     no column names.
 *   - Values round-trip through the real protocol codec, so its type handling
 *     is exercised rather than assumed.
 *
 * phpcs:disable WordPress.DB.RestrictedClasses.mysql__PDO
 */
class WP_SQLite_Turso_Fake_Transport implements WP_SQLite_Turso_Transport_Interface {
	/**
	 * The local SQLite database emulating Turso.
	 *
	 * @var PDO
	 */
	private $pdo;

	/**
	 * A log of all executed statements, as [ sql, params ] pairs.
	 *
	 * @var array<int, array{0: string, 1: array}>
	 */
	private $log = array();

	/**
	 * The stringification setting to restore after internal fetches.
	 *
	 * @var bool
	 */
	private $external_stringify = false;

	/**
	 * Constructor.
	 *
	 * @param PDO|null $pdo Optional. A PDO SQLite instance to use.
	 */
	public function __construct( ?PDO $pdo = null ) {
		$this->pdo = $pdo ?? new PDO( 'sqlite::memory:' );
		$this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );

		/*
		 * Turso honours "PRAGMA foreign_keys", and the driver sets it, so the
		 * fake leaves the SQLite default in place rather than forcing it on the
		 * way the D1 fake has to.
		 */

		try {
			$this->external_stringify = (bool) $this->pdo->getAttribute( PDO::ATTR_STRINGIFY_FETCHES );
		} catch ( PDOException $e ) {
			$this->external_stringify = null !== $pdo;
		}
	}

	/**
	 * Get the log of all executed statements.
	 *
	 * @return array<int, array{0: string, 1: array}>
	 */
	public function get_log(): array {
		return $this->log;
	}

	/**
	 * Execute a single SQL statement. See the transport interface.
	 *
	 * @param  string $sql    The SQL statement to execute.
	 * @param  array  $params Positional query parameters.
	 * @return array          The result.
	 */
	public function query( string $sql, array $params = array() ): array {
		$this->reject_unsupported( $sql );
		return $this->run( $sql, $params );
	}

	/**
	 * Execute a batch of SQL statements atomically. See the transport interface.
	 *
	 * @param  array<int, array{0: string, 1?: array}> $statements The statements.
	 * @return array[] One result per statement.
	 */
	public function batch( array $statements ): array {
		foreach ( $statements as $statement ) {
			$this->reject_unsupported( $statement[0] );
		}

		$this->pdo->beginTransaction();
		try {
			$results = array();
			foreach ( $statements as $statement ) {
				$results[] = $this->run( $statement[0], $statement[1] ?? array() );
			}
		} catch ( Throwable $e ) {
			$this->pdo->rollBack();
			throw $e;
		}
		$this->pdo->commit();
		return $results;
	}

	/**
	 * Reject statements Turso cannot carry over HTTP.
	 *
	 * @param  string $sql The SQL statement.
	 * @throws WP_SQLite_Turso_Exception When the statement is not supported.
	 */
	private function reject_unsupported( string $sql ): void {
		$normalized = strtoupper( ltrim( $sql ) );

		foreach ( array( 'BEGIN', 'COMMIT', 'ROLLBACK', 'SAVEPOINT', 'RELEASE', 'END' ) as $keyword ) {
			if ( 1 === preg_match( '/^' . $keyword . '\b/', $normalized ) ) {
				throw WP_SQLite_Turso_Exception::from_server_error(
					'PREPARE_ERROR',
					sprintf( 'Transaction error: %s statements are not supported', $keyword )
				);
			}
		}

		if ( 1 === preg_match( '/^CREATE\s+(TEMP|TEMPORARY)\b/', $normalized ) ) {
			throw WP_SQLite_Turso_Exception::from_server_error(
				'PREPARE_ERROR',
				'Parse error: temporary tables are not supported'
			);
		}
	}

	/**
	 * Execute a statement locally, shaping the result as Turso's protocol does.
	 *
	 * @param  string $sql    The SQL statement.
	 * @param  array  $params The positional parameters.
	 * @return array          The result (columns, rows, meta).
	 * @throws WP_SQLite_Turso_Exception When the execution fails.
	 */
	private function run( string $sql, array $params ): array {
		$this->log[] = array( $sql, $params );

		/*
		 * Fetch with stringification disabled, independently of the attribute
		 * on the handle: the protocol carries typed values. PDO SQLite applies
		 * the attribute when rows are fetched rather than executed, so it has
		 * to stay off until the rows have been read, then be restored so tests
		 * can make stringified assertions against the raw handle.
		 */
		$this->pdo->setAttribute( PDO::ATTR_STRINGIFY_FETCHES, false );

		try {
			$stmt = $this->pdo->prepare( $sql );
			$stmt->execute( array_values( $params ) );

			$columns = array();
			for ( $i = 0; $i < $stmt->columnCount(); $i++ ) {
				try {
					$meta = $stmt->getColumnMeta( $i );
				} catch ( PDOException $e ) {
					$meta = false;
				}
				$columns[] = false === $meta ? "column$i" : $meta['name'];
			}

			$rows = array();
			foreach ( $stmt->fetchAll( PDO::FETCH_NUM ) as $row ) {
				$rows[] = array_map( array( $this, 'protocol_round_trip' ), $row );
			}
		} catch ( PDOException $e ) {
			// Reproduce the error shape of Turso's protocol.
			$message = $e->getMessage();
			$matches = array();
			preg_match( '/SQLSTATE\[[^\]]+\]: [^:]+: \d+ (.*)/s', $message, $matches );

			throw WP_SQLite_Turso_Exception::from_server_error(
				'PREPARE_ERROR',
				sprintf( 'Parse error: %s', $matches[1] ?? $message )
			);
		} finally {
			$this->pdo->setAttribute( PDO::ATTR_STRINGIFY_FETCHES, $this->external_stringify );
		}

		$is_read = 1 === preg_match( '/^\s*(SELECT|PRAGMA|EXPLAIN|WITH)\b/i', $sql );

		if ( $is_read ) {
			// Turso reports no write metadata for any statement; the protocol
			// trait only asks for it after a write.
			$meta = array(
				'changes'     => 0,
				'last_row_id' => 0,
			);
		} else {
			$meta = array(
				'changes'     => $stmt->rowCount(),
				'last_row_id' => (int) $this->pdo->lastInsertId(),
			);

			// A write carries no column names.
			if ( 0 === count( $rows ) ) {
				$columns = array();
			}
		}

		return array(
			'columns' => $columns,
			'rows'    => $rows,
			'meta'    => $meta,
		);
	}

	/**
	 * Round-trip a value through the real protocol codec.
	 *
	 * Using the production encoder and decoder here means the fake exercises
	 * the type handling rather than approximating it.
	 *
	 * @param  mixed $value The value.
	 * @return mixed        The value after a protocol round trip.
	 */
	private function protocol_round_trip( $value ) {
		$encoded = WP_SQLite_Turso_Response::encode_value( $value );

		// Travel through JSON as the real transport's bytes do.
		$decoded = json_decode( (string) json_encode( $encoded ), true );

		return WP_SQLite_Turso_Response::decode_value( $decoded );
	}
}
