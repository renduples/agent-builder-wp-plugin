<?php
/**
 * Admin Settings REST — backs the React settings app.
 *
 * @package    Agent_Builder
 * @subpackage Includes
 * @since      3.3.0
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bootstrap + get/update per settings tab.
 */
class Admin_Settings_REST {

	/**
	 * Register routes.
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Routes.
	 */
	public static function register_routes(): void {
		register_rest_route(
			'agentic/v1',
			'/admin-settings',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get_bootstrap' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'update_tab' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
				),
			)
		);

		// Connectivity check for one Agentic service (Endpoints tab "Test" button).
		register_rest_route(
			'agentic/v1',
			'/admin-settings/test-service',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'test_service' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'args'                => array(
					'slug' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		// Add-ons can render classic PHP tab bodies inside the React shell.
		register_rest_route(
			'agentic/v1',
			'/admin-settings/classic-tab',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_classic_tab_html' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'args'                => array(
					'tab' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		// MCP tab: live tool count for one agent's endpoint ("Test" button).
		register_rest_route(
			'agentic/v1',
			'/admin-settings/mcp-test',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'mcp_test' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'args'                => array(
					'slug' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		// MCP tab: turn one agent's MCP endpoint on or off, independent of
		// whether the agent itself is active.
		register_rest_route(
			'agentic/v1',
			'/admin-settings/mcp-toggle-agent',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'mcp_toggle_agent' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'args'                => array(
					'slug'    => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
					'enabled' => array(
						'type'     => 'boolean',
						'required' => true,
					),
				),
			)
		);

		// MCP tab: mint a new "Agent Builder Relay" Application Password for
		// the current user, for manually configuring a client like Cursor.
		register_rest_route(
			'agentic/v1',
			'/admin-settings/mcp-create-credential',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'mcp_create_credential' ),
				'permission_callback' => array( __CLASS__, 'can_manage_mcp_credentials' ),
			)
		);

		// MCP tab: revoke an existing "Agent Builder Relay" credential.
		register_rest_route(
			'agentic/v1',
			'/admin-settings/mcp-revoke-credential',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'mcp_revoke_credential' ),
				'permission_callback' => array( __CLASS__, 'can_manage_mcp_credentials' ),
				'args'                => array(
					'user_id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'uuid'    => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Permission.
	 */
	public static function can_manage(): bool {
		return current_user_can( 'agentic_manage_settings' ) || current_user_can( 'manage_options' );
	}

	/**
	 * Permission for minting/revoking MCP "Agent Builder Relay" Application
	 * Passwords — a real authentication credential, not just a plugin
	 * setting, so this requires actual site-administrator capability
	 * (manage_options) rather than the broader agentic_manage_settings a
	 * role could otherwise be granted. Matches the same default the
	 * relay-connect approval flow itself uses (agentic_relay_connect_capability).
	 */
	public static function can_manage_mcp_credentials(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Full bootstrap for the settings SPA.
	 *
	 * @return \WP_REST_Response
	 */
	public static function get_bootstrap(): \WP_REST_Response {
		$tabs = array(
			'interface' => __( 'Interface', 'agent-builder' ),
			'agents'    => __( 'Agents', 'agent-builder' ),
			'providers' => __( 'Providers', 'agent-builder' ),
			'users'     => __( 'Users', 'agent-builder' ),
			'security'  => __( 'Security', 'agent-builder' ),
			'apis'      => __( 'APIs', 'agent-builder' ),
			'endpoints' => __( 'Endpoints', 'agent-builder' ),
			'mcp'       => __( 'MCP', 'agent-builder' ),
		);
		$tabs = apply_filters( 'agentic_settings_tabs', $tabs );

		// APIs / Endpoints / MCP stay in $tabs (deep links, search, and the
		// "same capabilities" rule) but the React nav hides that group in
		// Basic unless the user has opened the Settings-page escape hatch.
		// Screen key is 'settings' — independent of site-wide ui_mode, of
		// per-tab keys like 'settings-users' / 'settings-interface' /
		// 'settings-security', and of Phase 6's 'providers' key.
		$advanced_only_tabs = array( 'apis', 'endpoints', 'mcp' );
		$settings_advanced  = class_exists( Admin_Menu_Handler::class )
			? Admin_Menu_Handler::is_advanced_mode( 'settings' )
			: ( 'advanced' === get_option( 'agentic_ui_mode', 'basic' ) );

		$groups = array(
			array(
				'id'    => 'basic',
				'label' => __( 'Basic', 'agent-builder' ),
				'slugs' => array( 'interface', 'agents', 'providers', 'users', 'security' ),
			),
			array(
				'id'    => 'advanced',
				'label' => __( 'Advanced', 'agent-builder' ),
				'slugs' => $advanced_only_tabs,
			),
		);

		/**
		 * Tabs whose body is rendered by PHP and injected into React. Empty by
		 * default in this standalone free build — add-ons may still hook in
		 * their own classic-HTML tabs via this filter.
		 *
		 * @param string[] $classic_tabs Tab slugs.
		 */
		$classic_tabs = apply_filters( 'agentic_settings_classic_html_tabs', array() );
		$classic_tabs = array_values(
			array_unique(
				array_filter(
					array_map( 'sanitize_key', (array) $classic_tabs )
				)
			)
		);

		return new \WP_REST_Response(
			array(
				'tabs'                 => $tabs,
				'groups'               => $groups,
				'is_pro'               => false,
				'classic_tabs'         => $classic_tabs,
				'admin_url'            => admin_url(),
				'rest_url'             => rest_url( 'agentic/v1/' ),
				'is_settings_advanced' => $settings_advanced,
				'advanced_only_tabs'   => $advanced_only_tabs,
				'data'                 => array(
					'interface'    => self::data_interface(),
					'providers'    => self::data_providers(),
					'agents'       => self::data_agents(),
					// Still exposed for Knowledge page embed (settings REST).
					'instructions' => self::data_instructions(),
					'memory'       => self::data_memory(),
					'security'     => self::data_security(),
					'users'        => self::data_users(),
					'apis'         => self::data_apis(),
					'endpoints'    => self::data_endpoints(),
					'mcp'          => self::data_mcp(),
				),
			),
			200
		);
	}

	/**
	 * HTML body for a classic settings tab, rendered by an add-on via the
	 * agentic_settings_classic_html_tabs / agentic_render_settings_tab hooks.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get_classic_tab_html( \WP_REST_Request $request ) {
		$tab = sanitize_key( (string) $request->get_param( 'tab' ) );
		if ( '' === $tab ) {
			return new \WP_Error( 'agentic_invalid_tab', __( 'Invalid settings tab.', 'agent-builder' ), array( 'status' => 400 ) );
		}

		$classic_tabs = apply_filters( 'agentic_settings_classic_html_tabs', array() );
		$classic_tabs = array_map( 'sanitize_key', (array) $classic_tabs );

		if ( ! in_array( $tab, $classic_tabs, true ) ) {
			return new \WP_Error(
				'agentic_not_classic_tab',
				__( 'This tab is not available as classic HTML.', 'agent-builder' ),
				array( 'status' => 404 )
			);
		}

		/**
		 * Fires before capturing classic settings tab HTML for the React shell.
		 *
		 * @param string $tab Tab slug.
		 */
		do_action( 'agentic_before_classic_settings_tab', $tab );

		ob_start();
		/**
		 * Render classic settings tab body.
		 *
		 * @param string $tab Tab slug.
		 */
		do_action( 'agentic_render_settings_tab', $tab );
		$html = (string) ob_get_clean();

		return new \WP_REST_Response(
			array(
				'tab'  => $tab,
				'html' => $html,
			),
			200
		);
	}

	/**
	 * Connectivity check for one Agentic service, used by the Endpoints tab's
	 * per-row "Test" button. Uses the *currently saved* URL for that service
	 * (including any admin override), not necessarily the built-in default.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function test_service( \WP_REST_Request $request ) {
		$slug = sanitize_key( (string) $request->get_param( 'slug' ) );
		if ( ! in_array( $slug, Service_Registry::get_slugs(), true ) ) {
			return new \WP_Error( 'agentic_invalid_service', __( 'Unknown service.', 'agent-builder' ), array( 'status' => 400 ) );
		}

		// Every service except agentic-api (the marketplace's own WordPress
		// REST API, not one of the Python inference services) exposes an
		// unauthenticated GET /health probe.
		$has_health = 'agentic-api' !== $slug;
		$url        = $has_health ? Service_Registry::url( $slug, '/health' ) : Service_Registry::url( $slug );

		if ( '' === $url ) {
			return new \WP_REST_Response(
				array(
					'ok'      => false,
					'message' => __( 'No URL configured for this service.', 'agent-builder' ),
				),
				200
			);
		}

		$start = microtime( true );
		$resp  = wp_remote_get( $url, array( 'timeout' => 8 ) );
		$ms    = (int) round( ( microtime( true ) - $start ) * 1000 );

		if ( is_wp_error( $resp ) ) {
			return new \WP_REST_Response(
				array(
					'ok'      => false,
					'message' => $resp->get_error_message(),
				),
				200
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $resp );
		// A real /health endpoint must say 200. For agentic-api (no /health
		// route) any non-5xx response at least proves the host is up.
		$ok = $has_health ? ( 200 === $code ) : ( $code > 0 && $code < 500 );

		return new \WP_REST_Response(
			array(
				'ok'          => $ok,
				'status_code' => $code,
				'latency_ms'  => $ms,
				'message'     => $ok
					? sprintf(
						/* translators: 1: HTTP status code, 2: response time in milliseconds */
						__( 'Reachable (HTTP %1$d, %2$dms)', 'agent-builder' ),
						$code,
						$ms
					)
					: sprintf(
						/* translators: %d: HTTP status code */
						__( 'Unexpected response (HTTP %d)', 'agent-builder' ),
						$code
					),
			),
			200
		);
	}

	/**
	 * MCP tab "Test" button: how many tools a given agent's MCP endpoint
	 * currently exposes, computed in-process (no HTTP loopback) from the
	 * exact same logic tools/list itself uses.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function mcp_test( \WP_REST_Request $request ) {
		$slug = sanitize_key( (string) $request->get_param( 'slug' ) );
		if ( ! class_exists( '\\Agentic_Relay_Connect' ) ) {
			return new \WP_Error( 'agentic_mcp_unavailable', __( 'MCP is not available on this site.', 'agent-builder' ), array( 'status' => 500 ) );
		}

		$readiness = \Agentic_Relay_Connect::mcp_readiness( $slug );
		if ( ! $readiness['ready'] ) {
			return new \WP_REST_Response(
				array(
					'ok'      => false,
					'message' => $readiness['reason'] ?? __( 'This agent has no MCP tools available.', 'agent-builder' ),
				),
				200
			);
		}

		$count = \Agentic_Relay_Connect::count_agent_tools( $slug );

		return new \WP_REST_Response(
			array(
				'ok'         => true,
				'tool_count' => $count,
				'message'    => sprintf(
					/* translators: %d: number of MCP tools */
					_n( '%d tool available.', '%d tools available.', $count, 'agent-builder' ),
					$count
				),
			),
			200
		);
	}

	/**
	 * MCP tab "Connected"/"Enabled" switch: turn one agent's MCP endpoint on
	 * or off, independent of the agent's own active/inactive state.
	 * Agentic_Relay_Connect::set_mcp_enabled() logs the change.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function mcp_toggle_agent( \WP_REST_Request $request ) {
		$slug    = sanitize_key( (string) $request->get_param( 'slug' ) );
		$enabled = (bool) $request->get_param( 'enabled' );

		if ( ! class_exists( '\\Agentic_Relay_Connect' ) ) {
			return new \WP_Error( 'agentic_mcp_unavailable', __( 'MCP is not available on this site.', 'agent-builder' ), array( 'status' => 500 ) );
		}

		\Agentic_Relay_Connect::set_mcp_enabled( $slug, $enabled );

		// Recomputed fresh, post-toggle — the frontend updates its Status
		// column from this one response instead of needing a full reload.
		$readiness = \Agentic_Relay_Connect::mcp_readiness( $slug );

		return new \WP_REST_Response(
			array(
				'ok'      => true,
				'slug'    => $slug,
				'enabled' => $enabled,
				'ready'   => $readiness['ready'],
				'reason'  => $readiness['reason'],
			),
			200
		);
	}

	/**
	 * MCP tab "Create Application Password": mint a new "Agent Builder
	 * Relay" credential for the current user, the same call
	 * Agentic_Relay_Connect::process_approval() makes for the OAuth-style
	 * connector flow — this is the manual-setup equivalent, for configuring
	 * a generic MCP client (e.g. Cursor) that needs a username + password
	 * rather than a browser-driven approval.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function mcp_create_credential() {
		if ( ! class_exists( 'WP_Application_Passwords' ) || ! class_exists( '\\Agentic_Relay_Connect' ) ) {
			return new \WP_Error( 'agentic_mcp_unavailable', __( 'Application Passwords are not available on this site.', 'agent-builder' ), array( 'status' => 500 ) );
		}

		$user_id = get_current_user_id();
		$result  = \WP_Application_Passwords::create_new_application_password(
			$user_id,
			array( 'name' => \Agentic_Relay_Connect::APP_PASS_NAME )
		);

		if ( is_wp_error( $result ) ) {
			return new \WP_Error( 'agentic_mcp_credential_failed', $result->get_error_message(), array( 'status' => 400 ) );
		}

		list( $plaintext_password, $item ) = $result;
		$user = wp_get_current_user();

		Audit_Log::log_admin(
			'mcp_credential_created',
			'agent_builder_relay',
			array(
				'user_id' => $user_id,
				'uuid'    => $item['uuid'],
			)
		);

		return new \WP_REST_Response(
			array(
				'ok'         => true,
				'password'   => $plaintext_password,
				'user_id'    => $user_id,
				'user_login' => $user->user_login,
				'uuid'       => $item['uuid'],
				'created'    => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $item['created'] ),
			),
			200
		);
	}

	/**
	 * MCP tab "Revoke": delete an existing "Agent Builder Relay" credential.
	 * Gated by can_manage_mcp_credentials() (manage_options), since this can
	 * revoke a credential belonging to a *different* administrator than the
	 * one making the request.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function mcp_revoke_credential( \WP_REST_Request $request ) {
		if ( ! class_exists( 'WP_Application_Passwords' ) ) {
			return new \WP_Error( 'agentic_mcp_unavailable', __( 'Application Passwords are not available on this site.', 'agent-builder' ), array( 'status' => 500 ) );
		}

		$user_id = absint( $request->get_param( 'user_id' ) );
		$uuid    = sanitize_text_field( (string) $request->get_param( 'uuid' ) );

		$result = \WP_Application_Passwords::delete_application_password( $user_id, $uuid );
		if ( is_wp_error( $result ) ) {
			return new \WP_Error( 'agentic_mcp_revoke_failed', $result->get_error_message(), array( 'status' => 400 ) );
		}

		Audit_Log::log_admin(
			'mcp_credential_revoked',
			'agent_builder_relay',
			array(
				'user_id' => $user_id,
				'uuid'    => $uuid,
			)
		);

		// Agentic_Relay_Connect::CONNECTORS_OPTION only ever grows — nothing
		// else removes a provider from it once the connector-approval flow
		// adds it, so "Connected Clients" would otherwise still show a
		// provider as connected long after its only credential is gone. Since
		// that option doesn't track which credential belongs to which
		// provider, there's no way to correctly prune just one entry when
		// several might be connected — but once every Agent Builder Relay
		// credential is gone, "nothing is connected" is unambiguous, so
		// clear it then rather than leave a permanently stale badge.
		if ( empty( self::data_mcp_credentials() ) ) {
			update_option( \Agentic_Relay_Connect::CONNECTORS_OPTION, array() );
		}

		return new \WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * Update one tab.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function update_tab( \WP_REST_Request $request ) {
		$tab  = sanitize_key( (string) $request->get_param( 'tab' ) );
		$data = $request->get_param( 'data' );
		if ( ! is_array( $data ) ) {
			$data = array();
		}

		// Removed tabs → current homes.
		if ( 'general' === $tab ) {
			$tab = 'interface';
		}
		if ( 'global' === $tab ) {
			$tab = 'agents';
		}

		$warnings = array();

		switch ( $tab ) {
			case 'interface':
				self::save_interface( $data );
				break;
			case 'security':
				self::save_security( $data );
				break;
			case 'users':
				self::save_users( $data );
				break;
			case 'memory':
				self::save_memory( $data );
				break;
			case 'agents':
				$warnings = self::save_agents( $data );
				break;
			case 'instructions':
				self::save_instructions( $data );
				break;
			case 'endpoints':
				self::save_endpoints( $data );
				break;
			default:
				return new \WP_Error( 'invalid_tab', __( 'Unknown settings tab.', 'agent-builder' ), array( 'status' => 400 ) );
		}

		Security_Log::log_system(
			'settings_changed',
			$tab . '_settings',
			array(
				'tab' => $tab,
				'via' => 'react_rest',
			)
		);
		if ( class_exists( Audit_Log::class ) ) {
			Audit_Log::log_admin(
				'settings_changed',
				'settings',
				array(
					'id'  => $tab,
					'tab' => $tab,
					'via' => 'react_rest',
				)
			);
		}

		$method = 'data_' . $tab;
		$out    = method_exists( __CLASS__, $method ) ? self::$method() : array();

		return new \WP_REST_Response(
			array(
				'ok'       => true,
				'tab'      => $tab,
				'data'     => $out,
				'warnings' => $warnings,
			),
			200
		);
	}

	// ── Data builders ─────────────────────────────────────────────────────

	/**
	 * @return array<string,mixed>
	 */
	/**
	 * @return array<string,mixed>
	 */
	private static function data_interface(): array {
		$is_advanced = class_exists( Admin_Menu_Handler::class )
			? Admin_Menu_Handler::is_advanced_mode( 'settings-interface' )
			: ( 'advanced' === get_option( 'agentic_ui_mode', 'basic' ) );

		return array(
			'ui_mode'          => 'advanced' === get_option( 'agentic_ui_mode', 'basic' ) ? 'advanced' : 'basic',
			'show_onboarding'  => '0' !== get_option( 'agentic_show_onboarding', '1' ),
			'admin_address'    => (string) get_option( 'agentic_admin_address', '' ),
			'frontend_address' => (string) get_option( 'agentic_frontend_address', '' ),
			'global_font'      => (string) get_option( 'agentic_global_font', '' ),
			'global_accent'    => (string) get_option( 'agentic_global_accent', '' ),
			'chat_theme'       => (string) get_option( 'agentic_chat_theme', 'light' ),
			'chat_themes'      => self::chat_theme_presets(),
			'is_advanced'      => $is_advanced,
			'font_options'     => array(
				array(
					'label' => __( 'Theme default', 'agent-builder' ),
					'value' => '',
				),
				array(
					'label' => __( 'System UI', 'agent-builder' ),
					'value' => 'system-ui, sans-serif',
				),
				array(
					'label' => 'Arial / Helvetica',
					'value' => 'Arial, Helvetica, sans-serif',
				),
				array(
					'label' => 'Georgia',
					'value' => 'Georgia, serif',
				),
				array(
					'label' => 'Times New Roman',
					'value' => '"Times New Roman", Times, serif',
				),
				array(
					'label' => 'Courier New (monospace)',
					'value' => '"Courier New", Courier, monospace',
				),
				array(
					'label' => 'Verdana',
					'value' => 'Verdana, Geneva, sans-serif',
				),
			),
		);
	}

	/**
	 * Chat theme presets (preview swatches + labels).
	 *
	 * @return array<int, array<string, string>>
	 */
	private static function chat_theme_presets(): array {
		return array(
			array(
				'value'  => 'light',
				'label'  => __( 'Light', 'agent-builder' ),
				'desc'   => __( 'Clean white with WordPress blue accents — the default.', 'agent-builder' ),
				'bg'     => '#ffffff',
				'accent' => '#2271b1',
				'text'   => '#1d2327',
				'msg'    => '#f0f0f1',
			),
			array(
				'value'  => 'dark',
				'label'  => __( 'Dark', 'agent-builder' ),
				'desc'   => __( 'Deep purple dark theme.', 'agent-builder' ),
				'bg'     => '#1a1a2e',
				'accent' => '#8b5cf6',
				'text'   => '#f0f0f0',
				'msg'    => 'rgba(139,92,246,0.15)',
			),
			array(
				'value'  => 'midnight',
				'label'  => __( 'Midnight', 'agent-builder' ),
				'desc'   => __( 'Pure dark with emerald green accents.', 'agent-builder' ),
				'bg'     => '#0f172a',
				'accent' => '#10b981',
				'text'   => '#e2e8f0',
				'msg'    => 'rgba(16,185,129,0.12)',
			),
			array(
				'value'  => 'ocean',
				'label'  => __( 'Ocean', 'agent-builder' ),
				'desc'   => __( 'Deep blue with teal highlights.', 'agent-builder' ),
				'bg'     => '#0c1222',
				'accent' => '#06b6d4',
				'text'   => '#e0f2fe',
				'msg'    => 'rgba(6,182,212,0.12)',
			),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function data_providers(): array {
		$default = (string) get_option( 'agentic_llm_provider', 'agentic' );
		$rows    = array();
		foreach ( Provider_Registry::get_all() as $p ) {
			$connected = Admin_Menu_Handler::provider_is_connected( $p );
			$rows[]    = array(
				'slug'          => (string) ( $p['slug'] ?? '' ),
				'name'          => (string) ( $p['name'] ?? '' ),
				'default_model' => (string) ( $p['default_model'] ?? '' ),
				'auth_type'     => (string) ( $p['auth_type'] ?? '' ),
				'req_format'    => (string) ( $p['req_format'] ?? '' ),
				'is_builtin'    => ! empty( $p['is_builtin'] ),
				'connected'     => $connected,
				'is_default'    => ( (string) ( $p['slug'] ?? '' ) === $default ),
				'icon'          => (string) ( $p['icon'] ?? '' ),
				'edit_url'      => admin_url( 'admin.php?page=agentic-settings&tab=providers&edit_provider=' . rawurlencode( (string) ( $p['slug'] ?? '' ) ) ),
			);
		}
		return array(
			'providers'          => $rows,
			'default'            => $default,
			'add_url'            => admin_url( 'admin.php?page=agentic-settings&tab=providers&add_provider=1' ),
			'form_action'        => admin_url( 'admin.php?page=agentic-settings&tab=providers' ),
			'provider_nonce'     => wp_create_nonce( 'agentic_provider_nonce' ),
			'set_default_action' => 'agentic_provider_action',
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	/**
	 * @return array<string,mixed>
	 */
	private static function data_agents(): array {
		$registry  = \Agentic_Agent_Registry::get_instance();
		$installed = $registry->get_installed_agents();
		$active    = $registry->get_active_agents();
		$providers = array();
		foreach ( Provider_Registry::get_all() as $p ) {
			$providers[] = array(
				'slug'          => $p['slug'],
				'name'          => $p['name'],
				'default_model' => $p['default_model'] ?? '',
				'models'        => $p['models'] ?? array(),
				'connected'     => Admin_Menu_Handler::provider_is_connected( $p ),
			);
		}

		$agents = array();
		foreach ( $active as $slug ) {
			if ( ! isset( $installed[ $slug ] ) ) {
				continue;
			}
			$agents[] = array(
				'slug'              => $slug,
				'name'              => (string) ( $installed[ $slug ]['name'] ?? $slug ),
				'override_provider' => (string) Agent_Settings::get( $slug, 'override_provider' ),
				'override_model'    => (string) Agent_Settings::get( $slug, 'override_model' ),
				'override_mode'     => (string) Agent_Settings::get( $slug, 'override_mode' ),
				'override_audio'    => (string) Agent_Settings::get( $slug, 'override_audio' ),
				'override_tts'      => (string) Agent_Settings::get( $slug, 'override_tts' ),
				'override_vision'   => (string) Agent_Settings::get( $slug, 'override_vision' ),
			);
		}

		return array(
			'global_provider'    => (string) get_option( 'agentic_llm_provider', 'agentic' ),
			'global_model'       => (string) get_option( 'agentic_model', '' ),
			'providers'          => $providers,
			'agents'             => $agents,
			'disable_all_agents' => Emergency_Stop::is_active(),
			// Chat capabilities (moved from Global tab).
			'chat_audio'         => '1' === (string) get_option( 'agentic_chat_audio', '0' ) || true === get_option( 'agentic_chat_audio', false ),
			'chat_tts'           => '0' !== (string) get_option( 'agentic_chat_tts', '1' ),
			'chat_vision'        => '1' === (string) get_option( 'agentic_chat_vision', '0' ) || true === get_option( 'agentic_chat_vision', false ),
			'chat_whitelabel'    => '1' === (string) get_option( 'agentic_chat_whitelabel', '1' ),
			'show_whatsapp_cta'  => '1' === (string) get_option( 'agentic_show_whatsapp_cta', '0' ),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function data_instructions(): array {
		$registry  = \Agentic_Agent_Registry::get_instance();
		$installed = $registry->get_installed_agents();
		$list      = array();
		foreach ( $installed as $slug => $info ) {
			$list[] = array(
				'slug'            => $slug,
				'name'            => (string) ( $info['name'] ?? $slug ),
				'welcome_message' => (string) Agent_Settings::get( $slug, 'persona_welcome_message' ),
				'notes'           => (string) Agent_Settings::get( $slug, 'persona_notes' ),
				'response_style'  => (string) Agent_Settings::get( $slug, 'persona_response_style' ),
			);
		}
		return array( 'agents' => $list );
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function data_security(): array {
		$is_advanced = class_exists( Admin_Menu_Handler::class )
			? Admin_Menu_Handler::is_advanced_mode( 'settings-security' )
			: ( 'advanced' === get_option( 'agentic_ui_mode', 'basic' ) );

		return array(
			'default_agent_mode'       => (string) get_option( 'agentic_default_agent_mode', 'supervised' ),
			'message_scanning'         => (bool) get_option( 'agentic_message_scanning', true ),
			'chat_consent_enabled'     => (bool) get_option( 'agentic_chat_consent_enabled', false ),
			'chat_consent_text'        => (string) get_option( 'agentic_chat_consent_text', '' ),
			'retention_conversations'  => (int) get_option( 'agentic_retention_conversations', 30 ),
			'retention_audit_log'      => (int) get_option( 'agentic_retention_audit_log', 30 ),
			'rate_limit_authenticated' => (int) get_option( 'agentic_rate_limit_authenticated', 30 ),
			'rate_limit_anonymous'     => (int) get_option( 'agentic_rate_limit_anonymous', 10 ),
			'allow_platform_sync'      => '1' === (string) get_option( 'agentic_allow_platform_sync', '0' ),
			'is_advanced'              => $is_advanced,
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function data_users(): array {
		$roles_out = array();
		if ( class_exists( User_Roles::class ) ) {
			foreach ( User_Roles::get_all_wp_roles() as $slug => $role_data ) {
				$roles_out[] = array(
					'slug' => (string) $slug,
					'name' => translate_user_role( (string) ( $role_data['name'] ?? $slug ) ),
				);
			}
		}

		$plugin_privs = array();
		$agent_privs  = array();
		$settings     = array(
			'plugin' => array(),
			'agents' => array(),
		);
		if ( class_exists( User_Roles::class ) ) {
			$settings = User_Roles::get_settings();
			foreach ( User_Roles::get_plugin_privileges() as $key => $info ) {
				$plugin_privs[] = array(
					'key'         => (string) $key,
					'label'       => (string) ( $info['label'] ?? $key ),
					'description' => (string) ( $info['description'] ?? '' ),
					'roles'       => array_values( (array) ( $settings['plugin'][ $key ] ?? array() ) ),
				);
			}
			foreach ( User_Roles::get_agent_privileges() as $key => $info ) {
				$agent_privs[] = array(
					'key'         => (string) $key,
					'label'       => (string) ( $info['label'] ?? $key ),
					'description' => (string) ( $info['description'] ?? '' ),
					'roles'       => array_values( (array) ( $settings['agents'][ $key ] ?? array() ) ),
				);
			}
		}

		$limits = class_exists( Usage_Limits::class )
			? Usage_Limits::get_limits()
			: array();

		// Basic mode swaps the raw role/privilege matrix for a chat with the
		// bundled User Assistant — same per-tab mode split Skills/Tools/etc.
		// use, but scoped to just this one Settings tab via its own screen
		// key ('settings-users'), independent of the whole-page 'settings'
		// tab-visibility grouping above.
		$is_advanced = class_exists( Admin_Menu_Handler::class )
			? Admin_Menu_Handler::is_advanced_mode( 'settings-users' )
			: ( 'advanced' === get_option( 'agentic_ui_mode', 'basic' ) );

		$assistant = null;
		if ( ! $is_advanced ) {
			$instance  = \Agentic_Agent_Registry::get_instance()->get_agent_instance( 'user-assistant' );
			$assistant = $instance
				? array(
					'active'            => true,
					'id'                => $instance->get_id(),
					'name'              => $instance->get_name(),
					'icon'              => $instance->get_icon(),
					'welcome_message'   => $instance->get_welcome_message(),
					'suggested_prompts' => $instance->get_suggested_prompts(),
				)
				: array( 'active' => false );
		}

		return array(
			'allow_anonymous_chat' => (bool) get_option( 'agentic_allow_anonymous_chat', false ),
			'roles'                => $roles_out,
			'plugin_privileges'    => $plugin_privs,
			'agent_privileges'     => $agent_privs,
			'usage_limits'         => $limits,
			'is_advanced'          => $is_advanced,
			'assistant'            => $assistant,
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function data_memory(): array {
		return array(
			'ttl_days'             => (int) get_option( 'agentic_memory_ttl_days', 30 ),
			'local_memory_enabled' => '1' === (string) get_option( 'agentic_local_memory_enabled', '0' ),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function data_apis(): array {
		$psi = (string) get_option( 'agentic_psi_api_key', '' );
		return array(
			'services' => array(
				array(
					'slug'       => 'google_psi',
					'name'       => 'Google PageSpeed Insights',
					'configured' => '' !== $psi,
					'hint'       => '' !== $psi ? '••••' . substr( $psi, -4 ) : '',
					'key_url'    => 'https://developers.google.com/speed/docs/insights/v5/get-started',
				),
			),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function data_endpoints(): array {
		return array(
			'rest_namespace' => 'agentic/v1',
			'rest_url'       => rest_url( 'agentic/v1/' ),
			'note'           => __( 'REST and webhook endpoints for integrations. Full editor remains available via Advanced tools.', 'agent-builder' ),
			'services'       => self::data_services_list(),
		);
	}

	/**
	 * Base URLs for the Agentic services this plugin depends on (chat, RAG,
	 * image/video/TTS generation, the marketplace API), so an admin can see
	 * and override them the same way LLM provider endpoints already are.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function data_services_list(): array {
		$rows = array();
		foreach ( Service_Registry::get_all() as $slug => $svc ) {
			$rows[] = array(
				'slug'        => $slug,
				'name'        => (string) ( $svc['name'] ?? $slug ),
				'description' => (string) ( $svc['description'] ?? '' ),
				'url'         => (string) ( $svc['url'] ?? '' ),
				'default_url' => (string) ( $svc['default_url'] ?? '' ),
				'is_custom'   => ! empty( $svc['is_custom'] ),
			);
		}
		return $rows;
	}

	/**
	 * Model Context Protocol status: each active agent's own MCP endpoint and
	 * readiness, connected clients, and the "Agent Builder Relay" credentials
	 * that authenticate them. MCP is free and unconditional — has_connector is
	 * informational only, not a gate on MCP access.
	 *
	 * @return array<string,mixed>
	 */
	private static function data_mcp(): array {
		$agents = array();
		if ( class_exists( '\\Agentic_Agent_Registry' ) && class_exists( '\\Agentic_Relay_Connect' ) ) {
			foreach ( \Agentic_Agent_Registry::get_instance()->get_all_instances() as $slug => $agent ) {
				$readiness      = \Agentic_Relay_Connect::mcp_readiness( $slug );
				$last_connected = \Agentic_Relay_Connect::get_last_connected( $slug );
				$agents[]       = array(
					'slug'           => $slug,
					'name'           => $agent->get_name(),
					'icon'           => $agent->get_icon(),
					'url'            => rest_url( 'agentic/' . $slug . '/mcp' ),
					'ready'          => $readiness['ready'],
					'reason'         => $readiness['reason'],
					'enabled'        => \Agentic_Relay_Connect::is_mcp_enabled( $slug ),
					'last_connected' => $last_connected
						? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_connected )
						: '',
				);
			}
		}

		$connectors = get_option( \Agentic_Relay_Connect::CONNECTORS_OPTION, array() );
		$connectors = is_array( $connectors ) ? array_values( $connectors ) : array();

		// {slug, label} rather than the raw slug — the same human-readable
		// label the approval and success screens show ("Claude (Anthropic)"),
		// so a connected provider reads the same way everywhere in the
		// connector flow instead of a third, differently-formatted display
		// ("anthropic", unformatted) unique to this one list.
		$connector_rows = array_map(
			static fn( $slug ) => array(
				'slug'  => $slug,
				'label' => \Agentic_Relay_Connect::provider_label( $slug ),
			),
			$connectors
		);

		return array(
			'rest_namespace'    => 'agentic/v1',
			'mcp_available'     => array(
				'is_pro'        => false,
				'has_connector' => ! empty( $connectors ),
				'can_use'       => true,
			),
			'agents'            => $agents,
			'connectors'        => $connector_rows,
			'credentials'       => self::data_mcp_credentials(),
			'unattended_writes' => self::data_mcp_unattended_writes(),
		);
	}

	/**
	 * Every MEDIUM-risk write action any currently active agent could
	 * perform via MCP, across every agent — shown before a new Application
	 * Password is created so that decision is actually informed. MCP has no
	 * per-call confirmation step the way chat and WebMCP do (see
	 * SUBMISSION-NOTES.md's MCP section): "per-agent scoping and per-tool
	 * risk filtering are what actually bound what a given credential can
	 * list or call" is the complete, deliberate model, not a partial one —
	 * creating this credential is itself the site owner's one confirmation.
	 * NONE/LOW-risk writes already execute without confirmation in every
	 * context (chat included, at the default autonomous-mode ceiling), so
	 * they're not the notable case; MEDIUM is the tier that would normally
	 * pause for an in-chat/in-page confirmation but does not here.
	 *
	 * Public (was private) — Agentic_Relay_Connect's own connector-approval
	 * screen shows the same disclosure before minting the identical
	 * credential via that flow, so both call sites share one source rather
	 * than risking two lists drifting apart.
	 *
	 * @return array<int, array{agent:string, tool:string, description:string}>
	 */
	public static function data_mcp_unattended_writes(): array {
		if ( ! class_exists( '\\Agentic_Agent_Registry' ) || ! class_exists( '\\Agentic_Relay_Connect' ) || ! class_exists( Abilities_Manifest::class ) ) {
			return array();
		}

		$rows = array();
		foreach ( \Agentic_Agent_Registry::get_instance()->get_all_instances() as $slug => $agent ) {
			$manifest = Abilities_Manifest::load( $slug );
			if ( ! $manifest || empty( $manifest['abilities'] ) || ! is_array( $manifest['abilities'] ) ) {
				continue;
			}

			foreach ( array_keys( $manifest['abilities'] ) as $tool_name ) {
				$tool = Tool_Loader::get_instance()->get( (string) $tool_name );
				if ( ! $tool || ! empty( $tool->get_annotations()['readonly'] ) ) {
					continue;
				}

				$risk = Abilities_Manifest::get_effective_risk( $slug, (string) $tool_name, $tool );
				if ( Risk_Level::MEDIUM !== $risk || ! \Agentic_Relay_Connect::is_tool_mcp_safe( (string) $tool_name, $slug, $tool ) ) {
					continue;
				}

				$rows[] = array(
					'agent'       => $agent->get_name(),
					'tool'        => (string) $tool_name,
					'description' => $tool->get_description(),
				);
			}
		}

		return $rows;
	}

	/**
	 * "Agent Builder Relay" Application Passwords across every administrator
	 * — the credential the relay connect flow (and the new manual-creation
	 * button on this tab) both mint, scattered per-user by WordPress core
	 * with no built-in cross-user listing, so gathered here for one place
	 * to see and revoke them.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function data_mcp_credentials(): array {
		if ( ! class_exists( 'WP_Application_Passwords' ) || ! class_exists( '\\Agentic_Relay_Connect' ) ) {
			return array();
		}

		$rows  = array();
		$users = get_users( array( 'role' => 'administrator' ) );
		foreach ( $users as $user ) {
			foreach ( \WP_Application_Passwords::get_user_application_passwords( $user->ID ) as $item ) {
				if ( ( $item['name'] ?? '' ) !== \Agentic_Relay_Connect::APP_PASS_NAME ) {
					continue;
				}
				$rows[] = array(
					'user_id'    => $user->ID,
					'user_login' => $user->user_login,
					'uuid'       => $item['uuid'],
					'created'   => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $item['created'] ),
					'last_used' => $item['last_used']
						? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $item['last_used'] )
						: __( 'Never', 'agent-builder' ),
				);
			}
		}
		return $rows;
	}

	// ── Savers ────────────────────────────────────────────────────────────

	/**
	 * Persist the site-wide Basic/Advanced default.
	 *
	 * Single writer for the `agentic_ui_mode` option. Dashboard REST
	 * (`set_ui_mode` action), Settings → Interface (`save_interface()`),
	 * the classic-PHP fallback (`UI_Settings_REST`), and the global header
	 * switch (`Admin_Pages_REST` `set_ui_mode` action) all call through
	 * here so there is exactly one `update_option( 'agentic_ui_mode', ... )`
	 * in the codebase. Option name and values (`basic` / `advanced`) are
	 * part of the Agent Builder Pro contract and must not change.
	 *
	 * Invalid values are rejected (no write), matching `save_interface()`
	 * and `/ui-settings`. Callers that historically coerced invalid input
	 * to `'basic'` (Dashboard) must do that before calling this method.
	 *
	 * @param string $mode 'basic' or 'advanced'.
	 * @param string $via  Optional audit-log source tag. Empty keeps the
	 *                     historical payload (Dashboard / Settings app);
	 *                     `'ui_settings_rest'` preserves the classic
	 *                     fallback's extra `via` field; `'global_header'`
	 *                     marks the shared-chrome switch.
	 * @return bool True if the value was accepted and written.
	 */
	public static function set_ui_mode( string $mode, string $via = '' ): bool {
		if ( ! in_array( $mode, array( 'basic', 'advanced' ), true ) ) {
			return false;
		}

		$prev = (string) get_option( 'agentic_ui_mode', 'basic' );
		update_option( 'agentic_ui_mode', $mode, false );
		if ( $prev !== $mode && class_exists( Audit_Log::class ) ) {
			$details = array(
				'id'   => $mode,
				'from' => $prev,
				'to'   => $mode,
			);
			if ( '' !== $via ) {
				$details['via'] = $via;
			}
			Audit_Log::log_admin( 'ui_mode_changed', 'settings', $details );
		}

		return true;
	}

	/**
	 * @param array<string,mixed> $data Data.
	 */
	private static function save_interface( array $data ): void {
		$address_changed = false;
		if ( isset( $data['admin_address'] ) ) {
			update_option( 'agentic_admin_address', mb_substr( sanitize_text_field( (string) $data['admin_address'] ), 0, 60 ) );
			$address_changed = true;
		}
		if ( isset( $data['frontend_address'] ) ) {
			update_option( 'agentic_frontend_address', mb_substr( sanitize_text_field( (string) $data['frontend_address'] ), 0, 60 ) );
			$address_changed = true;
		}

		if ( isset( $data['ui_mode'] ) ) {
			self::set_ui_mode( (string) $data['ui_mode'] );
		}
		if ( array_key_exists( 'show_onboarding', $data ) ) {
			update_option( 'agentic_show_onboarding', ! empty( $data['show_onboarding'] ) ? '1' : '0', false );
		}
		if ( isset( $data['global_font'] ) ) {
			$allow = Chat_Assets::global_font_allowlist();
			$font  = sanitize_text_field( (string) $data['global_font'] );
			if ( in_array( $font, $allow, true ) ) {
				update_option( 'agentic_global_font', $font, false );
			}
		}
		if ( ! empty( $data['use_theme_accent'] ) || ( isset( $data['global_accent'] ) && '' === $data['global_accent'] ) ) {
			update_option( 'agentic_global_accent', '', false );
		} elseif ( isset( $data['global_accent'] ) ) {
			$accent = sanitize_hex_color( (string) $data['global_accent'] );
			if ( $accent ) {
				update_option( 'agentic_global_accent', $accent, false );
			}
		}

		if ( isset( $data['chat_theme'] ) ) {
			$theme   = sanitize_key( (string) $data['chat_theme'] );
			$allowed = array( 'dark', 'light', 'midnight', 'ocean', 'auto' );
			if ( in_array( $theme, $allowed, true ) ) {
				update_option( 'agentic_chat_theme', $theme );
			}
		}

		// Addressing fields change system prompts — drop response cache.
		if ( $address_changed && class_exists( Response_Cache::class ) ) {
			Response_Cache::clear_all();
		}
	}

	/**
	 * @param array<string,mixed> $data Data.
	 */
	/**
	 * @param array<string,mixed> $data Data.
	 */
	private static function save_security( array $data ): void {
		if ( isset( $data['default_agent_mode'] ) ) {
			$mode = sanitize_key( (string) $data['default_agent_mode'] );
			if ( in_array( $mode, array( 'autonomous', 'supervised', 'readonly' ), true ) ) {
				$prev = (string) get_option( 'agentic_default_agent_mode', 'supervised' );
				update_option( 'agentic_default_agent_mode', $mode );
				if ( $prev !== $mode && class_exists( Audit_Log::class ) ) {
					Audit_Log::log_admin(
						'default_agent_mode_changed',
						'settings',
						array(
							'id'   => $mode,
							'from' => $prev,
							'to'   => $mode,
						)
					);
				}
			}
		}
		if ( array_key_exists( 'message_scanning', $data ) ) {
			update_option( 'agentic_message_scanning', ! empty( $data['message_scanning'] ) );
		}
		if ( array_key_exists( 'chat_consent_enabled', $data ) ) {
			update_option( 'agentic_chat_consent_enabled', ! empty( $data['chat_consent_enabled'] ) );
		}
		if ( isset( $data['chat_consent_text'] ) ) {
			update_option( 'agentic_chat_consent_text', sanitize_textarea_field( (string) $data['chat_consent_text'] ) );
		}
		if ( isset( $data['retention_conversations'] ) ) {
			update_option( 'agentic_retention_conversations', max( 0, absint( $data['retention_conversations'] ) ) );
		}
		if ( isset( $data['retention_audit_log'] ) ) {
			update_option( 'agentic_retention_audit_log', max( 0, absint( $data['retention_audit_log'] ) ) );
		}
		if ( isset( $data['rate_limit_authenticated'] ) ) {
			update_option( 'agentic_rate_limit_authenticated', max( 1, absint( $data['rate_limit_authenticated'] ) ) );
		}
		if ( isset( $data['rate_limit_anonymous'] ) ) {
			update_option( 'agentic_rate_limit_anonymous', max( 1, absint( $data['rate_limit_anonymous'] ) ) );
		}
		if ( array_key_exists( 'allow_platform_sync', $data ) ) {
			update_option( 'agentic_allow_platform_sync', ! empty( $data['allow_platform_sync'] ) ? '1' : '0' );
		}
	}

	/**
	 * @param array<string,mixed> $data Data.
	 */
	private static function save_users( array $data ): void {
		if ( array_key_exists( 'allow_anonymous_chat', $data ) ) {
			update_option( 'agentic_allow_anonymous_chat', ! empty( $data['allow_anonymous_chat'] ) ? 1 : 0 );
		}
		if ( ! empty( $data['role_settings'] ) && is_array( $data['role_settings'] ) && class_exists( User_Roles::class ) ) {
			User_Roles::save_settings( $data['role_settings'] );
		}
		if ( ! empty( $data['usage_limits'] ) && is_array( $data['usage_limits'] ) && class_exists( Usage_Limits::class ) ) {
			Usage_Limits::save_limits( $data['usage_limits'] );
		}
	}

	/**
	 * @param array<string,mixed> $data Data.
	 */
	private static function save_memory( array $data ): void {
		if ( isset( $data['ttl_days'] ) ) {
			update_option( 'agentic_memory_ttl_days', max( 0, absint( $data['ttl_days'] ) ) );
		}
		if ( array_key_exists( 'local_memory_enabled', $data ) ) {
			update_option( 'agentic_local_memory_enabled', ! empty( $data['local_memory_enabled'] ) ? '1' : '0' );
		}
	}

	/**
	 * Save admin overrides for the Agentic services' base URLs.
	 *
	 * @param array<string,mixed> $data Data — expects a 'services' array of {slug, url}.
	 * @return void
	 */
	private static function save_endpoints( array $data ): void {
		$services = is_array( $data['services'] ?? null ) ? $data['services'] : array();
		foreach ( $services as $row ) {
			if ( ! is_array( $row ) || empty( $row['slug'] ) ) {
				continue;
			}
			$slug = sanitize_key( (string) $row['slug'] );
			if ( ! in_array( $slug, Service_Registry::get_slugs(), true ) ) {
				continue; // Unknown/custom slug — services are a fixed built-in set.
			}
			$before = Service_Registry::url( $slug );
			// Service_Registry::update() already treats an empty string as
			// "reset to default"; sanitize_text_field() alone would mangle a
			// URL (stripping slashes-as-tags edge cases), so use esc_url_raw.
			$url = isset( $row['url'] ) ? esc_url_raw( trim( (string) $row['url'] ) ) : '';
			Service_Registry::update( $slug, $url );

			// Every outbound AI/media/marketplace call this plugin makes goes
			// through one of these URLs, so a redirected endpoint is a real
			// security event — log it specifically (with before/after), not
			// just as a generic "settings_changed" line with no detail.
			$after = Service_Registry::url( $slug );
			if ( $after !== $before && class_exists( Audit_Log::class ) ) {
				Audit_Log::log_admin(
					'endpoint_url_changed',
					$slug,
					array(
						'service'  => $slug,
						'previous' => $before,
						'new'      => $after,
					)
				);
			}
		}
	}

	/**
	 * @param array<string,mixed> $data Data.
	 * @return string[] Warnings from any side effect (e.g. emergency-stop restore failures).
	 */
	private static function save_agents( array $data ): array {
		$warnings = array();

		if ( isset( $data['global_provider'] ) ) {
			$slug = sanitize_key( (string) $data['global_provider'] );
			if ( Provider_Registry::is_valid( $slug ) ) {
				update_option( 'agentic_llm_provider', $slug );
			}
		}
		if ( isset( $data['global_model'] ) ) {
			update_option( 'agentic_model', sanitize_text_field( (string) $data['global_model'] ) );
		}
		if ( ! empty( $data['agents'] ) && is_array( $data['agents'] ) ) {
			foreach ( $data['agents'] as $row ) {
				if ( ! is_array( $row ) || empty( $row['slug'] ) ) {
					continue;
				}
				$slug = sanitize_key( (string) $row['slug'] );
				foreach ( array( 'override_provider', 'override_model', 'override_mode', 'override_audio', 'override_tts', 'override_vision' ) as $key ) {
					if ( array_key_exists( $key, $row ) ) {
						Agent_Settings::update( $slug, $key, sanitize_text_field( (string) $row[ $key ] ) );
					}
				}
			}
		}
		if ( array_key_exists( 'disable_all_agents', $data ) ) {
			$want = ! empty( $data['disable_all_agents'] );
			if ( $want && ! Emergency_Stop::is_active() ) {
				Emergency_Stop::enable();
			} elseif ( ! $want && Emergency_Stop::is_active() ) {
				$warnings = Emergency_Stop::disable()['warnings'] ?? array();
			}
		}
		foreach ( array( 'chat_audio', 'chat_tts', 'chat_vision', 'chat_whitelabel' ) as $key ) {
			if ( array_key_exists( $key, $data ) ) {
				update_option( 'agentic_' . $key, ! empty( $data[ $key ] ) ? '1' : '0' );
			}
		}
		if ( array_key_exists( 'show_whatsapp_cta', $data ) ) {
			update_option( 'agentic_show_whatsapp_cta', ! empty( $data['show_whatsapp_cta'] ) ? '1' : '0' );
		}

		return $warnings;
	}

	/**
	 * @param array<string,mixed> $data Data.
	 */
	private static function save_instructions( array $data ): void {
		if ( empty( $data['agents'] ) || ! is_array( $data['agents'] ) ) {
			return;
		}
		foreach ( $data['agents'] as $row ) {
			if ( ! is_array( $row ) || empty( $row['slug'] ) ) {
				continue;
			}
			$slug = sanitize_key( (string) $row['slug'] );
			if ( isset( $row['welcome_message'] ) ) {
				Agent_Settings::update( $slug, 'persona_welcome_message', sanitize_textarea_field( (string) $row['welcome_message'] ) );
			}
			if ( isset( $row['notes'] ) ) {
				Agent_Settings::update( $slug, 'persona_notes', sanitize_textarea_field( (string) $row['notes'] ) );
			}
			if ( isset( $row['response_style'] ) ) {
				Agent_Settings::update( $slug, 'persona_response_style', sanitize_text_field( (string) $row['response_style'] ) );
			}
		}
	}
}
