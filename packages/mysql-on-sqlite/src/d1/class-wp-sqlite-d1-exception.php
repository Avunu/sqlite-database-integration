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
	 * @param  string|null $error_code  The proxy error code, e.g. "SQLITE_CONSTRAINT".
	 * @param  string      $message     The original error message.
	 * @param  int|null    $http_status The HTTP status of the response, if any.
	 * @return self
	 */
	public static function from_proxy_error( ?string $error_code, string $message, ?int $http_status = null ): self {
		if ( null !== $error_code && 0 === strpos( $error_code, 'SQLITE_CONSTRAINT' ) ) {
			$sqlstate = '23000';
		} else {
			$sqlstate = 'HY000';
		}

		return self::create( $sqlstate, $message );
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
		return self::create( '08006', $message, $previous );
	}

	/**
	 * Create an exception with the PDO error shape.
	 *
	 * @param  string         $sqlstate The SQLSTATE error code.
	 * @param  string         $message  The error message.
	 * @param  Throwable|null $previous The previous exception, if any.
	 * @return self
	 */
	private static function create( string $sqlstate, string $message, ?Throwable $previous = null ): self {
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
		$exception->errorInfo = array( $sqlstate, null, $message );
		return $exception;
	}
}
