<?php

/**
 * Load the Turso connection backend for the MySQL-on-SQLite driver.
 *
 * This loader is not part of the default driver loading. It is required
 * explicitly by applications using the Turso backend, after (or instead of)
 * the main "src/load.php" loader.
 */
require_once __DIR__ . '/../load.php';

require_once __DIR__ . '/interface-wp-sqlite-turso-transport.php';
require_once __DIR__ . '/class-wp-sqlite-turso-exception.php';
require_once __DIR__ . '/class-wp-sqlite-turso-response.php';
require_once __DIR__ . '/trait-wp-sqlite-turso-protocol.php';
require_once __DIR__ . '/class-wp-sqlite-turso-http-transport.php';
require_once __DIR__ . '/class-wp-sqlite-turso-connection.php';
require_once __DIR__ . '/class-wp-sqlite-turso-replica-connection.php';

/*
 * The Turso transport has an optional native (e.g. Rust) client providing an
 * HTTP connection pool that persists across PHP requests. When the extension is
 * loaded it pre-declares WP_SQLite_Turso_Native_Client, and the native
 * transport is preferred over the pure-PHP cURL one.
 */
if ( class_exists( 'WP_SQLite_Turso_Native_Client', false ) ) {
	require_once __DIR__ . '/class-wp-sqlite-turso-native-transport.php';
}

/**
 * Create the best available Turso transport.
 *
 * @param  string      $url     The base URL of the Turso database.
 * @param  string|null $token   Optional. A bearer token.
 * @param  array       $options Optional. Transport options.
 * @return WP_SQLite_Turso_Transport_Interface The transport.
 */
function wp_sqlite_turso_create_transport( string $url, ?string $token = null, array $options = array() ): WP_SQLite_Turso_Transport_Interface {
	if ( class_exists( 'WP_SQLite_Turso_Native_Transport', false ) ) {
		return new WP_SQLite_Turso_Native_Transport( $url, $token, $options );
	}
	return new WP_SQLite_Turso_HTTP_Transport( $url, $token, $options );
}

/**
 * Create a Turso connection for the driver.
 *
 * With a snapshot path, the result reads from that file and writes to the
 * primary, latching to the primary after the first write. Without one, every
 * statement goes to the primary.
 *
 * @param  string      $url           The base URL of the Turso database.
 * @param  string|null $token         Optional. A bearer token.
 * @param  string|null $snapshot_path Optional. Path to a published snapshot.
 * @param  array       $options       Optional. Transport and connection options.
 * @return WP_SQLite_Connection_Interface The connection.
 */
function wp_sqlite_turso_create_connection(
	string $url,
	?string $token = null,
	?string $snapshot_path = null,
	array $options = array()
): WP_SQLite_Connection_Interface {
	$primary = new WP_SQLite_Turso_Connection(
		wp_sqlite_turso_create_transport( $url, $token, $options ),
		$options
	);

	if ( null === $snapshot_path || '' === $snapshot_path ) {
		return $primary;
	}

	/*
	 * The snapshot is opened read-only. Verified against a real front end: with
	 * the file chmod 444 every public path still renders, because the driver
	 * only writes while changing the schema and the front end does not.
	 *
	 * The publisher is expected to hand over a rollback-journal database. A
	 * WAL-mode file would make SQLite create "-wal"/"-shm" beside it, and those
	 * are keyed by path rather than inode, so they would outlive the publisher's
	 * rename and describe the previous snapshot.
	 */
	$reader = new WP_SQLite_Connection(
		array(
			'path'         => $snapshot_path,
			// Match what the publisher wrote. WP_SQLite_Connection *sets* this
			// pragma, and setting WAL on a read-only rollback-journal file is a
			// write it cannot make.
			'journal_mode' => $options['snapshot_journal_mode'] ?? 'DELETE',
		)
	);

	return new WP_SQLite_Turso_Replica_Connection( $reader, $primary );
}
