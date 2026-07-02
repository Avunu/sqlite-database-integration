<?php

use PHPUnit\Framework\TestCase;

/**
 * A conformance test case for WP_SQLite_Connection_Interface implementations.
 *
 * Each connection implementation should extend this test case to verify that
 * it conforms to the behaviors expected by the MySQL-on-SQLite driver. Tests
 * adapt to the capabilities that the connection is expected to support.
 */
abstract class WP_SQLite_Connection_Conformance_Test_Case extends TestCase {
	/**
	 * Create a connection to a new, empty SQLite database.
	 */
	abstract protected function create_connection(): WP_SQLite_Connection_Interface;

	/**
	 * The capabilities the connection is expected to support.
	 *
	 * @return string[] A list of CAPABILITY_* constant values.
	 */
	abstract protected function expected_capabilities(): array;

	/**
	 * Check whether a capability is expected to be supported.
	 */
	protected function expects_capability( string $capability ): bool {
		return in_array( $capability, $this->expected_capabilities(), true );
	}

	public function test_capabilities(): void {
		$connection = $this->create_connection();
		foreach (
			array(
				WP_SQLite_Connection_Interface::CAPABILITY_TRANSACTIONS,
				WP_SQLite_Connection_Interface::CAPABILITY_SAVEPOINTS,
				WP_SQLite_Connection_Interface::CAPABILITY_TEMPORARY_TABLES,
				WP_SQLite_Connection_Interface::CAPABILITY_USER_DEFINED_FUNCTIONS,
			) as $capability
		) {
			$this->assertSame(
				$this->expects_capability( $capability ),
				$connection->has_capability( $capability ),
				"Capability '$capability'"
			);
		}
		$this->assertFalse( $connection->has_capability( 'an-unknown-capability' ) );
	}

	public function test_query_with_parameters(): void {
		$connection = $this->create_connection();
		$connection->query( 'CREATE TABLE t ( id INTEGER PRIMARY KEY, name TEXT )' );
		$connection->query( 'INSERT INTO t (name) VALUES (?)', array( 'Alice' ) );
		$connection->query( 'INSERT INTO t (name) VALUES (?)', array( 'Bob' ) );

		$stmt = $connection->query( 'SELECT id, name FROM t ORDER BY id' );
		$this->assertSame(
			array(
				array(
					'id'   => '1',
					'name' => 'Alice',
				),
				array(
					'id'   => '2',
					'name' => 'Bob',
				),
			),
			$this->fetch_all_assoc_stringified( $stmt )
		);
	}

	public function test_execute_batch(): void {
		$connection = $this->create_connection();
		$connection->query( 'CREATE TABLE t ( id INTEGER PRIMARY KEY, name TEXT )' );

		$results = $connection->execute_batch(
			array(
				array( 'INSERT INTO t (name) VALUES (?)', array( 'Alice' ) ),
				array( 'INSERT INTO t (name) VALUES (?), (?)', array( 'Bob', 'Carol' ) ),
				array( 'SELECT COUNT(*) FROM t' ),
			)
		);

		$this->assertCount( 3, $results );
		$this->assertSame( 1, $results[0]->rowCount() );
		$this->assertSame( 2, $results[1]->rowCount() );
		$this->assertSame( '3', (string) $results[2]->fetchColumn() );
	}

	public function test_last_insert_id(): void {
		$connection = $this->create_connection();
		$connection->query( 'CREATE TABLE t ( id INTEGER PRIMARY KEY, name TEXT )' );
		$connection->query( 'INSERT INTO t (name) VALUES (?)', array( 'Alice' ) );
		$this->assertSame( '1', $connection->get_last_insert_id() );

		$connection->query( 'INSERT INTO t (id, name) VALUES (?, ?)', array( 123, 'Bob' ) );
		$this->assertSame( '123', $connection->get_last_insert_id() );
	}

	public function test_server_version(): void {
		$connection = $this->create_connection();
		$this->assertRegExp(
			'/^\d+\.\d+(\.\d+)?/',
			$connection->get_server_version()
		);
	}

	public function test_quote(): void {
		$connection = $this->create_connection();
		$this->assertSame( "'abc'", $connection->quote( 'abc' ) );
		$this->assertSame( "'a''bc'", $connection->quote( "a'bc" ) );
	}

	public function test_quote_identifier(): void {
		$connection = $this->create_connection();
		$this->assertSame( '`abc`', $connection->quote_identifier( 'abc' ) );
		$this->assertSame( '`a``bc`', $connection->quote_identifier( 'a`bc' ) );
	}

	public function test_stringify_fetches_attribute(): void {
		$connection = $this->create_connection();

		// The attribute value is tracked by the connection.
		$connection->set_attribute( PDO::ATTR_STRINGIFY_FETCHES, true );
		$this->assertTrue( $connection->get_attribute( PDO::ATTR_STRINGIFY_FETCHES ) );

		$value = $connection->query( 'SELECT 123' )->fetchColumn();
		$this->assertSame( '123', $value );

		$connection->set_attribute( PDO::ATTR_STRINGIFY_FETCHES, false );
		$this->assertFalse( $connection->get_attribute( PDO::ATTR_STRINGIFY_FETCHES ) );
	}

	public function test_transactions(): void {
		$connection = $this->create_connection();
		$connection->query( 'CREATE TABLE t ( id INTEGER PRIMARY KEY, name TEXT )' );
		$this->assertFalse( $connection->in_transaction() );

		if ( ! $this->expects_capability( WP_SQLite_Connection_Interface::CAPABILITY_TRANSACTIONS ) ) {
			$this->markTestSkipped( 'The connection does not support transactions.' );
		}

		// Roll back a transaction.
		$connection->begin_transaction( 'IMMEDIATE' );
		$this->assertTrue( $connection->in_transaction() );
		$connection->query( 'INSERT INTO t (name) VALUES (?)', array( 'Alice' ) );
		$connection->rollback();
		$this->assertFalse( $connection->in_transaction() );
		$this->assertSame( 0, (int) $connection->query( 'SELECT COUNT(*) FROM t' )->fetchColumn() );

		// Commit a transaction.
		$connection->begin_transaction();
		$connection->query( 'INSERT INTO t (name) VALUES (?)', array( 'Bob' ) );
		$connection->commit();
		$this->assertFalse( $connection->in_transaction() );
		$this->assertSame( 1, (int) $connection->query( 'SELECT COUNT(*) FROM t' )->fetchColumn() );
	}

	public function test_savepoints(): void {
		if ( ! $this->expects_capability( WP_SQLite_Connection_Interface::CAPABILITY_SAVEPOINTS ) ) {
			$this->markTestSkipped( 'The connection does not support savepoints.' );
		}

		$connection = $this->create_connection();
		$connection->query( 'CREATE TABLE t ( id INTEGER PRIMARY KEY, name TEXT )' );

		$connection->begin_transaction( 'IMMEDIATE' );
		$connection->query( 'INSERT INTO t (name) VALUES (?)', array( 'Alice' ) );
		$connection->savepoint( 'sp-1' );
		$connection->query( 'INSERT INTO t (name) VALUES (?)', array( 'Bob' ) );
		$connection->rollback_to_savepoint( 'sp-1' );
		$connection->release_savepoint( 'sp-1' );
		$connection->commit();

		$stmt = $connection->query( 'SELECT name FROM t' );
		$this->assertSame( array( 'Alice' ), $stmt->fetchAll( PDO::FETCH_COLUMN ) );
	}

	public function test_create_function(): void {
		$connection = $this->create_connection();
		$result     = $connection->create_function(
			'wp_test_conformance_double',
			function ( $value ) {
				return 2 * $value;
			}
		);

		if ( ! $this->expects_capability( WP_SQLite_Connection_Interface::CAPABILITY_USER_DEFINED_FUNCTIONS ) ) {
			$this->assertFalse( $result );
			return;
		}

		$this->assertTrue( $result );
		$this->assertSame(
			'42',
			(string) $connection->query( 'SELECT wp_test_conformance_double(21)' )->fetchColumn()
		);
	}

	public function test_query_logger(): void {
		$connection = $this->create_connection();
		$logged     = array();
		$connection->set_query_logger(
			function ( string $sql, array $params ) use ( &$logged ) {
				$logged[] = array( $sql, $params );
			}
		);

		$connection->query( 'SELECT ?', array( 1 ) );
		$this->assertContains( array( 'SELECT ?', array( 1 ) ), $logged );
	}

	/**
	 * Fetch all rows as associative arrays with stringified values.
	 *
	 * This normalizes value types across connection implementations, so that
	 * conformance tests can assert on data without depending on the value of
	 * the PDO::ATTR_STRINGIFY_FETCHES attribute.
	 *
	 * @param  PDOStatement $stmt The statement to fetch from.
	 * @return array              The rows with stringified values.
	 */
	protected function fetch_all_assoc_stringified( PDOStatement $stmt ): array {
		$rows = array();
		foreach ( $stmt->fetchAll( PDO::FETCH_ASSOC ) as $row ) {
			$stringified = array();
			foreach ( $row as $key => $value ) {
				$stringified[ $key ] = null === $value ? null : (string) $value;
			}
			$rows[] = $stringified;
		}
		return $rows;
	}
}
