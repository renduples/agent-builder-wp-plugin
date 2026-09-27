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
