<?php
/**
 * Unit Tests for Agent_Controller SSE streaming events (live pane, M16-b).
 *
 * Drives chat() through the streaming path (enable_streaming + Fake_LLM_Client's
 * stream_chat() shim) and asserts on the emitted tool_end and gate_decision
 * events the live activity pane renders:
 *
 *  - tool_end carries a real success flag derived from the tool result (not a
 *    hardcoded true) plus a short human-readable summary.
 *  - gate_decision fires at the point the approval gate resolves a gated call
 *    (confirm/queue/block) and stays silent for an allowed call.
 *
 * @package Agentic\Tests
 */

namespace Agentic\Tests;

use Agentic\Agent_Controller;
use Agentic\Manifest_Agent;

/**
 * Test case for Agent_Controller streaming events.
 */
class Test_Agent_Controller_Streaming extends TestCase {

	/**
	 * Events emitted during the test's chat() call, in order.
	 *
	 * @var array<int, array{type: string, data: mixed}>
	 */
	private array $events = array();

	/**
	 * Register a minimal agent and enable anonymous chat, as the autonomous
	 * chat-sum test does, so chat() accepts the agent.
	 *
	 * @param string $slug Agent slug.
	 * @return Manifest_Agent
	 */
	private function make_agent( string $slug ): Manifest_Agent {
		$agent = new Manifest_Agent(
			array(
				'slug' => $slug,
				'name' => 'Test Agent',
			),
			''
		);

		\Agentic_Agent_Registry::get_instance()->register( $agent );
		update_option( 'agent_builder_allow_anonymous_chat', true );

		return $agent;
	}

	/**
	 * Run chat() in streaming mode, recording every emitted event.
	 *
	 * @param Fake_LLM_Client $fake  Scripted LLM double.
	 * @param string          $agent_id Agent slug.
	 * @return array The chat() result.
	 */
	private function run_streaming_chat( Fake_LLM_Client $fake, string $agent_id ): array {
		$this->events = array();

		// Run as an administrator so read-only tools (e.g. list_posts) satisfy
		// the native ability permission check and return a real success result.
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$controller = new Agent_Controller( $fake );
		$controller->enable_streaming(
			function ( string $type, mixed $data ): void {
				$this->events[] = array(
					'type' => $type,
					'data' => $data,
				);
			}
		);

		return $controller->chat( 'Run the tool.', array(), 0, 'session-streaming', $agent_id );
	}

	/**
	 * Return the first emitted event of a given type, or null when absent.
	 *
	 * @param string $type Event type.
	 * @return array{type: string, data: mixed}|null
	 */
	private function first_event( string $type ): ?array {
		foreach ( $this->events as $event ) {
			if ( $type === $event['type'] ) {
				return $event;
			}
		}
		return null;
	}

	/**
	 * Clear options the streaming path may have left behind.
	 */
	public function tearDown(): void {
		delete_option( 'agent_builder_allow_anonymous_chat' );
		parent::tearDown();
	}

	/**
	 * A successful (LOW-risk) tool call emits tool_end with success=true and a
	 * human-readable summary, derived from the real result rather than hardcoded.
	 */
	public function test_tool_end_reports_success_for_successful_tool(): void {
		$agent_id = 'test-streaming-success';
		$this->make_agent( $agent_id );

		$fake = new Fake_LLM_Client(
			array(
				Fake_LLM_Client::tool_call_response( 'list_posts', array() ),
				Fake_LLM_Client::text_response( 'Listed posts.' ),
			)
		);

		$this->run_streaming_chat( $fake, $agent_id );

		$tool_end = $this->first_event( 'tool_end' );
		$this->assertNotNull( $tool_end, 'a tool_end event must be emitted' );
		$this->assertSame( 'list_posts', $tool_end['data']['name'] );
		$this->assertTrue( $tool_end['data']['success'], 'a successful tool result must report success=true' );
		$this->assertSame( 'list_posts', $tool_end['data']['summary'], 'a tool with no summarizable arguments falls back to its name' );
	}

	/**
	 * A failing tool call emits tool_end with success=false — proving the flag
	 * reflects the real result, not a hardcoded true.
	 */
	public function test_tool_end_reports_failure_for_failing_tool(): void {
		$agent_id = 'test-streaming-failure';
		$this->make_agent( $agent_id );

		$fake = new Fake_LLM_Client(
			array(
				Fake_LLM_Client::tool_call_response( 'get_post_content', array( 'post_id' => 999999 ) ),
				Fake_LLM_Client::text_response( 'Could not read that post.' ),
			)
		);

		$this->run_streaming_chat( $fake, $agent_id );

		$tool_end = $this->first_event( 'tool_end' );
		$this->assertNotNull( $tool_end, 'a tool_end event must be emitted' );
		$this->assertSame( 'get_post_content', $tool_end['data']['name'] );
		$this->assertFalse( $tool_end['data']['success'], 'a failing tool result must report success=false' );
		$this->assertSame( 'post_id: 999999', $tool_end['data']['summary'] );
	}

	/**
	 * A MEDIUM-risk call gated to confirm emits a gate_decision event carrying
	 * the tool name and the resolved decision, at the point the gate resolves.
	 */
	public function test_gate_decision_fires_for_confirm(): void {
		$agent_id = 'test-streaming-gate';
		$this->make_agent( $agent_id );

		$fake = new Fake_LLM_Client(
			array(
				Fake_LLM_Client::tool_call_response( 'purge_expired_transients', array() ),
			)
		);

		$this->run_streaming_chat( $fake, $agent_id );

		$gate = $this->first_event( 'gate_decision' );
		$this->assertNotNull( $gate, 'a gated tool call must emit gate_decision' );
		$this->assertSame( 'purge_expired_transients', $gate['data']['tool'] );
		$this->assertSame( 'confirm', $gate['data']['decision'] );
	}

	/**
	 * An allowed (LOW-risk) call runs straight through and emits no
	 * gate_decision — its progress is conveyed by tool_start/tool_end alone.
	 */
	public function test_no_gate_decision_for_allowed_tool(): void {
		$agent_id = 'test-streaming-allow';
		$this->make_agent( $agent_id );

		$fake = new Fake_LLM_Client(
			array(
				Fake_LLM_Client::tool_call_response( 'list_posts', array() ),
				Fake_LLM_Client::text_response( 'Listed posts.' ),
			)
		);

		$this->run_streaming_chat( $fake, $agent_id );

		$this->assertNull( $this->first_event( 'gate_decision' ), 'an allowed tool call must not emit gate_decision' );
		$this->assertNotNull( $this->first_event( 'tool_end' ), 'an allowed tool call must still emit tool_end' );
	}
}
