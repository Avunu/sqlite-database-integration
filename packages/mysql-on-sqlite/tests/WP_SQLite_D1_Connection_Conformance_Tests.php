<?php

require_once __DIR__ . '/../src/d1/load.php';
require_once __DIR__ . '/tools/class-wp-sqlite-connection-conformance-test-case.php';
require_once __DIR__ . '/tools/class-wp-sqlite-d1-fake-transport.php';

/**
 * Conformance tests for the D1 connection, using the fake D1 transport.
 */
class WP_SQLite_D1_Connection_Conformance_Tests extends WP_SQLite_Connection_Conformance_Test_Case {
	protected function create_connection(): WP_SQLite_Connection_Interface {
		return new WP_SQLite_D1_Connection( new WP_SQLite_D1_Fake_Transport() );
	}

	protected function expected_capabilities(): array {
		// D1 supports none of the optional connection capabilities.
		return array();
	}
}
