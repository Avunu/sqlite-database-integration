<?php

require_once __DIR__ . '/tools/class-wp-sqlite-connection-conformance-test-case.php';

/**
 * Conformance tests for the default PDO SQLite connection.
 */
class WP_SQLite_Connection_Conformance_Tests extends WP_SQLite_Connection_Conformance_Test_Case {
	protected function create_connection(): WP_SQLite_Connection_Interface {
		$connection = new WP_SQLite_Connection( array( 'path' => ':memory:' ) );
		$connection->set_attribute( PDO::ATTR_STRINGIFY_FETCHES, true );
		return $connection;
	}

	protected function expected_capabilities(): array {
		return array(
			WP_SQLite_Connection_Interface::CAPABILITY_TRANSACTIONS,
			WP_SQLite_Connection_Interface::CAPABILITY_SAVEPOINTS,
			WP_SQLite_Connection_Interface::CAPABILITY_TEMPORARY_TABLES,
			WP_SQLite_Connection_Interface::CAPABILITY_USER_DEFINED_FUNCTIONS,
		);
	}
}
