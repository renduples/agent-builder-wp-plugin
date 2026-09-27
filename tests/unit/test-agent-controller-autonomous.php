<?php
/**
 * Unit Tests for Agent_Controller autonomous runs and chat() cost summing.
 *
 * Drives the real controller loop through the Fake_LLM_Client injection seam
 * (10b) instead of a live provider, so it can exercise the full
 * run_autonomous_task() behaviour end to end: LOW-risk tools execute and
 * complete, MEDIUM-risk tools pause into a resumable waiting state, an
 * Emergency Stop aborts mid-run, and tokens/cost accumulate across every
 * iteration rather than reflecting only the final turn.
 *
 * @package Agentic\Tests
 */

namespace Agentic\Tests;

use Agentic\Agent_Controller;
use Agentic\Agent_Permissions;
use Agentic\Agent_Run;
use Agentic\Manifest_Agent;

/**
 * Test case for Agent_Controller::run_autonomous_task() and chat().
 */
class Test_Agent_Controller_Autonomous extends TestCase {

	/**
	 * Build a minimal Manifest_Agent test double.
	 *
	 * @param string $slug Agent slug.
	 * @return Manifest_Agent
	 */
	private function make_agent( string $slug ): Manifest_Agent {
		return new Manifest_Agent(
			array(
				'slug' => $slug,
				'name' => 'Test Agent',
			),
			''
		);
	}

	/**
	 * Reset request-scoped static state the autonomous path sets, so a run that
	 * reached the loop in one test cannot leak into the next.
	 */
	public function setUp(): void {
		parent::setUp();
		Agent_Permissions::set_mode_override( null );
	}

	/**
	 * Clear options the autonomous path may have left behind.
	 */
	public function tearDown(): void {
		Agent_Permissions::set_mode_override( null );
		delete_option( 'agent_builder_disable_all_agents' );
		delete_option( 'agent_builder_allow_anonymous_chat' );
		parent::tearDown();
	}

	/**
	 * A LOW-risk (read-only) tool call runs to completion: the run finishes
	 * 'completed', records positive iterations, and reports the tool used.
	 */
	public function test_low_risk_tool_call_completes(): void {
		$agent_id = 'test-autonomous-low';
		$fake     = new Fake_LLM_Client(
			array(
				Fake_LLM_Client::tool_call_response( 'list_posts', array(), array( 'prompt_tokens' => 8, 'completion_tokens' => 4, 'total_tokens' => 12 ) ),
				Fake_LLM_Client::text_response( 'Listed posts.', array( 'prompt_tokens' => 6, 'completion_tokens' => 3, 'total_tokens' => 9 ) ),
			)
		);

		$controller = new Agent_Controller( $fake );
		$result     = $controller->run_autonomous_task( $this->make_agent( $agent_id ), 'List my posts.', 'task-low' );

		$this->assertIsArray( $result );
		$this->assertSame( 'completed', $result['status'] );
		$this->assertSame( 2, $result['iterations'] );
		$this->assertContains( 'list_posts', $result['tools_used'] );
		$this->assertSame( 21, $result['tokens_used'] );

		// The run row must reflect the completed state and accumulated tokens.
		$run = Agent_Run::load( $result['run_id'] );
		$this->assertNotNull( $run );
		$this->assertSame( 'completed', $run->to_array()['status'] );
		$this->assertSame( 21, $run->to_array()['tokens_used'] );
	}

	/**
	 * A MEDIUM-risk tool call pauses the run into a waiting state, recording
	 * the awaited proposal id and checkpointing a resumable transcript.
	 */
	public function test_medium_risk_tool_call_waits_with_transcript(): void {
		$agent_id = 'test-autonomous-medium';
		$fake     = new Fake_LLM_Client(
			array(
				Fake_LLM_Client::tool_call_response( 'purge_expired_transients', array(), array( 'prompt_tokens' => 20, 'completion_tokens' => 10, 'total_tokens' => 30 ) ),
			)
		);

		$controller = new Agent_Controller( $fake );
		$result     = $controller->run_autonomous_task( $this->make_agent( $agent_id ), 'Purge stale transients.', 'task-medium' );

		$this->assertIsArray( $result );
		$this->assertSame( 'waiting', $result['status'] );

		$run   = Agent_Run::load( $result['run_id'] );
		$state = $run->to_array();
		$this->assertSame( 'waiting', $state['status'] );
		$this->assertSame( 'proposal', $state['awaiting_type'] );
		$this->assertNotSame( '', $state['awaiting_id'] );

		// The transcript must be checkpointed so a later request can resume.
		$resume = $run->resume_state();
		$this->assertNotEmpty( $resume['messages'] );
		$this->assertSame( 'proposal', $resume['awaiting_type'] );
		$this->assertSame( $state['awaiting_id'], $resume['awaiting_id'] );
	}

	/**
	 * A run paused in the waiting state resumes (via $options run_id /
	 * resume_state / tool_result) and completes on the next scripted turn.
	 */
	public function test_resume_after_waiting_completes(): void {
		$agent_id = 'test-autonomous-resume';
		$fake     = new Fake_LLM_Client(
			array(
				Fake_LLM_Client::tool_call_response( 'purge_expired_transients', array(), array( 'prompt_tokens' => 20, 'completion_tokens' => 10, 'total_tokens' => 30 ) ),
				Fake_LLM_Client::text_response( 'Transients purged.', array( 'prompt_tokens' => 7, 'completion_tokens' => 5, 'total_tokens' => 12 ) ),
			)
		);

		$controller = new Agent_Controller( $fake );
		$agent      = $this->make_agent( $agent_id );

		$first = $controller->run_autonomous_task( $agent, 'Purge stale transients.', 'task-resume' );
		$this->assertSame( 'waiting', $first['status'] );

		$run    = Agent_Run::load( $first['run_id'] );
		$second = $controller->run_autonomous_task(
			$agent,
			'Purge stale transients.',
			'task-resume',
			array(
				'run_id'       => $first['run_id'],
				'resume_state' => $run->resume_state(),
				'tool_result'  => array(
					'tool'               => 'purge_expired_transients',
					'status'             => 'completed',
					'transients_deleted' => 0,
				),
			)
		);

		$this->assertIsArray( $second );
		$this->assertSame( 'completed', $second['status'] );
		$this->assertSame( $first['run_id'], $second['run_id'] );
		$this->assertGreaterThan( 0, $second['iterations'] );

		$run = Agent_Run::load( $first['run_id'] );
		$this->assertSame( 'completed', $run->to_array()['status'] );
	}

	/**
	 * Resuming a run whose status is already terminal (completed) must be
	 * rejected with a clear error, and must never replay the completed run's
	 * tool calls against the LLM again.
	 */
	public function test_resume_rejects_completed_run_without_replay(): void {
		$agent_id = 'test-autonomous-resume-terminal';
		$fake     = new Fake_LLM_Client(
			array(
				Fake_LLM_Client::tool_call_response( 'list_posts', array(), array( 'prompt_tokens' => 8, 'completion_tokens' => 4, 'total_tokens' => 12 ) ),
				Fake_LLM_Client::text_response( 'Listed posts.', array( 'prompt_tokens' => 6, 'completion_tokens' => 3, 'total_tokens' => 9 ) ),
			)
		);

		$controller = new Agent_Controller( $fake );
		$agent      = $this->make_agent( $agent_id );

		$first = $controller->run_autonomous_task( $agent, 'List my posts.', 'task-terminal' );
		$this->assertSame( 'completed', $first['status'] );
		$this->assertSame( 2, $fake->chat_calls );

		$run    = Agent_Run::load( $first['run_id'] );
		$second = $controller->run_autonomous_task(
			$agent,
			'List my posts.',
			'task-terminal',
			array(
				'run_id'       => $first['run_id'],
				'resume_state' => $run->resume_state(),
				'tool_result'  => array(
					'tool'   => 'list_posts',
					'status' => 'completed',
				),
			)
		);

		$this->assertIsArray( $second );
		$this->assertTrue( $second['error'] ?? false );
		$this->assertSame( 'error', $second['status'] );
		$this->assertSame( 2, $fake->chat_calls, 'resuming a completed run must not replay any tool calls' );

		$run_after = Agent_Run::load( $first['run_id'] );
		$this->assertSame( 'completed', $run_after->to_array()['status'] );
	}

	/**
	 * Resuming a run must validate it belongs to the requested agent — a
	 * mismatched agent_id is rejected and the run is left untouched.
	 */
	public function test_resume_rejects_run_belonging_to_a_different_agent(): void {
		$agent_id = 'test-autonomous-resume-owner';
		$fake     = new Fake_LLM_Client(
			array(
				Fake_LLM_Client::tool_call_response( 'purge_expired_transients', array(), array( 'prompt_tokens' => 20, 'completion_tokens' => 10, 'total_tokens' => 30 ) ),
			)
		);

		$controller  = new Agent_Controller( $fake );
		$agent       = $this->make_agent( $agent_id );
		$other_agent = $this->make_agent( 'test-autonomous-resume-owner-other' );

		$first = $controller->run_autonomous_task( $agent, 'Purge stale transients.', 'task-owner' );
		$this->assertSame( 'waiting', $first['status'] );

		$run    = Agent_Run::load( $first['run_id'] );
		$second = $controller->run_autonomous_task(
			$other_agent,
			'Purge stale transients.',
			'task-owner',
			array(
				'run_id'       => $first['run_id'],
				'resume_state' => $run->resume_state(),
				'tool_result'  => array(
					'tool'   => 'purge_expired_transients',
					'status' => 'completed',
				),
			)
		);

		$this->assertIsArray( $second );
		$this->assertTrue( $second['error'] ?? false );
		$this->assertSame( 'error', $second['status'] );

		$run_after = Agent_Run::load( $first['run_id'] );
		$this->assertSame( 'waiting', $run_after->to_array()['status'] );
	}

	/**
	 * The resumed tool message must carry the original LLM tool_call_id
	 * (from the assistant's tool_calls[].id), not the proposal/approval
	 * queue's own business id — the two are tracked separately and are
	 * never equal for a real pending tool call.
	 */
	public function test_resume_reconstructs_tool_message_with_original_tool_call_id(): void {
		$agent_id = 'test-autonomous-resume-toolcallid';
		$fake     = new Fake_LLM_Client(
			array(
				Fake_LLM_Client::tool_call_response( 'purge_expired_transients', array(), array( 'prompt_tokens' => 20, 'completion_tokens' => 10, 'total_tokens' => 30 ) ),
				Fake_LLM_Client::text_response( 'Transients purged.', array( 'prompt_tokens' => 7, 'completion_tokens' => 5, 'total_tokens' => 12 ) ),
			)
		);

		$controller = new Agent_Controller( $fake );
		$agent      = $this->make_agent( $agent_id );

		$first = $controller->run_autonomous_task( $agent, 'Purge stale transients.', 'task-toolcallid' );
		$this->assertSame( 'waiting', $first['status'] );

		$run   = Agent_Run::load( $first['run_id'] );
		$state = $run->resume_state();

		$this->assertNotSame( '', $state['awaiting_tool_call_id'] );
		$this->assertNotSame(
			$state['awaiting_id'],
			$state['awaiting_tool_call_id'],
			'the LLM tool-call id and the proposal/approval business id must be distinct'
		);

		$controller->run_autonomous_task(
			$agent,
			'Purge stale transients.',
			'task-toolcallid',
			array(
				'run_id'       => $first['run_id'],
				'resume_state' => $state,
				'tool_result'  => array(
					'tool'               => 'purge_expired_transients',
					'status'             => 'completed',
					'transients_deleted' => 0,
				),
			)
		);

		// The second chat() call is the resumed one; the reconstructed tool
		// message is the last one built before the loop's first iteration.
		$resumed_messages = end( $fake->messages_seen );
		$last_message      = end( $resumed_messages );

		$this->assertSame( 'tool', $last_message['role'] ?? null );
		$this->assertSame( $state['awaiting_tool_call_id'], $last_message['tool_call_id'] ?? null );
		$this->assertNotSame( $state['awaiting_id'], $last_message['tool_call_id'] ?? null );
	}

	/**
	 * Two concurrent resume attempts for the same run_id must not both
	 * execute the pending tool call, even when the race lands in the exact
	 * window the atomic claim exists to close: a plain get_status() read
	 * both attempts would see 'waiting' still passes for both, since the
	 * row hasn't moved yet at that point. This intercepts the moment
	 * run_autonomous_task()'s resume branch issues its claim_waiting()
	 * UPDATE and races a second, separately-loaded claim in ahead of it —
	 * proving the atomic claim itself (not just the earlier status check,
	 * which alone cannot see this interleaving) rejects the loser.
	 */
	public function test_resume_is_atomically_claimed_against_a_concurrent_resume(): void {
		global $wpdb;

		$agent_id = 'test-autonomous-resume-claim';
		$fake     = new Fake_LLM_Client(
			array(
				Fake_LLM_Client::tool_call_response( 'purge_expired_transients', array(), array( 'prompt_tokens' => 20, 'completion_tokens' => 10, 'total_tokens' => 30 ) ),
				Fake_LLM_Client::text_response( 'Transients purged.', array( 'prompt_tokens' => 7, 'completion_tokens' => 5, 'total_tokens' => 12 ) ),
			)
		);

		$controller = new Agent_Controller( $fake );
		$agent      = $this->make_agent( $agent_id );

		$first = $controller->run_autonomous_task( $agent, 'Purge stale transients.', 'task-claim' );
		$this->assertSame( 'waiting', $first['status'] );

		$run          = Agent_Run::load( $first['run_id'] );
		$resume_state = $run->resume_state();
		$run_id       = $first['run_id'];
		$table        = $wpdb->prefix . 'agent_builder_runs';

		// The row is still 'waiting' right up until claim_waiting()'s own
		// UPDATE runs. Intercept that exact statement the first time it is
		// about to execute and win the race with a separately-issued claim
		// of the same row, so the real claim_waiting() call finds 0 rows
		// left to update.
		$armed = false;
		$racer = static function ( $query ) use ( &$armed, $table, $run_id, $wpdb ) {
			if ( ! $armed && false !== stripos( (string) $query, "SET status = 'running'" ) && false !== stripos( (string) $query, "'waiting','continuing'" ) ) {
				$armed = true;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- test-only concurrent claimant simulating another process; mirrors claim_waiting()'s own prepared UPDATE.
				$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'running' WHERE run_id = %s AND status IN ('waiting','continuing')", $run_id ) );
			}
			return $query;
		};
		add_filter( 'query', $racer );

		try {
			$second = $controller->run_autonomous_task(
				$agent,
				'Purge stale transients.',
				'task-claim',
				array(
					'run_id'       => $run_id,
					'resume_state' => $resume_state,
					'tool_result'  => array(
						'tool'               => 'purge_expired_transients',
						'status'             => 'completed',
						'transients_deleted' => 0,
					),
				)
			);
		} finally {
			remove_filter( 'query', $racer );
		}

		$this->assertTrue( $armed, 'the race must actually intercept claim_waiting()\'s UPDATE for this test to prove anything' );
		$this->assertIsArray( $second );
		$this->assertTrue( $second['error'] ?? false );
		$this->assertSame( 'error', $second['status'] );
		$this->assertTrue( $second['guard_rejected'] ?? false );
		$this->assertSame( 1, $fake->chat_calls, 'the loser of the claim race must not execute the pending tool call again' );
	}

	/**
	 * The ~70% elapsed-time guard hands the run off via mark_continuing(),
	 * not finish() — so it lands in a non-terminal 'continuing' status that
	 * survives the shutdown safety net at request end (simulated here by
	 * calling finish('aborted') the way register_shutdown_guard() would,
	 * and asserting it is now a no-op).
	 */
	public function test_elapsed_time_guard_leaves_run_continuing_not_aborted(): void {
		$agent_id = 'test-autonomous-elapsed-guard';
		$agent    = $this->make_agent( $agent_id );
		$fake     = new Fake_LLM_Client();

		$run = Agent_Run::begin( $agent_id, array( 'task_text' => 'Long-running task.' ) );

		$controller = new Agent_Controller( $fake );
		$method     = new \ReflectionMethod( Agent_Controller::class, 'dispatch_continuation' );
		$method->invoke( $controller, $run, $agent, 'Long-running task.', 'task-elapsed' );

		$this->assertSame( 'continuing', $run->to_array()['status'] );

		// Stand-in for the shutdown safety net firing at request end.
		$run->finish( 'aborted' );

		$this->assertSame(
			'continuing',
			$run->to_array()['status'],
			'the elapsed-time hand-off must not be overwritten to aborted by the shutdown guard'
		);
	}

	/**
	 * A run handed off by the elapsed-time guard lands in 'continuing', not
	 * 'waiting' — the resume guard must accept that status too (the
	 * background continuation job resumes via the same run_id/resume_state
	 * options as an approval/proposal resume, but with no tool_result, since
	 * there is no pending tool call to answer).
	 */
	public function test_resume_accepts_continuing_run_from_elapsed_guard(): void {
		$agent_id = 'test-autonomous-resume-continuing';
		$agent    = $this->make_agent( $agent_id );
		$fake     = new Fake_LLM_Client(
			array(
				Fake_LLM_Client::text_response( 'Finished after continuation.', array( 'prompt_tokens' => 5, 'completion_tokens' => 3, 'total_tokens' => 8 ) ),
			)
		);

		$run = Agent_Run::begin( $agent_id, array( 'task_text' => 'Long-running task.' ) );
		$run->checkpoint_transcript(
			array(
				array( 'role' => 'system', 'content' => 'System prompt.' ),
				array( 'role' => 'user', 'content' => 'Long-running task.' ),
			)
		);
		$run->mark_continuing();
		Agent_Run::reset_current_for_tests();

		$controller = new Agent_Controller( $fake );
		$result     = $controller->run_autonomous_task(
			$agent,
			'Long-running task.',
			'task-continuing-resume',
			array(
				'run_id'       => $run->get_run_id(),
				'resume_state' => $run->resume_state(),
			)
		);

		$this->assertIsArray( $result );
		$this->assertFalse( $result['error'] ?? false, 'resuming a continuing run must not be rejected' );
		$this->assertSame( 'completed', $result['status'] );
		$this->assertSame( 1, $fake->chat_calls, 'the resumed loop must actually call the LLM again' );

		$run_after = Agent_Run::load( $run->get_run_id() );
		$this->assertSame( 'completed', $run_after->to_array()['status'] );
	}

	/**
	 * An active Emergency Stop aborts the run before any LLM call is made.
	 */
	public function test_emergency_stop_aborts_run(): void {
		$agent_id = 'test-autonomous-stop';
		$fake     = new Fake_LLM_Client( array() );

		update_option( 'agent_builder_disable_all_agents', '1' );

		$controller = new Agent_Controller( $fake );
		$result     = $controller->run_autonomous_task( $this->make_agent( $agent_id ), 'Do the thing.', 'task-stop' );

		$this->assertIsArray( $result );
		$this->assertSame( 'aborted', $result['status'] );
		$this->assertNotSame( '', $result['run_id'] );
		$this->assertSame( 0, $fake->chat_calls, 'the loop must abort before ever calling the LLM' );
	}

	/**
	 * Tokens and cost accumulate across every iteration of a multi-turn run,
	 * not just the final turn.
	 */
	public function test_cost_and_tokens_sum_across_iterations(): void {
		$agent_id = 'test-autonomous-sum';
		$fake     = new Fake_LLM_Client(
			array(
				Fake_LLM_Client::tool_call_response( 'list_posts', array(), array( 'prompt_tokens' => 40, 'completion_tokens' => 20, 'total_tokens' => 60 ) ),
				Fake_LLM_Client::tool_call_response( 'get_site_overview', array(), array( 'prompt_tokens' => 15, 'completion_tokens' => 10, 'total_tokens' => 25 ) ),
				Fake_LLM_Client::text_response( 'All done.', array( 'prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15 ) ),
			)
		);

		$controller = new Agent_Controller( $fake );
		$result     = $controller->run_autonomous_task( $this->make_agent( $agent_id ), 'Summarise the site.', 'task-sum' );

		$this->assertSame( 'completed', $result['status'] );
		$this->assertSame( 3, $result['iterations'] );
		$this->assertSame( 100, $result['tokens_used'], 'tokens must sum across all three turns (60 + 25 + 15)' );
		$this->assertSame( array( 'list_posts', 'get_site_overview' ), $result['tools_used'] );
	}

	/**
	 * chat() applies the same cost/token summing as run_autonomous_task(): a
	 * multi-iteration chat turn accumulates tokens rather than reflecting only
	 * the last LLM call.
	 */
	public function test_chat_sums_cost_across_iterations(): void {
		$agent_id = 'test-autonomous-chat-sum';
		$registry = \Agentic_Agent_Registry::get_instance();
		$registry->register( $this->make_agent( $agent_id ) );

		// Anonymous access must be allowed for set_agent() to accept the agent.
		update_option( 'agent_builder_allow_anonymous_chat', true );

		$fake = new Fake_LLM_Client(
			array(
				Fake_LLM_Client::tool_call_response( 'list_posts', array(), array( 'prompt_tokens' => 50, 'completion_tokens' => 30, 'total_tokens' => 80 ) ),
				Fake_LLM_Client::text_response( 'Here are your posts.', array( 'prompt_tokens' => 12, 'completion_tokens' => 8, 'total_tokens' => 20 ) ),
			)
		);

		$controller = new Agent_Controller( $fake );
		$result     = $controller->chat( 'List my posts.', array(), 0, 'session-chat-sum', $agent_id );

		$this->assertSame( 2, $result['iterations'] );
		$this->assertSame( 100, $result['tokens_used'], 'chat tokens must sum across both turns (80 + 20)' );
		$this->assertContains( 'list_posts', $result['tools_used'] );
	}
}
