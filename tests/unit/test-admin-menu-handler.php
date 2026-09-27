<?php
/**
 * Unit Tests for Admin_Menu_Handler::known_admin_pages().
 *
 * Covers the page-slug catalogue that maybe_show_access_notice() uses to tell
 * a real-but-hidden page apart from a mistyped `page=agentic-*` slug. When no
 * provider is configured, register() bails to the Quick Start funnel, so these
 * are the only slugs an administrator can ever be denied on for a
 * "no provider" reason — anything else is a not-found.
 *
 * @package Agentic\Tests
 */

namespace Agentic\Tests;

use Agentic\Admin_Menu_Handler;
use Agentic\Provider_Registry;

/**
 * Test case for Admin_Menu_Handler page-slug detection.
 */
class Test_Admin_Menu_Handler extends TestCase {

	/**
	 * Every page register() adds in the fully-configured branch is "known".
	 */
	public function test_known_pages_are_recognised(): void {
		$known = array(
			'agent-builder',
			'agentic-signup',
			'agentic-setup',
			'agentic-chat',
			'agentic-agents',
			'agentic-deployment',
			'agentic-run-task',
			'agentic-agent-wizard',
			'agentic-knowledge-wizard',
			'agentic-deploy-wizard',
			'agentic-train-data',
			'agentic-tools',
			'agentic-skills',
			'agentic-approvals',
			'agentic-safety-center',
			'agentic-agent-ready',
			'agentic-audit-log',
			'agentic-settings',
		);

		foreach ( $known as $slug ) {
			$this->assertTrue(
				Admin_Menu_Handler::is_known_admin_page( $slug ),
				"Expected {$slug} to be a known Agent Builder page."
			);
		}
	}

	/**
	 * A mistyped or removed `agentic-*` slug is not a known page.
	 */
	public function test_unknown_slugs_are_not_recognised(): void {
		foreach ( array( 'agentic-foo', 'agentic-bogus', 'agentic-', 'agentic-costs' ) as $slug ) {
			$this->assertFalse(
				Admin_Menu_Handler::is_known_admin_page( $slug ),
				"Expected {$slug} to be treated as unknown."
			);
		}
	}

	/**
	 * known_admin_pages() is filterable (Pro can register its own screens).
	 *
	 * The callback is kept in a variable so it can be removed by identity,
	 * rather than remove_all_filters() tearing down every other callback on
	 * the shared hook.
	 */
	public function test_known_pages_list_is_filterable(): void {
		$add_pro_page = static function ( array $pages ): array {
			$pages[] = 'agentic-pro-extra';
			return $pages;
		};
		add_filter( 'agentic_known_admin_pages', $add_pro_page );

		$this->assertTrue( Admin_Menu_Handler::is_known_admin_page( 'agentic-pro-extra' ) );

		remove_filter( 'agentic_known_admin_pages', $add_pro_page );
		$this->assertFalse( Admin_Menu_Handler::is_known_admin_page( 'agentic-pro-extra' ) );
	}

	/**
	 * A mistyped (or removed) `agentic-*` slug renders the not-found notice (404)
	 * for everyone — including a full administrator — never a permissions message.
	 */
	public function test_access_notice_unknown_slug_is_not_found(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$_GET['page'] = 'agentic-does-not-exist';

		$handler = new Admin_Menu_Handler();

		try {
			$handler->maybe_show_access_notice();
			$this->fail( 'Expected maybe_show_access_notice() to wp_die() on an unknown slug.' );
		} catch ( \WPDieException $e ) {
			$this->assertSame( 404, $e->getCode() );
			$this->assertStringContainsString( "This Agent Builder page doesn", $e->getMessage() );
			$this->assertStringContainsString( 'page=agent-builder', $e->getMessage() );
		}
	}

	/**
	 * An administrator denied only because no provider is configured is shown the
	 * "connect a provider" notice (403) with a primary link to Quick Start — not
	 * the misleading "Administrator access required" screen.
	 */
	public function test_access_notice_admin_without_provider_gets_connect_notice(): void {
		$this->force_no_provider();

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$_GET['page'] = 'agentic-settings';

		$handler = new Admin_Menu_Handler();

		try {
			$handler->maybe_show_access_notice();
			$this->fail( 'Expected maybe_show_access_notice() to wp_die() with the connect-provider notice.' );
		} catch ( \WPDieException $e ) {
			$this->assertSame( 403, $e->getCode() );
			$this->assertStringContainsString( 'Connect an AI provider to unlock this screen', $e->getMessage() );
			$this->assertStringContainsString( 'Go to Quick Start', $e->getMessage() );
			$this->assertStringContainsString( 'page=agentic-signup', $e->getMessage() );
		}
	}

	/**
	 * A non-admin denied on a real page still gets the existing access-denied
	 * screen (403), regardless of provider state.
	 */
	public function test_access_notice_non_admin_still_gets_denial(): void {
		$this->force_no_provider();

		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );
		$_GET['page'] = 'agentic-settings';

		$handler = new Admin_Menu_Handler();

		try {
			$handler->maybe_show_access_notice();
			$this->fail( 'Expected maybe_show_access_notice() to wp_die() with the access-denied notice.' );
		} catch ( \WPDieException $e ) {
			$this->assertSame( 403, $e->getCode() );
			$this->assertStringContainsString( 'Administrator access required', $e->getMessage() );
		}
	}

	/**
	 * The emergency stop disconnects providers, but a stopped site is still
	 * *configured*: has_usable_provider() must read false (nothing can run) while
	 * has_configured_provider() reads true, so any_llm_configured() keeps the full
	 * menu registered and the administrator can reach Interface Settings to
	 * disable the stop instead of being funnelled to Quick Start.
	 *
	 * The stop's own snapshot records the provider that existed before the stop,
	 * which is what has_configured_provider() reads while the stop is active.
	 */
	public function test_emergency_stop_is_distinct_from_no_provider(): void {
		// Configure a usable hosted provider first.
		Provider_Registry::save_api_key( 'agentic', 'relay-key-abc123' );
		Provider_Registry::invalidate();
		update_option( 'agent_builder_license_key', 'AGNT-TEST-KEY' );

		$this->assertTrue(
			Provider_Registry::has_usable_provider(),
			'precondition: a configured provider is usable before the stop.'
		);

		// Flip the emergency stop on with the snapshot enable() would have
		// persisted — the provider captured with its key.
		update_option(
			'agent_builder_disable_all_agents_snapshot',
			array(
				'providers'    => array(
					'agentic' => array(
						'slug'          => 'agentic',
						'name'          => 'Agentic',
						'had_key'       => true,
						'encrypted_key' => 'enc:relay-key-abc123',
						'default_model' => '',
						'auth_type'     => 'bearer',
					),
				),
				'ollama_url'   => '',
			)
		);
		update_option( 'agent_builder_disable_all_agents', '1' );

		$this->assertFalse(
			Provider_Registry::has_usable_provider(),
			'The stop disconnects providers, so nothing is usable while it is active.'
		);
		$this->assertTrue(
			Provider_Registry::has_configured_provider(),
			'A stopped site with a provider in its snapshot is still configured and must keep its full admin menu.'
		);
	}

	/**
	 * A site that was never configured is still unconfigured even while the
	 * emergency stop is active: the stop's snapshot has no provider keys, so
	 * has_configured_provider() reads false and the administrator is funnelled
	 * to Quick Start (the "connect a provider" notice) instead of being shown a
	 * full menu with nothing to reach.
	 */
	public function test_emergency_stop_with_empty_snapshot_is_unconfigured(): void {
		// No provider key anywhere, mirroring a fresh install.
		Provider_Registry::save_api_key( 'agentic', '' );
		Provider_Registry::invalidate();
		delete_option( 'agent_builder_license_key' );
		delete_option( 'agent_builder_ollama_url' );

		// The stop is active, but its snapshot shows nothing was ever configured.
		update_option(
			'agent_builder_disable_all_agents_snapshot',
			array(
				'providers'  => array(
					'agentic' => array(
						'slug'          => 'agentic',
						'name'          => 'Agentic',
						'had_key'       => false,
						'encrypted_key' => '',
						'default_model' => '',
						'auth_type'     => 'bearer',
					),
				),
				'ollama_url' => '',
			)
		);
		update_option( 'agent_builder_disable_all_agents', '1' );

		$this->assertFalse(
			Provider_Registry::has_usable_provider(),
			'Nothing is usable while the stop is active.'
		);
		$this->assertFalse(
			Provider_Registry::has_configured_provider(),
			'A stopped site whose snapshot shows no provider was configured is unconfigured.'
		);

		// An administrator landing on a hidden page is routed to Quick Start, not
		// shown the misleading "Administrator access required" screen.
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$_GET['page'] = 'agentic-settings';

		$handler = new Admin_Menu_Handler();

		try {
			$handler->maybe_show_access_notice();
			$this->fail( 'Expected maybe_show_access_notice() to wp_die() with the connect-provider notice.' );
		} catch ( \WPDieException $e ) {
			$this->assertSame( 403, $e->getCode() );
			$this->assertStringContainsString( 'Connect an AI provider to unlock this screen', $e->getMessage() );
			$this->assertStringContainsString( 'Go to Quick Start', $e->getMessage() );
		}
	}

	/**
	 * Reset per-test globals so one denial test can't leak into the next.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		unset( $_GET['page'] );
		Provider_Registry::save_api_key( 'agentic', '' );
		Provider_Registry::invalidate();
		delete_option( 'agent_builder_license_key' );
		delete_option( 'agent_builder_ollama_url' );
		delete_option( 'agent_builder_disable_all_agents' );
		delete_option( 'agent_builder_disable_all_agents_snapshot' );

		parent::tearDown();
	}

	/**
	 * Force has_usable_provider() to report false by clearing every configured
	 * credential, mirroring the no-provider funnel state register() bails on.
	 */
	private function force_no_provider(): void {
		delete_option( 'agent_builder_license_key' );
		delete_option( 'agent_builder_ollama_url' );
		Provider_Registry::save_api_key( 'agentic', '' );
		Provider_Registry::invalidate();
	}
}
