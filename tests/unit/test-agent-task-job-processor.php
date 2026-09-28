<?php
/**
 * Unit Tests for Agent_Task_Job_Processor (M10d2).
 *
 * Covers the dispatch() → Job_Manager plumbing (correct _processor + request
 * shape), the allowlist registration, and the processor's run-loading-first
 * behaviour (fails cleanly when the run row is missing, without crashing).
 *
 * The autonomous LLM loop itself is out of scope here (Fake_LLM_Client is a
 * later slice); these tests stop at the run/agent resolution boundary.
 *
 * @package Agentic\Tests
 */

namespace Agentic\Tests;

use Agentic\Agent_Controller;
use Agentic\Agent_Run;
use Agentic\Agent_Task_Job_Processor;
use Agentic\Job_Manager;
use Agentic\Manifest_Agent;
use Agentic\Provider_Registry;

/**
 * Test case for Agent_Task_Job_Processor.
 */
class Test_Agent_Task_Job_Processor extends TestCase {

	/**
	 * Reset the in-process run and clear the jobs table before each test.
	 */
	public function setUp(): void {
		parent::setUp();
		Agent_Run::reset_current_for_tests();

		global $wpdb;
		$jobs_table = $wpdb->prefix . 'agent_builder_jobs';
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$jobs_table}'" ) === $jobs_table ) {
			$wpdb->query( "DELETE FROM {$jobs_table}" );
		}
	}

	/**
	 * Leave no provider API key behind for other tests.
	 */
	public function tearDown(): void {
		Provider_Registry::save_api_key( 'agentic', '' );
		Provider_Registry::invalidate();
		parent::tearDown();
	}

	/**
	 * dispatch() creates a pending job carrying this processor and the full
	 * request shape derived from the run.
	 */
	public function test_dispatch_creates_job_with_processor_and_request_shape(): void {
		$run = Agent_Run::create_queued(
			'wordpress-assistant',
			array(
				'kind'       => 'routine',
				'user_id'    => 7,
				'task_text'  => 'Summarise the newest posts',
				'source_ref' => 'routine:12',
			)
		);

		$job_id = Agent_Task_Job_Processor::dispatch( $run );

		$this->assertNotSame( '', $job_id );

		$job = Job_Manager::get_job( $job_id );
		$this->assertNotNull( $job );
		$this->assertSame( Job_Manager::STATUS_PENDING, $job->status );

		$request = $job->request_data;
		$this->assertSame( Agent_Task_Job_Processor::class, $request['_processor'] );
		$this->assertSame( $run->get_run_id(), $request['run_id'] );
		$this->assertSame( 'wordpress-assistant', $request['agent_id'] );
		$this->assertSame( 'Summarise the newest posts', $request['prompt'] );
		$this->assertSame( 7, $request['user_id'] );
		$this->assertSame( 'routine', $request['kind'] );
		$this->assertSame( 'routine:12', $request['source_ref'] );
	}

	/**
	 * dispatch() merges the $extra payload into the request, so resume /
	 * tool_result (and any override) reach the processor.
	 */
	public function test_dispatch_merges_extra_payload(): void {
		$run = Agent_Run::create_queued(
			'wordpress-assistant',
			array(
				'kind'      => 'task',
				'task_text' => 'Publish a draft',
			)
		);

		$extra = array(
			'resume'      => array( 'messages' => array( array( 'role' => 'user', 'content' => 'hi' ) ) ),
			'tool_result' => array( 'tool' => 'db_create_post', 'result' => array( 'id' => 1 ) ),
		);

		$job_id = Agent_Task_Job_Processor::dispatch( $run, $extra );

		$job     = Job_Manager::get_job( $job_id );
		$request = $job->request_data;

		$this->assertSame( $extra['resume'], $request['resume'] );
		$this->assertSame( $extra['tool_result'], $request['tool_result'] );
	}

	/**
	 * The processor is registered on the agent_builder_job_processors allowlist.
	 */
	public function test_processor_is_on_the_allowlist(): void {
		$allowed = apply_filters(
			'agent_builder_job_processors',
			array( \Agentic\Agent_Builder_Job_Processor::class )
		);

		$this->assertContains( Agent_Task_Job_Processor::class, $allowed );
	}

	/**
	 * A misbehaving earlier-priority agent_builder_job_processors callback that
	 * returns a non-array does not crash this processor's own callback (which
	 * would previously throw a TypeError from its `array` type hint before
	 * Job_Manager's fallback could run): the value is cast back to an array and
	 * self::class is still appended.
	 */
	public function test_init_callback_survives_non_array_from_earlier_filter(): void {
		$callback = function () {
			return 'not-an-array';
		};
		add_filter( 'agent_builder_job_processors', $callback, 5 );

		try {
			$allowed = apply_filters(
				'agent_builder_job_processors',
				array( \Agentic\Agent_Builder_Job_Processor::class )
			);
		} finally {
			remove_filter( 'agent_builder_job_processors', $callback, 5 );
		}

		$this->assertIsArray( $allowed );
		$this->assertContains( Agent_Task_Job_Processor::class, $allowed );
	}

	/**
	 * Regression: when an earlier-priority filter returns a non-array, the
	 * callback restores the built-in default allowlist entry rather than an
	 * empty array, so Agent_Builder_Job_Processor's own jobs are not dropped
	 * and later rejected as "processor not allowed".
	 */
	public function test_init_callback_preserves_default_allowlist_on_non_array_from_earlier_filter(): void {
		$callback = function () {
			return 'not-an-array';
		};
		add_filter( 'agent_builder_job_processors', $callback, 5 );

		try {
			$allowed = apply_filters(
				'agent_builder_job_processors',
				array( \Agentic\Agent_Builder_Job_Processor::class )
			);
		} finally {
			remove_filter( 'agent_builder_job_processors', $callback, 5 );
		}

		$this->assertIsArray( $allowed );
		$this->assertContains( \Agentic\Agent_Builder_Job_Processor::class, $allowed );
		$this->assertContains( Agent_Task_Job_Processor::class, $allowed );
	}

	/**
	 * The processor resolves the Agent_Run by run_id before doing anything
	 * else: a missing run fails the job cleanly (status failed, "Run not
	 * found"), and does not crash or get rejected by the allowlist.
	 */
	public function test_missing_run_fails_cleanly(): void {
		$job_id = Job_Manager::create_job(
			array(
				'user_id'  => 1,
				'agent_id' => 'wordpress-assistant',
				'request_data' => array(
					'run_id'     => 'does-not-exist',
					'agent_id'   => 'wordpress-assistant',
					'prompt'     => 'Summarise posts',
					'user_id'    => 1,
					'kind'       => 'task',
					'source_ref' => '',
				),
				'processor' => Agent_Task_Job_Processor::class,
			)
		);

		Job_Manager::process_job( $job_id );

		$job = Job_Manager::get_job( $job_id );
		$this->assertNotNull( $job );
		$this->assertSame( Job_Manager::STATUS_FAILED, $job->status );
		$this->assertSame( 'Run not found: does-not-exist', $job->error_message );

		// It passed the allowlist and reached run resolution — it was not
		// rejected as a disallowed processor.
		$this->assertStringNotContainsString( 'processor not allowed', $job->error_message );
	}

	/**
	 * A resume request the controller's ownership/status guard rejects
	 * (here: agent mismatch) must not finish() the target run — it may
	 * still be legitimately waiting elsewhere, and a guard tripping on a
	 * stale/mismatched job is not this call's run to finalize.
	 */
	public function test_guard_rejected_resume_leaves_target_run_waiting(): void {
		Provider_Registry::save_api_key( 'agentic', 'test-relay-key' );
		Provider_Registry::invalidate();

		$admin_id    = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$owner_id    = 'test-job-processor-guard-owner';
		$mismatch_id = 'test-job-processor-guard-mismatched';
		$registry    = \Agentic_Agent_Registry::get_instance();
		$registry->register( $this->make_agent( $owner_id ) );
		$registry->register( $this->make_agent( $mismatch_id ) );

		$run = Agent_Run::begin(
			$owner_id,
			array(
				'kind'      => 'task',
				'user_id'   => $admin_id,
				'task_text' => 'Do something.',
			)
		);
		$run->mark_waiting( 'approval', 'appr-1', array(), 'call_abc' );
		$run_id       = $run->get_run_id();
		$resume_state = $run->resume_state();
		Agent_Run::reset_current_for_tests();

		$job_id = Job_Manager::create_job(
			array(
				'user_id'      => 1,
				'agent_id'     => $mismatch_id,
				'request_data' => array(
					'run_id'     => $run_id,
					'agent_id'   => $mismatch_id, // Deliberately not $owner_id.
					'prompt'     => 'Do something.',
					'user_id'    => 1,
					'kind'       => 'task',
					'source_ref' => '',
					'resume'     => $resume_state,
				),
				'processor' => Agent_Task_Job_Processor::class,
			)
		);

		Job_Manager::process_job( $job_id );

		$run_after = Agent_Run::load( $run_id );
		$this->assertSame(
			'waiting',
			$run_after->to_array()['status'],
			'a guard-rejected resume must not finish() the target run'
		);
	}

	/**
	 * A fresh dispatch → execute round trip must adopt the run dispatch()
	 * created (not begin() a second row that strands the dispatched one at
	 * 'running'), carry the assigning user id through to the completed run,
	 * and finish 'completed'.
	 */
	public function test_fresh_dispatch_adopts_dispatched_run_and_completes(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$agent_id = 'wordpress-assistant';
		$registry = \Agentic_Agent_Registry::get_instance();
		$registry->register( $this->make_agent( $agent_id ) );

		// dispatch() creates the run up front via create_queued() (as the Tasks
		// screen does) so the job's run_id refers to a real, not-yet-started
		// 'queued' row that the worker adopts.
		$run = Agent_Run::create_queued(
			$agent_id,
			array(
				'kind'      => 'task',
				'user_id'   => $admin_id,
				'task_text' => 'Summarise the newest posts',
			)
		);
		$run_id = $run->get_run_id();

		$job_id = Agent_Task_Job_Processor::dispatch( $run );
		$job    = Job_Manager::get_job( $job_id );

		// Simulate a separate background process: the run is not "current" here.
		Agent_Run::reset_current_for_tests();

		$controller = new Agent_Controller(
			new Fake_LLM_Client(
				array( Fake_LLM_Client::text_response( 'Done.' ) )
			)
		);

		$result = ( new Agent_Task_Job_Processor() )->execute(
			$job->request_data,
			static function ( $progress, $message ) {},
			$controller
		);

		$this->assertSame( $run_id, $result['run_id'] );
		$this->assertSame( 'completed', $result['status'] );

		$reloaded = Agent_Run::load( $run_id );
		$this->assertSame( 'completed', $reloaded->to_array()['status'] );
		$this->assertSame( $admin_id, $reloaded->to_array()['user_id'], 'the assigning admin user id must be preserved' );

		// Exactly one run row for this agent: the dispatched row was adopted,
		// not stranded while a second begin() row was created.
		global $wpdb;
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}agent_builder_runs WHERE root_agent = %s",
				$agent_id
			)
		);
		$this->assertSame( 1, $count, 'the dispatched run must be adopted, not stranded beside a second begin() row' );
	}

	/**
	 * execute() runs as the run owner for the task's duration and restores the
	 * previous user afterwards: under cron get_current_user_id() is 0, so a
	 * capability-gated tool would otherwise be refused. The owner (an admin) is
	 * set as the current user for the run, so current_user_can('edit_posts') is
	 * true mid-run, and the previous (cron) user is restored in every path.
	 */
	public function test_execute_runs_as_owner_and_restores_current_user(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$agent_id = 'wordpress-assistant';
		$registry = \Agentic_Agent_Registry::get_instance();
		$registry->register( $this->make_agent( $agent_id ) );

		$run = Agent_Run::create_queued(
			$agent_id,
			array(
				'kind'      => 'task',
				'user_id'   => $admin_id,
				'task_text' => 'Publish a post.',
			)
		);
		$job_id = Agent_Task_Job_Processor::dispatch( $run );
		$job    = Job_Manager::get_job( $job_id );
		Agent_Run::reset_current_for_tests();

		$fake = new Recording_LLM_Client( array( Fake_LLM_Client::text_response( 'Done.' ) ) );

		// Simulate cron: no current user.
		wp_set_current_user( 0 );

		$result = ( new Agent_Task_Job_Processor() )->execute(
			$job->request_data,
			static function ( $progress, $message ) {},
			new Agent_Controller( $fake )
		);

		$this->assertSame( 'completed', $result['status'] );
		$this->assertSame( $admin_id, $fake->seen_user_id, 'the run must execute as its owner' );
		$this->assertTrue( $fake->seen_edit_posts, 'an admin owner must pass a capability-gated check mid-run' );
		$this->assertSame( 0, get_current_user_id(), 'the previous (cron) user must be restored after the run' );
	}

	/**
	 * A background run whose owner has been deleted must fail cleanly rather
	 * than be silently impersonated: execute() refuses to run, finishes the run
	 * 'failed' with a clear error, and throws so the job records the failure.
	 */
	public function test_execute_fails_cleanly_when_owner_deleted(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$agent_id = 'wordpress-assistant';
		$registry = \Agentic_Agent_Registry::get_instance();
		$registry->register( $this->make_agent( $agent_id ) );

		$run = Agent_Run::create_queued(
			$agent_id,
			array(
				'kind'      => 'task',
				'user_id'   => $admin_id,
				'task_text' => 'Do something.',
			)
		);
		$run_id = $run->get_run_id();
		$job_id = Agent_Task_Job_Processor::dispatch( $run );
		$job    = Job_Manager::get_job( $job_id );
		Agent_Run::reset_current_for_tests();

		wp_delete_user( $admin_id );

		$thrown = null;
		try {
			( new Agent_Task_Job_Processor() )->execute(
				$job->request_data,
				static function ( $progress, $message ) {},
				new Agent_Controller( new Fake_LLM_Client( array( Fake_LLM_Client::text_response( 'Done.' ) ) ) )
			);
		} catch ( \Exception $e ) {
			$thrown = $e->getMessage();
		}

		$this->assertNotNull( $thrown, 'execute() must refuse to run an ownerless run' );
		$this->assertStringContainsString( 'no longer exists', $thrown );

		$reloaded = Agent_Run::load( $run_id );
		$this->assertSame( 'failed', $reloaded->to_array()['status'] );
		$this->assertStringContainsString( 'no longer exists', $reloaded->to_array()['error'] );
	}

	/**
	 * A background run whose owner is no longer authorised (a non-admin without
	 * the run_tasks_manually privilege) must also fail cleanly rather than run.
	 */
	public function test_execute_fails_cleanly_when_owner_lacks_capability(): void {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$agent_id = 'wordpress-assistant';
		$registry = \Agentic_Agent_Registry::get_instance();
		$registry->register( $this->make_agent( $agent_id ) );

		$run = Agent_Run::create_queued(
			$agent_id,
			array(
				'kind'      => 'task',
				'user_id'   => $subscriber_id,
				'task_text' => 'Do something.',
			)
		);
		$run_id = $run->get_run_id();
		$job_id = Agent_Task_Job_Processor::dispatch( $run );
		$job    = Job_Manager::get_job( $job_id );
		Agent_Run::reset_current_for_tests();

		$thrown = null;
		try {
			( new Agent_Task_Job_Processor() )->execute(
				$job->request_data,
				static function ( $progress, $message ) {},
				new Agent_Controller( new Fake_LLM_Client( array( Fake_LLM_Client::text_response( 'Done.' ) ) ) )
			);
		} catch ( \Exception $e ) {
			$thrown = $e->getMessage();
		}

		$this->assertNotNull( $thrown );
		$this->assertStringContainsString( 'lacks the agent_builder_run_tasks_manually capability', $thrown );

		$reloaded = Agent_Run::load( $run_id );
		$this->assertSame( 'failed', $reloaded->to_array()['status'] );
	}

	/**
	 * A queued run whose job is never processed stays 'queued': create_queued()
	 * must not make it current or arm a shutdown guard that would abort it when
	 * the creating request ends. The 6 h abandoned-pending health check that
	 * eventually flags the stale job is Job_Manager's concern; here we assert
	 * only the run side.
	 */
	public function test_queued_run_with_unprocessed_job_stays_queued(): void {
		$agent_id = 'wordpress-assistant';

		$run    = Agent_Run::create_queued(
			$agent_id,
			array(
				'kind'      => 'task',
				'user_id'   => 7,
				'task_text' => 'Summarise the newest posts',
			)
		);
		$run_id = $run->get_run_id();

		// A pending job exists, but the worker never runs it.
		$job_id = Agent_Task_Job_Processor::dispatch( $run );
		$this->assertNotSame( '', $job_id );

		$reloaded = Agent_Run::load( $run_id );
		$this->assertSame( 'queued', $reloaded->to_array()['status'], 'a queued run whose job never runs must stay queued' );
	}

	/**
	 * handle_continuation() — the listener on
	 * agent_builder_run_needs_continuation — atomically claims a run out of
	 * 'continuing' and dispatches a resume job carrying its resume state, so a
	 * run handed off by the elapsed-time guard does not stop forever.
	 */
	public function test_handle_continuation_dispatches_a_resume_job(): void {
		$agent_id = 'wordpress-assistant';
		$run      = Agent_Run::begin(
			$agent_id,
			array(
				'kind'      => 'task',
				'user_id'   => 7,
				'task_text' => 'Long-running task.',
			)
		);
		$run->checkpoint_transcript(
			array(
				array( 'role' => 'system', 'content' => 'System prompt.' ),
				array( 'role' => 'user', 'content' => 'Long-running task.' ),
			)
		);
		$run->mark_continuing();
		$run_id       = $run->get_run_id();
		$resume_state = $run->resume_state();
		Agent_Run::reset_current_for_tests();

		Agent_Task_Job_Processor::handle_continuation( $run_id );

		// The run is claimed out of 'continuing' into 'running'.
		$run_after = Agent_Run::load( $run_id );
		$this->assertSame( 'running', $run_after->to_array()['status'], 'the continuation listener must claim the run out of continuing' );

		// A single pending resume job was dispatched carrying the run id and
		// resume state.
		global $wpdb;
		$row = $wpdb->get_row( "SELECT request_data FROM {$wpdb->prefix}agent_builder_jobs ORDER BY created_at DESC LIMIT 1" );
		$this->assertNotNull( $row );

		$request = json_decode( $row->request_data, true );
		$this->assertSame( Agent_Task_Job_Processor::class, $request['_processor'] );
		$this->assertSame( $run_id, $request['run_id'] );
		$this->assertSame( 7, $request['user_id'] );
		$this->assertSame( $resume_state, $request['resume'] );
	}

	/**
	 * init() registers a listener on agent_builder_run_needs_continuation, so
	 * the controller's elapsed-time hand-off actually schedules a continuation.
	 */
	public function test_init_registers_continuation_listener(): void {
		$this->assertNotFalse(
			has_action( 'agent_builder_run_needs_continuation', array( Agent_Task_Job_Processor::class, 'handle_continuation' ) ),
			'the continuation seam must have a registered listener'
		);
	}

	/**
	 * Minimal Manifest_Agent test double, registered with the shared
	 * Agentic_Agent_Registry so Agent_Task_Job_Processor::execute() can
	 * resolve it by slug the way it does in production.
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
}

/**
 * A Fake_LLM_Client that records the current user (and a capability check) at
 * the moment the model is called, so tests can assert a background run is
 * executing as its owner rather than as the cron context (user 0).
 */
class Recording_LLM_Client extends Fake_LLM_Client {

	/**
	 * Current user id observed at the first chat() call.
	 *
	 * @var int
	 */
	public int $seen_user_id = 0;

	/**
	 * Whether current_user_can('edit_posts') passed at the first chat() call.
	 *
	 * @var bool
	 */
	public bool $seen_edit_posts = false;

	/**
	 * Record the current user, then delegate to the scripted response queue.
	 *
	 * @param array $messages      Conversation messages.
	 * @param array $tools         Available tools.
	 * @param bool  $force_tool_use Force-tool-use flag.
	 * @return array|\WP_Error
	 */
	public function chat( array $messages, array $tools = array(), bool $force_tool_use = false ): array|\WP_Error {
		$this->seen_user_id    = get_current_user_id();
		$this->seen_edit_posts = current_user_can( 'edit_posts' );
		return parent::chat( $messages, $tools, $force_tool_use );
	}
}
