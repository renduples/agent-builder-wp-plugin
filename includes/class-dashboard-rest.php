<?php
/**
 * Dashboard REST — full bootstrap for the React dashboard.
 *
 * @package    Agent_Builder
 * @subpackage REST
 * @since      2.12.0
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dashboard REST controller.
 */
class Dashboard_REST {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	const NS = 'agentic/v1';

	/**
	 * User meta: ordered list of dashboard card IDs.
	 */
	const LAYOUT_META = 'agentic_dashboard_layout';

	/**
	 * Default card order (all boxes).
	 *
	 * @return string[]
	 */
	public static function default_layout(): array {
		return array(
			'status',
			'safety',
			'agent-ready',
			'activity',
			'providers',
			'quick-actions',
			'interface',
			'getting-started',
		);
	}

	/**
	 * Resolved layout for the current user (invalid IDs stripped; missing IDs appended).
	 *
	 * @return string[]
	 */
	public static function get_layout_for_user(): array {
		$allowed = self::default_layout();
		$stored  = get_user_meta( get_current_user_id(), self::LAYOUT_META, true );
		if ( ! is_array( $stored ) || empty( $stored ) ) {
			return $allowed;
		}
		$order = array();
		foreach ( $stored as $id ) {
			// Keep hyphens (sanitize_key would strip them).
			$id = strtolower( preg_replace( '/[^a-z0-9\-]/', '', (string) $id ) );
			if ( in_array( $id, $allowed, true ) && ! in_array( $id, $order, true ) ) {
				$order[] = $id;
			}
		}
		foreach ( $allowed as $id ) {
			if ( ! in_array( $id, $order, true ) ) {
				$order[] = $id;
			}
		}
		return $order;
	}

	/**
	 * Boot.
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Routes.
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::NS,
			'/dashboard-stats',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_stats' ),
				'permission_callback' => array( __CLASS__, 'can_view' ),
				'args'                => array(
					'period' => array(
						'type'              => 'string',
						'default'           => 'week',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/dashboard',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_dashboard_route' ),
					'permission_callback' => array( __CLASS__, 'can_view' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'post_dashboard' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
				),
			)
		);
	}

	/**
	 * View capability.
	 */
	public static function can_view(): bool {
		return current_user_can( 'manage_options' ) || current_user_can( 'agentic_view_dashboard' );
	}

	/**
	 * Manage capability (toggles / quick actions).
	 */
	public static function can_manage(): bool {
		return current_user_can( 'manage_options' ) || current_user_can( 'agentic_manage_settings' );
	}

	/**
	 * GET /dashboard-stats (Activity refresh).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function get_stats( \WP_REST_Request $request ): \WP_REST_Response {
		$period = (string) $request->get_param( 'period' );
		if ( ! in_array( $period, array( 'day', 'week', 'month' ), true ) ) {
			$period = 'week';
		}

		$stats = ( new Audit_Log() )->get_stats( $period );

		return new \WP_REST_Response(
			array(
				'period'   => $period,
				'activity' => array(
					'actions' => (int) ( $stats['total_actions'] ?? 0 ),
					'tokens'  => (int) ( $stats['total_tokens'] ?? 0 ),
					'cost'    => round( (float) ( $stats['total_cost'] ?? 0 ), 4 ),
				),
				'agents'   => self::agent_counts(),
			),
			200
		);
	}

	/**
	 * GET /dashboard REST callback — the REST server always calls this with a
	 * WP_REST_Request, which is incompatible with get_dashboard()'s own
	 * $warnings array param (used only by internal callers below).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function get_dashboard_route( \WP_REST_Request $request ): \WP_REST_Response {
		return self::get_dashboard();
	}

	/**
	 * GET /dashboard — full page bootstrap.
	 *
	 * @return \WP_REST_Response
	 */
	public static function get_dashboard( array $warnings = array() ): \WP_REST_Response {
		$llm         = new LLM_Client();
		$is_pro      = false;
		$is_advanced = Admin_Menu_Handler::is_advanced_mode( 'dashboard' );
		$provider    = $llm->get_provider();
		$site_model  = (string) get_option( 'agentic_model', '' );

		// Providers connected list.
		$providers = array();
		foreach ( Provider_Registry::get_all() as $p ) {
			if ( ! Admin_Menu_Handler::provider_is_connected( $p ) ) {
				continue;
			}
			$slug        = (string) ( $p['slug'] ?? '' );
			$model       = ( $slug === $provider && '' !== $site_model )
				? $site_model
				: (string) ( $p['default_model'] ?? '' );
			$providers[] = array(
				'slug'       => $slug,
				'name'       => (string) ( $p['name'] ?? $slug ),
				'model'      => $model,
				'is_default' => $slug === $provider,
			);
		}

		// License tile.
		$license = self::license_tile();

		// Jobs.
		$job_health = ( class_exists( Job_Manager::class ) && method_exists( Job_Manager::class, 'get_health' ) )
			? Job_Manager::get_health()
			: array(
				'stats'             => array(
					'pending'    => 0,
					'processing' => 0,
					'completed'  => 0,
				),
				'stuck_processing'  => 0,
				'abandoned_pending' => 0,
			);

		// Activity.
		$stats = ( new Audit_Log() )->get_stats( 'week' );

		// Safety net — pending approvals and the automatic file/DB backups
		// created before an assistant modifies something (Tool_Helpers).
		$approval_queue      = class_exists( Approval_Queue::class ) ? new Approval_Queue() : null;
		$pending_approvals   = $approval_queue ? $approval_queue->get_pending_count() : 0;
		$completed_approvals = $approval_queue ? $approval_queue->get_completed_count() : 0;
		$file_backups        = class_exists( Tool_Helpers::class ) ? count( Tool_Helpers::get_backups() ) : 0;
		$table_backups       = class_exists( Tool_Helpers::class ) ? count( Tool_Helpers::get_table_backups() ) : 0;

		// Quick actions.
		$catalog = Admin_Menu_Handler::quick_actions_catalog();
		$enabled = Admin_Menu_Handler::get_enabled_quick_actions();
		$qa_list = array();
		foreach ( $catalog as $slug => $item ) {
			$qa_list[] = array(
				'slug'     => $slug,
				'label'    => (string) ( $item['label'] ?? $slug ),
				'url'      => (string) ( $item['url'] ?? '' ),
				'locked'   => ! empty( $item['locked'] ),
				'default'  => ! empty( $item['default'] ),
				'group'    => (string) ( $item['group'] ?? 'primary' ),
				'advanced' => ! empty( $item['advanced'] ),
				'pro'      => ! empty( $item['pro'] ),
				'enabled'  => in_array( $slug, $enabled, true ),
			);
		}

		// Onboarding steps.
		$agent_counts  = self::agent_counts();
		$has_knowledge = class_exists( Okf_Store::class )
			? Okf_Store::has_active_knowledge()
			: (bool) get_option( 'agentic_has_knowledge', false );
		$is_configured = $llm->is_configured() && ! Emergency_Stop::is_active();
		// Chat comes first: WordPress Assistant works out of the box on the
		// bundled default provider, so a brand-new user can start talking to it
		// with zero setup — before "connect a provider" (bring your own key/model)
		// or anything else. The &agent= param forces WordPress Assistant open
		// regardless of any last-used-agent cookie.
		$steps = array(
			array(
				'id'    => 'chat',
				'done'  => (int) ( $stats['total_actions'] ?? 0 ) > 0,
				'label' => __( 'Say hi to WordPress Assistant', 'agent-builder' ),
				'url'   => admin_url( 'admin.php?page=agentic-chat&agent=wordpress-assistant' ),
				'cta'   => __( 'Open chat', 'agent-builder' ),
			),
			array(
				'id'    => 'provider',
				'done'  => $is_configured,
				'label' => __( 'Connect an AI provider', 'agent-builder' ),
				'url'   => admin_url( 'admin.php?page=agentic-settings&tab=providers' ),
				'cta'   => __( 'Connect', 'agent-builder' ),
			),
			array(
				'id'    => 'agent',
				'done'  => $agent_counts['active'] > 0,
				'label' => __( 'Activate your first agent', 'agent-builder' ),
				'url'   => admin_url( 'admin.php?page=agentic-agents' ),
				'cta'   => __( 'Browse agents', 'agent-builder' ),
			),
			array(
				'id'    => 'knowledge',
				'done'  => $has_knowledge,
				'label' => __( 'Add knowledge', 'agent-builder' ),
				'url'   => admin_url( 'admin.php?page=agentic-knowledge-wizard' ),
				'cta'   => __( 'Add knowledge', 'agent-builder' ),
			),
		);

		return new \WP_REST_Response(
			array(
				'version'                 => AGENT_BUILDER_VERSION,
				'schema_version'          => (string) get_option( 'agentic_db_schema_version', AGENT_BUILDER_DB_VERSION ),
				'is_pro'                  => $is_pro,
				'is_advanced'             => $is_advanced,
				'is_configured'           => $is_configured,
				'emergency_stop'          => Emergency_Stop::is_active(),
				'show_onboarding'         => '0' !== get_option( 'agentic_show_onboarding', '1' ),
				'agent_updates'           => class_exists( Agent_Updates::class ) && Agent_Updates::is_opted_in(),
				// Pro-only: free / WPorg never expose the opt-in toggle (marketplace link instead).
				'has_agent_updates_class' => class_exists( Agent_Updates::class ) && Agent_Updates::is_remote_check_available(),
				'urls'                    => array(
					'admin'     => admin_url(),
					'icon'      => AGENT_BUILDER_URL . 'assets/icon.svg',
					'license'   => admin_url( 'admin.php?page=agentic-settings&tab=license' ),
					'providers' => admin_url( 'admin.php?page=agentic-settings&tab=providers' ),
					'interface' => admin_url( 'admin.php?page=agentic-settings&tab=interface' ),
					'activity'  => admin_url( 'admin.php?page=agentic-audit-log' ),
					'approvals'      => admin_url( 'admin.php?page=agentic-approvals' ),
					'safety_center'  => admin_url( 'admin.php?page=agentic-safety-center' ),
					'agent_ready'    => admin_url( 'admin.php?page=agentic-agent-ready' ),
					'backups'   => admin_url( 'admin.php?page=agentic-approvals&tab=backups' ),
					'pricing'   => 'https://agentic-plugin.com/pricing/',
					'community' => class_exists( Agent_Updates::class )
						? Agent_Updates::MARKETPLACE_URL
						: 'https://agentic-plugin.com/community-agents/',
					'settings'  => admin_url( 'admin.php?page=agentic-settings' ),
				),
				'license'                 => $license,
				'jobs'                    => array(
					'pending'    => (int) ( $job_health['stats']['pending'] ?? 0 ),
					'processing' => (int) ( $job_health['stats']['processing'] ?? 0 ),
					'completed'  => (int) ( $job_health['stats']['completed'] ?? 0 ),
					'stuck'      => (int) ( $job_health['stuck_processing'] ?? 0 ),
					'abandoned'  => (int) ( $job_health['abandoned_pending'] ?? 0 ),
				),
				'activity'                => array(
					'actions' => (int) ( $stats['total_actions'] ?? 0 ),
					'tokens'  => (int) ( $stats['total_tokens'] ?? 0 ),
					'cost'    => round( (float) ( $stats['total_cost'] ?? 0 ), 4 ),
				),
				'safety'                  => array(
					'pending_approvals'   => (int) $pending_approvals,
					'completed_approvals' => (int) $completed_approvals,
					'file_backups'        => $file_backups,
					'table_backups'       => $table_backups,
				),
				'agent_ready'             => self::agent_ready_summary(),
				'agents'                  => $agent_counts,
				'providers'               => $providers,
				'default_provider'        => $provider,
				'quick_actions'           => $qa_list,
				'onboarding'              => $steps,
				'layout'                  => self::get_layout_for_user(),
				'layout_default'          => self::default_layout(),
				'footer'                  => class_exists( Admin_Menu_Handler::class )
					? ( new Admin_Menu_Handler() )->get_admin_footer_data( 'agent-builder' )
					: array(),
				'warnings'                => $warnings,
			),
			200
		);
	}

	/**
	 * POST /dashboard — mutations.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function post_dashboard( \WP_REST_Request $request ) {
		$action = sanitize_key( (string) $request->get_param( 'action_name' ) );

		switch ( $action ) {
			case 'set_ui_mode':
				$mode = sanitize_key( (string) $request->get_param( 'mode' ) );
				if ( ! in_array( $mode, array( 'basic', 'advanced' ), true ) ) {
					$mode = 'basic';
				}
				Admin_Settings_REST::set_ui_mode( $mode );
				break;

			case 'set_emergency_stop':
				$enable             = rest_sanitize_boolean( $request->get_param( 'enable' ) );
				$emergency_warnings = array();
				if ( $enable && ! Emergency_Stop::is_active() ) {
					Emergency_Stop::enable();
				} elseif ( ! $enable && Emergency_Stop::is_active() ) {
					$emergency_warnings = Emergency_Stop::disable()['warnings'] ?? array();
				}
				return self::get_dashboard( $emergency_warnings );

			case 'set_agent_updates':
				// Free / WPorg: remote update opt-in is not available.
				if ( class_exists( Agent_Updates::class ) && Agent_Updates::is_remote_check_available() ) {
					$on = rest_sanitize_boolean( $request->get_param( 'enable' ) );
					update_option( Agent_Updates::OPT_IN_OPTION, $on ? 'yes' : 'no', false );
					if ( ! $on ) {
						Agent_Updates::bust();
					}
				}
				break;

			case 'save_quick_actions':
				$posted  = $request->get_param( 'actions' );
				$catalog = Admin_Menu_Handler::quick_actions_catalog();
				$enabled = array();
				if ( is_array( $posted ) ) {
					foreach ( $posted as $slug ) {
						$slug = sanitize_key( (string) $slug );
						if ( isset( $catalog[ $slug ] ) ) {
							$enabled[] = $slug;
						}
					}
				}
				if ( ! in_array( 'setup', $enabled, true ) ) {
					$enabled[] = 'setup';
				}
				update_user_meta( get_current_user_id(), Admin_Menu_Handler::QUICK_ACTIONS_META, array_values( array_unique( $enabled ) ) );
				break;

			case 'dismiss_pro_nudge':
				update_user_meta( get_current_user_id(), 'agentic_pro_nudge_dismissed', 1 );
				break;

			case 'save_layout':
				$posted  = $request->get_param( 'layout' );
				$allowed = self::default_layout();
				$order   = array();
				if ( is_array( $posted ) ) {
					foreach ( $posted as $raw_id ) {
						// Keep hyphens (sanitize_key would strip them).
						$cid = strtolower( preg_replace( '/[^a-z0-9\-]/', '', (string) $raw_id ) );
						if ( in_array( $cid, $allowed, true ) && ! in_array( $cid, $order, true ) ) {
							$order[] = $cid;
						}
					}
				}
				foreach ( $allowed as $id ) {
					if ( ! in_array( $id, $order, true ) ) {
						$order[] = $id;
					}
				}
				update_user_meta( get_current_user_id(), self::LAYOUT_META, $order );
				break;

			case 'reset_layout':
				delete_user_meta( get_current_user_id(), self::LAYOUT_META );
				break;

			default:
				return new \WP_Error( 'unknown_action', __( 'Unknown dashboard action.', 'agent-builder' ), array( 'status' => 400 ) );
		}

		return self::get_dashboard();
	}

	/**
	 * License display payload for Status tile. This build has no license path.
	 *
	 * @return array<string,mixed>
	 */
	private static function license_tile(): array {
		return array(
			'status' => 'free',
			'label'  => 'GPL-2.0-or-later',
			'tier'   => 'free',
			'class'  => '',
		);
	}

	/**
	 * Reduce the Agent-Ready Score to what the dashboard card needs — every
	 * other card reads from this single bootstrap payload rather than doing
	 * its own fetch, and this card follows the same pattern.
	 *
	 * @return array<string,mixed>
	 */
	private static function agent_ready_summary(): array {
		if ( ! class_exists( Agent_Ready_Score::class ) ) {
			return array(
				'overall' => 0,
				'grade'   => '',
				'top_fix' => null,
			);
		}

		$score   = Agent_Ready_Score::get_latest();
		$top_fix = null;
		foreach ( (array) ( $score['categories'] ?? array() ) as $id => $check ) {
			if ( ! empty( $check['fixable'] ) && (int) ( $check['score'] ?? 100 ) < 90 ) {
				$top_fix = array(
					'id'     => $id,
					'detail' => $check['detail'] ?? '',
				);
				break;
			}
		}

		return array(
			'overall' => (int) ( $score['overall'] ?? 0 ),
			'grade'   => (string) ( $score['grade'] ?? '' ),
			'top_fix' => $top_fix,
		);
	}

	/**
	 * Ecosystem agent counts.
	 *
	 * @return array<string,int>
	 */
	private static function agent_counts(): array {
		$active       = 0;
		$uploaded     = 0;
		$user_created = 0;

		if ( class_exists( '\Agentic_Agent_Registry' ) ) {
			$registry = \Agentic_Agent_Registry::get_instance();
			$all      = $registry->get_installed_agents();

			$active_slugs = $registry->get_active_agents();
			if ( is_array( $active_slugs ) ) {
				$active_slugs = array_unique( array_filter( array_map( 'sanitize_key', $active_slugs ) ) );
				foreach ( $active_slugs as $slug ) {
					if ( isset( $all[ $slug ] ) ) {
						++$active;
					}
				}
			}

			foreach ( $all as $info ) {
				if ( empty( $info['bundled'] ) && ! empty( $info['directory'] ) ) {
					if ( file_exists( $info['directory'] . '/.uploaded' ) ) {
						++$uploaded;
					} else {
						++$user_created;
					}
				}
			}
		}

		return array(
			'active'       => $active,
			'uploaded'     => $uploaded,
			'user_created' => $user_created,
			'community'    => 0,
		);
	}
}
