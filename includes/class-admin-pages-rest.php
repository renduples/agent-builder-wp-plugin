<?php
/**
 * Admin pages REST — bootstrap + actions for React admin surfaces
 * (tools, skills list, approvals, activity logs, deployment, upgrade,
 * safety center).
 *
 * Agents list intentionally stays PHP (WordPress plugins-style UI later).
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
 * REST for non-settings admin React pages.
 */
class Admin_Pages_REST {

	/**
	 * Boot.
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		// Plain admin-post handler (not REST) so the browser can trigger a
		// native file download via GET navigation — no fetch/blob dance,
		// no REST nonce-header requirement.
		add_action( 'admin_post_agentic_export_logs', array( __CLASS__, 'export_logs' ) );
		add_action( 'admin_post_agentic_export_skill', array( __CLASS__, 'export_skill' ) );
		add_action( 'admin_post_agentic_import_skill', array( __CLASS__, 'import_skill' ) );
	}

	/**
	 * Routes.
	 */
	public static function register_routes(): void {
		register_rest_route(
			'agentic/v1',
			'/admin-page',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get_page' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
					'args'                => array(
						'page' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_key',
						),
						'tab'  => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_key',
						),
					),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'post_action' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
				),
			)
		);
	}

	/**
	 * Permission check for the shared admin-page REST endpoint.
	 *
	 * This single endpoint serves several distinct React admin surfaces
	 * (tools, skills, approvals, logs, deployment, train-data, upgrade-pro),
	 * each already gated behind its own `agentic_*` capability at the
	 * wp-admin menu level (see Admin_Menu_Handler::register()). The
	 * capability required here must match that per-page grant, otherwise a
	 * role granted e.g. `agentic_manage_tools` can see the menu item and
	 * the empty page shell but every data request 403s.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool
	 */
	public static function can_manage( \WP_REST_Request $request ): bool {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		$page   = sanitize_key( (string) $request->get_param( 'page' ) );
		$action = sanitize_key( (string) $request->get_param( 'action_name' ) );

		$tools_actions  = array( 'toggle_tool', 'apply_tools_profile', 'delete_skill' );
		$agents_actions = array( 'save_approval_prefs', 'approval_decide', 'approval_decide_bulk' );

		if ( 'tools' === $page || 'skills' === $page || in_array( $action, $tools_actions, true ) ) {
			return current_user_can( 'agentic_manage_tools' );
		}

		if ( 'approvals' === $page || 'deployment' === $page || in_array( $action, $agents_actions, true ) ) {
			return current_user_can( 'agentic_manage_agents' );
		}

		if ( 'logs' === $page ) {
			return current_user_can( 'agentic_view_audit_log' );
		}

		if ( 'agent-ready' === $page || in_array( $action, array( 'apply_free_fix', 'confirm_agent_ready_proposal', 'toggle_webmcp_expose', 'submit_to_directory' ), true ) ) {
			// submit_to_directory is the one deliberate phone-home this feature
			// makes — require manage_options explicitly rather than the page's
			// normal agentic_manage_settings, even though the current_user_can(
			// 'manage_options' ) short-circuit above already covers the common
			// case; this keeps the requirement legible if that short-circuit is
			// ever narrowed.
			return 'submit_to_directory' === $action
				? current_user_can( 'manage_options' )
				: current_user_can( 'agentic_manage_settings' );
		}

		// Site-wide Basic/Advanced default — same cap as the Dashboard
		// Interface Settings card / Settings → Interface (the other
		// callers of Admin_Settings_REST::set_ui_mode()).
		if ( 'set_ui_mode' === $action ) {
			return current_user_can( 'agentic_manage_settings' );
		}

		// set_screen_mode is a personal, per-user preference for one screen —
		// require whatever capability that screen itself already requires,
		// so setting it never grants more than reading the screen already
		// does (and reset_screen_modes only touches the current user's own
		// overrides, so any of these are a safe minimum).
		if ( 'set_screen_mode' === $action || 'reset_screen_modes' === $action ) {
			$screen = sanitize_key( (string) $request->get_param( 'screen' ) );
			if ( in_array( $screen, array( 'tools', 'skills' ), true ) ) {
				return current_user_can( 'agentic_manage_tools' );
			}
			if ( in_array( $screen, array( 'approvals', 'deployment', 'agents' ), true ) ) {
				return current_user_can( 'agentic_manage_agents' );
			}
			if ( 'logs' === $screen ) {
				return current_user_can( 'agentic_view_audit_log' );
			}
			return current_user_can( 'agentic_manage_settings' );
		}

		// train-data, upgrade-pro, safety-center, and anything unmapped stay
		// behind the broadest admin-settings privilege as a safe default.
		return current_user_can( 'agentic_manage_settings' );
	}

	/**
	 * GET page payload.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get_page( \WP_REST_Request $request ) {
		$page = sanitize_key( (string) $request->get_param( 'page' ) );
		$tab  = sanitize_key( (string) $request->get_param( 'tab' ) );

		switch ( $page ) {
			case 'tools':
				return new \WP_REST_Response( self::tools_payload( $tab ?: 'all' ), 200 );
			case 'skills':
				return new \WP_REST_Response( self::skills_payload(), 200 );
			case 'approvals':
				return new \WP_REST_Response( self::approvals_payload( $tab ?: 'approvals' ), 200 );
			case 'logs':
				$period = sanitize_key( (string) $request->get_param( 'period' ) );
				if ( ! in_array( $period, array( 'day', 'week', 'month' ), true ) ) {
					$period = 'week';
				}
				return new \WP_REST_Response( self::logs_payload( $tab ?: 'audit', $period ), 200 );
			case 'deployment':
				return new \WP_REST_Response( self::deployment_payload(), 200 );
			case 'upgrade-pro':
				return new \WP_REST_Response( self::upgrade_payload(), 200 );
			case 'train-data':
				return new \WP_REST_Response( self::train_payload( $tab ?: 'wiki' ), 200 );
			case 'agent-ready':
				return new \WP_REST_Response( self::agent_ready_payload(), 200 );
			case 'safety-center':
				return new \WP_REST_Response( self::safety_center_payload(), 200 );
			default:
				return new \WP_Error( 'unknown_page', __( 'Unknown admin page.', 'agent-builder' ), array( 'status' => 404 ) );
		}
	}

	/**
	 * POST action.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function post_action( \WP_REST_Request $request ) {
		$action = sanitize_key( (string) $request->get_param( 'action_name' ) );

		if ( 'toggle_tool' === $action ) {
			$name    = sanitize_text_field( (string) $request->get_param( 'name' ) );
			$enabled = rest_sanitize_boolean( $request->get_param( 'enabled' ) );
			if ( '' === $name || ! class_exists( Tools_Registry::class ) ) {
				return new \WP_Error( 'invalid', __( 'Invalid tool.', 'agent-builder' ), array( 'status' => 400 ) );
			}
			if ( $enabled && class_exists( Risk_Level::class ) ) {
				$risk = Risk_Level::max(
					Tools_Registry::get_risk_level( $name ),
					Risk_Level::get_tool_default( $name )
				);
				if ( Risk_Level::EXTREME === $risk ) {
					return new \WP_Error(
						'extreme_blocked',
						__( 'This tool cannot be enabled', 'agent-builder' ),
						array( 'status' => 403 )
					);
				}
			}
			$ok = Tools_Registry::set_enabled( $name, $enabled );
			// Manual toggle leaves basic profile as custom.
			update_option( 'agentic_tools_ability_profile', 'custom', false );
			if ( $ok && class_exists( Audit_Log::class ) ) {
				Audit_Log::log_admin(
					$enabled ? 'tool_enabled' : 'tool_disabled',
					'tool',
					array(
						'id'      => $name,
						'name'    => $name,
						'enabled' => $enabled,
					)
				);
			}
			if ( class_exists( Security_Log::class ) ) {
				Security_Log::log_system( $enabled ? 'tool_enabled' : 'tool_disabled', $name );
			}
			return new \WP_REST_Response(
				array(
					'ok'      => (bool) $ok,
					'name'    => $name,
					'enabled' => $enabled,
				),
				$ok ? 200 : 500
			);
		}

		if ( 'apply_tools_profile' === $action ) {
			$profile_id = sanitize_key( (string) $request->get_param( 'profile' ) );
			$profiles   = self::tools_ability_profiles();
			if ( ! isset( $profiles[ $profile_id ] ) || 'custom' === $profile_id ) {
				return new \WP_Error( 'invalid_profile', __( 'Unknown ability profile.', 'agent-builder' ), array( 'status' => 400 ) );
			}
			if ( ! class_exists( Tools_Registry::class ) ) {
				return new \WP_Error( 'unavailable', __( 'Tools registry unavailable.', 'agent-builder' ), array( 'status' => 500 ) );
			}
			$max    = (string) $profiles[ $profile_id ]['max_risk'];
			$result = Tools_Registry::apply_max_risk_level( $max );
			update_option( 'agentic_tools_ability_profile', $profile_id, false );
			if ( class_exists( Audit_Log::class ) ) {
				Audit_Log::log_admin(
					'tools_profile_applied',
					'tools',
					array(
						'id'       => $profile_id,
						'profile'  => $profile_id,
						'max_risk' => $max,
						'enabled'  => $result['enabled'] ?? 0,
						'disabled' => $result['disabled'] ?? 0,
					),
					(string) ( $profiles[ $profile_id ]['label'] ?? $profile_id )
				);
			}
			if ( class_exists( Security_Log::class ) ) {
				Security_Log::log_system(
					'tools_profile_applied',
					'tools',
					array(
						'profile'  => $profile_id,
						'max_risk' => $max,
					)
				);
			}
			return new \WP_REST_Response(
				array(
					'ok'      => true,
					'profile' => $profile_id,
					'result'  => $result,
				),
				200
			);
		}

		if ( 'save_approval_prefs' === $action ) {
			return self::save_approval_prefs( $request );
		}

		if ( 'approval_decide' === $action ) {
			return self::approval_decide( $request );
		}

		if ( 'approval_decide_bulk' === $action ) {
			return self::approval_decide_bulk( $request );
		}

		if ( 'delete_skill' === $action ) {
			$id = absint( $request->get_param( 'id' ) );
			if ( $id < 1 || ! class_exists( Skills_Registry::class ) ) {
				return new \WP_Error( 'invalid', __( 'Invalid skill.', 'agent-builder' ), array( 'status' => 400 ) );
			}
			Skills_Registry::delete( $id );
			return new \WP_REST_Response(
				array(
					'ok' => true,
					'id' => $id,
				),
				200
			);
		}

		if ( 'set_ui_mode' === $action ) {
			$mode = sanitize_key( (string) $request->get_param( 'mode' ) );
			if ( ! in_array( $mode, array( 'basic', 'advanced' ), true ) ) {
				return new \WP_Error( 'invalid', __( 'Invalid mode.', 'agent-builder' ), array( 'status' => 400 ) );
			}
			Admin_Settings_REST::set_ui_mode( $mode, 'global_header' );
			return new \WP_REST_Response(
				array(
					'ok'   => true,
					'mode' => $mode,
				),
				200
			);
		}

		if ( 'set_screen_mode' === $action ) {
			$screen = sanitize_key( (string) $request->get_param( 'screen' ) );
			$mode   = sanitize_key( (string) $request->get_param( 'mode' ) );
			if ( '' === $screen || ! in_array( $mode, array( 'basic', 'advanced' ), true ) ) {
				return new \WP_Error( 'invalid', __( 'Invalid screen or mode.', 'agent-builder' ), array( 'status' => 400 ) );
			}
			Admin_Menu_Handler::set_screen_mode( $screen, $mode );
			return new \WP_REST_Response(
				array(
					'ok'     => true,
					'screen' => $screen,
					'mode'   => $mode,
				),
				200
			);
		}

		if ( 'reset_screen_modes' === $action ) {
			Admin_Menu_Handler::reset_screen_modes();
			return new \WP_REST_Response( array( 'ok' => true ), 200 );
		}

		if ( 'test_provider' === $action ) {
			return self::test_provider( $request );
		}

		if ( 'apply_free_fix' === $action ) {
			return self::apply_free_fix( $request );
		}

		if ( 'confirm_agent_ready_proposal' === $action ) {
			$proposal_id = sanitize_text_field( (string) $request->get_param( 'proposal_id' ) );
			if ( '' === $proposal_id || ! class_exists( Agent_Proposals::class ) ) {
				return new \WP_Error( 'invalid', __( 'Invalid proposal.', 'agent-builder' ), array( 'status' => 400 ) );
			}
			return new \WP_REST_Response( Agent_Proposals::approve( $proposal_id ), 200 );
		}

		if ( 'toggle_webmcp_expose' === $action ) {
			return self::toggle_webmcp_expose( $request );
		}

		if ( 'submit_to_directory' === $action ) {
			if ( ! class_exists( Directory_Submission::class ) ) {
				return new \WP_Error( 'unavailable', __( 'Directory submission is unavailable.', 'agent-builder' ), array( 'status' => 500 ) );
			}
			return new \WP_REST_Response( Directory_Submission::submit(), 200 );
		}

		if ( 'set_emergency_stop' === $action ) {
			return self::set_emergency_stop( $request );
		}

		return new \WP_Error( 'unknown_action', __( 'Unknown action.', 'agent-builder' ), array( 'status' => 400 ) );
	}

	/**
	 * Run one of the Agent-Ready Score's free fix tools through the same
	 * risk-gating Tool_Executor uses everywhere else — the pseudo agent_id
	 * carries no abilities.json, so effective risk resolves purely from the
	 * fix tool's own get_risk_level() override (always LOW), which the
	 * autonomous mode ceiling auto-approves in one click.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private static function apply_free_fix( \WP_REST_Request $request ) {
		$tool_name = sanitize_key( (string) $request->get_param( 'tool_name' ) );
		$allowed   = array( 'resign_agent_manifest', 'enable_webmcp_defaults', 'configure_approval_gate', 'enable_agent_readiness' );
		if ( ! in_array( $tool_name, $allowed, true ) ) {
			return new \WP_Error( 'invalid', __( 'Unknown fix.', 'agent-builder' ), array( 'status' => 400 ) );
		}

		$arguments = (array) $request->get_param( 'arguments' );
		$executor  = new Tool_Executor( Tool_Loader::get_instance(), new Audit_Log() );
		$result    = $executor->execute( $tool_name, $arguments, 'agent-ready-score', 'autonomous', 'admin_action' );

		return new \WP_REST_Response(
			array(
				'result' => $result,
				'score'  => Agent_Ready_Score::rescan(),
			),
			200
		);
	}

	/**
	 * Advanced-mode per-tool webmcp_expose toggle.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private static function toggle_webmcp_expose( \WP_REST_Request $request ) {
		$agent_slug = sanitize_key( (string) $request->get_param( 'agent_slug' ) );
		$tool_name  = sanitize_key( (string) $request->get_param( 'tool_name' ) );
		$expose     = rest_sanitize_boolean( $request->get_param( 'expose' ) );

		$manifest = Abilities_Manifest::load( $agent_slug );
		$path     = Abilities_Manifest::resolve_path( $agent_slug );
		if ( ! $manifest || ! $path || ! isset( $manifest['abilities'][ $tool_name ] ) ) {
			return new \WP_Error( 'invalid', __( 'Unknown agent or tool.', 'agent-builder' ), array( 'status' => 400 ) );
		}

		if ( $expose ) {
			// Effective risk, not the raw manifest field — a manifest can
			// under-declare a tool's risk (or omit it), but the tool's own
			// intrinsic floor (Risk_Level::BASELINE_RISKS, e.g.
			// manage_user_privileges => HIGH) always wins via max(). Trusting
			// the raw field here would let a mis-declared or missing risk
			// slip a HIGH/EXTREME-floor tool past this check.
			$tool_instance = Tool_Loader::get_instance()->get( $tool_name );
			$risk          = Abilities_Manifest::get_effective_risk( $agent_slug, $tool_name, $tool_instance );
			if ( Risk_Level::weight( $risk ) > Risk_Level::weight( Risk_Level::MEDIUM )
				|| ! Webmcp_Bridge::is_tool_webmcp_safe( $tool_name, $agent_slug )
			) {
				return new \WP_Error( 'unsafe_risk', __( 'This tool\'s risk is too high to expose to WebMCP.', 'agent-builder' ), array( 'status' => 400 ) );
			}
		}

		$manifest['abilities'][ $tool_name ]['webmcp_expose'] = $expose;
		if ( $expose && empty( $manifest['abilities'][ $tool_name ]['webmcp_context'] ) ) {
			$manifest['abilities'][ $tool_name ]['webmcp_context'] = 'both';
		}

		if ( ! wp_is_writable( $path ) ) {
			return new \WP_Error( 'not_writable', __( 'This agent\'s manifest file is not writable.', 'agent-builder' ), array( 'status' => 500 ) );
		}

		file_put_contents( $path, wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Editing an agent's own bundled manifest file from a settings action; WP_Filesystem is not bootstrapped on this REST request path.
		Abilities_Manifest::clear_cache( $agent_slug );
		Abilities_Manifest::save_integrity_hash( $agent_slug );

		return new \WP_REST_Response(
			array(
				'ok'    => true,
				'score' => Agent_Ready_Score::rescan(),
			),
			200
		);
	}

	/**
	 * Test connectivity for an already-configured provider.
	 *
	 * Reuses the same request-building helpers as Rest_Api::test_api_key()
	 * (the setup-wizard "test before saving" flow), but reads the already
	 * stored, decrypted API key server-side instead of requiring the browser
	 * to hold it, and resolves the model against the provider actually being
	 * tested — not the site's active-default model — since the two can
	 * differ for any provider that isn't the current default. Endpoint
	 * templates that embed the model (e.g. Google's %MODEL%) would otherwise
	 * silently point at a model the tested provider doesn't have.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	private static function test_provider( \WP_REST_Request $request ): \WP_REST_Response {
		$slug = sanitize_key( (string) $request->get_param( 'slug' ) );
		$p    = class_exists( Provider_Registry::class ) ? Provider_Registry::get( $slug ) : null;

		if ( ! $p ) {
			return new \WP_REST_Response(
				array(
					'ok'      => false,
					'message' => __( 'Unknown provider.', 'agent-builder' ),
				),
				404
			);
		}

		$api_key       = (string) ( $p['api_key'] ?? '' );
		$is_keyless    = in_array( $slug, array( 'ollama', 'agentic' ), true );
		if ( ! $is_keyless && ! empty( $p['requires_key'] ) && empty( $api_key ) ) {
			return new \WP_REST_Response(
				array(
					'ok'      => false,
					'message' => __( 'No API key configured for this provider.', 'agent-builder' ),
				),
				200
			);
		}

		$model = (string) ( $p['default_model'] ?? '' );
		if ( $slug === get_option( 'agentic_llm_provider', '' ) ) {
			$site_model = (string) get_option( 'agentic_model', '' );
			if ( '' !== $site_model ) {
				$model = $site_model;
			}
		}

		if ( $is_keyless ) {
			$url = 'ollama' === $slug
				? rtrim( get_option( 'agentic_ollama_url', 'http://localhost:11434' ), '/' ) . '/api/tags'
				: Service_Registry::url( 'agentic-chat', '/health' );
			$response = wp_remote_get( $url, array( 'timeout' => 10 ) );
		} else {
			$llm      = new LLM_Client();
			$endpoint = Provider_Registry::resolve_endpoint( (string) ( $p['endpoint'] ?? '' ), $model, $api_key );
			$response = wp_remote_post(
				$endpoint,
				array(
					'timeout' => 15,
					'headers' => $llm->get_headers_for_provider( $slug, $api_key ),
					'body'    => wp_json_encode(
						$llm->format_request_for_provider(
							$slug,
							array(
								array(
									'role'    => 'user',
									'content' => 'Hello, please respond with OK.',
								),
							),
							$model
						)
					),
				)
			);
		}

		if ( is_wp_error( $response ) ) {
			return new \WP_REST_Response(
				array(
					'ok'      => false,
					'message' => $response->get_error_message(),
				),
				200
			);
		}

		$status = wp_remote_retrieve_response_code( $response );
		if ( $status >= 200 && $status < 300 ) {
			return new \WP_REST_Response(
				array(
					'ok'      => true,
					'message' => __( 'Connected successfully.', 'agent-builder' ),
				),
				200
			);
		}

		$body      = json_decode( wp_remote_retrieve_body( $response ), true );
		$error_msg = $body['error']['message'] ?? $body['error'] ?? sprintf(
			/* translators: %d: HTTP status code. */
			__( 'Connection failed (HTTP %d).', 'agent-builder' ),
			$status
		);
		if ( is_array( $error_msg ) ) {
			$error_msg = wp_json_encode( $error_msg );
		}

		return new \WP_REST_Response(
			array(
				'ok'      => false,
				'message' => (string) $error_msg,
			),
			200
		);
	}

	/**
	 * Known tool category slugs → admin labels.
	 *
	 * @return array<string, string>
	 */
	private static function tool_category_labels(): array {
		return array(
			'all'                     => __( 'All', 'agent-builder' ),
			'agents'                  => __( 'Agents', 'agent-builder' ),
			'ai-visibility'           => __( 'AI Visibility', 'agent-builder' ),
			'analytics'               => __( 'Analytics', 'agent-builder' ),
			'assistant-trainer'       => __( 'Agents', 'agent-builder' ),
			'caching'                 => __( 'Caching', 'agent-builder' ),
			'cli'                     => __( 'CLI', 'agent-builder' ),
			'communication'           => __( 'Communication', 'agent-builder' ),
			'content'                 => __( 'Content', 'agent-builder' ),
			'crm'                     => __( 'CRM', 'agent-builder' ),
			'database'                => __( 'Database', 'agent-builder' ),
			'dataforseo'              => __( 'DataForSEO', 'agent-builder' ),
			'ecommerce'               => __( 'Ecommerce', 'agent-builder' ),
			'email'                   => __( 'Email', 'agent-builder' ),
			'files'                   => __( 'Files', 'agent-builder' ),
			'forms'                   => __( 'Forms', 'agent-builder' ),
			'gbp'                     => __( 'Google Business', 'agent-builder' ),
			'git'                     => __( 'Git', 'agent-builder' ),
			'google-search-marketing' => __( 'Search Marketing', 'agent-builder' ),
			'google-workspace'        => __( 'Google Workspace', 'agent-builder' ),
			'maintenance'             => __( 'Maintenance', 'agent-builder' ),
			'media'                   => __( 'Media', 'agent-builder' ),
			'orchestration'           => __( 'Orchestration', 'agent-builder' ),
			'plugins'                 => __( 'Plugins', 'agent-builder' ),
			'security'                => __( 'Security', 'agent-builder' ),
			'seo'                     => __( 'SEO', 'agent-builder' ),
			'site-audit'              => __( 'Site Audit', 'agent-builder' ),
			'site-health'             => __( 'Site Health', 'agent-builder' ),
			'themes'                  => __( 'Themes', 'agent-builder' ),
			'users'                   => __( 'Users', 'agent-builder' ),
			'utility'                 => __( 'Utility', 'agent-builder' ),
			'web'                     => __( 'Web', 'agent-builder' ),
			'wordpress'               => __( 'WordPress', 'agent-builder' ),
		);
	}

	/**
	 * Human label for a tool category slug.
	 *
	 * @param string $slug Category slug.
	 * @return string
	 */
	private static function tool_category_label( string $slug ): string {
		$slug  = strtolower( $slug );
		$known = self::tool_category_labels();
		if ( isset( $known[ $slug ] ) ) {
			return $known[ $slug ];
		}

		return ucwords( str_replace( array( '-', '_' ), ' ', $slug ) );
	}

	/**
	 * Normalize category slug for grouping (e.g. WordPress → WordPress).
	 *
	 * @param string $category Raw category.
	 * @return string
	 */
	private static function normalize_tool_category( string $category ): string {
		$category = strtolower( sanitize_key( $category ) );
		if ( '' === $category ) {
			return 'general';
		}
		// Collapse synonym.
		if ( 'agents' === $category ) {
			return 'assistant-trainer';
		}

		return $category;
	}

	/**
	 * @param string $tab Active category tab (all | category slug).
	 * @return array<string,mixed>
	 */
	private static function tools_payload( string $tab = 'all' ): array {
		// Keep registry in sync (same as tools.php).
		if ( class_exists( Tool_Loader::class ) ) {
			Tool_Loader::get_instance()->sync_to_registry();
			Tool_Loader::get_instance()->load();
		}
		if ( class_exists( Tools_Registry::class ) ) {
			Tools_Registry::seed_core_tools(
				array(
					'db_update_option' => 'database',
					'db_create_post'   => 'database',
					'db_update_post'   => 'database',
					'db_delete_post'   => 'database',
					'run_wp_cli'       => 'cli',
				)
			);
		}

		// Also sync tools claimed by active agents (category + presence).
		if ( class_exists( '\Agentic_Agent_Registry' ) && class_exists( Tool_Loader::class ) && class_exists( Tools_Registry::class ) ) {
			$loader    = Tool_Loader::get_instance();
			$instances = \Agentic_Agent_Registry::get_instance()->get_all_instances();
			foreach ( $instances as $agent ) {
				$names = $agent->get_tool_names();
				$defs  = $loader->get_definitions_for( $names );
				Tools_Registry::sync_agent_tools( $agent->get_id(), $defs );
			}
		}

		$tools = class_exists( Tools_Registry::class ) ? Tools_Registry::get_all() : array();
		$tab   = self::normalize_tool_category( $tab );
		if ( 'general' === $tab ) {
			$tab = 'all';
		}

		// Counts per normalized category.
		$counts   = array( 'all' => 0 );
		$rows_all = array();
		foreach ( $tools as $tool ) {
			$name = (string) ( $tool['name'] ?? '' );
			if ( '' === $name ) {
				continue;
			}
			$cat = self::normalize_tool_category( (string) ( $tool['category'] ?? 'general' ) );
			if ( ! isset( $counts[ $cat ] ) ) {
				$counts[ $cat ] = 0;
			}
			++$counts[ $cat ];
			++$counts['all'];

			$rows_all[] = array(
				'id'             => $name,
				'title'          => $name,
				'subtitle'       => (string) ( $tool['description'] ?? '' ),
				'category'       => $cat,
				'category_label' => self::tool_category_label( $cat ),
				'enabled'        => ! empty( $tool['enabled'] ),
				'source'         => (string) ( $tool['source'] ?? 'core' ),
				'risk_level'     => (string) ( $tool['risk_level'] ?? '' ),
			);
		}

		// Build tabs: All first, then categories A–Z.
		$cat_slugs = array_keys( $counts );
		$cat_slugs = array_values(
			array_filter(
				$cat_slugs,
				static function ( $slug ) use ( $counts ) {
					return 'all' !== $slug && ( $counts[ $slug ] ?? 0 ) > 0;
				}
			)
		);
		usort(
			$cat_slugs,
			static function ( $a, $b ) {
				return strcasecmp( self::tool_category_label( $a ), self::tool_category_label( $b ) );
			}
		);

		if ( 'all' !== $tab && ! in_array( $tab, $cat_slugs, true ) ) {
			$tab = 'all';
		}

		$tabs   = array();
		$tabs[] = array(
			'id'    => 'all',
			'label' => sprintf(
				/* translators: %d: tool count */
				__( 'All (%d)', 'agent-builder' ),
				(int) ( $counts['all'] ?? 0 )
			),
			'url'   => admin_url( 'admin.php?page=agentic-tools&tab=all' ),
		);
		foreach ( $cat_slugs as $slug ) {
			$tabs[] = array(
				'id'    => $slug,
				'label' => sprintf(
					'%s (%d)',
					self::tool_category_label( $slug ),
					(int) $counts[ $slug ]
				),
				'url'   => admin_url( 'admin.php?page=agentic-tools&tab=' . rawurlencode( $slug ) ),
			);
		}

		$rows = $rows_all;
		if ( 'all' !== $tab ) {
			$rows = array_values(
				array_filter(
					$rows_all,
					static function ( $row ) use ( $tab ) {
						return ( $row['category'] ?? '' ) === $tab;
					}
				)
			);
		}

		$panel_title = 'all' === $tab
			? __( 'All tools', 'agent-builder' )
			: self::tool_category_label( $tab );

		$is_advanced = class_exists( Admin_Menu_Handler::class )
			? Admin_Menu_Handler::is_advanced_mode( 'tools' )
			: ( 'advanced' === get_option( 'agentic_ui_mode', 'basic' ) );

		$enabled_count  = 0;
		$disabled_count = 0;
		foreach ( $rows_all as $row ) {
			if ( ! empty( $row['enabled'] ) ) {
				++$enabled_count;
			} else {
				++$disabled_count;
			}
		}

		$active_profile = self::resolve_active_tools_profile();
		$profile_cards  = array();
		foreach ( self::tools_ability_profiles() as $id => $p ) {
			if ( 'custom' === $id ) {
				continue;
			}
			$profile_cards[] = array(
				'id'       => $id,
				'label'    => $p['label'],
				'summary'  => $p['summary'],
				'detail'   => $p['detail'],
				'max_risk' => $p['max_risk'],
				'icon'     => $p['icon'],
				'active'   => $id === $active_profile,
			);
		}

		return array(
			'page'             => 'tools',
			'tab'              => $tab,
			'title'            => __( 'Tools', 'agent-builder' ),
			'panel_title'      => $is_advanced
				? $panel_title
				: __( 'What may agents do?', 'agent-builder' ),
			'description'      => $is_advanced
				? __( 'Enable or disable tools agents can use. Group by category using the tabs.', 'agent-builder' )
				: __( 'Pick a simple safety profile. We turn tools on or off to match — no need to manage hundreds of tools one by one.', 'agent-builder' ),
			'rows'             => $rows,
			'tabs'             => $tabs,
			'counts'           => $counts,
			'is_advanced'      => $is_advanced,
			'ui_mode'          => $is_advanced ? 'advanced' : 'basic',
			'interface_url'    => admin_url( 'admin.php?page=agentic-settings&tab=interface' ),
			'active_profile'   => $active_profile,
			'profiles'         => $profile_cards,
			'enabled_count'    => $enabled_count,
			'disabled_count'   => $disabled_count,
			'enabled_max_risk' => class_exists( Tools_Registry::class )
				? Tools_Registry::enabled_max_risk_level()
				: 'none',
			'docs_url'         => 'https://agentic-plugin.com/agent-tools/',
			'footer_policy'    => __(
				'Agents only use the tools you allow. Higher-risk actions still follow Approvals and your safety settings. Provider processing of chat content is covered by our Privacy Policy.',
				'agent-builder'
			),
		);
	}

	/**
	 * Named ability profiles for Basic Tools UI.
	 *
	 * @return array<string, array{label:string,summary:string,detail:string,max_risk:string,icon:string}>
	 */
	private static function tools_ability_profiles(): array {
		return array(
			'browse' => array(
				'label'    => __( 'Browse & answer', 'agent-builder' ),
				'summary'  => __( 'Safest — read-only help', 'agent-builder' ),
				'detail'   => __( 'Agents can look things up and answer questions. They cannot change posts, settings, or your site.', 'agent-builder' ),
				'max_risk' => 'low',
				'icon'     => '👀',
			),
			'assist' => array(
				'label'    => __( 'Help with drafts', 'agent-builder' ),
				'summary'  => __( 'Balanced — create drafts with care', 'agent-builder' ),
				'detail'   => __( 'Read plus everyday writing (drafts and light edits). Riskier changes still ask for confirmation.', 'agent-builder' ),
				'max_risk' => 'medium',
				'icon'     => '✍️',
			),
			'manage' => array(
				'label'    => __( 'Manage my site', 'agent-builder' ),
				'summary'  => __( 'Full productivity — approvals for big changes', 'agent-builder' ),
				'detail'   => __( 'Most tools on, including significant updates. High-risk actions go through the Approvals queue. Extreme tools stay off.', 'agent-builder' ),
				'max_risk' => 'high',
				'icon'     => '🛠️',
			),
			'custom' => array(
				'label'    => __( 'Custom mix', 'agent-builder' ),
				'summary'  => __( 'You mixed tools manually', 'agent-builder' ),
				'detail'   => __( 'Individual tools were toggled outside a profile.', 'agent-builder' ),
				'max_risk' => 'high',
				'icon'     => '⚙️',
			),
		);
	}

	/**
	 * Active profile id (stored or inferred).
	 *
	 * @return string
	 */
	private static function resolve_active_tools_profile(): string {
		$stored   = sanitize_key( (string) get_option( 'agentic_tools_ability_profile', '' ) );
		$profiles = self::tools_ability_profiles();
		if ( $stored && isset( $profiles[ $stored ] ) && 'custom' !== $stored ) {
			return $stored;
		}
		if ( 'custom' === $stored ) {
			return 'custom';
		}
		// Infer from current max enabled risk.
		if ( ! class_exists( Tools_Registry::class ) ) {
			return 'browse';
		}
		$max = Tools_Registry::enabled_max_risk_level();
		$w   = Risk_Level::weight( $max );
		if ( $w <= Risk_Level::weight( Risk_Level::LOW ) ) {
			return 'browse';
		}
		if ( $w <= Risk_Level::weight( Risk_Level::MEDIUM ) ) {
			return 'assist';
		}
		return 'manage';
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function skills_payload(): array {
		$is_advanced = class_exists( Admin_Menu_Handler::class )
			? Admin_Menu_Handler::is_advanced_mode( 'skills' )
			: ( 'advanced' === get_option( 'agentic_ui_mode', 'basic' ) );

		$source_labels = array(
			'core'      => __( 'Core', 'agent-builder' ),
			'agentic'   => __( 'Agentic', 'agent-builder' ),
			'clawhub'   => __( 'ClawHub', 'agent-builder' ),
			'wordpress' => __( 'WordPress.org', 'agent-builder' ),
			'anthropic' => __( 'Anthropic', 'agent-builder' ),
		);

		// Map agent slugs to their display names for the Agent column, same
		// as the classic admin/skills.php list did, so an assigned skill
		// shows "Content Writer" rather than the raw "content-writer" slug.
		$agent_instances = class_exists( '\Agentic_Agent_Registry' )
			? \Agentic_Agent_Registry::get_instance()->get_all_instances()
			: array();

		$skills = class_exists( Skills_Registry::class ) ? Skills_Registry::get_all() : array();
		$rows   = array();
		foreach ( $skills as $skill ) {
			$id          = (int) ( $skill['id'] ?? 0 );
			$source      = (string) ( $skill['source'] ?? 'local' );
			$agent_slugs = Skills_Registry::decode_agent_slugs( (string) ( $skill['agent_slug'] ?? '' ) );
			$agent_names = array_map(
				static function ( $slug ) use ( $agent_instances ) {
					$agent = $agent_instances[ $slug ] ?? null;
					return $agent ? $agent->get_name() : ucwords( str_replace( '-', ' ', $slug ) );
				},
				$agent_slugs
			);
			$row         = array(
				'id'        => (string) $id,
				'title'     => (string) ( $skill['name'] ?? '' ),
				'subtitle'  => (string) ( $skill['description'] ?? '' ),
				'agent'     => implode( ', ', $agent_names ),
				'enabled'   => ! empty( $skill['enabled'] ),
				'version'   => (string) ( $skill['version'] ?? '' ),
				'edit_url'  => admin_url( 'admin.php?page=agentic-skills&skill_view=edit&skill_id=' . $id ),
				'delete_id' => $id,
			);
			// Source/version detail and export are Advanced-only, matching the
			// classic Skills admin page's Basic/Advanced split.
			if ( $is_advanced ) {
				$row['source']       = $source;
				$row['source_label'] = $source_labels[ $source ] ?? __( 'Local', 'agent-builder' );
				$row['export_url']   = wp_nonce_url( admin_url( 'admin-post.php?action=agentic_export_skill&skill_id=' . $id ), 'agentic_export_skill' );
			}
			$rows[] = $row;
		}

		// In Basic mode the React view swaps to a chat with the bundled Skills
		// Assistant instead of the table — resolve its display details here so
		// the client doesn't need a second round-trip just to render a header.
		$assistant = null;
		if ( ! $is_advanced ) {
			$instance  = \Agentic_Agent_Registry::get_instance()->get_agent_instance( 'skills-assistant' );
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
			'page'        => 'skills',
			'title'       => __( 'Skills', 'agent-builder' ),
			'description' => __( 'Instructions that teach agents when and how to use tools.', 'agent-builder' ),
			'rows'        => $rows,
			'is_advanced' => $is_advanced,
			'assistant'   => $assistant,
			'actions'     => array(
				array(
					'label'   => __( 'Create Skill', 'agent-builder' ),
					'url'     => admin_url( 'admin.php?page=agentic-skills&skill_view=new' ),
					'primary' => true,
				),
				array(
					'label' => __( 'Browse Community', 'agent-builder' ),
					'url'   => admin_url( 'admin.php?page=agentic-skills&skill_view=hub' ),
				),
			),
		);
	}

	/**
	 * @param string $tab Tab.
	 * @return array<string,mixed>
	 */
	private static function approvals_payload( string $tab ): array {
		$queue   = new Approval_Queue();
		$pending = $queue->get_pending();
		$rows    = array();
		foreach ( $pending as $item ) {
			$action = (string) ( $item['tool_name'] ?? $item['action'] ?? 'action' );
			$params = $item['params'] ?? $item['data'] ?? array();
			if ( is_string( $params ) ) {
				$decoded = json_decode( $params, true );
				$params  = is_array( $decoded ) ? $decoded : array();
			}
			$summary = '';
			if ( ! empty( $item['reasoning'] ) ) {
				$summary = (string) $item['reasoning'];
			} elseif ( ! empty( $params['file_path'] ) ) {
				$summary = (string) $params['file_path'];
			} elseif ( ! empty( $params['title'] ) ) {
				$summary = (string) $params['title'];
			} else {
				$summary = wp_json_encode( $params );
			}

			$rows[] = array(
				'id'         => (string) ( $item['id'] ?? '' ),
				'title'      => str_replace( '_', ' ', $action ),
				'action'     => $action,
				'subtitle'   => (string) ( $item['agent_id'] ?? '' ),
				'risk_level' => (string) ( $item['risk_level'] ?? 'high' ),
				'created_at' => (string) ( $item['created_at'] ?? '' ),
				'summary'    => $summary,
			);
		}

		$is_advanced = class_exists( Admin_Menu_Handler::class )
			? Admin_Menu_Handler::is_advanced_mode( 'approvals' )
			: ( 'advanced' === get_option( 'agentic_ui_mode', 'basic' ) );

		$prefs = self::get_approval_prefs();

		return array(
			'page'             => 'approvals',
			'tab'              => $tab,
			'title'            => __( 'Approvals', 'agent-builder' ),
			'panel_title'      => __( 'Things waiting for your OK', 'agent-builder' ),
			'description'      => __( 'When an agent wants to change something important, it waits here until you approve or reject it.', 'agent-builder' ),
			'pending_count'    => count( $rows ),
			'rows'             => $rows,
			'groups'           => self::group_pending_for_bulk( $rows ),
			'tabs'             => array(
				array(
					'id'    => 'approvals',
					'label' => __( 'Approvals', 'agent-builder' ),
					'url'   => admin_url( 'admin.php?page=agentic-approvals&tab=approvals' ),
				),
				array(
					'id'    => 'backups',
					'label' => __( 'Backups', 'agent-builder' ),
					'url'   => admin_url( 'admin.php?page=agentic-approvals&tab=backups' ),
				),
			),
			'agent_mode'       => (string) get_option( 'agentic_agent_mode', 'supervised' ),
			'is_advanced'      => $is_advanced,
			'interface_url'    => admin_url( 'admin.php?page=agentic-settings&tab=interface' ),
			'prefs'            => $prefs,
			'comfort_profiles' => self::approval_comfort_profiles(),
			'docs_url'         => 'https://agentic-plugin.com/approval-queue/',
			'footer_policy'    => __(
				'Approvals keep high-risk agent actions under human control. Email alerts use your admin address and never include passwords. See Privacy Policy for how providers process chat.',
				'agent-builder'
			),
		);
	}

	/**
	 * Comfort profiles for non-technical Approvals preferences.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function approval_comfort_profiles(): array {
		$active = sanitize_key( (string) get_option( 'agentic_approval_comfort', 'careful' ) );
		$cards  = array(
			array(
				'id'        => 'careful',
				'icon'      => '🛡️',
				'label'     => __( 'Always ask me', 'agent-builder' ),
				'summary'   => __( 'Safest default', 'agent-builder' ),
				'detail'    => __( 'Important or writing actions wait for you. Best when you want full control.', 'agent-builder' ),
				'risk_note' => __( 'No automatic approvals beyond the safest reads.', 'agent-builder' ),
				'auto_max'  => 'none',
				'mode'      => 'supervised',
				'needs_ack' => false,
			),
			array(
				'id'        => 'balanced',
				'icon'      => '⚖️',
				'label'     => __( 'Auto-approve low risk', 'agent-builder' ),
				'summary'   => __( 'Recommended for most sites', 'agent-builder' ),
				'detail'    => __( 'Simple look-ups run freely. Drafts and bigger changes still pause for confirmation or this queue.', 'agent-builder' ),
				'risk_note' => __( 'You accept that low-risk tools may run without a separate approval email.', 'agent-builder' ),
				'auto_max'  => 'low',
				'mode'      => 'supervised',
				'needs_ack' => false,
			),
			array(
				'id'        => 'hands_off',
				'icon'      => '⚡',
				'label'     => __( 'Trust more (higher risk)', 'agent-builder' ),
				'summary'   => __( 'Faster — use with care', 'agent-builder' ),
				'detail'    => __( 'Agents work with less interruption (autonomous mode). You can still review history. Extreme tools stay blocked.', 'agent-builder' ),
				'risk_note' => __( 'I understand agents may change content without waiting in this queue, and I accept that increased risk.', 'agent-builder' ),
				'auto_max'  => 'medium',
				'mode'      => 'autonomous',
				'needs_ack' => true,
			),
		);
		foreach ( $cards as &$c ) {
			$c['active'] = ( $c['id'] === $active );
		}
		unset( $c );
		return $cards;
	}

	/**
	 * Current approval notification / comfort prefs.
	 *
	 * @return array<string, mixed>
	 */
	private static function get_approval_prefs(): array {
		$email = sanitize_email( (string) get_option( 'agentic_approval_email_to', '' ) );
		if ( ! is_email( $email ) ) {
			$email = (string) get_option( 'admin_email' );
		}
		return array(
			'email_notify'  => (bool) get_option( 'agentic_approval_email_notify', false ),
			'email_to'      => $email,
			'comfort'       => sanitize_key( (string) get_option( 'agentic_approval_comfort', 'careful' ) ),
			'auto_max_risk' => sanitize_key( (string) get_option( 'agentic_approval_auto_max_risk', 'none' ) ),
			'risk_ack'      => (bool) get_option( 'agentic_approval_risk_ack', false ),
			'agent_mode'    => (string) get_option( 'agentic_agent_mode', 'supervised' ),
		);
	}

	/**
	 * Save Approvals preferences from React UI.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private static function save_approval_prefs( \WP_REST_Request $request ) {
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'agentic_manage_agents' ) ) {
			return new \WP_Error( 'forbidden', __( 'Permission denied.', 'agent-builder' ), array( 'status' => 403 ) );
		}

		$email_notify = rest_sanitize_boolean( $request->get_param( 'email_notify' ) );
		$email_to     = sanitize_email( (string) $request->get_param( 'email_to' ) );
		$comfort      = sanitize_key( (string) $request->get_param( 'comfort' ) );
		$risk_ack     = rest_sanitize_boolean( $request->get_param( 'risk_ack' ) );

		$profiles = array();
		foreach ( self::approval_comfort_profiles() as $p ) {
			$profiles[ $p['id'] ] = $p;
		}
		if ( ! isset( $profiles[ $comfort ] ) ) {
			$comfort = 'careful';
		}

		if ( ! empty( $profiles[ $comfort ]['needs_ack'] ) && ! $risk_ack ) {
			return new \WP_Error(
				'ack_required',
				__( 'Please confirm you accept the increased risk for “Trust more”.', 'agent-builder' ),
				array( 'status' => 400 )
			);
		}

		if ( $email_notify && $email_to && ! is_email( $email_to ) ) {
			return new \WP_Error( 'invalid_email', __( 'Enter a valid email address.', 'agent-builder' ), array( 'status' => 400 ) );
		}

		$prev = self::get_approval_prefs();

		update_option( 'agentic_approval_email_notify', $email_notify ? 1 : 0, false );
		if ( is_email( $email_to ) ) {
			update_option( 'agentic_approval_email_to', $email_to, false );
		}

		$auto_max = (string) ( $profiles[ $comfort ]['auto_max'] ?? 'none' );
		$mode     = (string) ( $profiles[ $comfort ]['mode'] ?? 'supervised' );

		update_option( 'agentic_approval_comfort', $comfort, false );
		update_option( 'agentic_approval_auto_max_risk', $auto_max, false );
		update_option( 'agentic_agent_mode', $mode, false );
		update_option( 'agentic_approval_risk_ack', ( ! empty( $profiles[ $comfort ]['needs_ack'] ) && $risk_ack ) ? 1 : 0, false );

		if ( class_exists( Audit_Log::class ) ) {
			Audit_Log::log_admin(
				'approval_prefs_saved',
				'approvals',
				array(
					'id'           => $comfort,
					'comfort'      => $comfort,
					'auto_max'     => $auto_max,
					'agent_mode'   => $mode,
					'email_notify' => $email_notify,
					'previous'     => $prev,
				)
			);
		}
		if ( class_exists( Security_Log::class ) ) {
			Security_Log::log_system(
				'approval_prefs_saved',
				'approvals',
				array(
					'comfort'    => $comfort,
					'agent_mode' => $mode,
					'auto_max'   => $auto_max,
				)
			);
		}

		return new \WP_REST_Response(
			array(
				'ok'    => true,
				'prefs' => self::get_approval_prefs(),
			),
			200
		);
	}

	/**
	 * Approve or reject a queue item from the admin UI.
	 *
	 * Prefer the dedicated REST route which also executes on approve; this
	 * action is a thin fallback for the React admin-page POST channel.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private static function approval_decide( \WP_REST_Request $request ) {
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'agentic_manage_agents' ) ) {
			return new \WP_Error( 'forbidden', __( 'Permission denied.', 'agent-builder' ), array( 'status' => 403 ) );
		}
		$id     = absint( $request->get_param( 'id' ) );
		$decide = sanitize_key( (string) $request->get_param( 'decide' ) );
		if ( $id < 1 || ! in_array( $decide, array( 'approve', 'reject' ), true ) ) {
			return new \WP_Error( 'invalid', __( 'Invalid approval request.', 'agent-builder' ), array( 'status' => 400 ) );
		}

		// Dispatch through the main REST approval handler so approve also runs the tool.
		$rest_req = new \WP_REST_Request( 'POST', '/agentic/v1/approvals/' . $id );
		$rest_req->set_param( 'id', $id );
		$rest_req->set_param( 'action', $decide );
		$response = rest_do_request( $rest_req );
		if ( $response->is_error() ) {
			return $response->as_error();
		}

		// Flatten nested api_success envelope for the React admin UI.
		$body = $response->get_data();
		$core = is_array( $body ) ? $body : array();
		if ( isset( $core['data'] ) && is_array( $core['data'] ) ) {
			$core = $core['data'];
		}

		return new \WP_REST_Response(
			array_merge(
				array(
					'ok'     => true,
					'id'     => $id,
					'decide' => $decide,
				),
				is_array( $core ) ? $core : array()
			),
			200
		);
	}

	/**
	 * Approve or reject several queue items in one call — the React
	 * Approvals page's "Approve all" / "Reject all" group actions.
	 *
	 * Rejecting has no ordering requirement, so items run in the order
	 * given and every item is attempted regardless of earlier failures.
	 * Approving stops at the first failure instead: a later item in the
	 * same batch (e.g. a step that edits files a scaffold step just
	 * created) may depend on an earlier one having actually run, and nothing
	 * in Approval_Queue enforces that server-side — see
	 * order_ids_for_bulk_decide() below, which is the only thing standing
	 * between "approve all" and running steps out of order.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private static function approval_decide_bulk( \WP_REST_Request $request ) {
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'agentic_manage_agents' ) ) {
			return new \WP_Error( 'forbidden', __( 'Permission denied.', 'agent-builder' ), array( 'status' => 403 ) );
		}

		$ids = array_map( 'absint', (array) $request->get_param( 'ids' ) );
		$ids = array_values( array_unique( array_filter( $ids ) ) );
		$decide = sanitize_key( (string) $request->get_param( 'decide' ) );

		if ( empty( $ids ) || ! in_array( $decide, array( 'approve', 'reject' ), true ) ) {
			return new \WP_Error( 'invalid', __( 'Invalid approval request.', 'agent-builder' ), array( 'status' => 400 ) );
		}

		// Re-derive a safe order server-side rather than trusting whatever
		// order the client sent — cheap, and the one thing that actually
		// prevents "approve all" from running a dependent step first.
		$ids = self::order_ids_for_bulk_decide( $ids );

		$results       = array();
		$succeeded     = 0;
		$failed        = 0;
		$stopped_early = false;

		foreach ( $ids as $id ) {
			$rest_req = new \WP_REST_Request( 'POST', '/agentic/v1/approvals/' . $id );
			$rest_req->set_param( 'id', $id );
			$rest_req->set_param( 'action', $decide );
			$response = rest_do_request( $rest_req );
			$is_error = $response->is_error();
			$body     = $response->get_data();
			$core     = is_array( $body ) ? $body : array();
			if ( isset( $core['data'] ) && is_array( $core['data'] ) ) {
				$core = $core['data'];
			}

			$results[] = array(
				'id'      => $id,
				'ok'      => ! $is_error,
				'message' => (string) ( $core['message'] ?? ( $is_error ? $response->as_error()->get_error_message() : '' ) ),
			);

			if ( $is_error ) {
				++$failed;
				if ( 'approve' === $decide ) {
					// Stop the chain — don't approve a step that likely
					// depends on the one that just failed.
					$stopped_early = true;
					break;
				}
			} else {
				++$succeeded;
			}
		}

		return new \WP_REST_Response(
			array(
				'ok'            => 0 === $failed,
				'decide'        => $decide,
				'results'       => $results,
				'succeeded'     => $succeeded,
				'failed'        => $failed,
				'stopped_early' => $stopped_early,
			),
			200
		);
	}

	/**
	 * Order a set of pending-approval ids the same way the classic batch
	 * queue did: group members that look like scaffolding (creating the
	 * files/plugin a later step will edit) go first, then ascending id.
	 * Ids no longer pending are appended at the tail so approval_decide_bulk
	 * still surfaces an "already processed" style error for them instead of
	 * silently dropping them.
	 *
	 * @param array<int> $ids Requested ids.
	 * @return array<int>
	 */
	private static function order_ids_for_bulk_decide( array $ids ): array {
		$wanted = array_flip( $ids );
		$queue  = new Approval_Queue();
		$rows   = $queue->get_pending();

		$scaffold_actions = array( 'create_plugin_scaffold', 'create_agent_files' );
		$matched          = array();
		foreach ( $rows as $row ) {
			if ( isset( $wanted[ (int) ( $row['id'] ?? 0 ) ] ) ) {
				$matched[] = $row;
			}
		}

		usort(
			$matched,
			function ( $a, $b ) use ( $scaffold_actions ) {
				$a_action   = (string) ( $a['tool_name'] ?? $a['action'] ?? '' );
				$b_action   = (string) ( $b['tool_name'] ?? $b['action'] ?? '' );
				$a_scaffold = in_array( $a_action, $scaffold_actions, true ) ? 0 : 1;
				$b_scaffold = in_array( $b_action, $scaffold_actions, true ) ? 0 : 1;
				if ( $a_scaffold !== $b_scaffold ) {
					return $a_scaffold - $b_scaffold;
				}
				return (int) $a['id'] - (int) $b['id'];
			}
		);

		$ordered = array_map( fn( $row ) => (int) $row['id'], $matched );
		$missing = array_values( array_diff( $ids, $ordered ) );

		return array_merge( $ordered, $missing );
	}

	/**
	 * Group approvals_payload() rows by requesting agent + a 120s time
	 * window, same rule the classic batch queue used, so items from one
	 * agent "burst" (e.g. a scaffold step plus the edits that follow it) are
	 * offered together for "Approve all" / "Reject all" instead of one
	 * unrelated pending item from a different request being bundled in.
	 *
	 * @param array<int, array<string, mixed>> $rows Rows as built by approvals_payload().
	 * @return array<int, array<string, mixed>>
	 */
	private static function group_pending_for_bulk( array $rows ): array {
		$scaffold_actions = array( 'create_plugin_scaffold', 'create_agent_files' );
		$groups           = array();

		foreach ( $rows as $row ) {
			$agent = (string) ( $row['subtitle'] ?? '' );
			$ts    = strtotime( ( (string) ( $row['created_at'] ?? '' ) ) . ' UTC' );
			$ts    = false !== $ts ? $ts : time();
			$key   = null;

			foreach ( $groups as $k => $g ) {
				if ( $g['agent_id'] === $agent && abs( $g['last_ts'] - $ts ) < 120 ) {
					$key = $k;
					break;
				}
			}

			if ( null === $key ) {
				$key            = count( $groups );
				$groups[ $key ] = array(
					'agent_id' => $agent,
					'first_ts' => $ts,
					'last_ts'  => $ts,
					'items'    => array(),
				);
			}

			$groups[ $key ]['items'][] = $row;
			$groups[ $key ]['last_ts'] = $ts;
		}

		$out = array();
		foreach ( $groups as $g ) {
			usort(
				$g['items'],
				function ( $a, $b ) use ( $scaffold_actions ) {
					$a_scaffold = in_array( $a['action'], $scaffold_actions, true ) ? 0 : 1;
					$b_scaffold = in_array( $b['action'], $scaffold_actions, true ) ? 0 : 1;
					if ( $a_scaffold !== $b_scaffold ) {
						return $a_scaffold - $b_scaffold;
					}
					return (int) $a['id'] - (int) $b['id'];
				}
			);

			$out[] = array(
				'agent_id' => $g['agent_id'],
				'count'    => count( $g['items'] ),
				'time_ago' => human_time_diff( $g['first_ts'] ),
				'ids'      => array_map( fn( $r ) => (string) $r['id'], $g['items'] ),
			);
		}

		return $out;
	}

	/**
	 * @param string $tab Tab.
	 * @return array<string,mixed>
	 */
	private static function logs_payload( string $tab, string $period = 'week' ): array {
		$rows          = array();
		$stats         = array(
			'total'     => 0,
			'chats'     => 0,
			'tools'     => 0,
			'approvals' => 0,
			'security'  => 0,
			'tokens'    => 0,
		);
		$is_advanced   = class_exists( Admin_Menu_Handler::class )
			? Admin_Menu_Handler::is_advanced_mode( 'logs' )
			: ( 'advanced' === get_option( 'agentic_ui_mode', 'basic' ) );
		$period_limits = array(
			'day'   => 200,
			'week'  => 500,
			'month' => 1500,
		);
		$limit         = $period_limits[ $period ] ?? 500;

		$integrity = null;
		if ( 'audit' === $tab && class_exists( Audit_Log_Integrity::class ) ) {
			// Only computed for the tab that actually shows this table — walks
			// every row with no chunking, so it's deliberately not run on every
			// Activity page load regardless of which tab is open.
			$integrity = Audit_Log_Integrity::verify_chain();
		}

		if ( 'audit' === $tab && class_exists( Audit_Log::class ) ) {
			$log = new Audit_Log();
			// Hide noisy chat_start/complete by default (same as classic audit page).
			$exclude = array( 'chat_start', 'chat_complete' );
			$items   = $log->get_recent( $limit, null, null, $period, $exclude );
			foreach ( (array) $items as $item ) {
				$row    = self::humanize_audit_row( is_array( $item ) ? $item : (array) $item );
				$rows[] = $row;
				++$stats['total'];
				$kind = $row['kind'] ?? 'other';
				if ( 'chat' === $kind ) {
					++$stats['chats'];
				} elseif ( 'tool' === $kind ) {
					++$stats['tools'];
				} elseif ( 'approval' === $kind ) {
					++$stats['approvals'];
				} elseif ( 'security' === $kind ) {
					++$stats['security'];
				}
				$stats['tokens'] += (int) ( $row['tokens'] ?? 0 );
			}
		} elseif ( 'conversations' === $tab ) {
			global $wpdb;
			$table = $wpdb->prefix . 'agentic_conversations';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( $exists ) {
				$days = match ( $period ) {
					'week'  => 7,
					'month' => 30,
					default => 1,
				};
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; %i quotes table name.
				$items = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT id, agent_id, user_id, updated_at, created_at FROM %i
						WHERE updated_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
						ORDER BY updated_at DESC LIMIT %d',
						$table,
						$days,
						$limit
					),
					ARRAY_A
				);
				foreach ( (array) $items as $item ) {
					$agent  = (string) ( $item['agent_id'] ?? '' );
					$user   = absint( $item['user_id'] ?? 0 );
					$uname  = $user ? ( get_userdata( $user )->display_name ?? ( 'User #' . $user ) ) : __( 'Guest', 'agent-builder' );
					$rows[] = array(
						'id'         => (string) ( $item['id'] ?? '' ),
						'title'      => sprintf(
							/* translators: 1: agent name, 2: conversation id */
							__( 'Chat with %1$s (#%2$s)', 'agent-builder' ),
							self::friendly_agent_name( $agent ),
							(string) ( $item['id'] ?? '' )
						),
						'subtitle'   => $uname,
						'when'       => (string) ( $item['updated_at'] ?? $item['created_at'] ?? '' ),
						'when_human' => self::human_time_label( (string) ( $item['updated_at'] ?? $item['created_at'] ?? '' ) ),
						'detail'     => '',
						'kind'       => 'chat',
						'icon'       => '💬',
						'raw_action' => 'conversation',
						'tokens'     => 0,
					);
					++$stats['total'];
					++$stats['chats'];
				}
			}
		} elseif ( 'security' === $tab && class_exists( Security_Log::class ) ) {
			global $wpdb;
			$table = $wpdb->prefix . 'agentic_security_log';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( $exists ) {
				$days = match ( $period ) {
					'week'  => 7,
					'month' => 30,
					default => 1,
				};
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; %i quotes table name.
				$items = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT * FROM %i
						WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
						ORDER BY id DESC LIMIT %d',
						$table,
						$days,
						$limit
					),
					ARRAY_A
				);
				foreach ( (array) $items as $item ) {
					$event  = (string) ( $item['event'] ?? $item['action'] ?? 'security' );
					$rows[] = array(
						'id'         => (string) ( $item['id'] ?? '' ),
						'title'      => self::friendly_security_title( $event ),
						'subtitle'   => (string) ( $item['context'] ?? $item['source'] ?? '' ),
						'when'       => (string) ( $item['created_at'] ?? '' ),
						'when_human' => self::human_time_label( (string) ( $item['created_at'] ?? '' ) ),
						'detail'     => self::friendly_detail_string( (string) ( $item['message'] ?? $item['details'] ?? '' ) ),
						'kind'       => 'security',
						'icon'       => '🔒',
						'raw_action' => $event,
						'tokens'     => 0,
					);
					++$stats['total'];
					++$stats['security'];
				}
			}
		}

		$period_labels = array(
			'day'   => __( 'Today', 'agent-builder' ),
			'week'  => __( 'Last 7 days', 'agent-builder' ),
			'month' => __( 'Last 30 days', 'agent-builder' ),
		);

		return array(
			'page'           => 'logs',
			'tab'            => $tab,
			'title'          => __( 'Activity', 'agent-builder' ),
			'panel_title'    => match ( $tab ) {
				'conversations' => __( 'Recent conversations', 'agent-builder' ),
				'security'      => __( 'Security events', 'agent-builder' ),
				default         => __( 'What your agents have been doing', 'agent-builder' ),
			},
			'description'    => match ( $tab ) {
				'conversations' => __( 'Chats between people and agents on this site.', 'agent-builder' ),
				'security'      => __( 'Security-related events. Most sites stay quiet here.', 'agent-builder' ),
				default         => __( 'A simple timeline of agent work — tools used, approvals, and important system events. No technical jargon required.', 'agent-builder' ),
			},
			'rows'           => $rows,
			'stats'          => $stats,
			'period'         => $period,
			'period_options' => array(
				array(
					'id'    => 'day',
					'label' => $period_labels['day'],
				),
				array(
					'id'    => 'week',
					'label' => $period_labels['week'],
				),
				array(
					'id'    => 'month',
					'label' => $period_labels['month'],
				),
			),
			'kind_filters'   => array(
				array(
					'id'    => 'all',
					'label' => __( 'All', 'agent-builder' ),
				),
				array(
					'id'    => 'tool',
					'label' => __( 'Tools used', 'agent-builder' ),
				),
				array(
					'id'    => 'approval',
					'label' => __( 'Approvals', 'agent-builder' ),
				),
				array(
					'id'    => 'chat',
					'label' => __( 'Chats', 'agent-builder' ),
				),
				array(
					'id'    => 'settings',
					'label' => __( 'Settings', 'agent-builder' ),
				),
				array(
					'id'    => 'other',
					'label' => __( 'Other', 'agent-builder' ),
				),
			),
			'is_advanced'    => $is_advanced,
			'integrity'      => $integrity,
			'interface_url'  => admin_url( 'admin.php?page=agentic-settings&tab=interface' ),
			// Built by hand (not wp_nonce_url()): that helper HTML-entity-escapes
			// the "&" separators for embedding in server-rendered HTML, but this
			// URL is consumed as-is by React as a real href — entity-escaped
			// "&amp;" would reach the browser literally, mangling every param
			// after the first (including _wpnonce, which would then 403).
			'export_url'     => admin_url(
				'admin-post.php?action=agentic_export_logs&tab=' . rawurlencode( $tab )
				. '&period=' . rawurlencode( $period )
				. '&_wpnonce=' . wp_create_nonce( 'agentic_export_logs' )
			),
			'docs_url'       => 'https://agentic-plugin.com/audit-log/',
			'footer_policy'  => __(
				'Activity helps you understand what agents did on your site. Logs are stored locally and purged according to your retention settings. Chat content lives under Conversations.',
				'agent-builder'
			),
			'tabs'           => array(
				array(
					'id'    => 'audit',
					'label' => __( 'Timeline', 'agent-builder' ),
					'url'   => admin_url( 'admin.php?page=agentic-audit-log&tab=audit&period=' . rawurlencode( $period ) ),
				),
				array(
					'id'    => 'conversations',
					'label' => __( 'Conversations', 'agent-builder' ),
					'url'   => admin_url( 'admin.php?page=agentic-audit-log&tab=conversations&period=' . rawurlencode( $period ) ),
				),
				array(
					'id'    => 'security',
					'label' => __( 'Security', 'agent-builder' ),
					'url'   => admin_url( 'admin.php?page=agentic-audit-log&tab=security&period=' . rawurlencode( $period ) ),
				),
			),
		);
	}

	/**
	 * Download the current Logs view (Timeline/Conversations/Security) as a
	 * CSV file — e.g. to attach to a support email when diagnosing an issue.
	 * Plain admin-post handler (not REST) so a simple GET navigation
	 * triggers a native browser download; gated the same way the Logs page
	 * itself is (agentic_view_audit_log), plus a nonce since this both reads
	 * potentially sensitive data and is reachable via direct URL.
	 *
	 * @return void
	 */
	public static function export_logs(): void {
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'agentic_export_logs' ) ) {
			wp_die( esc_html__( 'Security check failed. Please reload the Activity page and try exporting again.', 'agent-builder' ), 403 );
		}
		if ( ! current_user_can( 'agentic_view_audit_log' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to export logs.', 'agent-builder' ), 403 );
		}

		$tab    = sanitize_key( (string) ( $_GET['tab'] ?? 'audit' ) );
		$period = sanitize_key( (string) ( $_GET['period'] ?? 'week' ) );
		if ( ! in_array( $tab, array( 'audit', 'conversations', 'security' ), true ) ) {
			$tab = 'audit';
		}
		if ( ! in_array( $period, array( 'day', 'week', 'month' ), true ) ) {
			$period = 'week';
		}

		$payload = self::logs_payload( $tab, $period );
		$rows    = is_array( $payload['rows'] ?? null ) ? $payload['rows'] : array();

		$filename = sprintf(
			'agent-builder-%s-log-%s.csv',
			$tab,
			gmdate( 'Y-m-d-His' )
		);

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Direct CSV stream to browser, not a filesystem write.
		// PHP 8.3+ deprecates relying on fputcsv()'s implicit $escape default;
		// pass it explicitly (backslash matches the historical default) so a
		// stray "Deprecated:" notice can never leak into the CSV body if this
		// site has error display on.
		fputcsv( $out, array( 'Date (UTC)', 'Kind', 'Actor', 'Action', 'Summary', 'Detail', 'Tokens', 'Cost (USD)' ), ',', '"', '\\' );
		foreach ( $rows as $row ) {
			fputcsv(
				$out,
				array(
					(string) ( $row['when'] ?? '' ),
					(string) ( $row['kind'] ?? '' ),
					(string) ( $row['subtitle'] ?? '' ),
					(string) ( $row['raw_action'] ?? '' ),
					(string) ( $row['title'] ?? '' ),
					(string) ( $row['detail'] ?? '' ),
					(string) ( $row['tokens'] ?? 0 ),
					(string) ( $row['cost'] ?? '' ),
				),
				',',
				'"',
				'\\'
			);
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		if ( class_exists( Security_Log::class ) ) {
			Security_Log::log_system(
				'logs_exported',
				$tab . '_log',
				array(
					'tab'    => $tab,
					'period' => $period,
					'rows'   => count( $rows ),
				)
			);
		}

		exit;
	}

	/**
	 * Download one skill as a spec-shaped, portable SKILL.md file.
	 *
	 * A skill's DB `content` column already *is* a valid SKILL.md body, so
	 * this is a direct stream — no packaging needed for a resource-less
	 * skill. (Skills with scripts/references/assets aren't supported by the
	 * DB-backed path at all yet — see class-skills-registry.php's file/DB
	 * split.)
	 */
	public static function export_skill(): void {
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'agentic_export_skill' ) ) {
			wp_die( esc_html__( 'Security check failed. Please reload the Skills page and try exporting again.', 'agent-builder' ), 403 );
		}
		if ( ! current_user_can( 'agentic_manage_tools' ) ) {
			wp_die( esc_html__( 'You do not have permission to export skills.', 'agent-builder' ), 403 );
		}

		$id    = isset( $_GET['skill_id'] ) ? absint( $_GET['skill_id'] ) : 0;
		$skill = $id > 0 && class_exists( Skills_Registry::class ) ? Skills_Registry::get( $id ) : null;

		if ( ! $skill ) {
			wp_die( esc_html__( 'Skill not found.', 'agent-builder' ), 404 );
		}

		$slug     = (string) ( $skill['slug'] ?? 'skill' );
		$filename = sanitize_file_name( $slug ) . '.SKILL.md';

		nocache_headers();
		header( 'Content-Type: text/markdown; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Raw file download body, not HTML.
		echo (string) ( $skill['content'] ?? '' );

		exit;
	}

	/**
	 * Import a SKILL.md file uploaded from the "Create Skill" gallery.
	 *
	 * Accepts any file matching the agentskills.io shape (YAML frontmatter +
	 * Markdown body) — from ClawHub, Android Skills, Claude Skills, or any
	 * other spec-compliant source. This is the file-upload counterpart to
	 * Skills_Registry::import_from_hub(), which imports by API payload.
	 */
	public static function import_skill(): void {
		if ( ! isset( $_POST['agentic_skill_import_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['agentic_skill_import_nonce'] ) ), 'agentic_import_skill' ) ) {
			wp_die( esc_html__( 'Security check failed. Please reload the Skills page and try importing again.', 'agent-builder' ), 403 );
		}
		if ( ! current_user_can( 'agentic_manage_tools' ) ) {
			wp_die( esc_html__( 'You do not have permission to import skills.', 'agent-builder' ), 403 );
		}

		$redirect_base = admin_url( 'admin.php?page=agentic-skills' );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- $_FILES[...]['error'] is a PHP-generated integer upload-error code (UPLOAD_ERR_* constant), not user-supplied string data.
		if ( empty( $_FILES['agentic_skill_file']['tmp_name'] ) || UPLOAD_ERR_OK !== ( $_FILES['agentic_skill_file']['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'skill_view'   => 'new',
						'import_error' => 1,
					),
					$redirect_base
				)
			);
			exit;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- Reading a just-uploaded tmp file, not a remote/user-controlled path.
		$raw = file_get_contents( sanitize_text_field( wp_unslash( $_FILES['agentic_skill_file']['tmp_name'] ) ), false, null, 0, 200000 );

		if ( false === $raw || '' === trim( (string) $raw ) || ! class_exists( Skills_Registry::class ) ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'skill_view'   => 'new',
						'import_error' => 1,
					),
					$redirect_base
				)
			);
			exit;
		}

		$identity = Skills_Registry::parse_front_matter_identity( $raw );
		$name     = '' !== $identity['name'] ? $identity['name'] : preg_replace( '/\.(SKILL)?\.?md$/i', '', sanitize_file_name( $_FILES['agentic_skill_file']['name'] ?? 'imported-skill' ) );

		$new_id = Skills_Registry::create(
			array(
				'name'        => $name,
				'description' => $identity['description'],
				'content'     => $raw,
				'agent_slug'  => '',
				'source'      => 'local',
				'version'     => '1.0.0',
				'enabled'     => true,
			)
		);

		if ( ! $new_id ) {
			wp_safe_redirect( add_query_arg( array( 'skill_view' => 'new' ), $redirect_base ) . '#import-error' );
			exit;
		}

		if ( class_exists( Security_Log::class ) ) {
			Security_Log::log_system( 'settings_changed', 'skills', array( 'action' => 'imported_file', 'skill_id' => $new_id ) );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'skill_view' => 'edit',
					'skill_id'   => $new_id,
				),
				$redirect_base
			)
		);
		exit;
	}

	/**
	 * Turn a raw audit row into a friendly timeline item.
	 *
	 * @param array<string,mixed> $item DB row.
	 * @return array<string,mixed>
	 */
	private static function humanize_audit_row( array $item ): array {
		$action  = (string) ( $item['action'] ?? 'event' );
		$agent   = (string) ( $item['agent_id'] ?? '' );
		$target  = (string) ( $item['target_type'] ?? '' );
		$when    = (string) ( $item['created_at'] ?? '' );
		$details = $item['details'] ?? '';
		if ( is_string( $details ) ) {
			$decoded = json_decode( $details, true );
			$details = is_array( $decoded ) ? $decoded : array( 'raw' => $details );
		}
		if ( ! is_array( $details ) ) {
			$details = array();
		}

		$kind  = 'other';
		$icon  = '•';
		$title = self::friendly_action_title( $action, $target, $details );

		if ( str_starts_with( $action, 'chat_' ) || 'conversation' === $target ) {
			$kind = 'chat';
			$icon = '💬';
		} elseif ( str_contains( $action, 'tool' ) || 'tool_call' === $action || 'tool_choice' === $action || 'tool_executed_on_approval' === $action ) {
			$kind = 'tool';
			$icon = '🔧';
			if ( $target && 'conversation' !== $target && 'tool_call' === $action ) {
				$title = sprintf(
					/* translators: %s: tool name */
					__( 'Used tool: %s', 'agent-builder' ),
					str_replace( '_', ' ', $target )
				);
			}
		} elseif ( str_contains( $action, 'approval' ) || str_contains( $action, 'approve' ) || str_contains( $action, 'reject' ) ) {
			$kind = 'approval';
			$icon = '✅';
		} elseif (
			str_contains( $action, 'settings' )
			|| str_contains( $action, 'mode' )
			|| str_contains( $action, 'profile' )
			|| str_contains( $action, 'deployment' )
			|| str_contains( $action, 'tool_enabled' )
			|| str_contains( $action, 'tool_disabled' )
			|| str_contains( $action, 'site_tool' )
			|| str_contains( $action, 'prefs' )
		) {
			$kind = 'settings';
			$icon = '⚙️';
		}

		$detail_bits = array();
		if ( ! empty( $item['reasoning'] ) ) {
			$detail_bits[] = (string) $item['reasoning'];
		}
		if ( 'endpoint_url_changed' === $action && ! empty( $details['previous'] ) && ! empty( $details['new'] ) ) {
			$detail_bits[] = sprintf( '%s → %s', $details['previous'], $details['new'] );
		}
		if ( 'knowledge_added' === $action && ! empty( $details['title'] ) ) {
			$detail_bits[] = sprintf( '"%s" (%s)', $details['title'], $details['source'] ?? 'wizard' );
		}
		if ( 'agent_installed' === $action && ! empty( $details['name'] ) ) {
			$detail_bits[] = sprintf( '%s (%s)', $details['name'], $details['category'] ?? 'admin' );
		}
		if ( ! empty( $details['message'] ) && is_string( $details['message'] ) ) {
			$detail_bits[] = $details['message'];
		}
		if ( ! empty( $details['error'] ) && is_string( $details['error'] ) ) {
			$detail_bits[] = $details['error'];
		}
		$tokens = (int) ( $item['tokens_used'] ?? 0 );
		if ( $tokens > 0 ) {
			$detail_bits[] = sprintf(
				/* translators: %s: token count */
				__( '%s tokens', 'agent-builder' ),
				number_format_i18n( $tokens )
			);
		}

		return array(
			'id'         => (string) ( $item['id'] ?? '' ),
			'title'      => $title,
			'subtitle'   => self::friendly_agent_name( $agent ),
			'when'       => $when,
			'when_human' => self::human_time_label( $when ),
			'detail'     => implode( ' · ', array_filter( $detail_bits ) ),
			'kind'       => $kind,
			'icon'       => $icon,
			'raw_action' => $action,
			'tokens'     => $tokens,
			'cost'       => (float) ( $item['cost'] ?? 0 ),
		);
	}

	/**
	 * @param string               $action  Action slug.
	 * @param string               $target  Target type.
	 * @param array<string,mixed>  $details Details.
	 * @return string
	 */
	private static function friendly_action_title( string $action, string $target, array $details ): string {
		$map = array(
			'chat_start'                 => __( 'Started a chat', 'agent-builder' ),
			'chat_complete'              => __( 'Finished a chat', 'agent-builder' ),
			'tool_call'                  => __( 'Used a tool', 'agent-builder' ),
			'tool_choice'                => __( 'Chose tools for a reply', 'agent-builder' ),
			'tool_executed_on_approval'  => __( 'Ran an approved action', 'agent-builder' ),
			'tool_enabled'               => __( 'Tool turned on', 'agent-builder' ),
			'tool_disabled'              => __( 'Tool turned off', 'agent-builder' ),
			'tools_profile_applied'      => __( 'Tools safety profile applied', 'agent-builder' ),
			'site_tool_created'          => __( 'Site-local tool created', 'agent-builder' ),
			'site_tool_updated'          => __( 'Site-local tool updated', 'agent-builder' ),
			'site_tool_deleted'          => __( 'Site-local tool deleted', 'agent-builder' ),
			'approval_queued'            => __( 'Action waiting for approval', 'agent-builder' ),
			'approval_approved'          => __( 'You approved an action', 'agent-builder' ),
			'approval_rejected'          => __( 'You rejected an action', 'agent-builder' ),
			'action_approved'            => __( 'You approved an action', 'agent-builder' ),
			'action_rejected'            => __( 'You rejected an action', 'agent-builder' ),
			'approval_prefs_saved'       => __( 'Approval preferences saved', 'agent-builder' ),
			'ui_mode_changed'            => __( 'Interface mode changed', 'agent-builder' ),
			'default_agent_mode_changed' => __( 'Default agent mode changed', 'agent-builder' ),
			'deployment_created'         => __( 'Deployment created', 'agent-builder' ),
			'deployment_updated'         => __( 'Deployment updated', 'agent-builder' ),
			'deployment_enabled'         => __( 'Deployment enabled', 'agent-builder' ),
			'deployment_disabled'        => __( 'Deployment disabled', 'agent-builder' ),
			'deployment_deleted'         => __( 'Deployment deleted', 'agent-builder' ),
			'settings_changed'           => __( 'Settings changed', 'agent-builder' ),
			'endpoint_url_changed'       => __( 'Changed a service endpoint URL', 'agent-builder' ),
			'knowledge_added'            => __( 'Added knowledge', 'agent-builder' ),
			'agent_activated'            => __( 'Agent activated', 'agent-builder' ),
			'agent_deactivated'          => __( 'Agent deactivated', 'agent-builder' ),
			'agent_installed'            => __( 'Agent created', 'agent-builder' ),
		);
		if ( isset( $map[ $action ] ) ) {
			return $map[ $action ];
		}
		return ucwords( str_replace( array( '_', '-' ), ' ', $action ) );
	}

	/**
	 * @param string $slug Agent slug.
	 * @return string
	 */
	private static function friendly_agent_name( string $slug ): string {
		if ( '' === $slug || 'human' === $slug || 'system' === $slug ) {
			return __( 'You / system', 'agent-builder' );
		}
		if ( class_exists( '\Agentic_Agent_Registry' ) ) {
			$agent = \Agentic_Agent_Registry::get_instance()->get_agent_instance( $slug );
			if ( $agent && method_exists( $agent, 'get_name' ) ) {
				return $agent->get_name();
			}
		}
		return ucwords( str_replace( array( '-', '_' ), ' ', $slug ) );
	}

	/**
	 * @param string $event Security event.
	 * @return string
	 */
	private static function friendly_security_title( string $event ): string {
		$map = array(
			'approval_execution_exception' => __( 'Approved action failed while running', 'agent-builder' ),
			'chat_exception'               => __( 'Chat hit an error', 'agent-builder' ),
			'chat_error'                   => __( 'Chat error recorded', 'agent-builder' ),
		);
		return $map[ $event ] ?? ucwords( str_replace( array( '_', '-' ), ' ', $event ) );
	}

	/**
	 * @param string $raw Raw detail.
	 * @return string
	 */
	private static function friendly_detail_string( string $raw ): string {
		$raw = trim( wp_strip_all_tags( $raw ) );
		if ( '' === $raw ) {
			return '';
		}
		if ( strlen( $raw ) > 160 ) {
			return substr( $raw, 0, 160 ) . '…';
		}
		return $raw;
	}

	/**
	 * @param string $mysql_utc MySQL datetime (UTC-ish).
	 * @return string
	 */
	private static function human_time_label( string $mysql_utc ): string {
		if ( '' === $mysql_utc ) {
			return '';
		}
		$ts = strtotime( $mysql_utc . ' UTC' );
		if ( ! $ts ) {
			$ts = strtotime( $mysql_utc );
		}
		if ( ! $ts ) {
			return $mysql_utc;
		}
		return sprintf(
			/* translators: %s: human time diff */
			__( '%s ago', 'agent-builder' ),
			human_time_diff( $ts, time() )
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function deployment_payload(): array {
		return array(
			'page'        => 'deployment',
			'title'       => __( 'Publish', 'agent-builder' ),
			'description' => __( 'Deploy agents to shortcodes, blocks, and channels.', 'agent-builder' ),
			'links'       => array(
				array(
					'label' => __( 'Shortcodes', 'agent-builder' ),
					'url'   => admin_url( 'admin.php?page=agentic-deployment' ),
					'hint'  => __( 'Embed chat on any page', 'agent-builder' ),
				),
				array(
					'label' => __( 'Open Agent Chat', 'agent-builder' ),
					'url'   => admin_url( 'admin.php?page=agentic-chat' ),
					'hint'  => __( 'Test agents in wp-admin', 'agent-builder' ),
				),
				array(
					'label' => __( 'Train an Agent', 'agent-builder' ),
					'url'   => admin_url( 'admin.php?page=agentic-agent-wizard' ),
					'hint'  => __( 'Wizard to create a new agent', 'agent-builder' ),
				),
			),
			'legacy_note' => __( 'Full deployment editor (shortcode builder, CLI, modals) remains available when you open a deployment action from here.', 'agent-builder' ),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function upgrade_payload(): array {
		return array(
			'page'        => 'upgrade-pro',
			'title'       => __( 'Upgrade to Pro', 'agent-builder' ),
			'description' => __( 'Unlock vector RAG, connectors, channels, and advanced metering.', 'agent-builder' ),
			'is_pro'      => false,
			'pricing_url' => 'https://agentic-plugin.com/pricing/',
			'features'    => array(
				__( 'Hosted vector store / RAG', 'agent-builder' ),
				__( 'MCP connectors & channels', 'agent-builder' ),
				__( 'Usage analytics & costs', 'agent-builder' ),
				__( 'Priority support', 'agent-builder' ),
			),
		);
	}

	/**
	 * @param string $tab Tab.
	 * @return array<string,mixed>
	 */
	private static function train_payload( string $tab ): array {
		$concepts = array();
		if ( class_exists( Okf_Store::class ) ) {
			foreach ( Okf_Store::list_concepts( '', true ) as $c ) {
				$concepts[] = array(
					'id'      => (string) ( $c['id'] ?? '' ),
					'title'   => (string) ( $c['title'] ?? $c['id'] ?? '' ),
					'type'    => (string) ( $c['type'] ?? '' ),
					'example' => ! empty( $c['example'] ),
					'status'  => (string) ( $c['status'] ?? '' ),
				);
			}
		}
		return array(
			'page'        => 'train-data',
			'tab'         => $tab,
			'title'       => __( 'Knowledge', 'agent-builder' ),
			'description' => __( 'Wiki concepts (OKF) and optional Pro vector store.', 'agent-builder' ),
			'is_pro'      => false,
			'concepts'    => $concepts,
			'tabs'        => array_values(
				array_filter(
					array(
						array(
							'id'    => 'wiki',
							'label' => __( 'Knowledge Wiki', 'agent-builder' ),
							'url'   => admin_url( 'admin.php?page=agentic-train-data&tab=wiki' ),
						),
						$is_pro ? array(
							'id'    => 'vector',
							'label' => __( 'Vector Store', 'agent-builder' ),
							'url'   => admin_url( 'admin.php?page=agentic-train-data&tab=vector' ),
						) : null,
					)
				)
			),
			'manage_url'  => admin_url( 'admin.php?page=agentic-train-data&tab=wiki' ),
		);
	}

	/**
	 * Agent-Ready page payload. Basic mode gets the score plus fix list;
	 * Advanced mode additionally gets the per-tool webmcp_expose matrix and
	 * directory submission status.
	 *
	 * @return array<string,mixed>
	 */
	private static function agent_ready_payload(): array {
		$is_advanced = class_exists( Admin_Menu_Handler::class )
			? Admin_Menu_Handler::is_advanced_mode( 'agent-ready' )
			: ( 'advanced' === get_option( 'agentic_ui_mode', 'basic' ) );

		$payload = array(
			'page'           => 'agent-ready',
			'title'          => __( 'Site Passport', 'agent-builder' ),
			'panel_title'    => __( 'Score & fixes', 'agent-builder' ),
			'description'    => __( 'Your site\'s passport for AI agents — what they can discover, and what they can access.', 'agent-builder' ),
			'is_advanced'    => $is_advanced,
			'score'          => class_exists( Agent_Ready_Score::class ) ? Agent_Ready_Score::get_latest() : array(),
			'webmcp_enabled' => class_exists( Webmcp_Bridge::class ) && Webmcp_Bridge::is_enabled(),
		);

		if ( $is_advanced ) {
			$payload['webmcp_matrix']    = class_exists( Abilities_Manifest::class ) ? Abilities_Manifest::get_webmcp_exposed() : array();
			$payload['directory_status'] = get_option( 'agentic_directory_submission', array() );
		}

		return $payload;
	}

	/**
	 * Safety Center payload (M2 Phase 1 overview + Phase 2 inventory/scopes
	 * + Phase 3 audit-integrity incident messaging + Phase 5 Advanced polish).
	 *
	 * Assembles the five summary cards plus the risk-tier strip, highest-risk
	 * enabled list, per-agent scope cards, and the audit-log integrity
	 * section from existing sources. No new storage. verify_chain() is called
	 * once here and reused by the overview card and the integrity section.
	 * The HIGH-risk confirmation modal (Phase 4) stays out of this payload.
	 *
	 * Overview cards and Phase 2–4 sections always render in both modes.
	 * Phase 5 uses is_advanced so Advanced can expand the full per-agent
	 * tool list and show the raw verify_chain() fields already in this payload.
	 *
	 * @return array<string, mixed>
	 */
	private static function safety_center_payload(): array {
		$risk_labels = array(
			'none'    => __( 'No Risk', 'agent-builder' ),
			'low'     => __( 'Low Risk', 'agent-builder' ),
			'medium'  => __( 'Medium Risk', 'agent-builder' ),
			'high'    => __( 'High Risk', 'agent-builder' ),
			'extreme' => __( 'Extreme Risk', 'agent-builder' ),
		);

		$all_tools      = class_exists( Tools_Registry::class ) ? Tools_Registry::get_all() : array();
		$enabled_count  = 0;
		$disabled_count = 0;
		$max_risk       = class_exists( Risk_Level::class ) ? Risk_Level::NONE : 'none';
		$inventory      = self::safety_center_risk_inventory( $all_tools, $risk_labels );
		foreach ( $all_tools as $tool ) {
			if ( ! empty( $tool['enabled'] ) ) {
				++$enabled_count;
			} else {
				++$disabled_count;
			}
		}
		if ( ! empty( $inventory['enabled_max_risk'] ) ) {
			$max_risk = (string) $inventory['enabled_max_risk'];
		}

		$pending_count = 0;
		if ( class_exists( Approval_Queue::class ) ) {
			$pending_count = ( new Approval_Queue() )->get_pending_count();
		}
		$agent_mode = (string) get_option( 'agentic_agent_mode', 'supervised' );
		if ( ! in_array( $agent_mode, array( 'disabled', 'supervised', 'autonomous' ), true ) ) {
			$agent_mode = 'supervised';
		}
		$comfort = sanitize_key( (string) get_option( 'agentic_approval_comfort', 'careful' ) );

		$integrity = array(
			'valid'          => true,
			'checked'        => 0,
			'broken_at_id'   => null,
			'chain_start_id' => null,
		);
		if ( class_exists( Audit_Log_Integrity::class ) ) {
			$integrity = Audit_Log_Integrity::verify_chain();
		}

		$emergency_active = class_exists( Emergency_Stop::class ) && Emergency_Stop::is_active();

		$inventory_agents = array();
		if ( class_exists( Inventory_REST::class ) ) {
			$inventory_data   = Inventory_REST::get_inventory()->get_data();
			$inventory_agents = is_array( $inventory_data['agents'] ?? null ) ? $inventory_data['agents'] : array();
		}
		$agent_scopes     = self::safety_center_agent_scopes( $inventory_agents, $risk_labels );
		$active_agents    = count( $agent_scopes );
		$high_risk_agents = 0;
		$mcp_agents       = 0;
		$high_w           = class_exists( Risk_Level::class ) ? Risk_Level::weight( Risk_Level::HIGH ) : 3;
		foreach ( $agent_scopes as $agent ) {
			if ( ! empty( $agent['mcp_enabled'] ) ) {
				++$mcp_agents;
			}
			$highest = (string) ( $agent['highest_risk'] ?? 'none' );
			$w       = class_exists( Risk_Level::class ) ? Risk_Level::weight( $highest ) : 0;
			if ( $w >= $high_w ) {
				++$high_risk_agents;
			}
		}

		$mode_labels = array(
			'disabled'   => __( 'Disabled', 'agent-builder' ),
			'supervised' => __( 'Supervised', 'agent-builder' ),
			'autonomous' => __( 'Autonomous', 'agent-builder' ),
		);

		$comfort_labels = array(
			'careful'   => __( 'Always ask me', 'agent-builder' ),
			'balanced'  => __( 'Auto-approve low risk', 'agent-builder' ),
			'hands_off' => __( 'Trust more', 'agent-builder' ),
		);

		return array(
			'page'           => 'safety-center',
			'title'          => __( 'Safety Center', 'agent-builder' ),
			'panel_title'    => __( 'Safety overview', 'agent-builder' ),
			'description'    => __( 'Is this site set up safely for AI agents right now? These cards summarize the controls you already have — they do not change how those controls work.', 'agent-builder' ),
			'is_advanced'    => class_exists( Admin_Menu_Handler::class )
				? Admin_Menu_Handler::is_advanced_mode( 'safety-center' )
				: ( 'advanced' === get_option( 'agentic_ui_mode', 'basic' ) ),
			'tools'          => array(
				'enabled_count'    => $enabled_count,
				'disabled_count'   => $disabled_count,
				'enabled_max_risk' => $max_risk,
				'max_risk_label'   => $risk_labels[ $max_risk ] ?? $max_risk,
			),
			'risk_inventory' => $inventory,
			'approvals'      => array(
				'pending_count'    => $pending_count,
				'agent_mode'       => $agent_mode,
				'agent_mode_label' => $mode_labels[ $agent_mode ] ?? $agent_mode,
				'comfort'          => $comfort,
				'comfort_label'    => $comfort_labels[ $comfort ] ?? $comfort_labels['careful'],
			),
			'integrity'      => array(
				'valid'          => ! empty( $integrity['valid'] ),
				'checked'        => (int) ( $integrity['checked'] ?? 0 ),
				'broken_at_id'   => $integrity['broken_at_id'] ?? null,
				'chain_start_id' => $integrity['chain_start_id'] ?? null,
			),
			'emergency_stop' => array(
				'active' => $emergency_active,
			),
			'agents'         => array(
				'active_count'    => $active_agents,
				'high_risk_count' => $high_risk_agents,
				'mcp_count'       => $mcp_agents,
				'items'           => $agent_scopes,
				'integrity_note'  => __( 'Tool list blocked if manifest signature fails.', 'agent-builder' ),
			),
			'urls'           => array(
				'tools'     => admin_url( 'admin.php?page=agentic-tools' ),
				'approvals' => admin_url( 'admin.php?page=agentic-approvals' ),
				'activity'  => admin_url( 'admin.php?page=agentic-audit-log' ),
				'passport'  => admin_url( 'admin.php?page=agentic-agent-ready' ),
				'agents'    => admin_url( 'admin.php?page=agentic-agents' ),
				// Same construction as logs_payload(): wp_nonce_url() would
				// entity-escape "&" and break the React href.
				'export'    => admin_url(
					'admin-post.php?action=agentic_export_logs&tab=audit'
					. '&period=week'
					. '&_wpnonce=' . wp_create_nonce( 'agentic_export_logs' )
				),
			),
			'docs_url'       => 'https://agentic-plugin.com/permissions-and-safety/',
			'footer_policy'  => __(
				'Safety Center summarizes existing operator controls. It does not change how tools, approvals, or Emergency Stop work.',
				'agent-builder'
			),
		);
	}

	/**
	 * Per-tier enabled/disabled counts, design-doc explanations, BASELINE_RISKS
	 * examples, and currently-enabled HIGH/EXTREME tools.
	 *
	 * Counts are grouped by Risk_Level::get_tool_default() so the strip uses
	 * the same floor as runtime, while still summing to Tools_Registry totals.
	 *
	 * @param array<string, array> $all_tools    Tools_Registry::get_all().
	 * @param array<string, string> $risk_labels Tier id => label.
	 * @return array<string, mixed>
	 */
	private static function safety_center_risk_inventory( array $all_tools, array $risk_labels ): array {
		$tiers_meta = self::safety_center_tier_copy();
		$examples   = self::safety_center_tier_examples();

		$counts = array();
		foreach ( array_keys( $tiers_meta ) as $tier ) {
			$counts[ $tier ] = array(
				'enabled'  => 0,
				'disabled' => 0,
			);
		}

		$max_risk          = class_exists( Risk_Level::class ) ? Risk_Level::NONE : 'none';
		$highest_enabled   = array();
		$high_w            = class_exists( Risk_Level::class ) ? Risk_Level::weight( Risk_Level::HIGH ) : 3;

		foreach ( $all_tools as $name => $tool ) {
			$name = (string) ( is_string( $name ) && '' !== $name ? $name : ( $tool['name'] ?? '' ) );
			if ( '' === $name ) {
				continue;
			}
			$risk = class_exists( Risk_Level::class )
				? Risk_Level::get_tool_default( $name )
				: (string) ( $tool['risk_level'] ?? 'none' );
			if ( ! isset( $counts[ $risk ] ) ) {
				$risk = 'none';
			}
			if ( ! empty( $tool['enabled'] ) ) {
				++$counts[ $risk ]['enabled'];
				if ( class_exists( Risk_Level::class ) ) {
					$max_risk = Risk_Level::max( $max_risk, $risk );
				}
				$w = class_exists( Risk_Level::class ) ? Risk_Level::weight( $risk ) : 0;
				if ( $w >= $high_w ) {
					$highest_enabled[] = array(
						'name'        => $name,
						'label'       => self::safety_center_tool_label( $name ),
						'risk'        => $risk,
						'risk_label'  => $risk_labels[ $risk ] ?? $risk,
						'description' => (string) ( $tool['description'] ?? '' ),
					);
				}
			} else {
				++$counts[ $risk ]['disabled'];
			}
		}

		usort(
			$highest_enabled,
			static function ( $a, $b ) {
				$wa = class_exists( Risk_Level::class ) ? Risk_Level::weight( (string) $a['risk'] ) : 0;
				$wb = class_exists( Risk_Level::class ) ? Risk_Level::weight( (string) $b['risk'] ) : 0;
				if ( $wa !== $wb ) {
					return $wb <=> $wa;
				}
				return strcasecmp( (string) $a['name'], (string) $b['name'] );
			}
		);

		$tiers = array();
		foreach ( $tiers_meta as $id => $meta ) {
			$tiers[] = array(
				'id'          => $id,
				'label'       => $risk_labels[ $id ] ?? $id,
				'short_label' => $meta['short_label'],
				'enabled'     => (int) $counts[ $id ]['enabled'],
				'disabled'    => (int) $counts[ $id ]['disabled'],
				'explanation' => $meta['explanation'],
				'examples'    => $examples[ $id ] ?? array(),
			);
		}

		return array(
			'tiers'            => $tiers,
			'highest_enabled'  => $highest_enabled,
			'enabled_max_risk' => $max_risk,
		);
	}

	/**
	 * Verbatim §3.1 copy plus the strip's short labels from §2.2.
	 *
	 * @return array<string, array{short_label:string, explanation:string}>
	 */
	private static function safety_center_tier_copy(): array {
		return array(
			'none'    => array(
				'short_label' => __( 'None', 'agent-builder' ),
				'explanation' => __( 'Read-only. The agent can look things up, but it cannot change your site.', 'agent-builder' ),
			),
			'low'     => array(
				'short_label' => __( 'Low', 'agent-builder' ),
				'explanation' => __( 'Usually safe to run automatically. These tools may read information that can include personal data, but they do not change your site.', 'agent-builder' ),
			),
			'medium'  => array(
				'short_label' => __( 'Medium', 'agent-builder' ),
				'explanation' => __( 'Changes something. The agent should pause and ask before using these tools.', 'agent-builder' ),
			),
			'high'    => array(
				'short_label' => __( 'High', 'agent-builder' ),
				'explanation' => __( 'Significant, bulk, account-sensitive, or money-moving actions. These do not run immediately — they wait for a human decision in the Approvals queue.', 'agent-builder' ),
			),
			'extreme' => array(
				'short_label' => __( 'Extreme', 'agent-builder' ),
				'explanation' => __( 'Too risky to allow. These tools are hidden from agents entirely and should not be enabled for normal use.', 'agent-builder' ),
			),
		);
	}

	/**
	 * 1–3 representative tool names per tier, taken from BASELINE_RISKS.
	 *
	 * Preferred examples follow the design doc (§1.1 / §3.5). Remaining slots
	 * fill from other baseline tools of that tier. None has no baseline
	 * entries, so its example list stays empty.
	 *
	 * @return array<string, array<int, array{name:string, label:string}>>
	 */
	private static function safety_center_tier_examples(): array {
		$baseline = class_exists( Risk_Level::class ) ? Risk_Level::get_baseline_risks() : array();
		$preferred = array(
			'none'    => array(),
			'low'     => array( 'request_human_help', 'wc_add_to_cart', 'manage_agent_shortcode' ),
			'medium'  => array( 'send_email', 'add_custom_css', 'cleanup_auto_drafts' ),
			'high'    => array( 'install_plugin_from_url', 'force_password_reset', 'wc_create_refund' ),
			'extreme' => array( 'run_wp_cli' ),
		);

		$by_tier = array(
			'none'    => array(),
			'low'     => array(),
			'medium'  => array(),
			'high'    => array(),
			'extreme' => array(),
		);
		foreach ( $baseline as $tool_name => $risk ) {
			if ( isset( $by_tier[ $risk ] ) ) {
				$by_tier[ $risk ][] = (string) $tool_name;
			}
		}

		$out = array();
		foreach ( $preferred as $tier => $names ) {
			$picked = array();
			foreach ( $names as $name ) {
				if ( isset( $baseline[ $name ] ) && $baseline[ $name ] === $tier ) {
					$picked[] = $name;
				}
			}
			foreach ( $by_tier[ $tier ] as $name ) {
				if ( count( $picked ) >= 3 ) {
					break;
				}
				if ( ! in_array( $name, $picked, true ) ) {
					$picked[] = $name;
				}
			}
			$examples = array();
			foreach ( array_slice( $picked, 0, 3 ) as $name ) {
				$examples[] = array(
					'name'  => $name,
					'label' => self::safety_center_tool_label( $name ),
				);
			}
			$out[ $tier ] = $examples;
		}

		return $out;
	}

	/**
	 * One scope card payload per active inventory agent.
	 *
	 * @param array<int, array<string, mixed>> $agents      Inventory_REST agents.
	 * @param array<string, string>            $risk_labels Tier id => label.
	 * @return array<int, array<string, mixed>>
	 */
	private static function safety_center_agent_scopes( array $agents, array $risk_labels ): array {
		$items  = array();
		$high_w = class_exists( Risk_Level::class ) ? Risk_Level::weight( Risk_Level::HIGH ) : 3;

		foreach ( $agents as $agent ) {
			if ( ! is_array( $agent ) ) {
				continue;
			}
			$slug = (string) ( $agent['slug'] ?? '' );
			if ( '' === $slug ) {
				continue;
			}

			$risk_counts = array(
				'none'    => 0,
				'low'     => 0,
				'medium'  => 0,
				'high'    => 0,
				'extreme' => 0,
			);
			$highest     = class_exists( Risk_Level::class ) ? Risk_Level::NONE : 'none';
			$high_tools  = array();
			$other_tools = array();

			foreach ( (array) ( $agent['tools'] ?? array() ) as $tool ) {
				$name = (string) ( $tool['name'] ?? '' );
				if ( '' === $name ) {
					continue;
				}
				$risk = (string) ( $tool['risk'] ?? 'none' );
				if ( ! isset( $risk_counts[ $risk ] ) ) {
					$risk = 'none';
				}
				++$risk_counts[ $risk ];
				if ( class_exists( Risk_Level::class ) ) {
					$highest = Risk_Level::max( $highest, $risk );
				}
				$row = array(
					'name'       => $name,
					'label'      => self::safety_center_tool_label( $name ),
					'risk'       => $risk,
					'risk_label' => $risk_labels[ $risk ] ?? $risk,
				);
				$w = class_exists( Risk_Level::class ) ? Risk_Level::weight( $risk ) : 0;
				if ( $w >= $high_w ) {
					$high_tools[] = $row;
				} else {
					$other_tools[] = $row;
				}
			}

			$sort_tools = static function ( $a, $b ) {
				$wa = class_exists( Risk_Level::class ) ? Risk_Level::weight( (string) $a['risk'] ) : 0;
				$wb = class_exists( Risk_Level::class ) ? Risk_Level::weight( (string) $b['risk'] ) : 0;
				if ( $wa !== $wb ) {
					return $wb <=> $wa;
				}
				return strcasecmp( (string) $a['name'], (string) $b['name'] );
			};
			usort( $high_tools, $sort_tools );
			usort( $other_tools, $sort_tools );

			$items[] = array(
				'slug'               => $slug,
				'name'               => (string) ( $agent['name'] ?? $slug ),
				'version'            => (string) ( $agent['version'] ?? '' ),
				'author'             => (string) ( $agent['author'] ?? '' ),
				'mcp_enabled'        => ! empty( $agent['mcp_enabled'] ),
				'risk_counts'        => $risk_counts,
				'highest_risk'       => $highest,
				'highest_risk_label' => $risk_labels[ $highest ] ?? $highest,
				'high_tools'         => $high_tools,
				'other_tools'        => $other_tools,
			);
		}

		usort(
			$items,
			static function ( $a, $b ) {
				$wa = class_exists( Risk_Level::class ) ? Risk_Level::weight( (string) $a['highest_risk'] ) : 0;
				$wb = class_exists( Risk_Level::class ) ? Risk_Level::weight( (string) $b['highest_risk'] ) : 0;
				if ( $wa !== $wb ) {
					return $wb <=> $wa;
				}
				return strcasecmp( (string) $a['name'], (string) $b['name'] );
			}
		);

		return $items;
	}

	/**
	 * Owner-facing tool slug (underscores to spaces).
	 *
	 * @param string $name Tool slug.
	 * @return string
	 */
	private static function safety_center_tool_label( string $name ): string {
		return str_replace( array( '_', '-' ), ' ', $name );
	}

	/**
	 * Wire Emergency Stop through the same enable/disable methods the
	 * Dashboard and Settings screens already use. No new storage.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private static function set_emergency_stop( \WP_REST_Request $request ) {
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'agentic_manage_settings' ) ) {
			return new \WP_Error( 'forbidden', __( 'Permission denied.', 'agent-builder' ), array( 'status' => 403 ) );
		}

		$enable   = rest_sanitize_boolean( $request->get_param( 'enable' ) );
		$warnings = array();
		if ( $enable && ! Emergency_Stop::is_active() ) {
			Emergency_Stop::enable();
		} elseif ( ! $enable && Emergency_Stop::is_active() ) {
			$warnings = Emergency_Stop::disable()['warnings'] ?? array();
		}

		return new \WP_REST_Response(
			array(
				'ok'       => true,
				'active'   => Emergency_Stop::is_active(),
				'warnings' => $warnings,
			),
			200
		);
	}
}
