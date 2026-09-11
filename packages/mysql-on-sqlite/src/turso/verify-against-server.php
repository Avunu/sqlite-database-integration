<?php
/**
 * End-to-end check of the Turso split connection against a real server.
 *
 * The PHPUnit suite runs over a fake transport, which cannot show that the
 * protocol is right -- only that the driver copes with the backend's semantics.
 * This script talks to an actual Turso server, so it covers the parts a fake
 * cannot: the wire encoding, the changes()/last_insert_rowid() ride-along that
 * supplies write metadata Turso does not report, the BEGIN/COMMIT/ROLLBACK
 * batch wrapping that makes a batch atomic, and the latch that gives a request
 * read-your-writes across two different databases.
 *
 *   tursodb primary.db --sync-server 127.0.0.1:8080
 *   # publish a snapshot, then:
 *   SNAPSHOT=/path/snapshot.db PRIMARY_URL=http://127.0.0.1:8080 \
 *     php src/turso/verify-against-server.php
 *
 * It writes to the database it is pointed at, and cleans up after itself.
 */
/*
 * A standalone CLI probe, not WordPress code: it opens the snapshot with PDO
 * directly to show what the snapshot does and does not contain.
 *
 * phpcs:disable WordPress.DB.RestrictedClasses.mysql__PDO
 */
require_once __DIR__ . '/load.php';

$snapshot = getenv( 'SNAPSHOT' );
$url      = getenv( 'PRIMARY_URL' );

if ( ! $url ) {
	fwrite( STDERR, "Set PRIMARY_URL (and optionally SNAPSHOT).\n" );
	exit( 1 );
}

function section( string $title ): void {
	echo "\n=== $title ===\n";
}

$connection = wp_sqlite_turso_create_connection( $url, null, $snapshot );
printf( "connection: %s\n", get_class( $connection ) );
printf( "server version (from the snapshot): %s\n", $connection->get_server_version() );

section( 'capabilities (the primary governs)' );
foreach ( array( 'transactions', 'savepoints', 'temporary_tables', 'user_defined_functions' ) as $capability ) {
	printf( "  %-24s %s\n", $capability, $connection->has_capability( $capability ) ? 'yes' : 'no' );
}

section( 'reads before any write come from the snapshot' );
$statement = $connection->query( 'SELECT option_value FROM wp_options WHERE option_name = ?', array( 'blogname' ) );
printf( "  blogname = %s\n", var_export( $statement->fetchColumn(), true ) );
printf( "  latched  = %s\n", $connection->is_latched() ? 'yes' : 'no' );
print_r( $connection->get_counters() );

section( 'a write latches to the primary' );
$marker    = 'latch-test-' . getmypid();
$statement = $connection->query(
	'INSERT INTO wp_options (option_name, option_value, autoload) VALUES (?, ?, ?)',
	array( $marker, 'written', 'no' )
);
printf( "  rowCount      = %d\n", $statement->rowCount() );
printf( "  last insert id = %s\n", $connection->get_last_insert_id() );
printf( "  latched        = %s\n", $connection->is_latched() ? 'yes' : 'no' );

section( 'read-your-writes: the snapshot does NOT have this row, the primary does' );
$statement = $connection->query( 'SELECT option_value FROM wp_options WHERE option_name = ?', array( $marker ) );
printf( "  through the connection (latched) = %s\n", var_export( $statement->fetchColumn(), true ) );

$direct = new PDO( 'sqlite:' . $snapshot );
$direct->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
$raw = $direct->prepare( 'SELECT option_value FROM wp_options WHERE option_name = ?' );
$raw->execute( array( $marker ) );
printf( "  directly in the snapshot file    = %s  (expected false)\n", var_export( $raw->fetchColumn(), true ) );

print_r( $connection->get_counters() );

section( 'atomic batch: a failure must leave nothing behind' );
try {
	$connection->execute_batch(
		array(
			array( 'INSERT INTO wp_options (option_name, option_value, autoload) VALUES (?, ?, ?)', array( $marker . '-a', 'x', 'no' ) ),
			array( 'INSERT INTO nonexistent_table (x) VALUES (1)', array() ),
		)
	);
	echo "  batch unexpectedly succeeded\n";
} catch ( PDOException $e ) {
	printf( "  threw: %s\n", $e->getMessage() );
	printf( "  SQLSTATE: %s\n", $e->getCode() );
}
$statement = $connection->query( 'SELECT count(*) FROM wp_options WHERE option_name = ?', array( $marker . '-a' ) );
printf( "  rows left behind = %s  (expected 0)\n", $statement->fetchColumn() );

section( 'cleanup' );
$connection->query( 'DELETE FROM wp_options WHERE option_name LIKE ?', array( $marker . '%' ) );
printf( "  deleted, changes reported = %d\n", $connection->query( 'SELECT changes()' )->fetchColumn() );
