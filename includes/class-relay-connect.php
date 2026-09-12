<?php
/**
 * Agent Builder — Relay Connect
 *
 * Two responsibilities:
 *   1. GET /wp-json/agentic/relay/ping  (public) — signals Agent Builder is installed
 *   2. ?agentic_relay_connect=1         (page)   — approval screen for the relay site-connect flow
 *
 * The relay probes (1) to detect this plugin.
 * On detection it redirects the user's browser to (2) with a signed relay_state token.
 * The user approves, we generate an Application Password and POST to the relay callback.
 *
 * @package Agentic
 */

defined( 'ABSPATH' ) || exit;

use Agentic\WP_Optional_API;
use Agentic\Tool_Loader;
use Agentic\Tools_Registry;
use Agentic\Risk_Level;
use Agentic\Abilities_Manifest;
use Agentic\Tool_Base;

/**
 * Connects this site to the Agentic MCP relay (mcp.agentic-plugin.com).
 *
 * Lives in the global namespace so it can be loaded before the autoloader.
 */
class Agentic_Relay_Connect {

	const RELAY_BASE        = 'https://mcp.agentic-plugin.com';
	const VERIFY_STATE_URL  = 'https://mcp.agentic-plugin.com/api/verify-state';
	const ALLOWED_CALLBACK  = 'https://mcp.agentic-plugin.com/oauth2/relay-callback';
	const APP_PASS_NAME     = 'Agent Builder Relay';
	const CONNECTORS_OPTION = 'agentic_active_connectors';

	/**
	 * Per-agent map of { slug => unix timestamp } for the last time an
	 * authenticated MCP client successfully reached that agent's endpoint —
	 * distinct from "ready" (mcp_readiness(), which only means the agent is
	 * capable of responding, not that anything has ever actually connected).
	 */
	const LAST_CONNECTED_OPTION = 'agentic_mcp_last_connected';

	/**
	 * Slugs of agents an admin has explicitly turned MCP *on* for — MCP is
	 * opt-in per agent, off by default (empty/missing option = no agent's
	 * MCP endpoint responds until someone turns it on in Settings > MCP),
	 * independent of the agent's own active/inactive state — an agent can
	 * be active for chat with its MCP endpoint still switched off.
	 */
	const ENABLED_AGENTS_OPTION = 'agentic_mcp_enabled_agents';

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_ping' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_mcp' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_handle_connect' ), 1 );
	}

	// ---------- 1. Ping endpoint ----------

	/**
	 * Register the public detection ping route.
	 */
	public static function register_ping(): void {
		register_rest_route(
			'agentic/relay',
			'/ping',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'handle_ping' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Answer the relay's detection probe.
	 *
	 * Deliberately minimal: the route's existence (agentic/relay/ping) is
	 * itself the detection signal the relay needs, so the body carries only
	 * the boolean it actually reads — no plugin version, no other
	 * fingerprintable detail an unauthenticated caller shouldn't get for
	 * free. Nothing in this codebase's relay-connect flow reads a version
	 * back from this endpoint; if the relay ever needs one, it can request
	 * it through the authenticated connect flow instead.
	 *
	 * @return WP_REST_Response
	 */
	public static function handle_ping(): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'relay_ready' => true,
			),
			200
		);
	}

	// ---------- 3. MCP JSON-RPC endpoint ----------

	/**
	 * Register the per-agent MCP JSON-RPC route.
	 */
	public static function register_mcp(): void {
		register_rest_route(
			'agentic',
			'/(?P<slug>[a-z0-9_-]+)/mcp',
			array(
				'methods'             => array( 'POST', 'GET', 'DELETE' ),
				'callback'            => array( __CLASS__, 'handle_mcp' ),
				'permission_callback' => array( __CLASS__, 'check_mcp_permission' ),
			)
		);
	}

	/**
	 * MCP route permission: any authenticated WordPress user who is at least
	 * a real staff member (edit_posts) — the same floor Webmcp_Bridge uses
	 * for its lowest-privilege (readonly) tools. Per-agent scoping
	 * (agent_is_declared()) and per-tool risk filtering (is_tool_mcp_safe(),
	 * required_capability_for_tool()) are what actually bound what a given
	 * credential can list or call; this floor only keeps any-account-at-all
	 * (e.g. a subscriber with no site role) from enumerating an agent's tool
	 * catalog and schemas via initialize/tools/list.
	 *
	 * @return bool|\WP_Error
	 */
	public static function check_mcp_permission(): bool|\WP_Error {
		if ( ! is_user_logged_in() || ! current_user_can( 'edit_posts' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'Authentication required.', 'agent-builder' ),
				array( 'status' => 401 )
			);
		}

		return true;
	}

	/**
	 * Handle an MCP JSON-RPC request.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response
	 */
	public static function handle_mcp( \WP_REST_Request $request ): \WP_REST_Response {
		// DELETE = session termination (MCP spec) — acknowledge and exit.
		if ( 'DELETE' === $request->get_method() ) {
			return new \WP_REST_Response( null, 200 );
		}

		$slug   = sanitize_key( (string) $request->get_param( 'slug' ) );
		$body   = $request->get_json_params();
		$body   = is_array( $body ) ? $body : array();
		$method = $body['method'] ?? '';
		$id     = $body['id'] ?? null;

		// check_mcp_permission() already required a real authenticated user
		// to reach this point — any recognized method against a real slug is
		// evidence of an actual connected client, not just a "ready" endpoint.
		self::record_connection( $slug );

		switch ( $method ) {
			case 'initialize':
				return new \WP_REST_Response(
					self::mcp_result(
						$id,
						array(
							'protocolVersion' => '2024-11-05',
							'capabilities'    => array( 'tools' => new \stdClass() ),
							'serverInfo'      => array(
								'name'    => 'Agent Builder MCP — ' . $slug,
								'version' => defined( 'AGENT_BUILDER_VERSION' ) ? AGENT_BUILDER_VERSION : '1.0.0',
							),
						)
					),
					200
				);

			case 'notifications/initialized':
				return new \WP_REST_Response( self::mcp_result( $id, null ), 200 );

			case 'tools/list':
				return new \WP_REST_Response(
					self::mcp_result( $id, array( 'tools' => self::get_mcp_tools( $slug ) ) ),
					200
				);

			case 'tools/call':
				return new \WP_REST_Response(
					self::handle_tool_call( $id, $body['params'] ?? array(), $slug ),
					200
				);

			default:
				return new \WP_REST_Response(
					self::mcp_error( $id, -32601, "Method not found: $method" ),
					200
				);
		}
	}

	/**
	 * Build the MCP tools list for one agent's endpoint.
	 *
	 * Prefers WordPress core's native Abilities API (6.9+). On older sites
	 * where that doesn't exist yet, falls back to Agent Builder's own tool
	 * registry directly — same tools, same MCP transport, no dependency on
	 * a WordPress version this plugin's own "Requires at least: 6.4" predates.
	 *
	 * Every tool is also scoped to what $slug's own abilities.json actually
	 * declares — the route is per-agent (/{slug}/mcp), so an agent's MCP
	 * endpoint should only ever expose what that agent itself is allowed to
	 * touch, the same fail-closed rule chat already enforces.
	 *
	 * @param string $slug Agent slug from the route.
	 * @return array<int,array<string,mixed>>
	 */
	private static function get_mcp_tools( string $slug ): array {
		if ( ! self::agent_mcp_ready( $slug ) ) {
			return array();
		}
		if ( WP_Optional_API::has( 'wp_get_abilities' ) ) {
			return self::get_mcp_tools_from_abilities( $slug );
		}
		return self::get_mcp_tools_from_registry( $slug );
	}

	/**
	 * Build the MCP tools list from registered WordPress abilities (6.9+).
	 *
	 * @param string $slug Agent slug, already verified active with a valid manifest.
	 * @return array<int,array<string,mixed>>
	 */
	private static function get_mcp_tools_from_abilities( string $slug ): array {
		$tools = array();
		foreach ( WP_Optional_API::get_abilities() as $ability ) {
			$name = $ability->get_name();

			// Skip mcp-adapter meta-tools — they are not useful direct tools for the relay.
			if ( str_starts_with( $name, 'mcp-adapter/' ) ) {
				continue;
			}

			// Agent Builder's own abilities (agent-builder/*) go through the
			// same MCP-safety posture as the registry fallback below, so a
			// high-risk tool isn't advertised via MCP just because the site
			// happens to be on 6.9+ — abilities from OTHER plugins are left
			// alone; this plugin has no risk model for those.
			$own_tool_name = self::own_ability_to_tool_name( $name );
			if ( null !== $own_tool_name ) {
				if ( ! Tools_Registry::is_enabled( $own_tool_name ) || ! self::is_tool_mcp_safe( $own_tool_name, $slug ) ) {
					continue;
				}
			}

			// Scope to this agent's own declared tools — own abilities are
			// matched by tool name, third-party abilities by their raw name
			// (Abilities_Manifest::is_declared() checks wp_abilities entries
			// by exact ability name).
			if ( ! self::agent_is_declared( $slug, $own_tool_name ?? $name ) ) {
				continue;
			}

			$schema = $ability->get_input_schema();
			if ( empty( $schema ) || ! is_array( $schema ) ) {
				$schema = array(
					'type'       => 'object',
					'properties' => new \stdClass(),
				);
			}

			$label       = $ability->get_label();
			$label       = ( is_string( $label ) && '' !== $label ) ? $label : $name;
			$description = $ability->get_description();
			$description = ( is_string( $description ) && '' !== $description ) ? $description : $label;

			$tools[] = array(
				'name'        => self::ability_to_mcp_name( $name ),
				'description' => $description,
				'inputSchema' => $schema,
				'annotations' => array( 'title' => $label ),
			);
		}

		return $tools;
	}

	/**
	 * Build the MCP tools list directly from Agent Builder's own tool
	 * registry — the pre-6.9 fallback, since core has no Abilities API to
	 * read from yet. Same enabled/safety posture Abilities_Bridge applies
	 * when it registers these same tools as native abilities on newer sites.
	 *
	 * @param string $slug Agent slug, already verified active with a valid manifest.
	 * @return array<int,array<string,mixed>>
	 */
	private static function get_mcp_tools_from_registry( string $slug ): array {
		$tools = array();
		foreach ( Tool_Loader::get_instance()->get_all_definitions() as $definition ) {
			$name = $definition['function']['name'] ?? '';
			if ( '' === $name || ! Tools_Registry::is_enabled( $name ) || ! self::is_tool_mcp_safe( $name, $slug, Tool_Loader::get_instance()->get( $name ) ) ) {
				continue;
			}
			if ( ! self::agent_is_declared( $slug, $name ) ) {
				continue;
			}

			$schema = $definition['function']['parameters'] ?? array();
			if ( empty( $schema ) || ! is_array( $schema ) ) {
				$schema = array(
					'type'       => 'object',
					'properties' => new \stdClass(),
				);
			}

			$tools[] = array(
				'name'        => $name,
				'description' => (string) ( $definition['function']['description'] ?? '' ),
				'inputSchema' => $schema,
				'annotations' => array( 'title' => self::tool_name_to_label( $name ) ),
			);
		}

		return $tools;
	}

	/**
	 * Whether $slug is a real, active agent with a manifest that passes
	 * integrity verification — the same fail-closed gate
	 * Agent_Controller::get_tools_for_agent() applies for chat, applied
	 * here so an agent's MCP endpoint can't expose more than chat would.
	 * An unknown/inactive agent, or one with a tampered abilities.json,
	 * gets zero tools rather than an error — the endpoint still responds,
	 * it just has nothing to offer.
	 *
	 * @param string $slug Agent slug from the route.
	 * @return bool
	 */
	private static function agent_mcp_ready( string $slug ): bool {
		return self::mcp_readiness( $slug )['ready'];
	}

	/**
	 * Same fail-closed check as agent_mcp_ready(), but with the reason a
	 * caller can show a human — used by the Settings > MCP tab to explain
	 * why a given agent isn't exposing any tools yet.
	 *
	 * @param string $slug Agent slug.
	 * @return array{ready: bool, reason: string|null}
	 */
	public static function mcp_readiness( string $slug ): array {
		if ( '' === $slug || ! class_exists( '\\Agentic_Agent_Registry' ) ) {
			return array(
				'ready'  => false,
				'reason' => __( 'Unknown agent.', 'agent-builder' ),
			);
		}
		if ( ! \Agentic_Agent_Registry::get_instance()->get_agent_instance( $slug ) ) {
			return array(
				'ready'  => false,
				'reason' => __( 'Agent is not active.', 'agent-builder' ),
			);
		}
		if ( ! class_exists( '\\Agentic\\Abilities_Manifest' ) ) {
			return array(
				'ready'  => false,
				'reason' => __( 'Abilities manifest system unavailable.', 'agent-builder' ),
			);
		}
		if ( ! \Agentic\Abilities_Manifest::load( $slug ) ) {
			return array(
				'ready'  => false,
				'reason' => __( 'No abilities.json manifest for this agent.', 'agent-builder' ),
			);
		}
		if ( ! \Agentic\Abilities_Manifest::verify_integrity( $slug ) ) {
			\Agentic\Security_Log::log_system(
				'integrity_failure',
				$slug,
				array( 'reason' => 'Manifest signature mismatch — MCP tools blocked for this agent.' )
			);
			return array(
				'ready'  => false,
				'reason' => __( 'Manifest signature mismatch — this agent\'s tools are blocked until it is re-signed.', 'agent-builder' ),
			);
		}
		if ( ! self::is_mcp_enabled( $slug ) ) {
			return array(
				'ready'  => false,
				'reason' => __( 'MCP is off by default for this agent — enable it in Settings > MCP.', 'agent-builder' ),
			);
		}
		return array(
			'ready'  => true,
			'reason' => null,
		);
	}

	/**
	 * Whether an admin has explicitly turned MCP on for this agent. Off by
	 * default — an agent's MCP endpoint doesn't respond until someone
	 * enables it here, independent of whether the agent itself is active.
	 *
	 * @param string $slug Agent slug.
	 * @return bool
	 */
	public static function is_mcp_enabled( string $slug ): bool {
		$enabled_agents = get_option( self::ENABLED_AGENTS_OPTION, array() );
		$enabled_agents = is_array( $enabled_agents ) ? $enabled_agents : array();

		return in_array( $slug, $enabled_agents, true );
	}

	/**
	 * Turn an agent's MCP endpoint on or off. Logged — this is a real access
	 * control change, the same class of event as a manifest re-sign or a
	 * connector approval/revocation.
	 *
	 * @param string $slug    Agent slug.
	 * @param bool   $enabled True to enable, false to disable.
	 * @return void
	 */
	public static function set_mcp_enabled( string $slug, bool $enabled ): void {
		$enabled_agents = get_option( self::ENABLED_AGENTS_OPTION, array() );
		$enabled_agents = is_array( $enabled_agents ) ? $enabled_agents : array();

		$was_enabled = in_array( $slug, $enabled_agents, true );
		$is_a_change = $enabled !== $was_enabled;

		if ( $enabled ) {
			$enabled_agents[] = $slug;
		} else {
			$enabled_agents = array_values( array_diff( $enabled_agents, array( $slug ) ) );
		}

		update_option( self::ENABLED_AGENTS_OPTION, array_values( array_unique( $enabled_agents ) ) );

		if ( $is_a_change && class_exists( '\\Agentic\\Security_Log' ) ) {
			\Agentic\Security_Log::log_system(
				$enabled ? 'mcp_agent_enabled' : 'mcp_agent_disabled',
				$slug,
				array( 'agent' => $slug )
			);
		}
	}

	/**
	 * Record that an authenticated MCP client successfully reached this
	 * agent's endpoint, so Settings > MCP can show real connection activity
	 * per agent rather than just "ready" (capable of responding, whether or
	 * not anything has ever actually connected). Throttled to at most once a
	 * minute per agent so an active client polling tools/list doesn't write
	 * to the options table on every request.
	 *
	 * @param string $slug Agent slug.
	 * @return void
	 */
	private static function record_connection( string $slug ): void {
		if ( '' === $slug ) {
			return;
		}

		$last = get_option( self::LAST_CONNECTED_OPTION, array() );
		$last = is_array( $last ) ? $last : array();

		$now = time();
		if ( isset( $last[ $slug ] ) && ( $now - (int) $last[ $slug ] ) < MINUTE_IN_SECONDS ) {
			return;
		}

		$last[ $slug ] = $now;
		update_option( self::LAST_CONNECTED_OPTION, $last, false );
	}

	/**
	 * Last time an authenticated MCP client reached this agent's endpoint,
	 * or null if never (or not since this tracking was added).
	 *
	 * @param string $slug Agent slug.
	 * @return int|null Unix timestamp, or null.
	 */
	public static function get_last_connected( string $slug ): ?int {
		$last = get_option( self::LAST_CONNECTED_OPTION, array() );
		$last = is_array( $last ) ? $last : array();

		return isset( $last[ $slug ] ) ? (int) $last[ $slug ] : null;
	}

	/**
	 * How many MCP tools a given agent's endpoint currently exposes —
	 * used by the Settings > MCP tab's "Test" button, computed in-process
	 * from the exact same logic tools/list itself uses (no HTTP loopback).
	 *
	 * @param string $slug Agent slug.
	 * @return int
	 */
	public static function count_agent_tools( string $slug ): int {
		return count( self::get_mcp_tools( $slug ) );
	}

	/**
	 * Whether $slug's own abilities.json declares $tool_name.
	 *
	 * @param string $slug      Agent slug.
	 * @param string $tool_name Tool (or third-party ability) name.
	 * @return bool
	 */
	private static function agent_is_declared( string $slug, string $tool_name ): bool {
		return class_exists( '\\Agentic\\Abilities_Manifest' )
			&& \Agentic\Abilities_Manifest::is_declared( $slug, $tool_name );
	}

	/**
	 * Whether an Agent Builder tool should ever be listed or callable via
	 * MCP — shell/code-execution/remote-install tools and anything at
	 * HIGH/EXTREME risk stay off the MCP surface entirely, matching the
	 * posture Abilities_Bridge already applies for the native-adapter path.
	 * Third-party tools have no risk model here and are never passed in.
	 *
	 * Promoted to public (was private) so Webmcp_Bridge can share this exact
	 * exclusion list rather than re-implementing it — see
	 * Webmcp_Bridge::is_tool_webmcp_safe().
	 *
	 * $agent_slug matters: risk here must be the *effective* risk (tool's own
	 * registry default, floored by Risk_Level::BASELINE_RISKS, escalated by
	 * this specific agent's abilities.json, escalated again by any admin
	 * risk override for this agent+tool pair) via Abilities_Manifest::
	 * get_effective_risk() — not just the tool's generic registry default.
	 * A manifest or admin override can only ever escalate risk, never lower
	 * it, so checking the generic default alone can miss a tool that this
	 * *specific* agent has been escalated to HIGH/EXTREME, silently letting
	 * it stay listable/callable via MCP for that agent when it must not be.
	 * Omit $agent_slug only where truly no agent context exists yet — every
	 * call site that has one must pass it.
	 *
	 * @param string        $tool_name  Tool name.
	 * @param string        $agent_slug Calling agent slug, for effective-risk resolution.
	 * @param Tool_Base|null $tool      Tool instance, if already loaded (optional).
	 * @return bool
	 */
	public static function is_tool_mcp_safe( string $tool_name, string $agent_slug = '', ?Tool_Base $tool = null ): bool {
		$always_blocked = array(
			'run_wp_cli',
			'install_plugin_from_url',
			'create_agent_files',
			'add_custom_js',
			'validate_agent_code',
		);
		if ( in_array( $tool_name, $always_blocked, true ) ) {
			return false;
		}
		if ( str_starts_with( $tool_name, 'git_' ) || str_starts_with( $tool_name, 'cloudflare_' ) ) {
			return false;
		}
		if ( class_exists( Risk_Level::class ) ) {
			$risk = ( '' !== $agent_slug && class_exists( Abilities_Manifest::class ) )
				? Abilities_Manifest::get_effective_risk( $agent_slug, $tool_name, $tool )
				: Risk_Level::get_tool_default( $tool_name );
			if ( in_array( $risk, array( Risk_Level::HIGH, Risk_Level::EXTREME ), true ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Capability required to call a given Agent Builder tool via MCP —
	 * read-only tools only need edit_posts, everything else needs
	 * manage_options. Mirrors Abilities_Bridge's own read/write split.
	 *
	 * Promoted to public (was private) — shared with Webmcp_Bridge's
	 * logged-in-visitor capability check.
	 *
	 * @param string $tool_name Tool name.
	 * @return string
	 */
	public static function required_capability_for_tool( string $tool_name ): string {
		$tool = Tool_Loader::get_instance()->get( $tool_name );
		$readonly = $tool && ( $tool->get_annotations()['readonly'] ?? false );
		return $readonly ? 'edit_posts' : 'manage_options';
	}

	/**
	 * If $ability_name is one of Agent Builder's own (agent-builder/*),
	 * return the underlying tool name; otherwise null (a third-party
	 * ability this plugin has no risk model for).
	 *
	 * @param string $ability_name WP ability name.
	 * @return string|null
	 */
	private static function own_ability_to_tool_name( string $ability_name ): ?string {
		$prefix = 'agent-builder/';
		if ( ! str_starts_with( $ability_name, $prefix ) ) {
			return null;
		}
		return str_replace( '-', '_', substr( $ability_name, strlen( $prefix ) ) );
	}

	/**
	 * Human-readable label for a tool name, e.g. "manage_skill" → "Manage Skill".
	 *
	 * @param string $tool_name Tool name.
	 * @return string
	 */
	private static function tool_name_to_label( string $tool_name ): string {
		return ucwords( str_replace( '_', ' ', $tool_name ) );
	}

	/**
	 * Execute a tool via MCP tools/call.
	 *
	 * Prefers WordPress core's native Abilities API (6.9+, which itself
	 * enforces the ability's own permission_callback). Falls back to Agent
	 * Builder's own tool registry on older sites — see
	 * handle_tool_call_via_registry() for the equivalent permission check
	 * on that path.
	 *
	 * @param mixed  $id     JSON-RPC request id.
	 * @param array  $params JSON-RPC params (name + arguments).
	 * @param string $slug   Agent slug from the route — the call is only
	 *                       honored if that agent's own manifest declares
	 *                       the requested tool.
	 * @return array JSON-RPC response payload.
	 */
	private static function handle_tool_call( mixed $id, array $params, string $slug ): array {
		$mcp_name  = $params['name'] ?? '';
		$arguments = $params['arguments'] ?? array();

		if ( ! $mcp_name ) {
			return self::mcp_error( $id, -32602, 'Missing tool name.' );
		}

		if ( ! self::agent_mcp_ready( $slug ) ) {
			return self::mcp_error( $id, -32601, "Unknown tool: $mcp_name" );
		}

		if ( WP_Optional_API::has( 'wp_get_ability' ) ) {
			return self::handle_tool_call_via_ability( $id, $mcp_name, $arguments, $slug );
		}
		return self::handle_tool_call_via_registry( $id, $mcp_name, $arguments, $slug );
	}

	/**
	 * Execute an ability via MCP tools/call (6.9+).
	 *
	 * @param mixed  $id        JSON-RPC request id.
	 * @param string $mcp_name  MCP tool name.
	 * @param array  $arguments Tool arguments.
	 * @param string $slug      Agent slug from the route, already verified ready.
	 * @return array JSON-RPC response payload.
	 */
	private static function handle_tool_call_via_ability( mixed $id, string $mcp_name, array $arguments, string $slug ): array {
		// Convert MCP name → ability name and look up.
		$ability = WP_Optional_API::get_ability( self::mcp_name_to_ability( $mcp_name ) );
		if ( ! $ability ) {
			// Fallback: scan all abilities for a matching MCP name.
			$ability = self::find_ability_by_mcp_name( $mcp_name );
		}

		if ( ! $ability ) {
			return self::mcp_error( $id, -32602, "Unknown tool: $mcp_name" );
		}

		// Same MCP-safety posture as the listing — a tool hidden from
		// tools/list must not be callable directly by name either.
		$own_tool_name = self::own_ability_to_tool_name( $ability->get_name() );
		if ( null !== $own_tool_name && ! self::is_tool_mcp_safe( $own_tool_name, $slug ) ) {
			return self::mcp_error( $id, -32601, "Unknown tool: $mcp_name" );
		}

		// Same per-agent scoping as the listing.
		if ( ! self::agent_is_declared( $slug, $own_tool_name ?? $ability->get_name() ) ) {
			return self::mcp_error( $id, -32601, "Unknown tool: $mcp_name" );
		}

		try {
			$result = $ability->execute( $arguments );
			$text   = is_string( $result ) ? $result : (string) wp_json_encode( $result );

			return self::mcp_result(
				$id,
				array(
					'content' => array(
						array(
							'type' => 'text',
							'text' => $text,
						),
					),
				)
			);
		} catch ( \Throwable $e ) {
			return self::mcp_result(
				$id,
				array(
					'content' => array(
						array(
							'type' => 'text',
							'text' => 'Error: ' . $e->getMessage(),
						),
					),
					'isError' => true,
				)
			);
		}
	}

	/**
	 * Execute a tool directly via Agent Builder's own registry — the
	 * pre-6.9 fallback. Enforces the same enabled/safety checks as the
	 * listing, plus the read/write capability check the native path gets
	 * for free from the ability's own permission_callback.
	 *
	 * @param mixed  $id        JSON-RPC request id.
	 * @param string $mcp_name  MCP tool name (the raw tool name in this path).
	 * @param array  $arguments Tool arguments.
	 * @param string $slug      Agent slug from the route, already verified ready.
	 * @return array JSON-RPC response payload.
	 */
	private static function handle_tool_call_via_registry( mixed $id, string $mcp_name, array $arguments, string $slug ): array {
		$tool_name = $mcp_name;

		if ( ! Tools_Registry::is_enabled( $tool_name ) || ! self::is_tool_mcp_safe( $tool_name, $slug, Tool_Loader::get_instance()->get( $tool_name ) ) ) {
			return self::mcp_error( $id, -32601, "Unknown tool: $mcp_name" );
		}

		if ( ! self::agent_is_declared( $slug, $tool_name ) ) {
			return self::mcp_error( $id, -32601, "Unknown tool: $mcp_name" );
		}

		if ( ! current_user_can( self::required_capability_for_tool( $tool_name ) ) ) {
			return self::mcp_error( $id, -32603, 'Insufficient permissions for this tool.' );
		}

		$result = Tool_Loader::get_instance()->execute( $tool_name, $arguments );
		if ( null === $result ) {
			return self::mcp_error( $id, -32602, "Unknown tool: $mcp_name" );
		}

		return self::mcp_result(
			$id,
			array(
				'content' => array(
					array(
						'type' => 'text',
						'text' => (string) wp_json_encode( $result ),
					),
				),
				'isError' => ! empty( $result['error'] ),
			)
		);
	}

	/**
	 * Convert an ability name to an MCP-safe tool name.
	 *
	 * @param string $name Ability name, e.g. "agent-builder/create-post".
	 * @return string MCP tool name.
	 */
	private static function ability_to_mcp_name( string $name ): string {
		// Replace namespace separator `/` with `__` (double underscore) — reversible.
		return str_replace( '/', '__', $name );
	}

	/**
	 * Convert an MCP tool name back to an ability name.
	 *
	 * @param string $mcp_name MCP tool name.
	 * @return string Ability name.
	 */
	private static function mcp_name_to_ability( string $mcp_name ): string {
		return str_replace( '__', '/', $mcp_name );
	}

	/**
	 * Fallback lookup: scan all abilities for a matching MCP name.
	 *
	 * @param string $mcp_name MCP tool name.
	 * @return \WP_Ability|null
	 */
	private static function find_ability_by_mcp_name( string $mcp_name ): ?\WP_Ability {
		foreach ( WP_Optional_API::get_abilities() as $ability ) {
			if ( self::ability_to_mcp_name( $ability->get_name() ) === $mcp_name ) {
				return $ability;
			}
		}
		return null;
	}

	/**
	 * Build a JSON-RPC success envelope.
	 *
	 * @param mixed $id     JSON-RPC request id.
	 * @param mixed $result Result payload.
	 * @return array
	 */
	private static function mcp_result( mixed $id, mixed $result ): array {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => $result,
		);
	}

	/**
	 * Build a JSON-RPC error envelope.
	 *
	 * @param mixed  $id      JSON-RPC request id.
	 * @param int    $code    JSON-RPC error code.
	 * @param string $message Error message.
	 * @return array
	 */
	private static function mcp_error( mixed $id, int $code, string $message ): array {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'error'   => array(
				'code'    => $code,
				'message' => $message,
			),
		);
	}

	// ---------- 2. Connect approval flow ----------

	/**
	 * Route ?agentic_relay_connect=1 requests to the approval flow.
	 */
	public static function maybe_handle_connect(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['agentic_relay_connect'] ) ) {
			return;
		}

		self::handle_connect_page();
	}

	/**
	 * Validate the connect request, then show or process the approval screen.
	 */
	public static function handle_connect_page(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only entry point; the state token is verified server-side with the relay and the approval POST is nonce-protected.
		$raw_state = sanitize_text_field( wp_unslash( $_GET['relay_state'] ?? '' ) );
		$provider  = sanitize_key( wp_unslash( $_GET['provider'] ?? 'anthropic' ) );
		$callback  = esc_url_raw( rawurldecode( sanitize_text_field( wp_unslash( $_GET['callback'] ?? '' ) ) ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// Validate callback origin — must be our relay's exact callback endpoint.
		if ( ! self::is_allowed_callback( $callback ) ) {
			wp_die( esc_html__( 'Invalid connector callback URL.', 'agent-builder' ), 400 );
		}

		// Verify relay_state with the relay.
		if ( ! $raw_state || ! self::verify_relay_state( $raw_state ) ) {
			wp_die( esc_html__( 'This connection link has expired or is invalid. Please start again from Claude.ai.', 'agent-builder' ), 400 );
		}

		// Require WP login.
		if ( ! is_user_logged_in() ) {
			$return = add_query_arg( self::connect_query_args(), home_url( '/' ) );
			wp_safe_redirect( wp_login_url( $return ) );
			exit;
		}

		/**
		 * Filters the capability required to approve the relay connector.
		 *
		 * Connecting mints an Application Password and exposes agent tools to
		 * the relay, so this defaults to site administrators.
		 *
		 * @param string $capability Required capability. Default 'manage_options'.
		 */
		$required_cap = apply_filters( 'agentic_relay_connect_capability', 'manage_options' );
		if ( ! current_user_can( $required_cap ) ) {
			wp_die( esc_html__( 'Sorry, you need administrator permissions to connect this site. Please ask your site administrator to approve the connection.', 'agent-builder' ), 403 );
		}

		// Handle POST (approval decision).
		$request_method = strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) );
		if ( 'POST' === $request_method ) {
			self::process_approval( $raw_state, $provider, $callback );
			return;
		}

		// Show approval screen.
		self::render_approval_screen( $raw_state, $provider, $callback );
	}

	/**
	 * Process the admin's approve/deny decision and notify the relay.
	 *
	 * @param string $raw_state Relay state token (already verified).
	 * @param string $provider  Connector provider slug.
	 * @param string $callback  Relay callback URL (already validated).
	 */
	private static function process_approval( string $raw_state, string $provider, string $callback ): void {
		$nonce_action = 'agentic_relay_connect_' . substr( $raw_state, 0, 16 );

		$nonce = sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) );
		if ( ! $nonce || ! wp_verify_nonce( $nonce, $nonce_action ) ) {
			wp_die( esc_html__( 'Security check failed.', 'agent-builder' ), 403 );
		}

		if ( sanitize_key( wp_unslash( $_POST['decision'] ?? '' ) ) !== 'approve' ) {
			self::render_denied();
			return;
		}

		$user_id = get_current_user_id();

		// Generate Application Password — WP 5.6+.
		if ( ! class_exists( 'WP_Application_Passwords' ) ) {
			wp_die( esc_html__( 'Application Passwords are not available on this site.', 'agent-builder' ), 500 );
		}

		$result = WP_Application_Passwords::create_new_application_password( $user_id, array( 'name' => self::APP_PASS_NAME ) );
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ), 500 );
		}
		[ $app_pass, ] = $result;

		$user = wp_get_current_user();

		// Base64-encode for relay storage: "login:password".
		$app_pass_b64 = base64_encode( $user->user_login . ':' . $app_pass ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

		// Record that the connector is active.
		$connectors   = (array) get_option( self::CONNECTORS_OPTION, array() );
		$connectors[] = $provider;
		update_option( self::CONNECTORS_OPTION, array_unique( $connectors ) );

		// Collect available agent slugs.
		$agent_slugs = array();
		$agents_dir  = defined( 'AGENT_BUILDER_DIR' ) ? AGENT_BUILDER_DIR . 'library/agents/' : '';
		if ( $agents_dir && is_dir( $agents_dir ) ) {
			foreach ( glob( $agents_dir . '*/agent.php' ) as $file ) {
				$agent_slugs[] = basename( dirname( $file ) );
			}
		}

		// POST to relay callback.
		$response = wp_remote_post(
			$callback,
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'relay_state'      => $raw_state,
						'site_url'         => home_url(),
						'wp_user_email'    => $user->user_email,
						'wp_user_login'    => $user->user_login,
						'app_password_b64' => $app_pass_b64,
						'agent_slugs'      => $agent_slugs,
						'provider'         => $provider,
					)
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_die( esc_html__( 'Could not reach the relay. Please try again.', 'agent-builder' ), 500 );
		}

		// The relay responds with a 302 redirect location in the Location header.
		$location = wp_remote_retrieve_header( $response, 'location' );
		if ( $location ) {
			wp_safe_redirect( $location );
			exit;
		}

		// Fallback — relay redirected the PHP request server-side; show success.
		self::render_success( $provider );
	}

	// ---------- Validation helpers ----------

	/**
	 * Strictly validate the relay callback URL: exact scheme, host, and path
	 * must match ALLOWED_CALLBACK (query string may vary).
	 *
	 * @param string $callback Callback URL supplied by the relay redirect.
	 * @return bool
	 */
	private static function is_allowed_callback( string $callback ): bool {
		$allowed = wp_parse_url( self::ALLOWED_CALLBACK );
		$given   = wp_parse_url( $callback );

		if ( ! is_array( $given ) || ! is_array( $allowed ) ) {
			return false;
		}

		return ( $given['scheme'] ?? '' ) === $allowed['scheme']
			&& ( $given['host'] ?? '' ) === $allowed['host']
			&& ( $given['path'] ?? '' ) === $allowed['path']
			&& empty( $given['port'] )
			&& empty( $given['user'] );
	}

	/**
	 * The connect-flow query args, sanitized, for rebuilding the login redirect.
	 *
	 * @return array<string,string>
	 */
	private static function connect_query_args(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Values are only echoed back into the login redirect URL.
		return array(
			'agentic_relay_connect' => '1',
			'relay_state'           => sanitize_text_field( wp_unslash( $_GET['relay_state'] ?? '' ) ),
			'provider'              => sanitize_key( wp_unslash( $_GET['provider'] ?? 'anthropic' ) ),
			'callback'              => sanitize_text_field( wp_unslash( $_GET['callback'] ?? '' ) ),
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	// ---------- Relay state verification ----------

	/**
	 * Verify the relay state token server-side with the relay.
	 *
	 * @param string $raw_state Relay state token.
	 * @return bool
	 */
	private static function verify_relay_state( string $raw_state ): bool {
		$response = wp_remote_get(
			add_query_arg( 'state', rawurlencode( $raw_state ), self::VERIFY_STATE_URL ),
			array( 'timeout' => 8 )
		);
		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return false;
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return ! empty( $data['valid'] );
	}

	// ---------- Templates ----------

	/**
	 * Human-readable provider name, consistent across every screen in the
	 * connector flow — the approval screen and the success screen it leads
	 * to a moment later used to compute this independently and had drifted
	 * ("Claude (Anthropic)" vs plain "Claude"), which read as if a user had
	 * approved one thing and connected to another.
	 *
	 * Public (was private) — the MCP Settings tab's "Connected Clients"
	 * list renders this same label instead of the raw provider slug, so a
	 * connected provider reads identically everywhere in the connector
	 * flow rather than a third, differently-formatted way.
	 *
	 * @param string $provider Connector provider slug.
	 * @return string
	 */
	public static function provider_label( string $provider ): string {
		return 'anthropic' === $provider ? 'Claude (Anthropic)' : ucfirst( $provider );
	}

	/**
	 * Render the connector approval screen.
	 *
	 * @param string $raw_state Relay state token.
	 * @param string $provider  Connector provider slug.
	 * @param string $callback  Relay callback URL.
	 */
	private static function render_approval_screen( string $raw_state, string $provider, string $callback ): void {
		$nonce_action   = 'agentic_relay_connect_' . substr( $raw_state, 0, 16 );
		$nonce          = wp_create_nonce( $nonce_action );
		$current_user   = wp_get_current_user();
		$provider_label = self::provider_label( $provider );

		status_header( 200 );
		nocache_headers();
		header( 'Content-Type: text/html; charset=UTF-8' );

		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?php esc_html_e( 'Allow Agent Builder Connector', 'agent-builder' ); ?></title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#f0f0f1;color:#1d2327;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:24px}
.card{background:#fff;border-radius:8px;box-shadow:0 2px 16px rgba(0,0,0,.12);max-width:440px;width:100%;padding:40px 36px 32px}
.logo{text-align:center;margin-bottom:24px}
.logo svg{width:48px;height:48px;color:#2271b1}
h1{font-size:20px;font-weight:700;text-align:center;margin-bottom:8px}
.sub{font-size:14px;color:#646970;text-align:center;margin-bottom:24px;line-height:1.5}
.user-row{display:flex;align-items:center;gap:10px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:6px;padding:10px 14px;margin-bottom:20px;font-size:14px}
.user-row strong{display:block;color:#1d2327}
.user-row span{color:#646970;font-size:13px}
.label{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:#646970;margin-bottom:8px}
.scope{background:#f0f6fc;border:1px solid #c3d9f5;border-left:3px solid #2271b1;border-radius:4px;padding:12px 14px;font-size:14px;margin-bottom:20px;line-height:1.5}
.notice{font-size:12px;color:#646970;margin-bottom:20px;line-height:1.5}
.actions{display:flex;gap:12px}
.btn{flex:1;padding:10px 16px;font-size:14px;font-weight:600;border-radius:4px;border:1px solid transparent;cursor:pointer}
.btn-primary{background:#2271b1;color:#fff;border-color:#2271b1}
.btn-primary:hover{background:#135e96}
.btn-secondary{background:#fff;color:#2271b1;border-color:#2271b1}
.btn-secondary:hover{background:#f0f6fc}
</style>
</head>
<body>
<div class="card">
	<div class="logo">
		<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
			<path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z"/>
		</svg>
	</div>

	<h1>
		<?php
		/* translators: %s: AI provider name, e.g. "Claude (Anthropic)". */
		printf( esc_html__( 'Allow %s to access this site?', 'agent-builder' ), esc_html( $provider_label ) );
		?>
	</h1>
	<p class="sub">
		<?php
		printf(
			/* translators: 1: AI provider name, 2: site URL. */
			esc_html__( 'The Agent Builder Connector will allow %1$s to read and use your AI agents on %2$s.', 'agent-builder' ),
			esc_html( $provider_label ),
			'<strong>' . esc_html( home_url() ) . '</strong>'
		);
		?>
	</p>

	<div class="label"><?php esc_html_e( 'Signed in as', 'agent-builder' ); ?></div>
	<div class="user-row">
		<?php echo get_avatar( $current_user->ID, 32 ); ?>
		<div>
			<strong><?php echo esc_html( $current_user->display_name ); ?></strong>
			<span><?php echo esc_html( $current_user->user_email ); ?></span>
		</div>
	</div>

	<div class="label"><?php esc_html_e( 'What will be shared', 'agent-builder' ); ?></div>
	<div class="scope">
		<?php esc_html_e( 'An Application Password will be created so the relay can call your Agent Builder tools on behalf of Claude. You can revoke it at any time under Users → Profile → Application Passwords.', 'agent-builder' ); ?>
	</div>

	<?php
	// Same disclosure the MCP Settings tab's manual "Create Application
	// Password" button shows, and for the same reason: this mints the
	// identical credential, and MCP has no per-call confirmation step the
	// way chat does — approving this screen is the one confirmation these
	// actions ever get. "Only your Agent Builder tools are exposed" above
	// is true but reassuring in a way that undersells this, so the actual
	// unattended writes are spelled out rather than left implicit.
	$unattended_writes = class_exists( '\\Agentic\\Admin_Settings_REST' )
		? \Agentic\Admin_Settings_REST::data_mcp_unattended_writes()
		: array();
	if ( ! empty( $unattended_writes ) ) :
		?>
	<div class="label"><?php esc_html_e( 'Can be performed without asking you again', 'agent-builder' ); ?></div>
	<div class="scope">
		<p style="margin:0 0 8px">
			<?php
			printf(
				/* translators: %s: AI provider name, e.g. "Claude (Anthropic)". */
				esc_html__( 'Unlike chat, MCP has no per-action confirmation step — once approved, %s can immediately perform the following without asking you first each time:', 'agent-builder' ),
				esc_html( $provider_label )
			);
			?>
		</p>
		<ul style="margin:0;padding-left:18px">
			<?php foreach ( $unattended_writes as $w ) : ?>
			<li><code><?php echo esc_html( $w['tool'] ); ?></code> (<?php echo esc_html( $w['agent'] ); ?>) — <?php echo esc_html( $w['description'] ); ?></li>
			<?php endforeach; ?>
		</ul>
	</div>
	<?php else : ?>
	<p class="notice"><?php esc_html_e( 'Only your Agent Builder tools are exposed — no other site data.', 'agent-builder' ); ?></p>
	<?php endif; ?>

	<form method="POST">
		<?php wp_nonce_field( $nonce_action ); ?>
		<input type="hidden" name="relay_state" value="<?php echo esc_attr( $raw_state ); ?>">
		<input type="hidden" name="provider"    value="<?php echo esc_attr( $provider ); ?>">
		<input type="hidden" name="callback"    value="<?php echo esc_attr( $callback ); ?>">
		<div class="actions">
			<button type="submit" name="decision" value="deny"    class="btn btn-secondary"><?php esc_html_e( 'Deny', 'agent-builder' ); ?></button>
			<button type="submit" name="decision" value="approve" class="btn btn-primary"><?php esc_html_e( 'Allow Access', 'agent-builder' ); ?></button>
		</div>
	</form>
</div>
</body>
</html>
		<?php
		exit;
	}

	/**
	 * Render the success screen after connecting.
	 *
	 * @param string $provider Connector provider slug.
	 */
	private static function render_success( string $provider ): void {
		$label = self::provider_label( $provider );
		status_header( 200 );
		header( 'Content-Type: text/html; charset=UTF-8' );
		?>
<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Connected</title>
<style>body{font-family:-apple-system,sans-serif;background:#f0f0f1;display:flex;align-items:center;justify-content:center;min-height:100vh}.card{background:#fff;border-radius:8px;padding:48px 36px;text-align:center;box-shadow:0 2px 16px rgba(0,0,0,.12);max-width:400px}.icon{font-size:48px;margin-bottom:16px}h1{font-size:20px;font-weight:700;margin-bottom:8px}p{color:#646970;font-size:14px;line-height:1.6}</style>
</head><body><div class="card">
<div class="icon">✅</div>
<h1>
		<?php
		/* translators: %s: AI provider name, e.g. "Claude". */
		printf( esc_html__( 'Connected to %s!', 'agent-builder' ), esc_html( $label ) );
		?>
</h1>
<p><?php esc_html_e( 'Your Agent Builder tools are now available. You can return to Claude.ai and start using them.', 'agent-builder' ); ?></p>
</div></body></html>
		<?php
		exit;
	}

	/**
	 * Render the denied screen.
	 */
	private static function render_denied(): void {
		status_header( 200 );
		header( 'Content-Type: text/html; charset=UTF-8' );
		?>
<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Denied</title>
<style>body{font-family:-apple-system,sans-serif;background:#f0f0f1;display:flex;align-items:center;justify-content:center;min-height:100vh}.card{background:#fff;border-radius:8px;padding:48px 36px;text-align:center;box-shadow:0 2px 16px rgba(0,0,0,.12);max-width:400px}h1{font-size:20px;font-weight:700;margin-bottom:8px}p{color:#646970;font-size:14px}</style>
</head><body><div class="card">
<h1><?php esc_html_e( 'Access denied', 'agent-builder' ); ?></h1>
<p><?php esc_html_e( 'You chose not to connect this site. You can close this window.', 'agent-builder' ); ?></p>
</div></body></html>
		<?php
		exit;
	}
}
