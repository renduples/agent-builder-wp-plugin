<?php
/**
 * Unit tests for Routines_REST (M14-d).
 *
 * Exercises the REST surface over the already-complete Routines data-access
 * layer through a real WP_REST_Server::dispatch(), so route matching, the
 * manage capability gate, and request/response translation are all covered —
 * not just the underlying Routines methods.
 *
 * @package Agentic\Tests
 */

namespace Agentic\Tests;

use Agentic\Agent_Lifecycle;
use Agentic\Agent_Permissions;
use Agentic\Deployments;
use Agentic\Manifest_Agent;
use Agentic\Provider_Registry;
use Agentic\Routines;

/**
 * Covers the /agentic/v1/routines routes.
 */
class Test_Routines_REST extends TestCase {

	/**
	 * Agent slug the routines in this file run.
	 */
	private const AGENT = 'routine-rest-agent';

	/**
	 * Register the agent the dual-write save path resolves by slug, and clear any
	 * leaked permission-mode override.
	 */
	public function setUp(): void {
		parent::setUp();
		\Agentic_Agent_Registry::get_instance()->register( $this->make_agent( self::AGENT ) );
		Agent_Permissions::set_mode_override( null );
	}

	/**
	 * Drop the registered agent, provider/LLM config, cron, options and
	 * Deployments rows this file wrote.
	 */
	public function tearDown(): void {
		global $wpdb;

		\Agentic_Agent_Registry::get_instance()->unregister( self::AGENT );
		Agent_Permissions::set_mode_override( null );

		MockWPFunctions::reset();
		delete_option( 'agent_builder_llm_provider' );
		delete_option( 'agent_builder_model' );
		Provider_Registry::save_api_key( 'openai', '' );

		$wpdb->query( "DELETE FROM {$wpdb->prefix}agent_builder_deployments" );

		delete_option( Agent_Lifecycle::USER_SCHEDULED_TASKS_OPTION );
		delete_option( Agent_Lifecycle::USER_EVENT_TRIGGERS_OPTION );

		$cron = _get_cron_array();
		if ( is_array( $cron ) ) {
			foreach ( $cron as $events ) {
				foreach ( $events as $hook => $args ) {
					if ( 0 === strpos( (string) $hook, 'agentic_task_' . self::AGENT . '_' ) ) {
						wp_clear_scheduled_hook( $hook );
					}
				}
			}
		}

		wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * A user without agent_builder_manage_agents is rejected with 403 on every
	 * route — reads and writes alike.
	 */
	public function test_subscriber_gets_403_on_every_route(): void {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$routes = array(
			array( 'GET', '/routines' ),
			array( 'POST', '/routines', array( 'kind' => 'scheduled_task', 'agent_slug' => self::AGENT, 'prompt' => 'x', 'schedule' => 'daily' ) ),
			array( 'PUT', '/routines/1', array( 'kind' => 'scheduled_task', 'agent_slug' => self::AGENT, 'prompt' => 'x', 'schedule' => 'daily' ) ),
			array( 'DELETE', '/routines/1' ),
			array( 'POST', '/routines/1/pause' ),
			array( 'POST', '/routines/1/resume' ),
			array( 'POST', '/routines/1/test-run' ),
			array( 'GET', '/routines/1/history' ),
		);

		foreach ( $routes as $route ) {
			$resp = $this->request( $route[0], $route[1], $route[2] ?? array() );
			$this->assertSame( 403, $resp->get_status(), "expected 403 for {$route[0]} {$route[1]}" );
		}
	}

	/**
	 * GET /routines returns the routines created via POST, decorated with next_run,
	 * and scoped by the optional agent_slug query param.
	 */
	public function test_list_returns_created_routines(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$create = $this->request(
			'POST',
			'/routines',
			array(
				'kind'       => 'scheduled_task',
				'agent_slug' => self::AGENT,
				'prompt'     => 'Do the thing',
				'schedule'   => 'daily',
			)
		);
		$this->assertSame( 201, $create->get_status() );

		$resp = $this->request( 'GET', '/routines' );
		$this->assertSame( 200, $resp->get_status() );

		$routines = $resp->get_data()['routines'];
		$this->assertCount( 1, $routines );
		$this->assertSame( $create->get_data()['routine']['id'], $routines[0]['id'] );
		$this->assertArrayHasKey( 'next_run', $routines[0], 'list decorates each routine with next_run' );

		$scoped = $this->request( 'GET', '/routines', array(), array( 'agent_slug' => 'other-agent' ) );
		$this->assertSame( 200, $scoped->get_status() );
		$this->assertCount( 0, $scoped->get_data()['routines'], 'agent_slug filter narrows the list' );
	}

	/**
	 * POST /routines → PUT /routines/{id} → DELETE /routines/{id} round-trips a
	 * routine: create 201, edit 200 with the new prompt, delete 200, list empty.
	 */
	public function test_create_edit_delete_round_trip(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$create = $this->request(
			'POST',
			'/routines',
			array(
				'kind'       => 'scheduled_task',
				'agent_slug' => self::AGENT,
				'prompt'     => 'Original prompt',
				'schedule'   => 'daily',
			)
		);
		$this->assertSame( 201, $create->get_status() );
		$id = $create->get_data()['routine']['id'];
		$this->assertIsInt( $id, 'create returns the Deployments row id' );

		$edit = $this->request(
			'PUT',
			"/routines/{$id}",
			array(
				'kind'       => 'scheduled_task',
				'agent_slug' => self::AGENT,
				'prompt'     => 'Updated prompt',
				'schedule'   => 'daily',
			)
		);
		$this->assertSame( 200, $edit->get_status() );
		$this->assertSame( 'Updated prompt', $edit->get_data()['routine']['config']['prompt'] );
		$this->assertSame( $id, $edit->get_data()['routine']['id'], 'edit keeps the same row id' );

		$delete = $this->request( 'DELETE', "/routines/{$id}" );
		$this->assertSame( 200, $delete->get_status() );

		$list = $this->request( 'GET', '/routines' );
		$this->assertCount( 0, $list->get_data()['routines'], 'routine gone after delete' );
	}

	/**
	 * POST /routines with a bad body returns a 400 rest_invalid error, not a 201.
	 */
	public function test_create_invalid_body_returns_400(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$resp = $this->request(
			'POST',
			'/routines',
			array(
				'kind'       => 'scheduled_task',
				'agent_slug' => self::AGENT,
				'prompt'     => '',
				'schedule'   => 'daily',
			)
		);

		$this->assertSame( 400, $resp->get_status() );
		$this->assertSame( 'rest_invalid', $resp->get_data()['code'] );
	}

	/**
	 * POST /routines/{id}/pause and /resume flip the WP-Cron state of the
	 * underlying scheduled task.
	 */
	public function test_pause_resume_change_cron_state(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$create = $this->request(
			'POST',
			'/routines',
			array(
				'kind'       => 'scheduled_task',
				'agent_slug' => self::AGENT,
				'prompt'     => 'Do the thing',
				'schedule'   => 'daily',
			)
		);
		$this->assertSame( 201, $create->get_status() );
		$id   = $create->get_data()['routine']['id'];
		$hook = Agent_Lifecycle::user_task_cron_hook( self::AGENT, $create->get_data()['routine']['config']['task_id'] );

		$this->assertNotFalse( wp_next_scheduled( $hook ), 'cron scheduled after create' );

		$pause = $this->request( 'POST', "/routines/{$id}/pause" );
		$this->assertSame( 200, $pause->get_status() );
		$this->assertFalse( wp_next_scheduled( $hook ), 'pause clears the cron event' );

		$resume = $this->request( 'POST', "/routines/{$id}/resume" );
		$this->assertSame( 200, $resume->get_status() );
		$this->assertNotFalse( wp_next_scheduled( $hook ), 'resume re-registers the cron event' );
	}

	/**
	 * POST /routines/{id}/test-run executes once and returns a run_id, and
	 * GET /routines/{id}/history then returns that run.
	 *
	 * The REST handler constructs its own Agent_Controller (no injection seam), so
	 * the LLM is configured to a real provider and the outbound HTTP call is
	 * short-circuited with a scripted text completion, letting run_autonomous_task()
	 * finish a run offline.
	 */
	public function test_run_returns_run_id_and_history(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$this->configure_llm();

		$create = $this->request(
			'POST',
			'/routines',
			array(
				'kind'       => 'scheduled_task',
				'agent_slug' => self::AGENT,
				'prompt'     => 'Do the thing',
				'schedule'   => 'daily',
			)
		);
		$this->assertSame( 201, $create->get_status() );
		$id = $create->get_data()['routine']['id'];

		$run = $this->request( 'POST', "/routines/{$id}/test-run" );
		$this->assertSame( 200, $run->get_status() );
		$run_id = $run->get_data()['run_id'];
		$this->assertNotEmpty( $run_id, 'test-run returns a run id' );

		$history = $this->request( 'GET', "/routines/{$id}/history" );
		$this->assertSame( 200, $history->get_status() );
		$this->assertCount( 1, $history->get_data()['history'], 'history returns the run just created' );
		$this->assertSame( $run_id, $history->get_data()['history'][0]['run_id'] );
	}

	/**
	 * A bad or missing id returns 404 (not a 200 with ok:false) on every
	 * id-addressed route.
	 */
	public function test_missing_id_returns_404(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$routes = array(
			array( 'PUT', '/routines/999999', array( 'kind' => 'scheduled_task', 'agent_slug' => self::AGENT, 'prompt' => 'x', 'schedule' => 'daily' ) ),
			array( 'DELETE', '/routines/999999' ),
			array( 'POST', '/routines/999999/pause' ),
			array( 'POST', '/routines/999999/resume' ),
			array( 'POST', '/routines/999999/test-run' ),
			array( 'GET', '/routines/999999/history' ),
		);

		foreach ( $routes as $route ) {
			$resp = $this->request( $route[0], $route[1], $route[2] ?? array() );
			$this->assertSame( 404, $resp->get_status(), "expected 404 for {$route[0]} {$route[1]}" );
			$this->assertSame( 'rest_routine_not_found', $resp->get_data()['code'] );
		}
	}

	/**
	 * A Deployments row that is not a routine (code-sourced) is treated as a bad
	 * id: every id-addressed route returns 404 rather than acting on it.
	 */
	public function test_non_routine_id_returns_404(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$id = Deployments::save(
			array(
				'type'       => Deployments::TYPE_SCHEDULED_TASK,
				'agent_slug' => self::AGENT,
				'label'      => 'Built-in task',
				'enabled'    => 1,
				'source'     => Deployments::SOURCE_CODE,
				'config'     => array(
					'task_id'  => 'builtin_task',
					'schedule' => 'daily',
					'source'   => 'code',
				),
			)
		);

		$resp = $this->request( 'POST', "/routines/{$id}/pause" );
		$this->assertSame( 404, $resp->get_status(), 'a non-routine row is not pausable' );
		$this->assertSame( 'rest_routine_not_found', $resp->get_data()['code'] );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Dispatch a REST request against the routines routes.
	 *
	 * @param string               $method HTTP method.
	 * @param string               $route  Route path (relative to /agentic/v1).
	 * @param array<string, mixed> $json   JSON body to send (POST/PUT).
	 * @param array<string, mixed> $query  Query params (GET).
	 * @return \WP_REST_Response
	 */
	private function request( string $method, string $route, array $json = array(), array $query = array() ): \WP_REST_Response {
		$req = new \WP_REST_Request( $method, '/agentic/v1' . $route );
		foreach ( $query as $key => $value ) {
			$req->set_param( $key, $value );
		}
		if ( ! empty( $json ) ) {
			$req->set_header( 'Content-Type', 'application/json' );
			$req->set_body( wp_json_encode( $json ) );
		}

		return rest_get_server()->dispatch( $req );
	}

	/**
	 * Configure a real provider + scripted offline completion so a REST-driven
	 * test-run can finish a run without leaving the process.
	 *
	 * @return void
	 */
	private function configure_llm(): void {
		update_option( 'agent_builder_llm_provider', 'openai' );
		update_option( 'agent_builder_model', 'gpt-4.1-mini' );
		Provider_Registry::upsert( array( 'slug' => 'openai', 'api_key' => 'test-key' ) );

		MockWPFunctions::mock_remote_response(
			array(
				'body'     => wp_json_encode( Fake_LLM_Client::text_response( 'Done.' ) ),
				'response' => array( 'code' => 200 ),
			)
		);
	}

	/**
	 * Minimal Manifest_Agent test double, registered with the shared registry.
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
