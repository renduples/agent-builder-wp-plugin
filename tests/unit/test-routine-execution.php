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
use Agentic\Agent_Run;
use Agentic\Deployments;
use Agentic\Manifest_Agent;
use Agentic\Notifications;
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
	}

	/**
	 * Drop registered agents, cron, options, and Deployments rows this file wrote.
	 */
	public function tearDown(): void {
		global $wpdb;

		\Agentic_Agent_Registry::get_instance()->unregister( self::AGENT );
		\Agentic_Agent_Registry::get_instance()->unregister( self::TRIGGER_AGENT );

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
	 * A controller whose run_autonomous_task() throws, to exercise the try/finally
	 * user-restore path when a scheduled/async run blows up mid-flight.
	 *
	 * @return Agent_Controller
	 */
	private function throwing_controller(): Agent_Controller {
		return new class extends Agent_Controller {
			public function run_autonomous_task( \Agentic\Agent_Base $agent, string $prompt, string $task_id = '', array $options = array() ): ?array {
				throw new \RuntimeException( 'boom' );
			}
		};
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

	/**
	 * save() delegates to save_user_scheduled_task() and returns the Deployments
	 * row id (not the option-backed task id) the rest of Routines keys on.
	 */
	public function test_save_creates_scheduled_task_routine(): void {
		$result = Routines::save(
			array(
				'kind'       => 'scheduled_task',
				'agent_slug' => self::AGENT,
				'prompt'     => 'Do the thing',
				'schedule'   => 'daily',
			)
		);

		$this->assertTrue( $result['ok'], 'save succeeded' );
		$this->assertIsInt( $result['id'], 'returns the Deployments row id' );

		$row = Deployments::get( $result['id'] );
		$this->assertSame( Deployments::TYPE_SCHEDULED_TASK, $row['type'] );
		$this->assertSame( 'user', $row['config']['source'] );
		$this->assertSame( $result['id'], Routines::deployment_id_for_task( $row['config']['task_id'] ) );
	}

	/**
	 * save() delegates to save_user_trigger() for an event-listener routine.
	 */
	public function test_save_creates_event_listener_routine(): void {
		$result = Routines::save(
			array(
				'kind'       => 'event_listener',
				'agent_slug' => self::TRIGGER_AGENT,
				'hook'       => 'updated_option',
				'prompt'     => 'React to the change',
			)
		);

		$this->assertTrue( $result['ok'], 'save succeeded' );
		$this->assertIsInt( $result['id'], 'returns the Deployments row id' );

		$row = Deployments::get( $result['id'] );
		$this->assertSame( Deployments::TYPE_EVENT_LISTENER, $row['type'] );
		$this->assertSame( $result['id'], Routines::deployment_id_for_trigger( $row['config']['trigger_id'] ) );
	}

	/**
	 * save() with an id passed edits the existing routine and resolves the same
	 * Deployments row id.
	 */
	public function test_save_edits_existing_routine_returns_same_row_id(): void {
		$created = Routines::save(
			array(
				'kind'       => 'scheduled_task',
				'agent_slug' => self::AGENT,
				'prompt'     => 'Original prompt',
				'schedule'   => 'daily',
			)
		);
		$this->assertTrue( $created['ok'] );

		$task_id = Deployments::get( $created['id'] )['config']['task_id'];

		$edited = Routines::save(
			array(
				'kind'       => 'scheduled_task',
				'id'         => $task_id,
				'agent_slug' => self::AGENT,
				'prompt'     => 'Updated prompt',
				'schedule'   => 'daily',
			)
		);

		$this->assertTrue( $edited['ok'] );
		$this->assertSame( $created['id'], $edited['id'], 'edit resolves the same Deployments row id' );
		$this->assertSame( 'Updated prompt', Deployments::get( $edited['id'] )['config']['prompt'] );
	}

	/**
	 * skill_slug and timezone in the args are layered onto the mirror row's config.
	 */
	public function test_save_stores_skill_slug_and_timezone_in_config(): void {
		$result = Routines::save(
			array(
				'kind'       => 'scheduled_task',
				'agent_slug' => self::AGENT,
				'prompt'     => 'Do the thing',
				'skill_slug' => 'my-skill',
				'timezone'   => 'America/New_York',
			)
		);

		$this->assertTrue( $result['ok'] );

		$config = Deployments::get( $result['id'] )['config'];
		$this->assertSame( 'my-skill', $config['skill_slug'] );
		$this->assertSame( 'America/New_York', $config['timezone'] );
	}

	/**
	 * save() bubbles up an error for an unknown kind.
	 */
	public function test_save_rejects_invalid_kind(): void {
		$result = Routines::save( array( 'kind' => 'nonsense' ) );

		$this->assertFalse( $result['ok'] );
	}

	/**
	 * test_run() actually executes a scheduled-task routine once and returns the
	 * run id now recorded on the mirror row.
	 */
	public function test_run_scheduled_task_executes_and_returns_run_id(): void {
		$created = Routines::save(
			array(
				'kind'       => 'scheduled_task',
				'agent_slug' => self::AGENT,
				'prompt'     => 'Do the thing',
				'schedule'   => 'daily',
			)
		);
		$this->assertTrue( $created['ok'] );

		$result = Routines::test_run( $created['id'], 0, new Agent_Controller( $this->fake_llm() ) );

		$this->assertTrue( $result['ok'], 'test_run succeeded' );
		$this->assertNotEmpty( $result['run_id'], 'run_id returned' );
		$this->assertSame( $result['run_id'], Deployments::get( $created['id'] )['config']['last_run_id'] );

		$history = Routines::history( $created['id'] );
		$this->assertCount( 1, $history, 'the run is discoverable via history()' );
		$this->assertSame( $result['run_id'], $history[0]['run_id'] );
	}

	/**
	 * test_run() on an event-listener routine drives handle_async_event() with
	 * synthetic empty hook args and returns the resulting run id.
	 */
	public function test_run_event_listener_executes_and_returns_run_id(): void {
		$created = Routines::save(
			array(
				'kind'       => 'event_listener',
				'agent_slug' => self::TRIGGER_AGENT,
				'hook'       => 'updated_option',
				'prompt'     => 'React to the change',
			)
		);
		$this->assertTrue( $created['ok'] );

		$result = Routines::test_run( $created['id'], 0, new Agent_Controller( $this->fake_llm( 'Reacted.' ) ) );

		$this->assertTrue( $result['ok'], 'test_run succeeded' );
		$this->assertNotEmpty( $result['run_id'], 'run_id returned' );
		$this->assertSame( $result['run_id'], Deployments::get( $created['id'] )['config']['last_run_id'] );
	}

	/**
	 * test_run() on a missing row fails cleanly.
	 */
	public function test_run_missing_routine_errors(): void {
		$result = Routines::test_run( 999999, 0 );

		$this->assertFalse( $result['ok'] );
	}

	/**
	 * A routine whose recorded owner no longer exists is skipped: no run is begun,
	 * the mirror row records an error, and a failure notification is raised.
	 */
	public function test_execute_skipped_when_owner_missing(): void {
		$save = Agent_Lifecycle::save_user_scheduled_task(
			array(
				'agent_slug' => self::AGENT,
				'prompt'     => 'Do the thing',
				'schedule'   => 'daily',
			)
		);
		$this->assertTrue( $save['ok'] );

		$task_id        = $save['id'];
		$deployments_id = Routines::deployment_id_for_task( $task_id );
		Deployments::update_config( $deployments_id, array( 'created_by' => 999999 ) );

		$user_task = Agent_Lifecycle::find_user_scheduled_task( $task_id );
		$agent     = \Agentic_Agent_Registry::get_instance()->get_agent_instance( self::AGENT );

		Agent_Lifecycle::execute_scheduled_task( $agent, Agent_Lifecycle::user_task_to_definition( $user_task ), new Agent_Controller( $this->fake_llm() ) );

		$row = Deployments::get( $deployments_id );
		$this->assertSame( 'error', $row['config']['last_status'], 'missing-owner routine records an error' );
		$this->assertNull( $row['config']['last_run_id'], 'no run id recorded' );
		$this->assertSame( array(), Routines::history( $deployments_id ), 'no run was created' );
		$this->assertNotEmpty( Notifications::list( 999999 ), 'a failure notification is recorded for the former owner' );
	}

	/**
	 * A routine whose recorded owner still exists but lost the
	 * agent_builder_manage_agents capability is skipped the same way.
	 */
	public function test_execute_skipped_when_owner_lacks_capability(): void {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$save = Agent_Lifecycle::save_user_scheduled_task(
			array(
				'agent_slug' => self::AGENT,
				'prompt'     => 'Do the thing',
				'schedule'   => 'daily',
			)
		);
		$this->assertTrue( $save['ok'] );

		$task_id        = $save['id'];
		$deployments_id = Routines::deployment_id_for_task( $task_id );
		Deployments::update_config( $deployments_id, array( 'created_by' => $subscriber ) );

		$user_task = Agent_Lifecycle::find_user_scheduled_task( $task_id );
		$agent     = \Agentic_Agent_Registry::get_instance()->get_agent_instance( self::AGENT );

		Agent_Lifecycle::execute_scheduled_task( $agent, Agent_Lifecycle::user_task_to_definition( $user_task ), new Agent_Controller( $this->fake_llm() ) );

		$row = Deployments::get( $deployments_id );
		$this->assertSame( 'error', $row['config']['last_status'], 'de-privileged owner routine records an error' );
		$this->assertSame( array(), Routines::history( $deployments_id ), 'no run was created' );
		$this->assertNotEmpty( Notifications::list( $subscriber ), 'a failure notification is recorded for the de-privileged owner' );
	}

	/**
	 * A routine with a valid owner runs as that owner on cron: the run is
	 * attributed to the owner (not the cron context user) and tagged cron.
	 */
	public function test_execute_runs_as_owner(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$save = Agent_Lifecycle::save_user_scheduled_task(
			array(
				'agent_slug' => self::AGENT,
				'prompt'     => 'Do the thing',
				'schedule'   => 'daily',
			)
		);
		$this->assertTrue( $save['ok'] );

		$task_id        = $save['id'];
		$deployments_id = Routines::deployment_id_for_task( $task_id );
		Deployments::update_config( $deployments_id, array( 'created_by' => $admin ) );

		$user_task = Agent_Lifecycle::find_user_scheduled_task( $task_id );
		$agent     = \Agentic_Agent_Registry::get_instance()->get_agent_instance( self::AGENT );

		Agent_Lifecycle::execute_scheduled_task( $agent, Agent_Lifecycle::user_task_to_definition( $user_task ), new Agent_Controller( $this->fake_llm() ) );

		$history = Routines::history( $deployments_id );
		$this->assertCount( 1, $history, 'a run was created for the owner' );
		$this->assertSame( $admin, $history[0]['user_id'], 'run is attributed to the routine owner' );
		$this->assertSame( 'cron', $history[0]['invocation'], 'cron invocation is recorded' );
	}

	/**
	 * When no run is actually produced, the completion write records 'skipped'
	 * (with a reason) rather than claiming a run completed.
	 */
	public function test_completion_skipped_when_no_run_produced(): void {
		$save = Agent_Lifecycle::save_user_scheduled_task(
			array(
				'agent_slug' => self::AGENT,
				'prompt'     => 'Do the thing',
				'schedule'   => 'daily',
			)
		);
		$this->assertTrue( $save['ok'] );

		$task_id        = $save['id'];
		$deployments_id = Routines::deployment_id_for_task( $task_id );

		// A task definition with no prompt / tool / callback produces no run at all.
		$task  = array(
			'id'       => $task_id,
			'name'     => 'No-run task',
			'schedule' => 'daily',
		);
		$agent = \Agentic_Agent_Registry::get_instance()->get_agent_instance( self::AGENT );

		Agent_Lifecycle::execute_scheduled_task( $agent, $task, new Agent_Controller( $this->fake_llm() ) );

		$row = Deployments::get( $deployments_id );
		$this->assertSame( 'skipped', $row['config']['last_status'], 'no-run outcome is skipped, not completed' );
		$this->assertSame( 'no_run_produced', $row['config']['last_skip_reason'] );
		$this->assertNull( $row['config']['last_run_id'] );
	}

	/**
	 * test_run() reports ok:false when the execution produced no run id.
	 */
	public function test_run_returns_not_ok_when_no_run_id(): void {
		$created = Routines::save(
			array(
				'kind'       => 'scheduled_task',
				'agent_slug' => self::AGENT,
				'prompt'     => 'Do the thing',
				'schedule'   => 'daily',
			)
		);
		$this->assertTrue( $created['ok'] );

		// An unconfigured LLM client forces run_autonomous_task() to return null
		// before a run is begun, so test_run() has no run id to report.
		$unconfigured = new class extends \Agentic\LLM_Client {
			public function is_configured(): bool {
				return false;
			}
		};

		$result = Routines::test_run( $created['id'], 0, new Agent_Controller( $unconfigured ) );

		$this->assertFalse( $result['ok'], 'test_run reports failure when no run was produced' );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertSame( 'skipped', Deployments::get( $created['id'] )['config']['last_status'] );
	}

	/**
	 * A manual test-run of a paused routine is allowed (paused only blocks
	 * scheduled/hook dispatches) and produces a fresh run.
	 */
	public function test_run_paused_routine_is_allowed_and_produces_run(): void {
		$created = Routines::save(
			array(
				'kind'       => 'scheduled_task',
				'agent_slug' => self::AGENT,
				'prompt'     => 'Do the thing',
				'schedule'   => 'daily',
			)
		);
		$this->assertTrue( $created['ok'] );

		Routines::pause( $created['id'] );

		$result = Routines::test_run( $created['id'], 0, new Agent_Controller( $this->fake_llm() ) );

		$this->assertTrue( $result['ok'], 'a manual test-run of a paused routine is allowed' );
		$this->assertNotEmpty( $result['run_id'], 'the test-run produces a fresh run id' );
		$this->assertSame( $result['run_id'], Deployments::get( $created['id'] )['config']['last_run_id'] );
	}

	/**
	 * A paused routine that produced a run earlier must not have that prior run id
	 * reported as a fresh success when a later manual test-run produces no run.
	 */
	public function test_run_paused_routine_does_not_report_stale_success(): void {
		$created = Routines::save(
			array(
				'kind'       => 'scheduled_task',
				'agent_slug' => self::AGENT,
				'prompt'     => 'Do the thing',
				'schedule'   => 'daily',
			)
		);
		$this->assertTrue( $created['ok'] );

		// Produce one real run so the mirror row carries a prior run id.
		$first = Routines::test_run( $created['id'], 0, new Agent_Controller( $this->fake_llm() ) );
		$this->assertTrue( $first['ok'] );
		$this->assertNotEmpty( Deployments::get( $created['id'] )['config']['last_run_id'], 'first run sets last_run_id' );

		Routines::pause( $created['id'] );

		// An unconfigured LLM produces no run, so test_run() must not report the
		// prior run id as a fresh success.
		$unconfigured = new class extends \Agentic\LLM_Client {
			public function is_configured(): bool {
				return false;
			}
		};

		$result = Routines::test_run( $created['id'], 0, new Agent_Controller( $unconfigured ) );

		$this->assertFalse( $result['ok'], 'a paused test-run that produced no run must not report stale success' );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertSame( 'skipped', Deployments::get( $created['id'] )['config']['last_status'] );
		$this->assertNull( Deployments::get( $created['id'] )['config']['last_run_id'] );
	}

	/**
	 * When a scheduled run throws, execute_scheduled_task() still restores the
	 * cron-context user in its finally, instead of leaving the owner impersonated.
	 */
	public function test_execute_restores_current_user_when_run_throws(): void {
		$owner     = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$cron_user = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$save = Agent_Lifecycle::save_user_scheduled_task(
			array(
				'agent_slug' => self::AGENT,
				'prompt'     => 'Do the thing',
				'schedule'   => 'daily',
			)
		);
		$this->assertTrue( $save['ok'] );

		$task_id        = $save['id'];
		$deployments_id = Routines::deployment_id_for_task( $task_id );
		Deployments::update_config( $deployments_id, array( 'created_by' => $owner ) );

		$user_task = Agent_Lifecycle::find_user_scheduled_task( $task_id );
		$agent     = \Agentic_Agent_Registry::get_instance()->get_agent_instance( self::AGENT );

		wp_set_current_user( $cron_user );

		try {
			Agent_Lifecycle::execute_scheduled_task( $agent, Agent_Lifecycle::user_task_to_definition( $user_task ), $this->throwing_controller() );

			$this->assertSame( $cron_user, get_current_user_id(), 'the cron-context user is restored after the run throws' );
		} finally {
			wp_set_current_user( 0 );
		}
	}

	/**
	 * When an async-event run throws, handle_async_event() still restores the
	 * hook-context user in its finally, instead of leaving the owner impersonated.
	 */
	public function test_handle_async_event_restores_current_user(): void {
		$owner     = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$cron_user = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$save = Agent_Lifecycle::save_user_trigger(
			array(
				'agent_slug' => self::TRIGGER_AGENT,
				'hook'       => 'updated_option',
				'prompt'     => 'React to the change',
			)
		);
		$this->assertTrue( $save['ok'] );

		$trigger_id     = $save['id'];
		$deployments_id = Routines::deployment_id_for_trigger( $trigger_id );
		Deployments::update_config( $deployments_id, array( 'created_by' => $owner ) );

		wp_set_current_user( $cron_user );

		try {
			Agent_Lifecycle::handle_async_event(
				self::TRIGGER_AGENT,
				$trigger_id,
				'React to the change',
				array( 'some_option', 'old', 'new' ),
				$this->throwing_controller()
			);

			$this->assertSame( $cron_user, get_current_user_id(), 'the hook-context user is restored after the run throws' );
		} finally {
			wp_set_current_user( 0 );
		}
	}
}
