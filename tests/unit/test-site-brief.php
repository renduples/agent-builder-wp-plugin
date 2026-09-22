<?php
/**
 * Unit tests for Site Brief.
 *
 * @package Agentic\Tests
 */

namespace Agentic\Tests;

use Agentic\Audit_Log;
use Agentic\Site_Brief\Agent_Matcher;
use Agentic\Site_Brief\Checker_Wc_Low_Stock;
use Agentic\Site_Brief\Checker_Wc_Stats;
use Agentic\Site_Brief\Checker_Wc_Unpaid;
use Agentic\Site_Brief\Site_Brief_Controller;
use Agentic\Site_Brief\Site_Brief_Runner;
use Agentic\Site_Brief\Site_Brief_Store;
use Agentic\Tool_Executor;
use Agentic\Tool_Loader;

/**
 * Site Brief runner, store, and REST tests.
 */
class Test_Site_Brief extends TestCase {

	/**
	 * Reset store between tests.
	 */
	public function setUp(): void {
		parent::setUp();
		delete_option( Site_Brief_Store::OPTION );
		delete_option( Site_Brief_Runner::ENABLED_CHECKERS_OPTION );
		delete_transient( Site_Brief_Runner::RUN_LOCK );
		Site_Brief_Runner::clear_progress();
		Site_Brief_Runner::load_checkers();
		$this->reset_matcher_state();
	}

	/**
	 * Clean option leftover.
	 */
	public function tearDown(): void {
		delete_option( Site_Brief_Store::OPTION );
		delete_option( Site_Brief_Runner::ENABLED_CHECKERS_OPTION );
		delete_transient( Site_Brief_Runner::RUN_LOCK );
		Site_Brief_Runner::clear_progress();
		$this->reset_matcher_state();
		parent::tearDown();
	}

	/**
	 * Drop every cache Agent_Matcher keeps between tests.
	 */
	private function reset_matcher_state(): void {
		delete_option( Agent_Matcher::MAP_OPTION );
		delete_option( Agent_Matcher::CATALOG_LAST_GOOD_OPTION );
		delete_option( 'agent_builder_allow_platform_sync' );
		delete_transient( Agent_Matcher::CATALOG_TRANSIENT );
		Agent_Matcher::reset_request_cache();
	}

	/**
	 * Observe mode refuses a High-risk tool even if a caller tries to pass it.
	 */
	public function test_allowlist_rejects_high_tool(): void {
		$runner = new Site_Brief_Runner();
		$result = $runner->observe_tool(
			'db_update_option',
			array(
				'name'  => 'agent_builder_test_site_brief',
				'value' => 'x',
			)
		);

		$this->assertSame( 'not_allowlisted', $result['error'] );
		$this->assertFalse( Site_Brief_Runner::is_allowlisted( 'db_update_option' ) );
		$this->assertTrue( Site_Brief_Runner::is_banned( 'db_update_option' ) );
		$this->assertTrue( Site_Brief_Runner::is_banned( 'check_core_web_vitals' ) );
		$this->assertFalse( Site_Brief_Runner::is_allowlisted( 'check_core_web_vitals' ) );

		$executor = new Tool_Executor( Tool_Loader::get_instance(), new Audit_Log(), null );
		$forced   = $executor->observe(
			'db_update_option',
			array(
				'name'  => 'agent_builder_test_site_brief',
				'value' => 'x',
			),
			array( 'db_update_option' )
		);
		$this->assertSame( 'risk_too_high', $forced['error'] );
	}

	/**
	 * A Tools Hub disabled read is treated as unavailable — no card.
	 */
	public function test_disabled_allowlisted_tool_is_skipped(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'agent_builder_tools';
		$wpdb->replace(
			$table,
			array(
				'name'        => 'check_plugin_updates',
				'description' => 'check_plugin_updates',
				'category'    => 'security',
				'source'      => 'core',
				'enabled'     => 0,
				'parameters'  => '{}',
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s' )
		);
		\Agentic\Tools_Registry::bust_cache();

		$runner = new Site_Brief_Runner();
		$this->assertFalse( $runner->tool_is_usable( 'check_plugin_updates' ) );
		$observed = $runner->observe_tool( 'check_plugin_updates', array() );
		$this->assertSame( 'disabled', $observed['error'] );
	}

	/**
	 * Woo checkers skip the whole family when WooCommerce is not active.
	 */
	public function test_woo_checkers_skip_when_woo_absent(): void {
		$this->assertFalse( class_exists( 'WooCommerce' ) );
		$this->assertFalse( ( new Checker_Wc_Unpaid() )->is_applicable() );
		$this->assertFalse( ( new Checker_Wc_Low_Stock() )->is_applicable() );
		$this->assertFalse( ( new Checker_Wc_Stats() )->is_applicable() );
	}

	/**
	 * Dismiss hides a card until its evidence hash changes.
	 */
	public function test_dismiss_hides_until_evidence_hash_changes(): void {
		$card = array(
			'id'            => 'plugin_updates:akismet',
			'checker_id'    => 'plugin_updates',
			'evidence_hash' => hash( 'sha256', 'akismet:1.0:1.1' ),
			'title'         => 'Akismet has an update',
		);
		Site_Brief_Store::save(
			array(
				'status'   => 'complete',
				'last_run' => gmdate( 'c' ),
				'cards'    => array( $card ),
			)
		);
		Site_Brief_Store::dismiss( $card['id'], $card['evidence_hash'] );

		$stored = Site_Brief_Store::get();
		$this->assertTrue(
			Site_Brief_Store::is_dismissed( $card['id'], $card['evidence_hash'], $stored['dismissed'] )
		);
		$this->assertFalse(
			Site_Brief_Store::is_dismissed(
				$card['id'],
				hash( 'sha256', 'akismet:1.0:1.2' ),
				$stored['dismissed']
			)
		);
	}

	/**
	 * Dismissing a card drops it from the presented brief straight away, not
	 * only after the next scan. Regression: present() must apply the dismissed
	 * filter the runner already applies, so the dismiss response and a plain
	 * reload both hide the card.
	 */
	public function test_dismiss_card_hides_it_from_presented_brief(): void {
		$card = array(
			'id'            => 'plugin_updates:akismet',
			'checker_id'    => 'plugin_updates',
			'evidence_hash' => hash( 'sha256', 'akismet:1.0:1.1' ),
			'title'         => 'Akismet has an update',
		);
		Site_Brief_Store::save(
			array(
				'status'   => 'complete',
				'last_run' => gmdate( 'c' ),
				'cards'    => array( $card ),
			)
		);

		$request = new \WP_REST_Request( 'POST', '/agentic/v1/site-brief/cards/plugin_updates:akismet/dismiss' );
		$request->set_param( 'id', $card['id'] );

		$response = Site_Brief_Controller::dismiss_card( $request );
		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );

		$ids = wp_list_pluck( (array) ( $response->get_data()['cards'] ?? array() ), 'id' );
		$this->assertNotContains(
			$card['id'],
			$ids,
			'A dismissed card must not appear in the presented brief.'
		);
		$this->assertTrue(
			(bool) ( $response->get_data()['healthy'] ?? false ),
			'With its only card dismissed the brief should read as healthy.'
		);
	}

	/**
	 * The JSON option is not autoloaded.
	 */
	public function test_option_is_not_autoloaded(): void {
		Site_Brief_Store::save(
			array(
				'status'   => 'complete',
				'last_run' => gmdate( 'c' ),
				'cards'    => array(),
			)
		);

		global $wpdb;
		$autoload = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT autoload FROM {$wpdb->options} WHERE option_name = %s",
				Site_Brief_Store::OPTION
			)
		);
		$this->assertTrue(
			in_array( (string) $autoload, array( 'no', 'off', 'false', '0' ), true ),
			'autoload was ' . (string) $autoload
		);
	}

	/**
	 * REST GET without auth is 401; a subscriber gets 403.
	 */
	public function test_rest_requires_caps(): void {
		wp_set_current_user( 0 );
		$get = rest_get_server()->dispatch( new \WP_REST_Request( 'GET', '/agentic/v1/site-brief' ) );
		$this->assertSame( 401, $get->get_status() );

		$run = rest_get_server()->dispatch( new \WP_REST_Request( 'POST', '/agentic/v1/site-brief/run' ) );
		$this->assertSame( 401, $run->get_status() );

		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$get2 = rest_get_server()->dispatch( new \WP_REST_Request( 'GET', '/agentic/v1/site-brief' ) );
		$this->assertSame( 403, $get2->get_status() );

		$run2 = rest_get_server()->dispatch( new \WP_REST_Request( 'POST', '/agentic/v1/site-brief/run' ) );
		$this->assertSame( 403, $run2->get_status() );
	}

	/**
	 * GET returns stored cards and does not invoke tools or take the run lock.
	 */
	public function test_get_does_not_invoke_tools(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		Site_Brief_Store::save(
			array(
				'status'   => 'complete',
				'last_run' => '2026-09-20T16:00:00+00:00',
				'cards'    => array(
					array(
						'id'         => 'plugin_updates',
						'checker_id' => 'plugin_updates',
						'title'      => '1 plugin has updates',
						'evidence'   => 'Akismet (1.0 → 1.1)',
					),
				),
			)
		);

		$http_calls = 0;
		add_filter(
			'pre_http_request',
			static function () use ( &$http_calls ) {
				++$http_calls;
				return new \WP_Error( 'site_brief_test_blocked', 'HTTP should not run on GET' );
			}
		);

		$response = rest_get_server()->dispatch( new \WP_REST_Request( 'GET', '/agentic/v1/site-brief' ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 0, $http_calls );
		$this->assertFalse( get_transient( Site_Brief_Runner::RUN_LOCK ) );
		$this->assertSame( '2026-09-20T16:00:00+00:00', $data['last_run'] );
		$this->assertSame( 'plugin_updates', $data['cards'][0]['id'] );
		$this->assertArrayNotHasKey( 'tool_slugs', $data['cards'][0] );
	}

	/**
	 * Controller class is wired.
	 */
	public function test_controller_class_exists(): void {
		$this->assertTrue( class_exists( Site_Brief_Controller::class ) );
		$this->assertTrue( class_exists( Site_Brief_Runner::class ) );
	}

	/**
	 * Assign records state in the store, present() surfaces it on the card
	 * (assign response AND a later GET/reload), and the response carries a
	 * short chat redirect_url that does not embed the finding.
	 */
	public function test_assign_records_state_and_present_surfaces_it(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$card = array(
			'id'              => 'plugin_updates:akismet',
			'checker_id'      => 'plugin_updates',
			'evidence_hash'   => hash( 'sha256', 'akismet:1.0:1.1' ),
			'title'           => 'Akismet has an update',
			'evidence'        => 'Akismet (1.0 → 1.1)',
			'proposed_action' => 'Update the plugin.',
			'agent'           => 'site-health-sentinel',
			'agent_label'     => 'Site Health Sentinel',
		);
		Site_Brief_Store::save(
			array(
				'status'   => 'complete',
				'last_run' => gmdate( 'c' ),
				'cards'    => array( $card ),
			)
		);

		$request = new \WP_REST_Request( 'POST', '/agentic/v1/site-brief/cards/plugin_updates:akismet/assign' );
		$request->set_param( 'id', $card['id'] );

		$response = Site_Brief_Controller::assign_card( $request );
		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertNotEmpty( $data['redirect_url'] );
		$this->assertStringContainsString( 'page=agentic-chat', $data['redirect_url'] );
		$this->assertStringContainsString( 'agent=site-health-sentinel', $data['redirect_url'] );
		$this->assertStringContainsString( 'brief=', $data['redirect_url'] );
		$this->assertStringNotContainsString( 'Akismet', $data['redirect_url'] );
		$this->assertStringNotContainsString( rawurlencode( $card['evidence'] ), $data['redirect_url'] );

		$this->assertNotEmpty( $data['cards'] );
		$this->assertSame( $card['id'], $data['cards'][0]['id'] );
		$this->assertIsArray( $data['cards'][0]['assigned'] ?? null );
		$this->assertSame( 'site-health-sentinel', $data['cards'][0]['assigned']['agent'] );
		$this->assertSame( 'Site Health Sentinel', $data['cards'][0]['assigned']['agent_label'] );
		$this->assertNotEmpty( $data['cards'][0]['assigned']['at'] );

		$stored = Site_Brief_Store::get();
		$this->assertArrayHasKey( $card['id'], $stored['assigned'] );
		$this->assertSame( 'site-health-sentinel', $stored['assigned'][ $card['id'] ]['agent'] );

		$reload = rest_get_server()->dispatch( new \WP_REST_Request( 'GET', '/agentic/v1/site-brief' ) );
		$this->assertSame( 200, $reload->get_status() );
		$reloaded = $reload->get_data();
		$this->assertSame( $card['id'], $reloaded['cards'][0]['id'] );
		$this->assertSame( 'site-health-sentinel', $reloaded['cards'][0]['assigned']['agent'] );
		$this->assertSame( 'Site Health Sentinel', $reloaded['cards'][0]['assigned']['agent_label'] );
		$this->assertSame( $data['cards'][0]['assigned']['at'], $reloaded['cards'][0]['assigned']['at'] );

		$parsed = wp_parse_url( $data['redirect_url'] );
		$query  = array();
		parse_str( (string) ( $parsed['query'] ?? '' ), $query );
		$token = sanitize_key( (string) ( $query['brief'] ?? '' ) );
		$this->assertNotSame( '', $token );

		$open_req = new \WP_REST_Request( 'GET', '/agentic/v1/site-brief/opening/' . $token );
		$open_res = rest_get_server()->dispatch( $open_req );
		$this->assertSame( 200, $open_res->get_status() );
		$opening = (string) ( $open_res->get_data()['message'] ?? '' );
		$this->assertStringContainsString( 'Akismet has an update', $opening );
		$this->assertStringContainsString( 'Akismet (1.0 → 1.1)', $opening );
		$this->assertStringContainsString( 'Update the plugin.', $opening );
		$this->assertStringContainsString( 'ask me before making any change', $opening );

		$replay = rest_get_server()->dispatch( $open_req );
		$this->assertSame( 404, $replay->get_status() );
	}

	/**
	 * Assigning an unknown card is 404 and does not write assigned state.
	 */
	public function test_assign_unknown_card_is_404(): void {
		$request = new \WP_REST_Request( 'POST', '/agentic/v1/site-brief/cards/missing/assign' );
		$request->set_param( 'id', 'missing' );

		$response = Site_Brief_Controller::assign_card( $request );
		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'site_brief_unknown_card', $response->get_error_code() );

		$stored = Site_Brief_Store::get();
		$this->assertSame( array(), $stored['assigned'] );
	}

	/**
	 * Card ids must be URL-route-safe. A ':' separator becomes %3A under
	 * encodeURIComponent, and WP REST routing then fails to match, so the
	 * assign/dismiss routes 404 for cards such as site_health:1. Ids use '.'.
	 */
	public function test_card_ids_are_url_route_safe(): void {
		$checker = new \Agentic\Site_Brief\Checker_Site_Health();
		$method  = new \ReflectionMethod( $checker, 'card' );
		$card    = $method->invoke(
			$checker,
			array(
				'subject'  => '1',
				'title'    => 'Example',
				'evidence' => 'Example',
			)
		);

		$this->assertSame( 'site_health.1', $card['id'] );
		$this->assertStringNotContainsString( ':', (string) $card['id'] );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_.-]+$/', (string) $card['id'] );
	}

	/**
	 * Every Site Brief card must be jointly serviceable: the agent
	 * Agent_Matcher recommends must actually be able to receive the tools
	 * the checker relies on. For a bundled agent that means each required
	 * tool is declared in BOTH agent.json (the chat runtime's candidate
	 * list) AND abilities.json (the runtime gate). The marketplace path is
	 * exercised too, with a faked catalog standing in for the real one, so a
	 * checker whose tools nothing bundled covers (WooCommerce) still
	 * resolves to a genuinely capable agent rather than a bare hint.
	 *
	 * This guards against a checker whose matched agent cannot help, which
	 * the handler-level tests miss because they never resolve tools to an
	 * agent.
	 */
	public function test_every_checker_agent_can_service_its_card(): void {
		$lib = AGENT_BUILDER_DIR . 'library/agents/';

		update_option( 'agent_builder_allow_platform_sync', '1' );
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) {
				if ( ! str_contains( (string) $url, 'agentic-marketplace/v1/agents' ) ) {
					return $preempt;
				}
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'agents' => array(
								array(
									'slug'  => 'woocommerce-assistant',
									'name'  => 'WooCommerce Assistant',
									'url'   => 'https://agentic-plugin.com/marketplace/woocommerce-assistant/',
									'tools' => array( 'wc_get_orders', 'wc_get_stock_report', 'wc_get_store_stats' ),
								),
							),
						)
					),
				);
			},
			10,
			3
		);

		$checkers = ( new \ReflectionClass( Site_Brief_Runner::class ) )->getConstant( 'CHECKERS' );
		$this->assertNotEmpty( $checkers, 'Runner exposes no checkers.' );

		foreach ( $checkers as $id => $class ) {
			$checker  = new $class();
			$needs    = $checker->get_tools();
			$resolved = Agent_Matcher::resolve_for_checker( $checker );
			$agent    = $resolved['slug'];

			$this->assertNotSame( '', $agent, "Checker {$id} resolved to no agent." );

			if ( 'marketplace' === $resolved['source'] ) {
				$this->assertDirectoryDoesNotExist(
					$lib . $agent,
					"Agent {$agent} is bundled; Agent_Matcher should have matched it as installed, not marketplace."
				);
				// The faked catalog feed above IS this test's declaration of
				// that agent's tools, and eligibility already required full
				// coverage — nothing further to check here.
				continue;
			}

			$agent_json = json_decode( (string) file_get_contents( $lib . $agent . '/agent.json' ), true );
			$abilities  = json_decode( (string) file_get_contents( $lib . $agent . '/abilities.json' ), true );
			$declared   = (array) ( $agent_json['tools'] ?? array() );
			$granted    = array_keys( (array) ( $abilities['abilities'] ?? array() ) );

			foreach ( $needs as $tool ) {
				$this->assertContains(
					$tool,
					$declared,
					"Checker {$id}: agent {$agent} agent.json 'tools' is missing {$tool} (chat runtime never offers it)."
				);
				$this->assertContains(
					$tool,
					$granted,
					"Checker {$id}: agent {$agent} abilities.json is missing {$tool} (runtime gate blocks it)."
				);
			}
		}
	}

	/**
	 * Unset === enabled, so a checker defaults on until explicitly disabled.
	 */
	public function test_checker_enabled_defaults_true_when_unset(): void {
		$this->assertTrue( Site_Brief_Runner::is_checker_enabled( 'plugin_updates' ) );

		update_option( Site_Brief_Runner::ENABLED_CHECKERS_OPTION, array( 'plugin_updates' => false ) );

		$this->assertFalse( Site_Brief_Runner::is_checker_enabled( 'plugin_updates' ) );
		$this->assertTrue( Site_Brief_Runner::is_checker_enabled( 'site_health' ) );
	}

	/**
	 * A disabled checker is excluded from the run entirely — it never
	 * appears in the progress step list, regardless of whether it would
	 * have produced a card.
	 */
	public function test_disabled_checker_is_excluded_from_the_scan(): void {
		update_option( Site_Brief_Runner::ENABLED_CHECKERS_OPTION, array( 'plugin_updates' => false ) );

		$runner = new Site_Brief_Runner();
		$runner->run();

		$progress = Site_Brief_Runner::get_progress();
		$step_ids = wp_list_pluck( $progress['steps'], 'id' );

		$this->assertNotContains( 'plugin_updates', $step_ids );
		$this->assertContains( 'matching', $step_ids );
	}

	/**
	 * A full run writes progress up front (every planned step, including the
	 * trailing "matching" step) and leaves it in a 'done' state with every
	 * step marked complete and nothing left "current".
	 */
	public function test_run_writes_progress_and_finishes_done(): void {
		$this->assertSame( 'idle', Site_Brief_Runner::get_progress()['status'] );

		$runner = new Site_Brief_Runner();
		$result = $runner->run();

		$progress = Site_Brief_Runner::get_progress();
		$this->assertSame( 'done', $progress['status'] );
		$this->assertNull( $progress['current'] );
		$this->assertNotEmpty( $progress['steps'] );

		$step_ids = wp_list_pluck( $progress['steps'], 'id' );
		$this->assertSame( 'matching', end( $step_ids ) );
		$this->assertSame( $step_ids, $progress['done'], 'Every planned step should be marked done.' );
		$this->assertSame( 'complete', $result['status'] );
	}

	/**
	 * GET /site-brief/progress surfaces the runner's live transient.
	 */
	public function test_rest_progress_reflects_runner_state(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$idle = rest_get_server()->dispatch( new \WP_REST_Request( 'GET', '/agentic/v1/site-brief/progress' ) );
		$this->assertSame( 200, $idle->get_status() );
		$this->assertSame( 'idle', $idle->get_data()['status'] );

		( new Site_Brief_Runner() )->run();

		$done = rest_get_server()->dispatch( new \WP_REST_Request( 'GET', '/agentic/v1/site-brief/progress' ) );
		$this->assertSame( 200, $done->get_status() );
		$this->assertSame( 'done', $done->get_data()['status'] );
	}

	/**
	 * GET /site-brief/checkers lists every checker enabled by default; POST
	 * persists a change and the runner honors it immediately.
	 */
	public function test_rest_checkers_list_and_update(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$get = rest_get_server()->dispatch( new \WP_REST_Request( 'GET', '/agentic/v1/site-brief/checkers' ) );
		$this->assertSame( 200, $get->get_status() );

		$rows = $get->get_data()['checkers'];
		$ids  = wp_list_pluck( $rows, 'id' );
		$this->assertContains( 'plugin_updates', $ids );
		foreach ( $rows as $row ) {
			$this->assertTrue( $row['enabled'], "Checker {$row['id']} should default enabled." );
			$this->assertNotSame( '', $row['category'] );
			$this->assertNotSame( '', $row['label'] );
		}

		$post = new \WP_REST_Request( 'POST', '/agentic/v1/site-brief/checkers' );
		$post->set_body_params( array( 'enabled' => array( 'plugin_updates' => false ) ) );
		$response = rest_get_server()->dispatch( $post );
		$this->assertSame( 200, $response->get_status() );

		$updated = wp_list_pluck( $response->get_data()['checkers'], 'enabled', 'id' );
		$this->assertFalse( $updated['plugin_updates'] );
		$this->assertTrue( $updated['site_health'] );
		$this->assertFalse( Site_Brief_Runner::is_checker_enabled( 'plugin_updates' ) );
	}

	/**
	 * A subscriber may view checkers but not change them.
	 */
	public function test_rest_checkers_update_requires_manage_cap(): void {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$get = rest_get_server()->dispatch( new \WP_REST_Request( 'GET', '/agentic/v1/site-brief/checkers' ) );
		$this->assertSame( 403, $get->get_status() );

		$post = new \WP_REST_Request( 'POST', '/agentic/v1/site-brief/checkers' );
		$post->set_body_params( array( 'enabled' => array( 'plugin_updates' => false ) ) );
		$response = rest_get_server()->dispatch( $post );
		$this->assertSame( 403, $response->get_status() );
	}
}
