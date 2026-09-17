<?php
/**
 * Contextual admin-screen launchers.
 *
 * Surfaces an unobtrusive, capability-gated "Ask AI" launcher on high-traffic
 * core WordPress admin screens (Plugins, Media Library, Users, Comments, and the
 * Dashboard). Each launcher opens the existing chat overlay pre-filled with an
 * editable, screen-relevant starter prompt. Nothing is ever sent to the AI
 * provider automatically — the user reviews the prompt and presses Send.
 *
 * Design / WordPress.org guideline notes:
 * - Administrator-only in this version (no privilege broadening).
 * - Native hooks only (views_plugins, attachment_fields_to_edit,
 *   media_row_actions, restrict_manage_users, user_row_actions,
 *   comment_row_actions, wp_dashboard_setup) — no admin_notices nags, no
 *   auto-opening modals, no Pro upsell messaging on core screens.
 * - Globally toggleable (Deployment → Admin UI) and per-user dismissible.
 * - Only small, non-sensitive identifiers (object IDs, counts, titles) are ever
 *   placed in the DOM or starter prompt — never user emails or full inventories.
 *
 * @package    Agent_Builder
 * @subpackage Includes
 * @since      2.12.0
 */

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers contextual launchers across core admin screens.
 */
class Admin_Surfaces {

	/**
	 * Option key for the global on/off toggle.
	 */
	const OPTION_ENABLED = 'agent_builder_admin_launchers_enabled';

	/**
	 * Option: which screens may show launchers (string[] of screen keys).
	 */
	const OPTION_SCREENS = 'agent_builder_admin_launcher_screens';

	/**
	 * Option: preferred agent slug for launchers (default wordpress-assistant).
	 */
	const OPTION_AGENT = 'agent_builder_admin_launcher_agent';

	/**
	 * User-meta key storing the list of dismissed launcher keys.
	 */
	const META_DISMISSED = 'agentic_dismissed_launchers';

	/**
	 * AJAX action used to persist a per-user dismissal.
	 */
	const AJAX_DISMISS = 'agentic_dismiss_launcher';

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		// Persisting a dismissal does not require the feature gate beyond the
		// capability + nonce check inside the handler.
		add_action( 'wp_ajax_' . self::AJAX_DISMISS, array( $this, 'ajax_dismiss' ) );

		// Attach the surface hooks on admin_init, where current_user_can() and the
		// pluggable auth functions are reliably available. (register_hooks runs
		// during the plugin constructor, which is too early for capability checks.)
		add_action( 'admin_init', array( $this, 'register_surfaces' ) );
	}

	/**
	 * Attach the contextual launcher hooks once auth is available and the feature
	 * is enabled for the current (administrator) user.
	 *
	 * @return void
	 */
	public function register_surfaces(): void {
		if ( ! $this->is_enabled() ) {
			return;
		}

		add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue_overlay' ) );

		$screens = $this->enabled_screens();

		if ( in_array( 'plugins', $screens, true ) ) {
			add_filter( 'views_plugins', array( $this, 'plugins_view_link' ) );
		}

		if ( in_array( 'media', $screens, true ) ) {
			// Covers List View — WP_Media_List_Table renders this server-side.
			add_action( 'restrict_manage_posts', array( $this, 'media_toolbar_launcher' ), 10, 2 );
			add_filter( 'attachment_fields_to_edit', array( $this, 'attachment_alt_launcher' ), 20, 2 );
			add_filter( 'media_row_actions', array( $this, 'media_row_action' ), 20, 2 );
			// Covers Grid View (the default) — rendered client-side by Backbone
			// (wp.media), so restrict_manage_posts never fires there at all.
			add_action( 'admin_footer-upload.php', array( $this, 'media_grid_launcher_js' ) );
		}

		if ( in_array( 'users', $screens, true ) ) {
			add_action( 'restrict_manage_users', array( $this, 'users_toolbar_launcher' ) );
			add_filter( 'user_row_actions', array( $this, 'user_row_action' ), 20, 2 );
		}

		if ( in_array( 'comments', $screens, true ) ) {
			add_filter( 'comment_row_actions', array( $this, 'comment_row_action' ), 20, 2 );
		}

		if ( in_array( 'dashboard', $screens, true ) ) {
			add_action( 'wp_dashboard_setup', array( $this, 'register_dashboard_widget' ) );
		}
	}

	/**
	 * Catalog of screens that can host Ask AI launchers.
	 *
	 * @return array<string, array{label:string,description:string,hooks:string[]}>
	 */
	public static function available_screens(): array {
		return array(
			'dashboard' => array(
				'label'       => __( 'Dashboard', 'agent-builder' ),
				'description' => __( 'Home screen widget', 'agent-builder' ),
				'hooks'       => array( 'index.php' ),
			),
			'plugins'   => array(
				'label'       => __( 'Plugins', 'agent-builder' ),
				'description' => __( 'Installed plugins list', 'agent-builder' ),
				'hooks'       => array( 'plugins.php' ),
			),
			'media'     => array(
				'label'       => __( 'Media Library', 'agent-builder' ),
				'description' => __( 'Media grid and list', 'agent-builder' ),
				'hooks'       => array( 'upload.php' ),
			),
			'users'     => array(
				'label'       => __( 'Users', 'agent-builder' ),
				'description' => __( 'Users list and row actions', 'agent-builder' ),
				'hooks'       => array( 'users.php' ),
			),
			'comments'  => array(
				'label'       => __( 'Comments', 'agent-builder' ),
				'description' => __( 'Comments list row actions', 'agent-builder' ),
				'hooks'       => array( 'edit-comments.php' ),
			),
		);
	}

	/**
	 * Enabled screen keys (defaults to all when option never saved).
	 *
	 * @return string[]
	 */
	public function enabled_screens(): array {
		$stored  = get_option( self::OPTION_SCREENS, null );
		$allowed = array_keys( self::available_screens() );
		if ( ! is_array( $stored ) ) {
			return $allowed;
		}
		return array_values( array_intersect( array_map( 'sanitize_key', $stored ), $allowed ) );
	}

	/**
	 * Admin page hooks where overlay assets must load.
	 *
	 * @return string[]
	 */
	private function screen_hooks(): array {
		$hooks   = array();
		$catalog = self::available_screens();
		foreach ( $this->enabled_screens() as $key ) {
			if ( ! empty( $catalog[ $key ]['hooks'] ) ) {
				foreach ( $catalog[ $key ]['hooks'] as $hook ) {
					$hooks[] = $hook;
				}
			}
		}
		return array_values( array_unique( $hooks ) );
	}

	/**
	 * Whether the contextual launchers should render for the current user.
	 *
	 * @return bool
	 */
	private function is_enabled(): bool {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		return '1' === get_option( self::OPTION_ENABLED, '1' );
	}

	/**
	 * Configured preferred launcher agent (option), defaulting to wordpress-assistant.
	 */
	private function preferred_launcher_agent(): string {
		$slug = sanitize_key( (string) get_option( self::OPTION_AGENT, 'wordpress-assistant' ) );
		return '' !== $slug ? $slug : 'wordpress-assistant';
	}

	/**
	 * Resolve the best agent slug for a surface.
	 *
	 * Preference order:
	 * 1. Site-configured launcher agent (if active)
	 * 2. Optional specialized preferred slug (if active)
	 * 3. wordpress-assistant
	 * 4. First active agent
	 *
	 * @param string $preferred Optional specialized agent slug (legacy / secondary).
	 * @return string Resolved active agent slug, or '' if none available.
	 */
	private function resolve_agent( string $preferred = '' ): string {
		if ( ! class_exists( '\Agentic_Agent_Registry' ) ) {
			return '';
		}
		$registry = \Agentic_Agent_Registry::get_instance();

		$configured = $this->preferred_launcher_agent();
		if ( $configured && $registry->is_agent_active( $configured ) ) {
			return $configured;
		}
		if ( $preferred && $registry->is_agent_active( $preferred ) ) {
			return $preferred;
		}
		if ( $registry->is_agent_active( 'wordpress-assistant' ) ) {
			return 'wordpress-assistant';
		}

		$active = $registry->get_active_agents();
		if ( is_array( $active ) && ! empty( $active ) ) {
			$first = reset( $active );
			if ( is_string( $first ) ) {
				return $first;
			}
			if ( is_array( $first ) && ! empty( $first['slug'] ) ) {
				return (string) $first['slug'];
			}
		}
		return '';
	}

	/**
	 * Strict availability check for surfaces whose seeded prompt only makes
	 * sense for one specific agent (no generalist fallback). True only when the
	 * agent is both active AND actually installed/loaded — guards against a
	 * stale active-option slug left behind after its plugin was deactivated.
	 *
	 * @param string $slug Agent slug.
	 * @return bool
	 */
	private function is_agent_available( string $slug ): bool {
		if ( '' === $slug || ! class_exists( '\Agentic_Agent_Registry' ) ) {
			return false;
		}
		$registry = \Agentic_Agent_Registry::get_instance();
		if ( ! $registry->is_agent_active( $slug ) ) {
			return false;
		}
		$installed = $registry->get_installed_agents();
		return is_array( $installed ) && isset( $installed[ $slug ] );
	}

	/**
	 * Whether a launcher key has been dismissed by the current user.
	 *
	 * @param string $key Launcher key.
	 * @return bool
	 */
	private function is_dismissed( string $key ): bool {
		$dismissed = get_user_meta( get_current_user_id(), self::META_DISMISSED, true );
		return is_array( $dismissed ) && in_array( $key, $dismissed, true );
	}

	/**
	 * Enqueue the chat overlay assets on the target admin screens so launchers
	 * have something to open. The overlay enqueue method is self-gating
	 * (admin-bar showing + manage_options) and localizes agenticChat.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public function maybe_enqueue_overlay( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, $this->screen_hooks(), true ) ) {
			return;
		}
		if ( ! class_exists( '\Agentic\Chat_Assets' ) ) {
			return;
		}

		\Agentic\Chat_Assets::enqueue_adminbar_chat_overlay();

		// Polished launcher styles on core screens (Users, Plugins, etc.).
		wp_enqueue_style(
			'agentic-admin-surfaces',
			AGENT_BUILDER_URL . 'assets/css/admin-surfaces.css',
			array(),
			AGENT_BUILDER_VERSION
		);

		// Inline dismissal behaviour, attached to the overlay handle.
		$nonce  = wp_json_encode( wp_create_nonce( self::AJAX_DISMISS ) );
		$ajax   = wp_json_encode( esc_url_raw( admin_url( 'admin-ajax.php' ) ) );
		$action = wp_json_encode( self::AJAX_DISMISS );

		$lines = array(
			'( function () {',
			"	document.addEventListener( 'click', function ( e ) {",
			"		var btn = e.target.closest( '.agentic-launcher-dismiss' );",
			'		if ( ! btn ) { return; }',
			'		e.preventDefault();',
			'		e.stopPropagation();',
			"		var key  = btn.getAttribute( 'data-launcher-key' ) || '';",
			"		var wrap = btn.closest( '.agentic-launcher-wrap' );",
			"		if ( wrap ) { wrap.style.display = 'none'; }",
			'		var body = new URLSearchParams();',
			"		body.set( 'action', " . $action . ' );',
			"		body.set( 'key', key );",
			"		body.set( '_wpnonce', " . $nonce . ' );',
			'		fetch( ' . $ajax . ", { method: 'POST', credentials: 'same-origin', body: body } );",
			'	} );',
			'} )();',
		);
		wp_add_inline_script( 'agentic-chat-overlay', implode( "\n", $lines ) );
	}

	/**
	 * Build a launcher anchor (scoped to our own markup so arbitrary DOM cannot
	 * trigger a seeded launch). The prompt is editable in the composer; it is
	 * never auto-sent.
	 *
	 * @param array $args {
	 *     @type string $slug   Resolved agent slug.
	 *     @type string $prompt Editable starter prompt.
	 *     @type string $label  Visible link label.
	 *     @type string $title  Tooltip / aria description.
	 * }
	 * @return string Escaped anchor HTML, or '' when no agent is available.
	 */
	private function launcher_link( array $args ): string {
		$slug = isset( $args['slug'] ) ? (string) $args['slug'] : '';
		if ( '' === $slug ) {
			return '';
		}
		$label = isset( $args['label'] ) ? (string) $args['label'] : __( 'Ask AI', 'agent-builder' );
		$title = isset( $args['title'] ) ? (string) $args['title'] : __( 'Open the AI agent. Data is sent only when you press Send.', 'agent-builder' );

		// Compact sparkle mark (SVG) — scales cleanly; no emoji font fallback issues.
		$icon = '<span class="agentic-context-launcher__icon" aria-hidden="true">'
			. '<svg width="14" height="14" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">'
			. '<path d="M8 1.5l1.2 3.6L13 6.3l-3.8 1.2L8 11.1 6.8 7.5 3 6.3l3.8-1.2L8 1.5z" fill="currentColor"/>'
			. '<path d="M12.5 9.2l.6 1.8 1.8.6-1.8.6-.6 1.8-.6-1.8-1.8-.6 1.8-.6.6-1.8z" fill="currentColor" opacity=".85"/>'
			. '</svg></span>';

		return sprintf(
			'<a href="#" class="agentic-context-launcher" data-agentic-launch="%1$s" data-agentic-prompt="%2$s" data-agentic-label="%3$s" title="%4$s"><span class="agentic-context-launcher__inner">%5$s<span class="agentic-context-launcher__label">%6$s</span></span></a>',
			esc_attr( $slug ),
			esc_attr( (string) ( $args['prompt'] ?? '' ) ),
			esc_attr( $label ),
			esc_attr( $title ),
			$icon, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG markup.
			esc_html( $label )
		);
	}

	/*
	--------------------------------------------------------------------- */
	/*
		Plugins screen                                                        */
	/* --------------------------------------------------------------------- */

	/**
	 * Append an "Ask AI" link to the Plugins screen status views.
	 *
	 * @param array $views Existing view links.
	 * @return array
	 */
	public function plugins_view_link( array $views ): array {
		// Prefer site-configured launcher agent (default wordpress-assistant).
		if ( ! $this->is_dismissed( 'plugins' ) ) {
			$slug = $this->resolve_agent( 'wordpress-assistant' );
			if ( '' !== $slug ) {
				$total   = 0;
				$updates = 0;
				if ( function_exists( 'get_plugins' ) ) {
					$total = count( get_plugins() );
				}
				$update_data = get_site_transient( 'update_plugins' );
				if ( $update_data && ! empty( $update_data->response ) ) {
					$updates = count( $update_data->response );
				}

				$prompt = sprintf(
					/* translators: 1: total plugin count, 2: number of plugins with updates. */
					__( "I'm on the Plugins screen (%1\$d installed, %2\$d with updates available). Give me a quick health check: flag anything abandoned, insecure, or redundant, and tell me what's safe to update first.", 'agent-builder' ),
					$total,
					$updates
				);

				$link = $this->launcher_link(
					array(
						'slug'   => $slug,
						'prompt' => $prompt,
						'label'  => __( 'Ask AI about plugins', 'agent-builder' ),
						'title'  => __( 'Review installed plugins with the AI agent. Data is sent only when you press Send.', 'agent-builder' ),
					)
				);
				if ( '' !== $link ) {
					$views['agentic_ai'] = $link;
				}
			}
		}

		// Plugin creation: only when a code-generation plugin assistant (Pro) is
		// genuinely installed and active. Separate dismissal key so hiding one
		// launcher does not hide the other.
		if ( ! $this->is_dismissed( 'plugins_create' ) && $this->is_agent_available( 'plugin-assistant' ) ) {
			$create = $this->launcher_link(
				array(
					'slug'   => 'plugin-assistant',
					'prompt' => __( 'I want to build a custom plugin. Help me scaffold it: ask me what it should do, then generate the plugin structure and code for me to review.', 'agent-builder' ),
					'label'  => __( 'Create a plugin with AI', 'agent-builder' ),
					'title'  => __( 'Scaffold a custom plugin with the AI agent. Data is sent only when you press Send.', 'agent-builder' ),
				)
			);
			if ( '' !== $create ) {
				$views['agentic_create_plugin'] = $create;
			}
		}

		return $views;
	}

	/*
	--------------------------------------------------------------------- */
	/*
		Media Library                                                         */
	/* --------------------------------------------------------------------- */

	/**
	 * Add a toolbar-level "Ask AI about media" launcher above the Media
	 * Library grid/list. Unlike the other four screens, Media's other two
	 * launchers (attachment_alt_launcher, media_row_action) only ever
	 * surface once a user has clicked into an individual item or switched
	 * out of the default Grid View into List View — so without this one,
	 * nothing is visible at all on the screen most people actually land on.
	 *
	 * restrict_manage_posts is shared across every post-type list table
	 * (Posts, Pages, custom post types), not media-specific, so the
	 * post_type check is required — without it this would also render on
	 * every other admin list screen.
	 *
	 * @param string $post_type Post type of the current list table.
	 * @param string $which     Location of the extra table nav ('bar' for media).
	 * @return void
	 */
	public function media_toolbar_launcher( string $post_type, string $which ): void {
		if ( 'attachment' !== $post_type || 'bar' !== $which ) {
			return;
		}
		$html = $this->media_launcher_html();
		if ( '' !== $html ) {
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- media_launcher_html() returns escaped HTML.
		}
	}

	/**
	 * Build the wrapped "Ask AI about media" launcher markup (link + dismiss
	 * button), shared by the List View server-render (media_toolbar_launcher)
	 * and the Grid View JS injection (media_grid_launcher_js) so both surface
	 * the exact same control rather than two copies that could drift apart.
	 *
	 * @return string Escaped HTML, or '' if the launcher shouldn't show.
	 */
	private function media_launcher_html(): string {
		if ( $this->is_dismissed( 'media' ) ) {
			return '';
		}
		$slug = $this->resolve_agent( 'media-assistant' );
		if ( '' === $slug ) {
			return '';
		}

		$prompt = __( 'Give me an overview of my media library: any images missing alt text, unused files I could clean up, and anything else worth a look.', 'agent-builder' );

		$link = $this->launcher_link(
			array(
				'slug'   => $slug,
				'prompt' => $prompt,
				'label'  => __( 'Ask AI about media', 'agent-builder' ),
			)
		);
		if ( '' === $link ) {
			return '';
		}

		return sprintf(
			'<span class="agentic-launcher-wrap agentic-launcher-wrap--toolbar">%1$s%2$s</span>',
			$link, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- launcher_link() returns escaped HTML.
			$this->dismiss_button( 'media' ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- dismiss_button() returns escaped HTML.
		);
	}

	/**
	 * Inject the same launcher into Media Library's Grid View — the default
	 * view, rendered client-side by Backbone (wp.media) rather than
	 * WP_Media_List_Table, so no PHP action hook fires inside it.
	 *
	 * .media-toolbar-secondary exists in the initial server-rendered HTML as
	 * an empty shell, but Backbone's own AttachmentsBrowser view populates
	 * (and in doing so, clears) it asynchronously after this admin_footer
	 * script has already run — an insert-once-at-parse-time approach loses
	 * the race and gets wiped. A MutationObserver re-inserts the launcher
	 * every time the toolbar's contents change instead of relying on timing.
	 *
	 * @return void
	 */
	public function media_grid_launcher_js(): void {
		$html = $this->media_launcher_html();
		if ( '' === $html ) {
			return;
		}
		?>
		<script>
		( function () {
			var launcherHtml = <?php echo wp_json_encode( $html ); ?>;

			function insert() {
				var toolbar = document.querySelector( '.media-toolbar-secondary' );
				if ( ! toolbar || toolbar.querySelector( '.agentic-launcher-wrap' ) ) {
					return;
				}
				var wrap = document.createElement( 'div' );
				wrap.innerHTML = launcherHtml;
				toolbar.appendChild( wrap.firstElementChild );
			}

			insert();

			// Observe body, not the toolbar node itself — Backbone may replace
			// the element outright rather than just clearing its contents, which
			// would silently detach an observer bound to the original reference.
			// insert() re-queries the toolbar fresh each time, so this stays
			// correct regardless of which underlying node currently matches.
			new MutationObserver( insert ).observe( document.body, { childList: true, subtree: true } );
		} )();
		</script>
		<?php
	}

	/**
	 * Add a "Generate alt text" launcher to the attachment edit fields.
	 *
	 * @param array    $fields     Attachment form fields.
	 * @param \WP_Post $attachment Attachment post object.
	 * @return array
	 */
	public function attachment_alt_launcher( array $fields, $attachment ): array {
		if ( ! $attachment instanceof \WP_Post || $this->is_dismissed( 'media' ) ) {
			return $fields;
		}
		if ( 0 !== strpos( (string) get_post_mime_type( $attachment ), 'image/' ) ) {
			return $fields;
		}
		$slug = $this->resolve_agent( 'media-assistant' );
		if ( '' === $slug ) {
			return $fields;
		}

		$filename = wp_basename( (string) get_post_meta( $attachment->ID, '_wp_attached_file', true ) );
		$prompt   = sprintf(
			/* translators: 1: attachment ID, 2: filename. */
			__( 'Write concise, descriptive alt text for image #%1$d (%2$s), then update it for me after I approve.', 'agent-builder' ),
			$attachment->ID,
			$filename
		);

		$link = $this->launcher_link(
			array(
				'slug'   => $slug,
				'prompt' => $prompt,
				'label'  => __( 'Generate alt text with AI', 'agent-builder' ),
				'title'  => __( 'Draft alt text with the AI agent. Data is sent only when you press Send.', 'agent-builder' ),
			)
		);
		if ( '' !== $link ) {
			$fields['agentic_alt_ai'] = array(
				'label' => __( 'AI agent', 'agent-builder' ),
				'input' => 'html',
				'html'  => $link,
			);
		}
		return $fields;
	}

	/**
	 * Add an "Ask AI" row action to media list rows.
	 *
	 * @param array    $actions Row actions.
	 * @param \WP_Post $post    Attachment post.
	 * @return array
	 */
	public function media_row_action( array $actions, $post ): array {
		if ( ! $post instanceof \WP_Post || $this->is_dismissed( 'media' ) ) {
			return $actions;
		}
		$slug = $this->resolve_agent( 'media-assistant' );
		if ( '' === $slug ) {
			return $actions;
		}

		$is_image = 0 === strpos( (string) get_post_mime_type( $post ), 'image/' );
		$prompt   = $is_image
			? sprintf(
				/* translators: %d: attachment ID. */
				__( 'Help me with image #%d: review its alt text, suggest a better version, and recommend how I could improve this image.', 'agent-builder' ),
				$post->ID
			)
			: sprintf(
				/* translators: %d: attachment ID. */
				__( 'Help me manage media item #%d.', 'agent-builder' ),
				$post->ID
			);

		$link = $this->launcher_link(
			array(
				'slug'   => $slug,
				'prompt' => $prompt,
				'label'  => __( 'Ask AI', 'agent-builder' ),
			)
		);
		if ( '' !== $link ) {
			$actions['agentic_ai'] = $link;
		}
		return $actions;
	}

	/*
	--------------------------------------------------------------------- */
	/*
		Users                                                                 */
	/* --------------------------------------------------------------------- */

	/**
	 * Render an "Ask AI" launcher above the Users table.
	 *
	 * @param string $which Position token ('top' or 'bottom').
	 * @return void
	 */
	public function users_toolbar_launcher( string $which ): void {
		if ( 'top' !== $which || $this->is_dismissed( 'users' ) ) {
			return;
		}
		$slug = $this->resolve_agent( 'user-assistant' );
		if ( '' === $slug ) {
			return;
		}

		$prompt = __( 'Give me an overview of my user base: recent registrations, inactive accounts, and anyone with elevated privileges I should review.', 'agent-builder' );

		$link = $this->launcher_link(
			array(
				'slug'   => $slug,
				'prompt' => $prompt,
				'label'  => __( 'Ask AI about users', 'agent-builder' ),
			)
		);
		if ( '' === $link ) {
			return;
		}

		printf(
			'<span class="agentic-launcher-wrap agentic-launcher-wrap--toolbar">%1$s%2$s</span>',
			$link, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- launcher_link() returns escaped HTML.
			$this->dismiss_button( 'users' ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- dismiss_button() returns escaped HTML.
		);
	}

	/**
	 * Add a "Draft email" row action to user rows.
	 *
	 * @param array    $actions Row actions.
	 * @param \WP_User $user    User object.
	 * @return array
	 */
	public function user_row_action( array $actions, $user ): array {
		if ( ! $user instanceof \WP_User || $this->is_dismissed( 'users' ) ) {
			return $actions;
		}
		$slug = $this->resolve_agent( 'user-assistant' );
		if ( '' === $slug ) {
			return $actions;
		}

		// Only non-sensitive identifiers (ID + display name) are passed.
		$prompt = sprintf(
			/* translators: 1: user ID, 2: display name. */
			__( 'Draft a friendly, professional email to user #%1$d (%2$s). Ask me the purpose first, then write it for my review.', 'agent-builder' ),
			$user->ID,
			$user->display_name
		);

		$link = $this->launcher_link(
			array(
				'slug'   => $slug,
				'prompt' => $prompt,
				'label'  => __( 'Draft email with AI', 'agent-builder' ),
			)
		);
		if ( '' !== $link ) {
			$actions['agentic_ai'] = $link;
		}
		return $actions;
	}

	/*
	--------------------------------------------------------------------- */
	/*
		Comments                                                              */
	/* --------------------------------------------------------------------- */

	/**
	 * Add a "Triage & reply" row action to comment rows.
	 *
	 * @param array       $actions Row actions.
	 * @param \WP_Comment $comment Comment object.
	 * @return array
	 */
	public function comment_row_action( array $actions, $comment ): array {
		if ( ! $comment instanceof \WP_Comment || $this->is_dismissed( 'comments' ) ) {
			return $actions;
		}
		$slug = $this->resolve_agent( 'support-triage' );
		if ( '' === $slug ) {
			return $actions;
		}

		$prompt = sprintf(
			/* translators: %d: comment ID. */
			__( 'Triage comment #%d: summarize it, suggest a priority and category, and draft a helpful reply for my review.', 'agent-builder' ),
			$comment->comment_ID
		);

		$link = $this->launcher_link(
			array(
				'slug'   => $slug,
				'prompt' => $prompt,
				'label'  => __( 'Triage with AI', 'agent-builder' ),
			)
		);
		if ( '' !== $link ) {
			$actions['agentic_ai'] = $link;
		}
		return $actions;
	}

	/*
	--------------------------------------------------------------------- */
	/*
		Dashboard widget                                                      */
	/* --------------------------------------------------------------------- */

	/**
	 * Register the dashboard "Ask Agent Builder" widget.
	 *
	 * @return void
	 */
	public function register_dashboard_widget(): void {
		if ( $this->is_dismissed( 'dashboard' ) ) {
			return;
		}
		if ( '' === $this->resolve_agent( 'wordpress-assistant' ) ) {
			return;
		}
		wp_add_dashboard_widget(
			'agentic_dashboard_launcher',
			__( 'Ask Agent Builder', 'agent-builder' ),
			array( $this, 'render_dashboard_widget' )
		);
	}

	/**
	 * Render the dashboard widget body.
	 *
	 * @return void
	 */
	public function render_dashboard_widget(): void {
		$slug = $this->resolve_agent( 'wordpress-assistant' );
		if ( '' === $slug ) {
			return;
		}

		$buttons = array(
			array(
				'prompt' => __( 'Give me a quick health check of my site and the top 3 things I should do today.', 'agent-builder' ),
				'label'  => __( 'What should I focus on today?', 'agent-builder' ),
			),
			array(
				'prompt' => __( 'Suggest a few blog post ideas based on my existing content and what might be missing.', 'agent-builder' ),
				'label'  => __( 'Suggest content ideas', 'agent-builder' ),
			),
			array(
				'prompt' => __( 'Review my site for SEO, performance, and security issues and prioritize the fixes.', 'agent-builder' ),
				'label'  => __( 'Audit my site', 'agent-builder' ),
			),
		);

		echo '<p>' . esc_html__( 'Pick a starting point — you can edit the prompt before sending.', 'agent-builder' ) . '</p>';
		echo '<p>';
		foreach ( $buttons as $b ) {
			$link = $this->launcher_link(
				array(
					'slug'   => $slug,
					'prompt' => $b['prompt'],
					'label'  => $b['label'],
				)
			);
			if ( '' !== $link ) {
				// Render each as a button-styled launcher.
				echo '<span class="button" style="margin:0 6px 6px 0;display:inline-block;">'
					. $link // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- launcher_link() returns escaped HTML.
					. '</span>';
			}
		}
		echo '</p>';
		echo '<p class="description">' . esc_html__( 'Data is sent to your configured AI provider only when you press Send.', 'agent-builder' ) . '</p>';
	}

	/*
	--------------------------------------------------------------------- */
	/*
		Dismissal                                                             */
	/* --------------------------------------------------------------------- */

	/**
	 * Build a small "dismiss" control for a launcher.
	 *
	 * @param string $key Launcher key.
	 * @return string
	 */
	private function dismiss_button( string $key ): string {
		return sprintf(
			'<button type="button" class="agentic-launcher-dismiss" data-launcher-key="%1$s" title="%2$s" aria-label="%2$s"><span aria-hidden="true">&times;</span></button>',
			esc_attr( $key ),
			esc_attr__( 'Dismiss this launcher', 'agent-builder' )
		);
	}

	/**
	 * Persist a per-user launcher dismissal.
	 *
	 * @return void
	 */
	public function ajax_dismiss(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}
		check_ajax_referer( self::AJAX_DISMISS );

		$key = isset( $_POST['key'] ) ? sanitize_key( wp_unslash( $_POST['key'] ) ) : '';
		if ( '' === $key ) {
			wp_send_json_error( array( 'message' => 'missing key' ), 400 );
		}

		$uid       = get_current_user_id();
		$dismissed = get_user_meta( $uid, self::META_DISMISSED, true );
		if ( ! is_array( $dismissed ) ) {
			$dismissed = array();
		}
		if ( ! in_array( $key, $dismissed, true ) ) {
			$dismissed[] = $key;
			update_user_meta( $uid, self::META_DISMISSED, $dismissed );
		}
		wp_send_json_success();
	}
}
