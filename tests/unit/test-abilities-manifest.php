<?php
/**
 * Unit Tests for Abilities_Manifest::get_effective_risk().
 *
 * effective_risk = max( tool_default_risk, manifest_risk, admin_override ) —
 * an agent's abilities.json or an admin override can only ever escalate a
 * tool's risk, never lower it below the tool's own intrinsic default
 * (Risk_Level::get_tool_default(), itself floored by BASELINE_RISKS).
 *
 * @package Agentic\Tests
 */

namespace Agentic\Tests;

use Agentic\Abilities_Manifest;
use Agentic\Risk_Level;

/**
 * Test case for Abilities_Manifest::get_effective_risk() and friends.
 */
class Test_Abilities_Manifest extends TestCase {

	/**
	 * Agent slug created per test, cleaned up in tearDown().
	 *
	 * @var string
	 */
	private string $agent_id = 'test-abilities-agent';

	/**
	 * Reset caches and remove any leftover manifest before each test.
	 */
	public function setUp(): void {
		parent::setUp();
		Risk_Level::bust_cache();
		delete_option( 'agentic_risk_overrides' );
		$this->delete_test_agent( $this->agent_id );
	}

	/**
	 * Clean up the manifest directory after each test.
	 */
	public function tearDown(): void {
		$this->delete_test_agent( $this->agent_id );
		delete_option( 'agentic_risk_overrides' );
		parent::tearDown();
	}

	/**
	 * With no manifest at all, effective risk falls back to the tool's own
	 * intrinsic default (registry value floored by BASELINE_RISKS).
	 */
	public function test_effective_risk_falls_back_to_tool_default_with_no_manifest(): void {
		$this->assertSame(
			Risk_Level::get_tool_default( 'force_password_reset' ),
			Abilities_Manifest::get_effective_risk( 'no-such-agent', 'force_password_reset' )
		);
	}

	/**
	 * A manifest can escalate a tool above its intrinsic default.
	 *
	 * add_custom_css's intrinsic default is MEDIUM; an agent declaring it as
	 * HIGH must resolve to HIGH.
	 */
	public function test_manifest_can_escalate_above_tool_default(): void {
		$this->create_test_agent_manifest(
			$this->agent_id,
			array( 'add_custom_css' => array( 'risk' => Risk_Level::HIGH, 'reason' => 'test' ) )
		);

		$this->assertSame( Risk_Level::MEDIUM, Risk_Level::get_tool_default( 'add_custom_css' ) );
		$this->assertSame(
			Risk_Level::HIGH,
			Abilities_Manifest::get_effective_risk( $this->agent_id, 'add_custom_css' )
		);
	}

	/**
	 * A manifest cannot lower a tool's risk below its intrinsic default —
	 * this is the "escalate only, never downgrade" guarantee the whole
	 * abilities.json mechanism depends on.
	 *
	 * add_custom_js has a HIGH baseline; an agent naively declaring it as
	 * 'none' must not be believed.
	 */
	public function test_manifest_cannot_downgrade_below_tool_default(): void {
		$this->create_test_agent_manifest(
			$this->agent_id,
			array( 'add_custom_js' => array( 'risk' => Risk_Level::NONE, 'reason' => 'test' ) )
		);

		$this->assertSame( Risk_Level::HIGH, Risk_Level::get_tool_default( 'add_custom_js' ) );
		$this->assertSame(
			Risk_Level::HIGH,
			Abilities_Manifest::get_effective_risk( $this->agent_id, 'add_custom_js' ),
			'a manifest declaring a lower risk must not pull the tool below its floor'
		);
	}

	/**
	 * An admin override (agentic_risk_overrides option) can also only
	 * escalate — same guarantee, different source.
	 */
	public function test_admin_override_cannot_downgrade_below_tool_default(): void {
		update_option(
			'agentic_risk_overrides',
			array( 'no-such-agent:add_custom_js' => Risk_Level::NONE )
		);

		$this->assertSame(
			Risk_Level::HIGH,
			Abilities_Manifest::get_effective_risk( 'no-such-agent', 'add_custom_js' )
		);
	}

	/**
	 * An admin override can escalate a tool above both its intrinsic default
	 * and its manifest declaration.
	 */
	public function test_admin_override_escalates_above_manifest_and_default(): void {
		$this->create_test_agent_manifest(
			$this->agent_id,
			array( 'add_custom_css' => array( 'risk' => Risk_Level::MEDIUM, 'reason' => 'test' ) )
		);
		update_option(
			'agentic_risk_overrides',
			array( "{$this->agent_id}:add_custom_css" => Risk_Level::EXTREME )
		);

		$this->assertSame(
			Risk_Level::EXTREME,
			Abilities_Manifest::get_effective_risk( $this->agent_id, 'add_custom_css' )
		);
	}

	/**
	 * risk_by_action lets a multi-action tool declare a narrower risk for one
	 * specific action, but that narrower value still cannot escape the
	 * tool's own intrinsic floor — only the flat `risk` key is bypassed, not
	 * get_effective_risk()'s max() against the tool default.
	 */
	public function test_risk_by_action_still_respects_tool_default_floor(): void {
		$this->create_test_agent_manifest(
			$this->agent_id,
			array(
				'add_custom_js' => array(
					'risk'           => Risk_Level::HIGH,
					'risk_by_action' => array( 'list' => Risk_Level::NONE ),
					'reason'         => 'test',
				),
			)
		);

		// The declared per-action risk is NONE, but add_custom_js's own
		// baseline is HIGH — max() must still win.
		$this->assertSame(
			Risk_Level::HIGH,
			Abilities_Manifest::get_effective_risk( $this->agent_id, 'add_custom_js', null, 'list' )
		);
	}

	/**
	 * A tool instance's own get_risk_level() override participates in the
	 * tool_default calculation alongside the registry/baseline value.
	 */
	public function test_tool_instance_risk_level_is_maxed_with_registry_default(): void {
		$tool = \Agentic\Tool_Loader::get_instance()->get( 'create_agent_files' );
		$this->assertNotNull( $tool, 'create_agent_files tool should load' );

		$this->assertSame(
			Risk_Level::HIGH,
			Abilities_Manifest::get_effective_risk( 'no-such-agent', 'create_agent_files', $tool )
		);
	}

	/**
	 * is_declared() reports true for a tool explicitly listed in abilities,
	 * and false for one that is not — with the framework-global exceptions
	 * (report_issue, request_human_help) always allowed.
	 */
	public function test_is_declared_reflects_manifest_contents(): void {
		$this->create_test_agent_manifest(
			$this->agent_id,
			array( 'add_custom_css' => array( 'risk' => Risk_Level::MEDIUM, 'reason' => 'test' ) )
		);

		$this->assertTrue( Abilities_Manifest::is_declared( $this->agent_id, 'add_custom_css' ) );
		$this->assertFalse( Abilities_Manifest::is_declared( $this->agent_id, 'wc_create_refund' ) );
		$this->assertTrue( Abilities_Manifest::is_declared( $this->agent_id, 'report_issue' ) );
		$this->assertTrue( Abilities_Manifest::is_declared( $this->agent_id, 'request_human_help' ) );
	}
}
