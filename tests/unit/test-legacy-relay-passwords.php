<?php
/**
 * Tests for revoking the old relay Application Passwords on upgrade.
 *
 * @package Agentic\Tests
 */

namespace Agentic\Tests;

use Agentic\Legacy_Relay_Passwords;

/**
 * Relay Application Passwords are revoked once; everything else is left alone.
 */
class Test_Legacy_Relay_Passwords extends TestCase {

	/**
	 * Reset the one-time flags.
	 */
	public function setUp(): void {
		parent::setUp();
		delete_option( Legacy_Relay_Passwords::DONE_OPTION );
		delete_option( Legacy_Relay_Passwords::NOTICE_OPTION );
	}

	/**
	 * Clean up.
	 */
	public function tearDown(): void {
		delete_option( Legacy_Relay_Passwords::DONE_OPTION );
		delete_option( Legacy_Relay_Passwords::NOTICE_OPTION );
		parent::tearDown();
	}

	/**
	 * Create an Application Password and return its uuid.
	 *
	 * @param int    $user_id User.
	 * @param string $name    Password name.
	 * @return string
	 */
	private function make_password( int $user_id, string $name ): string {
		$created = \WP_Application_Passwords::create_new_application_password( $user_id, array( 'name' => $name ) );
		$this->assertIsArray( $created, 'Could not create Application Password ' . $name );
		return (string) $created[1]['uuid'];
	}

	/**
	 * Names of a user's remaining Application Passwords.
	 *
	 * @param int $user_id User.
	 * @return string[]
	 */
	private function names( int $user_id ): array {
		$names = array_map(
			static fn( $p ) => (string) $p['name'],
			\WP_Application_Passwords::get_user_application_passwords( $user_id )
		);
		sort( $names );
		return $names;
	}

	/**
	 * Name matching covers the original, renamed copies and TestMCPrelay only.
	 */
	public function test_is_relay_name(): void {
		$this->assertTrue( Legacy_Relay_Passwords::is_relay_name( 'Agent Builder Relay' ) );
		$this->assertTrue( Legacy_Relay_Passwords::is_relay_name( 'Agent Builder Relay (rotated 2026-10-05)' ) );
		$this->assertTrue( Legacy_Relay_Passwords::is_relay_name( 'TestMCPrelay' ) );
		$this->assertFalse( Legacy_Relay_Passwords::is_relay_name( 'Claude Desktop' ) );
		$this->assertFalse( Legacy_Relay_Passwords::is_relay_name( 'My Agent Builder Relay' ) );
		$this->assertFalse( Legacy_Relay_Passwords::is_relay_name( 'testmcprelay' ) );
	}

	/**
	 * Relay passwords are revoked for every user, others are untouched, the
	 * count is logged and the notice queued; a second run is a no-op.
	 */
	public function test_upgrade_revokes_relay_passwords_once(): void {
		$admin  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );

		$this->make_password( $admin, 'Agent Builder Relay' );
		$this->make_password( $admin, 'Agent Builder Relay (rotated 2026-10-05)' );
		$this->make_password( $admin, 'Claude Desktop' );
		$this->make_password( $editor, 'TestMCPrelay' );
		$this->make_password( $editor, 'Cursor' );

		$revoked = Legacy_Relay_Passwords::maybe_revoke_on_upgrade();

		$this->assertSame( 3, $revoked );
		$this->assertSame( array( 'Claude Desktop' ), $this->names( $admin ) );
		$this->assertSame( array( 'Cursor' ), $this->names( $editor ) );
		$this->assertSame( 3, (int) get_option( Legacy_Relay_Passwords::NOTICE_OPTION ) );
		$this->assertNotFalse( get_option( Legacy_Relay_Passwords::DONE_OPTION, false ) );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$details = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT details FROM {$wpdb->prefix}agent_builder_audit_log WHERE action = %s ORDER BY id DESC LIMIT 1",
				'relay_passwords_revoked'
			)
		);
		$this->assertNotNull( $details, 'The revocation is written to the audit log.' );
		$this->assertSame( 3, (int) ( json_decode( (string) $details, true )['count'] ?? 0 ) );

		// A relay password created after the one-time run is not touched again.
		$this->make_password( $admin, 'Agent Builder Relay' );
		$this->assertSame( 0, Legacy_Relay_Passwords::maybe_revoke_on_upgrade() );
		$this->assertSame( array( 'Agent Builder Relay', 'Claude Desktop' ), $this->names( $admin ) );
	}

	/**
	 * Nothing to revoke: no notice is queued, and the run is still marked done.
	 */
	public function test_no_relay_passwords_queues_no_notice(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->make_password( $admin, 'Claude Desktop' );

		$this->assertSame( 0, Legacy_Relay_Passwords::maybe_revoke_on_upgrade() );
		$this->assertFalse( get_option( Legacy_Relay_Passwords::NOTICE_OPTION, false ) );
		$this->assertNotFalse( get_option( Legacy_Relay_Passwords::DONE_OPTION, false ) );
		$this->assertSame( array( 'Claude Desktop' ), $this->names( $admin ) );
	}

	/**
	 * The notice shows on Siteloom screens with a plural-aware count, and not
	 * on other admin screens.
	 */
	public function test_notice_only_on_plugin_screens(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		update_option( Legacy_Relay_Passwords::NOTICE_OPTION, 2, false );

		$_GET['page'] = 'agentic-settings';
		ob_start();
		Legacy_Relay_Passwords::show_notice();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'Siteloom removed 2 old relay connection passwords.', $html );
		$this->assertStringContainsString( 'data-dismiss-url', $html );

		$_GET['page'] = 'some-other-plugin';
		ob_start();
		Legacy_Relay_Passwords::show_notice();
		$this->assertSame( '', trim( (string) ob_get_clean() ) );
		unset( $_GET['page'] );
	}
}
