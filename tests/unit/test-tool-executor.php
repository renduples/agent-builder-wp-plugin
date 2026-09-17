<?php
/**
 * Unit Tests for Tool_Executor.
 *
 * Covers the risk-gate enforcement flow (allow / confirm / queue / block),
 * approval-queue consumption, and that Tool_Helpers::backup_tables_for_tool()
 * actually fires before a non-readonly tool executes.
 *
 * @package Agentic\Tests
 */

namespace Agentic\Tests;

use Agentic\Abilities_Manifest;
use Agentic\Approval_Queue;
use Agentic\Audit_Log;
use Agentic\Risk_Level;
use Agentic\Tool_Executor;
use Agentic\Tool_Loader;
use Agentic\Tools_Registry;

/**
 * Test case for Tool_Executor.
 */
class Test_Tool_Executor extends TestCase {

	/**
	 * Build a real Tool_Executor wired to the real Tool_Loader and Audit_Log.
	 *
	 * @return Tool_Executor
	 */
	private function make_executor(): Tool_Executor {
		return new Tool_Executor( Tool_Loader::get_instance(), new Audit_Log(), null );
	}

	/**
	 * Reset shared state between tests.
	 */
	public function setUp(): void {
		parent::setUp();
		Risk_Level::bust_cache();
		delete_option( 'agentic_approval_auto_max_risk' );
		$this->clear_backup_dir();
	}

	/**
	 * Clean up filesystem side effects (backups live outside the DB, so the
	 * WP test transaction rollback never touches them).
	 */
	public function tearDown(): void {
		delete_option( 'agentic_approval_auto_max_risk' );
		$this->clear_backup_dir();
		parent::tearDown();
	}

	/**
	 * Remove any options-table backup so the 60s throttle in
	 * Tool_Helpers::backup_table() never masks a real backup from firing in
	 * a later test in this run.
	 */
	private function clear_backup_dir(): void {
		$dir = AGENT_BUILDER_BACKUPS_DIR . '/db';
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( glob( $dir . '/*_options.json' ) ?: array() as $file ) {
			unlink( $file );
		}
	}

	/**
	 * A tool disabled by the administrator is blocked before risk gating
	 * even runs, regardless of its risk level.
	 */
	public function test_disabled_tool_is_blocked(): void {
		Tools_Registry::set_enabled( 'db_update_option', false );

		$result = $this->make_executor()->execute(
			'db_update_option',
			array( 'name' => 'agentic_test_disabled_opt', 'value' => 'x' ),
			'test-agent',
			'autonomous',
			'chat'
		);

		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'disabled', $result['error'] );
		$this->assertFalse( get_option( 'agentic_test_disabled_opt' ) );
	}

	/**
	 * A tool whose effective risk is EXTREME (via admin override) is always
	 * blocked, even in autonomous mode — no queue, no confirm, just refusal.
	 */
	public function test_extreme_risk_is_always_blocked(): void {
		update_option(
			'agentic_risk_overrides',
			array( 'test-agent:add_custom_css' => Risk_Level::EXTREME )
		);

		$result = $this->make_executor()->execute(
			'add_custom_css',
			array( 'css' => 'body{color:red}' ),
			'test-agent',
			'autonomous',
			'chat'
		);

		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'extreme risk', $result['error'] );

		delete_option( 'agentic_risk_overrides' );
	}

	/**
	 * A HIGH-risk tool (db_update_option's BASELINE_RISKS floor) is queued
	 * for admin approval even in autonomous mode, and does not execute.
	 */
	public function test_high_risk_tool_is_queued_and_not_executed(): void {
		$queue  = new Approval_Queue();
		$result = $this->make_executor()->execute(
			'db_update_option',
			array( 'name' => 'agentic_test_queued_opt', 'value' => 'should-not-be-set' ),
			'test-agent',
			'autonomous',
			'chat'
		);

		$this->assertSame( 'queued_for_approval', $result['status'] );
		$this->assertArrayHasKey( 'approval_id', $result );
		$this->assertSame( 1, $queue->get_pending_count() );
		$this->assertFalse( get_option( 'agentic_test_queued_opt' ), 'queued tool must not have executed yet' );
	}

	/**
	 * Once an admin approves a queued call, the *next* identical call is
	 * consumed from the queue (find_approved()) and actually executes.
	 */
	public function test_approved_queue_item_is_consumed_on_next_call(): void {
		$queue     = new Approval_Queue();
		$executor  = $this->make_executor();
		$arguments = array( 'name' => 'agentic_test_approved_opt', 'value' => 'approved-value' );

		$first = $executor->execute( 'db_update_option', $arguments, 'test-agent', 'autonomous', 'chat' );
		$this->assertSame( 'queued_for_approval', $first['status'] );

		$queue->approve( (int) $first['approval_id'] );

		$second = $executor->execute( 'db_update_option', $arguments, 'test-agent', 'autonomous', 'chat' );

		$this->assertArrayNotHasKey( 'status', $second, 'approved call should fall through to real execution, not queue again' );
		$this->assertSame( 'approved-value', get_option( 'agentic_test_approved_opt' ) );

		$row = $this->get_queue_row( (int) $first['approval_id'] );
		$this->assertSame( 'executed', $row['status'] );
	}

	/**
	 * A MEDIUM-risk tool asks for in-chat confirmation (Agent_Proposals)
	 * rather than running immediately or being queued for admin approval.
	 */
	public function test_medium_risk_tool_requires_confirmation(): void {
		$result = $this->make_executor()->execute(
			'add_custom_css',
			array( 'css' => 'body{color:red}' ),
			'test-agent',
			'supervised',
			'chat'
		);

		$this->assertSame( 'confirmation_required', $result['status'] );
		$this->assertArrayHasKey( 'proposal_id', $result );
	}

	/**
	 * When enforcement resolves to 'allow' (the site's auto-approve
	 * preference raises the ceiling to HIGH here), a non-readonly tool
	 * actually executes, AND Tool_Helpers::backup_tables_for_tool() must
	 * have run first — verified by a fresh backup file for the affected
	 * table appearing before the option's new value is confirmed written.
	 */
	public function test_allow_path_backs_up_table_before_executing_non_readonly_tool(): void {
		update_option( 'agentic_approval_auto_max_risk', Risk_Level::HIGH );

		$before = glob( AGENT_BUILDER_BACKUPS_DIR . '/db/*_options.json' ) ?: array();
		$this->assertCount( 0, $before, 'precondition: no stale options backup from a prior test' );

		$result = $this->make_executor()->execute(
			'db_update_option',
			array( 'name' => 'agentic_test_allow_opt', 'value' => 'written-value' ),
			'test-agent',
			'supervised',
			'chat'
		);

		$this->assertSame( 'written-value', get_option( 'agentic_test_allow_opt' ) );
		$this->assertTrue( $result['updated'] ?? false );

		$after = glob( AGENT_BUILDER_BACKUPS_DIR . '/db/*_options.json' ) ?: array();
		$this->assertCount( 1, $after, 'backup_tables_for_tool() should have written one options backup' );

		// The write must have been logged to the operations ledger too
		// (log_executed()), since it's a non-readonly tool that ran outside
		// the approval queue.
		$queue   = new Approval_Queue();
		$recent  = $queue->get_recent( array( 'agent_id' => 'test-agent' ) );
		$actions = array_column( $recent, 'action' );
		$this->assertContains( 'db_update_option', $actions );
	}

	/**
	 * A read-only tool never triggers a table backup, even under 'allow'.
	 */
	public function test_readonly_tool_never_triggers_a_backup(): void {
		$before = glob( AGENT_BUILDER_BACKUPS_DIR . '/db/*_options.json' ) ?: array();

		$this->make_executor()->execute(
			'list_posts',
			array(),
			'test-agent',
			'supervised',
			'chat'
		);

		$after = glob( AGENT_BUILDER_BACKUPS_DIR . '/db/*_options.json' ) ?: array();
		$this->assertSame( count( $before ), count( $after ) );
	}

	/**
	 * Fetch a raw approval_queue row by id.
	 *
	 * @param int $id Queue row id.
	 * @return array
	 */
	private function get_queue_row( int $id ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}agentic_approval_queue WHERE id = %d", $id ),
			ARRAY_A
		);
	}
}
