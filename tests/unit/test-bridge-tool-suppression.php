<?php
/**
 * Unit Tests for the bridged-tool suppression map in Agent_Controller::get_tools_for_agent().
 *
 * The WP 6.9+ Abilities bridge treats the plugin's own `wp-extended/*`
 * abilities (WP_Extended_Abilities — generic core-function wrappers such as
 * "get all users" / "get all posts", registered under a non-`agent-builder/`
 * namespace precisely so every agent gets them automatically) the same as any
 * real third-party ability. Left unfiltered, the model reliably reaches for
 * these generic tools instead of an agent's own purpose-built, risk-gated
 * ones — a system-prompt "prefer your own tools" nudge alone was not enough.
 * These tests exercise the structural fix: a bridged generic tool is
 * withheld only when the agent already holds a bundled tool that supersedes
 * it, and is still offered when it doesn't.
 *
 * @package Agentic\Tests
 */

namespace Agentic\Tests;

use Agentic\Agent_Controller;
use Agentic\Manifest_Agent;
use Agentic\Risk_Level;

/**
 * Test case for the bridge-tool suppression map.
 */
class Test_Bridge_Tool_Suppression extends TestCase {

	/**
	 * Ability names this test class registered directly on the core Abilities
	 * registry (synthetic ones only — never the plugin's own real abilities),
	 * torn down afterwards.
	 *
	 * @var string[]
	 */
	private array $registered_abilities = array();

	/**
	 * Agent slugs created via create_test_agent_manifest(), torn down afterwards.
	 *
	 * @var string[]
	 */
	private array $test_agent_ids = array();

	/**
	 * Reset caches and make sure the real Abilities registry (and with it,
	 * the plugin's own wp-extended/* abilities) is initialized.
	 */
	public function setUp(): void {
		parent::setUp();
		Risk_Level::bust_cache();
		delete_option( 'agent_builder_disabled_inbound_abilities' );

		// First touch anywhere in the process initializes the registry and
		// fires wp_abilities_api_init, registering the plugin's real
		// agent-builder/* and wp-extended/* abilities exactly once.
		\WP_Abilities_Registry::get_instance();
	}

	/**
	 * Unregister only the synthetic abilities this test class added, and
	 * clean up test agents.
	 */
	public function tearDown(): void {
		$registry = \WP_Abilities_Registry::get_instance();
		foreach ( $this->registered_abilities as $ability_name ) {
			if ( $registry && $registry->is_registered( $ability_name ) ) {
				$registry->unregister( $ability_name );
			}
		}
		$this->registered_abilities = array();

		foreach ( $this->test_agent_ids as $agent_id ) {
			$this->delete_test_agent( $agent_id );
		}
		$this->test_agent_ids = array();

		delete_option( 'agent_builder_disabled_inbound_abilities' );
		Risk_Level::bust_cache();
		parent::tearDown();
	}

	/**
	 * Register a synthetic bridged ability directly on the core registry, for
	 * suppression families the plugin doesn't itself ship a real ability for
	 * (e.g. environment-info). Bypasses wp_register_ability()'s doing_action()
	 * guard — a direct registry call is exactly equivalent once the registry
	 * is initialized, which setUp() guarantees.
	 *
	 * @param string $ability_name Fully-namespaced ability name, e.g. 'core/get-environment-info'.
	 */
	private function register_synthetic_ability( string $ability_name ): void {
		$registry = \WP_Abilities_Registry::get_instance();
		$this->assertNotNull( $registry, 'Abilities registry must be available on WP 6.9+ test env.' );
		$this->assertFalse( $registry->is_registered( $ability_name ), "Test ability {$ability_name} must not collide with a real one." );

		$registry->register(
			$ability_name,
			array(
				'label'               => $ability_name,
				'description'         => 'Scratch bridged ability for suppression tests.',
				'category'            => 'wp-admin',
				'execute_callback'    => '__return_empty_array',
				'permission_callback' => '__return_true',
				'meta'                => array( 'public' => true ),
			)
		);
		$this->registered_abilities[] = $ability_name;
	}

	/**
	 * Build an Agent_Controller with its current_agent set (via reflection,
	 * bypassing set_agent()'s registry/activation lookup) to a Manifest_Agent
	 * declaring exactly the given bundled tool names, and an abilities.json
	 * on disk declaring those same tools at 'none' risk.
	 *
	 * @param string   $agent_id   Agent slug.
	 * @param string[] $tool_names Bundled tool names the agent declares.
	 * @return Agent_Controller
	 */
	private function make_controller_for_agent( string $agent_id, array $tool_names ): Agent_Controller {
		$abilities = array();
		foreach ( $tool_names as $tool_name ) {
			$abilities[ $tool_name ] = array( 'risk' => Risk_Level::NONE, 'reason' => 'test' );
		}
		$this->create_test_agent_manifest( $agent_id, $abilities );
		$this->test_agent_ids[] = $agent_id;

		$agent = new Manifest_Agent(
			array(
				'slug'  => $agent_id,
				'name'  => 'Test Agent',
				'tools' => $tool_names,
			),
			''
		);

		$controller = new Agent_Controller();
		$prop       = new \ReflectionProperty( Agent_Controller::class, 'current_agent' );
		$prop->setValue( $controller, $agent );

		return $controller;
	}

	/**
	 * Get the tool function names Agent_Controller would offer the LLM for
	 * the agent currently set on it.
	 *
	 * @param Agent_Controller $controller Controller with current_agent set.
	 * @return string[]
	 */
	private function get_offered_tool_names( Agent_Controller $controller ): array {
		$method = new \ReflectionMethod( Agent_Controller::class, 'get_tools_for_agent' );
		$tools  = $method->invoke( $controller );

		return array_map(
			static fn( array $tool ) => $tool['function']['name'] ?? '',
			$tools
		);
	}

	/**
	 * Assert a 'tool_suppressed' audit row exists for the given agent/tool.
	 *
	 * Queries the table directly rather than Audit_Log::get_recent(), which
	 * caches results in a transient keyed by its filter arguments.
	 *
	 * @param string $agent_id      Agent slug.
	 * @param string $ability_fn    Suppressed bridged tool function name.
	 * @param string $superseded_by Expected superseding bundled tool slug.
	 */
	private function assert_suppression_logged( string $agent_id, string $ability_fn, string $superseded_by ): void {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT details FROM {$wpdb->prefix}agent_builder_audit_log WHERE agent_id = %s AND action = 'tool_suppressed' AND target_type = %s ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$agent_id,
				$ability_fn
			),
			ARRAY_A
		);

		$this->assertNotNull( $row, "Expected a tool_suppressed audit row for {$ability_fn}." );
		$details = json_decode( $row['details'], true );
		$this->assertSame( $superseded_by, $details['superseded_by'] ?? null );
	}

	/**
	 * An agent holding the bundled user tool does NOT get the plugin's own
	 * generic wp-extended/get-users ability offered — this is the P36 case
	 * (user-assistant calling wp_extended__get_users instead of
	 * lock_user_account/list_privileged_users).
	 */
	public function test_agent_with_bundled_user_tool_does_not_get_bridged_get_users(): void {
		$controller = $this->make_controller_for_agent(
			'test-bridge-dedupe-users',
			array( 'list_privileged_users' )
		);

		$offered = $this->get_offered_tool_names( $controller );

		$this->assertContains( 'list_privileged_users', $offered );
		$this->assertNotContains( 'wp_extended__get_users', $offered );
		$this->assert_suppression_logged( 'test-bridge-dedupe-users', 'wp_extended__get_users', 'list_privileged_users' );
	}

	/**
	 * An agent with NO superseding bundled user tool still receives the
	 * bridged generic tool — the bridge's value (capabilities the plugin
	 * itself lacks) must be preserved.
	 */
	public function test_agent_without_bundled_user_tool_still_gets_bridged_get_users(): void {
		$controller = $this->make_controller_for_agent(
			'test-bridge-dedupe-no-users',
			array( 'get_post_content' )
		);

		$offered = $this->get_offered_tool_names( $controller );

		$this->assertContains( 'wp_extended__get_users', $offered );
	}

	/**
	 * An agent holding a bundled post/content tool does NOT get the plugin's
	 * own generic wp-extended/get-posts ability offered — this is the P16
	 * case (seo-optimizer calling wp_extended__get_posts instead of
	 * analyze_content_quality).
	 */
	public function test_agent_with_bundled_post_tool_does_not_get_bridged_get_posts(): void {
		$controller = $this->make_controller_for_agent(
			'test-bridge-dedupe-posts',
			array( 'analyze_content_quality' )
		);

		$offered = $this->get_offered_tool_names( $controller );

		$this->assertContains( 'analyze_content_quality', $offered );
		$this->assertNotContains( 'wp_extended__get_posts', $offered );
		$this->assert_suppression_logged( 'test-bridge-dedupe-posts', 'wp_extended__get_posts', 'analyze_content_quality' );
	}

	/**
	 * An agent with NO superseding bundled post tool still receives the
	 * bridged generic "get posts" tool.
	 */
	public function test_agent_without_bundled_post_tool_still_gets_bridged_get_posts(): void {
		$controller = $this->make_controller_for_agent(
			'test-bridge-dedupe-no-posts',
			array( 'list_privileged_users' )
		);

		$offered = $this->get_offered_tool_names( $controller );

		$this->assertContains( 'wp_extended__get_posts', $offered );
	}

	/**
	 * Environment-info family: an agent holding a bundled site-overview tool
	 * does not get a generic bridged environment-info tool (no such ability
	 * ships with this plugin today, so a synthetic one stands in for a
	 * future/real third-party provider of it).
	 */
	public function test_agent_with_bundled_site_overview_does_not_get_bridged_environment_info(): void {
		$this->register_synthetic_ability( 'core/get-environment-info' );

		$controller = $this->make_controller_for_agent(
			'test-bridge-dedupe-env',
			array( 'get_site_overview' )
		);

		$offered = $this->get_offered_tool_names( $controller );

		$this->assertContains( 'get_site_overview', $offered );
		$this->assertNotContains( 'core__get_environment_info', $offered );
		$this->assert_suppression_logged( 'test-bridge-dedupe-env', 'core__get_environment_info', 'get_site_overview' );
	}

	/**
	 * The suppression map is filterable via agent_builder_bridge_suppressed_tools.
	 */
	public function test_suppression_map_is_filterable(): void {
		$filter = static function ( array $map ): array {
			unset( $map['wp_extended__get_users'] );
			return $map;
		};
		add_filter( 'agent_builder_bridge_suppressed_tools', $filter );

		$controller = $this->make_controller_for_agent(
			'test-bridge-dedupe-filtered',
			array( 'list_privileged_users' )
		);

		$offered = $this->get_offered_tool_names( $controller );

		remove_filter( 'agent_builder_bridge_suppressed_tools', $filter );

		$this->assertContains( 'wp_extended__get_users', $offered, 'A filter clearing the map entry must restore the bridged tool.' );
	}
}
