<?php
/**
 * Unit Tests for the Site Health background-tasks test (M11-4).
 *
 * Covers the three failure conditions (stale cron tick, stuck run, stale
 * approval) plus the all-clear state, and asserts the result matches
 * WordPress's expected Site Health direct-test shape.
 *
 * @package Agentic\Tests
 */

namespace Agentic\Tests;

use Agentic\Site_Health;

/**
 * Test case for Site_Health::test_background_runs().
 */
class Test_Site_Health_Test extends TestCase {

	/**
	 * Remove the cron last-tick option before each test so a previous test's
	 * tick can't leak into the next (options are not covered by the base
	 * cleanup_test_data() option filter).
	 */
	public function setUp(): void {
		parent::setUp();
		delete_option( 'agent_builder_cron_last_tick' );
	}

	/**
	 * Remove the cron last-tick option again on teardown.
	 */
	public function tearDown(): void {
		delete_option( 'agent_builder_cron_last_tick' );
		parent::tearDown();
	}

	/**
	 * The result always exposes WordPress's expected Site Health direct-test shape.
	 */
	public function test_result_matches_site_health_shape(): void {
		update_option( 'agent_builder_cron_last_tick', time() );

		$result = Site_Health::test_background_runs();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'label', $result );
		$this->assertArrayHasKey( 'status', $result );
		$this->assertArrayHasKey( 'badge', $result );
		$this->assertArrayHasKey( 'description', $result );
		$this->assertArrayHasKey( 'actions', $result );
		$this->assertArrayHasKey( 'test', $result );

		$this->assertContains( $result['status'], array( 'good', 'recommended', 'critical' ), true );
		$this->assertArrayHasKey( 'label', $result['badge'] );
		$this->assertArrayHasKey( 'color', $result['badge'] );
		$this->assertSame( 'agent_builder_background_runs', $result['test'] );
		$this->assertSame( 'Agent Builder: background tasks', $result['label'] );
	}

	/**
	 * A fresh cron tick with no stuck runs and no stale approvals is all-clear.
	 */
	public function test_all_clear_returns_good(): void {
		update_option( 'agent_builder_cron_last_tick', time() );

		$result = Site_Health::test_background_runs();

		$this->assertSame( 'good', $result['status'] );
		$this->assertSame( 'green', $result['badge']['color'] );
		$this->assertSame( '', $result['actions'] );
	}

	/**
	 * A missing cron tick (never recorded) is flagged as a recommendation.
	 */
	public function test_missing_cron_tick_returns_recommended(): void {
		$result = Site_Health::test_background_runs();

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertSame( 'orange', $result['badge']['color'] );
		$this->assertStringContainsString( 'overdue', $result['description'] );
		$this->assertStringContainsString( 'DISABLE_WP_CRON', $result['actions'] );
	}

	/**
	 * A cron tick older than 10 minutes is flagged with the actionable cron copy.
	 */
	public function test_stale_cron_tick_returns_recommended(): void {
		update_option( 'agent_builder_cron_last_tick', time() - 3600 );

		$result = Site_Health::test_background_runs();

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( 'overdue', $result['description'] );
		$this->assertStringContainsString( 'DISABLE_WP_CRON', $result['actions'] );
		$this->assertStringContainsString( 'system cron', $result['actions'] );
		$this->assertStringContainsString( 'developer.wordpress.org', $result['actions'] );
	}

	/**
	 * A run still in a running state for over 45 minutes is flagged.
	 */
	public function test_stuck_run_returns_recommended(): void {
		update_option( 'agent_builder_cron_last_tick', time() );

		$this->insert_run( 'test-stuck-run', gmdate( 'Y-m-d H:i:s', time() - 3600 ) );

		$result = Site_Health::test_background_runs();

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( 'stuck', $result['description'] );
		$this->assertStringContainsString( 'agentic-tasks', $result['actions'] );
	}

	/**
	 * A pending approval waiting over 24 hours is flagged.
	 */
	public function test_stale_approval_returns_recommended(): void {
		update_option( 'agent_builder_cron_last_tick', time() );

		$this->insert_approval( gmdate( 'Y-m-d H:i:s', time() - ( 25 * 3600 ) ) );

		$result = Site_Health::test_background_runs();

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( 'waiting', $result['description'] );
		$this->assertStringContainsString( 'agentic-approvals', $result['actions'] );
	}

	/**
	 * A fresh (non-stale) running run and approval do not trip the test.
	 */
	public function test_fresh_run_and_approval_do_not_flag(): void {
		update_option( 'agent_builder_cron_last_tick', time() );

		$this->insert_run( 'test-fresh-run', gmdate( 'Y-m-d H:i:s', time() ) );
		$this->insert_approval( gmdate( 'Y-m-d H:i:s', time() ) );

		$result = Site_Health::test_background_runs();

		$this->assertSame( 'good', $result['status'] );
	}

	/**
	 * Insert a row into the runs table.
	 *
	 * @param string $run_id     Run id.
	 * @param string $updated_at GMT datetime for both started_at and updated_at.
	 */
	private function insert_run( string $run_id, string $updated_at ): void {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'agent_builder_runs',
			array(
				'run_id'     => $run_id,
				'root_agent' => 'test-agent',
				'kind'       => 'task',
				'status'     => 'running',
				'started_at' => $updated_at,
				'updated_at' => $updated_at,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Insert a pending row into the approval queue table.
	 *
	 * @param string $created_at GMT datetime for created_at.
	 */
	private function insert_approval( string $created_at ): void {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'agent_builder_approval_queue',
			array(
				'agent_id'   => 'test-agent',
				'action'     => 'test_action',
				'params'     => wp_json_encode( array( 'x' => 1 ) ),
				'risk_level' => 'high',
				'status'     => 'pending',
				'created_at' => $created_at,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}
}
