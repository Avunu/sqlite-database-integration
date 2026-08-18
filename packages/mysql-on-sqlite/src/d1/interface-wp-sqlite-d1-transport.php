<?php declare(strict_types = 1);

/**
 * D1 transport interface.
 *
 * A transport moves SQL statements and their results between PHP and a
 * Cloudflare D1 database, using the JSON-over-HTTP protocol served by the
 * D1 proxy worker (see the "d1-proxy-worker" package).
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
 * BLOB values are decoded to PHP binary strings. Note that reads report
 * accurate column names with zeroed write metadata, while writes report
 * accurate metadata (see the D1 proxy worker protocol documentation).
 */
interface WP_SQLite_D1_Transport_Interface {
	/**
	 * Execute a single SQL statement.
	 *
	 * @param  string $sql    The SQL statement to execute.
	 * @param  array  $params Positional query parameters.
	 * @return array          The result. See the interface description.
	 * @throws WP_SQLite_D1_Exception When the execution or transport fails.
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
	 * @throws WP_SQLite_D1_Exception When the execution or transport fails.
	 */
	public function batch( array $statements ): array;

	/**
	 * Get the last D1 session bookmark received from the server.
	 *
	 * Bookmarks provide read-your-writes consistency with D1 read
	 * replication. See:
	 * https://developers.cloudflare.com/d1/best-practices/read-replication/
	 *
	 * @return string|null The last session bookmark, if any.
	 */
	public function get_bookmark(): ?string;

	/**
	 * Seed the D1 session bookmark for subsequent requests.
	 *
	 * @param string|null $bookmark A session bookmark, e.g. one persisted
	 *                              from a previous PHP request.
	 */
	public function set_bookmark( ?string $bookmark ): void;
}
