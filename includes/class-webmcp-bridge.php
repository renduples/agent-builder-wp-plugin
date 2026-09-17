<?php
/**
 * WebMCP Bridge — exposes an opt-in subset of this plugin's own tools to
 * whoever is currently in the browser, via the frontend script's
 * document.modelContext.registerTool() calls (assets/js/webmcp-bridge.js
 * falls back to the deprecated navigator.modelContext alias only on Chrome
 * builds that predate WebMCP's May 2026 spec revision).
 *
 * This is a different trust boundary than the MCP relay (class-relay-connect.php):
 * the relay authenticates a *remote* agent via an Application Password acting
 * on the site owner's behalf continuously, while this bridge serves the
 * *current browser session* — often an anonymous visitor. See
 * SUBMISSION-NOTES.md's "WebMCP Bridge" section for the full compliance
 * framing of agentic/v1/webmcp/execute.
 *
 * @package    Agent_Builder
 * @subpackage Includes
 * @since      3.3.90
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the WebMCP REST routes, the frontend bridge script, and the
 * /.well-known/webmcp.json discovery manifest.
 */
class Webmcp_Bridge {

	/**
	 * Option gating the entire bridge (master switch).
	 */
	public const OPTION_ENABLED = 'agent_builder_webmcp_enabled';

	/**
	 * Tool names a not-logged-in visitor may ever call, regardless of what
	 * webmcp_expose a manifest declares for any other tool. This is the
	 * single source of truth — enable_webmcp_defaults reads it too, rather
	 * than keeping its own separate list that could drift out of sync.
	 *
	 * Keep this list short and reviewed by hand: readonly + NONE/LOW risk is
	 * not sufficient justification on its own (those tiers were designed for
	 * the trusted wp-admin chat context, not an anonymous public caller) —
	 * every entry here must independently guarantee it never discloses
	 * anything beyond already-public content, regardless of what arguments
	 * an anonymous caller passes (see search_content's own is_user_logged_in()
	 * guard on its `status` argument for the pattern to follow).
	 *
	 * Two safety classes live here, not one:
	 *  - Readonly disclosure-safe (search_content, wc_browse_products,
	 *    wc_view_cart): never returns anything beyond what's already public,
	 *    or beyond the caller's own already-visible session data.
	 *  - Session-scoped mutation-safe (wc_add_to_cart, wc_update_cart_item):
	 *    not readonly, but every write is confined to the calling browser's
	 *    own ephemeral WooCommerce cart — no other visitor's data is ever
	 *    touched, no money moves, and the change is fully reversible. Being
	 *    logged in only adds trust, never removes it, so permission_execute()
	 *    below treats this whole list as safe for any caller, not just an
	 *    anonymous one — a guest and a logged-in customer get the same
	 *    shopping capability.
	 */
	public const ANONYMOUS_SAFE_TOOLS = array(
		'search_content',
		'wc_browse_products',
		'wc_view_cart',
		'wc_add_to_cart',
		'wc_update_cart_item',
	);

	/**
	 * Register all hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_bridge_script' ) );
		add_action( 'template_redirect', array( __CLASS__, 'serve_well_known_manifest' ), 1 );
	}

	/**
	 * Whether the master switch is on.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		return '1' === get_option( self::OPTION_ENABLED, '' );
	}

	/**
	 * Register the two WebMCP REST routes.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			'agentic/v1',
			'/webmcp/execute',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'execute_tool' ),
				'permission_callback' => array( __CLASS__, 'permission_execute' ),
			)
		);

		register_rest_route(
			'agentic/v1',
			'/webmcp/confirm',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'confirm_proposal' ),
				'permission_callback' => array( __CLASS__, 'permission_confirm' ),
			)
		);
	}

	/**
	 * Whether a tool should ever be reachable from WebMCP.
	 *
	 * Shares Agentic_Relay_Connect's exact HIGH/EXTREME-and-always-blocked
	 * exclusion list rather than re-implementing it — a HIGH-risk tool's
	 * natural outcome ("queue for an admin to review later") is the wrong UX
	 * for a live visitor waiting on an in-page confirm, so it is excluded
	 * upstream of risk enforcement entirely, the same way MCP excludes it.
	 *
	 * $agent_slug is required, not optional — the effective risk this
	 * ultimately checks can be escalated per-agent (manifest) or per-
	 * agent-and-tool (admin risk override), so "is this tool safe" only
	 * ever makes sense for a specific agent, never in the abstract.
	 *
	 * @param string $tool_name  Tool name.
	 * @param string $agent_slug Agent slug, for effective-risk resolution.
	 * @return bool
	 */
	public static function is_tool_webmcp_safe( string $tool_name, string $agent_slug ): bool {
		return class_exists( '\\Agentic_Relay_Connect' ) && \Agentic_Relay_Connect::is_tool_mcp_safe( $tool_name, $agent_slug );
	}

	/**
	 * Permission callback for POST /webmcp/execute.
	 *
	 * Decision table, first failure wins — see docs/agent-ready-score-brief.md
	 * and SUBMISSION-NOTES.md for the full rationale of each step.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return bool|\WP_Error
	 */
	public static function permission_execute( \WP_REST_Request $request ): bool|\WP_Error {
		if ( ! self::is_enabled() ) {
			return new \WP_Error( 'webmcp_disabled', __( 'The WebMCP Bridge is turned off.', 'agent-builder' ), array( 'status' => 403 ) );
		}

		$tool_name  = sanitize_key( (string) $request->get_param( 'tool_name' ) );
		$agent_slug = sanitize_key( (string) $request->get_param( 'agent_slug' ) );

		if ( '' === $tool_name || ! Tools_Registry::is_enabled( $tool_name ) ) {
			return new \WP_Error( 'unknown_tool', __( 'Unknown or disabled tool.', 'agent-builder' ), array( 'status' => 404 ) );
		}

		if ( ! self::is_tool_webmcp_safe( $tool_name, $agent_slug ) ) {
			return new \WP_Error( 'webmcp_unsafe_tool', __( 'This tool cannot be exposed to WebMCP.', 'agent-builder' ), array( 'status' => 403 ) );
		}

		$exposure = self::find_exposure( $agent_slug, $tool_name );
		if ( null === $exposure ) {
			return new \WP_Error( 'webmcp_not_exposed', __( 'This tool is not exposed for this agent.', 'agent-builder' ), array( 'status' => 403 ) );
		}

		if ( ! self::context_matches_request( $exposure['webmcp_context'], $request ) ) {
			return new \WP_Error( 'webmcp_wrong_context', __( 'This tool is not exposed in this context.', 'agent-builder' ), array( 'status' => 403 ) );
		}

		if ( ! self::is_same_origin( $request ) ) {
			return new \WP_Error( 'webmcp_cross_origin', __( 'Cross-origin WebMCP calls are not allowed.', 'agent-builder' ), array( 'status' => 403 ) );
		}

		// ANONYMOUS_SAFE_TOOLS is vetted safe for literally anyone — logged in
		// or not — so it's checked before branching on login state at all.
		// Being logged in only adds trust, never removes it: a tool that's
		// safe for a total stranger cannot become unsafe for a signed-in
		// customer. Without this, a logged-in shopper with no elevated WP
		// capability (the normal case — a WooCommerce customer account has
		// none) would fail the generic capability check below for any
		// non-readonly tool on this list (wc_add_to_cart, wc_update_cart_item),
		// while an anonymous guest calling the exact same tool would succeed
		// — logging in would make shopping less possible, which is backwards.
		if ( in_array( $tool_name, self::ANONYMOUS_SAFE_TOOLS, true ) ) {
			return true;
		}

		// Anonymous visitors, everything else: 'readonly' is NOT a safe proxy
		// for "safe to disclose publicly" — plenty of readonly, NONE/LOW-risk
		// tools return data that requires a WP capability to see anywhere
		// else in this plugin (admin usernames/emails via
		// list_privileged_users/get_author_list, failed-login counts via
		// get_security_overview, draft/private posts via list_posts' own
		// status:'any' default with zero arguments needed). A site owner can
		// still manually set webmcp_expose:true on any of those via the
		// Advanced tab — this is the fail-closed backstop that protects an
		// anonymous caller regardless of that manifest setting: only the
		// small, explicitly vetted allowlist above is ever reachable without
		// being logged in, the same way HIGH/EXTREME risk can never be
		// reached via MCP no matter what a manifest declares.
		if ( ! is_user_logged_in() ) {
			return new \WP_Error( 'webmcp_login_required', __( 'You must be logged in to use this action.', 'agent-builder' ), array( 'status' => 401 ) );
		}

		// Logged in: always the tool's own required capability — readonly
		// no longer bypasses this. A tool's own risk/capability floor is what
		// governs any authenticated caller, the same as it would via chat.
		if ( ! current_user_can( \Agentic_Relay_Connect::required_capability_for_tool( $tool_name ) ) ) {
			return new \WP_Error( 'webmcp_forbidden', __( 'You do not have permission to use this action.', 'agent-builder' ), array( 'status' => 403 ) );
		}

		return true;
	}

	/**
	 * POST /webmcp/execute.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public static function execute_tool( \WP_REST_Request $request ): \WP_REST_Response {
		$tool_name  = sanitize_key( (string) $request->get_param( 'tool_name' ) );
		$agent_slug = sanitize_key( (string) $request->get_param( 'agent_slug' ) );
		$arguments  = (array) $request->get_param( 'arguments' );
		$session_id = sanitize_text_field( (string) $request->get_param( 'session_id' ) );

		$tool_instance = Tool_Loader::get_instance()->get( $tool_name );
		$call_action   = is_string( $arguments['action'] ?? null ) ? $arguments['action'] : '';
		$risk          = Abilities_Manifest::get_effective_risk( $agent_slug, $tool_name, $tool_instance, $call_action );

		// WebMCP never honors the site's chat auto-approve preference
		// (agent_builder_approval_auto_max_risk) — that setting is a UX convenience
		// for the site owner's own trusted wp-admin chat sessions, and must
		// not silently let a MEDIUM-risk tool execute without this visitor's
		// own explicit in-page confirmation just because an admin picked
		// "hands-off" for their chat. NONE/LOW always execute immediately
		// (Risk_Level::mode_ceiling('autonomous') is LOW regardless of that
		// preference — see class-risk-level.php); MEDIUM always pauses here.
		// HIGH/EXTREME never reach this point (excluded in permission_execute).
		if ( Risk_Level::MEDIUM === $risk ) {
			$description = sprintf(
				/* translators: %s: tool name. */
				__( 'This site would like to run "%s".', 'agent-builder' ),
				$tool_name
			);
			$proposal = Agent_Proposals::create( $tool_name, $arguments, $agent_slug, $description );
			return new \WP_REST_Response(
				array(
					'status'      => 'confirmation_required',
					'proposal_id' => $proposal['id'],
					'message'     => $description,
				),
				200
			);
		}

		$executor = new Tool_Executor( Tool_Loader::get_instance(), new Audit_Log() );
		$result   = $executor->execute( $tool_name, $arguments, $agent_slug, 'autonomous', 'webmcp', null, $session_id );

		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * Permission callback for POST /webmcp/confirm.
	 *
	 * Re-derives the same facts permission_execute() checked, from the stored
	 * proposal row rather than trusting the first request — a session that
	 * lost eligibility between the two requests (e.g. logged out) cannot
	 * slip through on the confirm step.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return bool|\WP_Error
	 */
	public static function permission_confirm( \WP_REST_Request $request ): bool|\WP_Error {
		if ( ! self::is_enabled() ) {
			return new \WP_Error( 'webmcp_disabled', __( 'The WebMCP Bridge is turned off.', 'agent-builder' ), array( 'status' => 403 ) );
		}

		$proposal_id = sanitize_text_field( (string) $request->get_param( 'proposal_id' ) );
		$proposal    = Agent_Proposals::get( $proposal_id );
		if ( ! $proposal ) {
			return new \WP_Error( 'proposal_not_found', __( 'This confirmation has expired or was not found.', 'agent-builder' ), array( 'status' => 404 ) );
		}

		$tool_name  = (string) $proposal['tool'];
		$agent_slug = (string) $proposal['agent_id'];

		$exposure = self::find_exposure( $agent_slug, $tool_name );
		if ( ! self::is_tool_webmcp_safe( $tool_name, $agent_slug ) || null === $exposure ) {
			return new \WP_Error( 'webmcp_not_exposed', __( 'This tool is no longer exposed for this agent.', 'agent-builder' ), array( 'status' => 403 ) );
		}

		if ( ! self::context_matches_request( $exposure['webmcp_context'], $request ) ) {
			return new \WP_Error( 'webmcp_wrong_context', __( 'This tool is not exposed in this context.', 'agent-builder' ), array( 'status' => 403 ) );
		}

		if ( ! self::is_same_origin( $request ) ) {
			return new \WP_Error( 'webmcp_cross_origin', __( 'Cross-origin WebMCP calls are not allowed.', 'agent-builder' ), array( 'status' => 403 ) );
		}

		// Confirmations only ever exist for MEDIUM-risk tools (see execute_tool()
		// — NONE/LOW never create a proposal). In practice every non-readonly
		// tool is already gated to a logged-in, capable user by
		// permission_execute() before a proposal can exist; a readonly tool
		// an admin has unusually declared at MEDIUM risk would also require
		// login here, which a truly anonymous-reads-only setup should avoid
		// by keeping readonly tools at NONE/LOW.
		if ( ! is_user_logged_in() || ! current_user_can( \Agentic_Relay_Connect::required_capability_for_tool( $tool_name ) ) ) {
			return new \WP_Error( 'webmcp_forbidden', __( 'You do not have permission to confirm this action.', 'agent-builder' ), array( 'status' => 403 ) );
		}

		return true;
	}

	/**
	 * POST /webmcp/confirm.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public static function confirm_proposal( \WP_REST_Request $request ): \WP_REST_Response {
		$proposal_id = sanitize_text_field( (string) $request->get_param( 'proposal_id' ) );
		$result      = Agent_Proposals::approve( $proposal_id );
		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * Find this tool's WebMCP exposure entry for a given agent, if any.
	 *
	 * @param string $agent_slug Agent slug.
	 * @param string $tool_name  Tool name.
	 * @return array{agent_slug:string,tool_name:string,webmcp_context:string,risk:string}|null
	 */
	private static function find_exposure( string $agent_slug, string $tool_name ): ?array {
		foreach ( Abilities_Manifest::get_webmcp_exposed() as $exposure ) {
			if ( $exposure['agent_slug'] === $agent_slug && $exposure['tool_name'] === $tool_name ) {
				return $exposure;
			}
		}
		return null;
	}

	/**
	 * Whether a webmcp_context value is compatible with the current request
	 * (an admin-context tool called from wp-admin, or a frontend-context tool
	 * called from the public site). 'both' always matches.
	 *
	 * @param string           $webmcp_context 'frontend'|'admin'|'both'.
	 * @param \WP_REST_Request $request        REST request (read its own Referer header).
	 * @return bool
	 */
	private static function context_matches_request( string $webmcp_context, \WP_REST_Request $request ): bool {
		if ( 'both' === $webmcp_context ) {
			return true;
		}
		$referer     = (string) $request->get_header( 'referer' );
		$in_wp_admin = '' !== $referer && str_contains( $referer, admin_url() );
		return $in_wp_admin ? 'admin' === $webmcp_context : 'frontend' === $webmcp_context;
	}

	/**
	 * CSRF-hardening layer, not the sole security boundary — real enforcement
	 * is the capability/readonly checks above. Compares the Origin (falling
	 * back to Referer) header's host against this site's own host.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return bool
	 */
	private static function is_same_origin( \WP_REST_Request $request ): bool {
		$origin = $request->get_header( 'origin' );
		if ( ! $origin ) {
			$origin = $request->get_header( 'referer' );
		}
		if ( ! $origin ) {
			// No Origin/Referer at all — treat as same-origin rather than
			// blocking every non-browser client (e.g. curl during verification).
			return true;
		}

		$site_host    = wp_parse_url( home_url(), PHP_URL_HOST );
		$request_host = wp_parse_url( $origin, PHP_URL_HOST );

		return $site_host && $request_host && strtolower( (string) $site_host ) === strtolower( (string) $request_host );
	}

	/**
	 * Serve /.well-known/webmcp.json in-process, before WP's own 404 handling
	 * would otherwise take over — same pragmatic raw-REQUEST_URI style
	 * Agentic_Relay_Connect::maybe_handle_connect() already uses for its own
	 * frontend intercept. No rewrite rule or flush needed.
	 *
	 * @return void
	 */
	public static function serve_well_known_manifest(): void {
		if ( ! self::is_enabled() ) {
			return;
		}

		$path = wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only path comparison, not used as output or a file path.
		if ( ! is_string( $path ) || ! str_ends_with( $path, '/.well-known/webmcp.json' ) ) {
			return;
		}

		// WP::send_headers() (hooked to 'wp', which fires before 'template_redirect')
		// already sent a 404 status for this request, since no post/rewrite rule
		// matches this path — override it explicitly, or a client that checks the
		// status line before trusting the body would reject a perfectly valid response.
		status_header( 200 );
		header( 'Content-Type: application/json; charset=utf-8' );
		echo wp_json_encode( self::build_manifest() );
		exit;
	}

	/**
	 * The discovery manifest sitepassport.org (or any client) fetches to
	 * verify this site's WebMCP surface without visiting wp-admin.
	 *
	 * @return array
	 */
	private static function build_manifest(): array {
		$tools = array();
		foreach ( self::build_frontend_tool_manifest() as $tool ) {
			$tools[] = array(
				'name'        => $tool['name'],
				'description' => $tool['description'],
			);
		}

		return array(
			'site_url' => home_url( '/' ),
			'tools'    => $tools,
		);
	}

	/**
	 * Every frontend-exposed WebMCP tool, joined against its live Tool_Loader
	 * definition (name/description/inputSchema).
	 *
	 * @return array<int, array{name:string, description:string, inputSchema:array, agent_slug:string}>
	 */
	private static function build_frontend_tool_manifest(): array {
		$manifest = array();

		foreach ( Abilities_Manifest::get_webmcp_exposed( 'frontend' ) as $exposure ) {
			if ( ! Tools_Registry::is_enabled( $exposure['tool_name'] ) ) {
				continue;
			}
			$tool = Tool_Loader::get_instance()->get( $exposure['tool_name'] );
			if ( ! $tool ) {
				continue;
			}
			$manifest[] = array(
				'name'        => $tool->get_name(),
				'description' => $tool->get_description(),
				'inputSchema' => $tool->get_parameters(),
				'agent_slug'  => $exposure['agent_slug'],
			);
		}

		return $manifest;
	}

	/**
	 * Enqueue the frontend bridge script when the master switch is on.
	 *
	 * @return void
	 */
	public static function enqueue_bridge_script(): void {
		if ( ! self::is_enabled() ) {
			return;
		}

		$manifest = self::build_frontend_tool_manifest();
		if ( empty( $manifest ) ) {
			return;
		}

		Chat_Assets::register_ui_library();
		wp_enqueue_style( 'agentic-ui' );

		wp_enqueue_script(
			'agentic-webmcp-bridge',
			AGENT_BUILDER_URL . 'assets/js/webmcp-bridge.js',
			array( 'agentic-ui' ),
			AGENT_BUILDER_VERSION,
			true
		);

		wp_localize_script(
			'agentic-webmcp-bridge',
			'agenticWebmcp',
			array(
				'restUrl'      => rest_url( 'agentic/v1/' ),
				'nonce'        => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
				'toolManifest' => array_values( $manifest ),
			)
		);
	}
}
