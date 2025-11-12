<?php
/**
 * Cloudflare D1 PDO implementation.
 *
 * This class extends PDO to proxy queries to the Cloudflare D1 API instead of
 * executing them locally. It maintains compatibility with the existing SQLite
 * driver architecture while enabling cloud-native database operations.
 *
 * @package wp-sqlite-integration
 * @since 3.0.0
 */

/*
 * The D1 PDO uses PDO. Enable PDO function calls:
 * phpcs:disable WordPress.DB.RestrictedClasses.mysql__PDO
 */

/**
 * Cloudflare D1 PDO implementation.
 *
 * This class implements a PDO-compatible interface that sends queries to
 * Cloudflare's D1 database API instead of using a local SQLite file.
 */
class WP_SQLite_D1_PDO extends PDO {
	/**
	 * The Cloudflare API base URL.
	 *
	 * @var string
	 */
	private $api_url;

	/**
	 * The Cloudflare account ID.
	 *
	 * @var string
	 */
	private $account_id;

	/**
	 * The Cloudflare D1 database ID.
	 *
	 * @var string
	 */
	private $database_id;

	/**
	 * The Cloudflare API token.
	 *
	 * @var string
	 */
	private $api_token;

	/**
	 * Array to store last insert IDs by name.
	 *
	 * @var array
	 */
	private $last_insert_ids = array();

	/**
	 * Whether we're currently in a transaction.
	 *
	 * @var bool
	 */
	private $in_transaction = false;

	/**
	 * Constructor.
	 *
	 * @param string $dsn         Not used for D1, but required for PDO compatibility.
	 * @param string $account_id  Cloudflare account ID.
	 * @param string $database_id Cloudflare D1 database ID.
	 * @param string $api_token   Cloudflare API token.
	 * @param string $api_url     Optional. Cloudflare API base URL. Default is the production URL.
	 */
	public function __construct( $dsn, $account_id, $database_id, $api_token, $api_url = 'https://api.cloudflare.com/client/v4' ) {
		$this->account_id  = $account_id;
		$this->database_id = $database_id;
		$this->api_token   = $api_token;
		$this->api_url     = $api_url;

		// Call parent constructor with in-memory SQLite to satisfy PDO requirements.
		// This in-memory database is not actually used; all queries go to D1.
		parent::__construct( 'sqlite::memory:' );
	}

	/**
	 * Prepares a statement for execution.
	 *
	 * @param string $query   The SQL query to prepare.
	 * @param array  $options Driver options (not used for D1).
	 *
	 * @return WP_SQLite_D1_PDO_Statement|false The prepared statement or false on failure.
	 */
	public function prepare( $query, $options = array() ) {
		return new WP_SQLite_D1_PDO_Statement( $this, $query, $options );
	}

	/**
	 * Executes a query against the Cloudflare D1 API.
	 *
	 * @param string $sql    The SQL query to execute.
	 * @param array  $params Query parameters.
	 *
	 * @return array The response from the D1 API.
	 * @throws PDOException If the query fails.
	 */
	public function execute_d1_query( $sql, $params = array() ) {
		$endpoint = sprintf(
			'%s/accounts/%s/d1/database/%s/query',
			$this->api_url,
			$this->account_id,
			$this->database_id
		);

		$body = wp_json_encode(
			array(
				'sql'    => $sql,
				'params' => array_values( $params ),
			)
		);

		$response = wp_remote_post(
			$endpoint,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->api_token,
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
				),
				'body'    => $body,
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new PDOException( $response->get_error_message() );
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );
		$data          = json_decode( $response_body, true );

		if ( 200 !== $response_code || ! isset( $data['success'] ) || ! $data['success'] ) {
			$error_message = isset( $data['errors'][0]['message'] ) ? $data['errors'][0]['message'] : 'Unknown D1 API error';
			$error_code    = isset( $data['errors'][0]['code'] ) ? (int) $data['errors'][0]['code'] : 0;
			throw new PDOException( $error_message, $error_code );
		}

		return isset( $data['result'] ) ? $data['result'] : array();
	}

	/**
	 * Sets the last insert ID.
	 *
	 * @param string|null $name  Optional. The sequence name. Default null.
	 * @param mixed       $value The last insert ID value.
	 */
	public function set_last_insert_id( $name = null, $value = null ) {
		if ( null === $name ) {
			$name = 'id';
		}
		$this->last_insert_ids[ $name ] = $value;
	}

	/**
	 * Returns the ID of the last inserted row.
	 *
	 * @param string|null $name Optional. The sequence name. Default null.
	 *
	 * @return string|false The last insert ID or false if not set.
	 */
	public function lastInsertId( $name = null ) {
		if ( null === $name ) {
			$name = 'id';
		}
		return isset( $this->last_insert_ids[ $name ] ) ? (string) $this->last_insert_ids[ $name ] : false;
	}

	/**
	 * Begins a transaction.
	 *
	 * @return bool True on success.
	 */
	public function beginTransaction() {
		$this->in_transaction = true;
		return true;
	}

	/**
	 * Commits a transaction.
	 *
	 * @return bool True on success.
	 */
	public function commit() {
		$this->in_transaction = false;
		return true;
	}

	/**
	 * Checks if inside a transaction.
	 *
	 * @return bool True if in a transaction.
	 */
	public function inTransaction() {
		return $this->in_transaction;
	}
}
