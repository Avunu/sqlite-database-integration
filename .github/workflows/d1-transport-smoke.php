<?php

/**
 * A driver-level smoke test against a live D1 proxy.
 *
 * Runs a representative set of MySQL statements through the full driver
 * stack (parser, translator, D1 connection, transport, proxy, D1) and
 * verifies the results.
 *
 * Usage:
 *   php d1-transport-smoke.php <proxy-url> <expected-transport: curl|native>
 */

require_once __DIR__ . '/../../packages/mysql-on-sqlite/src/d1/load.php';

if ( ! defined( 'SQLITE_DRIVER_VERSION' ) ) {
	define( 'SQLITE_DRIVER_VERSION', '999.0.0' );
}

function check( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: $message\n" );
		exit( 1 );
	}
	echo "OK: $message\n";
}

$url      = $argv[1] ?? 'http://127.0.0.1:8787';
$expected = $argv[2] ?? 'curl';

$transport = wp_sqlite_d1_create_transport( $url );
check(
	( 'native' === $expected ) === ( $transport instanceof WP_SQLite_D1_Native_Transport ),
	"the $expected transport is selected (" . get_class( $transport ) . ')'
);

$driver = new WP_SQLite_Driver( new WP_SQLite_D1_Connection( $transport ), "smoke_$expected" );
$table  = "smoke_$expected";

$driver->query( "DROP TABLE IF EXISTS $table" );
$driver->query(
	"CREATE TABLE $table (
		ID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		title TEXT,
		status VARCHAR(20) NOT NULL DEFAULT 'publish',
		created DATETIME,
		PRIMARY KEY (ID),
		KEY status_key (status)
	)"
);

$affected = $driver->query(
	"INSERT INTO $table (title, status, created)
	 VALUES ('Hello', 'publish', '2026-07-02 10:00:00'), ('Draft', 'draft', '2026-07-02 11:00:00')"
);
check( 2 === $affected, 'INSERT reports 2 affected rows' );
check( 2 === $driver->get_insert_id(), 'insert ID is 2' );

$rows = $driver->query( "SELECT ID, title, MONTH(created) AS m FROM $table WHERE status = 'publish'" );
check( 1 === count( $rows ), 'SELECT returns one published row' );
check( '1' === $rows[0]->ID && 'Hello' === $rows[0]->title && '7' === $rows[0]->m, 'SELECT values are correct' );

check( 1 === $driver->query( "UPDATE $table SET title = 'Hello World' WHERE ID = 1" ), 'UPDATE affects 1 row' );

$driver->query( "ALTER TABLE $table ADD COLUMN slug VARCHAR(200) NOT NULL DEFAULT ''" );
$columns = $driver->query( "SHOW COLUMNS FROM $table" );
check( 5 === count( $columns ), 'ALTER TABLE adds a column (SHOW COLUMNS reports 5)' );

// Strict mode data validation rejects invalid values.
$rejected = false;
try {
	$driver->query( "INSERT INTO $table (created) VALUES ('not-a-date')" );
} catch ( Throwable $e ) {
	$rejected = true;
}
check( $rejected, 'strict mode rejects an invalid datetime value' );

check( 1 === $driver->query( "DELETE FROM $table WHERE status = 'draft'" ), 'DELETE affects 1 row' );
$count = $driver->query( "SELECT COUNT(*) AS c FROM $table" );
check( '1' === $count[0]->c, 'one row remains' );

$driver->query( "DROP TABLE $table" );
echo "All smoke tests passed over the $expected transport.\n";
