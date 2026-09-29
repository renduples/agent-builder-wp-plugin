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
use Agentic\Risk_Level;
use Agentic\User_Roles;

/**
 * Test case for Approval_Rules.
 */
class Test_Approval_Rules extends TestCase {

	/**
	 * Test-only agent slug registered into the shared registry for agent_slug
	 * validation. Distinct from every bundled agent slug so that registering and
	 * unregistering it here can never clobber a real instance other tests share.
	 */
	const AGENT = 'approval-rules-test-agent';

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

	/**
	 * PUT /approval-rules/{id} rejects an empty rule_text with 400, matching
	 * create, and leaves the stored rule_text unchanged.
	 */
	public function test_update_rejects_missing_rule_text(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$id = Approval_Rules::create( array( 'rule_text' => 'Original.', 'effect' => 'ask' ) );

		$resp = $this->request( 'PUT', '/approval-rules/' . $id, array( 'rule_text' => '' ) );
		$this->assertSame( 400, $resp->get_status() );
		$this->assertSame( 'missing_rule_text', $resp->as_error()->get_error_code() );
		$this->assertSame( 'Original.', Approval_Rules::get( $id )['rule_text'] );
	}

	/**
	 * PUT and DELETE to a non-existent rule id return 404, driven by the model's
	 * own "no row affected" return rather than a separate pre-check.
	 */
	public function test_update_and_delete_missing_return_404(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$update = $this->request( 'PUT', '/approval-rules/999999', array( 'effect' => 'deny' ) );
		$this->assertSame( 404, $update->get_status() );
		$this->assertSame( 'not_found', $update->as_error()->get_error_code() );

		$delete = $this->request( 'DELETE', '/approval-rules/999999' );
		$this->assertSame( 404, $delete->get_status() );
		$this->assertSame( 'not_found', $delete->as_error()->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// Evaluation engine (Phase A)
	// -------------------------------------------------------------------------

	/**
	 * classify() is a stub that always returns 'unsure'.
	 */
	public function test_classify_stub_returns_unsure(): void {
		$this->assertSame( 'unsure', Approval_Rules::classify( 'Any rule text.', $this->evaluation_ctx() ) );
	}

	/**
	 * An enabled deny rule for the agent tightens 'allow' to 'queue'.
	 */
	public function test_deny_rule_tightens_allow_to_queue(): void {
		Approval_Rules::create(
			array(
				'agent_slug' => self::AGENT,
				'rule_text'  => 'Deny writes to wp_options.',
				'effect'     => 'deny',
			)
		);

		$this->assertSame( 'queue', Approval_Rules::evaluate( 'allow', $this->evaluation_ctx() ) );
	}

	/**
	 * An enabled ask rule for the agent tightens 'allow' to 'confirm'.
	 */
	public function test_ask_rule_tightens_allow_to_confirm(): void {
		Approval_Rules::create(
			array(
				'agent_slug' => self::AGENT,
				'rule_text'  => 'Ask before deleting posts.',
				'effect'     => 'ask',
			)
		);

		$this->assertSame( 'confirm', Approval_Rules::evaluate( 'allow', $this->evaluation_ctx() ) );
	}

	/**
	 * An allow-effect rule never loosens anything: classify() is always
	 * 'unsure', and an 'unsure' allow rule is suppressed.
	 */
	public function test_allow_rule_never_fires(): void {
		Approval_Rules::create(
			array(
				'agent_slug' => self::AGENT,
				'rule_text'  => 'Allow read-only tools.',
				'effect'     => 'allow',
			)
		);

		$this->assertSame( 'allow', Approval_Rules::evaluate( 'allow', $this->evaluation_ctx() ) );
	}

	/**
	 * A rule for a different agent_slug never matches the calling agent.
	 */
	public function test_other_agent_rule_never_matches(): void {
		Approval_Rules::create(
			array(
				'agent_slug' => 'other-agent',
				'rule_text'  => 'Deny everything.',
				'effect'     => 'deny',
			)
		);

		$this->assertSame( 'allow', Approval_Rules::evaluate( 'allow', $this->evaluation_ctx() ) );
	}

	/**
	 * A disabled rule never matches.
	 */
	public function test_disabled_rule_never_matches(): void {
		Approval_Rules::create(
			array(
				'agent_slug' => self::AGENT,
				'rule_text'  => 'Deny everything.',
				'effect'     => 'deny',
				'enabled'    => false,
			)
		);

		$this->assertSame( 'allow', Approval_Rules::evaluate( 'allow', $this->evaluation_ctx() ) );
	}

	/**
	 * A rule with an empty agent_slug applies to every agent.
	 */
	public function test_empty_agent_slug_rule_matches_any_agent(): void {
		Approval_Rules::create(
			array(
				'agent_slug' => '',
				'rule_text'  => 'Deny everything.',
				'effect'     => 'deny',
			)
		);

		$this->assertSame( 'queue', Approval_Rules::evaluate( 'allow', $this->evaluation_ctx() ) );
	}

	/**
	 * Precedence: deny beats ask when both match the same call, regardless of
	 * priority order.
	 */
	public function test_deny_beats_ask_precedence(): void {
		Approval_Rules::create(
			array(
				'agent_slug' => self::AGENT,
				'rule_text'  => 'Ask about this.',
				'effect'     => 'ask',
				'priority'   => 5,
			)
		);
		Approval_Rules::create(
			array(
				'agent_slug' => self::AGENT,
				'rule_text'  => 'Deny this.',
				'effect'     => 'deny',
				'priority'   => 20,
			)
		);

		$this->assertSame( 'queue', Approval_Rules::evaluate( 'allow', $this->evaluation_ctx() ) );
	}

	/**
	 * evaluate() never loosens: an ask rule on an already-queued decision stays
	 * 'queue' rather than being talked back down to 'confirm'.
	 */
	public function test_ask_rule_does_not_loosen_existing_queue(): void {
		Approval_Rules::create(
			array(
				'agent_slug' => self::AGENT,
				'rule_text'  => 'Ask about this.',
				'effect'     => 'ask',
			)
		);

		$this->assertSame( 'queue', Approval_Rules::evaluate( 'queue', $this->evaluation_ctx() ) );
	}

	/**
	 * With no matching rule, evaluate() returns the incoming enforcement untouched.
	 */
	public function test_no_rules_returns_input_unchanged(): void {
		$this->assertSame( 'allow', Approval_Rules::evaluate( 'allow', $this->evaluation_ctx() ) );
	}

	/**
	 * A HIGH-risk call still honours Risk_Level::clamp_enforcement()'s ceiling
	 * after evaluate() runs: a deny rule keeps 'queue', and clamping the result
	 * back through the same ctx neither crashes nor changes direction.
	 */
	public function test_high_risk_clamp_ceiling_still_holds(): void {
		Approval_Rules::create(
			array(
				'agent_slug' => self::AGENT,
				'rule_text'  => 'Deny high-risk writes.',
				'effect'     => 'deny',
			)
		);

		$ctx = $this->evaluation_ctx( 'high', 'queue' );
		$out = Approval_Rules::evaluate( 'queue', $ctx );

		$this->assertSame( 'queue', $out );
		$this->assertSame( 'queue', Risk_Level::clamp_enforcement( $out, $ctx ) );
	}

	/**
	 * A matched rule is audit-logged as `rule_matched` with the rule id and
	 * resulting effect.
	 */
	public function test_rule_match_is_audit_logged(): void {
		global $wpdb;

		$id = Approval_Rules::create(
			array(
				'agent_slug' => self::AGENT,
				'rule_text'  => 'Deny writes to wp_options.',
				'effect'     => 'deny',
			)
		);

		Approval_Rules::evaluate( 'allow', $this->evaluation_ctx() );

		$rows = $wpdb->get_results(
			"SELECT * FROM {$wpdb->prefix}agent_builder_audit_log WHERE action = 'rule_matched'",
			ARRAY_A
		);
		$this->assertCount( 1, $rows );
		$this->assertSame( self::AGENT, $rows[0]['agent_id'] );
		$this->assertSame( 'db_update_option', $rows[0]['target_type'] );

		$details = json_decode( (string) $rows[0]['details'], true );
		$this->assertSame( $id, (int) $details['rule_id'] );
		$this->assertSame( 'deny', $details['effect'] );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Build a gate-context array for evaluate().
	 *
	 * @param string $risk     Risk level (default 'low').
	 * @param string $baseline Pre-filter enforcement decision (default 'allow').
	 * @return array<string, mixed>
	 */
	private function evaluation_ctx( string $risk = 'low', string $baseline = 'allow' ): array {
		return array(
			'tool'     => 'db_update_option',
			'agent_id' => self::AGENT,
			'risk'     => $risk,
			'baseline' => $baseline,
		);
	}

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
