<?php
/**
 * Unit Tests for Notifications_REST (M11 §7).
 *
 * Covers the view_dashboard gate on /agentic/v1/notifications and
 * /agentic/v1/notifications/read (subscriber is 403 everywhere), the unread
 * filter and per-user scoping of the list, marking read by ids or all, and the
 * Tasks-screen integration that finally gives mark_read() a production caller
 * (opening the screen clears the badge).
 *
 * @package Agentic\Tests
 */

namespace Agentic\Tests;

use Agentic\Admin_Menu_Handler;
use Agentic\Notifications;
use Agentic\User_Roles;

/**
 * Test case for Notifications_REST.
 */
class Test_Notifications_REST extends TestCase {

	/**
	 * Reset role settings before each test so grants never leak across tests.
	 */
	public function setUp(): void {
		parent::setUp();
		delete_option( User_Roles::OPTION_KEY );
	}

	/**
	 * Drop any role grants and reset the current user.
	 */
	public function tearDown(): void {
		delete_option( User_Roles::OPTION_KEY );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * A subscriber is rejected with 403 on both notifications routes.
	 */
	public function test_subscriber_gets_403_on_every_route(): void {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$responses = array(
			$this->request( 'GET', '/notifications' ),
			$this->request( 'POST', '/notifications/read', array( 'all' => true ) ),
			$this->request( 'POST', '/notifications/read', array( 'ids' => array( 1, 2 ) ) ),
		);

		foreach ( $responses as $response ) {
			$this->assertSame( 403, $response->get_status() );
		}
	}

	/**
	 * GET /notifications returns the current user's inbox newest first.
	 */
	public function test_get_notifications_lists_current_users_inbox(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		Notifications::notify( $admin, 'run_finished', 'First', '' );
		Notifications::notify( $admin, 'run_waiting', 'Second', '' );

		$resp = $this->request( 'GET', '/notifications' );
		$this->assertSame( 200, $resp->get_status() );

		$rows = $resp->get_data()['notifications'];
		$this->assertCount( 2, $rows );
		$this->assertSame( 'Second', $rows[0]['title'] );
		$this->assertSame( 'First', $rows[1]['title'] );
	}

	/**
	 * GET /notifications?unread=1 returns only unread rows.
	 */
	public function test_get_notifications_unread_filter(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$read_id = Notifications::notify( $admin, 'run_finished', 'Read', '' );
		Notifications::notify( $admin, 'run_finished', 'Unread', '' );
		Notifications::mark_read( $admin, array( $read_id ) );

		$resp = $this->request( 'GET', '/notifications', array( 'unread' => true ) );
		$this->assertSame( 200, $resp->get_status() );

		$rows = $resp->get_data()['notifications'];
		$this->assertCount( 1, $rows );
		$this->assertSame( 'Unread', $rows[0]['title'] );
	}

	/**
	 * A view_dashboard (non-admin) user can list their own notifications.
	 */
	public function test_view_dashboard_user_can_list(): void {
		$this->grant_plugin_privilege( 'view_dashboard', 'editor' );

		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$other  = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		Notifications::notify( $editor, 'run_finished', 'Mine', '' );
		Notifications::notify( $other, 'run_finished', 'Theirs', '' );

		wp_set_current_user( $editor );

		$resp = $this->request( 'GET', '/notifications' );
		$this->assertSame( 200, $resp->get_status() );

		$rows = $resp->get_data()['notifications'];
		$this->assertCount( 1, $rows );
		$this->assertSame( 'Mine', $rows[0]['title'] );
	}

	/**
	 * POST /notifications/read with `all` clears the whole inbox and reports the
	 * new (zero) unread count.
	 */
	public function test_post_read_all_marks_everything_read(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		Notifications::notify( $admin, 'run_finished', 'One', '' );
		Notifications::notify( $admin, 'run_waiting', 'Two', '' );

		$this->assertSame( 2, Notifications::unread_count( $admin ) );

		$resp = $this->request( 'POST', '/notifications/read', array( 'all' => true ) );
		$this->assertSame( 200, $resp->get_status() );
		$this->assertSame( 0, $resp->get_data()['unread'] );
		$this->assertSame( 0, Notifications::unread_count( $admin ) );
	}

	/**
	 * POST /notifications/read with `ids` marks only those rows read.
	 */
	public function test_post_read_ids_marks_only_those_rows(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$id1 = Notifications::notify( $admin, 'run_finished', 'One', '' );
		Notifications::notify( $admin, 'run_finished', 'Two', '' );

		$resp = $this->request( 'POST', '/notifications/read', array( 'ids' => array( $id1 ) ) );
		$this->assertSame( 200, $resp->get_status() );
		$this->assertSame( 1, $resp->get_data()['unread'] );
		$this->assertSame( 1, Notifications::unread_count( $admin ) );
	}

	/**
	 * POST /notifications/read is scoped to the current user: another user's ids
	 * are never marked read.
	 */
	public function test_post_read_respects_user_scope(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$other = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $admin );

		$other_id = Notifications::notify( $other, 'run_finished', 'Theirs', '' );

		$resp = $this->request( 'POST', '/notifications/read', array( 'ids' => array( $other_id ) ) );
		$this->assertSame( 200, $resp->get_status() );

		// The other user's notification is untouched.
		$this->assertSame( 1, Notifications::unread_count( $other ) );
	}

	/**
	 * Opening the Tasks screen marks the current user's notifications read, so
	 * the badge the admin-bar/submenu read from unread_count() actually shrinks.
	 *
	 * Regression for the M11 gate: mark_read() previously had no production
	 * caller outside tests, so the badge could only ever grow.
	 */
	public function test_opening_tasks_screen_marks_notifications_read(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		Notifications::notify( $admin, 'run_finished', 'One', '' );
		Notifications::notify( $admin, 'run_waiting', 'Two', '' );
		$this->assertSame( 2, Notifications::unread_count( $admin ) );

		( new Admin_Menu_Handler() )->mark_tasks_notifications_read();

		$this->assertSame( 0, Notifications::unread_count( $admin ) );
	}

	/**
	 * The Tasks-screen read action is a no-op when no user is logged in.
	 */
	public function test_opening_tasks_screen_without_user_is_noop(): void {
		wp_set_current_user( 0 );

		( new Admin_Menu_Handler() )->mark_tasks_notifications_read();

		// Nothing to assert beyond that it does not error/fatal.
		$this->assertTrue( true );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Dispatch a REST request against the notifications routes.
	 *
	 * @param string               $method HTTP method.
	 * @param string               $route  Route path (relative to /agentic/v1).
	 * @param array<string, mixed> $params Request params.
	 * @return \WP_REST_Response
	 */
	private function request( string $method, string $route, array $params = array() ): \WP_REST_Response {
		$req = new \WP_REST_Request( $method, '/agentic/v1' . $route );
		foreach ( $params as $key => $value ) {
			$req->set_param( $key, $value );
		}

		return rest_get_server()->dispatch( $req );
	}

	/**
	 * Grant a plugin privilege to a WordPress role via the settings option.
	 *
	 * @param string $privilege Unprefixed privilege (e.g. `view_dashboard`).
	 * @param string $role      WordPress role slug.
	 */
	private function grant_plugin_privilege( string $privilege, string $role ): void {
		$settings                         = User_Roles::get_settings();
		$settings['plugin'][ $privilege ] = array( $role );
		update_option( User_Roles::OPTION_KEY, $settings );
	}
}
