<?php

/**
 * Test backend selection.
 *
 * The driver test suites can run against different connection backends,
 * selected with the WP_SQLITE_TEST_BACKEND environment variable:
 *
 *   - "pdo" (default): the local PDO SQLite connection.
 *   - "d1": the Cloudflare D1 connection over a fake transport enforcing
 *     D1 semantics (no transactions, no temporary tables, no user-defined
 *     functions, JSON value coercion) against a local SQLite database.
 *
 * phpcs:disable WordPress.DB.RestrictedClasses.mysql__PDO
 */

if ( 'd1' === getenv( 'WP_SQLITE_TEST_BACKEND' ) ) {
	require_once __DIR__ . '/../../src/d1/load.php';
	require_once __DIR__ . '/class-wp-sqlite-d1-fake-transport.php';
}

/**
 * Get the connection backend to run driver tests against.
 *
 * @return string The backend name: "pdo" or "d1".
 */
function wp_sqlite_tests_backend(): string {
	$backend = getenv( 'WP_SQLITE_TEST_BACKEND' );
	return false === $backend || '' === $backend ? 'pdo' : $backend;
}

/**
 * Create a driver instance using the selected test backend.
 *
 * @param  PDO|null $sqlite  Set to the underlying SQLite PDO handle, which
 *                           tests can use to inspect the raw database state.
 * @param  string   $db_name The database name.
 * @return WP_SQLite_Driver  The driver.
 */
function wp_sqlite_tests_create_engine( ?PDO &$sqlite = null, string $db_name = 'wp' ): WP_SQLite_Driver {
	$pdo_class = PHP_VERSION_ID >= 80400 ? PDO\SQLite::class : PDO::class;
	$sqlite    = new $pdo_class( 'sqlite::memory:' );

	if ( 'd1' === wp_sqlite_tests_backend() ) {
		// Stringify fetches on the raw handle, so that direct assertions
		// against it behave as they do with the PDO backend.
		$sqlite->setAttribute( PDO::ATTR_STRINGIFY_FETCHES, true );
		$connection = new WP_SQLite_D1_Connection( new WP_SQLite_D1_Fake_Transport( $sqlite ) );

		// Transactional statements are ignored: PHPUnit converts the
		// "warn" fallback warnings to test errors.
		return new WP_SQLite_Driver( $connection, $db_name, 80038, array( 'transaction_fallback' => 'ignore' ) );
	}

	return new WP_SQLite_Driver(
		new WP_SQLite_Connection( array( 'pdo' => $sqlite ) ),
		$db_name
	);
}

/**
 * Create a raw engine (PDO API) instance using the selected test backend.
 *
 * @param  string   $dsn    The engine DSN.
 * @param  PDO|null $sqlite Set to the underlying SQLite PDO handle, which
 *                          tests can use to inspect the raw database state.
 * @return WP_MySQL_On_SQLite The engine.
 */
function wp_sqlite_tests_create_pdo_engine( string $dsn, ?PDO &$sqlite = null ): WP_MySQL_On_SQLite {
	$pdo_class = PHP_VERSION_ID >= 80400 ? PDO\SQLite::class : PDO::class;
	$sqlite    = new $pdo_class( 'sqlite::memory:' );

	if ( 'd1' === wp_sqlite_tests_backend() ) {
		// Stringify fetches on the raw handle, so that direct assertions
		// against it behave as they do with the PDO backend.
		$sqlite->setAttribute( PDO::ATTR_STRINGIFY_FETCHES, true );
		return new WP_MySQL_On_SQLite(
			$dsn,
			null,
			null,
			array(
				'connection'           => new WP_SQLite_D1_Connection( new WP_SQLite_D1_Fake_Transport( $sqlite ) ),
				'transaction_fallback' => 'ignore',
			)
		);
	}
	return new WP_MySQL_On_SQLite( $dsn, null, null, array( 'pdo' => $sqlite ) );
}

/**
 * Skip the current test when it covers a feature that the selected test
 * backend does not support.
 *
 * @param PHPUnit\Framework\TestCase $test The current test instance.
 */
function wp_sqlite_tests_skip_unsupported( PHPUnit\Framework\TestCase $test ): void {
	if ( 'd1' !== wp_sqlite_tests_backend() ) {
		return;
	}

	$reasons = array(
		'transactions'     => 'D1 does not support interactive transactions.',
		'temporary tables' => 'D1 does not support temporary tables.',
		'REGEXP'           => 'D1 does not support the REGEXP operator (no user-defined functions).',
		'seeded RAND'      => 'D1 does not support seeded RAND(N) (no user-defined functions).',
		'strict messages'  => 'Strict mode error messages differ without user-defined functions.',
		'PHP evaluation'   => 'The function requires constant arguments without user-defined functions.',
		'column metadata'  => 'Detailed column metadata is not carried by the D1 protocol.',
	);

	$skip_list = wp_sqlite_tests_d1_skip_list();
	$test_name = get_class( $test ) . '::' . $test->getName( false );
	if ( isset( $skip_list[ $test_name ] ) ) {
		$test->markTestSkipped( $reasons[ $skip_list[ $test_name ] ] );
	}
}

/**
 * The list of tests not supported by the D1 backend, with skip reasons.
 *
 * @return array<string, string> A map of "Class::method" to a reason key.
 */
function wp_sqlite_tests_d1_skip_list(): array {
	$list = array(
		// Interactive transactions and savepoints.
		'WP_MySQL_On_SQLite_Tests::testStartTransactionCommand' => 'transactions',
		'WP_MySQL_On_SQLite_Tests::testRepeatedTransactionCommands' => 'transactions',
		'WP_MySQL_On_SQLite_Tests::testTransactionRollback' => 'transactions',
		'WP_MySQL_On_SQLite_Tests::testTransactionSavepoints' => 'transactions',
		'WP_MySQL_On_SQLite_Tests::testRollbackNonExistentTransactionSavepoint' => 'transactions',

		// Temporary tables.
		'WP_MySQL_On_SQLite_Tests::testCreateTemporaryTable' => 'temporary tables',
		'WP_MySQL_On_SQLite_Tests::testCreateTemporaryTableIfNotExists' => 'temporary tables',
		'WP_MySQL_On_SQLite_Tests::testLockTemporaryTables' => 'temporary tables',
		'WP_MySQL_On_SQLite_Tests::testNonStrictModeWithTemporaryTable' => 'temporary tables',
		'WP_MySQL_On_SQLite_Tests::testTemporaryTableHasPriorityOverStandardTable' => 'temporary tables',

		// The REGEXP operator.
		'WP_MySQL_On_SQLite_Tests::testRegexp'             => 'REGEXP',
		'WP_MySQL_On_SQLite_Tests::testRegexps'            => 'REGEXP',

		// Seeded RAND(N).
		'WP_MySQL_On_SQLite_Tests::testRandOrderBy'        => 'seeded RAND',
		'WP_MySQL_On_SQLite_Tests::testRandInUpdateAndInsert' => 'seeded RAND',
		'WP_MySQL_On_SQLite_Tests::testRandFloatSeedRoundsToNearestInteger' => 'seeded RAND',
		'WP_MySQL_On_SQLite_Tests::testRandMultipleCallSitesShareLcgState' => 'seeded RAND',
		'WP_MySQL_On_SQLite_Tests::testRandNegativeSeedIsDeterministicAndInRange' => 'seeded RAND',
		'WP_MySQL_On_SQLite_Tests::testRandNonConstantSeedReinitializesPerRow' => 'seeded RAND',
		'WP_MySQL_On_SQLite_Tests::testRandNullIsEquivalentToZeroSeed' => 'seeded RAND',
		'WP_MySQL_On_SQLite_Tests::testRandSeededAndUnseededInSameQueryAreIndependent' => 'seeded RAND',
		'WP_MySQL_On_SQLite_Tests::testRandSeededMultiRowProducesDeterministicSequence' => 'seeded RAND',
		'WP_MySQL_On_SQLite_Tests::testRandSeededProducesDeterministicValues' => 'seeded RAND',
		'WP_MySQL_On_SQLite_Tests::testRandSeededResetsAcrossStatementsInsideTransaction' => 'seeded RAND',
		'WP_MySQL_On_SQLite_Tests::testRandSeededStateIsResetBetweenStatements' => 'seeded RAND',
		'WP_MySQL_On_SQLite_Tests::testRandStringSeedIsCoercedLikeMySQL' => 'seeded RAND',

		// Strict mode error messages.
		'WP_MySQL_On_SQLite_Tests::testCastValuesOnInsert' => 'strict messages',
		'WP_MySQL_On_SQLite_Tests::testCastValuesOnUpdate' => 'strict messages',
		'WP_MySQL_On_SQLite_Tests::testZeroDateRejectedWhenNoZeroDateAndStrictModeAreOn' => 'strict messages',
		'WP_MySQL_On_SQLite_Tests::testZeroDateInUpdateRejectedWhenNoZeroDateAndStrictModeAreOn' => 'strict messages',
		'WP_MySQL_On_SQLite_Tests::testZeroInDateRejectedWhenNoZeroInDateAndStrictModeAreOn' => 'strict messages',
		'WP_MySQL_On_SQLite_Tests::testZeroInDateInUpdateRejectedWhenNoZeroInDateAndStrictModeAreOn' => 'strict messages',

		// PHP-evaluated functions with non-constant arguments.
		'WP_MySQL_On_SQLite_Tests::testTranslateLikeBinary' => 'PHP evaluation',
		'WP_MySQL_On_SQLite_Tests::testToBase64Function'   => 'PHP evaluation',
		'WP_MySQL_On_SQLite_Tests::testFromBase64Function' => 'PHP evaluation',
		'WP_MySQL_On_SQLite_Tests::testReverseFunction'    => 'PHP evaluation',

		// Detailed column metadata.
		'WP_MySQL_On_SQLite_Tests::testColumnInfo'         => 'column metadata',
		'WP_MySQL_On_SQLite_Tests::testColumnInfoWithConstraints' => 'column metadata',
		'WP_MySQL_On_SQLite_Tests::testColumnInfoForIntegerDataTypes' => 'column metadata',
		'WP_MySQL_On_SQLite_Tests::testColumnInfoForUnsignedIntegerDataTypes' => 'column metadata',
		'WP_MySQL_On_SQLite_Tests::testColumnInfoForFloatingPointDataTypes' => 'column metadata',
		'WP_MySQL_On_SQLite_Tests::testColumnInfoForStringDataTypes' => 'column metadata',
		'WP_MySQL_On_SQLite_Tests::testColumnInfoForDateAndTimeDataTypes' => 'column metadata',
		'WP_MySQL_On_SQLite_Tests::testColumnInfoForBinaryDataTypes' => 'column metadata',
		'WP_MySQL_On_SQLite_Tests::testColumnInfoForSpatialDataTypes' => 'column metadata',
		'WP_MySQL_On_SQLite_Tests::testColumnInfoForExpressions' => 'column metadata',
		'WP_MySQL_On_SQLite_Tests::testColumnInfoWithZeroRows' => 'column metadata',
		'WP_MySQL_On_SQLite_Tests::testColumnInfoWithZeroRowsPhpBug' => 'column metadata',
	);
	return $list;
}
