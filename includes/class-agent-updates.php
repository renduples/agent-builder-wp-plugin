<?php
/**
 * Agent Updates
 *
 * Checks for newer versions of AI agents
 * and provides the one-click update installation logic
 *
 * @package    Agent_Builder
 * @subpackage Includes
 * @since      2.4.0
 */

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Agent_Updates class.
 */
class Agent_Updates {

	const TRANSIENT     = 'agentic_agent_updates';
	const TTL           = 12 * HOUR_IN_SECONDS;
	const OPT_IN_OPTION = 'agent_builder_agent_updates_optin';

	/**
	 * Marketplace URL for discovering / installing community agents.
	 *
	 * Free and WordPress.org builds no longer phone home for agent update
	 * checks. Users browse and install from the public marketplace instead.
	 */
	const MARKETPLACE_URL = 'https://agentic-plugin.com/community-agents/';

	/**
	 * Whether remote agent-update checks are available on this site.
	 *
	 * This build never calls the store for update checks (WordPress.org
	 * Guideline 7/8): there is no in-plugin install path for a downloaded agent
	 * package, and users are directed to the Community Agents marketplace
	 * (browse-only) instead.
	 */
	public static function is_remote_check_available(): bool {
		return false;
	}

	/**
	 * Whether the site administrator has opted in to agent update checks.
	 * Always false — this build never phones home for update checks.
	 */
	public static function is_opted_in(): bool {
		return false;
	}

	/**
	 * Whether the consent prompt still needs to be shown. Always false — this
	 * build never shows the consent wall, only the marketplace link.
	 */
	public static function needs_consent(): bool {
		return false;
	}

	/**
	 * Check for available agent updates. No-op — this build never phones home
	 * for update checks (see is_remote_check_available()).
	 *
	 * @param array $installed Map of slug => agent_data from get_installed_agents().
	 */
	public static function check( array $installed ): void {
		set_transient( self::TRANSIENT, array(), self::TTL );
	}

	/**
	 * Get cached update data.
	 *
	 * @return array<string, array{name: string, version: string, package: string, url: string}>
	 */
	public static function get(): array {
		$data = get_transient( self::TRANSIENT );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Return the number of available updates.
	 */
	public static function count(): int {
		return count( self::get() );
	}

	/**
	 * Bust the updates cache so the next page load triggers a fresh check.
	 */
	public static function bust(): void {
		delete_transient( self::TRANSIENT );
	}

	/**
	 * Admin_init handler: would process consent + trigger update checks on the
	 * Agents page. No-op in this build — see is_remote_check_available().
	 *
	 * @return void
	 */
	public static function maybe_check_on_agents_page(): void {
		// This build never phones home for agent update checks.
	}

}
