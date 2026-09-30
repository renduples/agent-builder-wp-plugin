<?php
/**
 * Unit tests for Skill_Recorder (M15-a) and its REST surface.
 *
 * The hook-wiring tests fire the real `agent_builder_tool_executed` action
 * (after Skill_Recorder::init() wires the listener) rather than calling
 * Skill_Recorder::on_tool_executed() directly, so the append path is exercised
 * through WordPress's actual hook system.
 *
 * @package Agentic\Tests
 */

namespace Agentic\Tests;

use Agentic\Skill_Recorder;

/**
 * Covers the recorder lifecycle, hook wiring, and /skills/recording routes.
 */
class Test_Skill_Recorder extends TestCase {

	/**
	 * User id the recordings in this file run under.
	 */
	private const USER = 77;

	/**
	 * Session id the matching-user tests record.
	 */
	private const SESSION = 'rec-session-1';

	/**
	 * Ensure no leftover recording from a previous test leaks in.
	 */
	public function setUp(): void {
		parent::setUp();
		Skill_Recorder::init();
		$this->clear_recordings();
	}

	/**
	 * Drop any recording this file wrote and reset the current user.
	 */
	public function tearDown(): void {
		$this->clear_recordings();
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Starting twice for the same user refuses the second start.
	 */
	public function test_start_twice_refuses_second_start(): void {
		$first = Skill_Recorder::start( self::USER, self::SESSION );
		$this->assertTrue( $first['ok'] );
		$this->assertSame( self::SESSION, $first['session_id'] );

		$second = Skill_Recorder::start( self::USER, 'another-session' );
		$this->assertFalse( $second['ok'], 'a second start while one is active must be refused' );
	}

	/**
	 * Starting with an empty session id is refused.
	 */
	public function test_start_with_empty_session_is_refused(): void {
		$result = Skill_Recorder::start( self::USER, '' );
		$this->assertFalse( $result['ok'] );
	}

	/**
	 * Stopping with nothing recording errors.
	 */
	public function test_stop_with_nothing_recording_errors(): void {
		$result = Skill_Recorder::stop( self::USER );
		$this->assertFalse( $result['ok'] );
	}

	/**
	 * peek() returns the captured steps without consuming the recording.
	 */
	public function test_peek_returns_steps_without_consuming(): void {
		Skill_Recorder::start( self::USER, self::SESSION );

		do_action(
			'agent_builder_tool_executed',
			'db_create_post',
			array( 'action' => 'create' ),
			array( 'success' => true ),
			array( 'user_id' => self::USER, 'session_id' => self::SESSION, 'action' => 'create' )
		);

		$peeked = Skill_Recorder::peek( self::USER );
		$this->assertTrue( $peeked['ok'] );
		$this->assertCount( 1, $peeked['steps'] );

		// Not consumed: the recording is still active.
		$this->assertTrue( Skill_Recorder::status( self::USER )['recording'] );
	}

	/**
	 * peek() with nothing recording errors.
	 */
	public function test_peek_with_nothing_recording_errors(): void {
		$result = Skill_Recorder::peek( self::USER );
		$this->assertFalse( $result['ok'] );
	}

	/**
	 * A tool call from the recording user+session is appended via the real
	 * agent_builder_tool_executed hook, with success derived from the result.
	 */
	public function test_matching_user_session_appends_via_hook(): void {
		Skill_Recorder::start( self::USER, self::SESSION );

		do_action(
			'agent_builder_tool_executed',
			'db_create_post',
			array( 'action' => 'create', 'title' => 'Recorded post' ),
			array( 'success' => true, 'post_id' => 123 ),
			array( 'user_id' => self::USER, 'session_id' => self::SESSION, 'action' => 'create' )
		);

		do_action(
			'agent_builder_tool_executed',
			'db_update_post',
			array( 'action' => 'update', 'post_id' => 123 ),
			array( 'error' => 'boom' ),
			array( 'user_id' => self::USER, 'session_id' => self::SESSION, 'action' => 'update' )
		);

		$result = Skill_Recorder::stop( self::USER );
		$this->assertTrue( $result['ok'] );
		$this->assertCount( 2, $result['steps'] );

		$first = $result['steps'][0];
		$this->assertSame( 'db_create_post', $first['tool'] );
		$this->assertSame( 'create', $first['action'] );
		$this->assertSame( 'Recorded post', $first['args_summary']['title'] );
		$this->assertTrue( $first['success'] );

		$second = $result['steps'][1];
		$this->assertSame( 'db_update_post', $second['tool'] );
		$this->assertFalse( $second['success'], 'an error result is recorded as a failed step' );
	}

	/**
	 * Tool calls from a different user, or from the same user's other session,
	 * are silently ignored during an active recording.
	 */
	public function test_other_user_or_session_is_ignored(): void {
		Skill_Recorder::start( self::USER, self::SESSION );

		do_action(
			'agent_builder_tool_executed',
			'list_posts',
			array(),
			array( 'success' => true ),
			array( 'user_id' => self::USER + 1, 'session_id' => self::SESSION, 'action' => '' )
		);

		do_action(
			'agent_builder_tool_executed',
			'list_posts',
			array(),
			array( 'success' => true ),
			array( 'user_id' => self::USER, 'session_id' => 'a-different-session', 'action' => '' )
		);

		$result = Skill_Recorder::stop( self::USER );
		$this->assertTrue( $result['ok'] );
		$this->assertCount( 0, $result['steps'], 'foreign user/session calls must not be appended' );
	}

	/**
	 * status() reports the active recording without consuming it.
	 */
	public function test_status_reports_without_consuming(): void {
		Skill_Recorder::start( self::USER, self::SESSION );

		$status = Skill_Recorder::status( self::USER );
		$this->assertTrue( $status['recording'] );
		$this->assertSame( self::SESSION, $status['session_id'] );
		$this->assertSame( 0, $status['step_count'] );
		$this->assertIsInt( $status['started_at'] );

		// Not consumed: a second status still reports active.
		$this->assertTrue( Skill_Recorder::status( self::USER )['recording'] );
	}

	/**
	 * Starting a recording sets a two-hour expiry on the backing transient.
	 */
	public function test_recording_has_two_hour_ttl(): void {
		Skill_Recorder::start( self::USER, self::SESSION );

		$timeout = get_option( '_transient_timeout_agentic_skill_recording_' . self::USER );
		$this->assertNotFalse( $timeout, 'transient timeout option must exist' );

		$expected = time() + 2 * HOUR_IN_SECONDS;
		$this->assertGreaterThanOrEqual( $expected - 5, (int) $timeout, 'TTL must be ~2h, not shorter' );
		$this->assertLessThanOrEqual( $expected + 5, (int) $timeout, 'TTL must be ~2h, not longer' );
	}

	/**
	 * Every /skills/recording route is refused (403) for a user without
	 * agent_builder_manage_tools.
	 */
	public function test_routes_gated_on_manage_tools(): void {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$routes = array(
			array( 'POST', '/skills/recording/start', array( 'session_id' => 's' ) ),
			array( 'POST', '/skills/recording/stop' ),
			array( 'GET', '/skills/recording' ),
		);

		foreach ( $routes as $route ) {
			$resp = $this->request( $route[0], $route[1], $route[2] ?? array() );
			$this->assertSame( 403, $resp->get_status(), "expected 403 for {$route[0]} {$route[1]}" );
		}
	}

	/**
	 * A user holding agent_builder_manage_tools round-trips start → status → stop
	 * through the REST routes.
	 */
	public function test_manage_tools_user_round_trips_rest(): void {
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		get_user_by( 'id', $user )->add_cap( 'agent_builder_manage_tools' );
		wp_set_current_user( $user );

		$start = $this->request( 'POST', '/skills/recording/start', array( 'session_id' => 'rest-session' ) );
		$this->assertSame( 201, $start->get_status() );
		$this->assertTrue( $start->get_data()['ok'] );

		$status = $this->request( 'GET', '/skills/recording' );
		$this->assertSame( 200, $status->get_status() );
		$this->assertTrue( $status->get_data()['recording'] );
		$this->assertSame( 'rest-session', $status->get_data()['session_id'] );

		$stop = $this->request( 'POST', '/skills/recording/stop' );
		$this->assertSame( 200, $stop->get_status() );
		$this->assertSame( array(), $stop->get_data()['steps'] );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Dispatch a REST request against the skill-recorder routes.
	 *
	 * @param string               $method HTTP method.
	 * @param string               $route  Route path (relative to /agentic/v1).
	 * @param array<string, mixed> $json   JSON body to send (POST).
	 * @return \WP_REST_Response
	 */
	private function request( string $method, string $route, array $json = array() ): \WP_REST_Response {
		$req = new \WP_REST_Request( $method, '/agentic/v1' . $route );
		if ( ! empty( $json ) ) {
			$req->set_header( 'Content-Type', 'application/json' );
			$req->set_body( wp_json_encode( $json ) );
		}

		return rest_get_server()->dispatch( $req );
	}

	/**
	 * Remove every recording transient this file might have written.
	 *
	 * @return void
	 */
	private function clear_recordings(): void {
		delete_transient( 'agentic_skill_recording_' . self::USER );
		delete_transient( 'agentic_skill_recording_' . ( self::USER + 1 ) );
	}
}
