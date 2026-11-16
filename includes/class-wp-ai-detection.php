<?php
/**
 * WP AI Detection — single source of truth for WordPress 7.0+ AI substrate features.
 *
 * Detects presence of the new WP AI Client, Abilities API (matured in 7.0),
 * Connectors, and MCP adapter at runtime. Used for adapter selection,
 * admin messaging ("bridge" experience on older WP), and graceful degradation.
 *
 * Never a hard dependency — everything continues to work on WP 6.4+ exactly
 * as before, with Agent Builder providing the full modern experience as the bridge.
 *
 * @package    Agent_Builder
 * @subpackage Includes
 * @author     Agent Builder Team <support@agentic-plugin.com>
 * @license    GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 * @link       https://agentic-plugin.com
 * @since      2.11.0
 *
 * php version 8.1
 */

declare( strict_types=1 );

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runtime feature detection for the WordPress AI substrate (WP 7.0+ "Armstrong").
 *
 * Provides:
 * - Fast, zero-side-effect checks (no network, no DB writes).
 * - Filterable for tests and future core changes.
 * - Human-readable feature matrix for admin surfaces and health cards.
 *
 * Usage:
 *   if ( WP_AI_Detection::has_ai_client() ) { ... }
 *   $matrix = WP_AI_Detection::get_feature_matrix();
 */
final class WP_AI_Detection {

	/**
	 * Minimum WP version that ships the full AI substrate (AI Client + matured Abilities + Connectors + MCP adapter).
	 */
	public const WP7_VERSION = '7.0';

	/**
	 * Cached results to avoid repeated function_exists / version checks.
	 *
	 * @var array<string, mixed>
	 */
	private static array $cache = array();

	/**
	 * Check if running on WordPress 7.0 or later (the release that ships the full AI substrate).
	 *
	 * @return bool
	 */
	public static function is_wp7_or_later(): bool {
		if ( isset( self::$cache['is_wp7_or_later'] ) ) {
			return (bool) self::$cache['is_wp7_or_later'];
		}

		global $wp_version;
		$is_wp7 = version_compare( $wp_version, self::WP7_VERSION, '>=' );

		/**
		 * Filter the WP7+ detection result.
		 *
		 * @param bool $is_wp7 The computed result.
		 */
		$is_wp7 = (bool) apply_filters( 'agentic_is_wp7_or_later', $is_wp7 );

		self::$cache['is_wp7_or_later'] = $is_wp7;
		return $is_wp7;
	}

	/**
	 * Check if the official WP AI Client is available (`wp_ai_client_prompt()` and friends).
	 *
	 * @return bool
	 */
	public static function has_ai_client(): bool {
		if ( isset( self::$cache['has_ai_client'] ) ) {
			return (bool) self::$cache['has_ai_client'];
		}

		$has = WP_Optional_API::has( 'wp_ai_client_prompt' );

		// Additional fast guard: the main builder class should exist when the function does.
		if ( $has && ! class_exists( 'WP_AI_Client_Prompt_Builder' ) && ! class_exists( '\\WP_AI_Client_Prompt_Builder' ) ) {
			// Core may expose only the function in some builds; still treat as present.
		}

		/** @var bool $has */
		$has = (bool) apply_filters( 'agentic_has_ai_client', $has );

		self::$cache['has_ai_client'] = $has;
		return $has;
	}

	/**
	 * Check if the (matured) Abilities API is present (`wp_register_ability`, `wp_get_abilities`).
	 * Note: Partial support landed in 6.9; full WP7 experience includes richer meta + MCP.
	 *
	 * @return bool
	 */
	public static function has_abilities_api(): bool {
		if ( isset( self::$cache['has_abilities_api'] ) ) {
			return (bool) self::$cache['has_abilities_api'];
		}

		$has = WP_Optional_API::has( 'wp_register_ability' ) && WP_Optional_API::has( 'wp_get_abilities' );

		/** @var bool $has */
		$has = (bool) apply_filters( 'agentic_has_abilities_api', $has );

		self::$cache['has_abilities_api'] = $has;
		return $has;
	}

	/**
	 * Check if the Connectors API / screen is available (central credential management).
	 * Looks for core indicators (function, screen slug, or option prefix used by official connectors).
	 *
	 * @return bool
	 */
	public static function has_connectors(): bool {
		if ( isset( self::$cache['has_connectors'] ) ) {
			return (bool) self::$cache['has_connectors'];
		}

		$has = WP_Optional_API::has( 'wp_get_connectors' )
			|| ( defined( 'WP_CONNECTORS_VERSION' ) && WP_CONNECTORS_VERSION )
			|| is_callable( array( 'WP_Connectors', 'get_instance' ) )
			// Fallback heuristic: the Settings page or REST namespace that core registers.
			|| ( function_exists( 'get_plugin_page_hook' ) && get_plugin_page_hook( 'options-connectors', 'options-general.php' ) );

		/** @var bool $has */
		$has = (bool) apply_filters( 'agentic_has_connectors', $has );

		self::$cache['has_connectors'] = $has;
		return $has;
	}

	/**
	 * Return the current WordPress version string (for messaging and matrix).
	 *
	 * @return string e.g. "6.9" or "7.0"
	 */
	public static function get_wp_version(): string {
		global $wp_version;
		return (string) ( $wp_version ?? 'unknown' );
	}

	/**
	 * Comprehensive feature matrix — ideal for admin health cards, notices, and the new WP AI Substrate UI.
	 *
	 * @return array<string, bool|string>
	 */
	public static function get_feature_matrix(): array {
		return array(
			'wp_version'      => self::get_wp_version(),
			'is_wp7_or_later' => self::is_wp7_or_later(),
			'ai_client'       => self::has_ai_client(),
			'abilities_api'   => self::has_abilities_api(),
			'connectors'      => self::has_connectors(),
			'mcp_adapter'     => self::has_mcp_adapter(),
			'bridge_active'   => ! self::is_wp7_or_later() || ! self::has_ai_client(), // AB is the bridge when native not fully present
		);
	}

	/**
	 * Whether the official MCP Adapter (or equivalent core MCP exposure) is active.
	 * The adapter turns Abilities into MCP tools/resources for external clients.
	 *
	 * @return bool
	 */
	public static function has_mcp_adapter(): bool {
		if ( isset( self::$cache['has_mcp_adapter'] ) ) {
			return (bool) self::$cache['has_mcp_adapter'];
		}

		// Common indicators from the official wordpress/mcp-adapter and core integration.
		$has = defined( 'MCP_ADAPTER_VERSION' )
			|| function_exists( 'mcp_adapter_init' )
			|| class_exists( 'MCP_Adapter' )
			|| ( defined( 'WP_MCP_ADAPTER_LOADED' ) && WP_MCP_ADAPTER_LOADED );

		/** @var bool $has */
		$has = (bool) apply_filters( 'agentic_has_mcp_adapter', $has );

		self::$cache['has_mcp_adapter'] = $has;
		return $has;
	}

	/**
	 * Human label for the current substrate mode (used in UI and agent prompts).
	 *
	 * @return string
	 */
	public static function get_mode_label(): string {
		if ( self::is_wp7_or_later() && self::has_ai_client() && self::has_connectors() ) {
			return __( 'Native WP 7.0+ AI Client + Connectors (with Agent Builder orchestration)', 'siteloom' );
		}

		if ( self::is_wp7_or_later() && self::has_abilities_api() ) {
			return __( 'WP 7.0+ Abilities + partial AI Client (Agent Builder bridge active)', 'siteloom' );
		}

		if ( self::has_abilities_api() ) {
			return __( 'WP 6.9+ Abilities API (Agent Builder full bridge for AI Client & orchestration)', 'siteloom' );
		}

		return __( 'Agent Builder bridge — full modern AI agent experience on this version of WordPress', 'siteloom' );
	}

	/**
	 * Clear the internal cache (primarily for tests or after core upgrades in the same request).
	 *
	 * @return void
	 */
	public static function clear_cache(): void {
		self::$cache = array();
	}
}
