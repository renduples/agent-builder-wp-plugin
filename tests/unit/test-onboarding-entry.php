<?php
/**
 * Unit tests for the first-run entry points: the one-time activation
 * redirect, the Plugins-screen action links, the default agent, the
 * WooCommerce-only bundled agent rule, the dashboard Site Passport summary,
 * and plain-language Site Brief copy.
 *
 * @package Agentic\Tests
 */

namespace Agentic\Tests;

use Agentic\Activator;
use Agentic\Admin_Menu_Handler;
use Agentic\Agent_Ready_Score;
use Agentic\Dashboard_REST;
use Agentic\Provider_Registry;

/**
 * Test case for onboarding / first-run behaviour.
 */
class Test_Onboarding_Entry extends TestCase {

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	private int $admin_id = 0;

	/**
	 * Active-agents option as it was before the test.
	 *
	 * @var mixed
	 */
	private $saved_active_agents;

	/**
	 * Start every test as an administrator with no redirect flag.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
		delete_transient( Activator::ACTIVATION_REDIRECT_TRANSIENT );
		delete_option( 'agent_builder_onboarding_complete' );
		$this->saved_active_agents = get_option( 'agent_builder_active_agents', array() );
	}

	/**
	 * Undo per-test globals.
	 */
	public function tearDown(): void {
		unset( $_GET['activate-multi'], $_REQUEST['action'] );
		remove_all_filters( 'wp_doing_ajax' );
		delete_transient( Activator::ACTIVATION_REDIRECT_TRANSIENT );
		delete_option( 'agent_builder_onboarding_complete' );
		delete_option( Agent_Ready_Score::OPTION );
		update_option( 'agent_builder_active_agents', $this->saved_active_agents );
		delete_option( Agent_Ready_Score::OPTION );
		Provider_Registry::save_api_key( 'agentic', '' );
		delete_option( 'agent_builder_license_key' );
		Provider_Registry::invalidate();
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	// ── Activation redirect ────────────────────────────────────────────────

	/**
	 * A normal single-site activation sets the flag for the activating user.
	 */
	public function test_activation_sets_redirect_flag(): void {
		Activator::flag_activation_redirect( false );
		$this->assertSame( $this->admin_id, (int) get_transient( Activator::ACTIVATION_REDIRECT_TRANSIENT ) );
	}

	/**
	 * Network-wide and bulk activations never set the flag.
	 */
	public function test_network_and_bulk_activation_do_not_set_flag(): void {
		Activator::flag_activation_redirect( true );
		$this->assertFalse( get_transient( Activator::ACTIVATION_REDIRECT_TRANSIENT ) );

		$_REQUEST['action'] = 'activate-selected';
		Activator::flag_activation_redirect( false );
		$this->assertFalse( get_transient( Activator::ACTIVATION_REDIRECT_TRANSIENT ) );
	}

	/**
	 * An AJAX activation never sets the flag.
	 */
	public function test_ajax_activation_does_not_set_flag(): void {
		add_filter( 'wp_doing_ajax', '__return_true' );
		Activator::flag_activation_redirect( false );
		$this->assertFalse( get_transient( Activator::ACTIVATION_REDIRECT_TRANSIENT ) );
	}

	/**
	 * Admin + flag + setup not finished: redirect exactly once.
	 */
	public function test_redirects_once_when_onboarding_incomplete(): void {
		set_transient( Activator::ACTIVATION_REDIRECT_TRANSIENT, $this->admin_id, MINUTE_IN_SECONDS );

		$this->assertTrue( Activator::should_activation_redirect() );
		$this->assertFalse( Activator::should_activation_redirect(), 'The redirect must only ever happen once.' );
	}

	/**
	 * No flag, no redirect.
	 */
	public function test_no_redirect_without_flag(): void {
		$this->assertFalse( Activator::should_activation_redirect() );
	}

	/**
	 * Setup already finished (e.g. a reactivation): no redirect.
	 */
	public function test_no_redirect_when_onboarding_complete(): void {
		update_option( 'agent_builder_onboarding_complete', true );
		set_transient( Activator::ACTIVATION_REDIRECT_TRANSIENT, $this->admin_id, MINUTE_IN_SECONDS );

		$this->assertFalse( Activator::should_activation_redirect() );
	}

	/**
	 * A user without manage_options is never redirected, and the flag is spent.
	 */
	public function test_no_redirect_for_non_admin(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		set_transient( Activator::ACTIVATION_REDIRECT_TRANSIENT, $this->admin_id, MINUTE_IN_SECONDS );

		$this->assertFalse( Activator::should_activation_redirect() );
		$this->assertFalse( get_transient( Activator::ACTIVATION_REDIRECT_TRANSIENT ) );
	}

	/**
	 * Landing on plugins.php?activate-multi=true after a bulk activation: no redirect.
	 */
	public function test_no_redirect_after_bulk_activation(): void {
		$_GET['activate-multi'] = 'true';
		set_transient( Activator::ACTIVATION_REDIRECT_TRANSIENT, $this->admin_id, MINUTE_IN_SECONDS );

		$this->assertFalse( Activator::should_activation_redirect() );
	}

	/**
	 * AJAX requests never redirect and leave the flag for the next page view.
	 */
	public function test_ajax_request_keeps_flag_for_page_view(): void {
		set_transient( Activator::ACTIVATION_REDIRECT_TRANSIENT, $this->admin_id, MINUTE_IN_SECONDS );
		add_filter( 'wp_doing_ajax', '__return_true' );

		$this->assertFalse( Activator::should_activation_redirect() );
		$this->assertSame( $this->admin_id, (int) get_transient( Activator::ACTIVATION_REDIRECT_TRANSIENT ) );
	}

	// ── Plugins screen action links ────────────────────────────────────────

	/**
	 * Before setup: a "Finish setup" link to Quick Start; core links kept after it.
	 */
	public function test_action_links_before_setup(): void {
		Provider_Registry::save_api_key( 'agentic', '' );
		delete_option( 'agent_builder_license_key' );
		Provider_Registry::invalidate();

		$links = ( new Admin_Menu_Handler() )->plugin_action_links( array( 'deactivate' => '<a href="#">Deactivate</a>' ) );

		$this->assertArrayHasKey( 'finish_setup', $links );
		$this->assertStringContainsString( 'page=agentic-signup', $links['finish_setup'] );
		$this->assertArrayNotHasKey( 'settings', $links, 'Settings does not exist until a provider is connected.' );
		$this->assertSame( 'deactivate', array_key_last( $links ) );
	}

	/**
	 * After setup with a provider connected: "Settings" first, no "Finish setup".
	 */
	public function test_action_links_after_setup(): void {
		Provider_Registry::save_api_key( 'agentic', 'relay-key-abc123' );
		update_option( 'agent_builder_license_key', 'AGNT-TEST-KEY' );
		Provider_Registry::invalidate();
		update_option( 'agent_builder_onboarding_complete', true );

		$links = ( new Admin_Menu_Handler() )->plugin_action_links( array( 'deactivate' => '<a href="#">Deactivate</a>' ) );

		$this->assertSame( 'settings', array_key_first( $links ) );
		$this->assertStringContainsString( 'page=agentic-settings', $links['settings'] );
		$this->assertArrayNotHasKey( 'finish_setup', $links );
	}

	// ── Default agent ──────────────────────────────────────────────────────

	/**
	 * The WordPress Assistant wins whenever it is available; else the first agent.
	 */
	public function test_default_agent_slug(): void {
		$this->assertSame(
			'wordpress-assistant',
			Admin_Menu_Handler::default_agent_slug( array( 'agent-orchestrator', 'user-assistant', 'wordpress-assistant' ) )
		);
		$this->assertSame( 'agent-orchestrator', Admin_Menu_Handler::default_agent_slug( array( 'agent-orchestrator', 'user-assistant' ) ) );
		$this->assertSame( '', Admin_Menu_Handler::default_agent_slug( array() ) );
	}

	/**
	 * The Tasks composer is told which agent to preselect.
	 */
	public function test_tasks_localize_has_default_agent(): void {
		$method  = new \ReflectionMethod( Admin_Menu_Handler::class, 'tasks_page_localize' );
		$payload = $method->invoke( new Admin_Menu_Handler() );

		$this->assertArrayHasKey( 'defaultAgent', $payload );
		$this->assertSame(
			Admin_Menu_Handler::default_agent_slug( array_column( $payload['agents'], 'id' ) ),
			$payload['defaultAgent']
		);
	}

	// ── WooCommerce-only bundled agent ─────────────────────────────────────

	/**
	 * Storefront Assistant is only switched on by the seed when WooCommerce is active.
	 */
	public function test_storefront_assistant_needs_woocommerce(): void {
		$bundled = array( 'content-writer', 'storefront-assistant', 'wordpress-assistant' );

		$without = Activator::merge_bundled_active_agents( $bundled, array(), false );
		$this->assertNotContains( 'storefront-assistant', $without );
		$this->assertContains( 'content-writer', $without );
		$this->assertContains( 'wordpress-assistant', $without );

		$with = Activator::merge_bundled_active_agents( $bundled, array(), true );
		$this->assertContains( 'storefront-assistant', $with );
	}

	/**
	 * A site already using Storefront Assistant keeps it, WooCommerce or not.
	 */
	public function test_storefront_assistant_stays_active_for_existing_users(): void {
		$merged = Activator::merge_bundled_active_agents(
			array( 'content-writer', 'storefront-assistant' ),
			array( 'storefront-assistant' ),
			false
		);
		$this->assertContains( 'storefront-assistant', $merged );
		$this->assertSame( $merged, array_values( array_unique( $merged ) ) );
	}

	// ── Dashboard: Site Passport card ──────────────────────────────────────

	/**
	 * Call a private static Dashboard_REST helper.
	 *
	 * @param string $name Method name.
	 * @return array
	 */
	private function dashboard_call( string $name ): array {
		$method = new \ReflectionMethod( Dashboard_REST::class, $name );
		return $method->invoke( null );
	}

	/**
	 * Never scanned: the card says so instead of showing a computed grade,
	 * and the dashboard does not run a scan on its own.
	 */
	public function test_passport_summary_not_scanned_on_fresh_install(): void {
		delete_option( Agent_Ready_Score::OPTION );

		$summary = $this->dashboard_call( 'agent_ready_summary' );

		$this->assertFalse( $summary['scanned'] );
		$this->assertSame( '', $summary['grade'] );
		$this->assertSame( array(), Agent_Ready_Score::get_stored(), 'Loading the dashboard must not run a scan.' );
	}

	/**
	 * Once scanned, the real score is shown.
	 */
	public function test_passport_summary_shows_real_score_once_scanned(): void {
		$score = Agent_Ready_Score::rescan();

		$summary = $this->dashboard_call( 'agent_ready_summary' );

		$this->assertTrue( $summary['scanned'] );
		$this->assertSame( (int) $score['overall'], $summary['overall'] );
		$this->assertSame( (string) $score['grade'], $summary['grade'] );
	}

	/**
	 * A score taken before the bundled agents were activated ("No agents are
	 * active yet") is refreshed once agents become active, instead of
	 * contradicting the Agents screen forever.
	 */
	public function test_score_taken_before_agents_activated_is_refreshed(): void {
		update_option( 'agent_builder_active_agents', array() );
		update_option(
			Agent_Ready_Score::OPTION,
			array(
				'overall'    => 28,
				'grade'      => 'F',
				'categories' => array(
					'mcp_server_reachable' => array(
						'score'   => 0,
						'detail'  => 'No agents are active yet.',
						'fixable' => true,
					),
				),
				'checked_at' => '2026-10-14 00:00:00',
			),
			false
		);

		update_option( 'agent_builder_active_agents', array( 'wordpress-assistant', 'content-writer' ) );

		$this->assertNotEmpty( Agent_Ready_Score::get_stored()['stale'] ?? false, 'Changing active agents marks the stored score stale.' );

		$latest = Agent_Ready_Score::get_latest();
		$this->assertArrayNotHasKey( 'stale', $latest );
		$this->assertNotSame( 'No agents are active yet.', $latest['categories']['mcp_server_reachable']['detail'] );
	}

	/**
	 * The weekly cron refreshes an existing score but never creates the first one.
	 */
	public function test_scheduled_rescan_skips_never_scanned_site(): void {
		delete_option( Agent_Ready_Score::OPTION );
		Agent_Ready_Score::scheduled_rescan();
		$this->assertSame( array(), Agent_Ready_Score::get_stored() );
	}

	/**
	 * The dashboard's active-agent count follows the active-agents option.
	 */
	public function test_dashboard_active_agent_count(): void {
		$registry  = \Agentic_Agent_Registry::get_instance();
		$installed = array_keys( $registry->get_installed_agents( true ) );
		$this->assertContains( 'wordpress-assistant', $installed, 'precondition: bundled agents are installed.' );

		$active = array_values( array_intersect( array( 'wordpress-assistant', 'content-writer', 'seo-optimizer' ), $installed ) );
		update_option( 'agent_builder_active_agents', array_merge( $active, array( 'not-installed-agent' ) ) );

		$counts = $this->dashboard_call( 'agent_counts' );
		$this->assertSame( count( $active ), $counts['active'] );
	}

	// ── Site Brief copy ────────────────────────────────────────────────────

	/**
	 * User-facing Site Brief card text never names internal identifiers such
	 * as tool slugs (e.g. "manage_cache") or PHP constants.
	 */
	public function test_site_brief_copy_has_no_internal_identifiers(): void {
		$files = glob( AGENT_BUILDER_DIR . 'includes/site-brief/checkers/class-checker-*.php' );
		$this->assertNotEmpty( $files );

		foreach ( $files as $file ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local plugin source file.
			$source = (string) file_get_contents( $file );
			preg_match_all( "/(?:__|_n|esc_html__)\\(\\s*'((?:[^'\\\\]|\\\\.)*)'(?:\\s*,\\s*'((?:[^'\\\\]|\\\\.)*)')?/", $source, $m );
			$strings = array_filter( array_merge( $m[1], $m[2] ) );

			foreach ( $strings as $string ) {
				if ( 'agent-builder' === $string ) {
					continue;
				}
				$this->assertDoesNotMatchRegularExpression(
					'/\b[a-z0-9]+(?:_[a-z0-9]+)+\b|\b[A-Z]+(?:_[A-Z]+)+\b/',
					$string,
					basename( $file ) . ': user-facing text names an internal identifier: ' . $string
				);
			}
		}
	}
}
