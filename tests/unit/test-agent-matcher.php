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
 * Best-fit agent discovery, catalog caching, and map invalidation.
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
	 * Drop every cache Agent_Matcher keeps, plus the platform-sync opt-in.
	 */
	private function reset_matcher_state(): void {
		delete_option( Agent_Matcher::MAP_OPTION );
		delete_option( Agent_Matcher::CATALOG_LAST_GOOD_OPTION );
		delete_option( 'agent_builder_allow_platform_sync' );
		delete_transient( Agent_Matcher::CATALOG_TRANSIENT );
		Agent_Matcher::reset_request_cache();
	}

	/**
	 * Fake the marketplace catalog response for the rest of this test.
	 *
	 * @param array<int, array<string, mixed>> $agents Catalog agent entries.
	 * @return void
	 */
	private function fake_catalog( array $agents ): void {
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( $agents ) {
				if ( ! str_contains( (string) $url, 'agentic-marketplace/v1/agents' ) ) {
					return $preempt;
				}
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array( 'agents' => $agents ) ),
				);
			},
			10,
			3
		);
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
		$this->assertNull( $resolved['upsell_url'] );
	}

	/**
	 * An installed agent always beats an uninstalled marketplace agent, even
	 * one that would otherwise win the "fewest tools" tiebreak.
	 */
	public function test_resolve_prefers_installed_over_smaller_marketplace_agent(): void {
		update_option( 'agent_builder_allow_platform_sync', '1' );
		$this->fake_catalog(
			array(
				array(
					'slug'  => 'tiny-updates-bot',
					'name'  => 'Tiny Updates Bot',
					'tools' => array( 'check_plugin_updates' ),
				),
			)
		);

		$resolved = Agent_Matcher::resolve( array( 'check_plugin_updates' ) );

		$this->assertSame( 'site-health-sentinel', $resolved['slug'] );
		$this->assertTrue( $resolved['installed'] );
	}

	/**
	 * When no installed/bundled agent fully covers the required tools, an
	 * opted-in marketplace agent that does is offered as an upsell.
	 */
	public function test_resolve_offers_marketplace_agent_when_nothing_installed_covers_it(): void {
		update_option( 'agent_builder_allow_platform_sync', '1' );
		$this->fake_catalog(
			array(
				array(
					'slug'     => 'woocommerce-assistant',
					'name'     => 'WooCommerce Assistant',
					'url'      => 'https://agentic-plugin.com/marketplace/woocommerce-assistant/',
					'rating'   => 4.8,
					'downloads' => 500,
					'tools'    => array( 'wc_get_orders', 'wc_get_stock_report', 'wc_get_store_stats' ),
				),
			)
		);

		$resolved = Agent_Matcher::resolve( array( 'wc_get_orders' ), '', 'woocommerce-assistant' );

		$this->assertSame( 'woocommerce-assistant', $resolved['slug'] );
		$this->assertFalse( $resolved['installed'] );
		$this->assertSame( 'marketplace', $resolved['source'] );
		$this->assertSame( 'https://agentic-plugin.com/marketplace/woocommerce-assistant/', $resolved['upsell_url'] );
	}

	/**
	 * Nothing covers an unknown tool and the marketplace is disabled (the
	 * default): resolution falls back to the checker's own agent hint
	 * instead of leaving a card without a recommended agent.
	 */
	public function test_resolve_falls_back_to_checker_hint_when_nothing_covers_it(): void {
		$resolved = Agent_Matcher::resolve( array( 'totally_unknown_tool_xyz' ), '', 'woocommerce-assistant' );

		$this->assertSame( 'woocommerce-assistant', $resolved['slug'] );
		$this->assertFalse( $resolved['installed'] );
		$this->assertSame( 'fallback', $resolved['source'] );
		$this->assertNotEmpty( $resolved['upsell_url'] );
	}

	/**
	 * A merchant WooCommerce checker resolves to woocommerce-assistant. No
	 * bundled agent declares the wc_* tools, and the marketplace is off by
	 * default, so this exercises the fallback path — but the recommended
	 * agent is never left empty.
	 */
	public function test_woocommerce_checker_resolves_to_woocommerce_assistant(): void {
		$resolved = Agent_Matcher::resolve_for_checker( new Checker_Wc_Unpaid() );

		$this->assertSame( 'woocommerce-assistant', $resolved['slug'] );
		$this->assertFalse( $resolved['installed'] );
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

	/**
	 * The marketplace catalog is opt-in: with the platform-sync setting off
	 * (the default) fetch_catalog() never phones home.
	 */
	public function test_catalog_is_not_fetched_without_opt_in(): void {
		$calls = 0;
		add_filter(
			'pre_http_request',
			static function ( $preempt ) use ( &$calls ) {
				++$calls;
				return $preempt;
			}
		);

		$catalog = Agent_Matcher::fetch_catalog();

		$this->assertSame( array(), $catalog );
		$this->assertSame( 0, $calls );
	}

	/**
	 * A failed/disabled catalog fetch degrades gracefully to the
	 * previously cached "last good" catalog rather than an error.
	 */
	public function test_catalog_falls_back_to_last_good_on_failure(): void {
		update_option( 'agent_builder_allow_platform_sync', '1' );
		update_option(
			Agent_Matcher::CATALOG_LAST_GOOD_OPTION,
			array( array( 'slug' => 'stale-agent', 'name' => 'Stale Agent', 'tools' => array() ) )
		);

		add_filter(
			'pre_http_request',
			static function () {
				return new \WP_Error( 'test_offline', 'offline' );
			}
		);

		$catalog = Agent_Matcher::fetch_catalog();

		$this->assertCount( 1, $catalog );
		$this->assertSame( 'stale-agent', $catalog[0]['slug'] );
	}
}
