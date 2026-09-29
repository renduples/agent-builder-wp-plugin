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
use Agentic\Agent_Base;
use Agentic\Agent_Run;
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
		delete_option( 'agent_builder_approval_auto_max_risk' );
		Agent_Run::reset_current_for_tests();
		$this->clear_backup_dir();
	}

	/**
	 * Clean up filesystem side effects (backups live outside the DB, so the
	 * WP test transaction rollback never touches them).
	 */
	public function tearDown(): void {
		delete_option( 'agent_builder_approval_auto_max_risk' );
		Agent_Run::reset_current_for_tests();
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
			array( 'name' => 'agent_builder_test_disabled_opt', 'value' => 'x' ),
			'test-agent',
			'autonomous',
			'chat'
		);

		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'disabled', $result['error'] );
		$this->assertFalse( get_option( 'agent_builder_test_disabled_opt' ) );
	}

	/**
	 * A tool whose effective risk is EXTREME (via admin override) is always
	 * blocked, even in autonomous mode — no queue, no confirm, just refusal.
	 */
	public function test_extreme_risk_is_always_blocked(): void {
		update_option(
			'agent_builder_risk_overrides',
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

		delete_option( 'agent_builder_risk_overrides' );
	}

	/**
	 * A HIGH-risk tool (db_update_option's BASELINE_RISKS floor) is queued
	 * for admin approval even in autonomous mode, and does not execute.
	 */
	public function test_high_risk_tool_is_queued_and_not_executed(): void {
		$queue  = new Approval_Queue();
		$result = $this->make_executor()->execute(
			'db_update_option',
			array( 'name' => 'agent_builder_test_queued_opt', 'value' => 'should-not-be-set' ),
			'test-agent',
			'autonomous',
			'chat'
		);

		$this->assertSame( 'queued_for_approval', $result['status'] );
		$this->assertArrayHasKey( 'approval_id', $result );
		$this->assertSame( 1, $queue->get_pending_count() );
		$this->assertFalse( get_option( 'agent_builder_test_queued_opt' ), 'queued tool must not have executed yet' );
	}

	/**
	 * Once an admin approves a queued call, the *next* identical call is
	 * consumed from the queue (find_approved()) and actually executes.
	 */
	public function test_approved_queue_item_is_consumed_on_next_call(): void {
		$queue     = new Approval_Queue();
		$executor  = $this->make_executor();
		$arguments = array( 'name' => 'agent_builder_test_approved_opt', 'value' => 'approved-value' );

		$first = $executor->execute( 'db_update_option', $arguments, 'test-agent', 'autonomous', 'chat' );
		$this->assertSame( 'queued_for_approval', $first['status'] );

		$queue->approve( (int) $first['approval_id'] );

		$second = $executor->execute( 'db_update_option', $arguments, 'test-agent', 'autonomous', 'chat' );

		$this->assertArrayNotHasKey( 'status', $second, 'approved call should fall through to real execution, not queue again' );
		$this->assertSame( 'approved-value', get_option( 'agent_builder_test_approved_opt' ) );

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
	 * The confirmation payload carries a reduced summary of the scalar tool
	 * arguments worth surfacing on the card. duplicate_post is MEDIUM risk, so
	 * in supervised mode it routes to the confirm branch; its new_title maps to
	 * "title", and a post_id-only call falls back to the raw id so the card is
	 * never entirely opaque.
	 */
	public function test_confirm_summary_payload(): void {
		$result = $this->make_executor()->execute(
			'duplicate_post',
			array( 'post_id' => 123, 'new_title' => 'Fresh copy' ),
			'test-agent',
			'supervised',
			'chat'
		);

		$this->assertSame( 'confirmation_required', $result['status'] );
		$this->assertArrayHasKey( 'summary', $result );
		$this->assertSame( 'Fresh copy', $result['summary']['title'] );
		$this->assertArrayNotHasKey( 'post_id', $result['summary'], 'a friendly field suppresses the post_id fallback' );

		$fallback = $this->make_executor()->execute(
			'duplicate_post',
			array( 'post_id' => 123 ),
			'test-agent',
			'supervised',
			'chat'
		);
		$this->assertSame( array( 'post_id' => '123' ), $fallback['summary'] );
	}

	/**
	 * new_type (switch_post_type) has no MEDIUM-risk tool, so it can't reach the
	 * confirm branch through execute(). Exercise the private mapping directly
	 * instead, covering the alternate keys the confirm summary resolves.
	 */
	public function test_summarize_arguments_maps_alternate_keys(): void {
		$method = new \ReflectionMethod( Tool_Executor::class, 'summarize_arguments' );

		// new_title (duplicate tools) surfaces under "title".
		$this->assertSame(
			array( 'title' => 'Fresh copy' ),
			$method->invoke( null, array( 'new_title' => 'Fresh copy' ) )
		);

		// new_type (switch_post_type) surfaces under "post_type".
		$this->assertSame(
			array( 'post_type' => 'page' ),
			$method->invoke( null, array( 'new_type' => 'page' ) )
		);

		// post_id is surfaced only as a last-resort fallback.
		$this->assertSame(
			array( 'post_id' => '123' ),
			$method->invoke( null, array( 'post_id' => 123 ) )
		);
	}

	/**
	 * When enforcement resolves to 'allow' (the site's auto-approve
	 * preference raises the ceiling to HIGH here), a non-readonly tool
	 * actually executes, AND Tool_Helpers::backup_tables_for_tool() must
	 * have run first — verified by a fresh backup file for the affected
	 * table appearing before the option's new value is confirmed written.
	 */
	public function test_allow_path_backs_up_table_before_executing_non_readonly_tool(): void {
		update_option( 'agent_builder_approval_auto_max_risk', Risk_Level::HIGH );

		$before = glob( AGENT_BUILDER_BACKUPS_DIR . '/db/*_options.json' ) ?: array();
		$this->assertCount( 0, $before, 'precondition: no stale options backup from a prior test' );

		$result = $this->make_executor()->execute(
			'db_update_option',
			array( 'name' => 'agent_builder_test_allow_opt', 'value' => 'written-value' ),
			'test-agent',
			'supervised',
			'chat'
		);

		$this->assertSame( 'written-value', get_option( 'agent_builder_test_allow_opt' ) );
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
	 * A filter on `agent_builder_tool_enforcement` can tighten enforcement —
	 * forcing `queue` on a call that would otherwise resolve to `allow`.
	 */
	public function test_gate_filter_can_tighten_a_normally_allowed_call(): void {
		update_option(
			'agent_builder_risk_overrides',
			array( 'test-agent:m10c_fake_low_tool' => Risk_Level::LOW )
		);

		$filter = static function () {
			return 'queue';
		};
		add_filter( 'agent_builder_tool_enforcement', $filter );

		$result = $this->make_executor()->execute(
			'm10c_fake_low_tool',
			array(),
			'test-agent',
			'autonomous', // Ceiling is LOW, so baseline enforcement here is 'allow'.
			'chat'
		);

		remove_filter( 'agent_builder_tool_enforcement', $filter );
		delete_option( 'agent_builder_risk_overrides' );

		$this->assertSame( 'queued_for_approval', $result['status'] );
	}

	/**
	 * A filter on `agent_builder_tool_enforcement` cannot resurrect an
	 * EXTREME-risk call that baseline enforcement already blocked — the
	 * seam is skipped entirely once baseline is 'block'.
	 */
	public function test_gate_filter_cannot_unblock_extreme_risk(): void {
		update_option(
			'agent_builder_risk_overrides',
			array( 'test-agent:add_custom_css' => Risk_Level::EXTREME )
		);

		$filter = static function () {
			return 'allow';
		};
		add_filter( 'agent_builder_tool_enforcement', $filter );

		$result = $this->make_executor()->execute(
			'add_custom_css',
			array( 'css' => 'body{color:red}' ),
			'test-agent',
			'autonomous',
			'chat'
		);

		remove_filter( 'agent_builder_tool_enforcement', $filter );
		delete_option( 'agent_builder_risk_overrides' );

		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'extreme risk', $result['error'] );
	}

	/**
	 * Risk_Level::clamp_enforcement() as a pure function: EXTREME risk
	 * always resolves to 'block', regardless of what a filter returned.
	 */
	public function test_clamp_enforcement_never_unblocks_extreme(): void {
		$ctx = array( 'risk' => Risk_Level::EXTREME, 'baseline' => 'block' );

		$this->assertSame( 'block', Risk_Level::clamp_enforcement( 'allow', $ctx ) );
		$this->assertSame( 'block', Risk_Level::clamp_enforcement( 'confirm', $ctx ) );
		$this->assertSame( 'block', Risk_Level::clamp_enforcement( 'queue', $ctx ) );
	}

	/**
	 * Risk_Level::clamp_enforcement(): a HIGH-risk decision cannot be
	 * loosened to 'allow' unless $ctx carries the documented grant flag.
	 */
	public function test_clamp_enforcement_high_risk_needs_grant_flag_to_loosen(): void {
		$ctx_no_grant = array( 'risk' => Risk_Level::HIGH, 'baseline' => 'queue' );
		$this->assertSame(
			'queue',
			Risk_Level::clamp_enforcement( 'allow', $ctx_no_grant ),
			'without the grant flag, a filter-loosened HIGH decision must be reclamped to baseline'
		);

		$ctx_with_grant = array( 'risk' => Risk_Level::HIGH, 'baseline' => 'queue', 'granted' => true );
		$this->assertSame(
			'allow',
			Risk_Level::clamp_enforcement( 'allow', $ctx_with_grant ),
			'with the grant flag present, loosening to allow is permitted'
		);

		// A baseline that was already 'allow' (e.g. the site's auto-approve
		// preference covers HIGH) is not a filter loosening anything, so it
		// is left alone even with no grant flag.
		$ctx_already_allowed = array( 'risk' => Risk_Level::HIGH, 'baseline' => 'allow' );
		$this->assertSame( 'allow', Risk_Level::clamp_enforcement( 'allow', $ctx_already_allowed ) );
	}

	/**
	 * A HIGH-risk call cannot be loosened to 'allow' by a real filter on the
	 * `agent_builder_tool_enforcement` hook unless it supplies the grant
	 * flag — and nothing in this codebase sets that flag yet (M12's
	 * Tool_Grants is the intended future setter), so today's filters can
	 * never actually execute a HIGH-risk call this way.
	 */
	public function test_high_risk_filter_cannot_loosen_to_allow_without_grant_flag(): void {
		$filter = static function () {
			return 'allow';
		};
		add_filter( 'agent_builder_tool_enforcement', $filter );

		$result = $this->make_executor()->execute(
			'db_update_option',
			array( 'name' => 'agent_builder_test_high_grant_opt', 'value' => 'should-not-be-set' ),
			'test-agent',
			'autonomous',
			'chat'
		);

		remove_filter( 'agent_builder_tool_enforcement', $filter );

		$this->assertSame( 'queued_for_approval', $result['status'] );
		$this->assertFalse( get_option( 'agent_builder_test_high_grant_opt' ) );
	}

	/**
	 * A HIGH-risk tool's baseline is 'queue'. A filter that loosens it to
	 * 'confirm' (not literally 'allow') must still be reclamped back to
	 * 'queue' without the grant flag — the gate-bypass bug this fix targets.
	 * Before the fix, 'confirm' passed clamp_enforcement() unchanged and the
	 * call was routed through Agent_Proposals (chat confirmation) instead of
	 * the admin Approval_Queue, with no grant check at all.
	 */
	public function test_high_risk_filter_loosen_to_confirm_is_reclamped_to_queue(): void {
		$filter = static function () {
			return 'confirm';
		};
		add_filter( 'agent_builder_tool_enforcement', $filter );

		$result = $this->make_executor()->execute(
			'db_update_option',
			array( 'name' => 'agent_builder_test_confirm_bypass_opt', 'value' => 'should-not-be-set' ),
			'test-agent',
			'autonomous',
			'chat'
		);

		remove_filter( 'agent_builder_tool_enforcement', $filter );

		$this->assertSame( 'queued_for_approval', $result['status'], 'must be reclamped to the queue path, not left as confirm' );
		$this->assertArrayNotHasKey( 'proposal_id', $result, 'the chat confirmation (proposal) path must never be reached' );
		$this->assertFalse( get_option( 'agent_builder_test_confirm_bypass_opt' ) );
	}

	/**
	 * A filter returning an unrecognized enforcement value (typo, stray
	 * string, anything not one of allow/confirm/queue/block) must never fall
	 * through to the 'allow' execution path — it is treated as the pre-filter
	 * baseline instead.
	 */
	public function test_unrecognized_enforcement_value_falls_back_to_baseline_not_allow(): void {
		$filter = static function () {
			return 'totally-bogus-value';
		};
		add_filter( 'agent_builder_tool_enforcement', $filter );

		$result = $this->make_executor()->execute(
			'add_custom_css',
			array( 'css' => 'body{color:blue}' ),
			'test-agent',
			'supervised',
			'chat'
		);

		remove_filter( 'agent_builder_tool_enforcement', $filter );

		$this->assertSame(
			'confirmation_required',
			$result['status'] ?? null,
			'an unrecognized enforcement value must fall back to baseline (confirm here), not silently execute'
		);
		$this->assertArrayNotHasKey( 'success', $result );
	}

	/**
	 * A filter returning a non-string value (null, an array, an int) must be
	 * validated and rejected *before* it reaches the strictly-typed
	 * Risk_Level::clamp_enforcement(), which would otherwise throw a
	 * TypeError instead of failing closed to the pre-filter baseline.
	 *
	 * @dataProvider provide_non_string_enforcement_values
	 */
	public function test_non_string_enforcement_value_fails_closed_to_baseline_not_typeerror( $bogus_value ): void {
		$filter = static function () use ( $bogus_value ) {
			return $bogus_value;
		};
		add_filter( 'agent_builder_tool_enforcement', $filter );

		$result = $this->make_executor()->execute(
			'add_custom_css',
			array( 'css' => 'body{color:blue}' ),
			'test-agent',
			'supervised',
			'chat'
		);

		remove_filter( 'agent_builder_tool_enforcement', $filter );

		$this->assertSame(
			'confirmation_required',
			$result['status'] ?? null,
			'a non-string enforcement value must fail closed to baseline (confirm here), not throw or silently execute'
		);
		$this->assertArrayNotHasKey( 'success', $result );
	}

	public function provide_non_string_enforcement_values(): array {
		return array(
			'null'  => array( null ),
			'array' => array( array( 'allow' ) ),
			'int'   => array( 1 ),
		);
	}

	/**
	 * A filter-driven block on a tool that is not itself extreme risk gets a
	 * generic policy-denial message, not the "classified as extreme risk"
	 * wording — that phrasing is reserved for the baseline-extreme-risk
	 * branch above it.
	 */
	public function test_filter_driven_block_on_non_extreme_tool_gets_generic_message(): void {
		$filter = static function () {
			return 'block';
		};
		add_filter( 'agent_builder_tool_enforcement', $filter );

		$result = $this->make_executor()->execute(
			'add_custom_css',
			array( 'css' => 'body{color:green}' ),
			'test-agent',
			'supervised',
			'chat'
		);

		remove_filter( 'agent_builder_tool_enforcement', $filter );

		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringNotContainsString( 'extreme risk', $result['error'] );
		$this->assertStringContainsString( 'policy', $result['error'] );
	}

	/**
	 * $ctx['run_id'] and $ctx['run_kind'] are populated from the passed
	 * Agent_Run, and $ctx['user_id'] resolves to the run's owner.
	 */
	public function test_ctx_run_fields_populated_when_run_is_passed(): void {
		$run = Agent_Run::begin( 'test-agent', array( 'kind' => 'routine', 'user_id' => 42 ) );

		$captured = null;
		$capture  = static function ( $enforcement, $ctx ) use ( &$captured ) {
			$captured = $ctx;
		};
		add_action( 'agent_builder_tool_gate_decision', $capture, 10, 2 );

		$this->make_executor()->execute(
			'list_posts',
			array(),
			'test-agent',
			'supervised',
			'chat',
			null,
			'',
			$run
		);

		remove_action( 'agent_builder_tool_gate_decision', $capture, 10 );

		$this->assertIsArray( $captured );
		$this->assertSame( $run->get_run_id(), $captured['run_id'] );
		$this->assertSame( 'routine', $captured['run_kind'] );
		$this->assertSame( 42, $captured['user_id'] );
	}

	/**
	 * With no Agent_Run (an interactive chat call), $ctx['run_id'] and
	 * $ctx['run_kind'] are empty rather than null or missing.
	 */
	public function test_ctx_run_fields_empty_without_a_run(): void {
		$captured = null;
		$capture  = static function ( $enforcement, $ctx ) use ( &$captured ) {
			$captured = $ctx;
		};
		add_action( 'agent_builder_tool_gate_decision', $capture, 10, 2 );

		$this->make_executor()->execute(
			'list_posts',
			array(),
			'test-agent',
			'supervised',
			'chat'
		);

		remove_action( 'agent_builder_tool_gate_decision', $capture, 10 );

		$this->assertIsArray( $captured );
		$this->assertSame( '', $captured['run_id'] );
		$this->assertSame( '', $captured['run_kind'] );
	}

	/**
	 * `agent_builder_tool_executed` fires exactly once for a call that
	 * actually executes — not zero, not twice.
	 */
	public function test_tool_executed_fires_exactly_once_on_execution(): void {
		$calls    = 0;
		$listener = static function () use ( &$calls ) {
			++$calls;
		};
		add_action( 'agent_builder_tool_executed', $listener, 10, 4 );

		$this->make_executor()->execute(
			'list_posts',
			array(),
			'test-agent',
			'supervised',
			'chat'
		);

		remove_action( 'agent_builder_tool_executed', $listener, 10 );

		$this->assertSame( 1, $calls );
	}

	/**
	 * `agent_builder_tool_executed` never fires for a call that was blocked
	 * or queued — only an actual execution should raise it.
	 */
	public function test_tool_executed_does_not_fire_when_blocked_or_queued(): void {
		$calls    = 0;
		$listener = static function () use ( &$calls ) {
			++$calls;
		};
		add_action( 'agent_builder_tool_executed', $listener, 10, 4 );

		update_option(
			'agent_builder_risk_overrides',
			array( 'test-agent:add_custom_css' => Risk_Level::EXTREME )
		);
		$this->make_executor()->execute(
			'add_custom_css',
			array( 'css' => 'body{color:red}' ),
			'test-agent',
			'autonomous',
			'chat'
		);
		delete_option( 'agent_builder_risk_overrides' );

		$this->make_executor()->execute(
			'db_update_option',
			array( 'name' => 'agent_builder_test_no_fire_opt', 'value' => 'x' ),
			'test-agent',
			'autonomous',
			'chat'
		);

		remove_action( 'agent_builder_tool_executed', $listener, 10 );

		$this->assertSame( 0, $calls );
	}

	/**
	 * `agent_builder_tool_executed` must not fire for a call that resolves to
	 * the synthesized "Unknown tool" error — none of Tool_Loader, the
	 * agent-inline fallback, or the abilities bridge actually produced a
	 * result, so nothing "executed."
	 */
	public function test_tool_executed_does_not_fire_for_unresolved_unknown_tool(): void {
		$calls    = 0;
		$listener = static function () use ( &$calls ) {
			++$calls;
		};
		add_action( 'agent_builder_tool_executed', $listener, 10, 4 );

		$result = $this->make_executor()->execute(
			'no_such_tool_does_not_exist',
			array(),
			'test-agent',
			'autonomous',
			'chat'
		);

		remove_action( 'agent_builder_tool_executed', $listener, 10 );

		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'Unknown tool', $result['error'] );
		$this->assertSame( 0, $calls );
	}

	/**
	 * execute_approved() runs a readonly tool through the tool_loader path and
	 * — because the tool is readonly — writes nothing to the operations ledger.
	 */
	public function test_execute_approved_runs_readonly_tool_without_ledger_write(): void {
		$result = $this->make_executor()->execute_approved(
			array(
				'tool'     => 'list_posts',
				'params'   => array(),
				'agent_id' => 'test-agent',
			)
		);

		$this->assertIsArray( $result );

		$queue   = new Approval_Queue();
		$recent  = $queue->get_recent( array( 'agent_id' => 'test-agent' ) );
		$actions = array_column( $recent, 'action' );
		$this->assertNotContains( 'list_posts', $actions );
	}

	/**
	 * execute_approved() actually runs a non-readonly tool (bypassing the gate,
	 * as the action is already approved) and logs it to the operations ledger
	 * with the provided risk level.
	 */
	public function test_execute_approved_logs_executed_for_non_readonly_tool(): void {
		$result = $this->make_executor()->execute_approved(
			array(
				'tool'       => 'db_update_option',
				'params'     => array( 'name' => 'agent_builder_test_approved_opt', 'value' => 'approved-value' ),
				'agent_id'   => 'test-agent',
				'risk_level' => Risk_Level::HIGH,
				'mode'       => 'supervised',
				'invocation' => 'chat',
			)
		);

		$this->assertSame( 'approved-value', get_option( 'agent_builder_test_approved_opt' ) );
		$this->assertTrue( $result['updated'] ?? false );

		$queue  = new Approval_Queue();
		$recent = $queue->get_recent( array( 'agent_id' => 'test-agent' ) );
		$actions = array_column( $recent, 'action' );
		$this->assertContains( 'db_update_option', $actions );

		$row = $recent[ array_search( 'db_update_option', $actions, true ) ];
		$this->assertSame( Risk_Level::HIGH, $row['risk_level'] );
	}

	/**
	 * execute_approved() fires agent_builder_tool_executed exactly once when a
	 * tool actually resolves (the same hook execute()'s allow-path raises).
	 */
	public function test_execute_approved_fires_tool_executed_hook(): void {
		$calls    = 0;
		$listener = static function () use ( &$calls ) {
			++$calls;
		};
		add_action( 'agent_builder_tool_executed', $listener, 10, 4 );

		$this->make_executor()->execute_approved(
			array(
				'tool'     => 'list_posts',
				'params'   => array(),
				'agent_id' => 'test-agent',
			)
		);

		remove_action( 'agent_builder_tool_executed', $listener, 10 );

		$this->assertSame( 1, $calls );
	}

	/**
	 * execute_approved() returns the synthesized "Unknown tool" error and does
	 * not fire agent_builder_tool_executed when no dispatcher resolves the call.
	 */
	public function test_execute_approved_unknown_tool_returns_error_without_hook(): void {
		$calls    = 0;
		$listener = static function () use ( &$calls ) {
			++$calls;
		};
		add_action( 'agent_builder_tool_executed', $listener, 10, 4 );

		$result = $this->make_executor()->execute_approved(
			array(
				'tool'     => 'no_such_tool_does_not_exist',
				'params'   => array(),
				'agent_id' => 'test-agent',
			)
		);

		remove_action( 'agent_builder_tool_executed', $listener, 10 );

		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'Unknown tool', $result['error'] );
		$this->assertSame( 0, $calls );
	}

	/**
	 * execute_approved() tolerates the classic approval-queue record shape:
	 * `action` for the tool name and a JSON-encoded `params` string.
	 */
	public function test_execute_approved_accepts_action_and_json_string_params(): void {
		$result = $this->make_executor()->execute_approved(
			array(
				'action'     => 'db_update_option',
				'params'     => wp_json_encode( array( 'name' => 'agent_builder_test_json_opt', 'value' => 'json-value' ) ),
				'agent_id'   => 'test-agent',
				'risk_level' => Risk_Level::HIGH,
			)
		);

		$this->assertSame( 'json-value', get_option( 'agent_builder_test_json_opt' ) );
		$this->assertTrue( $result['updated'] ?? false );
	}

	/**
	 * execute_approved() falls back to the agent-inline execute_tool() when
	 * Tool_Loader has no such tool — the same fallback chain as execute().
	 */
	public function test_execute_approved_uses_agent_inline_fallback(): void {
		$agent = new class() extends Agent_Base {
			public function get_id(): string {
				return 'inline-test-agent';
			}

			public function execute_tool( string $_tool_name, array $_arguments ): ?array {
				return array( 'from' => 'inline-fallback' );
			}
		};

		$result = $this->make_executor()->execute_approved(
			array(
				'tool'     => 'inline_only_tool',
				'params'   => array(),
				'agent_id' => 'inline-test-agent',
			),
			$agent
		);

		$this->assertSame( array( 'from' => 'inline-fallback' ), $result );
	}

	/**
	 * execute_approved() refuses an EXTREME-risk tool outright — an approval is
	 * not a way to authorise something outside every risk tier's ceiling.
	 */
	public function test_execute_approved_refuses_extreme_risk(): void {
		$result = $this->make_executor()->execute_approved(
			array(
				'tool'       => 'db_update_option',
				'params'     => array( 'name' => 'agent_builder_test_extreme_opt', 'value' => 'x' ),
				'agent_id'   => 'test-agent',
				'risk_level' => Risk_Level::EXTREME,
			)
		);

		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'extreme risk', $result['error'] );
		$this->assertFalse( get_option( 'agent_builder_test_extreme_opt' ), 'an extreme-risk tool must never run' );
	}

	/**
	 * execute_approved() re-runs validate_args against the tool schema, so an
	 * approval cannot smuggle invalid arguments past the gate.
	 */
	public function test_execute_approved_reruns_validate_args_against_schema(): void {
		$result = $this->make_executor()->execute_approved(
			array(
				'tool'       => 'db_update_option',
				'params'     => array(),
				'agent_id'   => 'test-agent',
				'risk_level' => Risk_Level::HIGH,
			)
		);

		$this->assertSame( false, $result['success'] ?? null );
		$this->assertSame( 'invalid_args', $result['error_code'] ?? null );
	}

	/**
	 * execute_approved() takes the same pre-write table backup as the allow-path
	 * before a non-readonly tool runs.
	 */
	public function test_execute_approved_backs_up_table_before_non_readonly_execution(): void {
		$before = glob( AGENT_BUILDER_BACKUPS_DIR . '/db/*_options.json' ) ?: array();
		$this->assertCount( 0, $before, 'precondition: no stale options backup from a prior test' );

		$result = $this->make_executor()->execute_approved(
			array(
				'tool'       => 'db_update_option',
				'params'     => array( 'name' => 'agent_builder_test_approved_backup_opt', 'value' => 'backed-up' ),
				'agent_id'   => 'test-agent',
				'risk_level' => Risk_Level::HIGH,
			)
		);

		$this->assertSame( 'backed-up', get_option( 'agent_builder_test_approved_backup_opt' ) );

		$after = glob( AGENT_BUILDER_BACKUPS_DIR . '/db/*_options.json' ) ?: array();
		$this->assertCount( 1, $after, 'execute_approved() must take a pre-write backup' );
	}

	/**
	 * A Throwable thrown during execute_approved() is caught and returned as an
	 * error (and audited) rather than bubbling up past the caller.
	 */
	public function test_execute_approved_catches_thrown_error(): void {
		$agent = new class() extends Agent_Base {
			public function get_id(): string {
				return 'throwing-agent';
			}

			public function execute_tool( string $_tool_name, array $_arguments ): ?array {
				throw new \RuntimeException( 'boom' );
			}
		};

		$result = $this->make_executor()->execute_approved(
			array(
				'tool'     => 'inline_only_tool',
				'params'   => array(),
				'agent_id' => 'throwing-agent',
			),
			$agent
		);

		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'boom', $result['error'] );

		global $wpdb;
		$failures = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}agent_builder_audit_log WHERE action = %s", 'tool_execution_failed' )
		);
		$this->assertSame( 1, $failures, 'a thrown execution must be audited as tool_execution_failed' );
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
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}agent_builder_approval_queue WHERE id = %d", $id ),
			ARRAY_A
		);
	}
}
