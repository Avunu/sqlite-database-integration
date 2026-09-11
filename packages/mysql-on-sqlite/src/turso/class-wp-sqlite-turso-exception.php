<?php declare(strict_types = 1);

/*
 * The Turso connection emulates the PDO SQLite error behavior:
 * phpcs:disable WordPress.DB.RestrictedClasses.mysql__PDO
 */

/**
 * An exception representing a Turso query or transport failure.
 *
 * The exception extends PDOException and mimics the PDO SQLite error shape
 * (SQLSTATE-prefixed messages, string SQLSTATE codes, and the "errorInfo"
 * property), so that the error handling of the MySQL-on-SQLite driver works
 * with the Turso backend unchanged.
 */
class WP_SQLite_Turso_Exception extends PDOException {
	/**
	 * Create an exception from a Turso error response.
	 *
	 * The error message is normalized to the PDO SQLite shape, e.g.:
	 *
	 *   SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: t.name
	 *
	 * Turso prefixes its messages with the stage that produced them
	 * ("Parse error: ", "Transaction error: "), which is stripped here so the
	 * driver sees the same text PDO SQLite would report.
	 *
	 * @param  string|null $error_code  The Turso error code, e.g. "PREPARE_ERROR".
	 * @param  string      $message     The original error message.
	 * @param  int|null    $http_status The HTTP status of the response, if any.
	 * @return self
	 */
	public static function from_server_error( ?string $error_code, string $message, ?int $http_status = null ): self {
		/*
		 * Strip Turso's decorations down to the SQLite message. The CLI sync
		 * server sends "<stage> error: <message>"; Turso Cloud wraps that again
		 * as "Tursodb error: <stage> error: <message>". The driver matches on
		 * the bare message to give errors their MySQL identity.
		 */
		$message = preg_replace(
			'/^(?:Tursodb error: )?(?:(?:Parse|Transaction|Runtime|Prepare|Execute) error: )?/',
			'',
			$message
		);

		if ( 1 === preg_match( '/\bconstraint failed\b/i', $message ) ) {
			// SQLite error code 19: SQLITE_CONSTRAINT.
			$sqlstate    = '23000';
			$driver_code = 19;
			$prefix      = 'Integrity constraint violation: 19 ';
		} elseif ( 1 === preg_match( '/\b(?:database is locked|database table is locked)\b/i', $message ) ) {
			// SQLite error codes 5 and 6: SQLITE_BUSY and SQLITE_LOCKED.
			$sqlstate    = 'HY000';
			$driver_code = 5;
			$prefix      = 'General error: 5 ';
		} else {
			// SQLite error code 1: SQLITE_ERROR (also used as a fallback).
			$sqlstate    = 'HY000';
			$driver_code = 1;
			$prefix      = 'General error: 1 ';
		}

		/*
		 * PDO splits the two: getMessage() carries the SQLSTATE and the driver's
		 * decoration, while errorInfo carries the driver's code and its
		 * *undecorated* message. The driver matches on errorInfo to recognise
		 * SQLite errors and give them their MySQL identity -- "no such table: x"
		 * becoming 42S02 / 1146 -- so the raw message has to survive there.
		 */
		$exception       = new self( sprintf( 'SQLSTATE[%s]: %s%s', $sqlstate, $prefix, $message ), 0 );
		$exception->code = $sqlstate;
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$exception->errorInfo = array( $sqlstate, $driver_code, $message );

		if ( null !== $error_code ) {
			$exception->turso_error_code = $error_code;
		}
		$exception->http_status = $http_status;

		return $exception;
	}

	/**
	 * Create an exception from a transport-level failure.
	 *
	 * These are connection problems and malformed responses rather than SQL
	 * errors, reported with the SQLSTATE PDO uses for general errors so the
	 * driver does not mistake them for constraint violations.
	 *
	 * @param  string $message The failure description.
	 * @return self
	 */
	public static function from_transport_failure( string $message ): self {
		$exception       = new self( sprintf( 'SQLSTATE[HY000]: General error: 1 %s', $message ), 0 );
		$exception->code = 'HY000';
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$exception->errorInfo = array( 'HY000', 1, $message );
		return $exception;
	}

	/**
	 * The Turso error code, when the failure came from the server.
	 *
	 * @var string|null
	 */
	public $turso_error_code;

	/**
	 * The HTTP status of the response, when there was one.
	 *
	 * @var int|null
	 */
	public $http_status;
}
