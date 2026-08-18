<?php declare(strict_types = 1);

/*
 * The connection interface refers to PDO classes. Enable PDO class usage:
 * phpcs:disable WordPress.DB.RestrictedClasses.mysql__PDO
 * phpcs:disable WordPress.DB.RestrictedClasses.mysql__PDOStatement
 */

/**
 * SQLite connection interface.
 *
 * This interface abstracts a connection to an SQLite database, decoupling
 * the MySQL-on-SQLite driver from a specific SQLite driver implementation.
 * The default implementation is a PDO SQLite connection to a local database
 * file, but the interface allows plugging in other SQLite database backends,
 * such as the SQLite3 extension, or remote SQLite databases accessed over
 * a network protocol.
 *
 * Backends may differ in the features they support. The "has_capability()"
 * method reports which optional capabilities a connection supports, so the
 * driver can adapt its behavior — e.g., a remote SQLite database accessed
 * using a stateless protocol may not support interactive transactions or
 * user-defined functions.
 */
interface WP_SQLite_Connection_Interface {
	/**
	 * Capability: Interactive transactions (BEGIN, COMMIT, ROLLBACK).
	 */
	const CAPABILITY_TRANSACTIONS = 'transactions';

	/**
	 * Capability: Savepoints (SAVEPOINT, RELEASE, ROLLBACK TO).
	 */
	const CAPABILITY_SAVEPOINTS = 'savepoints';

	/**
	 * Capability: Temporary tables (CREATE TEMPORARY TABLE).
	 */
	const CAPABILITY_TEMPORARY_TABLES = 'temporary_tables';

	/**
	 * Capability: User-defined SQL functions implemented as PHP callbacks.
	 */
	const CAPABILITY_USER_DEFINED_FUNCTIONS = 'user_defined_functions';

	/**
	 * Execute a query in SQLite.
	 *
	 * @param  string $sql   The query to execute.
	 * @param  array $params The query parameters.
	 * @throws PDOException  When the query execution fails.
	 * @return PDOStatement  The PDO statement object.
	 */
	public function query( string $sql, array $params = array() ): PDOStatement;

	/**
	 * Execute a batch of queries in SQLite.
	 *
	 * Backends that support batch execution may execute all the queries
	 * atomically, applying either all or none of the changes. Otherwise,
	 * the queries are executed sequentially, one at a time.
	 *
	 * The queries in a batch must not consume each other's results, and
	 * must not include any transaction control statements.
	 *
	 * @param  array<int, array{0: string, 1?: array}> $statements
	 *                       The queries to execute, each being an array of
	 *                       a query string and optional query parameters.
	 * @throws PDOException  When the execution of any of the queries fails.
	 * @return PDOStatement[] The PDO statement objects, one for each query.
	 */
	public function execute_batch( array $statements ): array;

	/**
	 * Begin a transaction.
	 *
	 * @param  string $behavior The SQLite transaction behavior.
	 *                          One of "DEFERRED", "IMMEDIATE", or "EXCLUSIVE".
	 * @throws PDOException     When the transaction cannot be started.
	 */
	public function begin_transaction( string $behavior = 'DEFERRED' ): void;

	/**
	 * Commit the current transaction.
	 *
	 * @throws PDOException When the transaction cannot be committed.
	 */
	public function commit(): void;

	/**
	 * Roll back the current transaction.
	 *
	 * @throws PDOException When the transaction cannot be rolled back.
	 */
	public function rollback(): void;

	/**
	 * Create a savepoint.
	 *
	 * @param  string $name The savepoint name, unquoted.
	 * @throws PDOException When the savepoint cannot be created.
	 */
	public function savepoint( string $name ): void;

	/**
	 * Release a savepoint.
	 *
	 * @param  string $name The savepoint name, unquoted.
	 * @throws PDOException When the savepoint cannot be released.
	 */
	public function release_savepoint( string $name ): void;

	/**
	 * Roll back to a savepoint.
	 *
	 * @param  string $name The savepoint name, unquoted.
	 * @throws PDOException When the rollback fails.
	 */
	public function rollback_to_savepoint( string $name ): void;

	/**
	 * Check if a transaction is currently active.
	 *
	 * @return bool True when a transaction is active, false otherwise.
	 */
	public function in_transaction(): bool;

	/**
	 * Quote a value for use in a query.
	 *
	 * @param  mixed  $value The value to quote.
	 * @param  int    $type  The type of the value.
	 * @return string        The quoted value.
	 */
	public function quote( $value, int $type = PDO::PARAM_STR ): string;

	/**
	 * Quote an SQLite identifier.
	 *
	 * Wraps the identifier in backticks and escapes backtick characters within.
	 *
	 * @param  string $unquoted_identifier The unquoted identifier value.
	 * @return string                      The quoted identifier value.
	 */
	public function quote_identifier( string $unquoted_identifier ): string;

	/**
	 * Returns the ID of the last inserted row.
	 *
	 * @return string The ID of the last inserted row.
	 */
	public function get_last_insert_id(): string;

	/**
	 * Get the version of the SQLite engine serving this connection.
	 *
	 * @return string The SQLite engine version, e.g. "3.45.1".
	 */
	public function get_server_version(): string;

	/**
	 * Set a connection attribute.
	 *
	 * The attributes correspond to the PDO::ATTR_* constants. Backends may
	 * support only a subset of the PDO attributes.
	 *
	 * @param  int   $attribute The attribute to set.
	 * @param  mixed $value     The value of the attribute.
	 * @return bool             True on success, false on failure.
	 */
	public function set_attribute( int $attribute, $value ): bool;

	/**
	 * Get a connection attribute.
	 *
	 * The attributes correspond to the PDO::ATTR_* constants. Backends may
	 * support only a subset of the PDO attributes.
	 *
	 * @param  int   $attribute The attribute to get.
	 * @return mixed            The value of the attribute, or null when the
	 *                          attribute is not supported by the backend.
	 */
	public function get_attribute( int $attribute );

	/**
	 * Register a user-defined SQL function implemented as a PHP callback.
	 *
	 * This is supported only by connections with the "user_defined_functions"
	 * capability. Other connections return false, and the caller must avoid
	 * using the function in SQL queries.
	 *
	 * @param  string   $name     The SQL function name.
	 * @param  callable $callback The PHP callback implementing the function.
	 * @return bool               True on success, false when user-defined
	 *                            functions are not supported.
	 */
	public function create_function( string $name, callable $callback ): bool;

	/**
	 * Check whether the connection supports an optional capability.
	 *
	 * @param  string $capability One of the CAPABILITY_* constants.
	 * @return bool               True when the capability is supported.
	 */
	public function has_capability( string $capability ): bool;

	/**
	 * Set a logger for the queries.
	 *
	 * @param callable(string, array): void $logger A query logger callback.
	 */
	public function set_query_logger( callable $logger ): void;
}
