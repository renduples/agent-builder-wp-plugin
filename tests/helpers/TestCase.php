<?php
/**
 * Base Test Case Class
 *
 * @package Agent_Builder
 * @subpackage Tests
 */

namespace Agentic\Tests;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter, PluginCheck.CodeAnalysis.Heredoc.NotAllowed

use WP_UnitTestCase;

/**
 * Base test case for Agent Builder tests.
 */
class TestCase extends WP_UnitTestCase {

	/**
	 * Setup test environment.
	 */
	public function setUp(): void {
		parent::setUp();
		// Flush the static registry cache so each test reads fresh table state —
		// otherwise Risk_Level::$registry survives from a previous test even
		// though the WP test framework rolled back that test's DB inserts.
		\Agentic\Risk_Level::bust_cache();
		\Agentic\Tools_Registry::bust_cache();
		$this->cleanup_test_data();
		$this->seed_tools_table();
	}

	/**
	 * Teardown test environment.
	 */
	public function tearDown(): void {
		$this->cleanup_test_data();
		parent::tearDown();
	}

	/**
	 * Cleanup test data.
	 */
	protected function cleanup_test_data() {
		global $wpdb;

		$wpdb->query(
			"DELETE FROM {$wpdb->options}
			WHERE option_name LIKE 'agentic_test_%'"
		);

		$audit_table = $wpdb->prefix . 'agent_builder_audit_log';
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$audit_table}'" ) === $audit_table ) {
			$wpdb->query( "DELETE FROM {$audit_table}" );
		}

		$queue_table = $wpdb->prefix . 'agent_builder_approval_queue';
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$queue_table}'" ) === $queue_table ) {
			$wpdb->query( "DELETE FROM {$queue_table}" );
		}

		$security_table = $wpdb->prefix . 'agent_builder_security_log';
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$security_table}'" ) === $security_table ) {
			$wpdb->query( "DELETE FROM {$security_table}" );
		}
	}

	/**
	 * Seed a handful of real, currently-shipping tools into wp_agent_builder_tools,
	 * all enabled, so Tools_Registry::get_all() / is_enabled() have rows to
	 * read from during a test. Tests that need a specific tool disabled
	 * should call Tools_Registry::set_enabled() directly.
	 */
	protected function seed_tools_table(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'agent_builder_tools';
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
			return;
		}
		$wpdb->query( "DELETE FROM {$table}" );
		\Agentic\Tools_Registry::bust_cache();
		\Agentic\Risk_Level::bust_cache();

		$tool_names = array(
			'db_update_option', 'db_create_post', 'db_update_post', 'db_delete_post',
			'list_posts', 'get_post_content', 'get_site_overview',
			'create_post_content', 'add_custom_css', 'purge_expired_transients',
		);
		foreach ( $tool_names as $name ) {
			$wpdb->replace(
				$table,
				array(
					'name'        => $name,
					'description' => $name,
					'category'    => 'database',
					'source'      => 'core',
					'enabled'     => 1,
					'parameters'  => '{}',
				),
				array( '%s', '%s', '%s', '%s', '%d', '%s' )
			);
		}
		\Agentic\Tools_Registry::bust_cache();
		\Agentic\Risk_Level::bust_cache();
	}

	/**
	 * Create a minimal agent with an abilities.json under AGENT_BUILDER_AGENTS_DIR
	 * (wp-content/agentic-agents/<slug>/), for tests exercising
	 * Abilities_Manifest resolution against a real manifest file.
	 *
	 * @param string $agent_id Agent slug.
	 * @param array  $abilities Optional abilities map (tool_name => {risk, reason, ...}).
	 * @return string Path to the created abilities.json.
	 */
	protected function create_test_agent_manifest( string $agent_id, array $abilities = array() ): string {
		$agents_dir = AGENT_BUILDER_AGENTS_DIR;
		if ( ! file_exists( $agents_dir ) ) {
			mkdir( $agents_dir, 0755, true );
		}

		$agent_dir = $agents_dir . '/' . $agent_id;
		if ( ! file_exists( $agent_dir ) ) {
			mkdir( $agent_dir, 0755, true );
		}

		if ( empty( $abilities ) ) {
			$abilities = array(
				'_test_placeholder' => array(
					'risk'   => 'none',
					'reason' => 'Test agent placeholder tool.',
				),
			);
		}

		$manifest_file = $agent_dir . '/abilities.json';
		$manifest      = wp_json_encode(
			array(
				'version'   => '1.0.0',
				'abilities' => $abilities,
			),
			JSON_PRETTY_PRINT
		);
		file_put_contents( $manifest_file, $manifest );

		\Agentic\Abilities_Manifest::clear_cache( $agent_id );

		return $manifest_file;
	}

	/**
	 * Delete a test agent directory created by create_test_agent_manifest().
	 *
	 * @param string $agent_id Agent slug.
	 */
	protected function delete_test_agent( string $agent_id ): void {
		$agent_dir = AGENT_BUILDER_AGENTS_DIR . '/' . $agent_id;
		if ( file_exists( $agent_dir ) ) {
			$this->delete_directory( $agent_dir );
		}
		\Agentic\Abilities_Manifest::clear_cache( $agent_id );
	}

	/**
	 * Recursively delete a directory.
	 *
	 * @param string $dir Directory path.
	 */
	protected function delete_directory( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$files = array_diff( scandir( $dir ), array( '.', '..' ) );
		foreach ( $files as $file ) {
			$path = $dir . '/' . $file;
			is_dir( $path ) ? $this->delete_directory( $path ) : unlink( $path );
		}
		rmdir( $dir );
	}
}
