<?php

/*
 * The SQLite driver uses PDO. Enable PDO function calls:
 *   phpcs:disable WordPress.DB.RestrictedClasses.mysql__PDO
 *   phpcs:disable WordPress.DB.RestrictedClasses.mysql__PDOStatement
 *
 * PDO uses camel case naming, enable non-snake case:
 *   phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
 *   phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
 *
 * Some PDOStatement methods use $class and $var as variable names, enable them:
 *   phpcs:disable Universal.NamingConventions.NoReservedKeywordParameterNames.classFound
 *   phpcs:disable Universal.NamingConventions.NoReservedKeywordParameterNames.varFound
 *
 * We use traits to support different PHP versions with incompatible PDO statement
 * method signatures. For that, enable multiple object structures in one file:
 *   phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound
 */

/**
 * Some PDOStatement methods are not compatible across different PHP versions.
 * To address "Declaration of ... should be compatible with ..." PHP warnings,
 * we conditionally define traits with different APIs based on the PHP version.
 */
if ( PHP_VERSION_ID < 80000 ) {
	trait WP_PDO_Array_Statement_PHP_Compat {
		/**
		 * Set the default fetch mode for this statement.
		 *
		 * @param  int   $mode   The fetch mode to set as the default.
		 * @param  mixed $params Additional parameters for the default fetch mode.
		 * @return bool          True on success, false on failure.
		 */
		public function setFetchMode( $mode, $params = null ): bool {
			// Do not pass additional arguments when they are NULL to prevent
			// "fetch mode doesn't allow any extra arguments" error.
			if ( null === $params ) {
				return $this->setDefaultFetchMode( $mode );
			}
			return $this->setDefaultFetchMode( $mode, $params );
		}

		/**
		 * Fetch all remaining rows from the result set.
		 *
		 * @param  int   $mode             The fetch mode to use.
		 * @param  mixed $class_name       With PDO::FETCH_CLASS, the name of the class to instantiate.
		 * @param  mixed $constructor_args With PDO::FETCH_CLASS, the parameters to pass to the class constructor.
		 * @return array                   The result set as an array of rows.
		 */
		public function fetchAll( $mode = null, $class_name = null, $constructor_args = null ): array {
			// Do not pass additional arguments when they are NULL to prevent
			// "Extraneous additional parameters" error.
			if ( null === $class_name && null === $constructor_args ) {
				return $this->fetchAllRows( $mode );
			}
			return $this->fetchAllRows( $mode, $class_name, $constructor_args );
		}
	}
} else {
	trait WP_PDO_Array_Statement_PHP_Compat {
		/**
		 * Set the default fetch mode for this statement.
		 *
		 * @param  int   $mode   The fetch mode to set as the default.
		 * @param  mixed $args   Additional parameters for the default fetch mode.
		 * @return bool          True on success, false on failure.
		 */
		#[ReturnTypeWillChange]
		public function setFetchMode( $mode, ...$args ): bool {
			return $this->setDefaultFetchMode( $mode, ...$args );
		}

		/**
		 * Fetch all remaining rows from the result set.
		 *
		 * @param  int   $mode The fetch mode to use.
		 * @param  mixed $args Additional parameters for the fetch mode.
		 * @return array       The result set as an array of rows.
		 */
		public function fetchAll( $mode = PDO::FETCH_DEFAULT, ...$args ): array {
			return $this->fetchAllRows( $mode, ...$args );
		}
	}
}

/**
 * PDOStatement implementation that operates on in-memory data.
 *
 * This class implements the PDOStatement interface on top of PHP arrays.
 * It is used for result sets that are composed or transformed in the PHP
 * layer, without a corresponding statement in the underlying database.
 *
 * The behavior follows the PDO SQLite driver. In particular, the fetched
 * values can be stringified as per the PDO::ATTR_STRINGIFY_FETCHES setting,
 * while the column metadata always exposes the original value types.
 *
 * PDO supports the following fetch modes:
 *   - PDO::FETCH_DEFAULT:  current default fetch mode (available from PHP 8.0)
 *   - PDO::FETCH_BOTH:     default
 *   - PDO::FETCH_NUM:      numeric array
 *   - PDO::FETCH_ASSOC:    associative array
 *   - PDO::FETCH_NAMED:    associative array retaining duplicate columns
 *   - PDO::FETCH_COLUMN:   single column value [1 extra arg]
 *   - PDO::FETCH_KEY_PAIR: key-value pair
 *   - PDO::FETCH_OBJ:      object (stdClass)
 *   - PDO::FETCH_CLASS:    object (custom class) [1-2 extra args]
 *   - PDO::FETCH_INTO:     update an exisisting object, can't be used with fetchAll() [1 extra arg]
 *   - PDO::FETCH_LAZY:     lazy fetch via PDORow, can't be used with fetchAll()
 *   - PDO::FETCH_BOUND:    bind values to PHP variables, can't be used with fetchAll()
 *   - PDO::FETCH_FUNC:     custom function, only works with fetchAll(), can't be default [1 extra arg]
 */
class WP_PDO_Array_Statement extends PDOStatement {
	use WP_PDO_Array_Statement_PHP_Compat;

	/**
	 * The column names, in order. Duplicate names are preserved.
	 *
	 * @var string[]
	 */
	private $columns;

	/**
	 * The rows of the result set as lists of positional values.
	 *
	 * The values preserve their original types. Stringification is
	 * applied only when the values are fetched.
	 *
	 * @var array[]
	 */
	private $rows;

	/**
	 * The number of affected rows reported by rowCount().
	 *
	 * @var int
	 */
	private $affected_rows;

	/**
	 * Whether to stringify fetched values (PDO::ATTR_STRINGIFY_FETCHES).
	 *
	 * @var bool
	 */
	private $stringify_fetches;

	/**
	 * Optional column metadata overrides, indexed by column position.
	 *
	 * Each item is an array as per PDOStatement::getColumnMeta() and is
	 * merged over the metadata derived from the column names and values.
	 *
	 * @var array[]
	 */
	private $column_meta;

	/**
	 * The current default fetch mode.
	 *
	 * @var int
	 */
	private $default_fetch_mode;

	/**
	 * Additional arguments for the current default fetch mode.
	 *
	 * @var array
	 */
	private $default_fetch_args = array();

	/**
	 * The cursor position in the result set (0-indexed).
	 *
	 * @var int
	 */
	private $cursor = 0;

	/**
	 * Columns bound to PHP variables by bindColumn(), for PDO::FETCH_BOUND.
	 *
	 * Keyed by the identifier the caller gave, holding a reference to their
	 * variable and the column position it resolves to.
	 *
	 * @var array<string|int, array{var: mixed, column: int}>
	 */
	private $bound_columns = array();

	/**
	 * Constructor.
	 *
	 * @param string[] $columns           The column names, in order. Duplicates are allowed.
	 * @param array[]  $rows              The rows of the result set. Values can be indexed
	 *                                    positionally or by column names (in the column order).
	 * @param int      $affected_rows     The number of affected rows reported by rowCount().
	 * @param bool     $stringify_fetches Whether to stringify fetched values.
	 * @param array[]  $column_meta       Optional column metadata overrides, indexed by column
	 *                                    position, as per PDOStatement::getColumnMeta().
	 * @param int      $default_fetch_mode The initial default fetch mode.
	 */
	public function __construct(
		array $columns,
		array $rows,
		int $affected_rows = 0,
		bool $stringify_fetches = false,
		array $column_meta = array(),
		int $default_fetch_mode = PDO::FETCH_BOTH
	) {
		$this->columns            = array_values( $columns );
		$this->rows               = array_map( 'array_values', $rows );
		$this->affected_rows      = $affected_rows;
		$this->stringify_fetches  = $stringify_fetches;
		$this->column_meta        = $column_meta;
		$this->default_fetch_mode = $default_fetch_mode;
	}

	/**
	 * Execute the statement.
	 *
	 * The in-memory result set is already materialized, so this only
	 * rewinds the cursor to make all rows available again.
	 *
	 * @param mixed $params The values to bind to the parameters of the prepared statement.
	 * @return bool         True on success, false on failure.
	 */
	public function execute( $params = null ): bool {
		$this->cursor = 0;
		return true;
	}

	/**
	 * Get the number of columns in the result set.
	 *
	 * @return int The number of columns in the result set.
	 */
	public function columnCount(): int {
		return count( $this->columns );
	}

	/**
	 * Get the number of rows affected by the statement.
	 *
	 * @return int The number of rows affected by the statement.
	 */
	public function rowCount(): int {
		return $this->affected_rows;
	}

	/**
	 * Fetch the next row from the result set.
	 *
	 * @param  int|null $mode              The fetch mode. Controls how the row is returned.
	 *                                     Default: PDO::FETCH_DEFAULT (null for PHP < 8.0)
	 * @param  int|null $cursorOrientation The cursor orientation. Controls which row is returned.
	 *                                     Default: PDO::FETCH_ORI_NEXT (null for PHP < 8.0)
	 * @param  int|null $cursorOffset      The cursor offset. Controls which row is returned.
	 *                                     Default: 0 (null for PHP < 8.0)
	 * @return mixed                       The row data formatted according to the fetch mode;
	 *                                     false if there are no more rows or a failure occurs.
	 */
	#[ReturnTypeWillChange]
	public function fetch(
		$mode = 0, // PDO::FETCH_DEFAULT (available from PHP 8.0)
		$cursorOrientation = 0,
		$cursorOffset = 0
	) {
		$args = array();
		if ( 0 === $mode || null === $mode ) {
			$mode = $this->default_fetch_mode;
			$args = $this->default_fetch_args;
		}

		if ( ! isset( $this->rows[ $this->cursor ] ) ) {
			return false;
		}
		$row           = $this->rows[ $this->cursor ];
		$this->cursor += 1;

		return $this->format_row( $row, $mode, $args );
	}

	/**
	 * Fetch a single column from the next row of a result set.
	 *
	 * @param  int   $column The index of the column to fetch (0-indexed).
	 * @return mixed         The value of the column; false if there are no more rows.
	 */
	#[ReturnTypeWillChange]
	public function fetchColumn( $column = 0 ) {
		if ( ! isset( $this->rows[ $this->cursor ] ) ) {
			return false;
		}
		$row           = $this->rows[ $this->cursor ];
		$this->cursor += 1;

		$this->assert_column_index( $column );
		return $this->output_value( $row[ $column ] );
	}

	/**
	 * Fetch the next row as an object.
	 *
	 * @param  string $class           The name of the class to instantiate.
	 * @param  array  $constructorArgs The parameters to pass to the class constructor.
	 * @return object|false            The next row as an object; false if there are no more rows.
	 */
	#[ReturnTypeWillChange]
	public function fetchObject( $class = 'stdClass', $constructorArgs = array() ) {
		if ( ! isset( $this->rows[ $this->cursor ] ) ) {
			return false;
		}
		$row           = $this->rows[ $this->cursor ];
		$this->cursor += 1;

		return $this->create_object( $class, $constructorArgs ?? array(), $row );
	}

	/**
	 * Get metadata for a column in a result set.
	 *
	 * The metadata follows the PDO SQLite driver. The "native_type" field
	 * exposes the PHP type of the original column value ("integer", "double",
	 * "string", or "null"), independently of the value stringification.
	 *
	 * @param  int         $column The index of the column (0-indexed).
	 * @return array|false         The column metadata as an associative array,
	 *                             or false if the column does not exist.
	 */
	#[ReturnTypeWillChange]
	public function getColumnMeta( $column ) {
		if ( ! isset( $this->columns[ $column ] ) ) {
			return false;
		}

		// Derive the native type from the first row of the result set.
		$value = $this->rows[0][ $column ] ?? null;
		if ( is_int( $value ) ) {
			$native_type = 'integer';
			$pdo_type    = PDO::PARAM_INT;
		} elseif ( is_float( $value ) ) {
			$native_type = 'double';
			$pdo_type    = PDO::PARAM_STR;
		} elseif ( null === $value ) {
			$native_type = 'null';
			$pdo_type    = PDO::PARAM_NULL;
		} else {
			$native_type = 'string';
			$pdo_type    = PDO::PARAM_STR;
		}

		// Note that there is no "table" key. This matches the PDO SQLite
		// behavior for columns that are not part of a database table.
		$meta = array(
			'native_type' => $native_type,
			'pdo_type'    => $pdo_type,
			'flags'       => array(),
			'name'        => $this->columns[ $column ],
			'len'         => -1,
			'precision'   => 0,
		);

		if ( isset( $this->column_meta[ $column ] ) ) {
			$meta = array_merge( $meta, $this->column_meta[ $column ] );
		}
		return $meta;
	}

	/**
	 * Fetch the SQLSTATE associated with the last statement operation.
	 *
	 * @return string|null The SQLSTATE error code (as defined by the ANSI SQL standard),
	 *                     or null if there is no error.
	 */
	public function errorCode(): ?string {
		/*
		 * An in-memory statement only exists once its statement succeeded:
		 * failures are thrown before one is constructed. So there is never an
		 * error to report, which is the SQLSTATE for successful completion.
		 */
		return '00000';
	}

	/**
	 * Fetch error information associated with the last statement operation.
	 *
	 * @return array The array consists of at least the following fields:
	 *                 0: SQLSTATE error code (as defined by the ANSI SQL standard).
	 *                 1: Driver-specific error code.
	 *                 2: Driver-specific error message.
	 */
	public function errorInfo(): array {
		// See errorCode(). PDO reports the driver fields as null on success.
		return array( '00000', null, null );
	}

	/**
	 * Get a statement attribute.
	 *
	 * @param  int   $attribute The attribute to get.
	 * @return mixed            The value of the attribute.
	 */
	#[ReturnTypeWillChange]
	public function getAttribute( $attribute ) {
		throw new RuntimeException( 'Not implemented' );
	}

	/**
	 * Set a statement attribute.
	 *
	 * @param  int   $attribute The attribute to set.
	 * @param  mixed $value     The value of the attribute.
	 * @return bool             True on success, false on failure.
	 */
	public function setAttribute( $attribute, $value ): bool {
		throw new RuntimeException( 'Not implemented' );
	}

	/**
	 * Get result set as iterator.
	 *
	 * @return Iterator The iterator for the result set.
	 */
	public function getIterator(): Iterator {
		/*
		 * PDO iterates the remaining rows in the statement's fetch mode, and
		 * consumes them as it goes -- iterating twice yields nothing the second
		 * time. Reading through fetch() reproduces both.
		 *
		 * Relies on PDOStatement implementing IteratorAggregate, which it does
		 * from PHP 8.0 -- the floor for this fork. Before that it iterated through
		 * an internal handler no subclass could override.
		 */
		return new ArrayIterator( $this->fetchAllRows() );
	}

	/**
	 * Advances to the next rowset in a multi-rowset statement handle.
	 *
	 * @return bool True on success, false on failure.
	 */
	public function nextRowset(): bool {
		throw new RuntimeException( 'Not implemented' );
	}

	/**
	 * Closes the cursor, enabling the statement to be executed again.
	 *
	 * @return bool True on success, false on failure.
	 */
	public function closeCursor(): bool {
		/*
		 * PDO discards the rest of the result set rather than rewinding it: after
		 * closeCursor() a fetch() returns false, and only a re-execute() makes
		 * the rows available again.
		 */
		$this->cursor = count( $this->rows );
		return true;
	}

	/**
	 * Bind a column to a PHP variable.
	 *
	 * @param  int|string $column        Number of the column (1-indexed) or name of the column in the result set.
	 * @param  mixed      $var           PHP variable to which the column will be bound.
	 * @param  int        $type          Data type of the parameter, specified by the PDO::PARAM_* constants.
	 * @param  int        $maxLength     A hint for pre-allocation.
	 * @param  mixed      $driverOptions Optional parameters for the driver.
	 * @return bool                      True on success, false on failure.
	 */
	public function bindColumn( $column, &$var, $type = null, $maxLength = null, $driverOptions = null ): bool {
		$position = $this->resolve_column_position( $column );
		if ( null === $position ) {
			// Matches PDO, which refuses rather than silently ignoring.
			throw new PDOException(
				sprintf(
					"SQLSTATE[HY000]: General error: Did not find column name '%s' in the defined columns;"
					. ' it will not be bound',
					$column
				)
			);
		}

		// Hold the caller's variable by reference so PDO::FETCH_BOUND can write
		// to it on every fetch.
		$this->bound_columns[ $column ] = array(
			'var'    => &$var,
			'column' => $position,
		);
		return true;
	}

	/**
	 * Resolve a bindColumn() identifier to a column position.
	 *
	 * @param  int|string $column A 1-indexed column number or a column name.
	 * @return int|null           The 0-indexed position, or null when unknown.
	 */
	private function resolve_column_position( $column ): ?int {
		if ( is_int( $column ) || ( is_string( $column ) && ctype_digit( $column ) ) ) {
			// PDO numbers columns from 1 here, unlike everywhere else.
			$position = (int) $column - 1;
			return isset( $this->columns[ $position ] ) ? $position : null;
		}

		$position = array_search( (string) $column, $this->columns, true );
		return false === $position ? null : (int) $position;
	}

	/**
	 * Bind a parameter to a PHP variable.
	 *
	 * @param  int|string $param         Parameter identifier. Either a 1-indexed position of the parameter or a named parameter.
	 * @param  mixed      $var           PHP variable to which the parameter will be bound.
	 * @param  int        $type          Data type of the parameter, specified by the PDO::PARAM_* constants.
	 * @param  int        $maxLength     Length of the data type.
	 * @param  mixed      $driverOptions Optional parameters for the driver.
	 * @return bool                      True on success, false on failure.
	 */
	public function bindParam( $param, &$var, $type = PDO::PARAM_STR, $maxLength = 0, $driverOptions = null ): bool {
		throw new RuntimeException( 'Not implemented' );
	}

	/**
	 * Bind a value to a parameter.
	 *
	 * @param  int|string $param Parameter identifier. Either a 1-indexed position of the parameter or a named parameter.
	 * @param  mixed      $value The value to bind to the parameter.
	 * @param  int        $type  Data type of the parameter, specified by the PDO::PARAM_* constants.
	 * @return bool              True on success, false on failure.
	 */
	public function bindValue( $param, $value, $type = PDO::PARAM_STR ): bool {
		throw new RuntimeException( 'Not implemented' );
	}

	/**
	 * Dump information about the statement.
	 *
	 * Dupms the SQL query and parameters information.
	 *
	 * @return bool|null Returns null, or false on failure.
	 */
	public function debugDumpParams(): ?bool {
		throw new RuntimeException( 'Not implemented' );
	}

	/**
	 * Fetch all remaining rows from the result set.
	 *
	 * This is used internally by the "WP_PDO_Array_Statement_PHP_Compat" trait,
	 * that is defined conditionally based on the current PHP version.
	 *
	 * @param  int   $mode The fetch mode to use.
	 * @param  mixed $args Additional parameters for the fetch mode.
	 * @return array       The result set as an array of rows.
	 */
	private function fetchAllRows( $mode = null, ...$args ): array {
		if ( null === $mode || 0 === $mode ) {
			$mode = $this->default_fetch_mode;
			$args = $this->default_fetch_args;
		}

		if ( PDO::FETCH_INTO === $mode || PDO::FETCH_LAZY === $mode ) {
			throw new ValueError( 'PDOStatement::fetchAll(): Argument #1 ($mode) fetch mode cannot be used with PDOStatement::fetchAll()' );
		}

		$result = array();
		while ( isset( $this->rows[ $this->cursor ] ) ) {
			$row           = $this->rows[ $this->cursor ];
			$this->cursor += 1;

			if ( PDO::FETCH_KEY_PAIR === $mode ) {
				// For duplicate keys, the last pair wins, as per PDO behavior.
				foreach ( $this->format_row( $row, $mode, $args ) as $key => $value ) {
					$result[ $key ] = $value;
				}
			} else {
				$result[] = $this->format_row( $row, $mode, $args );
			}
		}
		return $result;
	}

	/**
	 * Set the default fetch mode for this statement.
	 *
	 * This is used internally by the "WP_PDO_Array_Statement_PHP_Compat" trait,
	 * that is defined conditionally based on the current PHP version.
	 *
	 * @param  int   $mode   The fetch mode to set as the default.
	 * @param  mixed $args   Additional parameters for the default fetch mode.
	 * @return bool          True on success, false on failure.
	 */
	private function setDefaultFetchMode( $mode, ...$args ): bool {
		if ( PDO::FETCH_LAZY === $mode || PDO::FETCH_BOUND === $mode || PDO::FETCH_FUNC === $mode ) {
			throw new RuntimeException( 'Not implemented' );
		}
		$this->default_fetch_mode = $mode;
		$this->default_fetch_args = $args;
		return true;
	}

	/**
	 * Format a raw positional row according to a fetch mode.
	 *
	 * @param  array $row  The raw positional row values.
	 * @param  int   $mode The fetch mode to use.
	 * @param  array $args Additional parameters for the fetch mode.
	 * @return mixed       The row data formatted according to the fetch mode.
	 */
	private function format_row( array $row, int $mode, array $args ) {
		switch ( $mode ) {
			case PDO::FETCH_NUM:
				return $this->format_row_num( $row );
			case PDO::FETCH_ASSOC:
				return $this->format_row_assoc( $row );
			case PDO::FETCH_BOTH:
				/*
				 * As per PDO, for each column the associative key is followed by
				 * the numeric one, and the two behave differently when they
				 * collide. A repeated name takes the later value while keeping
				 * its first position, but a numeric index is only filled when it
				 * is still free -- so a column *named* "2" keeps key 2, and the
				 * third column then has no numeric key at all. PHP turns a
				 * numeric-string key into an integer, which is what makes the
				 * two namespaces overlap in the first place.
				 */
				$values = array();
				foreach ( $this->columns as $i => $name ) {
					$value           = $this->output_value( $row[ $i ] ?? null );
					$values[ $name ] = $value;
					if ( ! array_key_exists( $i, $values ) ) {
						$values[ $i ] = $value;
					}
				}
				return $values;
			case PDO::FETCH_NAMED:
				$named = array();
				foreach ( $this->columns as $i => $name ) {
					$value = $this->output_value( $row[ $i ] ?? null );
					if ( ! array_key_exists( $name, $named ) ) {
						$named[ $name ] = $value;
					} elseif ( is_array( $named[ $name ] ) ) {
						$named[ $name ][] = $value;
					} else {
						$named[ $name ] = array( $named[ $name ], $value );
					}
				}
				return $named;
			case PDO::FETCH_COLUMN:
				$column = $args[0] ?? 0;
				$this->assert_column_index( $column, $row );
				return $this->output_value( $row[ $column ] );
			case PDO::FETCH_KEY_PAIR:
				if ( 2 !== count( $this->columns ) ) {
					throw new PDOException(
						'SQLSTATE[HY000]: General error: PDO::FETCH_KEY_PAIR fetch mode requires the result set to contain exactly 2 columns.'
					);
				}
				return array( $this->output_value( $row[0] ) => $this->output_value( $row[1] ) );
			case PDO::FETCH_OBJ:
				return (object) $this->format_row_assoc( $row );
			case PDO::FETCH_CLASS:
				$class            = $args[0] ?? 'stdClass';
				$constructor_args = $args[1] ?? array();
				return $this->create_object( $class, $constructor_args, $row );
			case PDO::FETCH_BOUND:
				/*
				 * Write each bound column into the caller's variable and report
				 * only success; PDO returns true rather than the row here.
				 */
				foreach ( $this->bound_columns as &$binding ) {
					$binding['var'] = $this->output_value( $row[ $binding['column'] ] ?? null );
				}
				unset( $binding );
				return true;
			case PDO::FETCH_INTO:
				$object = $args[0] ?? null;
				if ( ! is_object( $object ) ) {
					throw new RuntimeException( 'No fetch-into object specified.' );
				}
				foreach ( $this->format_row_assoc( $row ) as $name => $value ) {
					$object->$name = $value;
				}
				return $object;
			default:
				throw new RuntimeException( 'Not implemented' );
		}
	}

	/**
	 * Validate a column index, raising what PDO raises.
	 *
	 * PDO distinguishes the two ways an index can be wrong: a negative one is
	 * rejected outright, while one past the end of the row is an invalid index.
	 *
	 * @param  mixed      $column The column index to validate.
	 * @param  array|null $row    The row to check the upper bound against.
	 * @throws ValueError         When the index is not usable.
	 */
	private function assert_column_index( $column, ?array $row = null ): void {
		if ( is_int( $column ) && $column < 0 ) {
			throw new ValueError( 'Column index must be greater than or equal to 0' );
		}
		$row = $row ?? ( $this->rows[ $this->cursor - 1 ] ?? array() );
		if ( ! array_key_exists( $column, $row ) ) {
			throw new ValueError( 'Invalid column index' );
		}
	}

	/**
	 * Format a raw positional row as a numeric array.
	 *
	 * @param  array $row The raw positional row values.
	 * @return array      The row as a numeric array.
	 */
	private function format_row_num( array $row ): array {
		$values = array();
		foreach ( $this->columns as $i => $name ) {
			$values[ $i ] = $this->output_value( $row[ $i ] ?? null );
		}
		return $values;
	}

	/**
	 * Format a raw positional row as an associative array.
	 *
	 * For duplicate column names, the value of the last column wins.
	 * This matches the PDO::FETCH_ASSOC behavior.
	 *
	 * @param  array $row The raw positional row values.
	 * @return array      The row as an associative array.
	 */
	private function format_row_assoc( array $row ): array {
		$values = array();
		foreach ( $this->columns as $i => $name ) {
			$values[ $name ] = $this->output_value( $row[ $i ] ?? null );
		}
		return $values;
	}

	/**
	 * Create an object from a row, as per PDO::FETCH_CLASS.
	 *
	 * Following the PDO behavior, the column values are assigned to the object
	 * properties first, and the constructor is called afterwards.
	 *
	 * @param  string $class            The name of the class to instantiate.
	 * @param  array  $constructor_args The parameters to pass to the class constructor.
	 * @param  array  $row              The raw positional row values.
	 * @return object                   The created object.
	 */
	private function create_object( string $class, array $constructor_args, array $row ) {
		if ( 'stdClass' === $class ) {
			return (object) $this->format_row_assoc( $row );
		}

		$reflection = new ReflectionClass( $class );
		$object     = $reflection->newInstanceWithoutConstructor();

		// Assign values also to private and protected properties, as PDO does.
		$values = $this->format_row_assoc( $row );
		$setter = Closure::bind(
			function ( $instance, $values ) {
				foreach ( $values as $name => $value ) {
					$instance->$name = $value;
				}
			},
			null,
			$class
		);
		$setter( $object, $values );

		$constructor = $reflection->getConstructor();
		if ( null !== $constructor ) {
			$constructor->invokeArgs( $object, $constructor_args );
		}
		return $object;
	}

	/**
	 * Convert a raw value to its fetched representation.
	 *
	 * When stringification is enabled, integers and floats are converted
	 * to strings, following the PDO::ATTR_STRINGIFY_FETCHES behavior.
	 *
	 * @param  mixed $value The raw value.
	 * @return mixed        The value to return from fetch functions.
	 */
	private function output_value( $value ) {
		if ( $this->stringify_fetches && ( is_int( $value ) || is_float( $value ) ) ) {
			return (string) $value;
		}
		return $value;
	}
}
