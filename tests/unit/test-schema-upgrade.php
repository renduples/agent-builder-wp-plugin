<?php
/**
 * Unit tests for Activator::maybe_upgrade() schema-version sync.
 *
 * @package Agentic\Tests
 */

namespace Agentic\Tests;

use Agentic\Activator;

/**
 * Stored agent_builder_db_schema_version must catch up on admin load
 * after a plugin upgrade (activation hook does not fire on auto-update).
 */
class Test_Schema_Upgrade extends TestCase {

	/**
	 * Previous stored schema version, restored in tearDown.
	 *
	 * @var mixed
	 */
	private $previous_schema;

	/**
	 * Snapshot the stored schema option.
	 */
	public function setUp(): void {
		parent::setUp();
		// The upgrade's create_tables()/dbDelta() runs DDL that implicitly
		// commits MySQL transactions, so a lock set in a prior test can leak
		// as a committed row. Clear it so each test starts with the lock free.
		delete_transient( 'agent_builder_upgrade_lock' );
		$this->previous_schema = get_option( 'agent_builder_db_schema_version', false );
	}

	/**
	 * Restore schema option and drop admin context.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		unset( $GLOBALS['current_screen'] );
		delete_transient( 'agent_builder_upgrade_lock' );
		if ( false === $this->previous_schema ) {
			delete_option( 'agent_builder_db_schema_version' );
		} else {
			update_option( 'agent_builder_db_schema_version', $this->previous_schema );
		}
		parent::tearDown();
	}

	/**
	 * Logged-in wp-admin request advances a stale stored version to the constant.
	 */
	public function test_maybe_upgrade_bumps_stored_schema_when_behind(): void {
		update_option( 'agent_builder_db_schema_version', '2.14.1' );
		$this->enter_admin_as_logged_in_user();

		Activator::maybe_upgrade();

		$this->assertSame(
			AGENT_BUILDER_DB_VERSION,
			(string) get_option( 'agent_builder_db_schema_version' )
		);
	}

	/**
	 * Already-current stored version is a no-op: no option write.
	 */
	public function test_maybe_upgrade_is_noop_when_already_current(): void {
		update_option( 'agent_builder_db_schema_version', AGENT_BUILDER_DB_VERSION );
		$this->enter_admin_as_logged_in_user();

		$option_written = false;
		$tracker        = static function ( $value ) use ( &$option_written ) {
			$option_written = true;
			return $value;
		};
		add_filter( 'pre_update_option_agent_builder_db_schema_version', $tracker );

		Activator::maybe_upgrade();

		remove_filter( 'pre_update_option_agent_builder_db_schema_version', $tracker );

		$this->assertFalse( $option_written );
		$this->assertSame(
			AGENT_BUILDER_DB_VERSION,
			(string) get_option( 'agent_builder_db_schema_version' )
		);
	}

	/**
	 * A logged-out, non-admin request — the shape of the first cron or REST
	 * hit after an auto-update, where admin_init never fires — still brings
	 * the stored schema version current. This is the acceptance case: no
	 * admin visit is required for the migration to run.
	 */
	public function test_maybe_upgrade_runs_on_first_cron_or_rest_hit(): void {
		update_option( 'agent_builder_db_schema_version', '2.14.2' );
		wp_set_current_user( 0 );
		unset( $GLOBALS['current_screen'] );

		Activator::maybe_upgrade();

		$this->assertSame(
			AGENT_BUILDER_DB_VERSION,
			(string) get_option( 'agent_builder_db_schema_version' )
		);
	}

	/**
	 * A near-simultaneous second request (a REST hit and a cron run landing
	 * together) must not re-run the upgrade while the first still holds the
	 * transient lock: the stored version stays behind until the lock clears,
	 * so create_tables() is not double-invoked.
	 */
	public function test_concurrent_requests_do_not_double_run_upgrade(): void {
		update_option( 'agent_builder_db_schema_version', '2.14.2' );

		// Simulate the first request holding the lock mid-upgrade.
		set_transient( 'agent_builder_upgrade_lock', 1, 60 );

		Activator::maybe_upgrade(); // Second request lands while the first is running.

		$this->assertSame(
			'2.14.2',
			(string) get_option( 'agent_builder_db_schema_version' ),
			'The second request must skip the upgrade while the lock is held.'
		);

		// Lock clears (first request finished) — the next request completes it.
		delete_transient( 'agent_builder_upgrade_lock' );
		Activator::maybe_upgrade();

		$this->assertSame(
			AGENT_BUILDER_DB_VERSION,
			(string) get_option( 'agent_builder_db_schema_version' )
		);
	}

	/**
	 * Put the test in a logged-in wp-admin context.
	 */
	private function enter_admin_as_logged_in_user(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		set_current_screen( 'dashboard' );
	}

	/**
	 * Every M10 column and key exists after upgrading from a 2.14.2-shaped
	 * schema — and dbDelta is idempotent, so running it again adds nothing.
	 */
	public function test_upgrade_adds_every_m10_column_and_key_once(): void {
		update_option( 'agent_builder_db_schema_version', '2.14.2' );
		$this->enter_admin_as_logged_in_user();

		Activator::maybe_upgrade();

		global $wpdb;

		$runs_columns = $wpdb->get_col( "SHOW COLUMNS FROM {$wpdb->prefix}agent_builder_runs", 0 );
		foreach (
			array(
				'kind',
				'user_id',
				'task_text',
				'parent_run_id',
				'job_id',
				'session_id',
				'invocation',
				'source_ref',
				'iterations',
				'tools_used',
				'result_summary',
				'error',
				'awaiting_type',
				'awaiting_id',
				'cancel_requested',
			) as $expected_column
		) {
			$this->assertContains( $expected_column, $runs_columns, "agent_builder_runs is missing column {$expected_column}" );
		}

		$runs_keys = $wpdb->get_col( "SHOW INDEX FROM {$wpdb->prefix}agent_builder_runs", 2 );
		foreach ( array( 'user_status', 'parent_run_id', 'source_ref', 'kind_started' ) as $expected_key ) {
			$this->assertContains( $expected_key, $runs_keys, "agent_builder_runs is missing key {$expected_key}" );
		}

		$queue_columns = $wpdb->get_col( "SHOW COLUMNS FROM {$wpdb->prefix}agent_builder_approval_queue", 0 );
		$this->assertContains( 'run_id', $queue_columns );
		$this->assertContains( 'user_id', $queue_columns );
		$queue_keys = $wpdb->get_col( "SHOW INDEX FROM {$wpdb->prefix}agent_builder_approval_queue", 2 );
		$this->assertContains( 'run_id', $queue_keys );

		$audit_columns = $wpdb->get_col( "SHOW COLUMNS FROM {$wpdb->prefix}agent_builder_audit_log", 0 );
		$this->assertContains( 'run_id', $audit_columns );
		$audit_keys = $wpdb->get_col( "SHOW INDEX FROM {$wpdb->prefix}agent_builder_audit_log", 2 );
		$this->assertContains( 'run_id', $audit_keys );

		// Running the upgrade again against an already-current schema is a no-op
		// (guarded by maybe_upgrade()'s own version check) and, more to the
		// point, calling create_tables() a second time via dbDelta directly
		// does not error or duplicate any column/key.
		$runs_column_count_before = count( $runs_columns );
		$runs_key_count_before    = count( array_unique( $runs_keys ) );

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$ref = new \ReflectionMethod( Activator::class, 'create_tables' );
		$ref->invoke( null );

		$runs_columns_after = $wpdb->get_col( "SHOW COLUMNS FROM {$wpdb->prefix}agent_builder_runs", 0 );
		$runs_keys_after     = array_unique( $wpdb->get_col( "SHOW INDEX FROM {$wpdb->prefix}agent_builder_runs", 2 ) );
		$this->assertSame( $runs_column_count_before, count( $runs_columns_after ) );
		$this->assertSame( $runs_key_count_before, count( $runs_keys_after ) );
	}
}
