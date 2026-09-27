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

		$fired = false;
		add_filter(
			'admin_memory_limit',
			function ( $limit ) use ( &$fired ) {
				$fired = true;
				return $limit;
			}
		);

		try {
			$job_id = Job_Manager::create_job(
				array(
					'processor' => Runnable_Test_Processor::class,
				)
			);

			Job_Manager::process_job( $job_id );
		} finally {
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
		add_filter(
			'agent_builder_job_processors',
			function ( array $allowed ) use ( $class ): array {
				$allowed[] = $class;
				return $allowed;
			}
		);
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
