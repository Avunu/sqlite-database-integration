<?php

/**
 * Load the Cloudflare D1 connection backend for the MySQL-on-SQLite driver.
 *
 * This loader is not part of the default driver loading. It is required
 * explicitly by applications using the D1 backend, after (or instead of)
 * the main "src/load.php" loader.
 */
require_once __DIR__ . '/../load.php';

require_once __DIR__ . '/interface-wp-sqlite-d1-transport.php';
require_once __DIR__ . '/class-wp-sqlite-d1-exception.php';
require_once __DIR__ . '/class-wp-sqlite-d1-response.php';
require_once __DIR__ . '/class-wp-sqlite-d1-connection.php';
require_once __DIR__ . '/class-wp-sqlite-d1-http-transport.php';

/*
 * The D1 transport has an optional native (e.g. Rust) client implementation
 * providing an HTTP connection pool that persists across PHP requests. When
 * the native extension is loaded, it pre-declares WP_SQLite_D1_Native_Client,
 * and the native transport is preferred over the pure-PHP cURL transport.
 */
if ( class_exists( 'WP_SQLite_D1_Native_Client', false ) ) {
	require_once __DIR__ . '/class-wp-sqlite-d1-native-transport.php';
}

/**
 * Create the best available D1 transport.
 *
 * Uses the native client transport when the extension is loaded, and the
 * pure-PHP cURL transport otherwise.
 *
 * @param  string      $url     The base URL of the D1 proxy.
 * @param  string|null $token   Optional. A bearer token for the proxy.
 * @param  array       $options Optional. Transport options.
 * @return WP_SQLite_D1_Transport_Interface The transport.
 */
function wp_sqlite_d1_create_transport( string $url, ?string $token = null, array $options = array() ): WP_SQLite_D1_Transport_Interface {
	if ( class_exists( 'WP_SQLite_D1_Native_Transport', false ) ) {
		return new WP_SQLite_D1_Native_Transport( $url, $token, $options );
	}
	return new WP_SQLite_D1_HTTP_Transport( $url, $token, $options );
}
