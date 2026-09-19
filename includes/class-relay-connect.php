<?php
/**
 * Agent Builder — MCP endpoint
 *
 * Exposes each active agent's own on-site Model Context Protocol JSON-RPC
 * endpoint (GET/POST /wp-json/agentic/{slug}/mcp), authenticated the same way
 * as any other WordPress REST route: a WordPress Application Password the
 * site itself issued to the connecting admin (Settings → MCP →
 * "Create Application Password"). The credential never leaves the site —
 * this class has no outbound connection to any Agentic-owned server at all.
 *
 * (An earlier version of this class also ran an auto-connect flow that
 * minted an Application Password and POSTed it to mcp.agentic-plugin.com on
 * an admin's approval. That code path has been removed outright — see
 * docs/SUBMISSION-NOTES.md — not reworded or gated, because relaying a real
 * WordPress credential off-site is unacceptable regardless of the approval
 * screen in front of it. The manual, on-site "mint a credential yourself"
 * flow this docblock describes is the only way to connect an external MCP
 * client now.)
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
use Agentic\Tool_Executor;
use Agentic\Audit_Log;
use Agentic\Abilities_Bridge;
use Agentic\Agent_Settings;

/**
 * Registers and serves each agent's on-site MCP endpoint.
 *
 * Lives in the global namespace so it can be loaded before the autoloader.
 */
class Agentic_Relay_Connect {

	const APP_PASS_NAME = 'Agent Builder Relay';

	/**
	 * Per-agent map of { slug => unix timestamp } for the last time an
	 * authenticated MCP client successfully reached that agent's endpoint —
	 * distinct from "ready" (mcp_readiness(), which only means the agent is
	 * capable of responding, not that anything has ever actually connected).
	 */
	const LAST_CONNECTED_OPTION = 'agent_builder_mcp_last_connected';

	/**
	 * Slugs of agents an admin has explicitly turned MCP *on* for — MCP is
	 * opt-in per agent, off by default (empty/missing option = no agent's
	 * MCP endpoint responds until someone turns it on in Settings > MCP),
	 * independent of the agent's own active/inactive state — an agent can
	 * be active for chat with its MCP endpoint still switched off.
	 */
	const ENABLED_AGENTS_OPTION = 'agent_builder_mcp_enabled_agents';

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_mcp' ) );
	}

	// ---------- MCP JSON-RPC endpoint ----------

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
			'create_agent_files',
			'add_custom_js',
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
	 * Resolve an agent's effective operating mode for risk enforcement,
	 * mirroring Agent_Controller::apply_agent_overrides()'s precedence
	 * (per-agent override → agent's own default → site-wide setting) minus
	 * that method's LLM provider/model side effects, which don't apply to
	 * a single MCP tool call.
	 *
	 * @param string $slug Agent slug.
	 * @return string One of 'disabled'|'supervised'|'autonomous'.
	 */
	private static function effective_mode_for_agent( string $slug ): string {
		$global_mode = (string) get_option( 'agent_builder_agent_mode', 'supervised' );

		$mode_override = Agent_Settings::get( $slug, 'override_mode' );
		if ( ! empty( $mode_override ) ) {
			$mode = $mode_override;
		} else {
			$agent         = \Agentic_Agent_Registry::get_instance()->get_agent_instance( $slug );
			$agent_default = $agent ? $agent->get_default_mode() : '';
			$mode          = ! empty( $agent_default ) ? $agent_default : $global_mode;
		}

		return in_array( $mode, array( 'disabled', 'supervised', 'autonomous' ), true ) ? $mode : $global_mode;
	}

	/**
	 * Run a tool through the same risk-gate/audit pipeline every other
	 * invocation path (chat, WebMCP, cron) uses, instead of calling the
	 * tool directly. MCP has no live confirmation UI of its own, so a
	 * MEDIUM-risk tool without a prior "Always Allow" grant correctly
	 * comes back as 'confirmation_required' rather than silently
	 * executing — see Tool_Executor::execute()'s 'confirm' branch.
	 *
	 * @param string                $tool_name        Tool name.
	 * @param array                 $arguments        Tool arguments.
	 * @param string                $slug             Calling agent slug.
	 * @param Abilities_Bridge|null $abilities_bridge Optional third-party abilities fallback.
	 * @return array Raw Tool_Executor result (not yet MCP-wrapped).
	 */
	private static function execute_via_tool_executor( string $tool_name, array $arguments, string $slug, ?Abilities_Bridge $abilities_bridge = null ): array {
		$executor = new Tool_Executor( Tool_Loader::get_instance(), new Audit_Log(), $abilities_bridge );
		return $executor->execute( $tool_name, $arguments, $slug, self::effective_mode_for_agent( $slug ), 'mcp' );
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

		// An ability that maps back to one of this plugin's own registered
		// tools gets full risk-gating + audit logging via Tool_Executor,
		// same as every other invocation path.
		if ( null !== $own_tool_name ) {
			$result = self::execute_via_tool_executor( $own_tool_name, $arguments, $slug );

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

		// Genuine third-party WordPress ability — this plugin's own risk
		// levels were never defined for it, so it keeps running through
		// the ability's own permission_callback rather than Tool_Executor
		// (which would only ever see it as an unregistered/default-risk
		// tool). Still logged here so MCP-triggered activity involving a
		// third-party ability is traceable in the same audit trail.
		try {
			$result = $ability->execute( $arguments );
			$text   = is_string( $result ) ? $result : (string) wp_json_encode( $result );

			( new Audit_Log() )->log(
				$slug,
				'tool_call',
				$ability->get_name(),
				array(
					'_invocation'          => 'mcp',
					'_third_party_ability' => true,
				)
			);

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
			( new Audit_Log() )->log(
				$slug,
				'tool_blocked',
				$ability->get_name(),
				array(
					'_invocation' => 'mcp',
					'error'       => $e->getMessage(),
				)
			);

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

		$result = self::execute_via_tool_executor( $tool_name, $arguments, $slug );

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
}
