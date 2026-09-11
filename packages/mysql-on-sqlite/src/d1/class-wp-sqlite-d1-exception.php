<?php declare(strict_types = 1);

/*
 * The D1 connection emulates the PDO SQLite error behavior:
 * phpcs:disable WordPress.DB.RestrictedClasses.mysql__PDO
 */

/**
 * An exception representing a D1 query or transport failure.
 *
 * The exception extends PDOException and mimics the PDO SQLite error shape
 * (SQLSTATE-prefixed messages, string SQLSTATE codes, and the "errorInfo"
 * property), so that the error handling of the MySQL-on-SQLite driver works
 * with the D1 backend unchanged.
 */
class WP_SQLite_D1_Exception extends PDOException {
	/**
	 * Create an exception from a proxy error response.
	 *
	 * The error message is normalized to the PDO SQLite shape, e.g.:
	 *
	 *   SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: t.name
	 *
	 * D1 error messages wrap the SQLite error message with a "D1_ERROR: "
	 * style prefix and an ": SQLITE_*" suffix, which are stripped here.
	 *
	 * @param  string|null $error_code  The proxy error code, e.g. "SQLITE_CONSTRAINT".
	 * @param  string      $message     The original error message.
	 * @param  int|null    $http_status The HTTP status of the response, if any.
	 * @return self
	 */
	public static function from_proxy_error( ?string $error_code, string $message, ?int $http_status = null ): self {
		// Strip the D1 message decorations around the SQLite error message.
		$message = preg_replace( '/^D1_[A-Z_]*ERROR: /', '', $message );
		$message = preg_replace( '/: SQLITE_[A-Z_]+$/', '', $message );

		if ( null !== $error_code && 0 === strpos( $error_code, 'SQLITE_CONSTRAINT' ) ) {
			// SQLite error code 19: SQLITE_CONSTRAINT.
			$sqlstate    = '23000';
			$driver_code = 19;
			$prefix      = 'Integrity constraint violation: 19 ';
		} elseif ( 'SQLITE_BUSY' === $error_code || 'SQLITE_LOCKED' === $error_code ) {
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

		return self::create( $sqlstate, $prefix . $message, $driver_code, $message );
	}

	/**
	 * Create an exception from a transport-level failure.
	 *
	 * These are connection failures — network errors, timeouts, or invalid
	 * proxy responses — where the statement may or may not have been applied.
	 *
	 * @param  string         $message  The error message.
	 * @param  Throwable|null $previous The previous exception, if any.
	 * @return self
	 */
	public static function from_transport_failure( string $message, ?Throwable $previous = null ): self {
		return self::create( '08006', $message, null, $message, $previous );
	}

	/**
	 * Create an exception with the PDO error shape.
	 *
	 * PDO splits the two: getMessage() carries the SQLSTATE and the driver's
	 * decoration, while errorInfo carries the driver's own code and its
	 * *undecorated* message. The driver matches on errorInfo to recognise
	 * SQLite errors and give them their MySQL identity, so the raw message has
	 * to survive there.
	 *
	 * @param  string         $sqlstate       The SQLSTATE error code.
	 * @param  string         $message        The decorated message, as PDO renders it.
	 * @param  int|null       $driver_code    The SQLite error code, if known.
	 * @param  string|null    $driver_message The undecorated driver message.
	 * @param  Throwable|null $previous       The previous exception, if any.
	 * @return self
	 */
	private static function create(
		string $sqlstate,
		string $message,
		?int $driver_code = null,
		?string $driver_message = null,
		?Throwable $previous = null
	): self {
		$exception = new self(
			sprintf( 'SQLSTATE[%s]: %s', $sqlstate, $message ),
			0,
			$previous
		);

		// As PDO does, expose the SQLSTATE as a string exception code.
		// It cannot be passed to the constructor, which expects an integer.
		$exception->code = $sqlstate;

		// The "errorInfo" property name is defined by PDOException.
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$exception->errorInfo = array( $sqlstate, $driver_code, $driver_message ?? $message );
		return $exception;
	}
}
