<?php
/**
 * Unit tests for the Site Brief Agent_Matcher.
 *
 * @package Agentic\Tests
 */

namespace Agentic\Tests;

use Agentic\Site_Brief\Agent_Matcher;
use Agentic\Site_Brief\Checker_Wc_Unpaid;
use Agentic\Site_Brief\Site_Brief_Runner;

/**
 * Best-fit agent discovery among installed agents, and map invalidation.
 */
class Test_Agent_Matcher extends TestCase {

	/**
	 * Reset all Agent_Matcher state between tests.
	 */
	public function setUp(): void {
		parent::setUp();
		Site_Brief_Runner::load_checkers();
		$this->reset_matcher_state();
	}

	/**
	 * Clean up.
	 */
	public function tearDown(): void {
		$this->reset_matcher_state();
		parent::tearDown();
	}

	/**
	 * Drop every cache Agent_Matcher keeps.
	 */
	private function reset_matcher_state(): void {
		delete_option( Agent_Matcher::MAP_OPTION );
		delete_option( 'agent_builder_allow_platform_sync' );
		Agent_Matcher::reset_request_cache();
	}

	/**
	 * Among two bundled agents that both cover a required tool, the more
	 * focused one (fewer total tools) wins over a mega-agent.
	 */
	public function test_resolve_picks_the_more_focused_bundled_agent(): void {
		$resolved = Agent_Matcher::resolve( array( 'run_health_check' ) );

		$this->assertSame( 'site-health-sentinel', $resolved['slug'] );
		$this->assertTrue( $resolved['installed'] );
		$this->assertSame( 'bundled', $resolved['source'] );
		$this->assertArrayNotHasKey( 'upsell_url', $resolved );
	}

	/**
	 * Nothing installed covers an unknown tool: resolution falls back to the
	 * checker's hint when that agent is installed.
	 */
	public function test_resolve_falls_back_to_installed_checker_hint(): void {
		$resolved = Agent_Matcher::resolve( array( 'totally_unknown_tool_xyz' ), '', 'site-health-sentinel' );

		$this->assertSame( 'site-health-sentinel', $resolved['slug'] );
		$this->assertTrue( $resolved['installed'] );
		$this->assertSame( 'fallback', $resolved['source'] );
	}

	/**
	 * A hint naming an agent this site does not have (e.g. a paid add-on)
	 * falls back to the bundled WordPress Assistant, never to the missing one.
	 */
	public function test_resolve_never_recommends_an_agent_that_is_not_installed(): void {
		$resolved = Agent_Matcher::resolve( array( 'wc_get_orders' ), 'woocommerce', 'woocommerce-assistant' );

		$this->assertSame( 'wordpress-assistant', $resolved['slug'] );
		$this->assertTrue( $resolved['installed'] );
		$this->assertSame( 'fallback', $resolved['source'] );
	}

	/**
	 * The matcher never contacts a remote agent catalog, even with the model
	 * catalog sync turned on, and no result carries a link to buy an agent.
	 */
	public function test_resolution_is_local_and_has_no_marketplace_url(): void {
		update_option( 'agent_builder_allow_platform_sync', '1' );
		$calls = 0;
		add_filter(
			'pre_http_request',
			static function ( $preempt ) use ( &$calls ) {
				++$calls;
				return $preempt;
			}
		);

		$results = array(
			Agent_Matcher::resolve( array( 'run_health_check' ) ),
			Agent_Matcher::resolve( array( 'wc_get_orders' ), 'woocommerce', 'woocommerce-assistant' ),
			Agent_Matcher::resolve( array( 'totally_unknown_tool_xyz' ) ),
			Agent_Matcher::resolve_for_checker( new Checker_Wc_Unpaid() ),
		);

		$this->assertSame( 0, $calls, 'Agent_Matcher must not make HTTP requests.' );
		foreach ( $results as $resolved ) {
			$this->assertTrue( $resolved['installed'] );
			$this->assertStringNotContainsString( 'marketplace', strtolower( (string) wp_json_encode( $resolved ) ) );
			$this->assertStringNotContainsString( 'agentic-plugin.com', (string) wp_json_encode( $resolved ) );
		}
	}

	/**
	 * A WooCommerce checker resolves to an installed agent. No bundled agent
	 * declares the merchant wc_* tools, so this is the fallback path.
	 */
	public function test_woocommerce_checker_resolves_to_an_installed_agent(): void {
		$resolved = Agent_Matcher::resolve_for_checker( new Checker_Wc_Unpaid() );

		$this->assertSame( 'wordpress-assistant', $resolved['slug'] );
		$this->assertTrue( $resolved['installed'] );
	}

	/**
	 * The resolved checker -> agent map is cached in the option and survives
	 * a second call without recomputing (no catalog opt-in needed for this
	 * assertion — installed-only resolution is deterministic).
	 */
	public function test_resolve_for_checker_caches_in_the_map_option(): void {
		$checker = new Checker_Wc_Unpaid();

		$this->assertSame( array(), Agent_Matcher::get_map() );

		$first = Agent_Matcher::resolve_for_checker( $checker );
		$map   = Agent_Matcher::get_map();

		$this->assertArrayHasKey( 'wc_unpaid', $map );
		$this->assertSame( $first['slug'], $map['wc_unpaid']['slug'] );
		$this->assertGreaterThan( 0, $map['wc_unpaid']['resolved_at'] );
	}

	/**
	 * Installing/activating or deleting an agent invalidates the cached map
	 * so the next scan re-resolves against the changed set of agents.
	 */
	public function test_map_is_invalidated_on_agent_activated(): void {
		Agent_Matcher::resolve_for_checker( new Checker_Wc_Unpaid() );
		$this->assertNotSame( array(), Agent_Matcher::get_map() );

		do_action( 'agent_builder_agent_activated', 'some-agent', array() );

		$this->assertSame( array(), Agent_Matcher::get_map() );
	}

	/**
	 * Deleting an agent also invalidates the map.
	 */
	public function test_map_is_invalidated_on_agent_deleted(): void {
		Agent_Matcher::resolve_for_checker( new Checker_Wc_Unpaid() );
		$this->assertNotSame( array(), Agent_Matcher::get_map() );

		do_action( 'agent_builder_agent_deleted', 'some-agent' );

		$this->assertSame( array(), Agent_Matcher::get_map() );
	}
}
