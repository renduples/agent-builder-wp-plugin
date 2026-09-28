<?php
/**
 * Unit Tests for Run_Resumer (M10e).
 *
 * Covers the `agent_builder_approval_resolved` action fired from both
 * REST_API::handle_approval() and Agent_Proposals::approve()/reject() (only
 * when the row carries a run_id), and Run_Resumer's response to it: approve
 * dispatches a resume job with the correct payload shape, reject stops the
 * run, non-waiting/unknown/missing-run_id rows are a clean no-op, and the
 * `agent_builder_run_started|waiting|finished` observability hooks fire
 * exactly once at the right moments.
 *
 * @package Agentic\Tests
 */

namespace Agentic\Tests;

use Agentic\Agent_Proposals;
use Agentic\Agent_Run;
use Agentic\Agent_Task_Job_Processor;
use Agentic\Approval_Queue;
use Agentic\Emergency_Stop;
use Agentic\Job_Manager;
use Agentic\REST_API;

/**
 * Test case for Run_Resumer.
 */
class Test_Run_Resumer extends TestCase {

	/**
	 * Reset the in-process run and clear the jobs table before each test.
	 */
	public function setUp(): void {
		parent::setUp();
		Agent_Run::reset_current_for_tests();
		$this->clear_jobs_table();
	}

	/**
	 * Teardown: reset the in-process run so it never leaks into the next test.
	 */
	public function tearDown(): void {
		Agent_Run::reset_current_for_tests();
		update_option( Emergency_Stop::OPTION_ENABLED, '0' );
		parent::tearDown();
	}

	/**
	 * Delete every row from the jobs table.
	 *
	 * @return void
	 */
	private function clear_jobs_table(): void {
		global $wpdb;
		$jobs_table = $wpdb->prefix . 'agent_builder_jobs';
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$jobs_table}'" ) === $jobs_table ) {
			$wpdb->query( "DELETE FROM {$jobs_table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test cleanup.
		}
	}

	/**
	 * The single pending job row, or null if there isn't exactly one.
	 *
	 * @return object|null
	 */
	private function the_pending_job(): ?object {
		$pending = Job_Manager::list_by_statuses( array( Job_Manager::STATUS_PENDING ) );
		if ( 1 !== count( $pending ) ) {
			return null;
		}
		return Job_Manager::get_job( (string) $pending[0]['id'] );
	}

	/**
	 * Begin a run and immediately mark it waiting on an approval/proposal,
	 * returning it. $type/$id default to 'approval'/'42' and must match
	 * whatever the test later fires `agent_builder_approval_resolved` with —
	 * claim_waiting() matches on both, not just run_id.
	 *
	 * @param array  $opts         Agent_Run::begin() opts.
	 * @param string $type         'approval' or 'proposal'.
	 * @param string $id           Id of the approval/proposal being awaited.
	 * @param string $tool_call_id Original LLM tool-call id to persist (empty = none).
	 * @return Agent_Run
	 */
	private function begin_waiting_run( array $opts = array(), string $type = 'approval', string $id = '42', string $tool_call_id = '' ): Agent_Run {
		$run = Agent_Run::begin( 'wordpress-assistant', $opts );
		$run->mark_waiting(
			$type,
			$id,
			array(
				array(
					'role'    => 'user',
					'content' => 'Publish the draft.',
				),
				array(
					'role'    => 'assistant',
					'content' => 'I need approval to publish.',
				),
			),
			$tool_call_id
		);
		return $run;
	}

	/**
	 * Approving a waiting run dispatches a resume job whose request_data
	 * carries the run's resume_state() and the passed-through tool result.
	 */
	public function test_approve_dispatches_resume_job_with_correct_payload(): void {
		$run = $this->begin_waiting_run( array( 'user_id' => 7, 'task_text' => 'Publish the draft' ) );

		$result = array(
			'ran'     => true,
			'success' => true,
			'message' => 'Published.',
		);
		$row = array(
			'run_id' => $run->get_run_id(),
			'action' => 'db_update_post',
		);

		do_action( 'agent_builder_approval_resolved', 'approval', 42, 'approved', $result, $row );

		$job = $this->the_pending_job();
		$this->assertNotNull( $job );

		$request = $job->request_data;
		$this->assertSame( Agent_Task_Job_Processor::class, $request['_processor'] );
		$this->assertSame( $run->get_run_id(), $request['run_id'] );
		$this->assertSame( 'wordpress-assistant', $request['agent_id'] );
		$this->assertSame( 7, $request['user_id'] );

		$this->assertIsArray( $request['resume'] );
		$this->assertSame( 'approval', $request['resume']['awaiting_type'] );
		$this->assertSame( '42', $request['resume']['awaiting_id'] );
		$this->assertSame(
			array(
				array( 'role' => 'user', 'content' => 'Publish the draft.' ),
				array( 'role' => 'assistant', 'content' => 'I need approval to publish.' ),
			),
			$request['resume']['messages']
		);

		$expected_tool_result         = $result;
		$expected_tool_result['tool'] = 'db_update_post';
		$this->assertSame( $expected_tool_result, $request['tool_result'] );
	}

	/**
	 * The resume payload must carry the original LLM tool_call_id (persisted
	 * in awaiting_tool_call_id) through to the job, never the approval's own
	 * business id — providers that validate tool-call pairing reject a
	 * fabricated tool message whose id doesn't match the assistant message's
	 * tool_calls[].id.
	 */
	public function test_approve_resume_carries_original_tool_call_id_not_business_id(): void {
		// awaiting_tool_call_id is the LLM-issued id; '42' is the approval's
		// business id. They must be distinct, and the resume payload must
		// carry the former, not the latter.
		$run = $this->begin_waiting_run( array(), 'approval', '42', 'call_abc123' );

		$row = array(
			'run_id' => $run->get_run_id(),
			'action' => 'db_update_post',
		);

		do_action( 'agent_builder_approval_resolved', 'approval', 42, 'approved', array( 'success' => true ), $row );

		$job = $this->the_pending_job();
		$this->assertNotNull( $job );

		$resume = $job->request_data['resume'];
		$this->assertIsArray( $resume );
		$this->assertSame( 'call_abc123', $resume['awaiting_tool_call_id'] );
		$this->assertSame( '42', $resume['awaiting_id'] );
		$this->assertNotSame( $resume['awaiting_id'], $resume['awaiting_tool_call_id'] );
	}

	/**
	 * The resumed run's tool message must carry the real tool name, not an
	 * empty string — neither execute_approved_action() nor
	 * Agent_Proposals::approve()'s return value carries a 'tool' key, so
	 * Run_Resumer must add it before the result becomes tool_result. A
	 * proposal row names its tool under 'tool', not 'action'.
	 */
	public function test_approve_adds_resolved_tool_name_for_proposal_rows(): void {
		$run = $this->begin_waiting_run( array(), 'proposal', 'prop-1' );

		$row = array(
			'run_id' => $run->get_run_id(),
			'tool'   => 'delete_form',
		);

		do_action( 'agent_builder_approval_resolved', 'proposal', 'prop-1', 'approved', array( 'success' => true ), $row );

		$job     = $this->the_pending_job();
		$this->assertNotNull( $job );
		$this->assertSame( 'delete_form', $job->request_data['tool_result']['tool'] );
	}

	/**
	 * A real approve can carry no execution result (`$result` is null) — the
	 * resume payload must not fabricate a `tool_result` key in that case,
	 * only 'resume'.
	 */
	public function test_approve_with_null_result_does_not_fabricate_tool_result(): void {
		$run = $this->begin_waiting_run();

		$row = array(
			'run_id' => $run->get_run_id(),
			'action' => 'db_update_post',
		);

		do_action( 'agent_builder_approval_resolved', 'approval', 42, 'approved', null, $row );

		$job = $this->the_pending_job();
		$this->assertNotNull( $job );
		$this->assertArrayNotHasKey( 'tool_result', $job->request_data );
		$this->assertIsArray( $job->request_data['resume'] );
	}

	/**
	 * A stale/duplicate resolution for a pending item the run has already
	 * moved past (it is now waiting on something newer) must not hijack the
	 * newer wait — claim_waiting() matches on awaiting_type/awaiting_id, not
	 * just run_id/status.
	 */
	public function test_stale_resolution_for_a_superseded_wait_is_a_clean_noop(): void {
		$run = $this->begin_waiting_run( array(), 'approval', '1' );

		// The run has since moved on to waiting on a different approval.
		$run->mark_waiting( 'approval', '2', array() );

		$row = array(
			'run_id' => $run->get_run_id(),
			'action' => 'db_update_post',
		);

		$waiting_before = did_action( 'agent_builder_run_waiting' );

		// A late resolution for the old (superseded) approval id '1' arrives.
		do_action( 'agent_builder_approval_resolved', 'approval', 1, 'approved', array( 'success' => true ), $row );

		$this->assertNull( $this->the_pending_job() );
		$this->assertSame( $waiting_before, did_action( 'agent_builder_run_waiting' ) );

		$reloaded = Agent_Run::load( $run->get_run_id() );
		$data     = $reloaded->to_array();
		$this->assertSame( 'waiting', $data['status'] );
		$this->assertSame( '2', $data['awaiting_id'] );
	}

	/**
	 * A refused dispatch (e.g. Emergency Stop active) must not leave the run
	 * stuck 'running' forever — claim_waiting() already flipped it out of
	 * 'waiting', so Run_Resumer must finish() it as failed and fire
	 * agent_builder_run_finished exactly once instead of silently orphaning it.
	 */
	public function test_failed_dispatch_finishes_run_as_failed_instead_of_orphaning_it(): void {
		$run = $this->begin_waiting_run();

		$row = array(
			'run_id' => $run->get_run_id(),
			'action' => 'db_update_post',
		);

		$finished_before = did_action( 'agent_builder_run_finished' );

		update_option( Emergency_Stop::OPTION_ENABLED, '1' );

		do_action( 'agent_builder_approval_resolved', 'approval', 42, 'approved', array( 'success' => true ), $row );

		update_option( Emergency_Stop::OPTION_ENABLED, '0' );

		$this->assertNull( $this->the_pending_job() );
		$this->assertSame( $finished_before + 1, did_action( 'agent_builder_run_finished' ) );

		$reloaded = Agent_Run::load( $run->get_run_id() );
		$this->assertSame( 'failed', $reloaded->to_array()['status'] );
	}

	/**
	 * Two concurrent resolutions for the same run (a double-submit, a
	 * retried REST request, two admins racing the same approval) must not
	 * both dispatch a resume job — only the first to claim the run out of
	 * 'waiting' may act; the second is a clean no-op.
	 */
	public function test_concurrent_approve_resolutions_dispatch_only_one_job(): void {
		$run = $this->begin_waiting_run( array(), 'approval', '1' );

		$row = array(
			'run_id' => $run->get_run_id(),
			'action' => 'db_update_post',
		);

		$waiting_before = did_action( 'agent_builder_run_waiting' );

		do_action( 'agent_builder_approval_resolved', 'approval', 1, 'approved', array( 'success' => true ), $row );
		do_action( 'agent_builder_approval_resolved', 'approval', 1, 'approved', array( 'success' => true ), $row );

		$pending = Job_Manager::list_by_statuses( array( Job_Manager::STATUS_PENDING ) );
		$this->assertCount( 1, $pending );
		$this->assertSame( $waiting_before + 1, did_action( 'agent_builder_run_waiting' ) );
	}

	/**
	 * The same race on the reject path: only the first resolution to claim
	 * the run may finish() it; the second is a clean no-op (no second
	 * finish, no duplicate agent_builder_run_finished).
	 */
	public function test_concurrent_reject_resolutions_finish_only_once(): void {
		$run = $this->begin_waiting_run( array(), 'approval', '1' );

		$row = array(
			'run_id' => $run->get_run_id(),
			'action' => 'db_delete_post',
		);

		$finished_before = did_action( 'agent_builder_run_finished' );

		do_action( 'agent_builder_approval_resolved', 'approval', 1, 'rejected', null, $row );
		do_action( 'agent_builder_approval_resolved', 'approval', 1, 'rejected', null, $row );

		$this->assertSame( $finished_before + 1, did_action( 'agent_builder_run_finished' ) );
	}

	/**
	 * Rejecting a waiting run finishes it (as stopped/denied) and dispatches
	 * no resume job. The 'action' column names the tool for an approval-queue
	 * row.
	 */
	public function test_reject_finishes_run_with_denied_message_and_dispatches_no_job(): void {
		$run = $this->begin_waiting_run( array(), 'approval', '99' );

		$row = array(
			'run_id' => $run->get_run_id(),
			'action' => 'db_delete_post',
		);

		do_action( 'agent_builder_approval_resolved', 'approval', 99, 'rejected', null, $row );

		$this->assertNull( $this->the_pending_job() );

		$reloaded = Agent_Run::load( $run->get_run_id() );
		$data     = $reloaded->to_array();
		$this->assertSame( 'completed', $data['status'] );
		$this->assertSame( 'Stopped: you denied db_delete_post', $data['result_summary']['text'] );
	}

	/**
	 * A proposal row names its tool under 'tool', not 'action' — the denied
	 * message must still name the right tool.
	 */
	public function test_reject_uses_tool_column_for_proposal_rows(): void {
		$run = $this->begin_waiting_run( array(), 'proposal', 'prop-1' );

		$row = array(
			'run_id' => $run->get_run_id(),
			'tool'   => 'delete_form',
		);

		do_action( 'agent_builder_approval_resolved', 'proposal', 'prop-1', 'rejected', null, $row );

		$reloaded = Agent_Run::load( $run->get_run_id() );
		$this->assertSame( 'Stopped: you denied delete_form', $reloaded->to_array()['result_summary']['text'] );
	}

	/**
	 * A run_id that resolves but is not 'waiting' (already resumed, or
	 * finished by something else) is a clean no-op: no crash, no job, no
	 * second finish().
	 */
	public function test_non_waiting_run_is_a_clean_noop(): void {
		$run = Agent_Run::begin( 'wordpress-assistant' );
		// Status stays 'running' — never marked waiting.

		$row = array(
			'run_id' => $run->get_run_id(),
			'action' => 'db_update_post',
		);

		do_action( 'agent_builder_approval_resolved', 'approval', 1, 'approved', array( 'success' => true ), $row );

		$this->assertNull( $this->the_pending_job() );
		$this->assertSame( 'running', Agent_Run::load( $run->get_run_id() )->to_array()['status'] );
	}

	/**
	 * An already-terminal run (completed/aborted) is also a clean no-op —
	 * a stale or duplicate resolution must not re-finish or re-dispatch it.
	 */
	public function test_already_terminal_run_is_a_clean_noop(): void {
		// Deliberately not begin_waiting_run(): mark_waiting() settles the
		// in-process instance (so the shutdown guard leaves it alone), which
		// makes a same-instance finish() call afterwards a no-op. A run that
		// reached a terminal status without ever waiting exercises the
		// "already terminal" no-op path for real.
		$run = Agent_Run::begin( 'wordpress-assistant' );
		$run->finish( 'aborted' );

		$row = array(
			'run_id' => $run->get_run_id(),
			'action' => 'db_update_post',
		);

		do_action( 'agent_builder_approval_resolved', 'approval', 1, 'approved', array( 'success' => true ), $row );

		$this->assertNull( $this->the_pending_job() );
		$this->assertSame( 'aborted', Agent_Run::load( $run->get_run_id() )->to_array()['status'] );
	}

	/**
	 * A row with no run_id at all (chat-originated approval/proposal) is a
	 * clean no-op if the action is fired for it anyway.
	 */
	public function test_row_without_run_id_is_a_clean_noop(): void {
		do_action( 'agent_builder_approval_resolved', 'approval', 1, 'approved', array( 'success' => true ), array( 'action' => 'db_update_post' ) );

		$this->assertNull( $this->the_pending_job() );
	}

	/**
	 * A run_id that does not resolve to any row is a clean no-op.
	 */
	public function test_unknown_run_id_is_a_clean_noop(): void {
		do_action(
			'agent_builder_approval_resolved',
			'approval',
			1,
			'approved',
			array( 'success' => true ),
			array(
				'run_id' => 'does-not-exist',
				'action' => 'db_update_post',
			)
		);

		$this->assertNull( $this->the_pending_job() );
	}

	/**
	 * agent_builder_run_waiting fires exactly once when Run_Resumer confirms
	 * the wait state, whichever way the decision resolves; agent_builder_run_finished
	 * fires exactly once, only on the reject path.
	 */
	public function test_run_waiting_and_run_finished_fire_once_on_reject(): void {
		$run = $this->begin_waiting_run( array(), 'approval', '1' );

		$waiting_before  = did_action( 'agent_builder_run_waiting' );
		$finished_before = did_action( 'agent_builder_run_finished' );

		$row = array(
			'run_id' => $run->get_run_id(),
			'action' => 'db_update_post',
		);
		do_action( 'agent_builder_approval_resolved', 'approval', 1, 'rejected', null, $row );

		$this->assertSame( $waiting_before + 1, did_action( 'agent_builder_run_waiting' ) );
		$this->assertSame( $finished_before + 1, did_action( 'agent_builder_run_finished' ) );
	}

	/**
	 * On approve, agent_builder_run_waiting still fires once (Run_Resumer
	 * confirmed the wait state before dispatching), but agent_builder_run_finished
	 * does not — the run is still going, handed off to the resume job.
	 */
	public function test_run_waiting_fires_but_not_run_finished_on_approve(): void {
		$run = $this->begin_waiting_run( array(), 'approval', '1' );

		$waiting_before  = did_action( 'agent_builder_run_waiting' );
		$finished_before = did_action( 'agent_builder_run_finished' );

		$row = array(
			'run_id' => $run->get_run_id(),
			'action' => 'db_update_post',
		);
		do_action( 'agent_builder_approval_resolved', 'approval', 1, 'approved', array( 'success' => true ), $row );

		$this->assertSame( $waiting_before + 1, did_action( 'agent_builder_run_waiting' ) );
		$this->assertSame( $finished_before, did_action( 'agent_builder_run_finished' ) );
	}

	/**
	 * agent_builder_run_started fires exactly once for a genuinely new run,
	 * and not again for a nested begin() (delegation) that returns the
	 * already-active run.
	 */
	public function test_run_started_fires_once_for_a_new_run_not_for_nested_begin(): void {
		$before = did_action( 'agent_builder_run_started' );

		$outer = Agent_Run::begin( 'content-writer' );
		$this->assertSame( $before + 1, did_action( 'agent_builder_run_started' ) );

		Agent_Run::begin( 'seo-optimizer' );
		$this->assertSame( $before + 1, did_action( 'agent_builder_run_started' ) );

		$outer->finish( 'completed' );
	}

	/**
	 * REST_API::handle_approval() fires agent_builder_approval_resolved only
	 * when the approval row carries a run_id — verified at the call site,
	 * not just inside Run_Resumer.
	 */
	public function test_handle_approval_fires_action_only_when_run_id_set(): void {
		$api = ( new \ReflectionClass( REST_API::class ) )->newInstanceWithoutConstructor();

		$queue         = new Approval_Queue();
		$id_no_run     = $queue->add( 'wordpress-assistant', 'list_posts', array(), 'test', 7, 'low' );
		$run           = Agent_Run::begin( 'wordpress-assistant' );
		$id_with_run   = $queue->add( 'wordpress-assistant', 'list_posts', array(), 'test', 7, 'low', '', '', $run->get_run_id() );

		$before = did_action( 'agent_builder_approval_resolved' );

		$request = new \WP_REST_Request( 'POST', '/agentic/v1/approvals/handle' );
		$request->set_param( 'id', $id_no_run );
		$request->set_param( 'action', 'approve' );
		$api->handle_approval( $request );

		$this->assertSame( $before, did_action( 'agent_builder_approval_resolved' ) );

		$request2 = new \WP_REST_Request( 'POST', '/agentic/v1/approvals/handle' );
		$request2->set_param( 'id', $id_with_run );
		$request2->set_param( 'action', 'approve' );
		$api->handle_approval( $request2 );

		$this->assertSame( $before + 1, did_action( 'agent_builder_approval_resolved' ) );

		$run->finish( 'completed' );
	}

	/**
	 * Agent_Proposals::approve()/reject() fire agent_builder_approval_resolved
	 * only when the proposal carries a run_id.
	 */
	public function test_agent_proposals_fires_action_only_when_run_id_set(): void {
		$no_run_proposal   = Agent_Proposals::create( 'list_posts', array(), 'wordpress-assistant', 'Test proposal (no run)' );
		$run               = Agent_Run::begin( 'wordpress-assistant' );
		$run_proposal      = Agent_Proposals::create( 'list_posts', array(), 'wordpress-assistant', 'Test proposal (run)', '', $run->get_run_id() );

		$before = did_action( 'agent_builder_approval_resolved' );

		Agent_Proposals::reject( $no_run_proposal['id'] );
		$this->assertSame( $before, did_action( 'agent_builder_approval_resolved' ) );

		Agent_Proposals::reject( $run_proposal['id'] );
		$this->assertSame( $before + 1, did_action( 'agent_builder_approval_resolved' ) );

		$run->finish( 'completed' );
	}
}
