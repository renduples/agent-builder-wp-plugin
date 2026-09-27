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
		delete_option( 'agent_builder_upgrade_lock' );
		$this->previous_schema = get_option( 'agent_builder_db_schema_version', false );
	}

	/**
	 * Restore schema option and drop admin context.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		unset( $GLOBALS['current_screen'] );
		delete_option( 'agent_builder_upgrade_lock' );
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
		// '2.14.2' is the last release before the 2.15.0 schema (the current
		// AGENT_BUILDER_DB_VERSION), so it is genuinely behind and this exercises
		// the full acquire → create_tables() → set-version path, not the version
		// short-circuit.
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
	 * lock: the stored version stays behind until the lock clears, so
	 * create_tables() is not double-invoked. The first "request" acquires the
	 * lock through the real atomic acquire() path, so the second maybe_upgrade()
	 * call actually hits the live-lock rejection branch rather than a hand-seeded
	 * transient.
	 */
	public function test_concurrent_requests_do_not_double_run_upgrade(): void {
		// Behind the current 2.15.0 constant, so the second maybe_upgrade() call
		// below is rejected by the held lock, not by the version short-circuit.
		update_option( 'agent_builder_db_schema_version', '2.14.2' );

		// Simulate the first request acquiring the lock mid-upgrade.
		$first = self::invoke_private( 'acquire_upgrade_lock' );
		$this->assertIsString( $first, 'The first request should acquire the lock.' );

		Activator::maybe_upgrade(); // Second request lands while the first is running.

		$this->assertSame(
			'2.14.2',
			(string) get_option( 'agent_builder_db_schema_version' ),
			'The second request must skip the upgrade while the lock is held.'
		);

		// First request finishes and releases — the next request completes it.
		self::invoke_private( 'release_upgrade_lock', array( $first ) );
		Activator::maybe_upgrade();

		$this->assertSame(
			AGENT_BUILDER_DB_VERSION,
			(string) get_option( 'agent_builder_db_schema_version' )
		);
	}

	/**
	 * The atomic acquire must reject a second holder while the first still owns
	 * the lock — this is the read-then-write race the lock exists to close, so
	 * it exercises the INSERT's duplicate-key path, not just a pre-seeded row.
	 */
	public function test_second_acquisition_is_rejected_while_first_holds_lock(): void {
		$first = self::invoke_private( 'acquire_upgrade_lock' );
		$this->assertIsString( $first, 'The first acquisition should succeed.' );

		$second = self::invoke_private( 'acquire_upgrade_lock' );
		$this->assertNull( $second, 'A second acquisition must be rejected while the lock is held.' );
	}

	/**
	 * A stale lock (the owning process died, or its TTL passed) must be taken
	 * over via the compare-and-swap path — and the takeover must overwrite the
	 * row with the new owner's value.
	 */
	public function test_expired_lock_is_taken_over_via_compare_and_swap(): void {
		$this->insert_lock( time() - 10, 'stale-token' );

		$taken = self::invoke_private( 'acquire_upgrade_lock' );
		$this->assertIsString( $taken, 'An expired lock should be taken over.' );

		global $wpdb;
		$stored = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
				'agent_builder_upgrade_lock'
			)
		);
		$this->assertSame( $taken, $stored, 'The new owner must overwrite the stale lock value.' );
	}

	/**
	 * A live (non-expired) lock must NOT be taken over: a second request must
	 * observe the active lock and back off.
	 */
	public function test_live_lock_is_not_taken_over(): void {
		$this->insert_lock( time() + 60, 'live-token' );

		$taken = self::invoke_private( 'acquire_upgrade_lock' );
		$this->assertNull( $taken, 'A live lock must not be taken over.' );
	}

	/**
	 * A lock whose owner is still actively working must survive a second
	 * request's takeover attempt. This is the exact failure the conservative TTL
	 * closes: under a 60s TTL, a migration that had been running for just over a
	 * minute (e.g. a slow ALTER on a large, populated table) would look
	 * "expired" and be taken over by a concurrent request — two processes running
	 * DDL against the same tables. Here the owner is 60s into its migration, so
	 * under the new scheme its lease still has (UPGRADE_LOCK_TTL - 60s) to run
	 * and the acquire must back off.
	 */
	public function test_slow_owner_within_ttl_is_not_taken_over(): void {
		$ttl = self::upgrade_lock_ttl();
		$this->insert_lock( time() + $ttl - 60, 'slow-owner-token' );

		$taken = self::invoke_private( 'acquire_upgrade_lock' );
		$this->assertNull( $taken, 'A slow-but-alive owner within the conservative TTL must not be taken over.' );
	}

	/**
	 * A request must never release a lock it does not own: if request A's TTL
	 * expires mid-migration and request B takes the row over, A's release must
	 * be a no-op (the token-guarded DELETE matches only A's exact value).
	 */
	public function test_release_only_clears_its_own_lock(): void {
		$value_a = self::invoke_private( 'acquire_upgrade_lock' );
		$this->assertIsString( $value_a );

		// A's lock "expires" and B takes the row over.
		$value_b = $this->insert_lock( time() + 60, 'b-token' );

		self::invoke_private( 'release_upgrade_lock', array( $value_a ) );

		global $wpdb;
		$stored = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
				'agent_builder_upgrade_lock'
			)
		);
		$this->assertSame( $value_b, $stored, 'A request must not release a lock it does not own.' );

		// B releases its own lock — the row is cleared.
		self::invoke_private( 'release_upgrade_lock', array( $value_b ) );
		$stored = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
				'agent_builder_upgrade_lock'
			)
		);
		$this->assertNull( $stored );
	}

	/**
	 * Invoke a private static Activator method (the existing suite already uses
	 * ReflectionMethod for create_tables(); the lock helpers stay private for
	 * the same reason and are exercised through the same seam).
	 *
	 * @param string $method Method name.
	 * @param array  $args   Positional arguments.
	 * @return mixed
	 */
	private static function invoke_private( string $method, array $args = array() ) {
		$ref = new \ReflectionMethod( Activator::class, $method );
		return $ref->invokeArgs( null, $args );
	}

	/**
	 * Read Activator's private UPGRADE_LOCK_TTL constant so the slow-owner test
	 * tracks the real lease rather than a hardcoded duration (a hardcoded value
	 * would silently stop exercising the takeover path if the TTL ever shrank).
	 *
	 * @return int
	 */
	private static function upgrade_lock_ttl(): int {
		$ref = new \ReflectionClassConstant( Activator::class, 'UPGRADE_LOCK_TTL' );
		return (int) $ref->getValue();
	}

	/**
	 * Write the raw lock row directly (mirroring the production INSERT) with a
	 * caller-chosen token and expiry, returning the exact stored value.
	 *
	 * @param int    $expires Unix timestamp the lock expires at.
	 * @param string $token   Owner token to embed.
	 * @return string The exact option_value written.
	 */
	private function insert_lock( int $expires, string $token ): string {
		global $wpdb;
		$value = wp_json_encode(
			array(
				'token'   => $token,
				'expires' => $expires,
			)
		);
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . $wpdb->options . ' WHERE option_name = %s', 'agent_builder_upgrade_lock' ) );
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				'agent_builder_upgrade_lock',
				$value
			)
		);
		return $value;
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
