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
use Agentic\Job_Manager;
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
	 * Drop any role grants and the registered test agent so they never leak into
	 * the next test.
	 */
	public function tearDown(): void {
		\Agentic_Agent_Registry::get_instance()->unregister( self::AGENT );
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

		// A retry is refused while the run is still active, so settle it first.
		$this->set_run_status( $run_id, 'cancelled' );

		// Retry own run spawns a brand-new row, never resuming the old one.
		$retry = $this->request( 'POST', '/runs/' . $run_id . '/retry' );
		$this->assertSame( 201, $retry->get_status() );
		$this->assertNotSame( $run_id, $retry->get_data()['run']['run_id'] );
	}

	/**
	 * A run_tasks_manually-only user (no manage_agents, no view_dashboard) can
	 * list runs — the Tasks screen is opened by exactly that capability, so its
	 * list must not 403. The result is still scoped to their own runs.
	 */
	public function test_run_tasks_manually_user_can_list_own_runs(): void {
		$this->grant_plugin_privilege( 'run_tasks_manually', 'editor' );

		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$other  = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		Agent_Run::create_queued( self::AGENT, array( 'kind' => 'task', 'user_id' => $editor, 'task_text' => 'Editor task' ) );
		Agent_Run::create_queued( self::AGENT, array( 'kind' => 'task', 'user_id' => $other, 'task_text' => 'Other task' ) );

		wp_set_current_user( $editor );

		$resp = $this->request( 'GET', '/runs' );
		$this->assertSame( 200, $resp->get_status() );

		$runs = $resp->get_data()['runs'];
		$this->assertCount( 1, $runs );
		$this->assertSame( $editor, (int) $runs[0]['user_id'] );
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

	/**
	 * A run_tasks_manually-only user (no manage_agents) is 403 on cancel/retry
	 * of another user's run — the capability gates create on your own runs, but
	 * only ownership or manage_agents reaches a specific run.
	 */
	public function test_run_tasks_manually_user_cannot_cancel_or_retry_others_run(): void {
		$this->grant_plugin_privilege( 'run_tasks_manually', 'editor' );

		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$other  = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$other_run = Agent_Run::create_queued(
			self::AGENT,
			array( 'kind' => 'task', 'user_id' => $other, 'task_text' => 'Other user task' )
		);

		wp_set_current_user( $editor );

		$cancel = $this->request( 'POST', '/runs/' . $other_run->get_run_id() . '/cancel' );
		$this->assertSame( 403, $cancel->get_status() );

		$retry = $this->request( 'POST', '/runs/' . $other_run->get_run_id() . '/retry' );
		$this->assertSame( 403, $retry->get_status() );
	}

	/**
	 * Retrying a run whose agent is no longer registered returns the same
	 * invalid_agent error POST /runs would, instead of cloning a doomed run.
	 */
	public function test_retry_unknown_agent_returns_invalid_agent(): void {
		$this->grant_plugin_privilege( 'run_tasks_manually', 'editor' );

		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );

		// create_queued() does not validate the agent, so this run references an
		// agent that is absent from the registry.
		$run = Agent_Run::create_queued(
			'nonexistent-agent',
			array( 'kind' => 'task', 'user_id' => $editor, 'task_text' => 'Do a thing' )
		);
		$this->set_run_status( $run->get_run_id(), 'failed' );

		$retry = $this->request( 'POST', '/runs/' . $run->get_run_id() . '/retry' );
		$this->assertSame( 400, $retry->get_status() );
		$this->assertSame( 'invalid_agent', $retry->as_error()->get_error_code() );
	}

	/**
	 * A retry carries the original run's skill_slug forward into the new job.
	 */
	public function test_retry_carries_forward_skill_slug(): void {
		$this->grant_plugin_privilege( 'run_tasks_manually', 'editor' );

		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );

		$create = $this->request(
			'POST',
			'/runs',
			array( 'agent_id' => self::AGENT, 'task' => 'My task', 'skill_slug' => 'pro-brief' )
		);
		$this->assertSame( 201, $create->get_status() );
		$run_id = $create->get_data()['run']['run_id'];

		$this->set_run_status( $run_id, 'completed' );

		$retry = $this->request( 'POST', '/runs/' . $run_id . '/retry' );
		$this->assertSame( 201, $retry->get_status() );

		$retry_job = \Agentic\Job_Manager::get_job( $retry->get_data()['job_id'] );
		$this->assertNotNull( $retry_job );
		$this->assertSame( 'pro-brief', (string) ( $retry_job->request_data['skill_slug'] ?? '' ) );
	}

	/**
	 * Retrying a still-queued or still-running run is refused with 409 so a
	 * second concurrent run is never spawned against the same task_text.
	 */
	public function test_retry_active_run_returns_conflict(): void {
		$this->grant_plugin_privilege( 'run_tasks_manually', 'editor' );

		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );

		$run = Agent_Run::create_queued(
			self::AGENT,
			array( 'kind' => 'task', 'user_id' => $editor, 'task_text' => 'Do a thing' )
		);

		$retry = $this->request( 'POST', '/runs/' . $run->get_run_id() . '/retry' );
		$this->assertSame( 409, $retry->get_status() );
		$this->assertSame( 'already_active', $retry->as_error()->get_error_code() );
	}

	/**
	 * Retrying a run paused on the user (`waiting`) or mid-resume (`continuing`)
	 * is refused with 409, just like `queued`/`running` — none of the non-terminal
	 * states may be retried, or a second concurrent run is spawned.
	 */
	public function test_retry_refuses_waiting_and_continuing(): void {
		$this->grant_plugin_privilege( 'run_tasks_manually', 'editor' );

		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );

		foreach ( array( 'waiting', 'continuing' ) as $status ) {
			$run = Agent_Run::create_queued(
				self::AGENT,
				array( 'kind' => 'task', 'user_id' => $editor, 'task_text' => 'Do a thing' )
			);
			$this->set_run_status( $run->get_run_id(), $status );

			$retry = $this->request( 'POST', '/runs/' . $run->get_run_id() . '/retry' );
			$this->assertSame( 409, $retry->get_status(), "retry of {$status} should conflict" );
			$this->assertSame( 'already_active', $retry->as_error()->get_error_code() );
		}
	}

	/**
	 * POST /runs rejects an agent the current user cannot reach: an agent that
	 * exists in the registry but is absent from their accessible list is 403
	 * (not 400), with a clear message.
	 */
	public function test_create_run_rejects_agent_outside_accessible_list(): void {
		$this->grant_plugin_privilege( 'run_tasks_manually', 'editor' );

		$slug = 'admin-only-agent';
		\Agentic_Agent_Registry::get_instance()->register( $this->make_restricted_agent( $slug ) );

		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );

		$create = $this->request( 'POST', '/runs', array( 'agent_id' => $slug, 'task' => 'Do a thing' ) );
		$this->assertSame( 403, $create->get_status() );
		$this->assertSame( 'forbidden', $create->as_error()->get_error_code() );

		\Agentic_Agent_Registry::get_instance()->unregister( $slug );
	}

	/**
	 * POST /runs/{id}/retry also rejects an agent the current user cannot reach:
	 * even though the user owns the (failed) run, the agent behind it is outside
	 * their accessible list, so the retry is 403 rather than spawning a doomed
	 * clone.
	 */
	public function test_retry_rejects_agent_outside_accessible_list(): void {
		$this->grant_plugin_privilege( 'run_tasks_manually', 'editor' );

		$slug = 'admin-only-agent';
		\Agentic_Agent_Registry::get_instance()->register( $this->make_restricted_agent( $slug ) );

		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );

		// create_queued() does not validate agent access, so this run references
		// an agent the editor cannot reach.
		$run = Agent_Run::create_queued(
			$slug,
			array( 'kind' => 'task', 'user_id' => $editor, 'task_text' => 'Do a thing' )
		);
		$this->set_run_status( $run->get_run_id(), 'failed' );

		$retry = $this->request( 'POST', '/runs/' . $run->get_run_id() . '/retry' );
		$this->assertSame( 403, $retry->get_status() );
		$this->assertSame( 'forbidden', $retry->as_error()->get_error_code() );

		\Agentic_Agent_Registry::get_instance()->unregister( $slug );
	}

	/**
	 * A pending job whose WP-Cron event was lost is re-armed by the GET /runs
	 * endpoint (which calls reschedule_stale_pending_jobs() before querying).
	 */
	public function test_get_runs_reschedules_stale_pending_job(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$job_id = Job_Manager::create_job(
			array(
				'user_id'      => $admin,
				'agent_id'     => self::AGENT,
				'request_data' => array( 'run_id' => 'r-stale' ),
				'processor'    => \Agentic\Agent_Task_Job_Processor::class,
			)
		);

		// create_job() schedules the event immediately.
		$this->assertNotFalse( wp_next_scheduled( 'agent_builder_process_job', array( $job_id ) ) );

		// Simulate the event disappearing out from under the still-pending job.
		wp_clear_scheduled_hook( 'agent_builder_process_job', array( $job_id ) );
		$this->assertFalse( wp_next_scheduled( 'agent_builder_process_job', array( $job_id ) ) );

		// Age the pending job past the 60s grace window so it qualifies.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test back-dates the job so it is "stale".
		$wpdb->update(
			$wpdb->prefix . 'agent_builder_jobs',
			array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 120 ) ),
			array( 'id' => $job_id ),
			array( '%s' ),
			array( '%s' )
		);

		$resp = $this->request( 'GET', '/runs' );
		$this->assertSame( 200, $resp->get_status() );

		// The GET /runs endpoint re-armed the lost cron event for the stale job.
		$this->assertNotFalse( wp_next_scheduled( 'agent_builder_process_job', array( $job_id ) ) );
	}

	/**
	 * GET /runs/{run_id} exposes the per-run step list the live pane polls,
	 * backed by real Audit_Log rows correlated to the run via Agent_Run::current()
	 * — not a fabricated or empty array.
	 */
	public function test_run_detail_returns_run_backed_steps(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		// begin() makes the run current, so the Audit_Log row below is correlated
		// to this run_id the same way the controller correlates its tool calls.
		$run = Agent_Run::begin(
			self::AGENT,
			array( 'kind' => 'task', 'user_id' => $admin, 'task_text' => 'Summarise the newest posts' )
		);
		$run_id = $run->get_run_id();

		$audit = new \Agentic\Audit_Log();
		$audit->log( self::AGENT, 'tool_executed', 'list_posts', array( 'id' => 1 ) );

		Agent_Run::reset_current_for_tests();

		$resp = $this->request( 'GET', '/runs/' . $run_id );
		$this->assertSame( 200, $resp->get_status() );

		$data = $resp->get_data();
		$this->assertSame( $run_id, $data['run']['run_id'] );

		$steps = $data['steps'];
		$this->assertNotEmpty( $steps, 'the run detail must expose real per-run steps' );

		$tool_step = null;
		foreach ( $steps as $step ) {
			if ( 'tool_executed' === ( $step['action'] ?? '' ) ) {
				$tool_step = $step;
				break;
			}
		}

		$this->assertNotNull( $tool_step, 'the tool_executed step must be present' );
		$this->assertSame( $run_id, $tool_step['run_id'] ?? '' );
		$this->assertSame( 'list_posts', $tool_step['target_type'] ?? '' );
	}

	/**
	 * agent_builder_before_task_dispatch returning a WP_Error short-circuits the
	 * request: the error is surfaced and no run row is created.
	 */
	public function test_before_task_dispatch_wp_error_short_circuits(): void {
		$this->grant_plugin_privilege( 'run_tasks_manually', 'editor' );

		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );

		$error  = new \WP_Error( 'team_budget', 'No budget left for this team run.', array( 'status' => 402 ) );
		$filter = static function ( $pre, $agent_id, $task, $request ) use ( $error ) {
			return $error;
		};
		add_filter( 'agent_builder_before_task_dispatch', $filter, 10, 4 );

		$create = $this->request( 'POST', '/runs', array( 'agent_id' => self::AGENT, 'task' => 'My task' ) );

		remove_filter( 'agent_builder_before_task_dispatch', $filter );

		$this->assertSame( 402, $create->get_status() );
		$this->assertSame( 'team_budget', $create->as_error()->get_error_code() );
		$this->assertSame( 0, $this->count_runs(), 'short-circuited dispatch must not create a run' );
	}

	/**
	 * agent_builder_before_task_dispatch returning a WP_REST_Response is returned
	 * as-is (untouched) and no run row is created.
	 */
	public function test_before_task_dispatch_response_short_circuits(): void {
		$this->grant_plugin_privilege( 'run_tasks_manually', 'editor' );

		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );

		$response = new \WP_REST_Response( array( 'run' => 'team-run-id', 'queued' => false ), 202 );
		$filter   = static function ( $pre, $agent_id, $task, $request ) use ( $response ) {
			return $response;
		};
		add_filter( 'agent_builder_before_task_dispatch', $filter, 10, 4 );

		$create = $this->request( 'POST', '/runs', array( 'agent_id' => self::AGENT, 'task' => 'My task' ) );

		remove_filter( 'agent_builder_before_task_dispatch', $filter );

		$this->assertSame( 202, $create->get_status() );
		$this->assertSame( 'team-run-id', $create->get_data()['run'] );
		$this->assertSame( 0, $this->count_runs(), 'short-circuited dispatch must not create a run' );
	}

	/**
	 * agent_builder_before_task_dispatch returning null (the default) continues
	 * normally: a queued run is created and the raw request (with `team`/`members`
	 * params) is passed through to the callback.
	 */
	public function test_before_task_dispatch_null_continues_and_creates_run(): void {
		$this->grant_plugin_privilege( 'run_tasks_manually', 'editor' );

		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );

		$captured = null;
		$filter   = static function ( $pre, $agent_id, $task, $request ) use ( &$captured ) {
			$captured = $request;
			return null;
		};
		add_filter( 'agent_builder_before_task_dispatch', $filter, 10, 4 );

		$create = $this->request(
			'POST',
			'/runs',
			array(
				'agent_id' => self::AGENT,
				'task'     => 'My task',
				'team'     => 'alpha',
				'members'  => array( 'agent-a', 'agent-b' ),
			)
		);

		remove_filter( 'agent_builder_before_task_dispatch', $filter );

		$this->assertSame( 201, $create->get_status() );
		$this->assertSame( 'queued', $create->get_data()['run']['status'] );
		$this->assertSame( 1, $this->count_runs(), 'a null filter result must let the run proceed' );

		// The raw request is passed through so Pro can read the team params.
		$this->assertInstanceOf( \WP_REST_Request::class, $captured );
		$this->assertSame( 'alpha', $captured->get_param( 'team' ) );
		$this->assertSame( array( 'agent-a', 'agent-b' ), $captured->get_param( 'members' ) );
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
	 * Count the run rows currently in the runs table.
	 *
	 * @return int
	 */
	private function count_runs(): int {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}agent_builder_runs" );
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

	/**
	 * A Manifest_Agent test double requiring manage_options, so an editor (even
	 * one granted run_tasks_manually) is excluded from its accessible list.
	 *
	 * @param string $slug Agent slug.
	 * @return Manifest_Agent
	 */
	private function make_restricted_agent( string $slug ): Manifest_Agent {
		return new Manifest_Agent(
			array(
				'slug'         => $slug,
				'name'         => 'Admin Only Agent',
				'capabilities' => array( 'manage_options' ),
			),
			''
		);
	}
}
