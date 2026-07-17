<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/d1/load.php';
require_once __DIR__ . '/tools/class-wp-sqlite-d1-fake-transport.php';

/**
 * Tests for MySQL function translation on connections without user-defined
 * function support.
 *
 * Most tests are oracle tests: the same MySQL expression is executed through
 * the default SQLite backend (where MySQL functions are emulated with PHP
 * callbacks registered as SQL functions) and through the D1 backend (where
 * they are rewritten to plain SQLite expressions), and results are compared.
 */
class WP_MySQL_On_SQLite_No_UDF_Tests extends TestCase {
	/**
	 * A driver using the default SQLite connection (the oracle).
	 *
	 * @var WP_SQLite_Driver
	 */
	private $sqlite_driver;

	/**
	 * A driver using the D1 connection without UDF support.
	 *
	 * @var WP_SQLite_Driver
	 */
	private $d1_driver;

	public function setUp(): void {
		$this->sqlite_driver = new WP_SQLite_Driver(
			new WP_SQLite_Connection( array( 'path' => ':memory:' ) ),
			'wp'
		);
		$this->d1_driver     = new WP_SQLite_Driver(
			new WP_SQLite_D1_Connection( new WP_SQLite_D1_Fake_Transport() ),
			'wp'
		);
	}

	/**
	 * MySQL expressions that must produce identical results on both backends.
	 */
	public static function data_rewritten_expressions(): array {
		return array(
			array( "MONTH('2026-07-02 10:20:30')" ),
			array( "YEAR('2026-07-02 10:20:30')" ),
			array( "DAY('2026-07-02 10:20:30')" ),
			array( "DAYOFMONTH('2026-07-02 10:20:30')" ),
			array( "HOUR('2026-07-02 10:20:30')" ),
			array( "MINUTE('2026-07-02 10:20:30')" ),
			array( "SECOND('2026-07-02 10:20:30')" ),
			array( "DAYOFWEEK('2026-07-02')" ),
			array( "WEEKDAY('2026-07-02')" ),
			array( "UNIX_TIMESTAMP('2026-07-02 10:20:30')" ),
			array( 'FROM_UNIXTIME(1783074030)' ),
			array( "FIELD('b', 'a', 'b', 'c')" ),
			array( "FIELD('z', 'a', 'b', 'c')" ),
			array( 'FIELD(NULL, 1, 2)' ),
			array( 'LEAST(3, 1, 2)' ),
			array( 'LEAST(3, NULL, 2)' ),
			array( 'GREATEST(3, 1, 2)' ),
			array( "LOCATE('bar', 'foobarbar')" ),
			array( "LOCATE('bar', 'foobarbar', 5)" ),
			array( "LOCATE('xyz', 'foobarbar')" ),
			array( 'INET_NTOA(3232235777)' ),
			array( "MD5('hello')" ),
			array( "REVERSE('hello')" ),
			array( "TO_BASE64('hello')" ),
			array( "FROM_BASE64('aGVsbG8=')" ),
		);
	}

	/**
	 * @dataProvider data_rewritten_expressions
	 */
	public function test_rewritten_expression_matches_sqlite_backend( string $expression ): void {
		$expected = $this->sqlite_driver->query( "SELECT $expression AS value" );
		$actual   = $this->d1_driver->query( "SELECT $expression AS value" );
		$this->assertEquals( $expected[0]->value, $actual[0]->value, $expression );
	}

	/**
	 * MySQL expressions and their expected values, as produced by MySQL.
	 *
	 * These functions have legacy PHP emulations with behaviors diverging
	 * from MySQL (e.g., UCASE() emits an SQL fragment, GET_LOCK() returns
	 * "1=1", WEEK() requires two arguments, MONTH(NULL) raises an error).
	 * On connections without UDF support, the plain-SQL rewrites follow
	 * the actual MySQL behavior, so they are asserted against it directly.
	 */
	public static function data_expression_values(): array {
		return array(
			array( 'MONTH(NULL)', null ),
			array( "WEEK('2026-07-02')", '26' ),
			array( "WEEK('2026-07-02', 1)", '27' ),
			array( "FROM_UNIXTIME(1783074030, '%Y-%m-%d %H:%i:%s')", gmdate( 'Y-m-d H:i:s', 1783074030 ) ),
			array( "ISNULL('a')", '0' ),
			array( 'ISNULL(NULL)', '1' ),
			array( "IF(1 < 2, 'yes', 'no')", 'yes' ),
			array( "IF(NULL, 'yes', 'no')", 'no' ),
			array( 'LOG(2, 65536)', '16' ),
			array( "DATEDIFF('2026-07-10', '2026-07-02 23:59:59')", '8' ),
			array( "UCASE('Hello')", 'HELLO' ),
			array( "LCASE('Hello')", 'hello' ),
			array( 'GET_LOCK(1, 2)', '1' ),
			array( "INET_ATON('192.168.1.1')", '3232235777' ),
		);
	}

	/**
	 * @dataProvider data_expression_values
	 */
	public function test_rewritten_expression_value( string $expression, ?string $expected ): void {
		$actual = $this->d1_driver->query( "SELECT $expression AS value" );
		if ( null === $expected ) {
			$this->assertNull( $actual[0]->value, $expression );
		} else {
			$this->assertEquals( $expected, $actual[0]->value, $expression );
		}
	}

	public function test_like_binary_with_constant_pattern(): void {
		$this->d1_driver->query( 'CREATE TABLE t ( name TEXT )' );
		$this->d1_driver->query( "INSERT INTO t (name) VALUES ('Case'), ('case')" );

		$rows = $this->d1_driver->query( "SELECT name FROM t WHERE name LIKE BINARY 'Ca%'" );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'Case', $rows[0]->name );
	}

	public function test_strict_mode_validation_rejects_invalid_values(): void {
		$this->d1_driver->query( 'CREATE TABLE t ( d DATETIME )' );
		$this->d1_driver->query( "INSERT INTO t (d) VALUES ('2026-07-02 10:00:00')" );

		$this->expectException( WP_SQLite_Driver_Exception::class );
		$this->d1_driver->query( "INSERT INTO t (d) VALUES ('not-a-date')" );
	}

	public function test_regexp_is_reported_as_not_supported(): void {
		$this->d1_driver->query( 'CREATE TABLE t ( name TEXT )' );

		$this->expectException( WP_SQLite_Driver_Exception::class );
		$this->expectExceptionMessage( 'REGEXP' );
		$this->d1_driver->query( "SELECT * FROM t WHERE name REGEXP '^a'" );
	}

	public function test_seeded_rand_is_reported_as_not_supported(): void {
		$this->expectException( WP_SQLite_Driver_Exception::class );
		$this->expectExceptionMessage( 'RAND(N)' );
		$this->d1_driver->query( 'SELECT RAND(42)' );
	}

	public function test_md5_with_non_constant_argument_is_reported_as_not_supported(): void {
		$this->d1_driver->query( 'CREATE TABLE t ( name TEXT )' );

		$this->expectException( WP_SQLite_Driver_Exception::class );
		$this->expectExceptionMessage( 'MD5()' );
		$this->d1_driver->query( 'SELECT MD5(name) FROM t' );
	}

	public function test_unseeded_rand_returns_a_float_in_range(): void {
		$rows  = $this->d1_driver->query( 'SELECT RAND() AS value' );
		$value = (float) $rows[0]->value;
		$this->assertGreaterThanOrEqual( 0.0, $value );
		$this->assertLessThan( 1.0, $value );
	}
}
