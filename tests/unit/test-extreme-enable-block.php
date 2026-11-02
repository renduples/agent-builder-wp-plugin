<?php
/**
 * Unit tests for the inline "extreme-risk tools cannot be enabled" check in
 * the Tools screen toggle (Admin_Pages_REST toggle_tool).
 *
 * @package Agentic\Tests
 */

namespace Agentic\Tests;

use Agentic\Admin_Pages_REST;
use Agentic\Risk_Level;
use Agentic\Tools_Registry;

/**
 * Test case for the extreme-risk enable block.
 */
class Test_Extreme_Enable_Block extends TestCase {

	/**
	 * Run as an administrator.
	 */
	public function setUp(): void {
		parent::setUp();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Clear cached risk data so registry rows written by a test are seen.
	 */
	private function reset_caches(): void {
		$prop = new \ReflectionProperty( Risk_Level::class, 'registry' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );
		Tools_Registry::bust_cache();
	}

	/**
	 * Turn a tool on through the same REST action the Tools screen uses.
	 *
	 * @param string $name Tool slug.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function enable( string $name ) {
		$request = new \WP_REST_Request( 'POST', '/agentic/v1/admin-page' );
		$request->set_param( 'page', 'tools' );
		$request->set_param( 'action_name', 'toggle_tool' );
		$request->set_param( 'name', $name );
		$request->set_param( 'enabled', true );
		return Admin_Pages_REST::post_action( $request );
	}

	/**
	 * A tool whose built-in floor is extreme cannot be enabled.
	 */
	public function test_extreme_baseline_tool_is_blocked(): void {
		$response = $this->enable( 'run_wp_cli' );
		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'extreme_blocked', $response->get_error_code() );
	}

	/**
	 * High-risk tools are confirm-to-enable, not blocked.
	 */
	public function test_high_risk_tools_are_not_blocked(): void {
		foreach ( array( 'force_password_reset', 'fetch_url' ) as $tool ) {
			$response = $this->enable( $tool );
			$this->assertFalse(
				$response instanceof \WP_Error && 'extreme_blocked' === $response->get_error_code(),
				"$tool must not be extreme-blocked"
			);
		}
	}

	/**
	 * An extreme risk recorded in the tools registry (above the tool's own
	 * floor) also blocks enabling.
	 */
	public function test_registry_escalation_to_extreme_is_blocked(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'agent_builder_tools';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (name, description, category, source, enabled, risk_level, parameters, created_at, updated_at)
				 VALUES (%s, '', 'test', 'core', 0, %s, '{}', NOW(), NOW())
				 ON DUPLICATE KEY UPDATE risk_level = VALUES(risk_level), enabled = VALUES(enabled)",
				'add_custom_css',
				Risk_Level::EXTREME
			)
		);
		$this->reset_caches();

		$response = $this->enable( 'add_custom_css' );
		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'extreme_blocked', $response->get_error_code() );
	}
}
