<?php
/**
 * Unit tests for the activation-hardening behaviour in class-activator.php:
 * safe mode, the deferred-seed flag, and the "reduced state" retry path.
 *
 * @package Agentic\Tests
 */

namespace Agentic\Tests;

use Agentic\Activator;

/**
 * Activation must stay light and never brick the site: heavy seeding is
 * deferred behind a flag, and errors flip a degraded flag rather than fatal.
 */
class Test_Activation_Hardening extends TestCase {

	/**
	 * Options this suite touches, snapshotted and restored in tearDown so it
	 * cannot leak state into other tests.
	 *
	 * @var array<string, mixed>
	 */
	private array $previous_options = array();

	/**
	 * Snapshot the options this suite mutates.
	 */
	public function setUp(): void {
		parent::setUp();
		foreach ( array( 'agent_builder_needs_seed', 'agent_builder_seed_progress', 'agent_builder_activation_degraded' ) as $key ) {
			$this->previous_options[ $key ] = get_option( $key, false );
			delete_option( $key );
		}
	}

	/**
	 * Restore snapshotted options and clear any cron this suite scheduled.
	 */
	public function tearDown(): void {
		foreach ( $this->previous_options as $key => $value ) {
			if ( false === $value ) {
				delete_option( $key );
			} else {
				update_option( $key, $value );
			}
		}
		wp_clear_scheduled_hook( 'agent_builder_cleanup_audit_log' );
		delete_transient( 'agent_builder_seed_lock' );
		wp_set_current_user( 0 );
		unset( $GLOBALS['current_screen'] );
		parent::tearDown();
	}

	/**
	 * AGENT_BUILDER_SAFE_MODE is not defined in the test environment.
	 */
	public function test_is_safe_mode_is_false_by_default(): void {
		$this->assertFalse( Activator::is_safe_mode() );
	}

	/**
	 * With no agent_builder_needs_seed flag, the deferred seeder is a no-op:
	 * it must not flip the degraded flag or otherwise touch state.
	 */
	public function test_deferred_seed_is_noop_without_needs_seed_flag(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		Activator::maybe_run_deferred_seed();

		$this->assertFalse( get_option( 'agent_builder_needs_seed' ) );
		$this->assertFalse( get_option( 'agent_builder_activation_degraded' ) );
	}

	/**
	 * The deferred seeder must not run for a request with no logged-in admin
	 * — heavy seeding is only ever triggered by an actual wp-admin visit.
	 */
	public function test_deferred_seed_is_noop_for_non_admin(): void {
		update_option( 'agent_builder_needs_seed', true );
		wp_set_current_user( 0 );

		Activator::maybe_run_deferred_seed();

		// Still armed — nothing consumed the flag because no admin ran it.
		$this->assertNotFalse( get_option( 'agent_builder_needs_seed' ) );
	}

	/**
	 * A concurrent/overlapping deferred-seed request is locked out: while the
	 * transient lock is held, a second call must not attempt any work.
	 */
	public function test_deferred_seed_is_locked_against_concurrent_runs(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		update_option( 'agent_builder_needs_seed', true );
		set_transient( 'agent_builder_seed_lock', 1, 30 );

		Activator::maybe_run_deferred_seed();

		// Locked out — the flag must still be armed, no progress recorded.
		$this->assertNotFalse( get_option( 'agent_builder_needs_seed' ) );
		$this->assertFalse( get_option( 'agent_builder_seed_progress' ) );
	}

	/**
	 * retry_activation() clears the degraded flag and re-arms deferred
	 * seeding, so the "Click to retry" notice link can simply call it.
	 */
	public function test_retry_activation_clears_degraded_and_arms_seed(): void {
		update_option( 'agent_builder_activation_degraded', true );
		delete_option( 'agent_builder_needs_seed' );

		Activator::retry_activation();

		$this->assertFalse( get_option( 'agent_builder_activation_degraded' ) );
		$this->assertTrue( get_option( 'agent_builder_needs_seed' ) );
	}

	/**
	 * Cron safety: outside safe mode, maybe_disable_cron_for_safe_mode() must
	 * never touch an already-scheduled event.
	 */
	public function test_cron_untouched_when_not_in_safe_mode(): void {
		wp_schedule_event( time(), 'daily', 'agent_builder_cleanup_audit_log' );

		Activator::maybe_disable_cron_for_safe_mode();

		$this->assertNotFalse( wp_next_scheduled( 'agent_builder_cleanup_audit_log' ) );
	}
}
