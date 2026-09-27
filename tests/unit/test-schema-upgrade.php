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
		$this->previous_schema = get_option( 'agent_builder_db_schema_version', false );
	}

	/**
	 * Restore schema option and drop admin context.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		unset( $GLOBALS['current_screen'] );
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
	 * Front-end / logged-out calls must not write even when stored is behind.
	 */
	public function test_maybe_upgrade_skips_logged_out_and_non_admin(): void {
		update_option( 'agent_builder_db_schema_version', '2.14.1' );
		wp_set_current_user( 0 );
		unset( $GLOBALS['current_screen'] );

		Activator::maybe_upgrade();

		$this->assertSame( '2.14.1', (string) get_option( 'agent_builder_db_schema_version' ) );

		$this->enter_admin_as_logged_in_user();
		wp_set_current_user( 0 );
		Activator::maybe_upgrade();

		$this->assertSame( '2.14.1', (string) get_option( 'agent_builder_db_schema_version' ) );
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
				'awaiting_tool_call_id',
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

	/**
	 * Regression: awaiting_tool_call_id was added to the runs table's dbDelta
	 * SQL. Bumping AGENT_BUILDER_DB_VERSION to pick it up would collide with
	 * 2.15.1, which the programme's schema plan reserves for M11's own
	 * schema work — a site upgraded that way here would then wrongly skip
	 * M11's real migration later, since the stored version would already
	 * match. The column is instead added by its own version-independent
	 * migration, maybe_add_awaiting_tool_call_id_column(), which must run
	 * (and add the column) even when the stored schema version already
	 * equals AGENT_BUILDER_DB_VERSION — exactly the case the version-gated
	 * path in maybe_upgrade() alone would no-op on — and must never touch
	 * the schema-version option itself.
	 */
	public function test_maybe_upgrade_adds_awaiting_tool_call_id_independent_of_schema_version(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$ref = new \ReflectionMethod( Activator::class, 'create_tables' );
		$ref->invoke( null );

		$wpdb->query( "ALTER TABLE {$wpdb->prefix}agent_builder_runs DROP COLUMN awaiting_tool_call_id" );
		delete_option( 'agent_builder_awaiting_tool_call_id_migrated' );

		$runs_columns_before = $wpdb->get_col( "SHOW COLUMNS FROM {$wpdb->prefix}agent_builder_runs", 0 );
		$this->assertNotContains( 'awaiting_tool_call_id', $runs_columns_before );

		// Stored version already equals the constant — the version-gated
		// path alone would no-op and never re-run create_tables().
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

		$runs_columns_after = $wpdb->get_col( "SHOW COLUMNS FROM {$wpdb->prefix}agent_builder_runs", 0 );
		$this->assertContains( 'awaiting_tool_call_id', $runs_columns_after );
		$this->assertFalse( $option_written, 'This migration must never write the schema-version option.' );
		$this->assertTrue( (bool) get_option( 'agent_builder_awaiting_tool_call_id_migrated' ) );
	}

	/**
	 * The column migration runs at most once per site: once its own
	 * "migrated" flag is set, a later maybe_upgrade() call must skip the
	 * SHOW COLUMNS/ALTER TABLE path entirely, even if (hypothetically) the
	 * column were missing again.
	 */
	public function test_maybe_add_awaiting_tool_call_id_column_runs_at_most_once(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$ref = new \ReflectionMethod( Activator::class, 'create_tables' );
		$ref->invoke( null );

		update_option( 'agent_builder_awaiting_tool_call_id_migrated', true );
		$wpdb->query( "ALTER TABLE {$wpdb->prefix}agent_builder_runs DROP COLUMN awaiting_tool_call_id" );

		try {
			update_option( 'agent_builder_db_schema_version', AGENT_BUILDER_DB_VERSION );
			$this->enter_admin_as_logged_in_user();

			Activator::maybe_upgrade();

			$runs_columns_after = $wpdb->get_col( "SHOW COLUMNS FROM {$wpdb->prefix}agent_builder_runs", 0 );
			$this->assertNotContains( 'awaiting_tool_call_id', $runs_columns_after, 'Already-migrated flag must short-circuit before the column is re-checked.' );
		} finally {
			// Restore — DDL isn't rolled back by the per-test transaction.
			$wpdb->query( "ALTER TABLE {$wpdb->prefix}agent_builder_runs ADD COLUMN awaiting_tool_call_id varchar(64) DEFAULT NULL AFTER awaiting_id" );
			delete_option( 'agent_builder_awaiting_tool_call_id_migrated' );
		}
	}
}
