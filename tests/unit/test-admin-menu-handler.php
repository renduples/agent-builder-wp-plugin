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
	 */
	public function test_known_pages_list_is_filterable(): void {
		add_filter(
			'agentic_known_admin_pages',
			static function ( array $pages ): array {
				$pages[] = 'agentic-pro-extra';
				return $pages;
			}
		);

		$this->assertTrue( Admin_Menu_Handler::is_known_admin_page( 'agentic-pro-extra' ) );

		remove_all_filters( 'agentic_known_admin_pages' );
		$this->assertFalse( Admin_Menu_Handler::is_known_admin_page( 'agentic-pro-extra' ) );
	}
}
