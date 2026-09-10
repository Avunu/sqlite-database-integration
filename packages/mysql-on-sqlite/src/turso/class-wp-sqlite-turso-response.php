<?php declare(strict_types = 1);

/**
 * Encoding and decoding of Turso's "SQL over HTTP" value protocol.
 *
 * Turso tags every value with its SQLite storage class:
 *
 *   { "type": "null" }
 *   { "type": "integer", "value": "42" }     // decimal string, not a number
 *   { "type": "float",   "value": 1.5 }
 *   { "type": "text",    "value": "abc" }
 *   { "type": "blob",    "base64": "AAEC" } // padding may be omitted
 *
 * Integers travel as strings because JSON numbers cannot carry the full 64-bit
 * range. They are decoded back to PHP integers where that is lossless and left
 * as strings where it is not, which matches what PDO SQLite hands back for
 * values beyond PHP's integer range.
 */
class WP_SQLite_Turso_Response {
	/**
	 * Encode query parameters as Turso protocol values.
	 *
	 * @param  array $params The positional query parameters.
	 * @return array         The JSON-encodable argument list.
	 */
	public static function encode_params( array $params ): array {
		$encoded = array();
		foreach ( array_values( $params ) as $value ) {
			$encoded[] = self::encode_value( $value );
		}
		return $encoded;
	}

	/**
	 * Encode a single PHP value as a Turso protocol value.
	 *
	 * @param  mixed $value The PHP value.
	 * @return array        The JSON-encodable value.
	 */
	public static function encode_value( $value ): array {
		if ( null === $value ) {
			return array( 'type' => 'null' );
		}
		if ( is_bool( $value ) ) {
			// SQLite has no boolean storage class; PDO binds these as integers.
			return array(
				'type'  => 'integer',
				'value' => $value ? '1' : '0',
			);
		}
		if ( is_int( $value ) ) {
			return array(
				'type'  => 'integer',
				'value' => (string) $value,
			);
		}
		if ( is_float( $value ) ) {
			return array(
				'type'  => 'float',
				'value' => $value,
			);
		}
		if ( is_string( $value ) && ! preg_match( '//u', $value ) ) {
			// Not valid UTF-8. Send binary strings as BLOB values.
			return array(
				'type'   => 'blob',
				'base64' => base64_encode( $value ),
			);
		}
		return array(
			'type'  => 'text',
			'value' => (string) $value,
		);
	}

	/**
	 * Decode a statement result from a pipeline response.
	 *
	 * @param  mixed $result The decoded JSON "result" object.
	 * @return array{columns: string[], rows: array[], meta: array} The result.
	 * @throws WP_SQLite_Turso_Exception When the result shape is invalid.
	 */
	public static function decode_result( $result ): array {
		if (
			! is_array( $result )
			|| ! isset( $result['cols'], $result['rows'] )
			|| ! is_array( $result['cols'] )
			|| ! is_array( $result['rows'] )
		) {
			throw WP_SQLite_Turso_Exception::from_transport_failure(
				'Turso returned a statement result with an invalid shape.'
			);
		}

		$columns = array();
		foreach ( $result['cols'] as $column ) {
			$columns[] = is_array( $column ) ? (string) ( $column['name'] ?? '' ) : (string) $column;
		}

		$rows = array();
		foreach ( $result['rows'] as $row ) {
			$values = array();
			foreach ( (array) $row as $value ) {
				$values[] = self::decode_value( $value );
			}
			$rows[] = $values;
		}

		/*
		 * Turso reports affected_row_count as 0 and last_insert_rowid as null
		 * for every statement, so the protocol trait asks for changes() and
		 * last_insert_rowid() alongside each write and fills these in. See
		 * WP_SQLite_Turso_Protocol::query().
		 */
		return array(
			'columns' => $columns,
			'rows'    => $rows,
			'meta'    => array(
				'changes'     => (int) ( $result['affected_row_count'] ?? 0 ),
				'last_row_id' => (int) ( $result['last_insert_rowid'] ?? 0 ),
			),
		);
	}

	/**
	 * Decode a single protocol value to a PHP value.
	 *
	 * @param  mixed $value The decoded JSON value.
	 * @return mixed        The PHP value.
	 */
	public static function decode_value( $value ) {
		if ( ! is_array( $value ) || ! isset( $value['type'] ) ) {
			// Already a bare scalar; nothing to unwrap.
			return $value;
		}

		switch ( $value['type'] ) {
			case 'null':
				return null;
			case 'integer':
				$raw = (string) ( $value['value'] ?? '0' );
				// Keep values outside PHP's integer range as strings, as PDO does.
				$int = (int) $raw;
				return (string) $int === $raw ? $int : $raw;
			case 'float':
				return (float) ( $value['value'] ?? 0.0 );
			case 'blob':
				// Turso may omit base64 padding; base64_decode() tolerates that.
				return (string) base64_decode( (string) ( $value['base64'] ?? '' ) );
			case 'text':
			default:
				return isset( $value['value'] ) ? (string) $value['value'] : null;
		}
	}

	/**
	 * Build an exception from a Turso error object.
	 *
	 * @param  mixed    $error       The decoded JSON "error" object.
	 * @param  int|null $http_status The HTTP status of the response, if any.
	 * @return WP_SQLite_Turso_Exception The exception representing the error.
	 */
	public static function decode_error( $error, ?int $http_status = null ): WP_SQLite_Turso_Exception {
		$code    = null;
		$message = null;
		if ( is_array( $error ) ) {
			$code    = isset( $error['code'] ) && is_string( $error['code'] ) ? $error['code'] : null;
			$message = isset( $error['message'] ) && is_string( $error['message'] ) ? $error['message'] : null;
		}
		if ( null === $message ) {
			$message = null !== $http_status
				? sprintf( 'Turso returned an error with HTTP status %d.', $http_status )
				: 'Turso returned an error without a message.';
		}
		return WP_SQLite_Turso_Exception::from_server_error( $code, $message, $http_status );
	}
}
