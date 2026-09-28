<?php
/**
 * Unit Tests for the agent-activity email setting (M11 §1).
 *
 * Covers the Security tab's `notify_email` control: it is exposed in the
 * bootstrap payload, persisted by the settings save path, and rejects invalid
 * values. Also locks the capability gate on the settings route.
 *
 * @package Agentic\Tests
 */

namespace Agentic\Tests;

/**
 * Test case for the settings-REST email toggle.
 */
class Test_Admin_Settings_REST extends TestCase {

	/**
	 * Reset the email-mode option so a save never leaks into the next test.
	 */
	public function setUp(): void {
		parent::setUp();
		delete_option( 'agent_builder_notify_email' );
	}

	/**
	 * Reset the email-mode option and current user.
	 */
	public function tearDown(): void {
		delete_option( 'agent_builder_notify_email' );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * A subscriber is rejected from the settings route.
	 */
	public function test_subscriber_cannot_access_settings(): void {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$resp = $this->request( 'GET', '/admin-settings' );
		$this->assertSame( 403, $resp->get_status() );
	}

	/**
	 * The bootstrap exposes `notify_email` (defaulting to daily) on Security.
	 */
	public function test_security_bootstrap_exposes_notify_email(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$resp = $this->request( 'GET', '/admin-settings' );
		$this->assertSame( 200, $resp->get_status() );

		$data = $resp->get_data()['data']['security'];
		$this->assertSame( 'daily', $data['notify_email'] );
	}

	/**
	 * Saving the Security tab persists a valid `notify_email` value.
	 */
	public function test_save_security_persists_notify_email(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$resp = $this->request(
			'POST',
			'/admin-settings',
			array(
				'tab'  => 'security',
				'data' => array( 'notify_email' => 'off' ),
			)
		);

		$this->assertSame( 200, $resp->get_status() );
		$this->assertSame( 'off', get_option( 'agent_builder_notify_email' ) );
	}

	/**
	 * An invalid `notify_email` value is rejected (no write).
	 */
	public function test_save_security_rejects_invalid_notify_email(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		update_option( 'agent_builder_notify_email', 'instant' );

		$resp = $this->request(
			'POST',
			'/admin-settings',
			array(
				'tab'  => 'security',
				'data' => array( 'notify_email' => 'bogus' ),
			)
		);

		$this->assertSame( 200, $resp->get_status() );
		$this->assertSame( 'instant', get_option( 'agent_builder_notify_email' ) );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Dispatch a REST request against the settings routes.
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
}
