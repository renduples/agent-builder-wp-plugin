<?php
/**
 * Unit tests for the Agent-Ready "Fix now" actions: each fix must change
 * what it promises, and a fix that cannot help must not be offered.
 *
 * @package Agentic\Tests
 */

namespace Agentic\Tests;

use Agentic\Admin_Pages_REST;
use Agentic\Agent_Ready_Score;
use Agentic\Webmcp_Bridge;

/**
 * Test case for the Agent-Ready one-click fixes.
 */
class Test_Agent_Ready_Fixes extends TestCase {

	/**
	 * Active-agents option as it was before the test.
	 *
	 * @var mixed
	 */
	private $saved_active_agents;

	/**
	 * Run as an administrator with the bridge off.
	 */
	public function setUp(): void {
		parent::setUp();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->saved_active_agents = get_option( 'agent_builder_active_agents' );
		delete_option( Webmcp_Bridge::OPTION_ENABLED );
	}

	/**
	 * Restore options.
	 */
	public function tearDown(): void {
		delete_option( Webmcp_Bridge::OPTION_ENABLED );
		update_option( 'agent_builder_active_agents', $this->saved_active_agents );
		parent::tearDown();
	}

	/**
	 * Run one fix through the same REST action the page uses.
	 *
	 * @param string $tool      Fix tool.
	 * @param array  $arguments Tool arguments.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function apply_fix( string $tool, array $arguments = array() ) {
		$request = new \WP_REST_Request( 'POST', '/agentic/v1/admin-page' );
		$request->set_param( 'action_name', 'apply_free_fix' );
		$request->set_param( 'tool_name', $tool );
		$request->set_param( 'arguments', $arguments );
		return Admin_Pages_REST::post_action( $request );
	}

	/**
	 * "Fix now" on the WebMCP check used to expose tools but leave the bridge
	 * off, so the check kept saying "The WebMCP Bridge is turned off".
	 */
	public function test_webmcp_fix_turns_the_bridge_on(): void {
		$this->assertSame(
			'The WebMCP Bridge is turned off.',
			Agent_Ready_Score::rescan()['categories']['webmcp_tools_registered']['detail']
		);

		$response = $this->apply_fix( 'enable_webmcp_defaults' );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertTrue( Webmcp_Bridge::is_enabled() );
		$this->assertNotSame(
			'The WebMCP Bridge is turned off.',
			$response->get_data()['score']['categories']['webmcp_tools_registered']['detail']
		);
	}

	/**
	 * Re-signing manifests cannot activate an agent, so the MCP check must not
	 * offer "Fix now" when no agent is active.
	 */
	public function test_mcp_check_not_fixable_without_active_agents(): void {
		update_option( 'agent_builder_active_agents', array() );
		$check = Agent_Ready_Score::rescan()['categories']['mcp_server_reachable'];

		if ( 'No agents are active yet.' !== $check['detail'] ) {
			$this->markTestSkipped( 'Agents are loaded in this test run; the no-agents branch is not reachable.' );
		}
		$this->assertFalse( $check['fixable'] );
	}

	/**
	 * A fix the executor refuses to run is reported as an error, not as a
	 * silent success.
	 */
	public function test_fix_that_does_not_run_returns_an_error(): void {
		$response = $this->apply_fix( 'enable_agent_readiness', array( 'enabled' => 'not-a-boolean-or-missing' ) );
		if ( $response instanceof \WP_REST_Response ) {
			// Arguments were accepted; force a refusal instead.
			add_filter( 'agent_builder_tool_enforcement', fn() => 'block', 999 );
			$response = $this->apply_fix( 'enable_agent_readiness', array( 'enabled' => true ) );
		}

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'fix_not_applied', $response->get_error_code() );
	}

	/**
	 * Unknown fix tools are still refused.
	 */
	public function test_unknown_fix_is_refused(): void {
		$response = $this->apply_fix( 'delete_post' );
		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'invalid', $response->get_error_code() );
	}
}
