<?php
/**
 * Unit Tests for the M10b run_autonomous_task() guards.
 *
 * Full behavioral coverage of the autonomous tool-calling loop needs a fake
 * LLM client (Fake_LLM_Client, dispatched separately as 10g) and lands in
 * test-agent-controller-autonomous.php. This file covers only what is
 * testable without invoking a live LLM: the fail-fast disabled-agent guard,
 * exercised via the __construct(?LLM_Client $llm) injection seam so we can
 * assert the LLM is never called.
 *
 * @package Agentic\Tests
 */

namespace Agentic\Tests;

use Agentic\Agent_Controller;
use Agentic\Agent_Run;
use Agentic\Agent_Settings;
use Agentic\LLM_Client;
use Agentic\Manifest_Agent;

/**
 * Test case for Agent_Controller::run_autonomous_task()'s mode guard.
 */
class Test_Agent_Controller_Autonomous_Guards extends TestCase {

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
	 * An agent whose per-agent override_mode is 'disabled' must fail fast —
	 * before any LLM call — regardless of whether the LLM is configured.
	 */
	public function test_disabled_override_mode_fails_fast_without_llm_call(): void {
		$agent_id = 'test-autonomous-disabled-agent';
		Agent_Settings::update( $agent_id, 'override_mode', 'disabled' );

		$llm = $this->createMock( LLM_Client::class );
		$llm->method( 'is_configured' )->willReturn( true );
		$llm->expects( $this->never() )->method( 'chat' );

		$controller = new Agent_Controller( $llm );
		$result     = $controller->run_autonomous_task( $this->make_agent( $agent_id ), 'Do the thing.', 'task-1' );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['error'] );
		$this->assertSame( 'error', $result['status'] );
		$this->assertSame( '', $result['run_id'] );
		$this->assertSame( array(), $result['cards'] );

		// No run row should have been created for a task that never started.
		$this->assertSame( array(), Agent_Run::query( array( 'agent' => $agent_id ) ) );

		Agent_Settings::delete_agent( $agent_id );
	}

	/**
	 * An agent with no override (or a non-disabled override) proceeds to the
	 * LLM configuration check as before — the guard only trips on 'disabled'.
	 */
	public function test_non_disabled_override_mode_proceeds_past_the_guard(): void {
		$agent_id = 'test-autonomous-enabled-agent';

		$llm = $this->createMock( LLM_Client::class );
		$llm->method( 'is_configured' )->willReturn( false );
		$llm->expects( $this->never() )->method( 'chat' );

		$controller = new Agent_Controller( $llm );
		$result     = $controller->run_autonomous_task( $this->make_agent( $agent_id ), 'Do the thing.', 'task-2' );

		// Falls through to the existing "LLM not configured" contract (null),
		// proving the disabled-mode guard did not fire for this agent.
		$this->assertNull( $result );
	}
}
