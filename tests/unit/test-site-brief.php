<?php
/**
 * Unit tests for Site Brief.
 *
 * @package Agentic\Tests
 */

namespace Agentic\Tests;

use Agentic\Audit_Log;
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
		delete_transient( Site_Brief_Runner::RUN_LOCK );
		Site_Brief_Runner::load_checkers();
	}

	/**
	 * Clean option leftover.
	 */
	public function tearDown(): void {
		delete_option( Site_Brief_Store::OPTION );
		delete_transient( Site_Brief_Runner::RUN_LOCK );
		parent::tearDown();
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
}
