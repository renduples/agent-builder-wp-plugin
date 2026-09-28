<?php
/**
 * Unit Tests for Approval_Rules (M12-2).
 *
 * Covers the CRUD round-trips against agent_builder_approval_rules, the
 * effect/agent_slug validation, and the capability gates on every
 * /agentic/v1/approval-rules route (subscriber 403 everywhere; a
 * manage_agents user may list but not write; an administrator may do both).
 *
 * @package Agentic\Tests
 */

namespace Agentic\Tests;

use Agentic\Approval_Rules;
use Agentic\Manifest_Agent;
use Agentic\User_Roles;

/**
 * Test case for Approval_Rules.
 */
class Test_Approval_Rules extends TestCase {

	/**
	 * Agent slug registered into the shared registry for agent_slug validation.
	 */
	const AGENT = 'wordpress-assistant';

	/**
	 * Register a test agent and reset role settings before each test.
	 */
	public function setUp(): void {
		parent::setUp();
		\Agentic_Agent_Registry::get_instance()->register(
			new Manifest_Agent(
				array(
					'slug' => self::AGENT,
					'name' => 'Test Agent',
				),
				''
			)
		);
		delete_option( User_Roles::OPTION_KEY );
	}

	/**
	 * Drop any role grants and the registered test agent so they never leak.
	 */
	public function tearDown(): void {
		\Agentic_Agent_Registry::get_instance()->unregister( self::AGENT );
		delete_option( User_Roles::OPTION_KEY );
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// CRUD round-trips
	// -------------------------------------------------------------------------

	/**
	 * create() persists a row and returns its id; get() reads it back with the
	 * expected fields, and `compiled` stays NULL.
	 */
	public function test_create_and_get_round_trip(): void {
		$id = Approval_Rules::create(
			array(
				'agent_slug' => self::AGENT,
				'rule_text'  => 'Deny writes to wp_options.',
				'effect'     => 'deny',
				'priority'   => 5,
				'enabled'    => true,
			)
		);

		$this->assertGreaterThan( 0, $id );

		$row = Approval_Rules::get( $id );
		$this->assertNotNull( $row );
		$this->assertSame( self::AGENT, $row['agent_slug'] );
		$this->assertSame( 'Deny writes to wp_options.', $row['rule_text'] );
		$this->assertSame( 'deny', $row['effect'] );
		$this->assertSame( 5, (int) $row['priority'] );
		$this->assertSame( 1, (int) $row['enabled'] );
		$this->assertNull( $row['compiled'] );
	}

	/**
	 * create() applies its defaults: empty agent_slug (all agents), priority 10,
	 * enabled true.
	 */
	public function test_create_applies_defaults(): void {
		$id = Approval_Rules::create(
			array(
				'rule_text' => 'Ask before deleting posts.',
				'effect'    => 'ask',
			)
		);

		$row = Approval_Rules::get( $id );
		$this->assertSame( '', $row['agent_slug'] );
		$this->assertSame( 10, (int) $row['priority'] );
		$this->assertSame( 1, (int) $row['enabled'] );
	}

	/**
	 * list() returns created rules ordered by priority, then id.
	 */
	public function test_list_orders_by_priority(): void {
		$later = Approval_Rules::create( array( 'rule_text' => 'Later.', 'effect' => 'allow', 'priority' => 20 ) );
		$first = Approval_Rules::create( array( 'rule_text' => 'First.', 'effect' => 'deny', 'priority' => 5 ) );

		$rules = Approval_Rules::list();

		$ids = array_map( 'intval', array_column( $rules, 'id' ) );
		$this->assertSame( array( $first, $later ), $ids );
	}

	/**
	 * update() changes only the fields provided and leaves the rest intact.
	 */
	public function test_update_changes_fields(): void {
		$id = Approval_Rules::create(
			array(
				'rule_text' => 'Original.',
				'effect'    => 'ask',
				'priority'  => 7,
			)
		);

		$updated = Approval_Rules::update( $id, array( 'effect' => 'deny', 'enabled' => false ) );
		$this->assertTrue( $updated );

		$row = Approval_Rules::get( $id );
		$this->assertSame( 'deny', $row['effect'] );
		$this->assertSame( 0, (int) $row['enabled'] );
		// Untouched fields are unchanged.
		$this->assertSame( 'Original.', $row['rule_text'] );
		$this->assertSame( 7, (int) $row['priority'] );
	}

	/**
	 * update() and delete() return false when the row does not exist.
	 */
	public function test_update_and_delete_missing_return_false(): void {
		$this->assertFalse( Approval_Rules::update( 999999, array( 'effect' => 'deny' ) ) );
		$this->assertFalse( Approval_Rules::delete( 999999 ) );
	}

	/**
	 * delete() removes the row; a second delete returns false.
	 */
	public function test_delete_removes_row(): void {
		$id = Approval_Rules::create( array( 'rule_text' => 'To be removed.', 'effect' => 'allow' ) );

		$this->assertTrue( Approval_Rules::delete( $id ) );
		$this->assertNull( Approval_Rules::get( $id ) );
		$this->assertFalse( Approval_Rules::delete( $id ) );
	}

	// -------------------------------------------------------------------------
	// Validation
	// -------------------------------------------------------------------------

	/**
	 * validate() accepts only ask/allow/deny effects.
	 */
	public function test_validate_effect(): void {
		$this->assertNull( Approval_Rules::validate( array( 'effect' => 'ask' ) ) );
		$this->assertNull( Approval_Rules::validate( array( 'effect' => 'allow' ) ) );
		$this->assertNull( Approval_Rules::validate( array( 'effect' => 'deny' ) ) );

		$error = Approval_Rules::validate( array( 'effect' => 'destroy' ) );
		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertSame( 'invalid_effect', $error->get_error_code() );
	}

	/**
	 * validate() accepts an empty agent_slug (all agents) or a registered slug,
	 * and rejects an unknown slug.
	 */
	public function test_validate_agent_slug(): void {
		$this->assertNull( Approval_Rules::validate( array( 'agent_slug' => '' ) ) );
		$this->assertNull( Approval_Rules::validate( array( 'agent_slug' => self::AGENT ) ) );

		$error = Approval_Rules::validate( array( 'agent_slug' => 'no-such-agent' ) );
		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertSame( 'invalid_agent', $error->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// REST capability gates
	// -------------------------------------------------------------------------

	/**
	 * A subscriber is rejected with 403 on every approval-rules route.
	 */
	public function test_subscriber_gets_403_on_every_route(): void {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$responses = array(
			$this->request( 'GET', '/approval-rules' ),
			$this->request( 'POST', '/approval-rules', array( 'rule_text' => 'X', 'effect' => 'deny' ) ),
			$this->request( 'PUT', '/approval-rules/1', array( 'effect' => 'deny' ) ),
			$this->request( 'DELETE', '/approval-rules/1' ),
		);

		foreach ( $responses as $response ) {
			$this->assertSame( 403, $response->get_status() );
		}
	}

	/**
	 * A manage_agents-only user may list rules but not write them.
	 */
	public function test_manage_agents_user_can_list_but_not_write(): void {
		$this->grant_plugin_privilege( 'manage_agents', 'editor' );

		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );

		$this->assertSame( 200, $this->request( 'GET', '/approval-rules' )->get_status() );
		$this->assertSame( 403, $this->request( 'POST', '/approval-rules', array( 'rule_text' => 'X', 'effect' => 'deny' ) )->get_status() );
		$this->assertSame( 403, $this->request( 'PUT', '/approval-rules/1', array( 'effect' => 'deny' ) )->get_status() );
		$this->assertSame( 403, $this->request( 'DELETE', '/approval-rules/1' )->get_status() );
	}

	/**
	 * An administrator can run the full create/list/update/delete cycle.
	 */
	public function test_admin_can_crud(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$create = $this->request(
			'POST',
			'/approval-rules',
			array(
				'agent_slug' => self::AGENT,
				'rule_text'  => 'Ask before touching anything.',
				'effect'     => 'ask',
			)
		);
		$this->assertSame( 201, $create->get_status() );
		$rule = $create->get_data()['rule'];
		$this->assertSame( 'ask', $rule['effect'] );
		$this->assertNull( $rule['compiled'] );
		$id = (int) $rule['id'];

		$list = $this->request( 'GET', '/approval-rules' );
		$this->assertSame( 200, $list->get_status() );
		$rules = $list->get_data()['rules'];
		$this->assertCount( 1, $rules );
		$this->assertSame( $id, (int) $rules[0]['id'] );

		$update = $this->request( 'PUT', '/approval-rules/' . $id, array( 'effect' => 'deny', 'priority' => 3 ) );
		$this->assertSame( 200, $update->get_status() );
		$this->assertSame( 'deny', $update->get_data()['rule']['effect'] );
		$this->assertSame( 3, (int) $update->get_data()['rule']['priority'] );

		$delete = $this->request( 'DELETE', '/approval-rules/' . $id );
		$this->assertSame( 200, $delete->get_status() );

		$this->assertCount( 0, $this->request( 'GET', '/approval-rules' )->get_data()['rules'] );
	}

	/**
	 * POST /approval-rules rejects an invalid effect with 400.
	 */
	public function test_create_rejects_invalid_effect(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$resp = $this->request( 'POST', '/approval-rules', array( 'rule_text' => 'X', 'effect' => 'destroy' ) );
		$this->assertSame( 400, $resp->get_status() );
		$this->assertSame( 'invalid_effect', $resp->as_error()->get_error_code() );
	}

	/**
	 * POST /approval-rules rejects an unknown agent slug with 400.
	 */
	public function test_create_rejects_unknown_agent(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$resp = $this->request(
			'POST',
			'/approval-rules',
			array( 'agent_slug' => 'no-such-agent', 'rule_text' => 'X', 'effect' => 'deny' )
		);
		$this->assertSame( 400, $resp->get_status() );
		$this->assertSame( 'invalid_agent', $resp->as_error()->get_error_code() );
	}

	/**
	 * POST /approval-rules rejects an empty rule_text with 400.
	 */
	public function test_create_rejects_missing_rule_text(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$resp = $this->request( 'POST', '/approval-rules', array( 'effect' => 'deny' ) );
		$this->assertSame( 400, $resp->get_status() );
		$this->assertSame( 'missing_rule_text', $resp->as_error()->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Dispatch a REST request against the approval-rules routes.
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
	 * @param string $privilege Unprefixed privilege (e.g. `manage_agents`).
	 * @param string $role      WordPress role slug.
	 */
	private function grant_plugin_privilege( string $privilege, string $role ): void {
		$settings                         = User_Roles::get_settings();
		$settings['plugin'][ $privilege ] = array( $role );
		update_option( User_Roles::OPTION_KEY, $settings );
	}
}
