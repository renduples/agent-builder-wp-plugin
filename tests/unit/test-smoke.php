<?php
/**
 * Smoke test — confirms the harness boots the real plugin against a real
 * WordPress test database.
 *
 * @package Agentic\Tests
 */

namespace Agentic\Tests;

/**
 * Test case verifying the harness itself.
 */
class Test_Smoke extends TestCase {

	/**
	 * The plugin's classes must be loaded.
	 */
	public function test_plugin_classes_loaded(): void {
		$this->assertTrue( class_exists( '\Agentic\Risk_Level' ) );
		$this->assertTrue( class_exists( '\Agentic\Audit_Log' ) );
		$this->assertTrue( class_exists( '\Agentic\Approval_Queue' ) );
	}

	/**
	 * The custom tables must exist.
	 */
	public function test_custom_tables_exist(): void {
		global $wpdb;
		foreach ( array( 'agent_builder_audit_log', 'agent_builder_approval_queue', 'agent_builder_tools' ) as $suffix ) {
			$table = $wpdb->prefix . $suffix;
			$this->assertSame( $table, $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) );
		}
	}
}
