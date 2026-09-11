<?php declare(strict_types = 1);

/**
 * Turso transport interface.
 *
 * A transport moves SQL statements and their results between PHP and a Turso
 * database over "SQL over HTTP" (the Hrana pipeline endpoint). See
 * "serverless/PROTOCOL.md" in the Turso repository.
 *
 * Results are represented as plain arrays in the following shape:
 *
 *   array(
 *     'columns' => string[],  // Column names, in order. Duplicates allowed.
 *     'rows'    => array[],   // Rows as lists of positional values.
 *     'meta'    => array(     // Statement metadata.
 *       'changes'     => int, // The number of rows changed by a write.
 *       'last_row_id' => int, // The last inserted row ID, or 0.
 *     ),
 *   )
 *
 * BLOB values are decoded to PHP binary strings.
 */
interface WP_SQLite_Turso_Transport_Interface {
	/**
	 * Execute a single SQL statement.
	 *
	 * @param  string $sql    The SQL statement to execute.
	 * @param  array  $params Positional query parameters.
	 * @return array          The result. See the interface description.
	 * @throws WP_SQLite_Turso_Exception When the execution or transport fails.
	 */
	public function query( string $sql, array $params = array() ): array;

	/**
	 * Execute a batch of SQL statements atomically.
	 *
	 * When any statement fails, none of the statements take effect.
	 *
	 * @param  array<int, array{0: string, 1?: array}> $statements
	 *                The statements to execute, each being an array of
	 *                an SQL string and optional positional parameters.
	 * @return array[] One result per statement. See the interface description.
	 * @throws WP_SQLite_Turso_Exception When the execution or transport fails.
	 */
	public function batch( array $statements ): array;

	/**
	 * Set statements to run at the start of every request.
	 *
	 * Turso Cloud gives each HTTP request its own session, so connection-scoped
	 * state such as "PRAGMA foreign_keys" does not survive from one request to
	 * the next. Statements set here are sent ahead of every query and batch, in
	 * the same round trip, which makes the state hold as if the session did.
	 *
	 * @param string[] $statements SQL statements, run in order.
	 */
	public function set_session_statements( array $statements ): void;
}
