<?php
/**
 * Virtual robots.txt rules and llms.txt
 *
 * The plugin never writes files at the site root. Instead:
 *
 *  - robots.txt: the AI-crawler rules chosen with the update_robots_txt tool
 *    are stored in an option and added to WordPress' own virtual robots.txt
 *    through the core `robots_txt` filter. WordPress only builds that
 *    virtual file when no physical robots.txt exists; when one does, the
 *    tool hands the administrator the exact lines to add themselves.
 *  - llms.txt: the content built by the generate_llms_txt tool is stored in
 *    an option and served at /llms.txt by a rewrite rule (plus a
 *    request-path fallback for sites without pretty permalinks). A physical
 *    llms.txt, if any, is served by the web server before WordPress loads,
 *    so it always wins; the tool then tells the administrator.
 *
 * @package    Agent_Builder
 * @subpackage Includes
 * @since      4.5.0
 *
 * php version 8.2
 */

declare(strict_types=1);

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serves robots.txt rules and llms.txt from options instead of files.
 */
class Virtual_Site_Files {

	/**
	 * Option: AI crawler user-agents the administrator chose to allow
	 * explicitly in robots.txt (string[]).
	 */
	public const ROBOTS_OPTION = 'agent_builder_robots_ai_allow';

	/**
	 * Option: llms.txt content served at /llms.txt ('' = not served).
	 */
	public const LLMS_OPTION = 'agent_builder_llms_txt';

	/**
	 * Option: root files written by earlier versions that are still on disk
	 * (array{files: string[], dismissed: bool}).
	 */
	public const LEGACY_NOTICE_OPTION = 'agent_builder_root_files_notice';

	/**
	 * Option set once the one-time legacy root-file check has run.
	 */
	public const LEGACY_CHECKED_OPTION = 'agent_builder_root_files_checked';

	/**
	 * Query var set by the llms.txt rewrite rule.
	 */
	public const QUERY_VAR = 'agent_builder_llms_txt';

	/**
	 * Rewrite rule regex for /llms.txt.
	 */
	public const REWRITE_REGEX = '^llms\.txt$';

	/**
	 * Comment line that starts the plugin's block in robots.txt. Earlier
	 * versions wrote the same header into a physical robots.txt.
	 */
	public const ROBOTS_HEADER = '# AI Search Engine Access';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register_rewrite' ) );
		add_action( 'init', array( __CLASS__, 'maybe_flush_rewrite' ), 99 );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'parse_request', array( __CLASS__, 'maybe_serve_llms_txt' ), 0 );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- 'robots_txt' is a WordPress core filter.
		add_filter( 'robots_txt', array( __CLASS__, 'filter_robots_txt' ), 20, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'show_legacy_root_files_notice' ) );
		add_action( 'wp_ajax_agentic_dismiss_root_files_notice', array( __CLASS__, 'ajax_dismiss_legacy_notice' ) );
	}

	// ─── Physical files (read-only checks) ──────────────────────────────────

	/**
	 * Absolute path a physical root file would have. Only ever read.
	 *
	 * @param string $name 'robots.txt' or 'llms.txt'.
	 * @return string
	 */
	public static function root_path( string $name ): string {
		return trailingslashit( ABSPATH ) . basename( $name );
	}

	/**
	 * Whether a physical file shadows the virtual one.
	 *
	 * @param string $name 'robots.txt' or 'llms.txt'.
	 * @return bool
	 */
	public static function physical_exists( string $name ): bool {
		return is_file( self::root_path( $name ) );
	}

	/**
	 * Content of a physical root file ('' if missing or unreadable).
	 *
	 * @param string $name 'robots.txt' or 'llms.txt'.
	 * @return string
	 */
	private static function read_physical( string $name ): string {
		$path = self::root_path( $name );
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return '';
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local read-only check of a file the site owner manages.
		return (string) file_get_contents( $path );
	}

	// ─── robots.txt ─────────────────────────────────────────────────────────

	/**
	 * AI crawlers the administrator chose to allow.
	 *
	 * @return string[]
	 */
	public static function robots_allowed_bots(): array {
		$bots = get_option( self::ROBOTS_OPTION, array() );
		if ( ! is_array( $bots ) ) {
			return array();
		}
		return array_values( array_unique( array_filter( array_map( array( __CLASS__, 'sanitize_bot' ), $bots ) ) ) );
	}

	/**
	 * Sanitize one robots.txt user-agent token.
	 *
	 * @param mixed $bot Raw value.
	 * @return string '' when invalid.
	 */
	public static function sanitize_bot( $bot ): string {
		if ( ! is_scalar( $bot ) ) {
			return '';
		}
		$bot = trim( (string) $bot );
		return preg_match( '/^[A-Za-z0-9][A-Za-z0-9._\-]{0,63}$/', $bot ) ? $bot : '';
	}

	/**
	 * Store the AI crawlers to allow (replaces the list).
	 *
	 * @param string[] $bots User-agent tokens.
	 * @return string[] The stored list.
	 */
	public static function set_robots_allowed_bots( array $bots ): array {
		$clean = array_values( array_unique( array_filter( array_map( array( __CLASS__, 'sanitize_bot' ), $bots ) ) ) );
		update_option( self::ROBOTS_OPTION, $clean, false );
		return $clean;
	}

	/**
	 * The robots.txt block for a list of bots.
	 *
	 * @param string[] $bots User-agent tokens.
	 * @return string '' for an empty list.
	 */
	public static function robots_block( array $bots ): string {
		if ( empty( $bots ) ) {
			return '';
		}
		$lines = array( self::ROBOTS_HEADER );
		foreach ( $bots as $bot ) {
			$lines[] = 'User-agent: ' . $bot;
			$lines[] = 'Allow: /';
			$lines[] = '';
		}
		return implode( "\n", $lines );
	}

	/**
	 * `robots_txt` filter: append the plugin's rules to WordPress' virtual
	 * robots.txt. Not added when the site discourages search engines.
	 *
	 * @param string $output Robots.txt output so far.
	 * @param bool   $is_public Whether the site is public (blog_public).
	 * @return string
	 */
	public static function filter_robots_txt( $output, $is_public ) {
		$output = (string) $output;
		if ( ! $is_public ) {
			return $output;
		}
		$block = self::robots_block( self::robots_allowed_bots() );
		if ( '' === $block ) {
			return $output;
		}
		return rtrim( $output ) . "\n\n" . $block . "\n";
	}

	/**
	 * The robots.txt WordPress would serve, built in-process (no HTTP):
	 * the physical file when one exists, otherwise core's virtual output
	 * with every `robots_txt` filter applied (this plugin's included).
	 *
	 * @return array{content: string, source: string, path: string}
	 */
	public static function robots_txt(): array {
		if ( self::physical_exists( 'robots.txt' ) ) {
			return array(
				'content' => self::read_physical( 'robots.txt' ),
				'source'  => 'physical_file',
				'path'    => self::root_path( 'robots.txt' ),
			);
		}

		$public = (bool) get_option( 'blog_public' );
		// Same output as core's do_robots(), without its header() call.
		if ( $public ) {
			$admin_path = (string) wp_parse_url( admin_url(), PHP_URL_PATH );
			$ajax_path  = (string) wp_parse_url( admin_url( 'admin-ajax.php' ), PHP_URL_PATH );
			$output     = "User-agent: *\n";
			if ( '' !== $admin_path ) {
				$output .= 'Disallow: ' . trailingslashit( $admin_path ) . "\n";
			}
			if ( '' !== $ajax_path ) {
				$output .= 'Allow: ' . $ajax_path . "\n";
			}
		} else {
			$output = "User-agent: *\nDisallow: /\n";
		}

		return array(
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core filter.
			'content' => (string) apply_filters( 'robots_txt', $output, $public ),
			'source'  => 'virtual',
			'path'    => '',
		);
	}

	// ─── llms.txt ───────────────────────────────────────────────────────────

	/**
	 * Stored llms.txt content ('' when none).
	 *
	 * @return string
	 */
	public static function llms_txt_option(): string {
		return (string) get_option( self::LLMS_OPTION, '' );
	}

	/**
	 * Store llms.txt content and make sure /llms.txt routes to it.
	 *
	 * @param string $content Plain-text Markdown.
	 * @return void
	 */
	public static function set_llms_txt( string $content ): void {
		$content = str_replace( "\0", '', wp_check_invalid_utf8( $content, true ) );
		update_option( self::LLMS_OPTION, $content, false );
		self::register_rewrite();
		flush_rewrite_rules( false );
	}

	/**
	 * The llms.txt a visitor gets: the physical file when one exists (the
	 * web server serves it first), otherwise the stored content.
	 *
	 * @return array{content: string, source: string, path: string} source is 'physical_file', 'virtual' or 'none'.
	 */
	public static function llms_txt(): array {
		if ( self::physical_exists( 'llms.txt' ) ) {
			return array(
				'content' => self::read_physical( 'llms.txt' ),
				'source'  => 'physical_file',
				'path'    => self::root_path( 'llms.txt' ),
			);
		}
		$content = self::llms_txt_option();
		return array(
			'content' => $content,
			'source'  => '' === $content ? 'none' : 'virtual',
			'path'    => '',
		);
	}

	/**
	 * Register the /llms.txt rewrite rule.
	 *
	 * @return void
	 */
	public static function register_rewrite(): void {
		add_rewrite_rule( self::REWRITE_REGEX, 'index.php?' . self::QUERY_VAR . '=1', 'top' );
	}

	/**
	 * Flush rewrite rules once if the /llms.txt rule is missing from the
	 * stored rules (after an update, which does not run activation).
	 *
	 * @return void
	 */
	public static function maybe_flush_rewrite(): void {
		if ( ! get_option( 'permalink_structure' ) ) {
			return;
		}
		$rules = get_option( 'rewrite_rules' );
		if ( is_array( $rules ) && ! isset( $rules[ self::REWRITE_REGEX ] ) ) {
			flush_rewrite_rules( false );
		}
	}

	/**
	 * Remove the rewrite rule (deactivation), before rules are flushed.
	 *
	 * @return void
	 */
	public static function unregister_rewrite(): void {
		global $wp_rewrite;
		if ( isset( $wp_rewrite->extra_rules_top[ self::REWRITE_REGEX ] ) ) {
			unset( $wp_rewrite->extra_rules_top[ self::REWRITE_REGEX ] );
		}
	}

	/**
	 * Register the query var.
	 *
	 * @param string[] $vars Public query vars.
	 * @return string[]
	 */
	public static function query_vars( $vars ) {
		$vars   = is_array( $vars ) ? $vars : array();
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Whether this request is for /llms.txt.
	 *
	 * @param \WP $wp Current request.
	 * @return bool
	 */
	public static function is_llms_request( $wp ): bool {
		if ( is_object( $wp ) && ! empty( $wp->query_vars[ self::QUERY_VAR ] ) ) {
			return true;
		}

		// Fallback without pretty permalinks (or before rules are flushed):
		// match the request path against {home path}/llms.txt.
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		if ( '' === $uri ) {
			return false;
		}
		$path      = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$home_path = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		return trailingslashit( $home_path ) . 'llms.txt' === $path;
	}

	/**
	 * Serve llms.txt from the option.
	 *
	 * @param \WP $wp Current request.
	 * @return void
	 */
	public static function maybe_serve_llms_txt( $wp ): void {
		if ( ! self::is_llms_request( $wp ) ) {
			return;
		}
		$content = self::llms_txt_option();
		if ( '' === $content ) {
			return; // Not configured: WordPress answers with its normal 404.
		}

		if ( ! headers_sent() ) {
			status_header( 200 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			header( 'X-Content-Type-Options: nosniff' );
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- text/plain response body, not HTML.
		echo $content;
		exit;
	}

	// ─── Files written by earlier versions ──────────────────────────────────

	/**
	 * One-time check (from Activator::maybe_upgrade()): earlier versions
	 * wrote robots.txt and llms.txt at the site root. They are never deleted
	 * (they may have been edited by hand); the administrator gets a
	 * dismissible note on Agent Builder screens, and an existing llms.txt
	 * seeds the stored content so nothing changes if they remove the file.
	 *
	 * @return void
	 */
	public static function maybe_check_legacy_root_files(): void {
		if ( get_option( self::LEGACY_CHECKED_OPTION ) ) {
			return;
		}
		update_option( self::LEGACY_CHECKED_OPTION, 1 );

		$files = array();

		$robots = self::read_physical( 'robots.txt' );
		if ( '' !== $robots && str_contains( $robots, self::ROBOTS_HEADER ) ) {
			$files[] = 'robots.txt';
			// Carry the plugin's rules over, so they are still served if the
			// file is removed.
			if ( empty( self::robots_allowed_bots() ) ) {
				$block = substr( $robots, (int) strpos( $robots, self::ROBOTS_HEADER ) );
				if ( preg_match_all( '/^User-agent:\s*(\S+)\s*\R\s*Allow:\s*\/\s*$/mi', $block, $m ) ) {
					self::set_robots_allowed_bots( $m[1] );
				}
			}
		}

		$llms = self::read_physical( 'llms.txt' );
		if ( '' !== $llms ) {
			$files[] = 'llms.txt';
			if ( '' === self::llms_txt_option() ) {
				update_option( self::LLMS_OPTION, $llms, false );
			}
		}

		if ( ! empty( $files ) ) {
			update_option(
				self::LEGACY_NOTICE_OPTION,
				array(
					'files'     => $files,
					'dismissed' => false,
				),
				false
			);
		}
	}

	/**
	 * Dismissible note on Agent Builder screens only.
	 *
	 * @return void
	 */
	public static function show_legacy_root_files_notice(): void {
		if ( ! current_user_can( 'manage_options' ) || ! Admin_Notice_Manager::is_plugin_admin_screen() ) {
			return;
		}
		$state = get_option( self::LEGACY_NOTICE_OPTION );
		if ( ! is_array( $state ) || ! empty( $state['dismissed'] ) || empty( $state['files'] ) ) {
			return;
		}
		$files = array_values( array_filter( (array) $state['files'], array( __CLASS__, 'physical_exists' ) ) );
		if ( empty( $files ) ) {
			return;
		}

		$names = implode(
			', ',
			array_map(
				static function ( string $f ): string {
					return '<code>' . esc_html( $f ) . '</code>';
				},
				$files
			)
		);
		?>
		<div class="notice notice-info is-dismissible" id="agentic-root-files-notice" data-nonce="<?php echo esc_attr( wp_create_nonce( 'agentic_dismiss_root_files_notice' ) ); ?>">
			<p>
				<?php
				printf(
					wp_kses(
						/* translators: %s: file names, e.g. robots.txt, llms.txt. */
						__( 'Earlier versions of Agent Builder wrote %s at the root of your site. Agent Builder no longer writes files there: it now adds its robots.txt rules to the robots.txt WordPress generates and serves llms.txt itself. Your existing files were left untouched because you may have edited them, and while they exist your web server serves them instead. To let Agent Builder manage them, copy any changes you want to keep, then delete the files.', 'siteloom' ),
						array( 'code' => array() )
					),
					wp_kses( $names, array( 'code' => array() ) )
				);
				?>
			</p>
		</div>
		<?php
		Inline_Assets::script( 'notices', array(), '', array( 'wp-util' ) );
	}

	/**
	 * AJAX: dismiss the legacy root files note.
	 *
	 * @return void
	 */
	public static function ajax_dismiss_legacy_notice(): void {
		check_ajax_referer( 'agentic_dismiss_root_files_notice', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}
		$state = get_option( self::LEGACY_NOTICE_OPTION );
		$state = is_array( $state ) ? $state : array( 'files' => array() );

		$state['dismissed'] = true;
		update_option( self::LEGACY_NOTICE_OPTION, $state, false );
		wp_send_json_success();
	}
}
