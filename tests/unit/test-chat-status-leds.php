<?php
/**
 * Unit Tests for the chat header status lights (GET /agentic/v1/status).
 *
 * The AI and Credits lights were always grey because /status never reported
 * their state. It now reports `ai` and `credits`, derived from local state only.
 *
 * @package Agentic\Tests
 */

namespace Agentic\Tests;

use Agentic\REST_API;

/**
 * Test case for REST_API::credits_status() and the /status payload.
 */
class Test_Chat_Status_Leds extends TestCase {

	const OPTION = 'agent_builder_hosted_credits';

	/**
	 * Leave no state behind for other tests.
	 */
	public function tearDown(): void {
		delete_option( self::OPTION );
		remove_all_filters( 'agent_builder_low_credits_threshold' );
		parent::tearDown();
	}

	/**
	 * Own-API-key providers do not spend plugin credits.
	 */
	public function test_credits_not_applicable_for_own_key_providers(): void {
		$this->assertSame( 'not_applicable', REST_API::credits_status( 'openai' ) );
	}

	/**
	 * Hosted provider: unknown until a balance is seen, then ok / low / exhausted.
	 */
	public function test_hosted_credits_states(): void {
		delete_option( self::OPTION );
		$this->assertSame( 'unknown', REST_API::credits_status( 'agentic' ) );

		update_option( self::OPTION, array( 'remaining' => 40.0 ) );
		$this->assertSame( 'ok', REST_API::credits_status( 'agentic' ) );

		update_option( self::OPTION, array( 'remaining' => 2.0 ) );
		$this->assertSame( 'low', REST_API::credits_status( 'agentic' ) );

		add_filter( 'agent_builder_low_credits_threshold', static fn() => 1.0 );
		$this->assertSame( 'ok', REST_API::credits_status( 'agentic' ) );

		update_option( self::OPTION, array( 'remaining' => 0 ) );
		$this->assertSame( 'exhausted', REST_API::credits_status( 'agentic' ) );
	}

	/**
	 * GET /status reports the ai and credits states the header lights read.
	 */
	public function test_status_endpoint_reports_light_states(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$resp = rest_do_request( new \WP_REST_Request( 'GET', '/agentic/v1/status' ) );
		$this->assertSame( 200, $resp->get_status() );

		$data = $resp->get_data();
		$this->assertContains( $data['ai'], array( 'ready', 'not_configured' ) );
		$this->assertSame( $data['configured'] ? 'ready' : 'not_configured', $data['ai'] );
		$this->assertContains( $data['credits'], array( 'not_applicable', 'unknown', 'ok', 'low', 'exhausted' ) );
		$this->assertArrayHasKey( 'provider_label', $data );
	}
}
