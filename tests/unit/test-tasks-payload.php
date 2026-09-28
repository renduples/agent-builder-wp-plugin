<?php
/**
 * Unit Tests for the Tasks payload (M11-3).
 *
 * Covers the dashboard `tasks` summary (shape, active vs. waiting counts, the
 * five-row cap, agent display-name resolution) plus its ownership scoping: a
 * non-admin (no manage_agents) only ever sees their own runs, while an admin
 * sees everyone's — mirroring GET /runs' user clamp. Also covers the
 * Agent_Run::count_waiting() helper that drives the Tasks menu badge.
 *
 * @package Agentic\Tests
 */

namespace Agentic\Tests;

use Agentic\Agent_Run;
use Agentic\Dashboard_REST;
use Agentic\Manifest_Agent;
use Agentic\Provider_Registry;
use Agentic\User_Roles;

/**
 * Test case for the Tasks dashboard payload.
 */
class Test_Tasks_Payload extends TestCase {

	/**
	 * Agent slug registered into the shared registry for name resolution.
	 */
	const AGENT = 'wordpress-assistant';

	/**
	 * Register a test agent, clear role settings and the provider identity
	 * before each test so get_dashboard() builds a clean payload.
	 */
	public function setUp(): void {
		parent::setUp();
		\Agentic_Agent_Registry::get_instance()->register( $this->make_agent( self::AGENT ) );
		delete_option( User_Roles::OPTION_KEY );
		Provider_Registry::save_api_key( 'agentic', '' );
		Provider_Registry::invalidate();
	}

	/**
	 * Drop the registered agent, role grants and provider identity.
	 */
	public function tearDown(): void {
		\Agentic_Agent_Registry::get_instance()->unregister( self::AGENT );
		delete_option( User_Roles::OPTION_KEY );
		Provider_Registry::save_api_key( 'agentic', '' );
		Provider_Registry::invalidate();
		parent::tearDown();
	}

	/**
	 * The dashboard `tasks` summary reports active vs. waiting counts, resolves
	 * the agent's display name, and caps the short list at five rows.
	 */
	public function test_dashboard_tasks_summary_shape_and_counts(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		// Six active runs (to exercise the five-row cap), one waiting, one done.
		for ( $i = 0; $i < 6; $i++ ) {
			Agent_Run::create_queued( self::AGENT, array( 'kind' => 'task', 'user_id' => $admin, 'task_text' => 'Active task ' . $i ) );
		}
		$waiting = Agent_Run::create_queued( self::AGENT, array( 'kind' => 'task', 'user_id' => $admin, 'task_text' => 'Awaiting approval' ) );
		$this->set_run_status( $waiting->get_run_id(), 'waiting' );
		$done = Agent_Run::create_queued( self::AGENT, array( 'kind' => 'task', 'user_id' => $admin, 'task_text' => 'Finished task' ) );
		$this->set_run_status( $done->get_run_id(), 'completed' );

		$tasks = Dashboard_REST::get_dashboard()->get_data()['tasks'];

		$this->assertArrayHasKey( 'active', $tasks );
		$this->assertArrayHasKey( 'waiting', $tasks );
		$this->assertArrayHasKey( 'runs', $tasks );

		// Completed runs are excluded from both counts and the list.
		$this->assertSame( 6, $tasks['active'] );
		$this->assertSame( 1, $tasks['waiting'] );
		$this->assertCount( 5, $tasks['runs'], 'The short list is capped at five rows.' );

		$first = $tasks['runs'][0];
		$this->assertArrayHasKey( 'run_id', $first );
		$this->assertArrayHasKey( 'agent', $first );
		$this->assertArrayHasKey( 'status', $first );
		$this->assertArrayHasKey( 'task_text', $first );
		$this->assertArrayHasKey( 'started_at', $first );
		$this->assertSame( 'Test Agent', $first['agent'], 'Agent display name is resolved from the registry.' );
	}

	/**
	 * A non-admin (view_dashboard, no manage_agents) sees only their own runs in
	 * the summary — another user's run is excluded.
	 */
	public function test_non_admin_sees_only_own_runs(): void {
		$this->grant_plugin_privilege( 'view_dashboard', 'editor' );

		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$other  = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		Agent_Run::create_queued( self::AGENT, array( 'kind' => 'task', 'user_id' => $editor, 'task_text' => 'Editor task' ) );
		$other_run = Agent_Run::create_queued( self::AGENT, array( 'kind' => 'task', 'user_id' => $other, 'task_text' => 'Other task' ) );

		wp_set_current_user( $editor );

		$tasks = Dashboard_REST::get_dashboard()->get_data()['tasks'];

		$this->assertCount( 1, $tasks['runs'] );
		$this->assertSame( 'Editor task', $tasks['runs'][0]['task_text'] );
		$this->assertNotSame( $other_run->get_run_id(), $tasks['runs'][0]['run_id'] );
	}

	/**
	 * An admin sees every user's active/waiting runs in the summary.
	 */
	public function test_admin_sees_all_users_runs(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$other = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$admin_run = Agent_Run::create_queued( self::AGENT, array( 'kind' => 'task', 'user_id' => $admin, 'task_text' => 'Admin task' ) );
		$other_run = Agent_Run::create_queued( self::AGENT, array( 'kind' => 'task', 'user_id' => $other, 'task_text' => 'Other task' ) );

		wp_set_current_user( $admin );

		$tasks = Dashboard_REST::get_dashboard()->get_data()['tasks'];

		$this->assertCount( 2, $tasks['runs'] );

		$run_ids = wp_list_pluck( $tasks['runs'], 'run_id' );
		$this->assertContains( $admin_run->get_run_id(), $run_ids );
		$this->assertContains( $other_run->get_run_id(), $run_ids );
	}

	/**
	 * count_waiting() returns the global waiting-run total with no user id, and
	 * scopes to a single owner when one is passed.
	 */
	public function test_count_waiting_scopes_globally_and_per_user(): void {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$other  = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$run_a = Agent_Run::create_queued( self::AGENT, array( 'kind' => 'task', 'user_id' => $editor, 'task_text' => 'Editor waiting' ) );
		$run_b = Agent_Run::create_queued( self::AGENT, array( 'kind' => 'task', 'user_id' => $other, 'task_text' => 'Other waiting' ) );
		$this->set_run_status( $run_a->get_run_id(), 'waiting' );
		$this->set_run_status( $run_b->get_run_id(), 'waiting' );

		$this->assertSame( 2, Agent_Run::count_waiting(), 'No user id counts every waiting run.' );
		$this->assertSame( 1, Agent_Run::count_waiting( $editor ), 'A user id scopes to that owner only.' );
		$this->assertSame( 1, Agent_Run::count_waiting( $other ), 'A user id scopes to that owner only.' );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

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

	/**
	 * Directly set a run's status (create_queued() always writes 'queued').
	 *
	 * @param string $run_id Run identifier.
	 * @param string $status New status.
	 */
	private function set_run_status( string $run_id, string $status ): void {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'agent_builder_runs',
			array( 'status' => $status ),
			array( 'run_id' => $run_id ),
			array( '%s' ),
			array( '%s' )
		);
	}

	/**
	 * Minimal Manifest_Agent test double for the shared registry.
	 *
	 * @param string $slug Agent slug.
	 * @return Manifest_Agent
	 */
	private function make_agent( string $slug ): Manifest_Agent {
		return new Manifest_Agent(
			array(
				'slug' => $slug,
				'name' => 'Test Agent',
			),
			''
		);
	}
}
