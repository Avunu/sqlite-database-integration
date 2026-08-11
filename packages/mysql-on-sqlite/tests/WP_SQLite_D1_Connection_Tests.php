<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/d1/load.php';
require_once __DIR__ . '/tools/class-wp-sqlite-d1-fake-transport.php';

/**
 * Unit tests for the D1 connection behaviors that are specific to the
 * remote, stateless nature of D1: statement interception, the schema
 * information cache, parameter inlining, and metadata bookkeeping.
 */
class WP_SQLite_D1_Connection_Tests extends TestCase {
	/**
	 * Create a D1 connection over a fake transport.
	 *
	 * @param  array $options Optional connection options.
	 * @return array{0: WP_SQLite_D1_Connection, 1: WP_SQLite_D1_Fake_Transport}
	 */
	private function create_connection( array $options = array() ): array {
		$transport  = new WP_SQLite_D1_Fake_Transport();
		$connection = new WP_SQLite_D1_Connection( $transport, $options );
		return array( $connection, $transport );
	}

	public function test_pragma_foreign_keys_is_emulated_locally(): void {
		list( $connection, $transport ) = $this->create_connection();

		// Reads and writes of "PRAGMA foreign_keys" don't hit the transport.
		$this->assertSame( '1', $connection->query( 'PRAGMA foreign_keys' )->fetchColumn() );
		$connection->query( 'PRAGMA foreign_keys = OFF' );
		$this->assertSame( '0', $connection->query( 'PRAGMA foreign_keys' )->fetchColumn() );
		$connection->query( 'PRAGMA foreign_keys = ON' );
		$this->assertSame( '1', $connection->query( 'PRAGMA foreign_keys' )->fetchColumn() );

		$this->assertSame( array(), $transport->get_log() );
	}

	public function test_batch_defers_foreign_keys_when_disabled(): void {
		list( $connection, $transport ) = $this->create_connection();

		$connection->query( 'PRAGMA foreign_keys = OFF' );
		$results = $connection->execute_batch(
			array(
				array( 'CREATE TABLE t ( id INTEGER PRIMARY KEY )' ),
				array( 'INSERT INTO t (id) VALUES (?)', array( 1 ) ),
			)
		);

		// The deferral statement is prefixed, and its result is dropped.
		$this->assertCount( 2, $results );
		$log = $transport->get_log();
		$this->assertSame( 'PRAGMA defer_foreign_keys = true', $log[0][0] );
		$this->assertSame( 'CREATE TABLE t ( id INTEGER PRIMARY KEY )', $log[1][0] );

		// With foreign keys enabled, no deferral statement is prefixed.
		$connection->query( 'PRAGMA foreign_keys = ON' );
		$connection->execute_batch( array( array( 'INSERT INTO t (id) VALUES (?)', array( 2 ) ) ) );
		$log = $transport->get_log();
		$this->assertSame( 'INSERT INTO t (id) VALUES (?)', $log[ count( $log ) - 1 ][0] );
	}

	public function test_unsupported_pragma_degrades_to_empty_result(): void {
		list( $connection ) = $this->create_connection();

		// The fake transport executes journal_mode locally; a real D1 may
		// reject it. Either way, the call must not throw.
		$stmt = $connection->query( 'PRAGMA wal_checkpoint(TRUNCATE)' );
		$this->assertInstanceOf( PDOStatement::class, $stmt );
	}

	public function test_sqlite_temp_master_reads_are_intercepted(): void {
		list( $connection, $transport ) = $this->create_connection();

		$stmt = $connection->query(
			"SELECT 1 FROM sqlite_temp_master WHERE type = 'table' AND name = ?",
			array( 'some_table' )
		);
		$this->assertFalse( $stmt->fetchColumn() );
		$this->assertSame( array(), $transport->get_log() );
	}

	public function test_last_insert_id_is_cached_across_statements(): void {
		list( $connection ) = $this->create_connection();

		$connection->query( 'CREATE TABLE t ( id INTEGER PRIMARY KEY, name TEXT )' );
		$this->assertSame( '0', $connection->get_last_insert_id() );

		$connection->query( 'INSERT INTO t (name) VALUES (?)', array( 'Alice' ) );
		$this->assertSame( '1', $connection->get_last_insert_id() );

		// Reads don't reset the last insert ID.
		$connection->query( 'SELECT * FROM t' );
		$this->assertSame( '1', $connection->get_last_insert_id() );

		$connection->query( 'INSERT INTO t (id, name) VALUES (?, ?)', array( 42, 'Bob' ) );
		$this->assertSame( '42', $connection->get_last_insert_id() );
	}

	public function test_schema_information_reads_are_cached(): void {
		list( $connection, $transport ) = $this->create_connection();

		$connection->query( 'CREATE TABLE _wp_sqlite_test_schema ( name TEXT )' );
		$connection->query( 'INSERT INTO _wp_sqlite_test_schema (name) VALUES (?)', array( 'a' ) );

		$read_sql = 'SELECT name FROM _wp_sqlite_test_schema';
		$connection->query( $read_sql );
		$count_after_first = count( $transport->get_log() );
		$connection->query( $read_sql );
		$connection->query( $read_sql );

		// The repeated reads are served from the cache.
		$this->assertCount( $count_after_first, $transport->get_log() );

		// A write to a driver-internal table invalidates the cache.
		$connection->query( 'INSERT INTO _wp_sqlite_test_schema (name) VALUES (?)', array( 'b' ) );
		$stmt = $connection->query( $read_sql );
		$this->assertCount( 2, $stmt->fetchAll( PDO::FETCH_COLUMN ) );
	}

	public function test_ddl_invalidates_schema_information_cache(): void {
		list( $connection, $transport ) = $this->create_connection();

		$connection->query( 'CREATE TABLE _wp_sqlite_test_schema ( name TEXT )' );

		$read_sql = 'SELECT COUNT(*) FROM _wp_sqlite_test_schema';
		$connection->query( $read_sql );
		$count_after_first = count( $transport->get_log() );
		$connection->query( $read_sql );
		$this->assertCount( $count_after_first, $transport->get_log() );

		// A DDL statement on a regular table invalidates the cache.
		$connection->query( 'CREATE TABLE regular_table ( id INTEGER )' );
		$connection->query( $read_sql );
		$this->assertGreaterThan( $count_after_first + 1, count( $transport->get_log() ) );
	}

	public function test_schema_cache_can_be_disabled(): void {
		list( $connection, $transport ) = $this->create_connection( array( 'schema_cache' => false ) );

		$connection->query( 'CREATE TABLE _wp_sqlite_test_schema ( name TEXT )' );
		$read_sql = 'SELECT COUNT(*) FROM _wp_sqlite_test_schema';
		$connection->query( $read_sql );
		$count_after_first = count( $transport->get_log() );
		$connection->query( $read_sql );
		$this->assertCount( $count_after_first + 1, $transport->get_log() );
	}

	public function test_cached_reads_return_fresh_statements(): void {
		list( $connection ) = $this->create_connection();

		$connection->query( 'CREATE TABLE _wp_sqlite_test_schema ( name TEXT )' );
		$connection->query( 'INSERT INTO _wp_sqlite_test_schema (name) VALUES (?)', array( 'a' ) );

		$read_sql = 'SELECT name FROM _wp_sqlite_test_schema';
		$first    = $connection->query( $read_sql );
		$this->assertSame( 'a', $first->fetchColumn() );

		// A cache hit returns a statement with a fresh cursor.
		$second = $connection->query( $read_sql );
		$this->assertSame( 'a', $second->fetchColumn() );
	}

	public function test_parameters_are_inlined_beyond_the_threshold(): void {
		list( $connection, $transport ) = $this->create_connection();
		$connection->query( 'CREATE TABLE t ( id INTEGER PRIMARY KEY, name TEXT )' );
		$connection->query( 'INSERT INTO t (id, name) VALUES (?, ?)', array( 5, "O'Hara" ) );

		$count        = WP_SQLite_D1_Connection::PARAMS_INLINE_THRESHOLD + 10;
		$placeholders = implode( ', ', array_fill( 0, $count, '?' ) );
		$params       = range( 1, $count );
		$params[4]    = "O'Hara"; // A string needing quoting.

		$stmt = $connection->query( "SELECT id, name FROM t WHERE name IN ($placeholders)", $params );
		$this->assertSame(
			array(
				array(
					'id'   => '5',
					'name' => "O'Hara",
				),
			),
			$stmt->fetchAll( PDO::FETCH_ASSOC )
		);

		// The transported statement has inlined literals and no parameters.
		$log  = $transport->get_log();
		$last = $log[ count( $log ) - 1 ];
		$this->assertSame( array(), $last[1] );
		$this->assertStringContainsString( "'O''Hara'", $last[0] );
		$this->assertStringNotContainsString( '?', $last[0] );
	}

	public function test_parameter_inlining_ignores_placeholders_in_literals(): void {
		list( $connection, $transport ) = $this->create_connection();
		$connection->query( 'CREATE TABLE t ( id INTEGER PRIMARY KEY, name TEXT )' );

		$count        = WP_SQLite_D1_Connection::PARAMS_INLINE_THRESHOLD + 1;
		$placeholders = implode( ', ', array_fill( 0, $count, '?' ) );
		$params       = range( 1, $count );

		$stmt = $connection->query(
			"SELECT 'a?b' AS q, `id` FROM t WHERE id IN ($placeholders)",
			$params
		);
		$this->assertSame( array(), $stmt->fetchAll( PDO::FETCH_ASSOC ) );

		$log  = $transport->get_log();
		$last = $log[ count( $log ) - 1 ];
		$this->assertStringContainsString( "'a?b'", $last[0] );
	}

	public function test_binary_values_round_trip(): void {
		list( $connection ) = $this->create_connection();

		$binary = "\x00\x01\xFF\xFE";
		$connection->query( 'CREATE TABLE t ( data BLOB )' );
		$connection->query( 'INSERT INTO t (data) VALUES (?)', array( $binary ) );

		$stmt = $connection->query( 'SELECT data FROM t' );
		$this->assertSame( $binary, $stmt->fetchColumn() );
	}

	public function test_errors_have_the_pdo_sqlite_shape(): void {
		list( $connection ) = $this->create_connection();
		$connection->query( 'CREATE TABLE t ( id INTEGER PRIMARY KEY, name TEXT UNIQUE )' );
		$connection->query( 'INSERT INTO t (name) VALUES (?)', array( 'Alice' ) );

		try {
			$connection->query( 'INSERT INTO t (name) VALUES (?)', array( 'Alice' ) );
			$this->fail( 'Expected a WP_SQLite_D1_Exception.' );
		} catch ( WP_SQLite_D1_Exception $e ) {
			$this->assertInstanceOf( PDOException::class, $e );
			$this->assertSame( '23000', $e->getCode() );
			$this->assertStringStartsWith( 'SQLSTATE[23000]', $e->getMessage() );
			$this->assertStringContainsString( 'UNIQUE constraint failed', $e->getMessage() );
		}

		try {
			$connection->query( 'SELECT * FROM no_such_table' );
			$this->fail( 'Expected a WP_SQLite_D1_Exception.' );
		} catch ( WP_SQLite_D1_Exception $e ) {
			$this->assertStringContainsString( 'no such table', $e->getMessage() );
		}
	}

	public function test_stringify_fetches_defaults_to_true(): void {
		list( $connection ) = $this->create_connection();
		$this->assertTrue( $connection->get_attribute( PDO::ATTR_STRINGIFY_FETCHES ) );
		$this->assertSame( '123', $connection->query( 'SELECT 123' )->fetchColumn() );

		$connection->set_attribute( PDO::ATTR_STRINGIFY_FETCHES, false );

		/*
		 * A real D1 database carries native JSON types, but the fake transport
		 * reads them from PDO SQLite, which always stringifies before PHP 8.1.
		 * Only the stringified half of this test is meaningful there.
		 */
		if ( PHP_VERSION_ID < 80100 ) {
			$this->assertSame( '123', $connection->query( 'SELECT 123' )->fetchColumn() );
			return;
		}
		$this->assertSame( 123, $connection->query( 'SELECT 123' )->fetchColumn() );
	}

	public function test_transaction_control_is_a_no_op(): void {
		list( $connection, $transport ) = $this->create_connection();

		$connection->begin_transaction( 'IMMEDIATE' );
		$this->assertFalse( $connection->in_transaction() );
		$connection->savepoint( 'sp' );
		$connection->release_savepoint( 'sp' );
		$connection->rollback_to_savepoint( 'sp' );
		$connection->commit();
		$connection->rollback();

		$this->assertSame( array(), $transport->get_log() );
	}
}
