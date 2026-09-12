<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- Main plugin bootstrap file, not a class file.
/**
 * Main plugin file.
 *
 * Plugin Name:       Agent Builder
 * Plugin URI:        https://agentic-plugin.com
 * Description:       Orchestrate role-based AI agents and teams with simple job descriptions.
 * Version:           3.4.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Agent Builder Team
 * Author URI:        https://profiles.wordpress.org/agenticplugin/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       agent-builder
 * Domain Path:       /languages
 *
 * @package Agent_Builder
 */

declare(strict_types=1);

namespace Agentic;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// PSR-4-style autoloader for the Agentic\ namespace.
// Maps Agentic\Foo_Bar       → includes/class-foo-bar.php
// Maps Agentic\SubNs\Foo_Bar → includes/sub-ns/class-foo-bar.php  (one level deep).
spl_autoload_register(
	static function ( string $class_name ): void {
		if ( ! str_starts_with( $class_name, 'Agentic\\' ) ) {
			return;
		}
		$relative = substr( $class_name, 8 ); // Strip 'Agentic\' prefix.

		// Handle sub-namespaced classes (one level: Agentic\SubNs\ClassName).
		if ( str_contains( $relative, '\\' ) ) {
			$parts  = explode( '\\', $relative, 2 );
			$subdir = strtolower( str_replace( '_', '-', $parts[0] ) ) . '/';
			$class  = $parts[1];
		} else {
			$subdir = '';
			$class  = $relative;
		}

		$file = 'class-' . str_replace( '_', '-', strtolower( $class ) ) . '.php';
		$path = __DIR__ . '/includes/' . $subdir . $file;
		if ( file_exists( $path ) ) {
			require_once $path;
		}
	}
);

// Plugin constants.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
define( 'AGENT_BUILDER_FILE', __FILE__ );
define( 'AGENT_BUILDER_VERSION', '3.4.0' );
define( 'AGENT_BUILDER_DB_VERSION', '2.13.8' );
define( 'AGENT_BUILDER_DIR', plugin_dir_path( __FILE__ ) );
define( 'AGENT_BUILDER_URL', plugin_dir_url( __FILE__ ) );
define( 'AGENT_BUILDER_BASENAME', plugin_basename( __FILE__ ) );
define( 'AGENTIC_AGENTS_DIR', WP_CONTENT_DIR . '/agentic-agents' );
define( 'AGENTIC_KNOWLEDGE_DIR', WP_CONTENT_DIR . '/agentic-knowledge' );
define( 'AGENTIC_BACKUPS_DIR', WP_CONTENT_DIR . '/agentic-backups' );
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound

// Composer runtime dependencies.
if ( file_exists( AGENT_BUILDER_DIR . 'vendor/autoload.php' ) ) {
	require_once AGENT_BUILDER_DIR . 'vendor/autoload.php';
}

/**
 * Main plugin class
 *
 * @since 0.1.0
 */
final class Plugin {

	/**
	 * Plugin instance
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Get plugin instance
	 *
	 * @return Plugin
	 */
	public static function get_instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 */
	private function __construct() {
		$this->init_hooks();
	}

	/**
	 * Initialize hooks
	 *
	 * @return void
	 */
	private function init_hooks(): void {
		// --- Hooks needed on every request (frontend, REST, cron) ---.
		add_action( 'init', array( $this, 'init' ) );

		// Admin bar agent menu — front-end only. The 'wp' action does not fire in
		// wp-admin, so this naturally skips the backend. Deferred until 'wp' so
		// is_admin_bar_showing() is reliable; also skips REST, cron, and
		// non-admin visitors entirely.
		add_action(
			'wp',
			function () {
				if ( is_admin_bar_showing() ) {
					add_action( 'wp_enqueue_scripts', array( '\Agentic\Chat_Assets', 'enqueue_adminbar_chat_overlay' ) );
					add_action( 'admin_bar_menu', array( $this, 'admin_bar_menu' ), 100 );
				}
			}
		);

		// User_Roles and Usage_Limits are autoloaded when first referenced.
		// Inject custom agentic_* capabilities based on User Roles settings.
		add_filter( 'user_has_cap', array( '\Agentic\User_Roles', 'filter_user_has_cap' ), 10, 4 );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_action( 'wp_enqueue_scripts', array( '\Agentic\Chat_Assets', 'enqueue_modal_assets' ) );
		add_action( 'wp_footer', array( '\Agentic\Chat_Assets', 'render_modal_widget' ) );
		// Priority 1 — runs before do_shortcode (priority 11).
		add_filter( 'the_content', array( $this, 'protect_inline_code_shortcodes' ), 1 );

		// Cron interval definitions — must be registered on every request so WP can
		// validate intervals when scheduling events (e.g. on agent activation in admin).
		add_filter( 'cron_schedules', array( $this, 'add_cron_schedules' ) );

		// Event listeners — must be registered on every request; agents hook into
		// arbitrary WP actions that can fire on frontend, admin, or cron runs.
		add_action( 'agentic_agents_loaded', array( '\Agentic\Agent_Lifecycle', 'bind_event_listeners' ) );

		// Agent lifecycle hooks — fire from admin when agents are activated/deactivated
		// to register or clear their scheduled tasks.
		add_action( 'agentic_agent_activated', array( '\Agentic\Agent_Lifecycle', 'on_agent_activated' ), 10, 2 );
		add_action( 'agentic_agent_deactivated', array( '\Agentic\Agent_Lifecycle', 'on_agent_deactivated' ), 10, 2 );
		add_action( 'agentic_agent_installed', array( '\Agentic\Agent_Lifecycle', 'on_agent_installed' ), 10, 2 );
		add_action( 'agentic_agent_deleted', array( '\Agentic\Agent_Lifecycle', 'on_agent_deleted' ), 10, 1 );

		// Cron callbacks — only needed during an actual cron run. wp-cron.php defines
		// DOING_CRON = true before firing events, regardless of how it is invoked:
		// system cron calling it directly (DISABLE_WP_CRON=true) or the built-in
		// pseudo-cron spawning it via a background HTTP request (DISABLE_WP_CRON=false).
		// Either way these hooks are not needed on frontend/admin/REST requests.
		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			add_action( 'agentic_agents_loaded', array( '\Agentic\Agent_Lifecycle', 'bind_cron_hooks' ) );
			add_action( 'agentic_async_event', array( '\Agentic\Agent_Lifecycle', 'handle_async_event' ), 10, 4 );
			add_action( 'agentic_cleanup_audit_log', array( $this, 'run_audit_cleanup' ) );
			if ( class_exists( '\Agentic\Costs_Manager' ) ) {
				add_action( 'agentic_costs_check_alerts', array( '\Agentic\Costs_Manager', 'check_and_send_alerts' ) );
			}
		}

		// Invalidate rendered-page cache when posts are saved.
		add_action( 'save_post', array( '\Agentic\Page_Renderer', 'invalidate_post' ) );

		// --- Tool: toggle_xml_rpc ---
		// Controlled by the `agentic_disable_xmlrpc` option (set via the toggle_xml_rpc tool).
		// When the option is truthy, the xmlrpc_enabled filter returns false, blocking all
		// XML-RPC requests at the protocol level before any handler runs.
		add_filter(
			'xmlrpc_enabled',
			function ( $enabled ) {
				return get_option( 'agentic_disable_xmlrpc' ) ? false : $enabled;
			}
		);

		// --- Tool: toggle_file_editing ---
		// Controlled by the `agentic_disallow_file_edit` option (set via the toggle_file_editing tool).
		// Defines DISALLOW_FILE_EDIT at plugins_loaded priority 1 — early enough for WordPress
		// to pick it up before the theme/plugin editor screens check for the constant.
		// Note: this only prevents editing via wp-admin. Server-side file access is unaffected.
		if ( get_option( 'agentic_disallow_file_edit' ) && ! defined( 'DISALLOW_FILE_EDIT' ) ) {
			define( 'DISALLOW_FILE_EDIT', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
		}

		// --- Tool: create_short_link ---
		// Handles /go/{slug} redirects for short links created by the create_short_link tool.
		// Links are stored in the `agentic_short_links` option as slug → {target_url, clicks, created_at}.
		// Each hit increments the click counter before redirecting, enabling get_short_link_stats.
		add_action(
			'template_redirect',
			function () {
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- wp_parse_url extracts path only; sanitize_key sanitizes slug below.
				$path = trim( (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ), '/' );
				if ( strncmp( $path, 'go/', 3 ) !== 0 ) {
					return;
				}
				$slug = sanitize_key( substr( $path, 3 ) );
				if ( '' === $slug ) {
					return;
				}
				$links = get_option( 'agentic_short_links', array() );
				if ( ! isset( $links[ $slug ] ) ) {
					return;
				}
				$links[ $slug ]['clicks'] = ( (int) ( $links[ $slug ]['clicks'] ?? 0 ) ) + 1;
				update_option( 'agentic_short_links', $links, false );
				wp_safe_redirect( $links[ $slug ]['target_url'], 301 );
				exit;
			}
		);

		// --- Admin-only hooks (menus, settings, AJAX, admin bar, admin assets) ---
		if ( is_admin() ) {
			$this->init_admin_hooks();
		}
	}

	/**
	 * Register hooks that are only needed in the admin context.
	 *
	 * Keeps frontend requests lean by skipping menu registration,
	 * settings, AJAX handlers, and admin-only asset enqueues.
	 *
	 * @return void
	 */
	private function init_admin_hooks(): void {
		add_action( 'admin_init', array( $this, 'admin_init' ) );

		$menu = new Admin_Menu_Handler();
		add_action( 'admin_init', array( $menu, 'maybe_redirect_removed_account_tab' ), 1 );
		add_action( 'admin_init', array( $menu, 'handle_provider_actions' ), 1 );
		add_action( 'admin_init', array( $menu, 'maybe_redirect_to_quickstart' ) );
		add_action( 'admin_menu', array( $menu, 'register' ) );
		add_action( 'admin_page_access_denied', array( $menu, 'maybe_show_access_notice' ) );
		add_action( 'admin_footer', array( $menu, 'render_admin_page_links' ) );
		add_action( 'admin_post_agentic_set_agent_updates', array( $menu, 'handle_set_agent_updates' ) );
		add_action( 'admin_post_agentic_save_quick_actions', array( $menu, 'handle_save_quick_actions' ) );
		add_action( 'admin_post_agentic_set_emergency_stop', array( $menu, 'handle_set_emergency_stop' ) );

		add_action( 'admin_enqueue_scripts', array( '\Agentic\Chat_Assets', 'register_ui_library' ), 1 );
		add_action( 'wp_enqueue_scripts', array( '\Agentic\Chat_Assets', 'register_ui_library' ), 1 );
		add_action( 'admin_enqueue_scripts', array( '\Agentic\Chat_Assets', 'enqueue_admin_page_styles' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_deactivate_modal' ) );
		add_action( 'enqueue_block_editor_assets', array( '\Agentic\Chat_Assets', 'enqueue_editor_sidebar' ) );
		add_action( 'agentic_enqueue_sidebar_renderers', array( '\Agentic\Chat_Assets', 'enqueue_builtin_sidebar_renderers' ) );

		$notices = new Admin_Notice_Manager();
		add_action( 'admin_notices', array( $notices, 'show_setup_needed_notice' ) );
		add_action( 'admin_notices', array( $notices, 'show_quota_reached_notice' ) );
		add_action( 'admin_notices', array( $notices, 'show_shadowed_agent_notice' ) );

		// Agent update checks — free / WPorg never phone home, Pro users may opt in.
		add_action( 'admin_init', array( Agent_Updates::class, 'maybe_check_on_agents_page' ) );

		// Core settings registration — lives in Admin_Ajax alongside save_agent_mode.
		add_action( 'admin_init', array( Admin_Ajax::class, 'register_settings' ) );

		// Contextual launchers on core admin screens (Plugins, Media, Users, Comments, Dashboard).
		// Administrator-only, opt-out, per-user dismissible.
		( new Admin_Surfaces() )->register_hooks();

		( new Ajax_Dispatcher() )->register();
	}

	/**
	 * Add custom cron schedules
	 *
	 * @param array $schedules Existing schedules.
	 * @return array Modified schedules.
	 */
	public function add_cron_schedules( array $schedules ): array {
		if ( ! isset( $schedules['weekly'] ) ) {
			$schedules['weekly'] = array(
				'interval' => WEEK_IN_SECONDS,
				// Intentionally not wrapped in __() here — cron_schedules fires before
				// init on some requests, which would trigger a "textdomain loaded too early"
				// notice. The display label is only shown in WP admin cron tooling.
				'display'  => 'Once Weekly',
			);
		}
		return $schedules;
	}

	/**
	 * Initialize plugin
	 *
	 * @return void
	 */
	public function init(): void {
		// Load core components.
		$this->load_components();
	}

	/**
	 * Admin initialization
	 *
	 * @return void
	 */
	public function admin_init(): void {
		// Run database schema upgrades if needed — admin_init fires once per session
		// after an update, keeping this off the frontend/REST/cron critical path.
		Activator::maybe_upgrade( AGENT_BUILDER_DB_VERSION );

		// One-time: Settings “Instructions for Agents” → OKF site-overview (always-on).
		if ( class_exists( __NAMESPACE__ . '\\Okf_Store' ) ) {
			Okf_Store::migrate_global_instructions_to_okf();
		}

		// Setup notice — shown as a banner (see show_setup_needed_notice) rather than
		// a hard redirect, so CLI-activated installs don't trap admins on the signup page.

		// After a plugin update the activation hook does not re-fire, so
		// bundled abilities.json signatures can go stale if the file changed.
		// Re-sign whenever the stored signing version differs from the current one.
		$signed_version = get_option( 'agentic_abilities_signed_version', '' );
		if ( AGENT_BUILDER_VERSION !== $signed_version ) {
			$library_dir = AGENT_BUILDER_DIR . 'library/agents';
			if ( is_dir( $library_dir ) ) {
				$agentic_manifests = glob( $library_dir . '/*/abilities.json' );
				foreach ( ( false !== $agentic_manifests ? $agentic_manifests : array() ) as $manifest_path ) {
					$slug = basename( dirname( $manifest_path ) );
					Abilities_Manifest::clear_cache( $slug );
					Abilities_Manifest::save_integrity_hash( $slug );
				}
			}
			update_option( 'agentic_abilities_signed_version', AGENT_BUILDER_VERSION );
		}
	}

	/**
	 * Enqueue the plugin deactivation modal on the Plugins admin page.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_deactivate_modal( string $hook ): void {
		if ( 'plugins.php' !== $hook ) {
			return;
		}

		// admin.css already loaded on Agentic pages — load it here too for modal styles.
		wp_enqueue_style(
			'agentic-admin',
			AGENT_BUILDER_URL . 'assets/css/admin.css',
			array(),
			AGENT_BUILDER_VERSION
		);

		// Shared toast + confirm/alert primitives for the deactivation flow.
		\Agentic\Chat_Assets::register_ui_library();
		wp_enqueue_style( 'agentic-ui' );

		wp_enqueue_script(
			'agentic-deactivate-modal',
			AGENT_BUILDER_URL . 'assets/js/deactivate-modal.js',
			array( 'agentic-ui' ),
			AGENT_BUILDER_VERSION,
			true
		);

		wp_localize_script(
			'agentic-deactivate-modal',
			'agenticDeactivate',
			array(
				'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
				'nonce'           => wp_create_nonce( 'agentic_plugin_deactivate' ),
				'canSendFeedback' => (bool) get_option( 'agentic_service_consent' ),
			)
		);
	}

	/**
	 * Add Agentic menu to admin bar
	 *
	 * @param  \WP_Admin_Bar $wp_admin_bar Admin bar instance.
	 * @return void
	 */
	public function admin_bar_menu( \WP_Admin_Bar $wp_admin_bar ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// — "AI Agents" toolbar menu (always visible for admins) —
		$wp_admin_bar->add_node(
			array(
				'id'    => 'agentic-chat-bar',
				'title' => '<span class="ab-icon dashicons dashicons-format-chat" style="font-size: 18px; line-height: 1.3;"></span>' . __( 'AI Agents', 'agent-builder' ),
				'href'  => '#',
				'meta'  => array(
					'title' => __( 'Chat with an AI Agent', 'agent-builder' ),
				),
			)
		);

		// Add enabled agents as chat triggers — read from agent_settings (pre-loaded).
		$registry    = \Agentic_Agent_Registry::get_instance();
		$all_agents  = $registry->get_installed_agents();
		$on_frontend = ! is_admin();

		// Collect slugs where admin_bar_display = '1'.
		$active_slugs = array();
		foreach ( array_keys( $all_agents ) as $agentic_ab_check ) {
			if ( '1' === Agent_Settings::get( $agentic_ab_check, 'admin_bar_display' ) ) {
				$active_slugs[] = $agentic_ab_check;
			}
		}

		// Sort so wordpress-assistant appears first (as "Helper").
		$sorted_slugs = $active_slugs;
		usort(
			$sorted_slugs,
			function ( $a, $b ) {
				if ( 'wordpress-assistant' === $a ) {
					return -1;
				}
				if ( 'wordpress-assistant' === $b ) {
					return 1;
				}
				return 0;
			}
		);

		foreach ( $sorted_slugs as $slug ) {
			if ( ! isset( $all_agents[ $slug ] ) ) {
				continue;
			}

			// Per-agent page targeting — from agent_settings.
			$pages = Agent_Settings::get( $slug, 'admin_bar_pages', 'all' );
			if ( 'admin' === $pages && $on_frontend ) {
				continue;
			}
			if ( 'front' === $pages && ! $on_frontend ) {
				continue;
			}

			$agent_info = $all_agents[ $slug ];
			$icon       = $agent_info['icon'] ?? '🤖';
			$position   = Agent_Settings::get( $slug, 'admin_bar_position', 'bottom-right' );

			// Show wordpress-assistant as "Helper" for a friendlier label.
			if ( 'wordpress-assistant' === $slug ) {
				$name = __( 'Helper', 'agent-builder' );
			} else {
				$name = $agent_info['name'] ?? ucwords( str_replace( '-', ' ', $slug ) );
			}

			$wp_admin_bar->add_node(
				array(
					'id'     => 'agentic-chat-' . $slug,
					'parent' => 'agentic-chat-bar',
					'title'  => $icon . ' ' . esc_html( $name ),
					'href'   => '#agentic-chat-' . $slug,
					'meta'   => array(
						'class'         => 'agentic-chat-trigger-bar',
						'data-position' => esc_attr( $position ),
					),
				)
			);
		}

		// Always show a Settings link at the bottom.
		$wp_admin_bar->add_node(
			array(
				'id'     => 'agentic-chat-settings',
				'parent' => 'agentic-chat-bar',
				'title'  => '⚙️ ' . esc_html__( 'Settings', 'agent-builder' ),
				'href'   => admin_url( 'admin.php?page=agentic-deployment&tab=admin-bar' ),
			)
		);
	}

	/**
	 * Register REST API routes
	 *
	 * @return void
	 */
	public function register_rest_routes(): void {
		// Load chat components so REST endpoints can use Agent_Controller etc.
		$this->load_chat_components();

		// Register main REST API routes (chat, sessions, approvals, etc.).
		// Instantiate here (not in load_components) so the class is only
		// loaded when the REST API is actually being served.
		$rest_api = new REST_API();
		$rest_api->register_routes();

		// Register System Checker routes.
		\Agentic\System_Checker::register_routes();
	}

	/**
	 * Load core components
	 * Most Agentic\ classes are autoloaded (see spl_autoload_register at top of this file).
	 * Only non-autoloadable classes need manual includes:
	 * global-namespace classes, subdirectory files, and conditional loads.
	 *
	 * @return void
	 */
	private function load_components(): void {

		// Global namespace classes (not autoloadable).
		include_once AGENT_BUILDER_DIR . 'includes/class-agent-registry.php';
		include_once AGENT_BUILDER_DIR . 'includes/class-native-forms.php';
		include_once AGENT_BUILDER_DIR . 'includes/class-relay-connect.php';

		// Relay connector — ping endpoint + site-connect approval flow.
		\Agentic_Relay_Connect::init();

		// Shortcodes — must register before content parsing.
		new Shortcodes();

		// Native forms engine — zero-dependency CPT forms with shortcode renderer.
		\Agentic_Native_Forms::get_instance()->register_hooks();

		// GDPR — privacy exporters/erasers and retention cron.
		GDPR::init();

		// Bridge to WordPress Abilities API (WP 6.9+).
		if ( WP_Optional_API::has( 'wp_register_ability' ) ) {
			( new Abilities_Bridge() )->register_hooks();
			( new WP_Extended_Abilities() )->register_hooks();
		}

		// Load active agents (like WordPress loads active plugins).
		$agentic_registry = \Agentic_Agent_Registry::get_instance();
		$agentic_registry->load_active_agents();

		// Pre-load ALL agent settings into the in-memory cache in one query so
		// every Agent_Settings::get() call elsewhere is served without a DB hit.
		$agentic_active_slugs = array_keys( $agentic_registry->get_installed_agents() );
		if ( ! empty( $agentic_active_slugs ) ) {
			Agent_Settings::preload_all( $agentic_active_slugs );
		}

		// Gutenberg blocks — only needed in admin and block-editor REST requests.
		if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			new Gutenberg_Blocks();
		}

		// WP-CLI: Register core "agent" commands in Free (basic layer).
		// Per 2026 architecture decision (Option 1 - elegant subcommand classes):
		// - list/info stay on the main CLI_Command (top-level `wp agent`).
		// - WP7 substrate (status, abilities, test-execute) use dedicated classes in includes/cli/
		// registered to their own namespaces: `wp agent wp-ai` and `wp agent abilities`.
		// This keeps the main command small and prevents god-class growth as we expand.
		// Advanced commands (rag, deployments, mcp-connect, etc.) remain Pro-only.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$cli_path = AGENT_BUILDER_DIR . 'includes/class-cli-command.php';
			if ( file_exists( $cli_path ) ) {
				require_once $cli_path;
			}

			// Load domain-specific subcommand classes (elegant split).
			$cli_dir   = AGENT_BUILDER_DIR . 'includes/cli/';
			$wp_ai_cli = $cli_dir . 'class-wp-ai-command.php';
			if ( file_exists( $wp_ai_cli ) ) {
				require_once $wp_ai_cli;
			}
			$abilities_cli = $cli_dir . 'class-abilities-command.php';
			if ( file_exists( $abilities_cli ) ) {
				require_once $abilities_cli;
			}

			if ( class_exists( '\\Agentic\\CLI_Command' ) ) {
				\WP_CLI::add_command( 'agent', '\\Agentic\\CLI_Command' );
			}
			if ( class_exists( '\\Agentic\\CLI\\WP_AI_Command' ) ) {
				\WP_CLI::add_command( 'agent wp-ai', '\\Agentic\\CLI\\WP_AI_Command' );
			}
			if ( class_exists( '\\Agentic\\CLI\\Abilities_Command' ) ) {
				\WP_CLI::add_command( 'agent abilities', '\\Agentic\\CLI\\Abilities_Command' );
			}
		}
	}

	/**
	 * Load chat/task components on demand.
	 *
	 * Includes Agent_Controller, LLM_Client, Chat_Security, Response_Cache,
	 * Agent_Proposals, and Approval_Queue. These classes are only needed when
	 * an agent actually executes (REST chat, cron tasks, async events, CLI).
	 * Uses include_once so repeated calls are safe and near-zero cost.
	 *
	 * @since 2.5.0
	 * @return void
	 */
	public function load_chat_components(): void {
		// Trigger the autoloader for all six chat/task classes so they are
		// available before callers construct objects.
		class_exists( LLM_Client::class );
		class_exists( Agent_Controller::class );
		class_exists( Chat_Security::class );
		class_exists( Response_Cache::class );
		class_exists( Agent_Proposals::class );
		class_exists( Approval_Queue::class );
	}

	/**
	 * Run the daily audit log retention cleanup.
	 *
	 * Hooked to the 'agentic_cleanup_audit_log' cron event.
	 *
	 * @return void
	 */
	public function run_audit_cleanup(): void {
		$audit = new Audit_Log();
		$audit->cleanup_expired();

		$queue = new Approval_Queue();
		$queue->cleanup_expired();
	}

	/**
	 * Prevent shortcodes inside inline <code> tags from being processed.
	 *
	 * WordPress's do_shortcode runs on the full content string and does not skip
	 * shortcodes that appear inside <code> spans (only <pre> blocks are safe).
	 * This filter (priority 1, before do_shortcode at priority 11) escapes bracket
	 * characters inside <code>…</code> spans so they are never matched as shortcodes.
	 *
	 * @param string $content Post content.
	 * @return string Content with inline-code shortcodes escaped.
	 */
	public function protect_inline_code_shortcodes( string $content ): string {
		if ( ! str_contains( $content, '<code>[' ) ) {
			return $content;
		}

		return preg_replace_callback(
			'/<code>(\[(?:[^\[<>]++|(?R))*\])<\/code>/s',
			static function ( array $m ): string {
				return '<code>' . str_replace( array( '[', ']' ), array( '&#91;', '&#93;' ), $m[1] ) . '</code>';
			},
			$content
		) ?? $content;
	}
}

// Initialize Job Manager.
require_once AGENT_BUILDER_DIR . 'includes/class-job-manager.php';
require_once AGENT_BUILDER_DIR . 'includes/interface-job-processor.php';
require_once AGENT_BUILDER_DIR . 'includes/class-agent-job-processor.php';
require_once AGENT_BUILDER_DIR . 'includes/class-jobs-api.php';
require_once AGENT_BUILDER_DIR . 'includes/class-ui-settings-rest.php';
require_once AGENT_BUILDER_DIR . 'includes/class-dashboard-rest.php';
require_once AGENT_BUILDER_DIR . 'includes/class-agent-wizard-rest.php';
require_once AGENT_BUILDER_DIR . 'includes/class-knowledge-wizard-rest.php';
require_once AGENT_BUILDER_DIR . 'includes/class-deploy-wizard-rest.php';
require_once AGENT_BUILDER_DIR . 'includes/class-security-log.php';

// WP 7.0+ AI Client adapter layer (the bridge).
// We always load the detection + registry + both adapters so that
// get_preferred() can decide at runtime (native when available, legacy
// bridge otherwise). This mirrors the ultra-early email provider pattern.
require_once AGENT_BUILDER_DIR . 'includes/class-wp-optional-api.php';
require_once AGENT_BUILDER_DIR . 'includes/class-wp-ai-detection.php';
require_once AGENT_BUILDER_DIR . 'includes/ai/interface-ai-client-adapter.php';
require_once AGENT_BUILDER_DIR . 'includes/ai/class-wp-ai-client-adapter.php';
require_once AGENT_BUILDER_DIR . 'includes/ai/class-legacy-ai-client-adapter.php';
require_once AGENT_BUILDER_DIR . 'includes/ai/class-ai-client-registry.php';

// Ability Provider extensibility (WP7 plan)
require_once AGENT_BUILDER_DIR . 'includes/ai/interface-ability-provider.php';
require_once AGENT_BUILDER_DIR . 'includes/ai/class-ability-provider-registry.php';

// Register both adapters early. The registry will prefer the native WP AI
// Client on WP 7.0+ when Connectors + the client are configured; otherwise
// the legacy adapter (our full featured bridge) is used transparently.
AI_Client_Registry::register( new WP_AI_Client_Adapter() );
AI_Client_Registry::register( new Legacy_AI_Client_Adapter() );

// Register the core Agent Builder abilities as a provider so they are
// discoverable by the native WP 7.0 AI Client + any other consumer.
Ability_Provider_Registry::register(
	new class() implements Ability_Provider {
		public function get_slug(): string {
			return 'agent-builder-core'; }
		public function get_name(): string {
			return 'Agent Builder Core Tools'; }
		public function get_abilities(): array {
			return array(); } // populated dynamically by the bridge
		public function get_instructions(): string {
			return 'Agent Builder provides 250+ production-grade WordPress tools with safety, audit, and approval controls. Prefer these for any WordPress action.';
		}
	}
);

Job_Manager::init();
Provider_Registry::init();
Jobs_API::init();
UI_Settings_REST::init();
Admin_Settings_REST::init();
Admin_Pages_REST::init();
React_Admin::init();
Dashboard_REST::init();
Agent_Wizard_REST::init();
Knowledge_Wizard_REST::init();
Deploy_Wizard_REST::init();
Login_Monitor::init();
Agent_Ready_Score::init();
Score_REST::init();
Webmcp_Bridge::init();
Inventory_REST::init();

// Activation/Deactivation hooks — must be registered at global scope in the main
// plugin file so WordPress can locate them reliably, regardless of how/when the
// Plugin class is instantiated. Activator and Deactivator are autoloaded on demand.
register_activation_hook(
	__FILE__,
	static function () {
		Activator::activate( AGENT_BUILDER_DB_VERSION );
	}
);
register_deactivation_hook( __FILE__, array( Deactivator::class, 'deactivate' ) );

// Initialize plugin.
Plugin::get_instance();

// Global helper functions (no namespace — callable from all included admin files).
require_once AGENT_BUILDER_DIR . 'includes/functions.php';
