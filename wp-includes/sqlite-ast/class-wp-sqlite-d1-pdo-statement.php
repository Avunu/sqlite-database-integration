<?php
/**
 * Cloudflare D1 PDO Statement implementation.
 *
 * This class extends PDOStatement to handle query execution against the
 * Cloudflare D1 API. It manages parameter binding and result fetching.
 *
 * @package wp-sqlite-integration
 * @since 3.0.0
 */

/*
 * The D1 PDO Statement uses PDO. Enable PDO function calls:
 * phpcs:disable WordPress.DB.RestrictedClasses.mysql__PDO
 * phpcs:disable WordPress.DB.RestrictedClasses.mysql__PDOStatement
 */

/**
 * Cloudflare D1 PDO Statement implementation.
 *
 * This class provides a PDOStatement-compatible interface for executing
 * queries against Cloudflare D1.
 */
class WP_SQLite_D1_PDO_Statement extends PDOStatement {
	/**
	 * The D1 PDO instance.
	 *
	 * @var WP_SQLite_D1_PDO
	 */
	private $pdo;

	/**
	 * The SQL query string.
	 *
	 * @var string
	 */
	private $query;

	/**
	 * Statement options.
	 *
	 * @var array
	 */
	private $options;

	/**
	 * The fetch mode for results.
	 *
	 * @var int
	 */
	private $fetch_mode = PDO::FETCH_ASSOC;

	/**
	 * Bound parameters.
	 *
	 * @var array
	 */
	private $bindings = array();

	/**
	 * Response data from D1 API.
	 *
	 * @var array
	 */
	private $responses = array();

	/**
	 * Constructor.
	 *
	 * @param WP_SQLite_D1_PDO $pdo     The D1 PDO instance.
	 * @param string           $query   The SQL query.
	 * @param array            $options Statement options.
	 */
	public function __construct( WP_SQLite_D1_PDO &$pdo, $query, $options = array() ) {
		$this->pdo     = $pdo;
		$this->query   = $query;
		$this->options = $options;
	}

	/**
	 * Sets the fetch mode.
	 *
	 * @param int   $mode The fetch mode.
	 * @param mixed ...$args Additional arguments.
	 *
	 * @return bool True on success.
	 */
	public function setFetchMode( $mode, ...$args ) {
		$this->fetch_mode = $mode;
		return true;
	}

	/**
	 * Binds a value to a parameter.
	 *
	 * @param mixed $param The parameter identifier.
	 * @param mixed $value The value to bind.
	 * @param int   $type  The data type (PDO::PARAM_*).
	 *
	 * @return bool True on success.
	 */
	public function bindValue( $param, $value, $type = PDO::PARAM_STR ) {
		$this->bindings[ $param ] = $this->cast_value( $value, $type );
		return true;
	}

	/**
	 * Casts a value to the appropriate type.
	 *
	 * @param mixed $value The value to cast.
	 * @param int   $type  The PDO parameter type.
	 *
	 * @return mixed The cast value.
	 */
	private function cast_value( $value, $type ) {
		switch ( $type ) {
			case PDO::PARAM_STR:
				return (string) $value;
			case PDO::PARAM_BOOL:
				return (bool) $value;
			case PDO::PARAM_INT:
				return (int) $value;
			case PDO::PARAM_NULL:
				return null;
			default:
				return $value;
		}
	}

	/**
	 * Executes the prepared statement.
	 *
	 * @param array|null $params Optional parameters to bind.
	 *
	 * @return bool True on success.
	 * @throws PDOException If execution fails.
	 */
	public function execute( $params = null ) {
		// Use provided params or bound params.
		$final_params = $this->bindings;
		if ( null !== $params ) {
			$final_params = $params;
		}

		// Ensure params are zero-indexed array.
		$final_params = array_values( $final_params );

		// Execute the query via D1 API.
		$this->responses = $this->pdo->execute_d1_query( $this->query, $final_params );

		// Extract last insert ID if present.
		if ( ! empty( $this->responses ) ) {
			$last_response = end( $this->responses );
			if ( isset( $last_response['meta']['last_row_id'] ) ) {
				$last_id = $last_response['meta']['last_row_id'];
				if ( 0 !== $last_id && null !== $last_id ) {
					$this->pdo->set_last_insert_id( null, $last_id );
				}
			}
		}

		return true;
	}

	/**
	 * Fetches all rows from the result set.
	 *
	 * @param int   $mode The fetch mode.
	 * @param mixed ...$args Additional arguments.
	 *
	 * @return array The result rows.
	 * @throws PDOException If the fetch mode is unsupported.
	 */
	public function fetchAll( $mode = PDO::FETCH_DEFAULT, ...$args ) {
		$rows = $this->get_rows_from_responses();

		switch ( $this->fetch_mode ) {
			case PDO::FETCH_ASSOC:
				return $rows;
			case PDO::FETCH_OBJ:
				return array_map(
					function ( $row ) {
						return (object) $row;
					},
					$rows
				);
			default:
				throw new PDOException( 'Unsupported fetch mode: ' . $this->fetch_mode );
		}
	}

	/**
	 * Returns the number of rows in the result set.
	 *
	 * @return int The row count.
	 */
	public function rowCount() {
		return count( $this->get_rows_from_responses() );
	}

	/**
	 * Extracts rows from the D1 API responses.
	 *
	 * @return array The extracted rows.
	 */
	private function get_rows_from_responses() {
		$all_rows = array();
		foreach ( $this->responses as $response ) {
			if ( isset( $response['results'] ) && is_array( $response['results'] ) ) {
				$all_rows = array_merge( $all_rows, $response['results'] );
			}
		}
		return $all_rows;
	}
}
