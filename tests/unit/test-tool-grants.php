<?php
/**
 * Unit Tests for Tool_Grants.
 *
 * Covers the consolidated grant store: how each scope (always / session / run)
 * resolves against a pending enforcement decision, the bare-tool vs tool@agent
 * always-grant forms, and grant()/revoke()/list_for_user().
 *
 * @package Agentic\Tests
 */

namespace Agentic\Tests;

use Agentic\Tool_Grants;

/**
 * Test case for Tool_Grants.
 */
class Test_Tool_Grants extends TestCase {

	/**
	 * An administrator user id, created fresh per test.
	 *
	 * @var int
	 */
	private int $admin_id;

	/**
	 * Create an admin user and clean any grant transient a prior test may have
	 * left behind.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
	}

	/**
	 * Remove the grant transients this suite writes.
	 */
	public function tearDown(): void {
		delete_transient( 'agentic_session_grants_sess1' );
		delete_transient( 'agentic_run_grants_run1' );
		parent::tearDown();
	}

	/**
	 * Build a gate-context array with sensible defaults.
	 *
	 * @param array $overrides Keys to override.
	 * @return array
	 */
	private function ctx( array $overrides = array() ): array {
		return array_merge(
			array(
				'tool'       => 'edit_post',
				'agent_id'   => 'content_writer',
				'user_id'    => $this->admin_id,
				'session_id' => '',
				'run_id'     => '',
				'risk'       => 'medium',
			),
			$overrides
		);
	}

	/**
	 * A bare-tool always grant downgrades a confirm decision to allow.
	 */
	public function test_always_bare_tool_downgrades_confirm(): void {
		update_user_meta( $this->admin_id, 'agentic_tool_grants_always', array( 'edit_post' ) );

		$this->assertSame( 'allow', Tool_Grants::resolve( 'confirm', $this->ctx() ) );
	}

	/**
	 * A bare-tool always grant also bypasses the approval queue.
	 */
	public function test_always_bare_tool_downgrades_queue(): void {
		update_user_meta( $this->admin_id, 'agentic_tool_grants_always', array( 'edit_post' ) );

		$this->assertSame( 'allow', Tool_Grants::resolve( 'queue', $this->ctx() ) );
	}

	/**
	 * A tool@agent always grant matches only the agent it names.
	 */
	public function test_always_agent_scoped_key_matches_only_that_agent(): void {
		update_user_meta( $this->admin_id, 'agentic_tool_grants_always', array( 'edit_post@content_writer' ) );

		$this->assertSame( 'allow', Tool_Grants::resolve( 'confirm', $this->ctx() ) );
		$this->assertSame( 'confirm', Tool_Grants::resolve( 'confirm', $this->ctx( array( 'agent_id' => 'other_agent' ) ) ) );
	}

	/**
	 * A bare-tool always grant covers every agent.
	 */
	public function test_always_bare_tool_matches_any_agent(): void {
		update_user_meta( $this->admin_id, 'agentic_tool_grants_always', array( 'edit_post' ) );

		$this->assertSame( 'allow', Tool_Grants::resolve( 'confirm', $this->ctx( array( 'agent_id' => 'any_agent' ) ) ) );
	}

	/**
	 * The always scope keeps its historic manage_options guard: a non-admin's
	 * stored grant never loosens the decision.
	 */
	public function test_always_grant_requires_manage_options(): void {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		update_user_meta( $subscriber, 'agentic_tool_grants_always', array( 'edit_post' ) );

		$this->assertSame( 'confirm', Tool_Grants::resolve( 'confirm', $this->ctx( array( 'user_id' => $subscriber ) ) ) );
	}

	/**
	 * A session grant downgrades a confirm decision.
	 */
	public function test_session_grant_downgrades_confirm(): void {
		set_transient( 'agentic_session_grants_sess1', array( 'edit_post' ), DAY_IN_SECONDS );

		$this->assertSame( 'allow', Tool_Grants::resolve( 'confirm', $this->ctx( array( 'session_id' => 'sess1' ) ) ) );
	}

	/**
	 * A session grant never bypasses the approval queue (session is confirm-only).
	 */
	public function test_session_grant_does_not_downgrade_queue(): void {
		set_transient( 'agentic_session_grants_sess1', array( 'edit_post' ), DAY_IN_SECONDS );

		$this->assertSame( 'queue', Tool_Grants::resolve( 'queue', $this->ctx( array( 'session_id' => 'sess1' ) ) ) );
	}

	/**
	 * A run grant downgrades both confirm and queue when a run_id is present.
	 */
	public function test_run_grant_downgrades_confirm_and_queue(): void {
		set_transient( 'agentic_run_grants_run1', array( 'edit_post' ), DAY_IN_SECONDS );

		$this->assertSame( 'allow', Tool_Grants::resolve( 'confirm', $this->ctx( array( 'run_id' => 'run1' ) ) ) );
		$this->assertSame( 'allow', Tool_Grants::resolve( 'queue', $this->ctx( array( 'run_id' => 'run1' ) ) ) );
	}

	/**
	 * A run grant is ignored when the context carries no run_id.
	 */
	public function test_run_grant_ignored_without_run_id(): void {
		set_transient( 'agentic_run_grants_run1', array( 'edit_post' ), DAY_IN_SECONDS );

		$this->assertSame( 'confirm', Tool_Grants::resolve( 'confirm', $this->ctx() ) );
	}

	/**
	 * No matching grant leaves the decision unchanged.
	 */
	public function test_no_grant_returns_unchanged(): void {
		$this->assertSame( 'confirm', Tool_Grants::resolve( 'confirm', $this->ctx() ) );
		$this->assertSame( 'queue', Tool_Grants::resolve( 'queue', $this->ctx() ) );
	}

	/**
	 * Terminal decisions are never downgraded by a grant.
	 */
	public function test_terminal_enforcements_are_not_downgraded(): void {
		update_user_meta( $this->admin_id, 'agentic_tool_grants_always', array( 'edit_post' ) );

		$this->assertSame( 'allow', Tool_Grants::resolve( 'allow', $this->ctx() ) );
		$this->assertSame( 'block', Tool_Grants::resolve( 'block', $this->ctx() ) );
	}

	/**
	 * grant() writes a bare-tool always grant to user meta.
	 */
	public function test_grant_always_writes_user_meta(): void {
		Tool_Grants::grant( 'always', 'edit_post', array( 'user_id' => $this->admin_id ) );

		$this->assertContains( 'edit_post', Tool_Grants::list_for_user( $this->admin_id ) );
	}

	/**
	 * grant() accepts a tool@agent key for the always scope.
	 */
	public function test_grant_always_accepts_agent_scoped_key(): void {
		Tool_Grants::grant( 'always', 'edit_post@content_writer', array( 'user_id' => $this->admin_id ) );

		$this->assertContains( 'edit_post@content_writer', Tool_Grants::list_for_user( $this->admin_id ) );
	}

	/**
	 * grant() is idempotent for the always scope.
	 */
	public function test_grant_always_is_idempotent(): void {
		Tool_Grants::grant( 'always', 'edit_post', array( 'user_id' => $this->admin_id ) );
		Tool_Grants::grant( 'always', 'edit_post', array( 'user_id' => $this->admin_id ) );

		$this->assertCount( 1, Tool_Grants::list_for_user( $this->admin_id ) );
	}

	/**
	 * grant() writes a session grant to its transient.
	 */
	public function test_grant_session_writes_transient(): void {
		Tool_Grants::grant( 'session', 'edit_post', array( 'session_id' => 'sess1' ) );

		$this->assertContains( 'edit_post', get_transient( 'agentic_session_grants_sess1' ) );
	}

	/**
	 * grant() writes a run grant to its transient.
	 */
	public function test_grant_run_writes_transient(): void {
		Tool_Grants::grant( 'run', 'edit_post', array( 'run_id' => 'run1' ) );

		$this->assertContains( 'edit_post', get_transient( 'agentic_run_grants_run1' ) );
	}

	/**
	 * revoke() removes an always grant.
	 */
	public function test_revoke_always_removes_grant(): void {
		Tool_Grants::grant( 'always', 'edit_post', array( 'user_id' => $this->admin_id ) );
		Tool_Grants::revoke( 'always', 'edit_post', array( 'user_id' => $this->admin_id ) );

		$this->assertNotContains( 'edit_post', Tool_Grants::list_for_user( $this->admin_id ) );
	}

	/**
	 * The DELETE /tool-grants/{tool} route revokes an agent-scoped `tool@agent`
	 * key: the route regex must match the `@`, and the handler must not strip it
	 * out via sanitize_key(), so the key round-trips through revoke and is
	 * removed from list_for_user().
	 */
	public function test_revoke_agent_scoped_grant_via_rest(): void {
		wp_set_current_user( $this->admin_id );
		Tool_Grants::grant( 'always', 'edit_post@content_writer', array( 'user_id' => $this->admin_id ) );
		$this->assertContains( 'edit_post@content_writer', Tool_Grants::list_for_user( $this->admin_id ) );

		$request  = new \WP_REST_Request( 'DELETE', '/agentic/v1/tool-grants/edit_post@content_writer' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertNotContains( 'edit_post@content_writer', Tool_Grants::list_for_user( $this->admin_id ) );
	}

	/**
	 * revoke() removes a session grant.
	 */
	public function test_revoke_session_removes_grant(): void {
		Tool_Grants::grant( 'session', 'edit_post', array( 'session_id' => 'sess1' ) );
		Tool_Grants::revoke( 'session', 'edit_post', array( 'session_id' => 'sess1' ) );

		$this->assertNotContains( 'edit_post', get_transient( 'agentic_session_grants_sess1' ) );
	}

	/**
	 * list_for_user() returns an empty list when the user has no grants.
	 */
	public function test_list_for_user_returns_empty_when_none(): void {
		$this->assertSame( array(), Tool_Grants::list_for_user( $this->admin_id ) );
	}
}
