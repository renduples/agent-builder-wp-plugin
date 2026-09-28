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
		$run = Agent_Run::begin(
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
		$run = Agent_Run::begin(
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

		$owner_id    = 'test-job-processor-guard-owner';
		$mismatch_id = 'test-job-processor-guard-mismatched';
		$registry    = \Agentic_Agent_Registry::get_instance();
		$registry->register( $this->make_agent( $owner_id ) );
		$registry->register( $this->make_agent( $mismatch_id ) );

		$run = Agent_Run::begin(
			$owner_id,
			array(
				'kind'      => 'task',
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
