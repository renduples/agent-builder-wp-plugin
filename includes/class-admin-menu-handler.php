<?php
/**
 * Admin Menu Handler
 *
 * Owns all wp-admin menu registration, page rendering, and the
 * quickstart-redirect guard. Extracted from the Plugin class so that
 * every concern that touches wp-admin pages lives in one place.
 *
 * @package    Agent_Builder
 * @subpackage Includes
 * @since      2.9.115
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers all Agent Builder wp-admin menu pages and renders their content.
 */
class Admin_Menu_Handler {

	/**
	 * Register all admin menu and submenu pages.
	 *
	 * Hooked to 'admin_menu'.
	 *
	 * @return void
	 */
	public function register(): void {
		add_menu_page(
			__( 'Agent Builder', 'agent-builder' ),
			__( 'Agent Builder', 'agent-builder' ),
			'agentic_view_dashboard',
			'agent-builder',
			fn() => $this->render_page( 'dashboard' ),
			'dashicons-superhero',
			30
		);

		// When no LLM provider is configured, show only "Quick Start" and bail.
		if ( ! $this->any_llm_configured() ) {
			// Remove the auto-generated duplicate top-level submenu entry WordPress adds.
			remove_submenu_page( 'agent-builder', 'agent-builder' );
			add_submenu_page(
				'agent-builder',
				__( 'Agent Builder — Quick Start', 'agent-builder' ),
				__( 'Quick Start', 'agent-builder' ),
				'manage_options',
				'agentic-signup',
				fn() => $this->render_page( 'signup' )
			);
			// Setup wizard — hidden, must be registered so skip link on signup page works.
			add_submenu_page(
				'',
				__( 'Agent Builder — Setup Wizard', 'agent-builder' ),
				__( 'Setup Wizard', 'agent-builder' ),
				'manage_options',
				'agentic-setup',
				fn() => $this->render_page( 'setup' )
			);
			return;
		}

		add_submenu_page(
			'agent-builder',
			__( 'Agent Builder — Dashboard', 'agent-builder' ),
			__( 'Dashboard', 'agent-builder' ),
			'agentic_view_dashboard',
			'agent-builder'
		);

		add_submenu_page(
			'agent-builder',
			__( 'Agent Builder — Chat', 'agent-builder' ),
			__( 'Chat', 'agent-builder' ),
			'agentic_chat_admin_bar',
			'agentic-chat',
			array( $this, 'render_chat_page' )
		);

		$agentic_agents_menu_title = __( 'Agents', 'agent-builder' );
		if ( class_exists( '\Agentic\Agent_Updates' ) ) {
			$agentic_agents_update_count = \Agentic\Agent_Updates::count();
			if ( $agentic_agents_update_count > 0 ) {
				$agentic_agents_menu_title .= sprintf(
					' <span class="update-plugins count-%1$d"><span class="update-count">%1$d</span></span>',
					$agentic_agents_update_count
				);
			}
		}
		add_submenu_page(
			'agent-builder',
			__( 'Agent Builder — Agents', 'agent-builder' ),
			$agentic_agents_menu_title,
			'agentic_manage_agents',
			'agentic-agents',
			fn() => $this->render_page( 'agents' )
		);

		// Publish — deployment surfaces (scheduled tasks, event listeners,
		// Gutenberg blocks, CLI, shortcodes, etc.). Always shown in the menu,
		// same as Tools/Skills/Approvals/Activity — Basic/Advanced only ever
		// affects a page's own content, never whether it's in the nav.
		add_submenu_page(
			'agent-builder',
			__( 'Agent Builder — Publish', 'agent-builder' ),
			__( 'Publish', 'agent-builder' ),
			'agentic_manage_agents',
			'agentic-deployment',
			fn() => $this->render_page( 'deployment' )
		);

		// Hidden page — Run (dedicated execution page).
		add_submenu_page(
			'',
			__( 'Agent Builder — Run', 'agent-builder' ),
			__( 'Run', 'agent-builder' ),
			'agentic_run_tasks_manually',
			'agentic-run-task',
			fn() => $this->render_page( 'run-task' )
		);

		// Hidden page — Agent Creation Wizard ("Train an Agent" guided flow).
		add_submenu_page(
			'',
			__( 'Agent Builder — Train an Agent', 'agent-builder' ),
			__( 'Train an Agent', 'agent-builder' ),
			'manage_options',
			'agentic-agent-wizard',
			fn() => $this->render_page( 'agent-wizard' )
		);

		// Hidden page — Knowledge Wizard (guided "Add knowledge" flow).
		add_submenu_page(
			'',
			__( 'Agent Builder — Add Knowledge', 'agent-builder' ),
			__( 'Add Knowledge', 'agent-builder' ),
			'agentic_manage_settings',
			'agentic-knowledge-wizard',
			fn() => $this->render_page( 'knowledge-wizard' )
		);

		// Hidden page — Publish Wizard (guided "get your agent in front of people" flow).
		add_submenu_page(
			'',
			__( 'Agent Builder — Publish Your Agent', 'agent-builder' ),
			__( 'Publish Your Agent', 'agent-builder' ),
			'manage_options',
			'agentic-deploy-wizard',
			fn() => $this->render_page( 'deploy-wizard' )
		);

		add_submenu_page(
			'agent-builder',
			__( 'Agent Builder — Knowledge', 'agent-builder' ),
			__( 'Knowledge', 'agent-builder' ),
			'agentic_manage_settings',
			'agentic-train-data',
			array( $this, 'render_train_data_page' )
		);

		add_submenu_page(
			'agent-builder',
			__( 'Agent Builder — Tools', 'agent-builder' ),
			__( 'Tools', 'agent-builder' ),
			'agentic_manage_tools',
			'agentic-tools',
			fn() => $this->render_page( 'tools' )
		);

		// Skills — always shown in the menu, same as Tools. The Basic/Advanced
		// split happens on the page itself (admin/skills.php), not by hiding
		// the menu entry.
		add_submenu_page(
			'agent-builder',
			__( 'Agent Builder — Skills', 'agent-builder' ),
			__( 'Skills', 'agent-builder' ),
			'agentic_manage_tools',
			'agentic-skills',
			fn() => $this->render_page( 'skills' )
		);

		$agentic_approvals_title = __( 'Approvals', 'agent-builder' );
		if ( class_exists( '\Agentic\Approval_Queue' ) ) {
			$agentic_pending = ( new \Agentic\Approval_Queue() )->get_pending_count();
			if ( $agentic_pending > 0 ) {
				$agentic_approvals_title .= sprintf(
					' <span class="awaiting-mod count-%1$d"><span class="pending-count">%1$d</span></span>',
					$agentic_pending
				);
			}
		}
		add_submenu_page(
			'agent-builder',
			__( 'Agent Builder — Approvals', 'agent-builder' ),
			$agentic_approvals_title,
			'agentic_manage_agents',
			'agentic-approvals',
			fn() => $this->render_page( 'approvals' )
		);

		// Safety Center — owner-facing overview of existing safety controls.
		// Always in the nav (Basic/Advanced only changes page content, never
		// whether the page exists). Placed after Approvals and before Passport
		// per reports/m2-safety-center-design.md §4. Intentionally not added
		// to the M1 Phase 8a secondary-nav rail (that list is the eight M1
		// target sections).
		add_submenu_page(
			'agent-builder',
			__( 'Agent Builder — Safety Center', 'agent-builder' ),
			__( 'Safety Center', 'agent-builder' ),
			'agentic_manage_settings',
			'agentic-safety-center',
			fn() => $this->render_page( 'safety-center' )
		);

		// Usage / Costs is a Pro screen. Free registers a locked Advanced-only
		// nav entry (not Basic — upsell is a power-user concern). Skip when Pro
		// already owns the real page (same slug agentic-costs).
		$this->register_locked_usage_costs();

		// Passport (page title "Site Passport") — always shown in the menu,
		// same as every other page here; Basic/Advanced only ever affects
		// this page's own content, never nav. Internal slug/render key stay
		// "agent-ready" — this is the same Agent-Ready Score/WebMCP feature,
		// just renamed for clarity ("ready" for what? — "Passport" names the
		// actual concept: what AI agents can discover and access on this site).
		add_submenu_page(
			'agent-builder',
			__( 'Agent Builder — Site Passport', 'agent-builder' ),
			__( 'Passport', 'agent-builder' ),
			'agentic_manage_settings',
			'agentic-agent-ready',
			fn() => $this->render_page( 'agent-ready' )
		);

		// Activity — always shown in the menu; the friendly/technical split
		// happens on the page itself via its own Basic/Advanced switch.
		add_submenu_page(
			'agent-builder',
			__( 'Agent Builder — Activity', 'agent-builder' ),
			__( 'Activity', 'agent-builder' ),
			'agentic_view_audit_log',
			'agentic-audit-log',
			fn() => $this->render_page( 'logs' )
		);

		add_submenu_page(
			'agent-builder',
			__( 'Agent Builder — Settings', 'agent-builder' ),
			__( 'Settings', 'agent-builder' ),
			'agentic_manage_settings',
			'agentic-settings',
			array( $this, 'render_settings_page' )
		);

		// Setup wizard — hidden from nav, accessible at admin.php?page=agentic-setup.
		add_submenu_page(
			'',
			__( 'Agent Builder — Setup Wizard', 'agent-builder' ),
			__( 'Setup Wizard', 'agent-builder' ),
			'manage_options',
			'agentic-setup',
			fn() => $this->render_page( 'setup' )
		);

		// Sign-up page — hidden page shown once after plugin activation.
		add_submenu_page(
			'',
			__( 'Agent Builder — Get Started', 'agent-builder' ),
			'',
			'manage_options',
			'agentic-signup',
			fn() => $this->render_page( 'signup' )
		);
	}

	/**
	 * User meta key for per-screen Basic/Advanced overrides.
	 *
	 * Stores an associative array of screen key => 'basic'|'advanced', e.g.
	 * { "tools": "advanced", "logs": "basic" }. A screen with no entry here
	 * falls back to the site-wide agentic_ui_mode default (see
	 * is_advanced_mode()). Same array-in-user-meta shape as
	 * QUICK_ACTIONS_META above.
	 */
	public const SCREEN_MODE_META = 'agentic_screen_mode';

	/**
	 * Whether the admin UI is in "Advanced" mode.
	 *
	 * When $screen is given, a per-user override for that specific screen
	 * (stored in SCREEN_MODE_META) takes precedence. With no override, or no
	 * $screen argument at all, falls back to the site-wide agentic_ui_mode
	 * option — this is also the only behavior a caller with no $screen ever
	 * sees, so existing callers (including Agent Builder Pro, which reads
	 * this same option) are unaffected.
	 *
	 * @param string $screen Screen key (e.g. 'tools', 'approvals', 'logs',
	 *                        'dashboard', 'settings'), or '' for the
	 *                        site-wide default only. Doesn't have to be an
	 *                        actual top-level menu page — a single tab within
	 *                        a larger screen can use its own key (e.g.
	 *                        'settings-users' for just the Settings > Users
	 *                        tab) to get its own independent mode.
	 * @return bool
	 */
	public static function is_advanced_mode( string $screen = '' ): bool {
		if ( '' !== $screen ) {
			$overrides = get_user_meta( get_current_user_id(), self::SCREEN_MODE_META, true );
			if ( is_array( $overrides ) && isset( $overrides[ $screen ] ) && in_array( $overrides[ $screen ], array( 'basic', 'advanced' ), true ) ) {
				return 'advanced' === $overrides[ $screen ];
			}
		}

		return 'advanced' === get_option( 'agentic_ui_mode', 'basic' );
	}

	/**
	 * Persist a per-screen Basic/Advanced override for the current user.
	 *
	 * @param string $screen Screen key.
	 * @param string $mode   'basic' or 'advanced'.
	 * @return void
	 */
	public static function set_screen_mode( string $screen, string $mode ): void {
		if ( '' === $screen || ! in_array( $mode, array( 'basic', 'advanced' ), true ) ) {
			return;
		}

		$overrides = get_user_meta( get_current_user_id(), self::SCREEN_MODE_META, true );
		if ( ! is_array( $overrides ) ) {
			$overrides = array();
		}

		$overrides[ $screen ] = $mode;
		update_user_meta( get_current_user_id(), self::SCREEN_MODE_META, $overrides );
	}

	/**
	 * Clear all per-screen Basic/Advanced overrides for the current user,
	 * resetting every screen back to the site-wide default.
	 *
	 * @return void
	 */
	public static function reset_screen_modes(): void {
		delete_user_meta( get_current_user_id(), self::SCREEN_MODE_META );
	}

	/**
	 * User meta key for dashboard Quick Actions visibility.
	 */
	public const QUICK_ACTIONS_META = 'agentic_quick_actions_enabled';

	/**
	 * Catalog of major admin links available as dashboard Quick Actions.
	 *
	 * @return array<string, array{label:string,url:string,locked?:bool,default?:bool,group?:string,advanced?:bool,pro?:bool}>
	 */
	public static function quick_actions_catalog(): array {
		$catalog = array(
			'setup'     => array(
				'label'   => __( 'Setup Wizard', 'agent-builder' ),
				'url'     => admin_url( 'admin.php?page=agentic-setup' ),
				'locked'  => true,
				'default' => true,
				'group'   => 'primary',
			),
			'chat'      => array(
				'label'   => __( 'Agent Chat', 'agent-builder' ),
				'url'     => admin_url( 'admin.php?page=agentic-chat' ),
				'default' => true,
				'group'   => 'primary',
			),
			'train'     => array(
				'label'   => __( 'Train an Agent', 'agent-builder' ),
				'url'     => admin_url( 'admin.php?page=agentic-agent-wizard' ),
				'default' => true,
				'group'   => 'primary',
			),
			'agents'    => array(
				'label'   => __( 'Agents', 'agent-builder' ),
				'url'     => admin_url( 'admin.php?page=agentic-agents' ),
				'default' => true,
				'group'   => 'primary',
			),
			'knowledge' => array(
				'label'   => __( 'Knowledge', 'agent-builder' ),
				'url'     => admin_url( 'admin.php?page=agentic-train-data' ),
				'default' => true,
				'group'   => 'primary',
			),
			'activity'  => array(
				'label'   => __( 'Activity', 'agent-builder' ),
				'url'     => admin_url( 'admin.php?page=agentic-audit-log' ),
				'default' => false,
				'group'   => 'secondary',
			),
			'providers' => array(
				'label'   => __( 'Providers', 'agent-builder' ),
				'url'     => admin_url( 'admin.php?page=agentic-settings&tab=providers' ),
				'default' => false,
				'group'   => 'secondary',
			),
			'approvals' => array(
				'label'    => __( 'Approvals', 'agent-builder' ),
				'url'      => admin_url( 'admin.php?page=agentic-approvals' ),
				'default'  => true,
				'group'    => 'secondary',
				'advanced' => true,
			),
			'safety-center' => array(
				'label'   => __( 'Safety Center', 'agent-builder' ),
				'url'     => admin_url( 'admin.php?page=agentic-safety-center' ),
				'default' => true,
				'group'   => 'secondary',
			),
			'tools'     => array(
				'label'    => __( 'Tools', 'agent-builder' ),
				'url'      => admin_url( 'admin.php?page=agentic-tools' ),
				'default'  => true,
				'group'    => 'secondary',
				'advanced' => true,
			),
			'skills'    => array(
				'label'    => __( 'Skills', 'agent-builder' ),
				'url'      => admin_url( 'admin.php?page=agentic-skills' ),
				'default'  => true,
				'group'    => 'secondary',
				'advanced' => true,
			),
			'publish'   => array(
				'label'    => __( 'Publish', 'agent-builder' ),
				'url'      => admin_url( 'admin.php?page=agentic-deployment' ),
				'default'  => true,
				'group'    => 'secondary',
				'advanced' => true,
			),
			'settings'  => array(
				'label'    => __( 'Settings', 'agent-builder' ),
				'url'      => admin_url( 'admin.php?page=agentic-settings' ),
				'default'  => true,
				'group'    => 'secondary',
				'advanced' => true,
			),
		);

		/**
		 * Filter the dashboard Quick Actions catalog.
		 *
		 * @param array $catalog Catalog keyed by action slug.
		 */
		return apply_filters( 'agentic_dashboard_quick_actions_catalog', $catalog );
	}

	/**
	 * Locked "Usage & Costs" nav entry for the free plugin.
	 *
	 * Visible only in Advanced mode, with a small Pro badge. The page is an
	 * honest explanation plus the same pricing link used in page footers —
	 * not a fake costs UI. Hidden (empty parent) in Basic so a bookmark still
	 * resolves. No-op when Pro already registered `agentic-costs`.
	 *
	 * @return void
	 */
	private function register_locked_usage_costs(): void {
		global $submenu;

		if ( isset( $submenu['agent-builder'] ) && is_array( $submenu['agent-builder'] ) ) {
			foreach ( $submenu['agent-builder'] as $item ) {
				if ( isset( $item[2] ) && 'agentic-costs' === $item[2] ) {
					return;
				}
			}
		}

		$parent = self::is_advanced_mode() ? 'agent-builder' : '';
		$title  = sprintf(
			/* translators: 1: page name, 2: "Pro" badge */
			'%1$s <span class="agentic-badge-pill-grey">%2$s</span>',
			esc_html__( 'Usage & Costs', 'agent-builder' ),
			esc_html__( 'Pro', 'agent-builder' )
		);

		add_submenu_page(
			$parent,
			__( 'Agent Builder — Usage & Costs', 'agent-builder' ),
			$title,
			'agentic_view_dashboard',
			'agentic-costs',
			fn() => $this->render_page( 'costs-locked' )
		);
	}

	/**
	 * Enabled Quick Action slugs for the current user (Setup always included).
	 *
	 * @return string[]
	 */
	public static function get_enabled_quick_actions(): array {
		$catalog = self::quick_actions_catalog();
		$stored  = get_user_meta( get_current_user_id(), self::QUICK_ACTIONS_META, true );

		if ( ! is_array( $stored ) ) {
			$enabled = array();
			foreach ( $catalog as $slug => $item ) {
				if ( ! empty( $item['default'] ) || ! empty( $item['locked'] ) ) {
					$enabled[] = $slug;
				}
			}
		} else {
			$enabled = array_values(
				array_filter(
					array_map( 'sanitize_key', $stored ),
					static function ( $slug ) use ( $catalog ) {
						return isset( $catalog[ $slug ] );
					}
				)
			);
		}

		// Setup Wizard is always visible.
		if ( ! in_array( 'setup', $enabled, true ) ) {
			array_unshift( $enabled, 'setup' );
		}

		return array_values( array_unique( $enabled ) );
	}

	/**
	 * Handle save of dashboard Quick Actions visibility (admin-post).
	 *
	 * @return void
	 */
	public function handle_save_quick_actions(): void {
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'agentic_manage_settings' ) ) {
			wp_die( esc_html__( 'You do not have permission to customize Quick Actions.', 'agent-builder' ) );
		}
		check_admin_referer( 'agentic_save_quick_actions' );

		$catalog = self::quick_actions_catalog();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized below.
		$posted = isset( $_POST['agentic_quick_actions'] ) && is_array( $_POST['agentic_quick_actions'] )
			? array_map( 'sanitize_key', wp_unslash( $_POST['agentic_quick_actions'] ) )
			: array();

		$enabled = array();
		foreach ( $posted as $slug ) {
			if ( isset( $catalog[ $slug ] ) ) {
				$enabled[] = $slug;
			}
		}

		// Setup cannot be disabled.
		if ( ! in_array( 'setup', $enabled, true ) ) {
			$enabled[] = 'setup';
		}

		update_user_meta( get_current_user_id(), self::QUICK_ACTIONS_META, array_values( array_unique( $enabled ) ) );

		$redirect = wp_get_referer();
		if ( ! $redirect ) {
			$redirect = admin_url( 'admin.php?page=agent-builder' );
		}
		$redirect = add_query_arg( 'qa_saved', '1', remove_query_arg( array( 'qa_saved' ), $redirect ) );
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Redirect removed Account settings tab (admin_init, before headers).
	 *
	 * Always redirects to Providers — there is no License tab in this build.
	 *
	 * @return void
	 */
	public function maybe_redirect_removed_account_tab(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing only.
		if ( ! isset( $_GET['page'], $_GET['tab'] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'agentic-settings' !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'account' !== sanitize_key( wp_unslash( $_GET['tab'] ) ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'agentic_manage_settings' ) ) {
			return;
		}
		wp_safe_redirect( admin_url( 'admin.php?page=agentic-settings&tab=providers' ) );
		exit;
	}

	/**
	 * Handle Provider CRUD POSTs on admin_init (before headers / admin chrome).
	 *
	 * Must not run inside the settings page template — that loads after
	 * admin-header.php, so wp_safe_redirect() fails and leaves a blank page.
	 *
	 * @return void
	 */
	public function handle_provider_actions(): void {
		if ( ! isset( $_POST['agentic_provider_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}
		if ( ! current_user_can( 'agentic_manage_settings' ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( 'agentic_provider_nonce' );

		$action = sanitize_key( wp_unslash( $_POST['agentic_provider_action'] ?? '' ) );
		$slug   = sanitize_key( wp_unslash( $_POST['provider_slug'] ?? '' ) );

		if ( 'save' === $action ) {
			$auth_type  = sanitize_key( wp_unslash( $_POST['provider_auth_type'] ?? 'bearer' ) );
			$req_format = sanitize_key( wp_unslash( $_POST['provider_req_format'] ?? 'openai' ) );
			$models_raw = sanitize_textarea_field( wp_unslash( $_POST['provider_models'] ?? '' ) );
			$models     = array_values(
				array_filter(
					array_map( 'trim', explode( "\n", $models_raw ) )
				)
			);

			Provider_Registry::upsert(
				array(
					'slug'          => $slug,
					'name'          => sanitize_text_field( wp_unslash( $_POST['provider_name'] ?? '' ) ),
					'endpoint'      => sanitize_text_field( wp_unslash( $_POST['provider_endpoint'] ?? '' ) ),
					'default_model' => sanitize_text_field( wp_unslash( $_POST['provider_default_model'] ?? '' ) ),
					'auth_type'     => $auth_type,
					'req_format'    => $req_format,
					'resp_format'   => sanitize_key( wp_unslash( $_POST['provider_resp_format'] ?? 'openai' ) ),
					'api_key'       => sanitize_text_field( wp_unslash( $_POST['provider_api_key'] ?? '' ) ),
					'key_url'       => sanitize_text_field( wp_unslash( $_POST['provider_key_url'] ?? '' ) ),
					'icon'          => sanitize_text_field( wp_unslash( $_POST['provider_icon'] ?? '' ) ),
					'models'        => $models,
					'vision_model'  => sanitize_text_field( wp_unslash( $_POST['provider_vision_model'] ?? '' ) ),
					'requires_key'  => 'none' !== $auth_type,
					'sort_order'    => absint( wp_unslash( $_POST['provider_sort_order'] ?? 99 ) ),
				)
			);
		} elseif ( 'delete' === $action ) {
			Provider_Registry::delete( $slug );
		} elseif ( 'set_default' === $action ) {
			// Only allow setting a connected provider as default.
			$entry = Provider_Registry::get( $slug );
			if ( $entry && self::provider_is_connected( $entry ) ) {
				update_option( 'agentic_llm_provider', $slug );
				update_option( 'agentic_model', (string) ( $entry['default_model'] ?? '' ) );
			}
		} else {
			return;
		}

		Security_Log::log_system(
			'settings_changed',
			'providers',
			array(
				'setting' => 'provider_' . $action,
				'changes' => array(
					'provider_slug' => $slug,
					'action'        => $action,
				),
			)
		);

		wp_safe_redirect( admin_url( 'admin.php?page=agentic-settings&tab=providers&saved=1' ) );
		exit;
	}

	/**
	 * Whether a provider row is "connected" (usable credentials present).
	 *
	 * @param array<string,mixed> $provider Provider registry entry.
	 */
	public static function provider_is_connected( array $provider ): bool {
		$auth = (string) ( $provider['auth_type'] ?? 'bearer' );
		if ( 'none' === $auth ) {
			// Ollama / local: only connected when a base URL is configured.
			return '' !== (string) get_option( 'agentic_ollama_url', '' );
		}
		if ( empty( $provider['requires_key'] ) ) {
			return true;
		}
		return ! empty( $provider['api_key'] );
	}

	/**
	 * Handle emergency Disable All Agents toggle from the dashboard (admin-post).
	 *
	 * @return void
	 */
	public function handle_set_emergency_stop(): void {
		if ( ! current_user_can( 'agentic_manage_settings' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to use the emergency stop.', 'agent-builder' ) );
		}
		check_admin_referer( 'agentic_set_emergency_stop' );

		$enable = isset( $_POST['agentic_disable_all_agents'] ) && 'yes' === sanitize_key( wp_unslash( $_POST['agentic_disable_all_agents'] ) );
		if ( $enable ) {
			Emergency_Stop::enable();
		} else {
			Emergency_Stop::disable();
		}

		$redirect = wp_get_referer();
		if ( ! $redirect ) {
			$redirect = admin_url( 'admin.php?page=agent-builder' );
		}
		$redirect = add_query_arg( 'emergency_stop', $enable ? 'on' : 'off', remove_query_arg( array( 'emergency_stop' ), $redirect ) );
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Handle the Automatic Agent Updates opt-in/opt-out toggle (admin-post).
	 *
	 * @return void
	 */
	public function handle_set_agent_updates(): void {
		if ( ! current_user_can( 'agentic_manage_settings' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change update settings.', 'agent-builder' ) );
		}
		check_admin_referer( 'agentic_set_agent_updates' );

		// Free / WPorg: no remote agent-update opt-in — ignore.
		if (
			class_exists( '\Agentic\Agent_Updates' )
			&& \Agentic\Agent_Updates::is_remote_check_available()
		) {
			$enabled = isset( $_POST['agentic_agent_updates'] ) && 'yes' === sanitize_key( wp_unslash( $_POST['agentic_agent_updates'] ) );
			update_option( \Agentic\Agent_Updates::OPT_IN_OPTION, $enabled ? 'yes' : 'no' );
			if ( ! $enabled ) {
				\Agentic\Agent_Updates::bust();
			}
		}

		$redirect = wp_get_referer();
		if ( ! $redirect ) {
			$redirect = admin_url( 'admin.php?page=agent-builder' );
		}
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Redirect any Agentic admin page to the Quick Start (signup) page when
	 * no LLM provider is configured yet.
	 *
	 * Runs on admin_init. Exits early on the signup/setup pages themselves
	 * so the user is never trapped in a redirect loop.
	 *
	 * @return void
	 */
	public function maybe_redirect_to_quickstart(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( $this->any_llm_configured() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page identification.
		$page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '';

		// Not an Agentic page — nothing to do.
		if ( 'agent-builder' !== $page && ! str_starts_with( $page, 'agentic-' ) ) {
			return;
		}

		// Already on an allowed page — don't redirect.
		if ( in_array( $page, array( 'agentic-signup', 'agentic-setup' ), true ) ) {
			return;
		}

		// No usable provider — revert onboarding so the setup wizard reappears
		// (a plugin with zero configured providers is non-functional).
		update_option( 'agentic_onboarding_complete', false );

		wp_safe_redirect( admin_url( 'admin.php?page=agentic-signup' ) );
		exit;
	}

	/**
	 * Replace WordPress's bare "Sorry, you are not allowed to access this page."
	 * with a branded, actionable notice when a logged-in user without the
	 * required permissions lands on an Agent Builder admin page.
	 *
	 * Hooked to 'admin_page_access_denied', which WordPress fires immediately
	 * before it wp_die()s in wp-admin/admin.php. We only take over for our own
	 * pages, so denials for other plugins are left untouched.
	 *
	 * @return void
	 */
	public function maybe_show_access_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page identification.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		// Only intervene for Agent Builder pages; leave every other denial alone.
		if ( 'agent-builder' !== $page && ! str_starts_with( $page, 'agentic-' ) ) {
			return;
		}

		$admin_email = get_option( 'admin_email', '' );

		$parts   = array();
		$parts[] = '<h1>' . esc_html__( 'Administrator access required', 'agent-builder' ) . '</h1>';
		$parts[] = '<p>' . esc_html__( 'Agent Builder can only be set up and managed by a site administrator. Your account does not have the required permissions, so we cannot continue here.', 'agent-builder' ) . '</p>';
		$parts[] = '<p>' . esc_html__( 'Please sign in with an administrator account, or ask a user with administrator access to complete the setup.', 'agent-builder' ) . '</p>';
		if ( is_email( $admin_email ) ) {
			$parts[] = '<p>' . sprintf(
				/* translators: %s: site administrator email address, rendered as a mailto link. */
				esc_html__( 'Need help? Contact your site administrator at %s.', 'agent-builder' ),
				'<a href="mailto:' . esc_attr( $admin_email ) . '">' . esc_html( $admin_email ) . '</a>'
			) . '</p>';
		}
		$parts[] = '<p><a href="' . esc_url( admin_url() ) . '">' . esc_html__( 'Back to the WordPress dashboard', 'agent-builder' ) . '</a></p>';

		wp_die(
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each $parts fragment is individually escaped (esc_html__/esc_attr/esc_url) above.
			implode( '', $parts ),
			esc_html__( 'Administrator access required', 'agent-builder' ),
			array( 'response' => 403 )
		);
	}

	/**
	 * Catalog for the Advanced-mode secondary nav rail (M1 Phase 8a).
	 *
	 * Eight target sections from reports/m1-modes-design.md §3. Each entry
	 * maps 1:1 onto an existing add_submenu_page() slug — no new URLs.
	 * Tools and Skills are one grouped entry with two child links, the same
	 * grouped-nav pattern Settings already uses (label + rows), not a merge
	 * of the underlying pages.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_secondary_nav_items(): array {
		return array(
			array(
				'id'    => 'dashboard',
				'label' => __( 'Dashboard', 'agent-builder' ),
				'url'   => admin_url( 'admin.php?page=agent-builder' ),
				'pages' => array( 'agent-builder' ),
				'cap'   => 'agentic_view_dashboard',
			),
			array(
				'id'    => 'agents',
				'label' => __( 'Agents', 'agent-builder' ),
				'url'   => admin_url( 'admin.php?page=agentic-agents' ),
				'pages' => array( 'agentic-agents' ),
				'cap'   => 'agentic_manage_agents',
			),
			array(
				'id'       => 'tools-skills',
				'label'    => __( 'Tools & Skills', 'agent-builder' ),
				'url'      => admin_url( 'admin.php?page=agentic-tools' ),
				'pages'    => array( 'agentic-tools', 'agentic-skills' ),
				'cap'      => 'agentic_manage_tools',
				'children' => array(
					array(
						'id'    => 'tools',
						'label' => __( 'Tools', 'agent-builder' ),
						'url'   => admin_url( 'admin.php?page=agentic-tools' ),
						'pages' => array( 'agentic-tools' ),
					),
					array(
						'id'    => 'skills',
						'label' => __( 'Skills', 'agent-builder' ),
						'url'   => admin_url( 'admin.php?page=agentic-skills' ),
						'pages' => array( 'agentic-skills' ),
					),
				),
			),
			array(
				'id'    => 'knowledge',
				'label' => __( 'Knowledge', 'agent-builder' ),
				'url'   => admin_url( 'admin.php?page=agentic-train-data' ),
				'pages' => array( 'agentic-train-data' ),
				'cap'   => 'agentic_manage_settings',
			),
			array(
				'id'    => 'logs',
				'label' => __( 'Logs', 'agent-builder' ),
				'url'   => admin_url( 'admin.php?page=agentic-audit-log' ),
				'pages' => array( 'agentic-audit-log' ),
				'cap'   => 'agentic_view_audit_log',
			),
			array(
				'id'    => 'usage-costs',
				'label' => __( 'Usage & Costs', 'agent-builder' ),
				'url'   => admin_url( 'admin.php?page=agentic-costs' ),
				'pages' => array( 'agentic-costs' ),
				'cap'   => 'agentic_view_dashboard',
				'pro'   => true,
			),
			array(
				'id'    => 'providers',
				'label' => __( 'Providers & Keys', 'agent-builder' ),
				'url'   => admin_url( 'admin.php?page=agentic-settings&tab=providers' ),
				'pages' => array( 'agentic-settings' ),
				'tabs'  => array( 'providers' ),
				'cap'   => 'agentic_manage_settings',
			),
			array(
				'id'           => 'settings',
				'label'        => __( 'Settings', 'agent-builder' ),
				'url'          => admin_url( 'admin.php?page=agentic-settings' ),
				'pages'        => array( 'agentic-settings' ),
				'exclude_tabs' => array( 'providers' ),
				'cap'          => 'agentic_manage_settings',
			),
		);
	}

	/**
	 * Whether a secondary-nav item (or child) matches the current admin page.
	 *
	 * @param array<string, mixed> $item Item from get_secondary_nav_items().
	 * @param string               $page Current ?page= slug.
	 * @param string               $tab  Current ?tab= / ?section= value.
	 * @return bool
	 */
	private function is_secondary_nav_item_current( array $item, string $page, string $tab ): bool {
		$pages = $item['pages'] ?? array();
		if ( ! in_array( $page, $pages, true ) ) {
			return false;
		}
		if ( ! empty( $item['tabs'] ) ) {
			return in_array( $tab, $item['tabs'], true );
		}
		if ( ! empty( $item['exclude_tabs'] ) ) {
			return ! in_array( $tab, $item['exclude_tabs'], true );
		}
		return true;
	}

	/**
	 * Print one secondary-nav link (label, optional Pro badge, current state).
	 *
	 * @param array<string, mixed> $item Item or child item.
	 * @param string               $page Current ?page= slug.
	 * @param string               $tab  Current ?tab= / ?section= value.
	 * @return void
	 */
	private function render_secondary_nav_link( array $item, string $page, string $tab ): void {
		$is_current = $this->is_secondary_nav_item_current( $item, $page, $tab );
		$classes    = 'agentic-secondary-nav__item';
		if ( $is_current ) {
			$classes .= ' is-active';
		}
		?>
		<a href="<?php echo esc_url( (string) ( $item['url'] ?? '#' ) ); ?>"
			class="<?php echo esc_attr( $classes ); ?>"
			data-section="<?php echo esc_attr( (string) ( $item['id'] ?? '' ) ); ?>"
			<?php echo $is_current ? 'aria-current="page"' : ''; ?>>
			<?php echo esc_html( (string) ( $item['label'] ?? '' ) ); ?>
			<?php if ( ! empty( $item['pro'] ) ) : ?>
				<span class="agentic-badge-pill-grey"><?php esc_html_e( 'Pro', 'agent-builder' ); ?></span>
			<?php endif; ?>
		</a>
		<?php
	}

	/**
	 * Advanced-mode secondary nav rail. Echoed from admin_footer (same hook
	 * as the shared page footer) and moved to the top of `.wrap` by a small
	 * inline script — the existing shared-chrome pattern, not a new one.
	 *
	 * Gated on the site-wide default (`is_advanced_mode()` with no $screen).
	 * The rail spans every Agentic screen, so a per-screen content override
	 * must not hide or show it.
	 *
	 * @param string $page Current ?page= slug.
	 * @param string $tab  Current ?tab= / ?section= value.
	 * @return void
	 */
	private function render_secondary_nav( string $page, string $tab ): void {
		if ( ! self::is_advanced_mode() ) {
			return;
		}

		// Full-page onboarding overlays — no in-page chrome.
		if ( in_array( $page, array( 'agentic-setup', 'agentic-signup' ), true ) ) {
			return;
		}

		$items = array_values(
			array_filter(
				$this->get_secondary_nav_items(),
				static function ( array $item ): bool {
					$cap = (string) ( $item['cap'] ?? '' );
					return '' === $cap || current_user_can( $cap );
				}
			)
		);
		if ( empty( $items ) ) {
			return;
		}
		?>
		<nav id="agentic-secondary-nav" class="agentic-secondary-nav" aria-label="<?php esc_attr_e( 'Agent Builder sections', 'agent-builder' ); ?>">
			<ul class="agentic-secondary-nav__list">
				<?php foreach ( $items as $item ) : ?>
					<?php
					$children = $item['children'] ?? array();
					$group_on = false;
					if ( ! empty( $children ) ) {
						foreach ( $children as $child ) {
							if ( $this->is_secondary_nav_item_current( $child, $page, $tab ) ) {
								$group_on = true;
								break;
							}
						}
					}
					?>
					<li class="agentic-secondary-nav__entry<?php echo $group_on ? ' is-current' : ''; ?>">
						<?php if ( ! empty( $children ) ) : ?>
							<div class="agentic-secondary-nav__group">
								<span class="agentic-secondary-nav__group-label"><?php echo esc_html( (string) $item['label'] ); ?></span>
								<ul class="agentic-secondary-nav__group-items">
									<?php foreach ( $children as $child ) : ?>
										<li><?php $this->render_secondary_nav_link( $child, $page, $tab ); ?></li>
									<?php endforeach; ?>
								</ul>
							</div>
						<?php else : ?>
							<?php $this->render_secondary_nav_link( $item, $page, $tab ); ?>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</nav>
		<script>
		(function() {
			function agenticPlaceSecondaryNav() {
				var nav = document.getElementById( 'agentic-secondary-nav' );
				if ( ! nav || nav.classList.contains( 'is-placed' ) ) {
					return true;
				}
				var wrap = document.querySelector( '#wpbody-content .wrap' );
				if ( ! wrap ) {
					var root = document.getElementById( 'agentic-dashboard-app-root' );
					if ( ! root || ! root.parentNode ) {
						return false;
					}
					wrap = document.createElement( 'div' );
					wrap.className = 'wrap agentic-admin';
					root.parentNode.insertBefore( wrap, root );
					wrap.appendChild( root );
				}
				wrap.insertBefore( nav, wrap.firstChild );
				wrap.classList.add( 'agentic-has-secondary-nav' );
				nav.classList.add( 'is-placed' );
				return true;
			}
			if ( ! agenticPlaceSecondaryNav() ) {
				document.addEventListener( 'DOMContentLoaded', agenticPlaceSecondaryNav );
				window.setTimeout( agenticPlaceSecondaryNav, 0 );
				window.setTimeout( agenticPlaceSecondaryNav, 400 );
			}
		})();
		</script>
		<?php
	}

	/**
	 * Site-wide Basic/Advanced switch. Echoed from admin_footer (same hook
	 * as the secondary nav and page footer) and moved to the top of `.wrap`
	 * by a small inline script — shared chrome, not a per-template include.
	 *
	 * Reflects the site-wide `agentic_ui_mode` default (`is_advanced_mode()`
	 * with no $screen), not a per-screen override. Writes through
	 * Admin_Settings_REST::set_ui_mode() via the admin-page REST action.
	 *
	 * @param string $page Current ?page= slug.
	 * @return void
	 */
	private function render_global_mode_switch( string $page ): void {
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'agentic_manage_settings' ) ) {
			return;
		}

		// Full-page onboarding overlays — no in-page chrome.
		if ( in_array( $page, array( 'agentic-setup', 'agentic-signup' ), true ) ) {
			return;
		}

		$is_advanced = self::is_advanced_mode();
		?>
		<div id="agentic-global-mode-switch" class="agentic-global-mode" role="group" aria-label="<?php esc_attr_e( 'Site-wide interface mode', 'agent-builder' ); ?>">
			<span class="agentic-global-mode__label"><?php esc_html_e( 'Site-wide', 'agent-builder' ); ?></span>
			<span class="agentic-screen-mode-toggle">
				<button type="button" class="button button-small<?php echo $is_advanced ? '' : ' button-primary'; ?>" data-mode="basic">
					<?php esc_html_e( 'Basic', 'agent-builder' ); ?>
				</button>
				<button type="button" class="button button-small<?php echo $is_advanced ? ' button-primary' : ''; ?>" data-mode="advanced">
					<?php esc_html_e( 'Advanced', 'agent-builder' ); ?>
				</button>
			</span>
		</div>
		<script>
		(function() {
			function agenticPlaceGlobalModeSwitch() {
				var el = document.getElementById( 'agentic-global-mode-switch' );
				if ( ! el || el.classList.contains( 'is-placed' ) ) {
					return true;
				}
				var wrap = document.querySelector( '#wpbody-content .wrap' );
				if ( ! wrap ) {
					var root = document.getElementById( 'agentic-dashboard-app-root' );
					if ( ! root || ! root.parentNode ) {
						return false;
					}
					wrap = document.createElement( 'div' );
					wrap.className = 'wrap agentic-admin';
					root.parentNode.insertBefore( wrap, root );
					wrap.appendChild( root );
				}
				wrap.insertBefore( el, wrap.firstChild );
				wrap.classList.add( 'agentic-has-global-mode' );
				el.classList.add( 'is-placed' );
				return true;
			}
			if ( ! agenticPlaceGlobalModeSwitch() ) {
				document.addEventListener( 'DOMContentLoaded', agenticPlaceGlobalModeSwitch );
				window.setTimeout( agenticPlaceGlobalModeSwitch, 0 );
				window.setTimeout( agenticPlaceGlobalModeSwitch, 400 );
			}

			var toggle = document.getElementById( 'agentic-global-mode-switch' );
			if ( ! toggle ) {
				return;
			}
			Array.prototype.forEach.call( toggle.querySelectorAll( 'button[data-mode]' ), function ( btn ) {
				btn.addEventListener( 'click', function () {
					if ( btn.disabled || btn.classList.contains( 'button-primary' ) ) {
						return;
					}
					Array.prototype.forEach.call( toggle.querySelectorAll( 'button[data-mode]' ), function ( b ) {
						b.disabled = true;
					} );
					fetch( <?php echo wp_json_encode( esc_url_raw( rest_url( 'agentic/v1/admin-page' ) ) ); ?>, {
						method: 'POST',
						credentials: 'same-origin',
						headers: {
							'Content-Type': 'application/json',
							'X-WP-Nonce': <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>
						},
						body: JSON.stringify( {
							action_name: 'set_ui_mode',
							mode: btn.getAttribute( 'data-mode' )
						} )
					} ).then( function ( res ) {
						if ( ! res.ok ) {
							Array.prototype.forEach.call( toggle.querySelectorAll( 'button[data-mode]' ), function ( b ) {
								b.disabled = false;
							} );
							return;
						}
						window.location.reload();
					} ).catch( function () {
						Array.prototype.forEach.call( toggle.querySelectorAll( 'button[data-mode]' ), function ( b ) {
							b.disabled = false;
						} );
					} );
				} );
			} );
		})();
		</script>
		<?php
	}

	/**
	 * Inject the site-wide mode switch (top of .wrap), Advanced-mode
	 * secondary nav rail, and footer legal links on all Agentic admin
	 * pages via JavaScript.
	 *
	 * Runs on admin_footer so the full page DOM is already in place. Same
	 * shared-chrome hook for every piece — not a per-template include.
	 *
	 * @return void
	 */
	public function render_admin_page_links(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only, page identification only.
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';

		// Only on Agentic admin pages.
		if ( 'agent-builder' !== $page && ! str_starts_with( $page, 'agentic-' ) ) {
			return;
		}

		// Skip the setup wizard — it renders a full-page custom overlay.
		if ( 'agentic-setup' === $page ) {
			return;
		}

		// Resolve active tab — settings/deployment use ?tab=, tools uses ?section=.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only.
		$tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : '';
		if ( ! $tab ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only.
			$tab = isset( $_GET['section'] ) ? sanitize_text_field( wp_unslash( $_GET['section'] ) ) : '';
		}

		$this->render_secondary_nav( $page, $tab );
		// After the rail so this insertBefore(firstChild) lands above it.
		$this->render_global_mode_switch( $page );

		// Footer markup (promo URL is channel-aware: external on WPorg free).
		$footer_html = $this->build_admin_footer_html( $page, $tab );
		?>
		<script>
		(function() {
			function agenticAppendPageFooter() {
				if ( document.querySelector( '.agentic-page-footer' ) ) {
					return true;
				}
				// React admin-pages (and similar) render their own footer from localized data.
				if ( document.getElementById( 'agentic-admin-pages-root' )
					|| document.getElementById( 'agentic-settings-app-root' )
					|| document.getElementById( 'agentic-dashboard-app-root' ) ) {
					return true;
				}
				var wrap = document.querySelector( '.wrap' );
				if ( ! wrap ) {
					return false;
				}
				var footerDiv = document.createElement( 'div' );
				footerDiv.innerHTML = <?php echo wp_json_encode( $footer_html ); ?>;
				if ( footerDiv.firstChild ) {
					wrap.appendChild( footerDiv.firstChild );
				}
				return true;
			}
			// Classic pages: DOM ready. React shells skip inject (component owns footer).
			if ( ! agenticAppendPageFooter() ) {
				document.addEventListener( 'DOMContentLoaded', agenticAppendPageFooter );
				window.setTimeout( agenticAppendPageFooter, 0 );
				window.setTimeout( agenticAppendPageFooter, 400 );
			}
		})();
		</script>
		<?php
	}

	/**
	 * Footer link payload for React (and shared HTML builder).
	 *
	 * @param string $page Menu page slug.
	 * @param string $tab  Active tab.
	 * @return array<string, mixed>
	 */
	public function get_admin_footer_data( string $page, string $tab = '' ): array {
		$doc_url        = $this->get_page_doc_url( $page, $tab );
		$support_url    = 'https://agentic-plugin.com/support/';
		$promo_url      = 'https://agentic-plugin.com/pricing/';
		$promo_label    = __( 'Upgrade to Pro', 'agent-builder' );
		$promo_external = true;

		// Short policy blurb — contextual by page/tab.
		$policy = __( 'Settings control how agents behave on this site. Changes are stored locally and take effect for new conversations.', 'agent-builder' );
		if ( 'agent-builder' === $page ) {
			$policy = __( 'The dashboard summarizes agents, approvals, activity, and providers at a glance. Open any card\'s own page for full control.', 'agent-builder' );
		} elseif ( 'agentic-tools' === $page ) {
			$policy = __( 'Agents only use the tools you allow. Higher-risk actions still follow Approvals and your safety settings.', 'agent-builder' );
		} elseif ( str_starts_with( $page, 'agentic-train' ) ) {
			$policy = __( 'Knowledge stays on your site for the free wiki; hosted vector features require Agent Builder Pro.', 'agent-builder' );
			if ( 'instructions' === $tab ) {
				$policy = __( 'Per-agent instructions shape tone and greetings. Site-wide knowledge belongs in the Knowledge Wiki.', 'agent-builder' );
			} elseif ( 'memory' === $tab ) {
				$policy = __( 'Local memory is optional and site-owned. Turn it off if you prefer agents not to retain short notes across chats.', 'agent-builder' );
			}
		} elseif ( 'agentic-approvals' === $page ) {
			$policy = __( 'Approvals keep high-risk tool calls under human control before they change your site.', 'agent-builder' );
		} elseif ( 'agentic-safety-center' === $page ) {
			$policy = __( 'Safety Center summarizes existing operator controls. It does not change how tools, approvals, or Emergency Stop work.', 'agent-builder' );
		} elseif ( 'agentic-audit-log' === $page || 'agentic-logs' === $page ) {
			$policy = __( 'Activity helps you understand what agents did. Logs are local; retention follows your Security settings.', 'agent-builder' );
		} elseif ( 'agentic-costs' === $page ) {
			$policy = __( 'Usage & Costs is part of Agent Builder Pro. The free plugin does not meter spend.', 'agent-builder' );
		} elseif ( 'agentic-settings' === $page ) {
			$policy = match ( $tab ) {
				'interface' => __( 'Interface settings change how chat looks and how agents address people. Theme applies to admin and frontend chat.', 'agent-builder' ),
				'agents'    => __( 'Agent chat features apply site-wide. Per-agent provider/model overrides only when needed. Emergency stop deactivates all agents.', 'agent-builder' ),
				'providers' => __( 'API keys stay on your site. Only connected providers can be set as the site default.', 'agent-builder' ),
				'users'     => __( 'Role privileges control who can administer the plugin and chat with agents. Administrators always retain full access.', 'agent-builder' ),
				'security'  => __( 'Security settings include consent, retention, scanning, and request rate limits. Review Approvals for high-risk actions.', 'agent-builder' ),
				'apis', 'endpoints' => __( 'Advanced integrations and API endpoints. Prefer connected providers on the Providers tab for everyday use.', 'agent-builder' ),
				default     => $policy,
			};
		}

		return array(
			'doc_url'        => $doc_url,
			'support_url'    => $support_url,
			'promo_url'      => $promo_url,
			'promo_label'    => $promo_label,
			'promo_external' => $promo_external,
			'is_pro'         => false,
			'policy'         => $policy,
			'terms_url'      => 'https://agentic-plugin.com/terms-of-service/',
			'privacy_url'    => 'https://agentic-plugin.com/privacy-policy/',
			'gdpr_url'       => 'https://agentic-plugin.com/gdpr-policy/',
		);
	}

	/**
	 * HTML for the standard admin page footer bar.
	 *
	 * @param string $page Page slug.
	 * @param string $tab  Tab.
	 * @return string
	 */
	public function build_admin_footer_html( string $page, string $tab = '' ): string {
		$f = $this->get_admin_footer_data( $page, $tab );

		$html  = '<div class="agentic-page-footer">';
		$html .= '<span class="agentic-page-footer-left">';
		if ( ! empty( $f['policy'] ) ) {
			$html .= '<span class="agentic-page-footer-policy">' . esc_html( (string) $f['policy'] ) . '</span> ';
		}
		$html       .= esc_html__( 'Need help?', 'agent-builder' ) . ' ';
		$html       .= '<a href="' . esc_url( (string) $f['support_url'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'Visit our Support Center', 'agent-builder' ) . '</a>';
		$html       .= ' | ';
		$html       .= '<a href="' . esc_url( (string) $f['doc_url'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'Documentation', 'agent-builder' ) . '</a>';
		$html       .= ' | ';
		$promo_blank = ! empty( $f['promo_external'] ) || ! empty( $f['is_pro'] );
		$html       .= '<a href="' . esc_url( (string) $f['promo_url'] ) . '"' . ( $promo_blank ? ' target="_blank" rel="noopener noreferrer"' : '' ) . '>' . esc_html( (string) $f['promo_label'] ) . '</a>';
		$html       .= '</span>';
		$html       .= '<span class="agentic-page-footer-right">';
		$html       .= '<a href="' . esc_url( (string) $f['terms_url'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'Terms of Service', 'agent-builder' ) . '</a>';
		$html       .= ' | ';
		$html       .= '<a href="' . esc_url( (string) $f['privacy_url'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'Privacy Policy', 'agent-builder' ) . '</a>';
		$html       .= ' | ';
		$html       .= '<a href="' . esc_url( (string) $f['gdpr_url'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'GDPR Policy', 'agent-builder' ) . '</a>';
		$html       .= '</span></div>';

		return $html;
	}

	/**
	 * Include a simple admin page template.
	 *
	 * @param string $file Template filename (without .php extension) inside admin/.
	 * @return void
	 */
	public function render_page( string $file ): void {
		// React admin pages (skip agents — plugins-style list later).
		// Deployment stays on classic PHP (multi-tab shortcodes/tasks/events UI).
		$react_map = array(
			'tools'       => array(
				'page' => 'tools',
				'tab'  => '',
			),
			'skills'      => array(
				'page' => 'skills',
				'tab'  => '',
			),
			'approvals'   => array(
				'page' => 'approvals',
				'tab'  => '',
			),
			'logs'        => array(
				'page' => 'logs',
				'tab'  => '',
			),
			'upgrade-pro' => array(
				'page' => 'upgrade-pro',
				'tab'  => '',
			),
			'agent-ready'   => array(
				'page' => 'agent-ready',
				'tab'  => '',
			),
			'safety-center' => array(
				'page' => 'safety-center',
				'tab'  => '',
			),
		);

		// Skills create/edit/hub stay on classic PHP forms.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'skills' === $file && isset( $_GET['skill_view'] ) && 'list' !== sanitize_key( wp_unslash( $_GET['skill_view'] ) ) ) {
			include AGENT_BUILDER_DIR . "admin/{$file}.php";
			return;
		}

		// Approvals backups tab stays classic for now.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'approvals' === $file && isset( $_GET['tab'] ) && 'backups' === sanitize_key( wp_unslash( $_GET['tab'] ) ) ) {
			include AGENT_BUILDER_DIR . "admin/{$file}.php";
			return;
		}

		if ( isset( $react_map[ $file ] ) && React_Admin::enqueue( 'admin-pages' ) ) {
			// Skills can swap to a chat embed at any time — mode can toggle
			// client-side without a page reload — so load chat.css unconditionally
			// here rather than only when the initial request happens to be Basic.
			if ( 'skills' === $file ) {
				wp_enqueue_style(
					'agentic-chat',
					AGENT_BUILDER_URL . 'assets/css/chat.css',
					array(),
					AGENT_BUILDER_VERSION
				);
				Chat_Assets::maybe_add_chat_theme_overrides();
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : $react_map[ $file ]['tab'];
			// Classic tools.php used ?category= — keep bookmarks working (read-only routing).
			if ( 'tools' === $file && '' === $tab && isset( $_GET['category'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin navigation.
				$tab = sanitize_key( wp_unslash( $_GET['category'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin navigation.
			}
			if ( 'logs' === $file && '' === $tab ) {
				$tab = 'audit';
			}
			if ( 'approvals' === $file && '' === $tab ) {
				$tab = 'approvals';
			}
			if ( 'tools' === $file && '' === $tab ) {
				$tab = 'all';
			}
			// Map react file keys to real menu page slugs for doc lookup.
			$menu_page = match ( $file ) {
				'tools'          => 'agentic-tools',
				'skills'         => 'agentic-skills',
				'approvals'      => 'agentic-approvals',
				'logs'           => 'agentic-audit-log',
				'upgrade'        => 'agentic-upgrade-pro',
				'train-data'     => 'agentic-train-data',
				'safety-center'  => 'agentic-safety-center',
				default          => 'agentic-' . $file,
			};
			wp_localize_script(
				'agentic-admin-pages',
				'agenticAdminPage',
				array(
					'page'    => $react_map[ $file ]['page'],
					'tab'     => $tab,
					'restUrl' => rest_url( 'agentic/v1/' ),
					'nonce'   => wp_create_nonce( 'wp_rest' ),
					'footer'  => $this->get_admin_footer_data( $menu_page, $tab ),
				)
			);
			// Outer .wrap so admin_footer can attach the policy/docs bar.
			// The per-page class lets CSS target one React admin screen
			// without affecting the others that share this same markup.
			printf( '<div class="wrap agentic-admin agentic-admin-page-%s">', esc_attr( $file ) );
			React_Admin::mount( 'agentic-admin-pages-root' );
			echo '</div>';
			return;
		}

		include AGENT_BUILDER_DIR . "admin/{$file}.php";
	}

	/**
	 * Render Agent Chat page.
	 *
	 * @return void
	 */
	public function render_chat_page(): void {
		wp_enqueue_style(
			'agentic-chat',
			AGENT_BUILDER_URL . 'assets/css/chat.css',
			array(),
			AGENT_BUILDER_VERSION
		);
		\Agentic\Chat_Assets::maybe_add_chat_theme_overrides();

		wp_enqueue_script(
			'agentic-chat',
			AGENT_BUILDER_URL . 'assets/js/chat.js',
			array( 'agentic-ui' ),
			AGENT_BUILDER_VERSION,
			true
		);

		// Resolve agent slug (same priority as chat-interface.php: URL → cookie →
		// WordPress Assistant, if the user has never chatted with anything yet → first).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$agentic_chat_slug = isset( $_GET['agent'] ) ? sanitize_key( $_GET['agent'] ) : '';
		if ( ! $agentic_chat_slug && isset( $_COOKIE['agentic_last_agent'] ) ) {
			$agentic_chat_slug = sanitize_key( $_COOKIE['agentic_last_agent'] );
		}
		if ( ! $agentic_chat_slug ) {
			$agentic_chat_accessible = \Agentic_Agent_Registry::get_instance()->get_accessible_instances();
			if ( isset( $agentic_chat_accessible['wordpress-assistant'] ) ) {
				$agentic_chat_slug = 'wordpress-assistant';
			}
		}
		$agentic_chat_features = agentic_get_effective_chat_features( $agentic_chat_slug );

		wp_localize_script(
			'agentic-chat',
			'agenticChat',
			array(
				'restUrl'        => rest_url( 'agentic/v1/' ),
				'nonce'          => wp_create_nonce( 'wp_rest' ),
				'userId'         => get_current_user_id(),
				'userName'       => wp_get_current_user()->display_name,
				'audio'          => $agentic_chat_features['audio'],
				'vision'         => $agentic_chat_features['vision'],
				'costs'          => $agentic_chat_features['costs'],
				'tts'            => $agentic_chat_features['tts'],
				'ttsVoice'       => get_option( 'agentic_tts_voice', 'journey-f' ),
				'consentEnabled' => get_option( 'agentic_chat_consent_enabled', false ) ? '1' : '0',
				'consentText'    => \Agentic\GDPR::get_consent_text(),
				'isAdmin'        => current_user_can( 'manage_options' ) ? '1' : '0',
				'adminUrl'       => admin_url(),
				'adminAgentsUrl' => admin_url( 'admin.php?page=agentic-agents' ),
				// Agent-to-agent handoff context (set when arriving via a delegate
				// button). Without these the admin chat drops the handoff silently.
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display params from a same-site delegate link.
				'initialMessage' => isset( $_GET['initial_message'] ) ? sanitize_textarea_field( wp_unslash( $_GET['initial_message'] ) ) : '',
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				'handoffFrom'    => isset( $_GET['handoff_from'] ) ? sanitize_key( wp_unslash( $_GET['handoff_from'] ) ) : '',
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				'handoffContext' => isset( $_GET['handoff_context'] ) ? sanitize_textarea_field( wp_unslash( $_GET['handoff_context'] ) ) : '',
				'slashCommands'  => \Agentic\Chat_Assets::get_slash_commands_for_js(),
				'i18n'           => agentic_chat_i18n(),
			)
		);

		// Playground is Chat-only Advanced chrome. Gated on is_advanced_mode('chat')
		// so it follows the site-wide default (Phase 8b header switch) and would
		// honour a Chat per-screen override if one is ever set. No toggle is
		// added on this page — Basic mode stays pixel-identical, including
		// Skills/Publish embeds of chat-interface.php, which never reach here.
		$agentic_playground = self::is_advanced_mode( 'chat' );
		if ( $agentic_playground ) {
			wp_enqueue_script(
				'agentic-chat-playground',
				AGENT_BUILDER_URL . 'assets/js/chat-playground.js',
				array( 'agentic-chat' ),
				AGENT_BUILDER_VERSION,
				true
			);
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Agent Chat', 'agent-builder' ) . ' <span class="agentic-status" style="font-size: 14px; font-weight: normal; vertical-align: middle;"><span class="agentic-status-dot"></span>' . esc_html__( 'Online', 'agent-builder' ) . '</span></h1>';
		if ( $agentic_playground ) {
			echo '<div class="agentic-playground">';
			echo '<div class="agentic-playground__thread">';
		}
		include AGENT_BUILDER_DIR . 'templates/chat-interface.php';
		if ( $agentic_playground ) {
			echo '</div>';
			$this->render_chat_playground_panel(
				isset( $agentic_current_agent_id ) ? (string) $agentic_current_agent_id : '',
				( isset( $agentic_current_agent ) && $agentic_current_agent instanceof Agent_Base ) ? $agentic_current_agent : null
			);
			echo '</div>';
		}
		echo '<p style="margin-top:12px;"><a href="' . esc_url( admin_url( 'admin.php?page=agentic-deployment' ) ) . '">' . esc_html__( 'Manage Agent Deployments', 'agent-builder' ) . '</a></p>';
		echo '</div>';
	}

	/**
	 * Advanced-mode Playground side panel for Agent Chat.
	 *
	 * Read-only: handle_chat() does not accept per-request model/temperature
	 * overrides (temperature is hardcoded in Llm_Client for OpenAI-compatible
	 * providers). Agent-level override_provider/override_model are persisted
	 * settings, not playground controls — shown as the effective model, not
	 * as interactive widgets.
	 *
	 * @param string          $agent_id Current agent slug.
	 * @param Agent_Base|null $agent    Current agent instance, if resolved.
	 * @return void
	 */
	private function render_chat_playground_panel( string $agent_id, ?Agent_Base $agent ): void {
		$effective        = function_exists( 'agentic_get_effective_provider_model' )
			? agentic_get_effective_provider_model( $agent_id )
			: array(
				'provider'       => '',
				'model'          => '',
				'vision_model'   => '',
				'provider_label' => '',
			);
		$ov_provider      = Agent_Settings::get( $agent_id, 'override_provider' );
		$ov_model         = Agent_Settings::get( $agent_id, 'override_model' );
		$model_source     = ( ! empty( $ov_provider ) || ! empty( $ov_model ) )
			? __( 'Per-agent override (Settings → Agents)', 'agent-builder' )
			: __( 'Site default (Settings → Providers)', 'agent-builder' );
		$system_prompt    = ( $agent instanceof Agent_Base ) ? $agent->get_system_prompt() : '';
		$persona_notes    = Agent_Settings::get( $agent_id, 'persona_notes' );
		$style            = Agent_Settings::get( $agent_id, 'persona_response_style' );
		$tools            = class_exists( Inventory_REST::class )
			? Inventory_REST::get_agent_tools( $agent_id )
			: array();
		$instructions_url = admin_url( 'admin.php?page=agentic-train-data&tab=instructions' );
		if ( $agent_id ) {
			$instructions_url = add_query_arg( 'edit_persona', $agent_id, $instructions_url );
		}

		$agentic_pg = array(
			'agent_id'         => $agent_id,
			'agent_name'       => ( $agent instanceof Agent_Base ) ? $agent->get_name() : '',
			'effective'        => $effective,
			'model_source'     => $model_source,
			'system_prompt'    => $system_prompt,
			'persona_notes'    => $persona_notes,
			'response_style'   => $style,
			'tools'            => $tools,
			'instructions_url' => $instructions_url,
		);
		include AGENT_BUILDER_DIR . 'admin/partials/chat-playground-panel.php';
	}

	/**
	 * Render Settings page.
	 *
	 * @return void
	 */
	public function render_settings_page(): void {
		// Prefer the React settings app (WordPress components, plugin-wide UI).
		if ( React_Admin::enqueue( 'settings-app' ) ) {
			// Still load legacy settings.js for provider edit forms when deep-linked.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$edit = isset( $_GET['edit_provider'] ) || isset( $_GET['add_provider'] );
			if ( $edit ) {
				wp_enqueue_script(
					'agentic-settings',
					AGENT_BUILDER_URL . 'assets/js/settings.js',
					array( 'agentic-ui' ),
					AGENT_BUILDER_VERSION,
					true
				);
				include AGENT_BUILDER_DIR . 'admin/settings.php';
				return;
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'interface';
			if ( in_array( $tab, array( 'general' ), true ) ) {
				$tab = 'interface';
			}
			if ( in_array( $tab, array( 'global' ), true ) ) {
				$tab = 'agents';
			}
			wp_enqueue_style(
				'agentic-react-admin',
				AGENT_BUILDER_URL . 'assets/css/react-admin.css',
				array( 'wp-components' ),
				AGENT_BUILDER_VERSION
			);
			// Users' Basic mode embeds a chat with User Assistant. Mode can
			// toggle client-side without a page reload, and the tab itself
			// can be switched to without a reload too, so load this
			// unconditionally rather than only when the initial request
			// happens to land on the Users tab in Basic mode.
			wp_enqueue_style(
				'agentic-chat',
				AGENT_BUILDER_URL . 'assets/css/chat.css',
				array(),
				AGENT_BUILDER_VERSION
			);
			Chat_Assets::maybe_add_chat_theme_overrides();
			// Footer docs per tab for the React settings shell.
			$footer_by_tab = array();
			foreach ( array( 'interface', 'agents', 'providers', 'users', 'security', 'apis', 'endpoints', 'mcp' ) as $slug ) {
				$footer_by_tab[ $slug ] = $this->get_admin_footer_data( 'agentic-settings', $slug );
			}
			wp_localize_script(
				'agentic-settings-app',
				'agenticSettingsBoot',
				array(
					'restUrl'     => rest_url( 'agentic/v1/' ),
					'nonce'       => wp_create_nonce( 'wp_rest' ),
					'footer'      => $this->get_admin_footer_data( 'agentic-settings', $tab ),
					'footerByTab' => $footer_by_tab,
				)
			);
			echo '<div class="wrap agentic-admin">';
			React_Admin::mount( 'agentic-settings-app-root' );
			echo '</div>';
			return;
		}

		React_Admin::missing_build_notice( 'settings-app' );
		// Fallback: classic PHP settings if build is missing.
		wp_enqueue_script(
			'agentic-settings',
			AGENT_BUILDER_URL . 'assets/js/settings.js',
			array( 'agentic-ui' ),
			AGENT_BUILDER_VERSION,
			true
		);
		include AGENT_BUILDER_DIR . 'admin/settings.php';
	}

	/**
	 * Render Train on Data / Knowledge page.
	 *
	 * Classic PHP for both tabs: full OKF wiki editor (free) and Vector Store
	 * (Pro UI or free upgrade path). React stub was dropping the editor + Vector tab.
	 *
	 * @return void
	 */
	public function render_train_data_page(): void {
		// Classic PHP page — reuse react-admin.css for the shared
		// .agentic-screen-mode-toggle styling, same pattern as admin/agents.php.
		// wp-components stays on the dependency list so Advanced
		// Instructions/Memory (settings-app) keep their previous enqueue.
		wp_enqueue_style(
			'agentic-react-admin',
			AGENT_BUILDER_URL . 'assets/css/react-admin.css',
			array( 'wp-components' ),
			AGENT_BUILDER_VERSION
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab params.
		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'wiki';

		// Wiki editor assets are Advanced-only (M1 Phase 5). Basic mode is
		// a landing into knowledge-wizard and does not load the editor JS.
		if ( self::is_advanced_mode( 'knowledge' )
			&& ( 'wiki' === $active_tab || ! in_array( $active_tab, array( 'instructions', 'memory', 'vector' ), true ) )
		) {
			wp_enqueue_script(
				'agentic-okf-knowledge',
				AGENT_BUILDER_URL . 'assets/js/okf-knowledge.js',
				array( 'jquery' ),
				AGENT_BUILDER_VERSION,
				true
			);
		}

		include AGENT_BUILDER_DIR . 'admin/train-data.php';
	}

	/**
	 * Check if at least one LLM provider has a usable API key (or no-key endpoint).
	 *
	 * Used to gate the full admin menu: when nothing is configured the user is
	 * funnelled to the Quick Start / signup page.
	 *
	 * @return bool
	 */
	private function any_llm_configured(): bool {
		return \Agentic\Provider_Registry::has_usable_provider();
	}

	/**
	 * Get the documentation URL for a given Agentic admin page slug and optional tab.
	 *
	 * The full map lives in admin/doc-map.php — edit that file to add
	 * or update entries without touching this class.
	 *
	 * @param string $page The admin page slug (value of ?page= query var).
	 * @param string $tab  The active tab/section (value of ?tab= or ?section= query var).
	 * @return string Absolute URL to the relevant documentation page.
	 */
	private function get_page_doc_url( string $page, string $tab = '' ): string {
		$map = require AGENT_BUILDER_DIR . 'admin/doc-map.php';

		$key = $tab ? "{$page}:{$tab}" : '';
		if ( $key && isset( $map['tabs'][ $key ] ) ) {
			return $map['tabs'][ $key ];
		}

		return $map['pages'][ $page ] ?? 'https://agentic-plugin.com/documentation/';
	}
}
