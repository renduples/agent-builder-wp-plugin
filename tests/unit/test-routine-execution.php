<?php
/**
 * End-to-end tests for routine execution wiring (M14-b).
 *
 * Proves the two things this task fixes:
 *   1. execute_scheduled_task() / handle_async_event() write a `routine:<id>`
 *      source_ref keyed on the Deployments row's integer id, so
 *      Routines::history() actually finds the runs they create.
 *   2. Both paths write last_run / last_status / last_run_id back into the
 *      Deployments mirror row on completion.
 *
 * Each test drives the real execution path end to end through the optional
 * Agent_Controller injection seam (backed by a Fake_LLM_Client), so a run is
 * genuinely begun, finished, and discoverable via history().
 *
 * @package Agentic\Tests
 */

namespace Agentic\Tests;

use Agentic\Agent_Controller;
use Agentic\Agent_Lifecycle;
use Agentic\Agent_Permissions;
use Agentic\Agent_Run;
use Agentic\Deployments;
use Agentic\Manifest_Agent;
use Agentic\Routines;

/**
 * Covers the M14-b execution-wiring fix across scheduled tasks and event listeners.
 */
class Test_Routine_Execution extends TestCase {

	/** Agent slug used by the scheduled-task tests. */
	private const AGENT = 'routine-exec-agent';

	/** Agent slug used by the event-listener tests. */
	private const TRIGGER_AGENT = 'routine-trigger-agent';

	/**
	 * Register the agents the dual-write paths resolve by slug.
	 */
	public function setUp(): void {
		parent::setUp();
		\Agentic_Agent_Registry::get_instance()->register( $this->make_agent( self::AGENT ) );
		\Agentic_Agent_Registry::get_instance()->register( $this->make_agent( self::TRIGGER_AGENT ) );
		Agent_Permissions::set_mode_override( null );
	}

	/**
	 * Drop registered agents, cron, options, and Deployments rows this file wrote.
	 */
	public function tearDown(): void {
		global $wpdb;

		\Agentic_Agent_Registry::get_instance()->unregister( self::AGENT );
		\Agentic_Agent_Registry::get_instance()->unregister( self::TRIGGER_AGENT );
		Agent_Permissions::set_mode_override( null );

		$wpdb->query( "DELETE FROM {$wpdb->prefix}agent_builder_deployments" );

		delete_option( Agent_Lifecycle::USER_SCHEDULED_TASKS_OPTION );
		delete_option( Agent_Lifecycle::USER_EVENT_TRIGGERS_OPTION );

		$cron = _get_cron_array();
		if ( is_array( $cron ) ) {
			foreach ( $cron as $events ) {
				foreach ( $events as $hook => $args ) {
					if ( 0 === strpos( (string) $hook, 'agentic_task_' ) ) {
						wp_clear_scheduled_hook( $hook );
					}
				}
			}
		}

		parent::tearDown();
	}

	/**
	 * Minimal Manifest_Agent test double, registered with the shared registry.
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
	 * A single scripted text response that lets run_autonomous_task() complete a
	 * run in one iteration, offline.
	 *
	 * @return Fake_LLM_Client
	 */
	private function fake_llm( string $content = 'Done.' ): Fake_LLM_Client {
		return new Fake_LLM_Client( array( Fake_LLM_Client::text_response( $content ) ) );
	}

	/**
	 * execute_scheduled_task() writes the run with a `routine:<deployments-id>`
	 * source_ref so history() finds it, and stamps the mirror row's last_run.
	 */
	public function test_execute_scheduled_task_writes_history_and_last_run(): void {
		$save = Agent_Lifecycle::save_user_scheduled_task(
			array(
				'agent_slug' => self::AGENT,
				'prompt'     => 'Do the thing',
				'schedule'   => 'daily',
			)
		);
		$this->assertTrue( $save['ok'], 'task saved' );

		$task_id        = $save['id'];
		$deployments_id = Routines::deployment_id_for_task( $task_id );
		$this->assertIsInt( $deployments_id, 'mirror row linked by option-backed task id' );

		$user_task = Agent_Lifecycle::find_user_scheduled_task( $task_id );
		$task      = Agent_Lifecycle::user_task_to_definition( $user_task );
		$agent     = \Agentic_Agent_Registry::get_instance()->get_agent_instance( self::AGENT );

		Agent_Lifecycle::execute_scheduled_task( $agent, $task, new Agent_Controller( $this->fake_llm() ) );

		$history = Routines::history( $deployments_id );
		$this->assertCount( 1, $history, 'history() finds the run just created' );
		$this->assertSame( 'routine:' . $deployments_id, $history[0]['source_ref'] );

		$row = Deployments::get( $deployments_id );
		$this->assertNotNull( $row['config']['last_run'], 'last_run stamped' );
		$this->assertSame( 'completed', $row['config']['last_status'] );
		$this->assertSame( $history[0]['run_id'], $row['config']['last_run_id'] );
	}

	/**
	 * handle_async_event() writes the run with a `routine:<deployments-id>`
	 * source_ref (not the old `listener:` prefix) so history() finds it.
	 */
	public function test_handle_async_event_writes_history_and_last_run(): void {
		$save = Agent_Lifecycle::save_user_trigger(
			array(
				'agent_slug' => self::TRIGGER_AGENT,
				'hook'       => 'updated_option',
				'prompt'     => 'React to the change',
			)
		);
		$this->assertTrue( $save['ok'], 'trigger saved' );

		$trigger_id     = $save['id'];
		$deployments_id = Routines::deployment_id_for_trigger( $trigger_id );
		$this->assertIsInt( $deployments_id, 'mirror row linked by option-backed trigger id' );

		Agent_Lifecycle::handle_async_event(
			self::TRIGGER_AGENT,
			$trigger_id,
			'React to the change',
			array( 'some_option', 'old', 'new' ),
			new Agent_Controller( $this->fake_llm( 'Reacted.' ) )
		);

		$history = Routines::history( $deployments_id );
		$this->assertCount( 1, $history, 'history() finds the run just created' );
		$this->assertSame( 'routine:' . $deployments_id, $history[0]['source_ref'] );

		$row = Deployments::get( $deployments_id );
		$this->assertNotNull( $row['config']['last_run'], 'last_run stamped' );
		$this->assertSame( 'completed', $row['config']['last_status'] );
		$this->assertSame( $history[0]['run_id'], $row['config']['last_run_id'] );
	}

	/**
	 * A built-in/code-sourced task with no Deployments mirror keeps the string-id
	 * source_ref and is untouched by the completion write.
	 */
	public function test_builtin_task_keeps_string_source_ref(): void {
		$agent = \Agentic_Agent_Registry::get_instance()->get_agent_instance( self::AGENT );
		$task  = array(
			'id'       => 'builtin_task',
			'name'     => 'Built-in task',
			'schedule' => 'daily',
			'prompt'   => 'Do the built-in thing',
		);

		Agent_Lifecycle::execute_scheduled_task( $agent, $task, new Agent_Controller( $this->fake_llm() ) );

		$this->assertNull( Routines::deployment_id_for_task( 'builtin_task' ), 'no mirror row for a built-in task' );

		$runs = Agent_Run::query( array( 'source_ref' => 'routine:builtin_task' ) );
		$this->assertCount( 1, $runs, 'run keeps the string-id source_ref' );
	}

	/**
	 * Lookups return null for ids with no mirror row (built-in task and manifest
	 * listener alike).
	 */
	public function test_lookups_return_null_when_no_mirror_row(): void {
		$this->assertNull( Routines::deployment_id_for_task( 'us_missing' ) );
		$this->assertNull( Routines::deployment_id_for_trigger( 'ut_missing' ) );
	}
}
