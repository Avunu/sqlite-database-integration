<?php

/**
 * A fake D1 transport backed by a local SQLite database.
 *
 * This transport emulates the observable behaviors of a real D1 database
 * behind the D1 proxy protocol, enabling tests without network access:
 *
 *   - Transaction control statements are rejected (D1 doesn't support
 *     interactive transactions).
 *   - Temporary tables are rejected.
 *   - No user-defined functions are registered; SQL using them fails.
 *   - Batches execute atomically, rolling back on failure.
 *   - Values round-trip through JSON, reproducing the type coercion of
 *     the HTTP protocol.
 *   - Reads report accurate column names with zeroed write meta; writes
 *     report accurate meta (changes/last_row_id).
 *
 * phpcs:disable WordPress.DB.RestrictedClasses.mysql__PDO
 */
class WP_SQLite_D1_Fake_Transport implements WP_SQLite_D1_Transport_Interface {
	/**
	 * The local SQLite database emulating D1.
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

		// D1 always enforces foreign keys.
		$this->pdo->query( 'PRAGMA foreign_keys = ON' );

		// Capture the handle's stringification setting, to restore it after
		// internal fetches. Reading the attribute requires PHP 8.1+; older
		// versions assume the tests' backend factory setting (true) for
		// injected handles.
		if ( PHP_VERSION_ID >= 80100 ) {
			$this->external_stringify = (bool) $this->pdo->getAttribute( PDO::ATTR_STRINGIFY_FETCHES );
		} else {
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
	 * See the transport interface.
	 *
	 * @return string|null Always null; the fake transport has no sessions.
	 */
	public function get_bookmark(): ?string {
		return null;
	}

	/**
	 * See the transport interface.
	 *
	 * @param string|null $bookmark Ignored.
	 */
	public function set_bookmark( ?string $bookmark ): void {
		// The fake transport has no sessions.
	}

	/**
	 * Reject statements that D1 doesn't support.
	 *
	 * @param  string $sql The SQL statement.
	 * @throws WP_SQLite_D1_Exception When the statement is not supported.
	 */
	private function reject_unsupported( string $sql ): void {
		$normalized = strtoupper( ltrim( $sql ) );

		foreach ( array( 'BEGIN', 'COMMIT', 'ROLLBACK', 'SAVEPOINT', 'RELEASE', 'END' ) as $keyword ) {
			if ( 1 === preg_match( '/^' . $keyword . '\b/', $normalized ) ) {
				throw WP_SQLite_D1_Exception::from_proxy_error(
					'SQLITE_ERROR',
					sprintf( 'D1_ERROR: %s statements are not supported: SQLITE_ERROR', $keyword )
				);
			}
		}

		if ( 1 === preg_match( '/^CREATE\s+(TEMP|TEMPORARY)\b/', $normalized ) ) {
			throw WP_SQLite_D1_Exception::from_proxy_error(
				'SQLITE_AUTH',
				'D1_ERROR: not authorized: SQLITE_AUTH'
			);
		}
	}

	/**
	 * Execute a statement against the local database, shaping the result
	 * as the D1 proxy protocol does.
	 *
	 * @param  string $sql    The SQL statement.
	 * @param  array  $params The positional parameters.
	 * @return array          The result (columns, rows, meta).
	 * @throws WP_SQLite_D1_Exception When the execution fails.
	 */
	private function run( string $sql, array $params ): array {
		$this->log[] = array( $sql, $params );

		/*
		 * Fetch with value stringification disabled, independently of the
		 * attribute set on the PDO handle: the D1 protocol carries native
		 * JSON types. The attribute is restored afterwards, so that tests
		 * can make stringified assertions against the raw handle.
		 */
		$this->pdo->setAttribute( PDO::ATTR_STRINGIFY_FETCHES, false );

		try {
			$stmt = $this->pdo->prepare( $sql );
			$stmt->execute( array_values( $params ) );
		} catch ( PDOException $e ) {
			// Reproduce the error shape of the D1 proxy protocol.
			$message = $e->getMessage();
			$matches = array();
			preg_match( '/SQLSTATE\[[^\]]+\]: [^:]+: \d+ (.*)/s', $message, $matches );

			$is_constraint_error = false !== strpos( $message, 'constraint failed' )
				|| false !== strpos( $message, 'cannot store' );
			throw WP_SQLite_D1_Exception::from_proxy_error(
				$is_constraint_error ? 'SQLITE_CONSTRAINT' : 'SQLITE_ERROR',
				sprintf( 'D1_ERROR: %s: SQLITE_ERROR', $matches[1] ?? $message )
			);
		} finally {
			$this->pdo->setAttribute( PDO::ATTR_STRINGIFY_FETCHES, $this->external_stringify );
		}

		$is_read = 1 === preg_match( '/^\s*(SELECT|PRAGMA|EXPLAIN|WITH)\b/i', $sql );

		$columns = array();
		for ( $i = 0; $i < $stmt->columnCount(); $i++ ) {
			$meta      = $stmt->getColumnMeta( $i );
			$columns[] = $meta['name'];
		}

		$rows = array();
		foreach ( $stmt->fetchAll( PDO::FETCH_NUM ) as $row ) {
			$rows[] = array_map( array( $this, 'json_round_trip' ), $row );
		}

		if ( $is_read ) {
			// As with the D1 proxy, reads report zeroed write meta.
			$meta = array(
				'changes'     => 0,
				'last_row_id' => 0,
			);
		} else {
			$meta = array(
				'changes'     => $stmt->rowCount(),
				'last_row_id' => (int) $this->pdo->lastInsertId(),
			);

			// Object-shaped write results cannot represent duplicate column
			// names or column names of empty results. See the D1 proxy.
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
	 * Round-trip a value through JSON, as the HTTP protocol does.
	 *
	 * Binary strings are exempt: the protocol wraps them as base64 BLOB
	 * values, which decode back to identical binary strings.
	 *
	 * @param  mixed $value The value.
	 * @return mixed        The value after a JSON round trip.
	 */
	private function json_round_trip( $value ) {
		if ( is_string( $value ) && ! preg_match( '//u', $value ) ) {
			return $value;
		}
		return json_decode( (string) json_encode( $value ), true );
	}
}
