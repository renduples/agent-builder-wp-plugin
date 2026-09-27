<?php
/**
 * Unit Tests for Job_Manager hardening (M10d1).
 *
 * Covers the processor allowlist, the atomic claim, \Throwable handling and
 * the memory-limit raise added to Job_Manager::process_job(). Tests use real
 * Job_Manager and real processor classes (no mocks).
 *
 * @package Agentic\Tests
 */

namespace Agentic\Tests;

use Agentic\Job_Manager;

/**
 * Test case for Job_Manager.
 */
class Test_Job_Manager extends TestCase {

	/**
	 * Filter callbacks registered by this test on agent_builder_job_processors,
	 * removed in tearDown() so one test's allow_processor() can't leak into the
	 * next test running in the same PHP process.
	 *
	 * @var array<int, array{0: string, 1: callable}>
	 */
	private array $registered_filters = array();

	/**
	 * Reset cross-test state and clear the jobs table before each test.
	 */
	public function setUp(): void {
		parent::setUp();
		Counting_Test_Processor::$run_count = 0;

		global $wpdb;
		$jobs_table = $wpdb->prefix . 'agent_builder_jobs';
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$jobs_table}'" ) === $jobs_table ) {
			$wpdb->query( "DELETE FROM {$jobs_table}" );
		}
	}

	/**
	 * Remove any allowlist filters registered by this test so they don't leak
	 * into subsequent tests (WordPress hooks persist across tests in a process).
	 */
	public function tearDown(): void {
		foreach ( $this->registered_filters as $entry ) {
			remove_filter( $entry[0], $entry[1] );
		}
		$this->registered_filters = array();

		parent::tearDown();
	}

	/**
	 * A non-allowlisted processor is rejected: status failed, "processor not
	 * allowed" message, and an audit entry for visibility.
	 */
	public function test_non_allowlisted_processor_fails_with_not_allowed_message(): void {
		$job_id = Job_Manager::create_job(
			array(
				'processor' => Non_Allowlisted_Test_Processor::class,
			)
		);

		$this->assertNotSame( '', $job_id );

		Job_Manager::process_job( $job_id );

		$job = Job_Manager::get_job( $job_id );
		$this->assertNotNull( $job );
		$this->assertSame( Job_Manager::STATUS_FAILED, $job->status );
		$this->assertSame( 'processor not allowed: ' . Non_Allowlisted_Test_Processor::class, $job->error_message );

		global $wpdb;
		$audit_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}agent_builder_audit_log WHERE action = %s",
				'job_processor_not_allowed'
			)
		);
		$this->assertGreaterThanOrEqual( 1, $audit_count );
	}

	/**
	 * A job with no _processor at all is also rejected and audited: a missing
	 * processor must not skip the job_processor_not_allowed audit entry the way
	 * an empty-string processor value would.
	 */
	public function test_missing_processor_fails_and_is_audited(): void {
		$job_id = Job_Manager::create_job( array() );

		$this->assertNotSame( '', $job_id );

		Job_Manager::process_job( $job_id );

		$job = Job_Manager::get_job( $job_id );
		$this->assertNotNull( $job );
		$this->assertSame( Job_Manager::STATUS_FAILED, $job->status );
		$this->assertSame( 'processor not allowed: (missing)', $job->error_message );

		global $wpdb;
		$audit_row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT details FROM {$wpdb->prefix}agent_builder_audit_log WHERE action = %s AND target_id = %s ORDER BY id DESC LIMIT 1",
				'job_processor_not_allowed',
				$job_id
			)
		);
		$this->assertNotNull( $audit_row );

		$details = json_decode( $audit_row->details, true );
		$this->assertSame( $job_id, $details['id'] );
		$this->assertSame( '(missing)', $details['processor'] );
	}

	/**
	 * A filter callback adding a processor class makes that processor runnable.
	 */
	public function test_allowlist_filter_makes_processor_runnable(): void {
		$this->allow_processor( Runnable_Test_Processor::class );

		$job_id = Job_Manager::create_job(
			array(
				'processor' => Runnable_Test_Processor::class,
			)
		);

		Job_Manager::process_job( $job_id );

		$job = Job_Manager::get_job( $job_id );
		$this->assertSame( Job_Manager::STATUS_COMPLETED, $job->status );
		$this->assertSame( array( 'marker' => 'ran' ), $job->response_data );
	}

	/**
	 * A misbehaving agent_builder_job_processors callback returning a non-array
	 * is coerced back to the default allowlist instead of crashing in_array()
	 * with a TypeError: a non-allowlisted processor is rejected cleanly with
	 * the expected "processor not allowed" message.
	 */
	public function test_non_array_allowlist_filter_falls_back_to_default(): void {
		$callback = function () {
			return 'not-an-array';
		};
		add_filter( 'agent_builder_job_processors', $callback );
		$this->registered_filters[] = array( 'agent_builder_job_processors', $callback );

		$job_id = Job_Manager::create_job(
			array(
				'processor' => Runnable_Test_Processor::class,
			)
		);

		Job_Manager::process_job( $job_id );

		$job = Job_Manager::get_job( $job_id );
		$this->assertNotNull( $job );
		$this->assertSame( Job_Manager::STATUS_FAILED, $job->status );
		$this->assertSame( 'processor not allowed: ' . Runnable_Test_Processor::class, $job->error_message );
	}

	/**
	 * Calling process_job() twice on the same id runs the processor once: the
	 * second call's atomic claim fails because the job is no longer pending.
	 */
	public function test_process_job_twice_runs_processor_once(): void {
		$this->allow_processor( Counting_Test_Processor::class );

		$job_id = Job_Manager::create_job(
			array(
				'processor' => Counting_Test_Processor::class,
			)
		);

		Job_Manager::process_job( $job_id );
		Job_Manager::process_job( $job_id );

		$this->assertSame( 1, Counting_Test_Processor::$run_count );

		$job = Job_Manager::get_job( $job_id );
		$this->assertSame( Job_Manager::STATUS_COMPLETED, $job->status );
	}

	/**
	 * A processor that throws \Error is recorded as failed with a truncated
	 * message, and the error does not escape process_job().
	 */
	public function test_throwable_error_is_recorded_as_failed(): void {
		$this->allow_processor( Throwing_Error_Test_Processor::class );

		Throwing_Error_Test_Processor::$message = str_repeat( 'x', 300 );

		$job_id = Job_Manager::create_job(
			array(
				'processor' => Throwing_Error_Test_Processor::class,
			)
		);

		Job_Manager::process_job( $job_id );

		$job = Job_Manager::get_job( $job_id );
		$this->assertSame( Job_Manager::STATUS_FAILED, $job->status );
		$this->assertSame( str_repeat( 'x', 255 ), $job->error_message );
		$this->assertStringNotContainsString( 'Stack trace', $job->error_message );
	}

	/**
	 * wp_raise_memory_limit('admin') runs at the start of process_job(),
	 * observable through the admin_memory_limit filter firing.
	 */
	public function test_wp_raise_memory_limit_is_called(): void {
		$this->allow_processor( Runnable_Test_Processor::class );

		// wp_raise_memory_limit() returns early when memory_limit is unlimited
		// (-1), so set a finite limit first to reach the admin_memory_limit filter.
		$previous_limit = ini_set( 'memory_limit', '64M' );

		$fired    = false;
		$callback = function ( $limit ) use ( &$fired ) {
			$fired = true;
			return $limit;
		};
		add_filter( 'admin_memory_limit', $callback );

		try {
			$job_id = Job_Manager::create_job(
				array(
					'processor' => Runnable_Test_Processor::class,
				)
			);

			Job_Manager::process_job( $job_id );
		} finally {
			remove_filter( 'admin_memory_limit', $callback );
			if ( false !== $previous_limit ) {
				ini_set( 'memory_limit', $previous_limit );
			}
		}

		$this->assertTrue( $fired );
	}

	/**
	 * Register a processor class as allowlisted for the current test.
	 *
	 * @param string $class Processor class FQN.
	 * @return void
	 */
	private function allow_processor( string $class ): void {
		$callback = function ( array $allowed ) use ( $class ): array {
			$allowed[] = $class;
			return $allowed;
		};
		add_filter( 'agent_builder_job_processors', $callback );
		$this->registered_filters[] = array( 'agent_builder_job_processors', $callback );
	}
}

/**
 * A processor that implements the interface but is not in the default allowlist.
 */
class Non_Allowlisted_Test_Processor implements \Agentic\Job_Processor_Interface {

	/**
	 * Execute (never runs in tests — the allowlist rejects it first).
	 *
	 * @param array    $request_data      Request data.
	 * @param callable $progress_callback Progress callback.
	 * @return array
	 */
	public function execute( array $request_data, callable $progress_callback ): array {
		return array( 'should_not_run' => true );
	}
}

/**
 * A runnable processor returning a fixed marker.
 */
class Runnable_Test_Processor implements \Agentic\Job_Processor_Interface {

	/**
	 * Execute.
	 *
	 * @param array    $request_data      Request data.
	 * @param callable $progress_callback Progress callback.
	 * @return array
	 */
	public function execute( array $request_data, callable $progress_callback ): array {
		$progress_callback( 50, 'running' );
		return array( 'marker' => 'ran' );
	}
}

/**
 * A processor that counts how many times it runs.
 */
class Counting_Test_Processor implements \Agentic\Job_Processor_Interface {

	/**
	 * Run counter (static so process_job can't reach it without executing).
	 *
	 * @var int
	 */
	public static int $run_count = 0;

	/**
	 * Execute.
	 *
	 * @param array    $request_data      Request data.
	 * @param callable $progress_callback Progress callback.
	 * @return array
	 */
	public function execute( array $request_data, callable $progress_callback ): array {
		++self::$run_count;
		return array( 'ran' => self::$run_count );
	}
}

/**
 * A processor that throws a PHP \Error (not \Exception).
 */
class Throwing_Error_Test_Processor implements \Agentic\Job_Processor_Interface {

	/**
	 * Message to throw.
	 *
	 * @var string
	 */
	public static string $message = 'boom';

	/**
	 * Execute.
	 *
	 * @param array    $request_data      Request data.
	 * @param callable $progress_callback Progress callback.
	 * @return array
	 */
	public function execute( array $request_data, callable $progress_callback ): array {
		throw new \Error( self::$message );
	}
}
