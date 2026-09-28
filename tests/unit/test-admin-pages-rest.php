<?php
/**
 * Unit Tests for Admin_Pages_REST::can_manage() capability resolution.
 *
 * @package Agentic\Tests
 */

namespace Agentic\Tests;

use Agentic\Admin_Pages_REST;

/**
 * Test case for the shared admin-page REST permission callback.
 */
class Test_Admin_Pages_REST extends TestCase {

	/**
	 * Set the current user to a subscriber granted only agent_builder_manage_tools.
	 *
	 * @return int User id.
	 */
	private function set_tools_only_user(): int {
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		get_user_by( 'id', $user )->add_cap( 'agent_builder_manage_tools' );
		wp_set_current_user( $user );
		return $user;
	}

	/**
	 * Set the current user to a subscriber granted only agent_builder_manage_agents.
	 *
	 * @return int User id.
	 */
	private function set_agents_only_user(): int {
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		get_user_by( 'id', $user )->add_cap( 'agent_builder_manage_agents' );
		wp_set_current_user( $user );
		return $user;
	}

	/**
	 * Build a request carrying the given page + action params.
	 *
	 * @param string $page   page param.
	 * @param string $action action_name param.
	 * @return \WP_REST_Request
	 */
	private function request( string $page, string $action ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'POST', '/agentic/v1/admin-page' );
		$request->set_param( 'page', $page );
		$request->set_param( 'action_name', $action );
		return $request;
	}

	/**
	 * A user holding only agent_builder_manage_tools must be refused every
	 * agent_* action whatever `page` is sent alongside it — the page-spoof hole
	 * where page=tools cleared the tools branch before the agent_* branch ran.
	 */
	public function test_manage_tools_user_cannot_spoof_agent_actions(): void {
		$this->set_tools_only_user();

		$agent_actions = array(
			'agent_profile_save',
			'agent_toggle',
			'agent_duplicate',
			'agent_reorder',
			'agent_export',
			'agent_delete',
		);

		foreach ( array( 'tools', 'skills', 'agents', 'deployment', 'approvals', '' ) as $page ) {
			foreach ( $agent_actions as $action ) {
				$this->assertFalse(
					Admin_Pages_REST::can_manage( $this->request( $page, $action ) ),
					"Expected manage_tools-only user to be refused {$action} with page='{$page}'"
				);
			}
		}
	}

	/**
	 * A user holding only agent_builder_manage_tools still passes the tools
	 * actions it legitimately owns.
	 */
	public function test_manage_tools_user_passes_tools_actions(): void {
		$this->set_tools_only_user();

		foreach ( array( 'toggle_tool', 'apply_tools_profile', 'delete_skill' ) as $action ) {
			$this->assertTrue(
				Admin_Pages_REST::can_manage( $this->request( 'tools', $action ) ),
				"Expected manage_tools-only user to pass {$action}"
			);
		}
	}

	/**
	 * agent_delete requires manage_options, not the everyday manage_agents.
	 */
	public function test_agent_delete_requires_manage_options(): void {
		$this->set_agents_only_user();

		$this->assertFalse(
			Admin_Pages_REST::can_manage( $this->request( 'agents', 'agent_delete' ) ),
			'Expected agent_delete to be refused for a manage_agents-only user'
		);
	}
}
