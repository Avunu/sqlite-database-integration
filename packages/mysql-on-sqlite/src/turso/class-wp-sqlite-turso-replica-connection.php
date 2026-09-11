<?php declare(strict_types = 1);

/*
 * The connection implements a PDO-compatible API. Enable PDO class usage:
 * phpcs:disable WordPress.DB.RestrictedClasses.mysql__PDO
 */

/**
 * A connection that reads from a local Turso snapshot and writes to the primary.
 *
 * This is the read-mostly front end of the split-plane design. Reads go to a
 * plain SQLite file through pdo_sqlite, which is as fast as SQLite gets; writes
 * go to the Turso primary over HTTP.
 *
 * Why a snapshot and not the replica itself: Turso's embedded replica holds an
 * exclusive POSIX lock on its database file for the lifetime of its connection,
 * and coordinates its WAL through a ".tshm" file that SQLite knows nothing
 * about. pdo_sqlite therefore cannot read a live replica at all -- with the lock
 * it gets "database is locked", and with turso_core's lock disabled it silently
 * reads a stale snapshot forever. A separate publisher process keeps a private
 * replica, and periodically hands this connection a consistent standalone file
 * (sync, VACUUM INTO, rename).
 *
 * The consequence is that the snapshot lags the primary, so:
 *
 *   - Before the first write, reads come from the snapshot.
 *   - The first write, or the first transactional statement, *latches* the
 *     connection to the primary for the rest of the PHP request. Everything
 *     after that -- reads included -- goes to the primary, which is what makes
 *     read-your-writes hold within a request.
 *
 * A transaction latches as well as a write, because a transaction spanning a
 * snapshot read and a remote write could not be atomic across both.
 *
 * Capabilities are reported as the *primary's*, not the snapshot's, because any
 * transactional work happens there. That is conservative: it means the driver
 * emits the same UDF-less SQL on both paths, so a query does not change shape
 * depending on which side of the latch it lands on.
 */
class WP_SQLite_Turso_Replica_Connection implements WP_SQLite_Connection_Interface {
	/**
	 * Statements that can be served from the read-only snapshot.
	 *
	 * WITH is deliberately absent: a CTE can wrap a write in SQLite, so it is
	 * only treated as a read when the statement plainly selects.
	 *
	 * @var string
	 */
	const READ_ONLY_PATTERN = '/^\s*(?:\/\*.*?\*\/\s*)*(?:\(\s*)*(?:SELECT|PRAGMA|EXPLAIN)\b/is';

	/**
	 * A WITH statement that only reads.
	 *
	 * @var string
	 */
	const READ_ONLY_CTE_PATTERN = '/^\s*WITH\b(?:(?!\b(?:INSERT|UPDATE|DELETE|REPLACE)\b).)*\bSELECT\b/is';

	/**
	 * The local snapshot reader.
	 *
	 * @var WP_SQLite_Connection_Interface
	 */
	private $reader;

	/**
	 * The primary, reached over HTTP.
	 *
	 * @var WP_SQLite_Turso_Connection
	 */
	private $primary;

	/**
	 * Whether this request has latched to the primary.
	 *
	 * @var bool
	 */
	private $latched = false;

	/**
	 * A query logger callback.
	 *
	 * @var callable(string, array): void
	 */
	private $query_logger;

	/**
	 * Per-route statement counts, for diagnostics.
	 *
	 * @var array{snapshot: int, primary: int, latched_at: int|null}
	 */
	private $counters = array(
		'snapshot'   => 0,
		'primary'    => 0,
		'latched_at' => null,
	);

	/**
	 * Constructor.
	 *
	 * @param WP_SQLite_Connection_Interface $reader  A connection over the
	 *                                                published snapshot, which
	 *                                                may be opened read-only.
	 * @param WP_SQLite_Turso_Connection     $primary The primary connection.
	 */
	public function __construct( WP_SQLite_Connection_Interface $reader, WP_SQLite_Turso_Connection $primary ) {
		$this->reader  = $reader;
		$this->primary = $primary;
	}

	/**
	 * Whether the connection has latched to the primary for this request.
	 *
	 * @return bool
	 */
	public function is_latched(): bool {
		return $this->latched;
	}

	/**
	 * Get the per-route statement counters.
	 *
	 * @return array{snapshot: int, primary: int, latched_at: int|null}
	 */
	public function get_counters(): array {
		return $this->counters;
	}

	/**
	 * Get the connection a statement would be routed to.
	 *
	 * @param  string $sql The SQL statement.
	 * @return WP_SQLite_Connection_Interface The connection.
	 */
	private function route( string $sql ): WP_SQLite_Connection_Interface {
		if ( $this->latched ) {
			++$this->counters['primary'];
			return $this->primary;
		}

		if ( self::is_read_only( $sql ) ) {
			++$this->counters['snapshot'];
			return $this->reader;
		}

		return $this->latch();
	}

	/**
	 * Latch to the primary for the rest of the request.
	 *
	 * @return WP_SQLite_Turso_Connection The primary.
	 */
	private function latch(): WP_SQLite_Turso_Connection {
		if ( ! $this->latched ) {
			$this->latched                = true;
			$this->counters['latched_at'] = $this->counters['snapshot'] + $this->counters['primary'];
		}
		++$this->counters['primary'];
		return $this->primary;
	}

	/**
	 * Whether a statement can be served from the read-only snapshot.
	 *
	 * @param  string $sql The SQL statement.
	 * @return bool        True when the statement only reads.
	 */
	public static function is_read_only( string $sql ): bool {
		if ( 1 === preg_match( self::READ_ONLY_PATTERN, $sql ) ) {
			return true;
		}
		return 1 === preg_match( self::READ_ONLY_CTE_PATTERN, $sql );
	}

	/**
	 * Execute a query, routing it to the snapshot or the primary.
	 *
	 * @param  string $sql    The query to execute.
	 * @param  array  $params The query parameters.
	 * @throws PDOException  When the query execution fails.
	 * @return PDOStatement  The PDO statement object.
	 */
	public function query( string $sql, array $params = array() ): PDOStatement {
		if ( $this->query_logger ) {
			( $this->query_logger )( $sql, $params );
		}
		return $this->route( $sql )->query( $sql, $params );
	}

	/**
	 * Execute a batch of queries atomically on the primary.
	 *
	 * A batch is a write by definition, so it latches.
	 *
	 * @param  array<int, array{0: string, 1?: array}> $statements The queries.
	 * @throws PDOException   When the execution of any query fails.
	 * @return PDOStatement[] The PDO statement objects, one for each query.
	 */
	public function execute_batch( array $statements ): array {
		return $this->latch()->execute_batch( $statements );
	}

	/**
	 * Begin a transaction on the primary.
	 *
	 * Latches even though the primary reports transactions as unsupported: a
	 * transaction must not span the snapshot and the primary, and whatever
	 * follows it is the driver's transactional work.
	 *
	 * @param string $behavior The SQLite transaction behavior.
	 */
	public function begin_transaction( string $behavior = 'DEFERRED' ): void {
		$this->latch()->begin_transaction( $behavior );
	}

	/**
	 * Commit the current transaction on the primary.
	 */
	public function commit(): void {
		$this->latch()->commit();
	}

	/**
	 * Roll back the current transaction on the primary.
	 */
	public function rollback(): void {
		$this->latch()->rollback();
	}

	/**
	 * Create a savepoint on the primary.
	 *
	 * @param string $name The savepoint name, unquoted.
	 */
	public function savepoint( string $name ): void {
		$this->latch()->savepoint( $name );
	}

	/**
	 * Release a savepoint on the primary.
	 *
	 * @param string $name The savepoint name, unquoted.
	 */
	public function release_savepoint( string $name ): void {
		$this->latch()->release_savepoint( $name );
	}

	/**
	 * Roll back to a savepoint on the primary.
	 *
	 * @param string $name The savepoint name, unquoted.
	 */
	public function rollback_to_savepoint( string $name ): void {
		$this->latch()->rollback_to_savepoint( $name );
	}

	/**
	 * Check if a transaction is currently active.
	 *
	 * @return bool True when a transaction is active.
	 */
	public function in_transaction(): bool {
		return $this->latched ? $this->primary->in_transaction() : false;
	}

	/**
	 * Quote a value for use in a query.
	 *
	 * @param  mixed  $value The value to quote.
	 * @param  int    $type  The type of the value.
	 * @return string        The quoted value.
	 */
	public function quote( $value, int $type = PDO::PARAM_STR ): string {
		return $this->primary->quote( $value, $type );
	}

	/**
	 * Quote an SQLite identifier.
	 *
	 * @param  string $unquoted_identifier The unquoted identifier value.
	 * @return string                      The quoted identifier value.
	 */
	public function quote_identifier( string $unquoted_identifier ): string {
		return $this->primary->quote_identifier( $unquoted_identifier );
	}

	/**
	 * Returns the ID of the last inserted row.
	 *
	 * Inserts only ever happen on the primary.
	 *
	 * @return string The ID of the last inserted row.
	 */
	public function get_last_insert_id(): string {
		return $this->primary->get_last_insert_id();
	}

	/**
	 * Get the SQLite version.
	 *
	 * Reported from the snapshot: it is the engine that actually executes
	 * reads, it answers without a network round trip, and the driver uses this
	 * to decide which SQLite features it may emit.
	 *
	 * @return string The SQLite engine version.
	 */
	public function get_server_version(): string {
		return $this->reader->get_server_version();
	}

	/**
	 * Set a connection attribute on both sides.
	 *
	 * @param  int   $attribute The attribute to set.
	 * @param  mixed $value     The value of the attribute.
	 * @return bool             True when either side accepted the attribute.
	 */
	public function set_attribute( int $attribute, $value ): bool {
		$reader  = $this->reader->set_attribute( $attribute, $value );
		$primary = $this->primary->set_attribute( $attribute, $value );
		return $reader || $primary;
	}

	/**
	 * Get a connection attribute.
	 *
	 * @param  int   $attribute The attribute to get.
	 * @return mixed            The value of the attribute, or null.
	 */
	public function get_attribute( int $attribute ) {
		$value = $this->reader->get_attribute( $attribute );
		return null !== $value ? $value : $this->primary->get_attribute( $attribute );
	}

	/**
	 * Register a user-defined SQL function.
	 *
	 * Registered on the snapshot only, where PHP callbacks can run. It returns
	 * false regardless, because has_capability() reports no user-defined
	 * function support: a function the primary cannot evaluate must not appear
	 * in SQL that might be routed there.
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
	 * The primary's answer governs. The snapshot could offer transactions,
	 * savepoints, temporary tables and user-defined functions, but a statement
	 * may be routed to either side, so the driver has to emit SQL that works on
	 * both.
	 *
	 * @param  string $capability One of the CAPABILITY_* interface constants.
	 * @return bool               Whether the capability is supported.
	 */
	public function has_capability( string $capability ): bool {
		return $this->primary->has_capability( $capability );
	}

	/**
	 * Set a logger for the queries.
	 *
	 * @param callable(string, array): void $logger A query logger callback.
	 */
	public function set_query_logger( callable $logger ): void {
		$this->query_logger = $logger;
	}
}
