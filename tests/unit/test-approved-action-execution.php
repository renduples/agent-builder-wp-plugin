<?php
/**
 * Unit Tests for REST_API::execute_approved_action()'s generic branch.
 *
 * The classic Approval_Queue approve action routes its generic (non-code_change)
 * branch through Tool_Executor::execute_approved(), which runs the already
 * approved tool via the tool_loader → agent-inline → abilities-bridge chain,
 * logs non-readonly tools to the operations ledger, and fires
 * agent_builder_tool_executed. This file asserts that routing; the hostile
 * code_change write path is covered by Test_Code_Change_Approval.
 *
 * @package Agentic\Tests
 */

namespace Agentic\Tests;

use Agentic\Approval_Queue;
use Agentic\REST_API;
use Agentic\Risk_Level;
use ReflectionMethod;

/**
 * Test case for the generic execute_approved_action() branch.
 */
class Test_Approved_Action_Execution extends TestCase {

	/**
	 * REST_API instance (constructed without hooks).
	 *
	 * @var REST_API
	 */
	private REST_API $api;

	/**
	 * Reflected handler under test.
	 *
	 * @var ReflectionMethod
	 */
	private ReflectionMethod $handler;

	/**
	 * Setup.
	 */
	public function setUp(): void {
		parent::setUp();

		// newInstanceWithoutConstructor() so the test does not register the
		// plugin's REST routes a second time inside the test request.
		$this->api = ( new \ReflectionClass( REST_API::class ) )->newInstanceWithoutConstructor();

		// No setAccessible() call: since PHP 8.1 reflection can invoke a
		// private method directly, and the method is deprecated as of 8.5.
		$this->handler = new ReflectionMethod( REST_API::class, 'execute_approved_action' );
	}

	/**
	 * A generic (non-code_change) approval actually runs the tool, fires
	 * agent_builder_tool_executed once, and logs the non-readonly tool to the
	 * operations ledger — proving the branch routes through execute_approved().
	 */
	public function test_generic_approval_runs_tool_and_fires_executed_hook(): void {
		$calls    = 0;
		$listener = static function () use ( &$calls ) {
			++$calls;
		};
		add_action( 'agent_builder_tool_executed', $listener, 10, 4 );

		$result = $this->handler->invoke(
			$this->api,
			array(
				'id'         => 4242,
				'action'     => 'db_update_option',
				'params'     => wp_json_encode( array( 'name' => 'agent_builder_test_rest_opt', 'value' => 'rest-value' ) ),
				'agent_id'   => 'test-agent',
				'risk_level' => Risk_Level::HIGH,
				'mode'       => 'supervised',
				'invocation' => 'chat',
			)
		);

		remove_action( 'agent_builder_tool_executed', $listener, 10 );

		$this->assertTrue( $result['ran'] );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'rest-value', get_option( 'agent_builder_test_rest_opt' ) );
		$this->assertSame( 1, $calls, 'execute_approved() must raise the executed hook once' );

		$queue  = new Approval_Queue();
		$recent = $queue->get_recent( array( 'agent_id' => 'test-agent' ) );
		$this->assertContains( 'db_update_option', array_column( $recent, 'action' ) );
	}
}
