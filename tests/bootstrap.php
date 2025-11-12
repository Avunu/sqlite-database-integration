<?php

require_once __DIR__ . '/wp-sqlite-schema.php';
require_once __DIR__ . '/../version.php';
require_once __DIR__ . '/../wp-includes/parser/class-wp-parser-grammar.php';
require_once __DIR__ . '/../wp-includes/parser/class-wp-parser.php';
require_once __DIR__ . '/../wp-includes/parser/class-wp-parser-node.php';
require_once __DIR__ . '/../wp-includes/parser/class-wp-parser-token.php';
require_once __DIR__ . '/../wp-includes/mysql/class-wp-mysql-token.php';
require_once __DIR__ . '/../wp-includes/mysql/class-wp-mysql-lexer.php';
require_once __DIR__ . '/../wp-includes/mysql/class-wp-mysql-parser.php';
require_once __DIR__ . '/../wp-includes/sqlite/class-wp-sqlite-query-rewriter.php';
require_once __DIR__ . '/../wp-includes/sqlite/class-wp-sqlite-lexer.php';
require_once __DIR__ . '/../wp-includes/sqlite/class-wp-sqlite-token.php';
require_once __DIR__ . '/../wp-includes/sqlite/class-wp-sqlite-pdo-user-defined-functions.php';
require_once __DIR__ . '/../wp-includes/sqlite/class-wp-sqlite-translator.php';
require_once __DIR__ . '/../wp-includes/sqlite-ast/class-wp-sqlite-connection.php';
require_once __DIR__ . '/../wp-includes/sqlite-ast/class-wp-sqlite-configurator.php';
require_once __DIR__ . '/../wp-includes/sqlite-ast/class-wp-sqlite-driver.php';
require_once __DIR__ . '/../wp-includes/sqlite-ast/class-wp-sqlite-driver-exception.php';
require_once __DIR__ . '/../wp-includes/sqlite-ast/class-wp-sqlite-information-schema-builder.php';
require_once __DIR__ . '/../wp-includes/sqlite-ast/class-wp-sqlite-information-schema-exception.php';
require_once __DIR__ . '/../wp-includes/sqlite-ast/class-wp-sqlite-information-schema-reconstructor.php';
require_once __DIR__ . '/../wp-includes/sqlite-ast/class-wp-sqlite-d1-pdo.php';
require_once __DIR__ . '/../wp-includes/sqlite-ast/class-wp-sqlite-d1-pdo-statement.php';

// Configure the test environment.
error_reporting( E_ALL );
define( 'FQDB', ':memory:' );
define( 'FQDBDIR', __DIR__ . '/../testdb' );

// Polyfill WPDB globals.
$GLOBALS['table_prefix'] = 'wptests_';
$GLOBALS['wpdb']         = new class() {
	public function set_prefix( string $prefix ): void {}
};

/**
 * Polyfills for WordPress functions
 */
if ( ! function_exists( 'do_action' ) ) {
	/**
	 * Polyfill the do_action function.
	 */
	function do_action() {}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Polyfill the apply_filters function.
	 *
	 * @param string $tag The filter name.
	 * @param mixed  $value The value to filter.
	 * @param mixed  ...$args Additional arguments to pass to the filter.
	 *
	 * @return mixed Returns $value.
	 */
	function apply_filters( $tag, $value, ...$args ) {
		return $value;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * Polyfill the wp_json_encode function.
	 *
	 * @param mixed $data The data to encode.
	 * @param int   $options Optional. JSON encode options.
	 * @param int   $depth Optional. Maximum depth.
	 *
	 * @return string|false The JSON encoded string or false on failure.
	 */
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}

if ( ! function_exists( 'wp_remote_post' ) ) {
	/**
	 * Polyfill the wp_remote_post function for testing.
	 *
	 * @param string $url The URL to post to.
	 * @param array  $args Request arguments.
	 *
	 * @return array|WP_Error Mock response.
	 */
	function wp_remote_post( $url, $args = array() ) {
		// Return a mock error for testing.
		return new WP_Error( 'http_request_failed', 'Mock HTTP request not configured for testing' );
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	/**
	 * Polyfill the wp_remote_retrieve_response_code function.
	 *
	 * @param array|WP_Error $response The response.
	 *
	 * @return int The response code.
	 */
	function wp_remote_retrieve_response_code( $response ) {
		if ( is_wp_error( $response ) ) {
			return 0;
		}
		return isset( $response['response']['code'] ) ? (int) $response['response']['code'] : 0;
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	/**
	 * Polyfill the wp_remote_retrieve_body function.
	 *
	 * @param array|WP_Error $response The response.
	 *
	 * @return string The response body.
	 */
	function wp_remote_retrieve_body( $response ) {
		if ( is_wp_error( $response ) ) {
			return '';
		}
		return isset( $response['body'] ) ? $response['body'] : '';
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	/**
	 * Polyfill the is_wp_error function.
	 *
	 * @param mixed $thing The object to check.
	 *
	 * @return bool True if $thing is WP_Error.
	 */
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * WordPress Error class polyfill.
	 */
	class WP_Error {
		/**
		 * Error code.
		 *
		 * @var string
		 */
		private $code;

		/**
		 * Error message.
		 *
		 * @var string
		 */
		private $message;

		/**
		 * Constructor.
		 *
		 * @param string $code Error code.
		 * @param string $message Error message.
		 */
		public function __construct( $code = '', $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}

		/**
		 * Get error message.
		 *
		 * @return string The error message.
		 */
		public function get_error_message() {
			return $this->message;
		}

		/**
		 * Get error code.
		 *
		 * @return string The error code.
		 */
		public function get_error_code() {
			return $this->code;
		}
	}
}

/**
 * Polyfills for php 7 & 8 functions
 */

if ( ! function_exists( 'str_starts_with' ) ) {
	/**
	 * Check if a string starts with a specific substring.
	 *
	 * @param string $haystack The string to search in.
	 * @param string $needle The string to search for.
	 *
	 * @see https://www.php.net/manual/en/function.str-starts-with
	 *
	 * @return bool
	 */
	function str_starts_with( string $haystack, string $needle ) {
		return empty( $needle ) || 0 === strpos( $haystack, $needle );
	}
}

if ( ! function_exists( 'str_contains' ) ) {
	/**
	 * Check if a string contains a specific substring.
	 *
	 * @param string $haystack The string to search in.
	 * @param string $needle The string to search for.
	 *
	 * @see https://www.php.net/manual/en/function.str-contains
	 *
	 * @return bool
	 */
	function str_contains( string $haystack, string $needle ) {
		return empty( $needle ) || false !== strpos( $haystack, $needle );
	}
}

if ( ! function_exists( 'str_ends_with' ) ) {
	/**
	 * Check if a string ends with a specific substring.
	 *
	 * @param string $haystack The string to search in.
	 * @param string $needle The string to search for.
	 *
	 * @see https://www.php.net/manual/en/function.str-ends-with
	 *
	 * @return bool
	 */
	function str_ends_with( string $haystack, string $needle ) {
		return empty( $needle ) || substr( $haystack, -strlen( $needle ) ) === $needle;
	}
}
if ( extension_loaded( 'mbstring' ) ) {

	if ( ! function_exists( 'mb_str_starts_with' ) ) {
		/**
		 * Polyfill for mb_str_starts_with.
		 *
		 * @param string $haystack The string to search in.
		 * @param string $needle   The string to search for.
		 *
		 * @return bool
		 */
		function mb_str_starts_with( string $haystack, string $needle ) {
			return empty( $needle ) || 0 === mb_strpos( $haystack, $needle );
		}
	}

	if ( ! function_exists( 'mb_str_contains' ) ) {
		/**
		 * Polyfill for mb_str_contains.
		 *
		 * @param string $haystack The string to search in.
		 * @param string $needle   The string to search for.
		 *
		 * @return bool
		 */
		function mb_str_contains( string $haystack, string $needle ) {
			return empty( $needle ) || false !== mb_strpos( $haystack, $needle );
		}
	}

	if ( ! function_exists( 'mb_str_ends_with' ) ) {
		/**
		 * Polyfill for mb_str_ends_with.
		 *
		 * @param string $haystack The string to search in.
		 * @param string $needle   The string to search for.
		 *
		 * @return bool
		 */
		function mb_str_ends_with( string $haystack, string $needle ) {
			// phpcs:ignore Squiz.PHP.DisallowMultipleAssignments.Found
			return empty( $needle ) || $needle = mb_substr( $haystack, - mb_strlen( $needle ) );
		}
	}
}
