<?php declare(strict_types = 1);

/*
 * The Turso connection implements a PDO-compatible API. Enable PDO class usage:
 * phpcs:disable WordPress.DB.RestrictedClasses.mysql__PDO
 */

/**
 * A MySQL-on-SQLite connection backed by a remote Turso database.
 *
 * This class implements the SQLite connection interface on top of Turso's
 * "SQL over HTTP" pipeline endpoint, so the driver can run against a Turso
 * database with no local SQLite file at all.
 *
 * What Turso does and does not give us, all established by probing a
 * "tursodb --sync-server" instance:
 *
 *   - Write metadata is missing. Every statement reports
 *     "affected_row_count": 0 and "last_insert_rowid": null, so the transport
 *     asks for changes() and last_insert_rowid() in the same pipeline request
 *     as the write. See WP_SQLite_Turso_Protocol.
 *   - Named parameters bind silently to NULL. Only positional "?" works, which
 *     is what the driver emits.
 *   - Batches are not atomic on their own, so the transport wraps them in
 *     BEGIN / conditional COMMIT / conditional ROLLBACK steps.
 *   - Interactive transactions cannot be used. A BEGIN in one HTTP request
 *     stays open on the server's shared connection, and every later BEGIN
 *     anywhere fails with "cannot start a transaction within a transaction".
 *     Transactions are therefore reported as unsupported and atomicity comes
 *     from execute_batch().
 *   - PRAGMA statements do work, including "foreign_keys", and they persist
 *     across requests -- they are connection state on a connection the server
 *     shares between clients. The driver's own use of them is safe because it
 *     sets them deliberately; be aware that two clients share the setting.
 *   - Bound parameters are not capped the way D1's are: 1000 in one statement
 *     is fine. Inlining stays as a safety valve, at a much higher threshold.
 */
class WP_SQLite_Turso_Connection implements WP_SQLite_Connection_Interface {
	/**
	 * The parameter count threshold at which parameters are inlined.
	 *
	 * Turso accepts far more bound parameters than D1 does, so this exists
	 * only to keep a pathological "IN (...)" list from failing outright.
	 *
	 * @var int
	 */
	const PARAMS_INLINE_THRESHOLD = 5000;

	/**
	 * The fallback SQLite version when the server will not report one.
	 *
	 * @var string
	 */
	const FALLBACK_SERVER_VERSION = '3.45.0';

	/**
	 * The Turso transport.
	 *
	 * @var WP_SQLite_Turso_Transport_Interface
	 */
	private $transport;

	/**
	 * A query logger callback.
	 *
	 * @var callable(string, array): void
	 */
	private $query_logger;

	/**
	 * Whether fetched values are stringified (PDO::ATTR_STRINGIFY_FETCHES).
	 *
	 * Defaults to true: the driver expects string values, while the Turso
	 * protocol carries typed ones.
	 *
	 * @var bool
	 */
	private $stringify_fetches = true;

	/**
	 * The default fetch mode (PDO::ATTR_DEFAULT_FETCH_MODE).
	 *
	 * @var int
	 */
	private $default_fetch_mode = PDO::FETCH_BOTH;

	/**
	 * The error mode (PDO::ATTR_ERRMODE).
	 *
	 * Failures always surface as exceptions from the transport. The driver
	 * still reads and restores this attribute, so the value is stored and
	 * reported back faithfully.
	 *
	 * @var int
	 */
	private $error_mode = PDO::ERRMODE_EXCEPTION;

	/**
	 * The last inserted row ID reported by the database.
	 *
	 * @var int
	 */
	private $last_insert_id = 0;

	/**
	 * Whether the schema information cache is enabled.
	 *
	 * @var bool
	 */
	private $schema_cache_enabled;

	/**
	 * A cache of schema information query results.
	 *
	 * The driver consults its information schema tables repeatedly while
	 * translating queries. Over a remote transport each lookup is a round
	 * trip, so information schema reads are memoized and invalidated by any
	 * statement that could change the schema.
	 *
	 * @var array<string, array>
	 */
	private $schema_cache = array();

	/**
	 * The SQLite version of the remote database, lazily fetched.
	 *
	 * @var string|null
	 */
	private $server_version;

	/**
	 * Constructor.
	 *
	 * @param WP_SQLite_Turso_Transport_Interface $transport The Turso transport.
	 * @param array                               $options {
	 *     Optional. An array of options.
	 *
	 *     @type bool $schema_cache Whether to cache schema information reads.
	 *                              Default true.
	 * }
	 */
	public function __construct( WP_SQLite_Turso_Transport_Interface $transport, array $options = array() ) {
		$this->transport            = $transport;
		$this->schema_cache_enabled = (bool) ( $options['schema_cache'] ?? true );
	}

	/**
	 * Get the Turso transport.
	 *
	 * @return WP_SQLite_Turso_Transport_Interface
	 */
	public function get_transport(): WP_SQLite_Turso_Transport_Interface {
		return $this->transport;
	}

	/**
	 * Execute a query in the Turso database.
	 *
	 * @param  string $sql    The query to execute.
	 * @param  array  $params The query parameters.
	 * @throws WP_SQLite_Turso_Exception When the query execution fails.
	 * @return PDOStatement  The PDO statement object.
	 */
	public function query( string $sql, array $params = array() ): PDOStatement {
		if ( $this->query_logger ) {
			( $this->query_logger )( $sql, $params );
		}

		$intercepted = $this->maybe_intercept_query( $sql );
		if ( null !== $intercepted ) {
			return $intercepted;
		}

		// Serve schema information reads from the cache when possible.
		$cache_key = null;
		if ( $this->is_schema_information_read( $sql ) ) {
			if ( $this->schema_cache_enabled ) {
				$cache_key = sha1( $sql . "\0" . serialize( $params ) );
				if ( isset( $this->schema_cache[ $cache_key ] ) ) {
					return $this->create_statement( $this->schema_cache[ $cache_key ] );
				}
			}
		} elseif ( $this->can_change_schema_information( $sql ) ) {
			$this->schema_cache = array();
		}

		list( $sql, $params ) = $this->maybe_inline_params( $sql, $params );

		$result = $this->transport->query( $sql, $params );
		$this->remember_meta( $result['meta'] );

		if ( null !== $cache_key ) {
			$this->schema_cache[ $cache_key ] = $result;
		}
		return $this->create_statement( $result );
	}

	/**
	 * Execute a batch of queries atomically in the Turso database.
	 *
	 * When any query fails, none of the queries take effect. The transport
	 * spells the transaction out as batch steps; see WP_SQLite_Turso_Protocol.
	 *
	 * @param  array<int, array{0: string, 1?: array}> $statements
	 *                        The queries to execute, each being an array of
	 *                        a query string and optional query parameters.
	 * @throws WP_SQLite_Turso_Exception When the execution of any query fails.
	 * @return PDOStatement[] The PDO statement objects, one for each query.
	 */
	public function execute_batch( array $statements ): array {
		$prepared = array();
		foreach ( $statements as $statement ) {
			$sql    = $statement[0];
			$params = $statement[1] ?? array();

			if ( $this->query_logger ) {
				( $this->query_logger )( $sql, $params );
			}
			if ( $this->can_change_schema_information( $sql ) ) {
				$this->schema_cache = array();
			}

			$prepared[] = $this->maybe_inline_params( $sql, $params );
		}

		$results        = $this->transport->batch( $prepared );
		$statements_out = array();
		foreach ( $results as $result ) {
			$this->remember_meta( $result['meta'] );
			$statements_out[] = $this->create_statement( $result );
		}
		return $statements_out;
	}

	/**
	 * Begin a transaction. Not supported by Turso over HTTP; this is a no-op.
	 *
	 * A BEGIN issued in one HTTP request stays open on the connection the
	 * server shares between clients, wedging every later transaction. Single
	 * statements are atomic, and execute_batch() provides multi-statement
	 * atomicity.
	 *
	 * @param string $behavior The SQLite transaction behavior (ignored).
	 */
	public function begin_transaction( string $behavior = 'DEFERRED' ): void {
		// No-op. See the method description.
	}

	/**
	 * Commit the current transaction. Not supported; this is a no-op.
	 */
	public function commit(): void {
		// No-op. See begin_transaction().
	}

	/**
	 * Roll back the current transaction. Not supported; this is a no-op.
	 */
	public function rollback(): void {
		// No-op. See begin_transaction().
	}

	/**
	 * Create a savepoint. Not supported; this is a no-op.
	 *
	 * @param string $name The savepoint name (ignored).
	 */
	public function savepoint( string $name ): void {
		// No-op. See begin_transaction().
	}

	/**
	 * Release a savepoint. Not supported; this is a no-op.
	 *
	 * @param string $name The savepoint name (ignored).
	 */
	public function release_savepoint( string $name ): void {
		// No-op. See begin_transaction().
	}

	/**
	 * Roll back to a savepoint. Not supported; this is a no-op.
	 *
	 * @param string $name The savepoint name (ignored).
	 */
	public function rollback_to_savepoint( string $name ): void {
		// No-op. See begin_transaction().
	}

	/**
	 * Check if a transaction is currently active. Always false.
	 *
	 * @return bool Always false.
	 */
	public function in_transaction(): bool {
		return false;
	}

	/**
	 * Quote a value for use in a query.
	 *
	 * @param  mixed  $value The value to quote.
	 * @param  int    $type  The type of the value.
	 * @return string        The quoted value.
	 */
	public function quote( $value, int $type = PDO::PARAM_STR ): string {
		if ( null === $value ) {
			return 'NULL';
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return (string) $value;
		}
		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}

		$value = (string) $value;
		if ( false !== strpos( $value, "\0" ) ) {
			// SQL string literals can't contain null characters.
			return sprintf( "CAST(x'%s' AS TEXT)", bin2hex( $value ) );
		}
		return "'" . str_replace( "'", "''", $value ) . "'";
	}

	/**
	 * Quote an SQLite identifier.
	 *
	 * @param  string $unquoted_identifier The unquoted identifier value.
	 * @return string                      The quoted identifier value.
	 */
	public function quote_identifier( string $unquoted_identifier ): string {
		return '`' . str_replace( '`', '``', $unquoted_identifier ) . '`';
	}

	/**
	 * Returns the ID of the last inserted row.
	 *
	 * @return string The ID of the last inserted row.
	 */
	public function get_last_insert_id(): string {
		return (string) $this->last_insert_id;
	}

	/**
	 * Get the SQLite version of the remote Turso database.
	 *
	 * @return string The SQLite engine version, e.g. "3.50.4".
	 */
	public function get_server_version(): string {
		if ( null === $this->server_version ) {
			try {
				$result               = $this->transport->query( 'SELECT sqlite_version()' );
				$this->server_version = (string) ( $result['rows'][0][0] ?? '' );
			} catch ( WP_SQLite_Turso_Exception $e ) {
				$this->server_version = '';
			}
			if ( '' === $this->server_version ) {
				$this->server_version = self::FALLBACK_SERVER_VERSION;
			}
		}
		return $this->server_version;
	}

	/**
	 * Set a connection attribute.
	 *
	 * @param  int   $attribute The attribute to set.
	 * @param  mixed $value     The value of the attribute.
	 * @return bool             True on success, false for unsupported attributes.
	 */
	public function set_attribute( int $attribute, $value ): bool {
		if ( PDO::ATTR_STRINGIFY_FETCHES === $attribute ) {
			$this->stringify_fetches = (bool) $value;
			return true;
		}
		if ( PDO::ATTR_DEFAULT_FETCH_MODE === $attribute ) {
			$this->default_fetch_mode = (int) $value;
			return true;
		}
		if ( PDO::ATTR_ERRMODE === $attribute ) {
			if (
				! in_array(
					$value,
					array( PDO::ERRMODE_SILENT, PDO::ERRMODE_WARNING, PDO::ERRMODE_EXCEPTION ),
					true
				)
			) {
				return false;
			}
			$this->error_mode = $value;
			return true;
		}
		return false;
	}

	/**
	 * Get a connection attribute.
	 *
	 * @param  int   $attribute The attribute to get.
	 * @return mixed            The value of the attribute, or null when the
	 *                          attribute is not supported.
	 */
	public function get_attribute( int $attribute ) {
		if ( PDO::ATTR_STRINGIFY_FETCHES === $attribute ) {
			return $this->stringify_fetches;
		}
		if ( PDO::ATTR_DEFAULT_FETCH_MODE === $attribute ) {
			return $this->default_fetch_mode;
		}
		if ( PDO::ATTR_SERVER_VERSION === $attribute ) {
			return $this->get_server_version();
		}
		if ( PDO::ATTR_ERRMODE === $attribute ) {
			return $this->error_mode;
		}
		return null;
	}

	/**
	 * Register a user-defined SQL function. Not supported remotely.
	 *
	 * PHP callbacks cannot run inside a remote database.
	 *
	 * @param  string   $name     The SQL function name.
	 * @param  callable $callback The PHP callback implementing the function.
	 * @return bool               Always false.
	 */
	public function create_function( string $name, callable $callback ): bool {
		return false;
	}

	/**
	 * Check whether the connection supports an optional capability.
	 *
	 * None of them, for the reasons given in the class description: a BEGIN
	 * outlives its HTTP request, temporary tables would live on a connection
	 * shared with other clients, and PHP callbacks cannot run server-side.
	 *
	 * @param  string $capability One of the CAPABILITY_* interface constants.
	 * @return bool               Always false.
	 */
	public function has_capability( string $capability ): bool {
		return false;
	}

	/**
	 * Set a logger for the queries.
	 *
	 * @param callable(string, array): void $logger A query logger callback.
	 */
	public function set_query_logger( callable $logger ): void {
		$this->query_logger = $logger;
	}

	/**
	 * Intercept statements that should not reach Turso.
	 *
	 * Unlike the D1 backend, PRAGMA statements are passed through: Turso
	 * honours them, "foreign_keys" included. Only reads of the temporary
	 * table master need heading off, and that is defensive -- temporary
	 * tables are gated by the capability API.
	 *
	 * @param  string $sql       The SQL statement.
	 * @return PDOStatement|null The intercepted result, or null to proceed.
	 */
	private function maybe_intercept_query( string $sql ): ?PDOStatement {
		$normalized = strtolower( ltrim( $sql ) );
		if ( 0 === strpos( $normalized, 'select' ) && false !== strpos( $normalized, 'sqlite_temp_master' ) ) {
			return $this->create_statement(
				array(
					'columns' => array( '1' ),
					'rows'    => array(),
					'meta'    => array(
						'changes'     => 0,
						'last_row_id' => 0,
					),
				)
			);
		}
		return null;
	}

	/**
	 * Check whether a statement is a schema information read.
	 *
	 * @param  string $sql The SQL statement.
	 * @return bool        True for cacheable schema information reads.
	 */
	private function is_schema_information_read( string $sql ): bool {
		if ( 1 !== preg_match( '/^\s*SELECT\b/i', $sql ) ) {
			return false;
		}
		if ( false === stripos( $sql, '_wp_sqlite_' ) && false === stripos( $sql, 'sqlite_master' ) ) {
			return false;
		}

		/*
		 * Some information schema reads join "sqlite_sequence" to report the next
		 * AUTO_INCREMENT value. That depends on row data, not on the schema: a
		 * plain INSERT moves it without touching anything this cache invalidates
		 * on. Such a read has to go to the database every time.
		 */
		return false === stripos( $sql, 'sqlite_sequence' );
	}

	/**
	 * Check whether a statement can change schema information.
	 *
	 * @param  string $sql The SQL statement.
	 * @return bool        True when the schema information cache must be
	 *                     invalidated after executing the statement.
	 */
	private function can_change_schema_information( string $sql ): bool {
		// Writes to the driver-internal tables change schema information.
		if ( false !== stripos( $sql, '_wp_sqlite_' ) ) {
			return true;
		}
		// DDL statements change the real database schema.
		return 1 === preg_match( '/^\s*(CREATE|ALTER|DROP|TRUNCATE)\b/i', $sql );
	}

	/**
	 * Inline query parameters when the statement has too many of them.
	 *
	 * @param  string $sql    The SQL statement with "?" placeholders.
	 * @param  array  $params The positional query parameters.
	 * @return array{0: string, 1: array} The statement and parameters to send.
	 */
	private function maybe_inline_params( string $sql, array $params ): array {
		if ( count( $params ) <= self::PARAMS_INLINE_THRESHOLD ) {
			return array( $sql, $params );
		}

		$params   = array_values( $params );
		$position = 0;
		$length   = strlen( $sql );
		$inlined  = '';

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $sql[ $i ];

			// Skip quoted regions ('...', "...", `...`).
			if ( "'" === $char || '"' === $char || '`' === $char ) {
				$end = $i;
				do {
					$end = strpos( $sql, $char, $end + 1 );
					if ( false === $end ) {
						$end = $length - 1;
						break;
					}
					// A doubled quote character is an escape sequence.
				} while ( isset( $sql[ $end + 1 ] ) && $sql[ $end + 1 ] === $char && ++$end );

				$inlined .= substr( $sql, $i, $end - $i + 1 );
				$i        = $end;
				continue;
			}

			if ( '?' === $char ) {
				$inlined .= $this->quote( $params[ $position ] ?? null );
				++$position;
				continue;
			}

			$inlined .= $char;
		}

		return array( $inlined, array() );
	}

	/**
	 * Store statement metadata for connection-level APIs.
	 *
	 * @param array $meta The statement metadata.
	 */
	private function remember_meta( array $meta ): void {
		if ( ( $meta['last_row_id'] ?? 0 ) > 0 ) {
			$this->last_insert_id = (int) $meta['last_row_id'];
		}
	}

	/**
	 * Create an in-memory statement from a transport result.
	 *
	 * @param  array $result The transport result (columns, rows, meta).
	 * @return WP_PDO_Array_Statement The statement.
	 */
	private function create_statement( array $result ): WP_PDO_Array_Statement {
		return new WP_PDO_Array_Statement(
			$result['columns'],
			$result['rows'],
			(int) ( $result['meta']['changes'] ?? 0 ),
			$this->stringify_fetches,
			array(),
			$this->default_fetch_mode
		);
	}
}
