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
}
