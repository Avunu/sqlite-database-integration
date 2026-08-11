<?php declare(strict_types = 1);

/*
 * The D1 connection implements a PDO-compatible API. Enable PDO class usage:
 * phpcs:disable WordPress.DB.RestrictedClasses.mysql__PDO
 * phpcs:disable WordPress.DB.RestrictedClasses.mysql__PDOStatement
 */

/**
 * Cloudflare D1 connection.
 *
 * This class implements the SQLite connection interface on top of a remote
 * Cloudflare D1 database, accessed through a transport implementing the D1
 * proxy protocol. Results are served as in-memory statements.
 *
 * D1 is a stateless, per-request SQLite service, and this connection reports
 * no support for the optional connection capabilities:
 *
 *   - Interactive transactions and savepoints are not supported. Single
 *     statements are atomic, batches execute atomically, and transaction
 *     control methods are no-ops.
 *   - Temporary tables are not supported.
 *   - User-defined PHP functions cannot run inside a remote database.
 *
 * The MySQL-on-SQLite driver adapts to these constraints through the
 * connection capability API.
 */
class WP_SQLite_D1_Connection implements WP_SQLite_Connection_Interface {
	/**
	 * The maximum number of bound parameters per statement accepted by D1.
	 */
	const D1_MAX_BOUND_PARAMETERS = 100;

	/**
	 * The parameter count threshold at which parameters are inlined.
	 *
	 * WordPress composes queries with unbounded "IN (...)" lists that can
	 * exceed the D1 bound parameter limit. Statements with more parameters
	 * than this threshold have all their parameters inlined as literals.
	 */
	const PARAMS_INLINE_THRESHOLD = 90;

	/**
	 * The D1 transport.
	 *
	 * @var WP_SQLite_D1_Transport_Interface
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
	 * Defaults to true: the MySQL-on-SQLite driver expects string values,
	 * while the D1 protocol carries JSON-native types.
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
	 * still reads and restores this attribute to track the caller's chosen
	 * error mode while keeping its own internal operations in exception mode,
	 * so the value is stored and reported back faithfully.
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
	 * The emulated value of the "PRAGMA foreign_keys" setting.
	 *
	 * D1 always enforces foreign keys and doesn't support toggling them
	 * with "PRAGMA foreign_keys". The setting is emulated: when disabled,
	 * batches are executed with "PRAGMA defer_foreign_keys = true", which
	 * defers enforcement to the end of the batch transaction.
	 *
	 * @var bool
	 */
	private $foreign_keys_enabled = true;

	/**
	 * Whether the schema information cache is enabled.
	 *
	 * @var bool
	 */
	private $schema_cache_enabled;

	/**
	 * A cache of schema information query results.
	 *
	 * The MySQL-on-SQLite driver consults its information schema tables
	 * repeatedly while translating queries. Over a remote transport, each
	 * lookup is a network round trip, so results of information schema
	 * reads are memoized. The cache is invalidated by any statement that
	 * could change the schema information.
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
	 * @param WP_SQLite_D1_Transport_Interface $transport The D1 transport.
	 * @param array                            $options {
	 *     Optional. An array of options.
	 *
	 *     @type bool $schema_cache Whether to cache schema information reads.
	 *                              Default true.
	 * }
	 */
	public function __construct( WP_SQLite_D1_Transport_Interface $transport, array $options = array() ) {
		$this->transport            = $transport;
		$this->schema_cache_enabled = (bool) ( $options['schema_cache'] ?? true );
	}

	/**
	 * Get the D1 transport.
	 *
	 * @return WP_SQLite_D1_Transport_Interface
	 */
	public function get_transport(): WP_SQLite_D1_Transport_Interface {
		return $this->transport;
	}

	/**
	 * Execute a query in the D1 database.
	 *
	 * @param  string $sql   The query to execute.
	 * @param  array $params The query parameters.
	 * @throws WP_SQLite_D1_Exception When the query execution fails.
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
	 * Execute a batch of queries atomically in the D1 database.
	 *
	 * When any query fails, none of the queries take effect.
	 *
	 * @param  array<int, array{0: string, 1?: array}> $statements
	 *                       The queries to execute, each being an array of
	 *                       a query string and optional query parameters.
	 * @throws WP_SQLite_D1_Exception When the execution of any query fails.
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

		/*
		 * When foreign keys are disabled, defer their enforcement to the
		 * end of the batch transaction. This is the D1-supported mechanism
		 * backing schema changes that recreate tables.
		 */
		$has_deferral_prefix = false;
		if ( ! $this->foreign_keys_enabled ) {
			array_unshift( $prepared, array( 'PRAGMA defer_foreign_keys = true', array() ) );
			$has_deferral_prefix = true;
		}

		$results = $this->transport->batch( $prepared );
		if ( $has_deferral_prefix ) {
			array_shift( $results );
		}

		$statements_out = array();
		foreach ( $results as $result ) {
			$this->remember_meta( $result['meta'] );
			$statements_out[] = $this->create_statement( $result );
		}
		return $statements_out;
	}

	/**
	 * Begin a transaction. Not supported by D1; this is a no-op.
	 *
	 * D1 doesn't support interactive transactions. Single statements are
	 * atomic, and "execute_batch()" provides multi-statement atomicity.
	 *
	 * @param string $behavior The SQLite transaction behavior (ignored).
	 */
	public function begin_transaction( string $behavior = 'DEFERRED' ): void {
		// No-op. See the method description.
	}

	/**
	 * Commit the current transaction. Not supported by D1; this is a no-op.
	 */
	public function commit(): void {
		// No-op. See begin_transaction().
	}

	/**
	 * Roll back the current transaction. Not supported by D1; this is a no-op.
	 */
	public function rollback(): void {
		// No-op. See begin_transaction().
	}

	/**
	 * Create a savepoint. Not supported by D1; this is a no-op.
	 *
	 * @param string $name The savepoint name (ignored).
	 */
	public function savepoint( string $name ): void {
		// No-op. See begin_transaction().
	}

	/**
	 * Release a savepoint. Not supported by D1; this is a no-op.
	 *
	 * @param string $name The savepoint name (ignored).
	 */
	public function release_savepoint( string $name ): void {
		// No-op. See begin_transaction().
	}

	/**
	 * Roll back to a savepoint. Not supported by D1; this is a no-op.
	 *
	 * @param string $name The savepoint name (ignored).
	 */
	public function rollback_to_savepoint( string $name ): void {
		// No-op. See begin_transaction().
	}

	/**
	 * Check if a transaction is currently active. Always false for D1.
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
	 * Wraps the identifier in backticks and escapes backtick characters
	 * within. See WP_SQLite_Connection::quote_identifier() for details.
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
	 * Get the SQLite version of the remote D1 database.
	 *
	 * The D1 authorizer may not permit calling "sqlite_version()". In that
	 * case, a conservative default is assumed — D1 runs recent SQLite
	 * versions well above the driver's requirements.
	 *
	 * @return string The SQLite engine version, e.g. "3.45.1".
	 */
	public function get_server_version(): string {
		if ( null === $this->server_version ) {
			try {
				$result               = $this->transport->query( 'SELECT sqlite_version()' );
				$this->server_version = (string) ( $result['rows'][0][0] ?? '' );
			} catch ( WP_SQLite_D1_Exception $e ) {
				$this->server_version = '';
			}
			if ( '' === $this->server_version ) {
				$this->server_version = '3.45.0';
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
	 * Register a user-defined SQL function. Not supported by D1.
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
	 * A D1 connection supports none of the optional capabilities.
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
	 * Intercept SQLite statements that D1 doesn't support.
	 *
	 * The MySQL-on-SQLite driver emits a small set of statements that are
	 * local-database concepts. These are handled here, so that the driver
	 * itself remains backend-agnostic:
	 *
	 *   - "PRAGMA foreign_keys" reads and writes are emulated locally.
	 *     See the "$foreign_keys_enabled" property.
	 *   - Other PRAGMA statements are attempted against D1, degrading to
	 *     an empty result when D1 rejects them.
	 *   - Reads from "sqlite_temp_master" return an empty result. This is
	 *     defensive; temporary tables are gated by the capability API.
	 *
	 * @param  string $sql         The SQL statement.
	 * @return PDOStatement|null   The intercepted result, or null to proceed
	 *                             with normal execution.
	 */
	private function maybe_intercept_query( string $sql ): ?PDOStatement {
		$normalized = strtolower( trim( $sql ) );

		if ( 0 === strpos( $normalized, 'pragma' ) ) {
			// PRAGMA foreign_keys (read).
			if ( 'pragma foreign_keys' === rtrim( $normalized, '; ' ) ) {
				return $this->create_statement(
					array(
						'columns' => array( 'foreign_keys' ),
						'rows'    => array( array( $this->foreign_keys_enabled ? 1 : 0 ) ),
						'meta'    => array(
							'changes'     => 0,
							'last_row_id' => 0,
						),
					)
				);
			}

			// PRAGMA foreign_keys = ON|OFF (write).
			if ( 1 === preg_match( '/^pragma\s+foreign_keys\s*=\s*(on|off|true|false|1|0)\s*;?\s*$/', $normalized, $matches ) ) {
				$this->foreign_keys_enabled = in_array( $matches[1], array( 'on', 'true', '1' ), true );
				return $this->create_empty_statement();
			}

			// Attempt other PRAGMA statements, degrading to an empty result.
			try {
				$result = $this->transport->query( $sql );
				return $this->create_statement( $result );
			} catch ( WP_SQLite_D1_Exception $e ) {
				return $this->create_empty_statement();
			}
		}

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
		return false !== stripos( $sql, '_wp_sqlite_' ) || false !== stripos( $sql, 'sqlite_master' );
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
	 * D1 rejects statements with more than 100 bound parameters, which
	 * WordPress can exceed with large "IN (...)" lists. Above a threshold,
	 * all positional placeholders are replaced with quoted literals.
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
				$inlined  .= $this->quote( $params[ $position ] ?? null );
				$position += 1;
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

	/**
	 * Create an empty in-memory statement with no columns and no rows.
	 *
	 * @return WP_PDO_Array_Statement The statement.
	 */
	private function create_empty_statement(): WP_PDO_Array_Statement {
		return $this->create_statement(
			array(
				'columns' => array(),
				'rows'    => array(),
				'meta'    => array(
					'changes'     => 0,
					'last_row_id' => 0,
				),
			)
		);
	}
}
