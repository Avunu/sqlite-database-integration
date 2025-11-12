<?php
/**
 * Tests for Cloudflare D1 PDO implementation.
 *
 * @package wp-sqlite-integration
 */

use PHPUnit\Framework\TestCase;

/**
 * Tests for WP_SQLite_D1_PDO and WP_SQLite_D1_PDO_Statement classes.
 */
class WP_SQLite_D1_PDO_Tests extends TestCase {

	/**
	 * Test that WP_SQLite_D1_PDO class exists.
	 */
	public function test_d1_pdo_class_exists() {
		$this->assertTrue( class_exists( 'WP_SQLite_D1_PDO' ) );
	}

	/**
	 * Test that WP_SQLite_D1_PDO_Statement class exists.
	 */
	public function test_d1_pdo_statement_class_exists() {
		$this->assertTrue( class_exists( 'WP_SQLite_D1_PDO_Statement' ) );
	}

	/**
	 * Test that D1 PDO can be instantiated with required parameters.
	 */
	public function test_d1_pdo_instantiation() {
		$pdo = new WP_SQLite_D1_PDO(
			'sqlite::memory:',
			'test-account-id',
			'test-database-id',
			'test-api-token'
		);
		$this->assertInstanceOf( 'WP_SQLite_D1_PDO', $pdo );
		$this->assertInstanceOf( 'PDO', $pdo );
	}

	/**
	 * Test that D1 PDO can prepare statements.
	 */
	public function test_d1_pdo_prepare() {
		$pdo  = new WP_SQLite_D1_PDO(
			'sqlite::memory:',
			'test-account-id',
			'test-database-id',
			'test-api-token'
		);
		$stmt = $pdo->prepare( 'SELECT * FROM test' );
		$this->assertInstanceOf( 'WP_SQLite_D1_PDO_Statement', $stmt );
		$this->assertInstanceOf( 'PDOStatement', $stmt );
	}

	/**
	 * Test that D1 PDO handles last insert ID.
	 */
	public function test_d1_pdo_last_insert_id() {
		$pdo = new WP_SQLite_D1_PDO(
			'sqlite::memory:',
			'test-account-id',
			'test-database-id',
			'test-api-token'
		);

		// Initially should be false.
		$this->assertFalse( $pdo->lastInsertId() );

		// Set and retrieve last insert ID.
		$pdo->set_last_insert_id( null, 123 );
		$this->assertEquals( '123', $pdo->lastInsertId() );

		// Test named sequence.
		$pdo->set_last_insert_id( 'users', 456 );
		$this->assertEquals( '456', $pdo->lastInsertId( 'users' ) );
	}

	/**
	 * Test that D1 PDO handles transactions.
	 */
	public function test_d1_pdo_transactions() {
		$pdo = new WP_SQLite_D1_PDO(
			'sqlite::memory:',
			'test-account-id',
			'test-database-id',
			'test-api-token'
		);

		// Initially not in transaction.
		$this->assertFalse( $pdo->inTransaction() );

		// Begin transaction.
		$this->assertTrue( $pdo->beginTransaction() );
		$this->assertTrue( $pdo->inTransaction() );

		// Commit transaction.
		$this->assertTrue( $pdo->commit() );
		$this->assertFalse( $pdo->inTransaction() );
	}

	/**
	 * Test that PDO statement can bind values.
	 */
	public function test_d1_pdo_statement_bind_value() {
		$pdo  = new WP_SQLite_D1_PDO(
			'sqlite::memory:',
			'test-account-id',
			'test-database-id',
			'test-api-token'
		);
		$stmt = $pdo->prepare( 'SELECT * FROM test WHERE id = ?' );

		$this->assertTrue( $stmt->bindValue( 1, 'test', PDO::PARAM_STR ) );
		$this->assertTrue( $stmt->bindValue( 2, 123, PDO::PARAM_INT ) );
	}

	/**
	 * Test that PDO statement can set fetch mode.
	 */
	public function test_d1_pdo_statement_fetch_mode() {
		$pdo  = new WP_SQLite_D1_PDO(
			'sqlite::memory:',
			'test-account-id',
			'test-database-id',
			'test-api-token'
		);
		$stmt = $pdo->prepare( 'SELECT * FROM test' );

		$this->assertTrue( $stmt->setFetchMode( PDO::FETCH_ASSOC ) );
		$this->assertTrue( $stmt->setFetchMode( PDO::FETCH_OBJ ) );
	}
}
