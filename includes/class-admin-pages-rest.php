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

use Agentic\Admin_Pages\Agent_Ready_Payload;
use Agentic\Admin_Pages\Approvals_Payload;
use Agentic\Admin_Pages\Deployment_Payload;
use Agentic\Admin_Pages\Logs_Payload;
use Agentic\Admin_Pages\Safety_Center_Payload;
use Agentic\Admin_Pages\Skills_Payload;
use Agentic\Admin_Pages\Tools_Payload;
use Agentic\Admin_Pages\Train_Payload;

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
	 * (tools, skills, approvals, logs, deployment, train-data),
	 * each already gated behind its own `agentic_*` capability at the
	 * wp-admin menu level (see Admin_Menu_Handler::register()). The
	 * capability required here must match that per-page grant, otherwise a
	 * role granted e.g. `agent_builder_manage_tools` can see the menu item and
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
			return current_user_can( 'agent_builder_manage_tools' );
		}

		if ( 'approvals' === $page || 'deployment' === $page || in_array( $action, $agents_actions, true ) ) {
			return current_user_can( 'agent_builder_manage_agents' );
		}

		if ( 'logs' === $page ) {
			return current_user_can( 'agent_builder_view_audit_log' );
		}

		if ( 'agent-ready' === $page || in_array( $action, array( 'apply_free_fix', 'confirm_agent_ready_proposal', 'toggle_webmcp_expose', 'submit_to_directory' ), true ) ) {
			// submit_to_directory is the one deliberate phone-home this feature
			// makes — require manage_options explicitly rather than the page's
			// normal agent_builder_manage_settings, even though the current_user_can(
			// 'manage_options' ) short-circuit above already covers the common
			// case; this keeps the requirement legible if that short-circuit is
			// ever narrowed.
			return 'submit_to_directory' === $action
				? current_user_can( 'manage_options' )
				: current_user_can( 'agent_builder_manage_settings' );
		}

		// Site-wide Basic/Advanced default — same cap as the Dashboard
		// Interface Settings card / Settings → Interface (the other
		// callers of Admin_Settings_REST::set_ui_mode()).
		if ( 'set_ui_mode' === $action ) {
			return current_user_can( 'agent_builder_manage_settings' );
		}

		// set_screen_mode is a personal, per-user preference for one screen —
		// require whatever capability that screen itself already requires,
		// so setting it never grants more than reading the screen already
		// does (and reset_screen_modes only touches the current user's own
		// overrides, so any of these are a safe minimum).
		if ( 'set_screen_mode' === $action || 'reset_screen_modes' === $action ) {
			$screen = sanitize_key( (string) $request->get_param( 'screen' ) );
			if ( in_array( $screen, array( 'tools', 'skills' ), true ) ) {
				return current_user_can( 'agent_builder_manage_tools' );
			}
			if ( in_array( $screen, array( 'approvals', 'deployment', 'agents' ), true ) ) {
				return current_user_can( 'agent_builder_manage_agents' );
			}
			if ( 'logs' === $screen ) {
				return current_user_can( 'agent_builder_view_audit_log' );
			}
			return current_user_can( 'agent_builder_manage_settings' );
		}

		// train-data, safety-center, and anything unmapped stay behind the
		// broadest admin-settings privilege as a safe default.
		return current_user_can( 'agent_builder_manage_settings' );
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
		$page_builders = array(
			'tools'         => array( 'class' => Tools_Payload::class, 'args' => array( $tab ?: 'all' ) ),
			'skills'        => array( 'class' => Skills_Payload::class, 'args' => array() ),
			'approvals'     => array( 'class' => Approvals_Payload::class, 'args' => array( $tab ?: 'approvals' ) ),
			'deployment'    => array( 'class' => Deployment_Payload::class, 'args' => array() ),
			'train-data'    => array( 'class' => Train_Payload::class, 'args' => array( $tab ?: 'wiki' ) ),
			'agent-ready'   => array( 'class' => Agent_Ready_Payload::class, 'args' => array() ),
			'safety-center' => array( 'class' => Safety_Center_Payload::class, 'args' => array() ),
		);

		if ( 'logs' === $page ) {
			$period = sanitize_key( (string) $request->get_param( 'period' ) );
			if ( ! in_array( $period, array( 'day', 'week', 'month' ), true ) ) {
				$period = 'week';
			}
			return new \WP_REST_Response( Logs_Payload::build( $tab ?: 'audit', $period ), 200 );
		}

		if ( isset( $page_builders[ $page ] ) ) {
			$class = $page_builders[ $page ]['class'];
			return new \WP_REST_Response( $class::build( ...$page_builders[ $page ]['args'] ), 200 );
		}

		return new \WP_Error( 'unknown_page', __( 'Unknown admin page.', 'agent-builder' ), array( 'status' => 404 ) );
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
			update_option( 'agent_builder_tools_ability_profile', 'custom', false );
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
			$profiles   = Tools_Payload::tools_ability_profiles();
			if ( ! isset( $profiles[ $profile_id ] ) || 'custom' === $profile_id ) {
				return new \WP_Error( 'invalid_profile', __( 'Unknown ability profile.', 'agent-builder' ), array( 'status' => 400 ) );
			}
			if ( ! class_exists( Tools_Registry::class ) ) {
				return new \WP_Error( 'unavailable', __( 'Tools registry unavailable.', 'agent-builder' ), array( 'status' => 500 ) );
			}
			$max    = (string) $profiles[ $profile_id ]['max_risk'];
			$result = Tools_Registry::apply_max_risk_level( $max );
			update_option( 'agent_builder_tools_ability_profile', $profile_id, false );
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
		if ( $slug === get_option( 'agent_builder_llm_provider', '' ) ) {
			$site_model = (string) get_option( 'agent_builder_model', '' );
			if ( '' !== $site_model ) {
				$model = $site_model;
			}
		}

		if ( $is_keyless ) {
			$url = 'ollama' === $slug
				? rtrim( get_option( 'agent_builder_ollama_url', 'http://localhost:11434' ), '/' ) . '/api/tags'
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
	 * Save Approvals preferences from React UI.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private static function save_approval_prefs( \WP_REST_Request $request ) {
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'agent_builder_manage_agents' ) ) {
			return new \WP_Error( 'forbidden', __( 'Permission denied.', 'agent-builder' ), array( 'status' => 403 ) );
		}

		$email_notify = rest_sanitize_boolean( $request->get_param( 'email_notify' ) );
		$email_to     = sanitize_email( (string) $request->get_param( 'email_to' ) );
		$comfort      = sanitize_key( (string) $request->get_param( 'comfort' ) );
		$risk_ack     = rest_sanitize_boolean( $request->get_param( 'risk_ack' ) );

		$profiles = array();
		foreach ( Approvals_Payload::approval_comfort_profiles() as $p ) {
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

		$prev = Approvals_Payload::get_approval_prefs();

		update_option( 'agent_builder_approval_email_notify', $email_notify ? 1 : 0, false );
		if ( is_email( $email_to ) ) {
			update_option( 'agent_builder_approval_email_to', $email_to, false );
		}

		$auto_max = (string) ( $profiles[ $comfort ]['auto_max'] ?? 'none' );
		$mode     = (string) ( $profiles[ $comfort ]['mode'] ?? 'supervised' );

		update_option( 'agent_builder_approval_comfort', $comfort, false );
		update_option( 'agent_builder_approval_auto_max_risk', $auto_max, false );
		update_option( 'agent_builder_agent_mode', $mode, false );
		update_option( 'agent_builder_approval_risk_ack', ( ! empty( $profiles[ $comfort ]['needs_ack'] ) && $risk_ack ) ? 1 : 0, false );

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
				'prefs' => Approvals_Payload::get_approval_prefs(),
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
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'agent_builder_manage_agents' ) ) {
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
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'agent_builder_manage_agents' ) ) {
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
	 * Download the current Logs view (Timeline/Conversations/Security) as a
	 * CSV file — e.g. to attach to a support email when diagnosing an issue.
	 * Plain admin-post handler (not REST) so a simple GET navigation
	 * triggers a native browser download; gated the same way the Logs page
	 * itself is (agent_builder_view_audit_log), plus a nonce since this both reads
	 * potentially sensitive data and is reachable via direct URL.
	 *
	 * @return void
	 */
	public static function export_logs(): void {
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'agentic_export_logs' ) ) {
			wp_die( esc_html__( 'Security check failed. Please reload the Activity page and try exporting again.', 'agent-builder' ), 403 );
		}
		if ( ! current_user_can( 'agent_builder_view_audit_log' ) && ! current_user_can( 'manage_options' ) ) {
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

		$payload = Logs_Payload::build( $tab, $period );
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
		if ( ! current_user_can( 'agent_builder_manage_tools' ) ) {
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
	 * Extensions accepted for an uploaded skill file. A skill's `content` is
	 * only ever stored as a database TEXT column — never written back out to
	 * disk, eval()'d, or include()'d anywhere in this plugin — but the
	 * client-side `accept=".md"` on the file input is a UI hint only, not a
	 * security control, so the upload itself must be validated server-side
	 * too. Allowlisted rather than denylisted (see File_Manager's own
	 * DENYLISTED_EXTENSIONS for why a denylist alone isn't enough) since a
	 * skill file has one legitimate shape and everything else should be
	 * rejected, not just the executable-looking cases.
	 *
	 * @var string[]
	 */
	private const ALLOWED_SKILL_FILE_EXTENSIONS = array( 'md', 'markdown', 'txt' );

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
		if ( ! current_user_can( 'agent_builder_manage_tools' ) ) {
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

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_file_name() below is the actual sanitizer; this just extracts the extension for the allowlist check.
		$uploaded_name      = wp_unslash( $_FILES['agentic_skill_file']['name'] ?? '' );
		$uploaded_extension = strtolower( pathinfo( sanitize_file_name( $uploaded_name ), PATHINFO_EXTENSION ) );
		if ( ! in_array( $uploaded_extension, self::ALLOWED_SKILL_FILE_EXTENSIONS, true ) ) {
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
	 * Wire Emergency Stop through the same enable/disable methods the
	 * Dashboard and Settings screens already use. No new storage.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private static function set_emergency_stop( \WP_REST_Request $request ) {
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'agent_builder_manage_settings' ) ) {
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
