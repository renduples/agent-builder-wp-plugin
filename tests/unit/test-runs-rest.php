<?php
/**
 * Unit Tests for Runs_REST (M11-2).
 *
 * Covers the capability gates on every /agentic/v1/runs route (subscriber is
 * 403 everywhere; a run_tasks_manually user can create/cancel/retry their own
 * runs but not read someone else's; a manage_agents user reads any run), plus
 * list filtering (status/agent/kind) and the non-admin `user`-param clamp.
 *
 * @package Agentic\Tests
 */

namespace Agentic\Tests;

use Agentic\Agent_Run;
use Agentic\Manifest_Agent;
use Agentic\User_Roles;

/**
 * Test case for Runs_REST.
 */
class Test_Runs_REST extends TestCase {

	/**
	 * Agent slug registered into the shared registry for create/retry.
	 */
	const AGENT = 'wordpress-assistant';

	/**
	 * Register a test agent and reset role settings to defaults before each test.
	 */
	public function setUp(): void {
		parent::setUp();
		\Agentic_Agent_Registry::get_instance()->register( $this->make_agent( self::AGENT ) );
		delete_option( User_Roles::OPTION_KEY );
	}

	/**
	 * Drop any role grants so they never leak into the next test.
	 */
	public function tearDown(): void {
		delete_option( User_Roles::OPTION_KEY );
		parent::tearDown();
	}

	/**
	 * A subscriber is rejected with 403 on every runs route.
	 */
	public function test_subscriber_gets_403_on_every_route(): void {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$responses = array(
			$this->request( 'GET', '/runs' ),
			$this->request( 'POST', '/runs', array( 'agent_id' => self::AGENT, 'task' => 'Do a thing' ) ),
			$this->request( 'GET', '/runs/some-run-id' ),
			$this->request( 'POST', '/runs/some-run-id/cancel' ),
			$this->request( 'POST', '/runs/some-run-id/retry' ),
		);

		foreach ( $responses as $response ) {
			$this->assertSame( 403, $response->get_status() );
		}
	}

	/**
	 * A run_tasks_manually-only user (no manage_agents) can POST /runs and
	 * cancel/retry their own run, but is 403 reading someone else's run detail.
	 */
	public function test_run_tasks_manually_user_scopes_to_own_run(): void {
		$this->grant_plugin_privilege( 'run_tasks_manually', 'editor' );

		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$other  = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$other_run = Agent_Run::create_queued(
			self::AGENT,
			array( 'kind' => 'task', 'user_id' => $other, 'task_text' => 'Other user task' )
		);

		wp_set_current_user( $editor );

		// Reading someone else's run detail is forbidden.
		$read = $this->request( 'GET', '/runs/' . $other_run->get_run_id() );
		$this->assertSame( 403, $read->get_status() );

		// Creating a run works and returns the queued row plus a dispatched job.
		$create = $this->request( 'POST', '/runs', array( 'agent_id' => self::AGENT, 'task' => 'My task' ) );
		$this->assertSame( 201, $create->get_status() );
		$data   = $create->get_data();
		$run_id = $data['run']['run_id'];
		$this->assertSame( 'queued', $data['run']['status'] );
		$this->assertSame( $editor, (int) $data['run']['user_id'] );
		$this->assertNotSame( '', $data['job_id'] );

		// Cancel own run.
		$cancel = $this->request( 'POST', '/runs/' . $run_id . '/cancel' );
		$this->assertSame( 200, $cancel->get_status() );
		$this->assertTrue( $cancel->get_data()['run']['cancel_requested'] );

		// Retry own run spawns a brand-new row, never resuming the old one.
		$retry = $this->request( 'POST', '/runs/' . $run_id . '/retry' );
		$this->assertSame( 201, $retry->get_status() );
		$this->assertNotSame( $run_id, $retry->get_data()['run']['run_id'] );
	}

	/**
	 * A manage_agents user reads any run's detail, regardless of ownership.
	 */
	public function test_manage_agents_user_reads_any_run(): void {
		$this->grant_plugin_privilege( 'manage_agents', 'editor' );

		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$other  = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$other_run = Agent_Run::create_queued(
			self::AGENT,
			array( 'kind' => 'task', 'user_id' => $other, 'task_text' => 'Other user task' )
		);

		wp_set_current_user( $editor );

		$read = $this->request( 'GET', '/runs/' . $other_run->get_run_id() );
		$this->assertSame( 200, $read->get_status() );
		$this->assertSame( $other_run->get_run_id(), $read->get_data()['run']['run_id'] );
	}

	/**
	 * GET /runs filters by status, agent and kind.
	 */
	public function test_get_runs_filters_by_status_agent_and_kind(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$a1 = Agent_Run::create_queued( 'agent-alpha', array( 'kind' => 'task',    'user_id' => $admin, 'task_text' => 'Alpha task' ) );
		$a2 = Agent_Run::create_queued( 'agent-beta',  array( 'kind' => 'routine', 'user_id' => $admin, 'task_text' => 'Beta routine' ) );
		$a3 = Agent_Run::create_queued( 'agent-alpha', array( 'kind' => 'routine', 'user_id' => $admin, 'task_text' => 'Alpha routine' ) );

		$this->set_run_status( $a1->get_run_id(), 'completed' );
		$this->set_run_status( $a2->get_run_id(), 'running' );
		$this->set_run_status( $a3->get_run_id(), 'completed' );

		// By status.
		$runs = $this->request( 'GET', '/runs', array( 'status' => 'completed' ) )->get_data()['runs'];
		$this->assertCount( 2, $runs );
		foreach ( $runs as $run ) {
			$this->assertSame( 'completed', $run['status'] );
		}

		// By agent.
		$runs = $this->request( 'GET', '/runs', array( 'agent' => 'agent-alpha' ) )->get_data()['runs'];
		$this->assertCount( 2, $runs );
		foreach ( $runs as $run ) {
			$this->assertSame( 'agent-alpha', $run['root_agent'] );
		}

		// By kind.
		$runs = $this->request( 'GET', '/runs', array( 'kind' => 'routine' ) )->get_data()['runs'];
		$this->assertCount( 2, $runs );
		foreach ( $runs as $run ) {
			$this->assertSame( 'routine', $run['kind'] );
		}
	}

	/**
	 * A non-admin (no manage_agents) passing a `user` param still only sees
	 * their own runs: the param is ignored and scoped to the current user.
	 */
	public function test_non_admin_user_param_is_ignored(): void {
		$this->grant_plugin_privilege( 'view_dashboard', 'editor' );

		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$other  = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		Agent_Run::create_queued( self::AGENT, array( 'kind' => 'task', 'user_id' => $editor, 'task_text' => 'Editor task' ) );
		Agent_Run::create_queued( self::AGENT, array( 'kind' => 'task', 'user_id' => $other, 'task_text' => 'Other task' ) );

		wp_set_current_user( $editor );

		$resp = $this->request( 'GET', '/runs', array( 'user' => $other ) );
		$this->assertSame( 200, $resp->get_status() );

		$runs = $resp->get_data()['runs'];
		$this->assertCount( 1, $runs );
		$this->assertSame( $editor, (int) $runs[0]['user_id'] );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Dispatch a REST request against the runs routes.
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
	 * @param string $privilege Unprefixed privilege (e.g. `run_tasks_manually`).
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
