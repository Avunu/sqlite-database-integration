<?php declare(strict_types = 1);

/**
 * Shared encoding and decoding of the D1 proxy worker protocol.
 *
 * This class implements the value encoding rules of the D1 proxy protocol,
 * shared by all transport implementations, so that transports differ only
 * in how the bytes are moved:
 *
 *   - SQLite BLOB values, which JSON cannot express, are wrapped as
 *     '{ "$type": "blob", "b64": "<base64>" }' objects in both directions.
 *     PHP strings that are not valid UTF-8 are treated as BLOB values.
 *   - Result rows are columnar: a "columns" list and positional "rows".
 */
class WP_SQLite_D1_Response {
	/**
	 * Encode query parameters for a proxy request.
	 *
	 * @param  array $params The positional query parameters.
	 * @return array         The JSON-encodable parameters.
	 */
	public static function encode_params( array $params ): array {
		$encoded = array();
		foreach ( array_values( $params ) as $value ) {
			if ( is_string( $value ) && ! preg_match( '//u', $value ) ) {
				// Not valid UTF-8. Send binary strings as BLOB values.
				$value = array(
					'$type' => 'blob',
					'b64'   => base64_encode( $value ),
				);
			}
			$encoded[] = $value;
		}
		return $encoded;
	}

	/**
	 * Decode a result object from a proxy response.
	 *
	 * @param  mixed $result The decoded JSON result object.
	 * @return array{columns: string[], rows: array[], meta: array} The result.
	 * @throws WP_SQLite_D1_Exception When the result shape is invalid.
	 */
	public static function decode_result( $result ): array {
		if (
			! is_array( $result )
			|| ! isset( $result['columns'], $result['rows'] )
			|| ! is_array( $result['columns'] )
			|| ! is_array( $result['rows'] )
		) {
			throw WP_SQLite_D1_Exception::from_transport_failure(
				'The D1 proxy returned a result with an invalid shape.'
			);
		}

		$rows = array();
		foreach ( $result['rows'] as $row ) {
			$values = array();
			foreach ( $row as $value ) {
				$values[] = self::decode_value( $value );
			}
			$rows[] = $values;
		}

		$meta = is_array( $result['meta'] ?? null ) ? $result['meta'] : array();
		return array(
			'columns' => $result['columns'],
			'rows'    => $rows,
			'meta'    => array(
				'changes'     => (int) ( $meta['changes'] ?? 0 ),
				'last_row_id' => (int) ( $meta['last_row_id'] ?? 0 ),
			),
		);
	}

	/**
	 * Decode a single result value from a proxy response.
	 *
	 * @param  mixed $value The decoded JSON value.
	 * @return mixed        The PHP value.
	 */
	public static function decode_value( $value ) {
		if (
			is_array( $value )
			&& isset( $value['$type'], $value['b64'] )
			&& 'blob' === $value['$type']
		) {
			return base64_decode( $value['b64'] );
		}
		return $value;
	}

	/**
	 * Extract a structured error from a proxy error response body.
	 *
	 * @param  mixed $body        The decoded JSON response body.
	 * @param  int   $http_status The HTTP status of the response.
	 * @return WP_SQLite_D1_Exception The exception representing the error.
	 */
	public static function decode_error( $body, int $http_status ): WP_SQLite_D1_Exception {
		$error = is_array( $body ) && is_array( $body['error'] ?? null ) ? $body['error'] : array();
		$code  = isset( $error['code'] ) && is_string( $error['code'] ) ? $error['code'] : null;

		if ( isset( $error['message'] ) && is_string( $error['message'] ) ) {
			$message = $error['message'];
		} else {
			$message = sprintf( 'The D1 proxy returned an error with HTTP status %d.', $http_status );
		}

		return WP_SQLite_D1_Exception::from_proxy_error( $code, $message, $http_status );
	}
}
