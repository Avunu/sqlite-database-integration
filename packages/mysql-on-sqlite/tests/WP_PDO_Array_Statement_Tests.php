<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for the WP_PDO_Array_Statement class.
 *
 * Most tests are "oracle" tests: the same result set is fetched both through
 * a real PDO SQLite statement and through the in-memory array statement, and
 * the outputs are compared across fetch modes and stringification settings.
 */
class WP_PDO_Array_Statement_Tests extends TestCase {
	/**
	 * Columns of the reference result set.
	 *
	 * @var string[]
	 */
	const COLUMNS = array( 'id', 'name', 'ratio', 'nothing' );

	/**
	 * Rows of the reference result set.
	 *
	 * @var array[]
	 */
	const ROWS = array(
		array( 1, 'Alice', 1.5, null ),
		array( 2, 'Bob', -0.25, null ),
		array( 3, "Ann\0O'Hara", 1230.0, null ),
	);

	/**
	 * Create a real PDO SQLite statement returning the reference result set.
	 *
	 * The query is composed in the same way that the SQLite driver used to
	 * compose synthetic result sets (a UNION of a header row and a VALUES
	 * list), making it a faithful oracle for the array statement.
	 *
	 * @param  bool  $stringify Whether to enable PDO::ATTR_STRINGIFY_FETCHES.
	 * @param  array $columns   The result set columns.
	 * @param  array $rows      The result set rows.
	 * @return PDOStatement     The executed PDO statement.
	 */
	private function create_pdo_statement(
		bool $stringify = false,
		array $columns = self::COLUMNS,
		array $rows = self::ROWS
	): PDOStatement {
		/*
		 * PDO SQLite gained native value types in PHP 8.1. Before that it
		 * always stringifies, and it does so from SQLite's own text rendering
		 * rather than from a PHP value, so a float reads as "1230.0" instead
		 * of "1230" and every column reports PDO::PARAM_STR. The array
		 * statement implements the current semantics, which the older
		 * extension cannot express, so it has no oracle to compare against.
		 */
		if ( PHP_VERSION_ID < 80100 ) {
			$this->markTestSkipped(
				'PDO SQLite cannot report native value types before PHP 8.1, so it cannot serve as an oracle.'
			);
		}

		$pdo = new PDO( 'sqlite::memory:' );
		$pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
		$pdo->setAttribute( PDO::ATTR_STRINGIFY_FETCHES, $stringify );

		$query = 'SELECT ';
		foreach ( $columns as $i => $column ) {
			$query .= $i > 0 ? ', ' : '';
			$query .= 'NULL AS "' . str_replace( '"', '""', $column ) . '"';
		}
		$query .= ' WHERE FALSE';

		if ( count( $rows ) > 0 ) {
			$query .= ' UNION ALL VALUES ';
		}

		foreach ( $rows as $i => $row ) {
			$query .= $i > 0 ? ', ' : '';
			$query .= '(';
			foreach ( array_values( $row ) as $j => $value ) {
				$query .= $j > 0 ? ', ' : '';
				if ( null === $value ) {
					$query .= 'NULL';
				} elseif ( is_string( $value ) && strpos( $value, "\0" ) !== false ) {
					$query .= sprintf( "CAST(x'%s' AS TEXT)", bin2hex( $value ) );
				} elseif ( is_string( $value ) ) {
					$query .= $pdo->quote( $value );
				} elseif ( is_float( $value ) ) {
					// Preserve the REAL type also for integral values ("1230.0").
					$query .= var_export( $value, true );
				} else {
					$query .= $value;
				}
			}
			$query .= ')';
		}
		return $pdo->query( $query );
	}

	/**
	 * Create an array statement returning the reference result set.
	 *
	 * @param  bool  $stringify Whether to stringify fetched values.
	 * @param  array $columns   The result set columns.
	 * @param  array $rows      The result set rows.
	 * @return WP_PDO_Array_Statement The array statement.
	 */
	private function create_array_statement(
		bool $stringify = false,
		array $columns = self::COLUMNS,
		array $rows = self::ROWS
	): WP_PDO_Array_Statement {
		return new WP_PDO_Array_Statement( $columns, $rows, 0, $stringify );
	}

	/**
	 * Data provider with all combinations of simple fetch modes and
	 * stringification settings.
	 */
	public static function data_fetch_modes(): array {
		$modes = array(
			'ASSOC' => PDO::FETCH_ASSOC,
			'NUM'   => PDO::FETCH_NUM,
			'BOTH'  => PDO::FETCH_BOTH,
			'NAMED' => PDO::FETCH_NAMED,
			'OBJ'   => PDO::FETCH_OBJ,
		);

		$data = array();
		foreach ( $modes as $name => $mode ) {
			$data[ "$name, raw" ]         = array( $mode, false );
			$data[ "$name, stringified" ] = array( $mode, true );
		}
		return $data;
	}

	/**
	 * @dataProvider data_fetch_modes
	 */
	public function test_fetch_matches_pdo( int $mode, bool $stringify ): void {
		$pdo_stmt   = $this->create_pdo_statement( $stringify );
		$array_stmt = $this->create_array_statement( $stringify );

		for ( $i = 0; $i <= count( self::ROWS ); $i++ ) {
			$expected = $pdo_stmt->fetch( $mode );
			$actual   = $array_stmt->fetch( $mode );
			if ( is_object( $expected ) ) {
				$this->assertEquals( $expected, $actual, "Row $i" );
			} else {
				$this->assertSame( $expected, $actual, "Row $i" );
			}
		}
	}

	/**
	 * @dataProvider data_fetch_modes
	 */
	public function test_fetch_all_matches_pdo( int $mode, bool $stringify ): void {
		$pdo_stmt   = $this->create_pdo_statement( $stringify );
		$array_stmt = $this->create_array_statement( $stringify );

		$expected = $pdo_stmt->fetchAll( $mode );
		$actual   = $array_stmt->fetchAll( $mode );
		if ( PDO::FETCH_OBJ === $mode ) {
			$this->assertEquals( $expected, $actual );
		} else {
			$this->assertSame( $expected, $actual );
		}

		// A subsequent fetchAll() returns no more rows.
		$this->assertSame( array(), $array_stmt->fetchAll( $mode ) );
	}

	public function test_fetch_column_matches_pdo(): void {
		$pdo_stmt   = $this->create_pdo_statement( true );
		$array_stmt = $this->create_array_statement( true );

		// fetchColumn() advances the cursor, alternating column indexes.
		$this->assertSame( $pdo_stmt->fetchColumn(), $array_stmt->fetchColumn() );
		$this->assertSame( $pdo_stmt->fetchColumn( 1 ), $array_stmt->fetchColumn( 1 ) );
		$this->assertSame( $pdo_stmt->fetchColumn( 2 ), $array_stmt->fetchColumn( 2 ) );
		$this->assertSame( $pdo_stmt->fetchColumn(), $array_stmt->fetchColumn() );
		$this->assertFalse( $array_stmt->fetchColumn() );
	}

	public function test_fetch_all_column_matches_pdo(): void {
		$pdo_stmt   = $this->create_pdo_statement( true );
		$array_stmt = $this->create_array_statement( true );

		$this->assertSame(
			$pdo_stmt->fetchAll( PDO::FETCH_COLUMN, 1 ),
			$array_stmt->fetchAll( PDO::FETCH_COLUMN, 1 )
		);
	}

	public function test_fetch_key_pair_matches_pdo(): void {
		$columns = array( 'key', 'value' );
		$rows    = array(
			array( 'a', 1 ),
			array( 'b', 2 ),
			array( 'a', 3 ), // Duplicate key. The last pair wins.
		);

		$pdo_stmt   = $this->create_pdo_statement( false, $columns, $rows );
		$array_stmt = $this->create_array_statement( false, $columns, $rows );

		$this->assertSame(
			$pdo_stmt->fetchAll( PDO::FETCH_KEY_PAIR ),
			$array_stmt->fetchAll( PDO::FETCH_KEY_PAIR )
		);
	}

	public function test_fetch_class_matches_pdo(): void {
		$pdo_stmt   = $this->create_pdo_statement( false );
		$array_stmt = $this->create_array_statement( false );

		$expected = $pdo_stmt->fetchAll( PDO::FETCH_CLASS, WP_PDO_Array_Statement_Test_Row::class, array( 'ctor' ) );
		$actual   = $array_stmt->fetchAll( PDO::FETCH_CLASS, WP_PDO_Array_Statement_Test_Row::class, array( 'ctor' ) );
		$this->assertEquals( $expected, $actual );

		// As per PDO, properties are assigned before the constructor runs.
		$this->assertSame( 'Alice', $actual[0]->name_at_construction );
		$this->assertSame( 'ctor', $actual[0]->constructor_arg );
	}

	public function test_fetch_object_matches_pdo(): void {
		$pdo_stmt   = $this->create_pdo_statement( false );
		$array_stmt = $this->create_array_statement( false );

		$this->assertEquals( $pdo_stmt->fetchObject(), $array_stmt->fetchObject() );
		$this->assertEquals(
			$pdo_stmt->fetchObject( WP_PDO_Array_Statement_Test_Row::class, array( 'ctor' ) ),
			$array_stmt->fetchObject( WP_PDO_Array_Statement_Test_Row::class, array( 'ctor' ) )
		);

		$array_stmt->fetchObject();
		$this->assertFalse( $array_stmt->fetchObject() );
	}

	public function test_duplicate_column_names_match_pdo(): void {
		$columns = array( 'id', 'id', 'name' );
		$rows    = array(
			array( 1, 2, 'Alice' ),
			array( 3, 4, 'Bob' ),
		);

		foreach ( array( PDO::FETCH_ASSOC, PDO::FETCH_NUM, PDO::FETCH_BOTH, PDO::FETCH_NAMED ) as $mode ) {
			$pdo_stmt   = $this->create_pdo_statement( false, $columns, $rows );
			$array_stmt = $this->create_array_statement( false, $columns, $rows );
			$this->assertSame(
				$pdo_stmt->fetchAll( $mode ),
				$array_stmt->fetchAll( $mode ),
				"Fetch mode $mode"
			);
		}
	}

	public function test_default_fetch_mode(): void {
		$stmt = $this->create_array_statement();
		$stmt->setFetchMode( PDO::FETCH_ASSOC );
		$this->assertSame(
			array(
				'id'      => 1,
				'name'    => 'Alice',
				'ratio'   => 1.5,
				'nothing' => null,
			),
			$stmt->fetch()
		);

		$stmt->setFetchMode( PDO::FETCH_COLUMN, 1 );
		$this->assertSame( 'Bob', $stmt->fetch() );
		$this->assertSame( array( "Ann\0O'Hara" ), $stmt->fetchAll() );
	}

	public function test_fetch_into(): void {
		$stmt   = $this->create_array_statement( false, array( 'name' ), array( array( 'Alice' ) ) );
		$object = new stdClass();
		$stmt->setFetchMode( PDO::FETCH_INTO, $object );
		$this->assertSame( $object, $stmt->fetch() );
		$this->assertSame( 'Alice', $object->name );
		$this->assertFalse( $stmt->fetch() );
	}

	public function test_empty_result_set(): void {
		$stmt = $this->create_array_statement( true, array(), array() );
		$this->assertSame( 0, $stmt->columnCount() );
		$this->assertSame( 0, $stmt->rowCount() );
		$this->assertFalse( $stmt->fetch( PDO::FETCH_ASSOC ) );
		$this->assertFalse( $stmt->fetchColumn() );
		$this->assertSame( array(), $stmt->fetchAll( PDO::FETCH_ASSOC ) );
		$this->assertFalse( $stmt->getColumnMeta( 0 ) );
	}

	public function test_column_count(): void {
		$stmt = $this->create_array_statement();
		$this->assertSame( count( self::COLUMNS ), $stmt->columnCount() );
	}

	public function test_row_count_reports_affected_rows(): void {
		$stmt = new WP_PDO_Array_Statement( array(), array(), 123 );
		$this->assertSame( 123, $stmt->rowCount() );
	}

	public function test_get_column_meta_matches_pdo(): void {
		foreach ( array( true, false ) as $stringify ) {
			$pdo_stmt   = $this->create_pdo_statement( $stringify );
			$array_stmt = $this->create_array_statement( $stringify );

			// Step to the first row, so that PDO SQLite can report value types.
			$pdo_stmt->fetch();
			$array_stmt->fetch();

			for ( $i = 0; $i < count( self::COLUMNS ); $i++ ) {
				$expected = $pdo_stmt->getColumnMeta( $i );
				$actual   = $array_stmt->getColumnMeta( $i );
				foreach ( array( 'name', 'native_type', 'pdo_type' ) as $key ) {
					$this->assertSame(
						$expected[ $key ],
						$actual[ $key ],
						"Column $i, key '$key', stringify: " . var_export( $stringify, true )
					);
				}

				// PDO SQLite reports no "table" and "sqlite:decl_type" keys
				// for columns that are not part of a database table.
				foreach ( array( 'table', 'sqlite:decl_type' ) as $key ) {
					$this->assertSame(
						array_key_exists( $key, $expected ),
						array_key_exists( $key, $actual ),
						"Column $i, key '$key' existence"
					);
				}
			}
		}
	}

	public function test_get_column_meta_overrides(): void {
		$stmt = new WP_PDO_Array_Statement(
			array( 'id' ),
			array( array( 1 ) ),
			0,
			false,
			array(
				array(
					'sqlite:decl_type' => 'INTEGER',
					'table'            => 'my_table',
				),
			)
		);

		$meta = $stmt->getColumnMeta( 0 );
		$this->assertSame( 'integer', $meta['native_type'] );
		$this->assertSame( 'INTEGER', $meta['sqlite:decl_type'] );
		$this->assertSame( 'my_table', $meta['table'] );
		$this->assertSame( 'id', $meta['name'] );
		$this->assertFalse( $stmt->getColumnMeta( 1 ) );
	}

	public function test_execute_rewinds_cursor(): void {
		$stmt = $this->create_array_statement();
		$stmt->fetchAll( PDO::FETCH_NUM );
		$this->assertFalse( $stmt->fetch( PDO::FETCH_NUM ) );

		$this->assertTrue( $stmt->execute() );
		$this->assertSame( count( self::ROWS ), count( $stmt->fetchAll( PDO::FETCH_NUM ) ) );

		/*
		 * closeCursor() discards the rest of the result set rather than
		 * rewinding it, as PDO does: fetching afterwards yields nothing until
		 * the statement is executed again.
		 */
		$stmt->fetch( PDO::FETCH_NUM );
		$this->assertTrue( $stmt->closeCursor() );
		$this->assertSame( array(), $stmt->fetchAll( PDO::FETCH_NUM ) );

		$this->assertTrue( $stmt->execute() );
		$this->assertSame( count( self::ROWS ), count( $stmt->fetchAll( PDO::FETCH_NUM ) ) );
	}

	public function test_stringification_preserves_value_types_in_meta(): void {
		$stmt = $this->create_array_statement( true );
		$this->assertSame( 'integer', $stmt->getColumnMeta( 0 )['native_type'] );
		$this->assertSame( 'string', $stmt->getColumnMeta( 1 )['native_type'] );
		$this->assertSame( 'double', $stmt->getColumnMeta( 2 )['native_type'] );
		$this->assertSame( 'null', $stmt->getColumnMeta( 3 )['native_type'] );

		$row = $stmt->fetch( PDO::FETCH_NUM );
		$this->assertSame( '1', $row[0] );
		$this->assertSame( 'Alice', $row[1] );
		$this->assertSame( '1.5', $row[2] );
		$this->assertNull( $row[3] );
	}

	public function test_unsupported_fetch_modes_throw(): void {
		$stmt = $this->create_array_statement();
		$this->expectException( RuntimeException::class );
		$stmt->fetch( PDO::FETCH_LAZY );
	}

	public function test_fetch_bound_writes_into_bound_variables(): void {
		$stmt = $this->create_array_statement();

		$id   = null;
		$name = null;
		$this->assertTrue( $stmt->bindColumn( 1, $id ) );
		$this->assertTrue( $stmt->bindColumn( 'name', $name ) );

		// PDO::FETCH_BOUND reports success rather than returning the row.
		$this->assertTrue( $stmt->fetch( PDO::FETCH_BOUND ) );
		$this->assertSame( self::ROWS[0][0], $id );
		$this->assertSame( self::ROWS[0][1], $name );
	}

	public function test_bind_column_rejects_an_unknown_column(): void {
		$stmt = $this->create_array_statement();
		$var  = null;

		$this->expectException( PDOException::class );
		$stmt->bindColumn( 'no_such_column', $var );
	}

	public function test_error_information_reports_success(): void {
		$stmt = $this->create_array_statement();

		$this->assertSame( '00000', $stmt->errorCode() );
		$this->assertSame( array( '00000', null, null ), $stmt->errorInfo() );
	}

	public function test_iteration_yields_the_remaining_rows(): void {
		if ( PHP_VERSION_ID < 80000 ) {
			$this->markTestSkipped(
				'Before PHP 8.0, PDOStatement iterates through an internal handler that'
				. ' a subclass cannot override; getIterator() only takes effect from 8.0.'
			);
		}

		$stmt = $this->create_array_statement();
		$stmt->setFetchMode( PDO::FETCH_NUM );

		$this->assertSame( self::ROWS, iterator_to_array( $stmt ) );

		// Iteration consumes the result set, as PDO's does.
		$this->assertSame( array(), iterator_to_array( $stmt ) );
	}

	public function test_key_pair_requires_two_columns(): void {
		$stmt = $this->create_array_statement();
		$this->expectException( PDOException::class );
		$stmt->fetch( PDO::FETCH_KEY_PAIR );
	}
}

/**
 * A test class for PDO::FETCH_CLASS and fetchObject() tests.
 *
 * phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound
 */
class WP_PDO_Array_Statement_Test_Row {
	public $id;
	public $name;
	public $ratio;
	public $nothing;

	/**
	 * The value of the "name" property at construction time.
	 *
	 * As per PDO::FETCH_CLASS behavior, column values are assigned to object
	 * properties before the constructor is called.
	 *
	 * @var string|null
	 */
	public $name_at_construction;

	/**
	 * The first constructor argument.
	 *
	 * @var mixed
	 */
	public $constructor_arg;

	public function __construct( $constructor_arg = null ) {
		$this->name_at_construction = $this->name;
		$this->constructor_arg      = $constructor_arg;
	}
}
