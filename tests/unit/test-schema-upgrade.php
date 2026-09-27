<?php
/**
 * Unit tests for Activator::maybe_upgrade() schema-version sync.
 *
 * @package Agentic\Tests
 */

namespace Agentic\Tests;

use Agentic\Activator;
use Agentic\Agent_Run;
use Agentic\Audit_Log;

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
		delete_option( 'agent_builder_needs_seed' );
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
	 * maybe_upgrade_schema() (the broader, non-admin-gated entry point hooked
	 * on 'init') repairs a stale table even with no admin ever having loaded
	 * wp-admin — the exact cron/REST/frontend gap findings #3/#4 describe —
	 * and an Agent_Run insert immediately afterward lands successfully.
	 */
	public function test_maybe_upgrade_schema_repairs_stale_table_outside_admin(): void {
		update_option( 'agent_builder_db_schema_version', '2.14.2' );
		wp_set_current_user( 0 );
		unset( $GLOBALS['current_screen'] );
		$this->recreate_pre_m10_tables();

		Activator::maybe_upgrade_schema();

		$this->assertSame(
			AGENT_BUILDER_DB_VERSION,
			(string) get_option( 'agent_builder_db_schema_version' )
		);

		global $wpdb;
		$runs_columns = $wpdb->get_col( "SHOW COLUMNS FROM {$wpdb->prefix}agent_builder_runs", 0 );
		$this->assertContains( 'kind', $runs_columns );

		Agent_Run::reset_current_for_tests();
		$run = Agent_Run::begin( 'test-agent' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test assertion against the persisted row.
		$run_row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}agent_builder_runs WHERE run_id = %s", $run->get_run_id() ),
			ARRAY_A
		);
		$this->assertIsArray( $run_row, 'Agent_Run::begin() must persist a row immediately after maybe_upgrade_schema()' );
		$run->finish( 'completed' );
		Agent_Run::reset_current_for_tests();
	}

	/**
	 * A successful non-admin upgrade must arm agent_builder_needs_seed just
	 * like maybe_upgrade() does — otherwise, once maybe_upgrade_schema() has
	 * already bumped the stored version here, a later admin_init call to
	 * maybe_upgrade() sees the version already current and skips entirely,
	 * permanently missing newly bundled content. Arming the flag here is
	 * safe because maybe_run_deferred_seed() only ever executes from
	 * admin_init while current_user_can( 'manage_options' ), so this
	 * non-admin call still can't start background seeding on its own.
	 */
	public function test_maybe_upgrade_schema_arms_needs_seed_on_success(): void {
		update_option( 'agent_builder_db_schema_version', '2.14.2' );
		delete_option( 'agent_builder_needs_seed' );
		wp_set_current_user( 0 );
		unset( $GLOBALS['current_screen'] );
		$this->recreate_pre_m10_tables();

		Activator::maybe_upgrade_schema();

		$this->assertSame(
			AGENT_BUILDER_DB_VERSION,
			(string) get_option( 'agent_builder_db_schema_version' )
		);
		$this->assertTrue( (bool) get_option( 'agent_builder_needs_seed' ) );
	}

	/**
	 * maybe_upgrade_schema() defers entirely to maybe_upgrade() when the
	 * request is already in a logged-in wp-admin context, rather than racing
	 * it or doubling up the same dbDelta call within one request.
	 */
	public function test_maybe_upgrade_schema_defers_to_admin_path(): void {
		update_option( 'agent_builder_db_schema_version', '2.14.2' );
		$this->enter_admin_as_logged_in_user();

		$option_written = false;
		$tracker        = static function ( $value ) use ( &$option_written ) {
			$option_written = true;
			return $value;
		};
		add_filter( 'pre_update_option_agent_builder_db_schema_version', $tracker );

		Activator::maybe_upgrade_schema();

		remove_filter( 'pre_update_option_agent_builder_db_schema_version', $tracker );

		$this->assertFalse( $option_written );
		$this->assertSame( '2.14.2', (string) get_option( 'agent_builder_db_schema_version' ) );
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
	 * Drop and recreate agent_builder_runs, agent_builder_approval_queue, and
	 * agent_builder_audit_log in their genuine pre-M10 (2.14.2) column shape —
	 * copied verbatim from Activator::create_tables() as it existed at that
	 * version — so the tests below exercise the real dbDelta migration
	 * instead of asserting against columns tests/bootstrap.php already
	 * hand-creates in the current (M10) shape regardless of what
	 * maybe_upgrade() actually does.
	 */
	private function recreate_pre_m10_tables(): void {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test fixture setup.
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}agent_builder_runs" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test fixture setup.
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}agent_builder_approval_queue" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test fixture setup.
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}agent_builder_audit_log" );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test fixture setup; pre-M10 shape, no run_id column.
		$wpdb->query(
			"CREATE TABLE {$wpdb->prefix}agent_builder_audit_log (
	            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	            agent_id varchar(64) NOT NULL,
	            action varchar(128) NOT NULL,
	            target_type varchar(64),
	            target_id varchar(128),
	            details longtext,
	            reasoning text,
	            mode varchar(32) DEFAULT '',
	            provider varchar(64) DEFAULT '',
	            tokens_used int unsigned DEFAULT 0,
	            cost decimal(10,6) DEFAULT 0,
	            user_id bigint(20) unsigned,
	            created_at datetime DEFAULT CURRENT_TIMESTAMP,
	            agent_author varchar(191) DEFAULT '',
	            agent_version varchar(32) DEFAULT '',
	            integrity_hash char(64) DEFAULT NULL,
	            PRIMARY KEY (id),
	            KEY agent_id (agent_id),
	            KEY action (action),
	            KEY created_at (created_at),
	            KEY user_created (user_id, created_at),
	            KEY idx_agent_created (agent_id, created_at)
	        ) {$charset_collate}"
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test fixture setup; pre-M10 shape, no run_id/user_id columns.
		$wpdb->query(
			"CREATE TABLE {$wpdb->prefix}agent_builder_approval_queue (
	            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	            agent_id varchar(64) NOT NULL,
	            action varchar(128) NOT NULL,
	            params longtext NOT NULL,
	            reasoning text,
	            risk_level varchar(32) DEFAULT 'none',
	            status varchar(32) DEFAULT 'pending',
	            approved_by bigint(20) unsigned,
	            approved_at datetime,
	            created_at datetime DEFAULT CURRENT_TIMESTAMP,
	            expires_at datetime,
	            executed_at datetime DEFAULT NULL,
	            mode varchar(32) DEFAULT '',
	            invocation varchar(32) DEFAULT '',
	            PRIMARY KEY (id),
	            KEY status (status),
	            KEY created_at (created_at),
	            KEY idx_status_created (status, created_at),
	            KEY idx_expires (expires_at)
	        ) {$charset_collate}"
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test fixture setup; pre-M10 shape, missing every M10 column.
		$wpdb->query(
			"CREATE TABLE {$wpdb->prefix}agent_builder_runs (
	            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	            run_id varchar(36) NOT NULL,
	            root_agent varchar(64) NOT NULL DEFAULT '',
	            status varchar(16) NOT NULL DEFAULT 'running',
	            delegations int unsigned NOT NULL DEFAULT 0,
	            max_depth smallint unsigned NOT NULL DEFAULT 0,
	            tokens_used int unsigned NOT NULL DEFAULT 0,
	            cost decimal(10,6) NOT NULL DEFAULT 0,
	            state longtext,
	            started_at datetime DEFAULT CURRENT_TIMESTAMP,
	            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	            finished_at datetime DEFAULT NULL,
	            PRIMARY KEY (id),
	            UNIQUE KEY run_id (run_id),
	            KEY root_agent (root_agent),
	            KEY status (status),
	            KEY started_at (started_at)
	        ) {$charset_collate}"
		);
	}

	/**
	 * Every M10 column and key exists after upgrading from a genuinely
	 * 2.14.2-shaped schema — and dbDelta is idempotent, so running it again
	 * adds nothing. Also proves the migration itself (not just a schema the
	 * test bootstrap already hand-created in the current shape) leaves
	 * Agent_Run/Audit_Log able to insert immediately afterward — the exact
	 * gap findings #3/#4 describe.
	 */
	public function test_upgrade_adds_every_m10_column_and_key_once(): void {
		update_option( 'agent_builder_db_schema_version', '2.14.2' );
		$this->enter_admin_as_logged_in_user();
		$this->recreate_pre_m10_tables();

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

		// Findings #3/#4: an Agent_Run insert and an Audit_Log insert must
		// both succeed immediately after maybe_upgrade() runs — the migration
		// having actually widened the tables, not merely bumped the stored
		// version, is what makes these inserts land.
		Agent_Run::reset_current_for_tests();
		$run = Agent_Run::begin( 'test-agent' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test assertion against the persisted row.
		$run_row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}agent_builder_runs WHERE run_id = %s", $run->get_run_id() ),
			ARRAY_A
		);
		$this->assertIsArray( $run_row, 'Agent_Run::begin() must persist a row immediately after maybe_upgrade()' );
		$run->finish( 'completed' );
		Agent_Run::reset_current_for_tests();

		$audit_log = new Audit_Log();
		$audit_id  = $audit_log->log( 'test-agent', 'tool_call', 'list_posts', array( 'id' => 1 ) );
		$this->assertNotFalse( $audit_id, 'Audit_Log::log() must succeed immediately after maybe_upgrade()' );

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
