<?php

/**
 * Verify that the native D1 client extension is loaded and selected.
 *
 * Usage:
 *   php -d extension=/path/to/libwp_d1_client.so tests/tools/verify-native-d1-client-extension.php
 */

if ( ! class_exists( 'WP_SQLite_D1_Native_Client', false ) ) {
	fwrite( STDERR, "FAIL: The WP_SQLite_D1_Native_Client class is not pre-declared.\n" );
	fwrite( STDERR, "Is the wp_d1_client extension loaded?\n" );
	exit( 1 );
}

require_once __DIR__ . '/../../src/d1/load.php';

if ( ! class_exists( 'WP_SQLite_D1_Native_Transport', false ) ) {
	fwrite( STDERR, "FAIL: The native D1 transport was not loaded.\n" );
	exit( 1 );
}

$transport = wp_sqlite_d1_create_transport( 'http://127.0.0.1:1' );
if ( ! $transport instanceof WP_SQLite_D1_Native_Transport ) {
	fwrite( STDERR, 'FAIL: Expected the native transport, got ' . get_class( $transport ) . ".\n" );
	exit( 1 );
}

// A request against an unroutable endpoint must fail with a transport
// exception (not a crash), proving the native call path works.
try {
	$transport->query( 'SELECT 1' );
	fwrite( STDERR, "FAIL: Expected a transport failure.\n" );
	exit( 1 );
} catch ( WP_SQLite_D1_Exception $e ) {
	if ( false === strpos( $e->getMessage(), 'wp_d1_client' ) ) {
		fwrite( STDERR, 'FAIL: Unexpected error: ' . $e->getMessage() . "\n" );
		exit( 1 );
	}
}

echo 'OK: wp_d1_client ' . WP_SQLite_D1_Native_Client::version() . " is loaded and selected.\n";
