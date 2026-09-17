<?php
/**
 * Chat_Assets — Enqueue all plugin styles, scripts, and chat theme overrides.
 *
 * @package    Agent_Builder
 * @subpackage Includes
 * @since      2.10.0
 */

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles all asset enqueuing for the Agent Builder plugin:
 * admin page styles, admin-bar overlay, frontend chat, modal widget,
 * Gutenberg editor sidebar, and chat theme CSS variable overrides.
 */
class Chat_Assets {

	/**
	 * Register the shared UI primitives library (toasts + confirm/alert modals).
	 *
	 * Registered early on both admin and frontend so any script can declare it as
	 * a dependency, and enqueued directly on plugin admin pages. Idempotent.
	 *
	 * @return void
	 */
	public static function register_ui_library(): void {
		if ( wp_script_is( 'agentic-ui', 'registered' ) ) {
			return;
		}

		wp_register_style(
			'agentic-ui',
			AGENT_BUILDER_URL . 'assets/css/agentic-ui.css',
			array(),
			AGENT_BUILDER_VERSION
		);

		wp_register_script(
			'agentic-ui',
			AGENT_BUILDER_URL . 'assets/js/agentic-ui.js',
			array(),
			AGENT_BUILDER_VERSION,
			true
		);

		wp_localize_script(
			'agentic-ui',
			'agenticUIL10n',
			array(
				'ok'            => __( 'OK', 'agent-builder' ),
				'confirm'       => __( 'Confirm', 'agent-builder' ),
				'cancel'        => __( 'Cancel', 'agent-builder' ),
				'dismiss'       => __( 'Dismiss', 'agent-builder' ),
				'areYouSure'    => __( 'Are you sure?', 'agent-builder' ),
				'notifications' => __( 'Notifications', 'agent-builder' ),
			)
		);
	}

	/**
	 * Enqueue admin.css on all Agentic admin pages.
	 *
	 * @return void
	 */
	public static function enqueue_admin_page_styles(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only, page identification only.
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		if ( ! str_starts_with( $page, 'agent-builder' ) && ! str_starts_with( $page, 'agentic-' ) ) {
			return;
		}
		wp_enqueue_style(
			'agentic-admin',
			AGENT_BUILDER_URL . 'assets/css/admin.css',
			array(),
			(string) filemtime( AGENT_BUILDER_DIR . 'assets/css/admin.css' )
		);

		// Shared vertical-nav search filter (used by Settings and other admin
		// pages that render the Admin_Vnav shell).
		wp_enqueue_script(
			'agentic-admin-vnav',
			AGENT_BUILDER_URL . 'assets/js/admin-vnav.js',
			array(),
			(string) filemtime( AGENT_BUILDER_DIR . 'assets/js/admin-vnav.js' ),
			true
		);

		// Shared toast + confirm/alert primitives used across all plugin admin pages.
		self::register_ui_library();
		wp_enqueue_style( 'agentic-ui' );
		wp_enqueue_script( 'agentic-ui' );
	}

	/**
	 * Enqueue admin-bar chat overlay assets.
	 *
	 * @return void
	 */
	public static function enqueue_adminbar_chat_overlay(): void {
		if ( ! is_admin_bar_showing() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_enqueue_style(
			'agentic-chat-overlay',
			AGENT_BUILDER_URL . 'assets/css/chat-overlay.css',
			array(),
			AGENT_BUILDER_VERSION
		);

		// Shared toast + confirm/alert primitives (used for image-upload validation).
		self::register_ui_library();
		wp_enqueue_style( 'agentic-ui' );

		// Apply the selected chat theme to the overlay via --aco-* variables.
		self::apply_overlay_chat_theme();

		wp_enqueue_script(
			'agentic-chat-overlay',
			AGENT_BUILDER_URL . 'assets/js/chat-overlay.js',
			array( 'agentic-ui', 'wp-i18n' ),
			AGENT_BUILDER_VERSION,
			true
		);
		wp_set_script_translations( 'agentic-chat-overlay', 'agent-builder', AGENT_BUILDER_DIR . 'languages' );

		// Build welcome messages and agent names map for active agents.
		$agentic_welcome_messages = array();
		$agentic_agent_names      = array();
		$agentic_registry         = \Agentic_Agent_Registry::get_instance();
		$agentic_registry->load_active_agents();

		$agentic_instances = $agentic_registry->get_all_instances();
		foreach ( $agentic_instances as $agentic_instance ) {
			$agentic_agent_names[ $agentic_instance->get_id() ] = $agentic_instance->get_name();
			$agentic_msg                                        = $agentic_instance->get_welcome_message();
			if ( $agentic_msg ) {
				$agentic_welcome_messages[ $agentic_instance->get_id() ] = $agentic_msg;
			}
		}

		$agentic_overlay_whitelabel = '1' === get_option( 'agent_builder_chat_whitelabel', '1' );
		$agentic_provider_labels    = array(
			'openai'    => 'OpenAI',
			'anthropic' => 'Anthropic',
			'xai'       => 'xAI',
			'google'    => 'Google',
			'mistral'   => 'Mistral',
			'kimi'      => 'Kimi',
			'deepseek'  => 'DeepSeek',
			'ollama'    => 'Ollama',
			'agentic'   => 'Agentic AI',
			'meta'      => 'Meta Llama',
			'cohere'    => 'Cohere',
		);
		$agentic_overlay_provider   = get_option( 'agent_builder_llm_provider', 'agentic' );

		wp_localize_script(
			'agentic-chat-overlay',
			'agenticChat',
			array(
				'restUrl'         => rest_url( 'agentic/v1/' ),
				'nonce'           => wp_create_nonce( 'wp_rest' ),
				'userId'          => get_current_user_id(),
				'userName'        => wp_get_current_user()->display_name,
				// The overlay itself is already gated to manage_options above, but
				// keep this explicit and consistent with the other two chat surfaces
				// (class-shortcodes.php, class-admin-menu-handler.php) rather than
				// relying on that outer gate implicitly.
				'isAdmin'         => current_user_can( 'manage_options' ) ? '1' : '0',
				'welcomeMessages' => $agentic_welcome_messages,
				'agentNames'      => $agentic_agent_names,
				'showBranding'    => $agentic_overlay_whitelabel ? '0' : '1',
				'provider'        => $agentic_provider_labels[ $agentic_overlay_provider ] ?? ucfirst( $agentic_overlay_provider ),
				'model'           => get_option( 'agent_builder_model', '' ),
				'slashCommands'   => self::get_slash_commands_for_js(),
				'i18n'            => agent_builder_chat_i18n(),
			)
		);
	}

	/**
	 * Enqueue the Gutenberg editor sidebar plugin script.
	 *
	 * Loads on all block editor screens; the JS itself checks whether the
	 * current post type is in the configured allowlist before rendering.
	 *
	 * @return void
	 */
	public static function enqueue_editor_sidebar(): void {
		$agentic_es_settings = wp_parse_args(
			(array) get_option( 'agent_builder_editor_sidebar_settings', array() ),
			array(
				'enabled'         => '0',
				'agent_slug'      => '',
				'post_types'      => array( 'post', 'page' ),
				'inject_context'  => '1',
				'toolbar_enabled' => '1',
			)
		);

		$agentic_sidebar_on = '1' === $agentic_es_settings['enabled'];
		$agentic_toolbar_on = '1' === $agentic_es_settings['toolbar_enabled'];

		// Nothing to enqueue if both features are off.
		if ( ! $agentic_sidebar_on && ! $agentic_toolbar_on ) {
			return;
		}

		$agentic_es_slug = sanitize_key( $agentic_es_settings['agent_slug'] );
		$agentic_es_name = $agentic_es_slug;

		// Resolve agent name and the admin-configured agent list from registry.
		$agentic_es_registry = \Agentic_Agent_Registry::get_instance();
		$agentic_es_registry->load_active_agents();
		$agentic_es_instance = $agentic_es_registry->get_agent_instance( $agentic_es_slug );
		if ( $agentic_es_instance ) {
			$agentic_es_name = $agentic_es_instance->get_name();
		}

		// Determine which agents the admin has enabled for the sidebar.
		$agentic_es_allowed_slugs = (array) ( $agentic_es_settings['agent_slugs'] ?? array() );
		// BC: if agent_slugs not yet saved, fall back to the single default slug.
		if ( empty( $agentic_es_allowed_slugs ) && $agentic_es_slug ) {
			$agentic_es_allowed_slugs = array( $agentic_es_slug );
		}
		// Always ensure the default slug is in the list.
		if ( $agentic_es_slug && ! in_array( $agentic_es_slug, $agentic_es_allowed_slugs, true ) ) {
			array_unshift( $agentic_es_allowed_slugs, $agentic_es_slug );
		}

		// Build only the allowed agents for the client-side switcher.
		$agentic_available_agents = array();
		foreach ( $agentic_es_allowed_slugs as $agentic_loop_slug ) {
			$agentic_loop_instance = $agentic_es_registry->get_agent_instance( $agentic_loop_slug );
			if ( $agentic_loop_instance ) {
				$agentic_available_agents[] = array(
					'id'   => $agentic_loop_slug,
					'name' => $agentic_loop_instance->get_name(),
				);
			}
		}

		// Shared config object used by both sidebar and toolbar scripts.
		$agentic_es_config = array(
			'enabled'         => $agentic_sidebar_on ? '1' : '0',
			'toolbarEnabled'  => $agentic_toolbar_on ? '1' : '0',
			'agentId'         => $agentic_es_slug,
			'agentName'       => $agentic_es_name,
			'availableAgents' => $agentic_available_agents,
			'postTypes'       => (array) $agentic_es_settings['post_types'],
			'injectContext'   => $agentic_es_settings['inject_context'],
			'restUrl'         => rest_url( 'agentic/v1/' ),
			'nonce'           => wp_create_nonce( 'wp_rest' ),
			'adminUrl'        => admin_url(),
		);

		// ── Editor Sidebar ────────────────────────────────────────────────────
		if ( $agentic_sidebar_on ) {
			wp_enqueue_script(
				'agentic-editor-sidebar',
				AGENT_BUILDER_URL . 'assets/js/editor-sidebar.js',
				array( 'wp-plugins', 'wp-edit-post', 'wp-editor', 'wp-rich-text', 'wp-block-editor', 'wp-blocks', 'wp-element', 'wp-data', 'wp-components' ),
				AGENT_BUILDER_VERSION,
				true
			);
			wp_localize_script( 'agentic-editor-sidebar', 'agenticEditorSidebar', $agentic_es_config );

			/**
			 * Fires once per allowed sidebar agent when the editor sidebar is loaded.
			 *
			 * Only the agents ticked in Deployment > Admin UI are included, so
			 * renderer scripts are limited to what the admin actually enabled.
			 *
			 * @param string $agent_slug One of the allowed sidebar agent slugs.
			 */
			foreach ( $agentic_es_allowed_slugs as $agentic_loop_slug ) {
				do_action( 'agentic_enqueue_sidebar_renderers', $agentic_loop_slug );
			}
		}

		// ── Editor Toolbar (Content Writer toolbar button) ───────────────────────
		if ( $agentic_toolbar_on ) {
			$agentic_toolbar_deps = array( 'wp-hooks', 'wp-block-editor', 'wp-element', 'wp-components', 'wp-data' );

			// If the sidebar script is already on the page, depend on it so we
			// share the single agenticEditorSidebar config object.
			if ( $agentic_sidebar_on ) {
				$agentic_toolbar_deps[] = 'agentic-editor-sidebar';
			}

			wp_enqueue_script(
				'agentic-editor-toolbar',
				AGENT_BUILDER_URL . 'assets/js/editor-toolbar.js',
				$agentic_toolbar_deps,
				AGENT_BUILDER_VERSION,
				true
			);

			// Only localize if the sidebar script (which already localizes) is absent.
			if ( ! $agentic_sidebar_on ) {
				wp_localize_script( 'agentic-editor-toolbar', 'agenticEditorSidebar', $agentic_es_config );
			}
		}
	}

	/**
	 * Enqueue built-in sidebar renderer scripts for core agent libraries.
	 *
	 * Hooked into `agentic_enqueue_sidebar_renderers`. Third-party plugins
	 * and pro libraries can hook the same action to load their own
	 * renderers without touching this file.
	 *
	 * @param string $agent_slug The slug of the agent currently powering the sidebar.
	 * @return void
	 */
	public static function enqueue_builtin_sidebar_renderers( string $agent_slug ): void {
		$renderer_map = array(
			'forms-builder' => 'form-preview',
			// 'my-future-agent' => 'my-future-renderer',  ← extend here as needed.
		);

		if ( ! isset( $renderer_map[ $agent_slug ] ) ) {
			return;
		}

		$filename = $renderer_map[ $agent_slug ] . '.js';
		$filepath = AGENT_BUILDER_DIR . 'assets/js/renderers/' . $filename;

		if ( ! file_exists( $filepath ) ) {
			return;
		}

		wp_enqueue_script(
			'agentic-renderer-' . $renderer_map[ $agent_slug ],
			AGENT_BUILDER_URL . 'assets/js/renderers/' . $filename,
			array( 'agentic-editor-sidebar', 'wp-element' ),
			AGENT_BUILDER_VERSION,
			true
		);
	}

	/**
	 * Enqueue chat assets on the frontend when the modal widget is active.
	 *
	 * @return void
	 */
	public static function enqueue_modal_assets(): void {
		if ( ! self::should_show_modal() ) {
			return;
		}

		// Ensure chat components (Chat_Security, etc.) are available.
		\Agentic\Plugin::get_instance()->load_chat_components();

		// Register the frontend chat assets if the Shortcodes class hasn't yet.
		if ( ! wp_style_is( 'agentic-chat-frontend', 'registered' ) ) {
			wp_register_style(
				'agentic-chat-frontend',
				AGENT_BUILDER_URL . 'assets/css/chat-frontend.css',
				array(),
				(string) filemtime( AGENT_BUILDER_DIR . 'assets/css/chat-frontend.css' )
			);
		}
		if ( ! wp_script_is( 'agentic-chat-frontend', 'registered' ) ) {
			wp_register_script(
				'agentic-chat-frontend',
				AGENT_BUILDER_URL . 'assets/js/chat.js',
				array( 'agentic-ui' ),
				(string) filemtime( AGENT_BUILDER_DIR . 'assets/js/chat.js' ),
				true
			);
		}

		self::register_ui_library();
		wp_enqueue_style( 'agentic-ui' );
		wp_enqueue_style( 'agentic-chat-frontend' );
		wp_enqueue_script( 'agentic-chat-frontend' );
		self::apply_frontend_chat_theme();

		$eligible   = self::get_modal_agents_for_page();
		$first_slug = $eligible[0] ?? '';
		$features   = \agent_builder_get_effective_chat_features( $first_slug );

		// Only localize once — shortcode may also localize agenticChat.
		$existing = wp_scripts()->get_data( 'agentic-chat-frontend', 'data' );
		if ( empty( $existing ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$localize_data = array(
				'restUrl'        => esc_url_raw( rest_url( 'agentic/v1/' ) ),
				'nonce'          => wp_create_nonce( 'wp_rest' ),
				'audio'          => $features['audio'],
				'vision'         => $features['vision'],
				'costs'          => '0',
				'tts'            => $features['tts'],
				'ttsVoice'       => get_option( 'agent_builder_tts_voice', 'journey-f' ),
				'consentEnabled' => get_option( 'agent_builder_chat_consent_enabled', false ) ? '1' : '0',
				'consentText'    => \Agentic\GDPR::get_consent_text(),
				'isAdmin'        => current_user_can( 'manage_options' ) ? '1' : '0',
				'adminUrl'       => admin_url(),
				'adminAgentsUrl' => admin_url( 'admin.php?page=agentic-agents' ),
				// Read-only deep-link query args for chat bootstrap (no state change).
				// phpcs:disable WordPress.Security.NonceVerification.Recommended
				'initialMessage' => isset( $_GET['initial_message'] ) ? sanitize_textarea_field( wp_unslash( $_GET['initial_message'] ) ) : '',
				// P0 Basic Multi-Agent Orchestration — richer handoff support.
				'handoffFrom'    => isset( $_GET['handoff_from'] ) ? sanitize_key( wp_unslash( $_GET['handoff_from'] ) ) : '',
				'handoffContext' => isset( $_GET['handoff_context'] ) ? sanitize_textarea_field( wp_unslash( $_GET['handoff_context'] ) ) : '',
				// phpcs:enable WordPress.Security.NonceVerification.Recommended
				'slashCommands'  => self::get_slash_commands_for_js(),
				'i18n'           => agent_builder_chat_i18n(),
			);

			if ( Turnstile::is_required() ) {
				$localize_data['turnstileSiteKey'] = Turnstile::get_site_key();
				Turnstile::enqueue_script();
			}

			wp_localize_script( 'agentic-chat-frontend', 'agenticChat', $localize_data );
		}

		wp_enqueue_style(
			'agentic-modal-widget',
			AGENT_BUILDER_URL . 'assets/css/modal-widget.css',
			array( 'agentic-chat-frontend' ),
			AGENT_BUILDER_VERSION
		);
		wp_enqueue_script(
			'agentic-modal-widget',
			AGENT_BUILDER_URL . 'assets/js/modal-widget.js',
			array( 'agentic-chat-frontend' ),
			AGENT_BUILDER_VERSION,
			true
		);
	}

	/**
	 * Render the floating modal widget HTML in the footer.
	 *
	 * @return void
	 */
	public static function render_modal_widget(): void {
		$eligible_slugs = self::get_modal_agents_for_page();
		if ( empty( $eligible_slugs ) ) {
			return;
		}

		$config   = (array) get_option( 'agent_builder_modal_config', array() );
		$registry = \Agentic_Agent_Registry::get_instance();
		$registry->load_active_agents();
		$all_instances = $registry->get_all_instances();

		// Filter to only eligible agents that are actually active.
		$agents = array();
		foreach ( $eligible_slugs as $slug ) {
			if ( isset( $all_instances[ $slug ] ) ) {
				$agents[ $slug ] = $all_instances[ $slug ];
			}
		}

		if ( empty( $agents ) ) {
			return;
		}

		// Use the first agent's position setting for the widget placement.
		$first_slug  = array_key_first( $agents );
		$first_agent = $agents[ $first_slug ];
		$position    = $config[ $first_slug ]['position'] ?? 'bottom-right';
		$whitelabel  = '1' === get_option( 'agent_builder_chat_whitelabel', '1' );

		include AGENT_BUILDER_DIR . 'templates/modal-widget.php';
	}

	/**
	 * Apply the selected chat theme CSS variable overrides to the frontend shortcode stylesheet.
	 *
	 * Uses --agentic-* variables targeting .agentic-chat-frontend (the shortcode wrapper),
	 * which is a different namespace from the admin --ac-* variables. ALL themes are applied
	 * unconditionally because the frontend CSS defaults to light colors regardless of setting.
	 *
	 * @return void
	 */
	public static function apply_frontend_chat_theme(): void {
		$theme = get_option( 'agent_builder_chat_theme', 'light' );

		// Map each theme to --agentic-* CSS variables on .agentic-chat-frontend.
		// Dark must be listed explicitly — the frontend CSS defaults to light colors.
		$themes = array(
			'dark'     => '.agentic-chat-frontend{--agentic-bg:#1a1a2e;--agentic-primary:#8b5cf6;--agentic-primary-hover:#6366f1;--agentic-border:rgba(139,92,246,0.2);--agentic-text:#f0f0f0;--agentic-text-muted:#a0a0a0;--agentic-user-bg:rgba(99,102,241,0.3);--agentic-user-text:#f0f0f0;--agentic-agent-bg:rgba(139,92,246,0.15);--agentic-agent-text:#f0f0f0}',
			'light'    => '.agentic-chat-frontend{--agentic-bg:#ffffff;--agentic-primary:#2271b1;--agentic-primary-hover:#135e96;--agentic-border:#dcdcde;--agentic-text:#1d2327;--agentic-text-muted:#646970;--agentic-user-bg:#2271b1;--agentic-user-text:#ffffff;--agentic-agent-bg:#f0f0f1;--agentic-agent-text:#1d2327}',
			'midnight' => '.agentic-chat-frontend{--agentic-bg:#0f172a;--agentic-primary:#10b981;--agentic-primary-hover:#059669;--agentic-border:rgba(16,185,129,0.3);--agentic-text:#e2e8f0;--agentic-text-muted:#94a3b8;--agentic-user-bg:rgba(5,150,105,0.25);--agentic-user-text:#e2e8f0;--agentic-agent-bg:rgba(16,185,129,0.12);--agentic-agent-text:#e2e8f0}',
			'ocean'    => '.agentic-chat-frontend{--agentic-bg:#0c1222;--agentic-primary:#06b6d4;--agentic-primary-hover:#0891b2;--agentic-border:rgba(6,182,212,0.3);--agentic-text:#e0f2fe;--agentic-text-muted:#7dd3fc;--agentic-user-bg:rgba(8,145,178,0.25);--agentic-user-text:#e0f2fe;--agentic-agent-bg:rgba(6,182,212,0.12);--agentic-agent-text:#e0f2fe}',
		);

		if ( isset( $themes[ $theme ] ) ) {
			wp_add_inline_style( 'agentic-chat-frontend', $themes[ $theme ] );
		}

		$agentic_ov = self::global_appearance_overrides( 'frontend' );
		if ( '' !== $agentic_ov ) {
			wp_add_inline_style( 'agentic-chat-frontend', $agentic_ov );
		}
	}

	/**
	 * Build the global appearance override CSS for a chat surface.
	 *
	 * Applies the site-wide accent colour and font (General → Appearance) on top
	 * of the selected theme, so the override wins the cascade. Each surface uses
	 * a different CSS-variable namespace. Returns '' when neither global option
	 * is set, costing nothing by default.
	 *
	 * @param string $surface One of 'admin', 'frontend', 'overlay'.
	 * @return string CSS rule, or '' when no global appearance is configured.
	 */
	public static function global_appearance_overrides( string $surface ): string {
		$accent = (string) get_option( 'agent_builder_global_accent', '' );
		$font   = (string) get_option( 'agent_builder_global_font', '' );
		if ( '' === $accent && '' === $font ) {
			return '';
		}

		$surfaces = array(
			'admin'    => array(
				'selector'      => '.agentic-chat-container',
				'font_selector' => '.agentic-chat-container',
				'accent'        => array( '--ac-accent', '--ac-accent2' ),
				'rgb'           => '--ac-accent-rgb',
			),
			'frontend' => array(
				'selector'      => '.agentic-chat-frontend',
				'font_selector' => '.agentic-chat-frontend',
				'accent'        => array( '--agentic-primary', '--agentic-primary-hover' ),
				'rgb'           => '',
			),
			'overlay'  => array(
				'selector'      => '.agentic-overlay',
				// The overlay panel sets its own font-family, so the font override
				// must target the panel directly to win the cascade.
				'font_selector' => '.agentic-overlay-panel',
				'accent'        => array( '--aco-primary', '--aco-primary-hover' ),
				'rgb'           => '',
			),
		);

		if ( ! isset( $surfaces[ $surface ] ) ) {
			return '';
		}
		$map = $surfaces[ $surface ];
		$css = '';

		if ( '' !== $accent && self::is_hex_color( $accent ) ) {
			$decls = '';
			foreach ( $map['accent'] as $var ) {
				$decls .= $var . ':' . $accent . ';';
			}
			if ( '' !== $map['rgb'] ) {
				$triplet = self::hex_to_rgb_triplet( $accent );
				if ( '' !== $triplet ) {
					$decls .= $map['rgb'] . ':' . $triplet . ';';
				}
			}
			if ( '' !== $decls ) {
				$css .= $map['selector'] . '{' . $decls . '}';
			}
		}

		// Re-validate the font against the allowlist at output time so a polluted
		// stored option can never become injected CSS.
		if ( '' !== $font && in_array( $font, self::global_font_allowlist(), true ) ) {
			$css .= $map['font_selector'] . '{font-family:' . $font . ';}';
		}

		return $css;
	}

	/**
	 * Curated allowlist of global chat font stacks.
	 *
	 * Must stay in sync with the choices offered on Settings → Interface.
	 *
	 * @return string[] Allowed font-family values ('' = theme default).
	 */
	public static function global_font_allowlist(): array {
		return array(
			'',
			'system-ui, sans-serif',
			'Arial, Helvetica, sans-serif',
			'Georgia, serif',
			'"Times New Roman", Times, serif',
			'"Courier New", Courier, monospace',
			'Verdana, Geneva, sans-serif',
		);
	}

	/**
	 * Validate a #rgb or #rrggbb hex colour string.
	 *
	 * @param string $hex Colour string.
	 * @return bool
	 */
	private static function is_hex_color( string $hex ): bool {
		return (bool) preg_match( '/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $hex );
	}

	/**
	 * Convert a hex colour to a comma-separated "r,g,b" triplet.
	 *
	 * @param string $hex Colour string (#rgb or #rrggbb).
	 * @return string Triplet, or '' when invalid.
	 */
	private static function hex_to_rgb_triplet( string $hex ): string {
		$hex = ltrim( $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( 6 !== strlen( $hex ) ) {
			return '';
		}
		return hexdec( substr( $hex, 0, 2 ) ) . ',' . hexdec( substr( $hex, 2, 2 ) ) . ',' . hexdec( substr( $hex, 4, 2 ) );
	}

	/**
	 * Add inline CSS overrides for the selected chat theme.
	 *
	 * Called by enqueue_frontend_assets() and render_chat_page() in Plugin.
	 *
	 * @return void
	 */
	public static function maybe_add_chat_theme_overrides(): void {
		$theme = get_option( 'agent_builder_chat_theme', 'light' );

		if ( 'dark' !== $theme ) {
			$themes = self::get_theme_overrides();
			if ( isset( $themes[ $theme ] ) ) {
				wp_add_inline_style( 'agentic-chat', $themes[ $theme ] );
			}
		}

		// Global appearance applies on every theme, including the default.
		$agentic_ov = self::global_appearance_overrides( 'admin' );
		if ( '' !== $agentic_ov ) {
			wp_add_inline_style( 'agentic-chat', $agentic_ov );
		}
	}

	/**
	 * Apply the selected chat theme to the admin-bar overlay via --aco-* variables.
	 *
	 * @return void
	 */
	private static function apply_overlay_chat_theme(): void {
		$theme = get_option( 'agent_builder_chat_theme', 'light' );

		$themes = array(
			'dark'     => '.agentic-overlay{--aco-bg:#1a1a2e;--aco-text:#f0f0f0;--aco-text-muted:#a0a0a0;--aco-primary:#8b5cf6;--aco-primary-hover:#6366f1;--aco-border:rgba(139,92,246,0.2);--aco-user-bg:rgba(99,102,241,0.3);--aco-user-text:#f0f0f0;--aco-agent-bg:rgba(139,92,246,0.15);--aco-agent-text:#f0f0f0;--aco-code-bg:rgba(255,255,255,0.08);--aco-pre-bg:#0f0f1e;--aco-pre-text:#f0f0f0}',
			'light'    => '', // Light is the CSS default — no overrides needed.
			'midnight' => '.agentic-overlay{--aco-bg:#0f172a;--aco-text:#e2e8f0;--aco-text-muted:#94a3b8;--aco-primary:#10b981;--aco-primary-hover:#059669;--aco-border:rgba(16,185,129,0.3);--aco-user-bg:rgba(5,150,105,0.25);--aco-user-text:#e2e8f0;--aco-agent-bg:rgba(16,185,129,0.12);--aco-agent-text:#e2e8f0;--aco-code-bg:rgba(255,255,255,0.08);--aco-pre-bg:#060d1a;--aco-pre-text:#e2e8f0}',
			'ocean'    => '.agentic-overlay{--aco-bg:#0c1222;--aco-text:#e0f2fe;--aco-text-muted:#7dd3fc;--aco-primary:#06b6d4;--aco-primary-hover:#0891b2;--aco-border:rgba(6,182,212,0.3);--aco-user-bg:rgba(8,145,178,0.25);--aco-user-text:#e0f2fe;--aco-agent-bg:rgba(6,182,212,0.12);--aco-agent-text:#e0f2fe;--aco-code-bg:rgba(255,255,255,0.08);--aco-pre-bg:#060d1a;--aco-pre-text:#e0f2fe}',
		);

		if ( ! empty( $themes[ $theme ] ) ) {
			wp_add_inline_style( 'agentic-chat-overlay', $themes[ $theme ] );
		}

		$agentic_ov = self::global_appearance_overrides( 'overlay' );
		if ( '' !== $agentic_ov ) {
			wp_add_inline_style( 'agentic-chat-overlay', $agentic_ov );
		}
	}

	/**
	 * Returns the CSS variable override strings for each non-default theme.
	 * Used for the admin chat interface (--ac-* variables on .agentic-chat-container).
	 *
	 * @return array<string, string>
	 */
	private static function get_theme_overrides(): array {
		return array(
			'light'    => '.agentic-chat-container{--ac-bg:#ffffff;--ac-accent:#2271b1;--ac-accent2:#135e96;--ac-accent-rgb:34,113,177;--ac-accent2-rgb:19,94,150;--ac-text:#1d2327;--ac-text-secondary:#2c3338;--ac-text-muted:#646970;--ac-text-dim:#787c82;--ac-text-faint:#a7aaad;--ac-text-footer:#a7aaad;--ac-input-bg:#f0f0f1;--ac-input-border:#c3c4c7;--ac-input-placeholder:#a7aaad;--ac-agent-msg-bg:#f0f0f1;--ac-agent-msg-border:#c3c4c7;--ac-user-msg-bg:rgba(34,113,177,0.1);--ac-user-msg-border:rgba(34,113,177,0.2);--ac-code-bg:#f0f0f1;--ac-pre-bg:#f6f7f7;--ac-btn-secondary-bg:#f0f0f1;--ac-btn-secondary-border:#c3c4c7;--ac-btn-secondary-hover:#e0e0e0;--ac-tool-tag-bg:rgba(0,124,67,0.08);--ac-tool-tag-color:#007c43;--ac-input-area-bg:#f6f7f7;--ac-card-bg:#f6f7f7;--ac-card-border:#dcdcde;--ac-scrollbar-track:#f0f0f1;--ac-scrollbar-thumb:#c3c4c7;--ac-scrollbar-hover:#a7aaad;--ac-proposal-header-bg:rgba(34,113,177,0.08);--ac-proposal-desc:#50575e;--ac-proposal-toggle:#2271b1;--ac-diff-bg:#f6f7f7;--ac-diff-text:#2c3338;--ac-link-color:#2271b1;--ac-voice-border:#c3c4c7;--ac-voice-color:#646970;--ac-attach-border:#c3c4c7;--ac-attach-color:#646970;--ac-footer-border:#dcdcde;--ac-image-remove-bg:#dcdcde;--ac-login-gradient:linear-gradient(135deg,rgba(34,113,177,0.06) 0%,rgba(34,113,177,0.02) 100%);--ac-empty-gradient:linear-gradient(135deg,rgba(34,113,177,0.04) 0%,rgba(34,113,177,0.01) 100%);--ac-header-label:#135e96;--ac-quick-btn-bg:rgba(34,113,177,0.08);--ac-quick-btn-border:rgba(34,113,177,0.3);--ac-quick-btn-color:#2271b1;--ac-prompt-btn-bg:rgba(34,113,177,0.04);--ac-prompt-btn-border:rgba(34,113,177,0.2);--ac-prompt-btn-color:#2271b1;--ac-status-dot:#00a32a;--ac-feature-check:#00a32a;--ac-th-bg:rgba(34,113,177,0.06)}',
			'midnight' => '.agentic-chat-container{--ac-bg:#0f172a;--ac-accent:#10b981;--ac-accent2:#059669;--ac-accent-rgb:16,185,129;--ac-accent2-rgb:5,150,105;--ac-text:#e2e8f0;--ac-text-secondary:#cbd5e1;--ac-text-muted:#94a3b8;--ac-text-dim:#64748b;--ac-text-faint:#475569;--ac-text-footer:#475569;--ac-input-bg:rgba(255,255,255,0.05);--ac-input-border:rgba(16,185,129,0.3);--ac-input-placeholder:#475569;--ac-agent-msg-bg:rgba(16,185,129,0.12);--ac-agent-msg-border:rgba(16,185,129,0.2);--ac-user-msg-bg:rgba(5,150,105,0.25);--ac-user-msg-border:rgba(5,150,105,0.3);--ac-code-bg:rgba(0,0,0,0.3);--ac-pre-bg:rgba(0,0,0,0.4);--ac-btn-secondary-bg:rgba(255,255,255,0.08);--ac-btn-secondary-border:rgba(255,255,255,0.1);--ac-btn-secondary-hover:rgba(255,255,255,0.12);--ac-tool-tag-bg:rgba(16,185,129,0.15);--ac-tool-tag-color:#34d399;--ac-input-area-bg:rgba(0,0,0,0.2);--ac-card-bg:rgba(255,255,255,0.04);--ac-card-border:rgba(255,255,255,0.08);--ac-scrollbar-track:rgba(0,0,0,0.2);--ac-scrollbar-thumb:rgba(16,185,129,0.3);--ac-scrollbar-hover:rgba(16,185,129,0.5);--ac-proposal-header-bg:rgba(16,185,129,0.1);--ac-proposal-desc:#94a3b8;--ac-proposal-toggle:#34d399;--ac-diff-bg:#020617;--ac-diff-text:#e2e8f0;--ac-link-color:#34d399;--ac-voice-border:rgba(255,255,255,0.2);--ac-voice-color:rgba(255,255,255,0.5);--ac-attach-border:rgba(255,255,255,0.2);--ac-attach-color:rgba(255,255,255,0.5);--ac-footer-border:rgba(255,255,255,0.05);--ac-image-remove-bg:rgba(255,255,255,0.12);--ac-login-gradient:linear-gradient(135deg,rgba(16,185,129,0.12) 0%,rgba(6,182,212,0.06) 100%);--ac-empty-gradient:linear-gradient(135deg,rgba(16,185,129,0.08) 0%,rgba(6,182,212,0.03) 100%);--ac-header-label:#6ee7b7;--ac-quick-btn-bg:rgba(16,185,129,0.1);--ac-quick-btn-border:rgba(16,185,129,0.4);--ac-quick-btn-color:#6ee7b7;--ac-prompt-btn-bg:rgba(16,185,129,0.05);--ac-prompt-btn-border:rgba(16,185,129,0.25);--ac-prompt-btn-color:#34d399;--ac-status-dot:#10b981;--ac-feature-check:#10b981;--ac-th-bg:rgba(16,185,129,0.15)}',
			'ocean'    => '.agentic-chat-container{--ac-bg:#0c1222;--ac-accent:#06b6d4;--ac-accent2:#0891b2;--ac-accent-rgb:6,182,212;--ac-accent2-rgb:8,145,178;--ac-text:#e0f2fe;--ac-text-secondary:#bae6fd;--ac-text-muted:#7dd3fc;--ac-text-dim:#38bdf8;--ac-text-faint:#0ea5e9;--ac-text-footer:#475569;--ac-input-bg:rgba(255,255,255,0.05);--ac-input-border:rgba(6,182,212,0.3);--ac-input-placeholder:#475569;--ac-agent-msg-bg:rgba(6,182,212,0.12);--ac-agent-msg-border:rgba(6,182,212,0.2);--ac-user-msg-bg:rgba(8,145,178,0.25);--ac-user-msg-border:rgba(8,145,178,0.3);--ac-code-bg:rgba(0,0,0,0.3);--ac-pre-bg:rgba(0,0,0,0.4);--ac-btn-secondary-bg:rgba(255,255,255,0.08);--ac-btn-secondary-border:rgba(255,255,255,0.1);--ac-btn-secondary-hover:rgba(255,255,255,0.12);--ac-tool-tag-bg:rgba(6,182,212,0.15);--ac-tool-tag-color:#22d3ee;--ac-input-area-bg:rgba(0,0,0,0.2);--ac-card-bg:rgba(255,255,255,0.04);--ac-card-border:rgba(255,255,255,0.08);--ac-scrollbar-track:rgba(0,0,0,0.2);--ac-scrollbar-thumb:rgba(6,182,212,0.3);--ac-scrollbar-hover:rgba(6,182,212,0.5);--ac-proposal-header-bg:rgba(6,182,212,0.1);--ac-proposal-desc:#94a3b8;--ac-proposal-toggle:#67e8f9;--ac-diff-bg:#020617;--ac-diff-text:#e0f2fe;--ac-link-color:#22d3ee;--ac-voice-border:rgba(255,255,255,0.2);--ac-voice-color:rgba(255,255,255,0.5);--ac-attach-border:rgba(255,255,255,0.2);--ac-attach-color:rgba(255,255,255,0.5);--ac-footer-border:rgba(255,255,255,0.05);--ac-image-remove-bg:rgba(255,255,255,0.12);--ac-login-gradient:linear-gradient(135deg,rgba(6,182,212,0.12) 0%,rgba(59,130,246,0.06) 100%);--ac-empty-gradient:linear-gradient(135deg,rgba(6,182,212,0.08) 0%,rgba(59,130,246,0.03) 100%);--ac-header-label:#a5f3fc;--ac-quick-btn-bg:rgba(6,182,212,0.1);--ac-quick-btn-border:rgba(6,182,212,0.4);--ac-quick-btn-color:#a5f3fc;--ac-prompt-btn-bg:rgba(6,182,212,0.05);--ac-prompt-btn-border:rgba(6,182,212,0.25);--ac-prompt-btn-color:#67e8f9;--ac-status-dot:#06b6d4;--ac-feature-check:#06b6d4;--ac-th-bg:rgba(6,182,212,0.15)}',
		);
	}

	/**
	 * Build the slashCommands array for JS localisation.
	 *
	 * Returns all globally-enabled commands with their metadata.
	 * JS filters by context using agenticChat.isAdmin.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_slash_commands_for_js(): array {
		if ( ! class_exists( '\\Agentic\\Slash_Commands' ) ) {
			return array();
		}

		$result = array();
		foreach ( Slash_Commands::get_all() as $cmd ) {
			if ( ! $cmd['enabled'] ) {
				continue;
			}
			$result[] = array(
				'name'        => $cmd['name'],
				'description' => $cmd['description'],
				'client_side' => $cmd['client_side'],
				'has_args'    => $cmd['has_args'],
				'arg_hint'    => $cmd['arg_hint'],
				'contexts'    => $cmd['contexts'],
			);
		}
		return $result;
	}

	/**
	 * Check whether the modal widget should render on the current page.
	 *
	 * @return bool
	 */
	private static function should_show_modal(): bool {
		return ! empty( self::get_modal_agents_for_page() );
	}

	/**
	 * Get modal agent slugs eligible for the current page.
	 *
	 * Filters by per-agent page targeting, login requirements,
	 * and anonymous chat settings.
	 *
	 * @return string[] Agent slugs that should appear on this page.
	 */
	private static function get_modal_agents_for_page(): array {
		if ( is_admin() ) {
			return array();
		}

		$slugs = (array) get_option( 'agent_builder_modal_agents', array() );
		if ( empty( $slugs ) ) {
			return array();
		}

		$config       = (array) get_option( 'agent_builder_modal_config', array() );
		$logged_in    = is_user_logged_in();
		$anon_allowed = '1' === get_option( 'agent_builder_allow_anonymous_chat', '0' );

		// Anonymous visitors need anonymous chat enabled globally.
		if ( ! $logged_in && ! $anon_allowed ) {
			return array();
		}

		$eligible = array();
		foreach ( $slugs as $slug ) {
			$cfg = $config[ $slug ] ?? array();

			// Per-agent login requirement.
			if ( ! empty( $cfg['require_login'] ) && '1' === $cfg['require_login'] && ! $logged_in ) {
				continue;
			}

			// Per-agent page targeting.
			$pages = $cfg['pages'] ?? 'all';
			switch ( $pages ) {
				case 'homepage':
					if ( ! is_front_page() ) {
						continue 2;
					}
					break;
				case 'singular':
					if ( ! is_singular() ) {
						continue 2;
					}
					break;
				case 'front':
					// Already checked ! is_admin() above.
					break;
				case 'all':
				default:
					break;
			}

			$eligible[] = $slug;
		}

		return $eligible;
	}
}
