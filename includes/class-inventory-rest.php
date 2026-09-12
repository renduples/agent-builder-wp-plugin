<?php
/**
 * Inventory REST — a single, machine-readable document listing every active
 * agent, its declared tools and their effective risk tier, and who built it.
 *
 * Closes the gap the per-agent MCP tools/list endpoints (class-relay-connect.php)
 * leave open: each of those is real and machine-readable, but scoped to one
 * agent and gated behind that agent's own Application Password, so there is
 * no single place a site owner, an auditor, or an automated compliance
 * scanner can ask "what agents does this site run, what can each one do, and
 * how risky is each of those actions" without authenticating N separate
 * times and having no way to discover N in the first place.
 *
 * Deliberately its own authenticated admin endpoint rather than a public
 * unauthenticated /.well-known/ file (contrast Webmcp_Bridge's
 * /.well-known/webmcp.json, which is intentionally public but only ever
 * lists the narrow, pre-vetted subset of tools an anonymous visitor may
 * call): a full risk-tiered map of every tool every agent can use is real
 * reconnaissance value to an attacker, so it stays behind the same
 * capability check as the rest of Settings, reachable by an external
 * auditor the same way the MCP relay already is — an Application Password.
 *
 * @package    Agent_Builder
 * @subpackage Includes
 * @since      3.3.96
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and handles the agentic/v1/inventory route.
 */
class Inventory_REST {

	/**
	 * Hook route registration.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register agentic/v1/inventory (GET) and agentic/v1/inventory/integrity
	 * (GET) — the audit-log chain check, surfaced here rather than as its own
	 * top-level route since both exist to answer the same question ("can I
	 * trust what this site says its agents did").
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			'agentic/v1',
			'/inventory',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_inventory' ),
				'permission_callback' => array( __CLASS__, 'can_view' ),
			)
		);

		register_rest_route(
			'agentic/v1',
			'/inventory/integrity',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_integrity' ),
				'permission_callback' => array( __CLASS__, 'can_view' ),
			)
		);
	}

	/**
	 * Same capability as the rest of Settings — this is admin-facing
	 * diagnostic data, not a public discovery document. Matches
	 * Admin_Settings_REST::can_manage() exactly (kept as its own method
	 * rather than a cross-class call so this route's access rule doesn't
	 * silently change if that one's ever narrowed for unrelated reasons).
	 *
	 * @return bool
	 */
	public static function can_view(): bool {
		return current_user_can( 'agentic_manage_settings' ) || current_user_can( 'manage_options' );
	}

	/**
	 * GET agentic/v1/inventory
	 *
	 * @return \WP_REST_Response
	 */
	public static function get_inventory(): \WP_REST_Response {
		$agents = array();

		if ( class_exists( '\\Agentic_Agent_Registry' ) ) {
			foreach ( \Agentic_Agent_Registry::get_instance()->get_installed_agents() as $slug => $agent ) {
				if ( empty( $agent['active'] ) ) {
					continue;
				}
				$agents[] = self::describe_agent( (string) $slug, $agent );
			}
		}

		return rest_ensure_response(
			array(
				'site_url'     => home_url( '/' ),
				'generated_at' => gmdate( 'c' ),
				'agents'       => $agents,
			)
		);
	}

	/**
	 * Build one agent's inventory entry: identity, MCP exposure, and every
	 * declared tool with its currently effective risk tier.
	 *
	 * @param string $slug  Agent slug.
	 * @param array  $agent Agent data from Agentic_Agent_Registry::get_installed_agents().
	 * @return array
	 */
	private static function describe_agent( string $slug, array $agent ): array {
		return array(
			'slug'        => $slug,
			'name'        => (string) ( $agent['name'] ?? $slug ),
			'version'     => (string) ( $agent['version'] ?? '' ),
			'author'      => (string) ( $agent['author'] ?? '' ),
			'author_uri'  => (string) ( $agent['author_uri'] ?? '' ),
			'mcp_enabled' => class_exists( '\\Agentic_Relay_Connect' ) && \Agentic_Relay_Connect::is_mcp_enabled( $slug ),
			'tools'       => self::describe_tools( $slug ),
		);
	}

	/**
	 * Public wrapper for describe_tools() so other admin surfaces (Chat
	 * Playground) can reuse the same per-agent tool list the inventory
	 * endpoint already exposes, instead of assembling a second copy.
	 *
	 * @param string $agent_slug Agent slug.
	 * @return array<int, array{name:string, risk:string}>
	 */
	public static function get_agent_tools( string $agent_slug ): array {
		return self::describe_tools( $agent_slug );
	}

	/**
	 * Every tool an agent's manifest declares (abilities.json's own
	 * `abilities` map, plus any wp_abilities entries), each with its
	 * effective risk tier — the same value Tool_Executor::execute() actually
	 * enforces, via Abilities_Manifest::get_effective_risk(), so this can
	 * never drift from what the site really does at call time.
	 *
	 * @param string $agent_slug Agent slug.
	 * @return array<int, array{name:string, risk:string}>
	 */
	private static function describe_tools( string $agent_slug ): array {
		$manifest = Abilities_Manifest::load( $agent_slug );
		if ( ! $manifest ) {
			return array();
		}

		$tools = array();
		$seen  = array();

		if ( ! empty( $manifest['abilities'] ) && is_array( $manifest['abilities'] ) ) {
			foreach ( $manifest['abilities'] as $tool_name => $entry ) {
				$tool_name = (string) $tool_name;
				if ( ! Tools_Registry::is_enabled( $tool_name ) || isset( $seen[ $tool_name ] ) ) {
					continue;
				}
				$seen[ $tool_name ] = true;
				$tools[]             = array(
					'name' => $tool_name,
					'risk' => Abilities_Manifest::get_effective_risk( $agent_slug, $tool_name ),
				);
			}
		}

		if ( ! empty( $manifest['wp_abilities'] ) && is_array( $manifest['wp_abilities'] ) ) {
			foreach ( $manifest['wp_abilities'] as $entry ) {
				$tool_name = (string) ( $entry['name'] ?? '' );
				if ( '' === $tool_name || ! Tools_Registry::is_enabled( $tool_name ) || isset( $seen[ $tool_name ] ) ) {
					continue;
				}
				$seen[ $tool_name ] = true;
				$tools[]             = array(
					'name' => $tool_name,
					'risk' => Abilities_Manifest::get_effective_risk( $agent_slug, $tool_name ),
				);
			}
		}

		return $tools;
	}

	/**
	 * GET agentic/v1/inventory/integrity — walks the full audit-log hash
	 * chain (Audit_Log_Integrity) and reports whether it's intact.
	 *
	 * @return \WP_REST_Response
	 */
	public static function get_integrity(): \WP_REST_Response {
		return rest_ensure_response( Audit_Log_Integrity::verify_chain() );
	}
}
