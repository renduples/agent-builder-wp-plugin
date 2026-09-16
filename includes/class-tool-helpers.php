<?php
/**
 * Tool Helpers
 *
 * Shared utility methods used across multiple standalone tools.
 *
 * @package    Agent_Builder
 * @subpackage Tools
 * @since      2.0.0
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static helper methods shared by tool implementations.
 */
class Tool_Helpers {

	/**
	 * Gate mutating deploy tools for interactive non-admin users.
	 *
	 * Returns null when the call may proceed. When a real user is logged in but
	 * lacks manage_options, returns an error payload. Unauthenticated contexts
	 * (cron / hooks / CLI) are allowed through — risk gating and agent assignment
	 * still apply.
	 *
	 * @return array{error:string}|null
	 */
	public static function deny_unless_admin_user(): ?array {
		$user_id = get_current_user_id();
		if ( $user_id > 0 && ! current_user_can( 'manage_options' ) ) {
			return array(
				'error' => __( 'Administrator privileges are required for this action.', 'agent-builder' ),
			);
		}
		return null;
	}

	/**
	 * Check whether a name matches any sensitive pattern.
	 *
	 * Used by tools that read/write options or post meta to block
	 * access to passwords, keys, or secrets.
	 *
	 * @param string $name The name to check.
	 * @return bool True if the name is sensitive.
	 */
	public static function is_sensitive_name( string $name ): bool {
		$sensitive_patterns = array(
			'password',
			'secret',
			'api_key',
			'apikey',
			'auth_key',
			'auth_salt',
			'logged_in_key',
			'logged_in_salt',
			'nonce_key',
			'nonce_salt',
			'secure_auth_key',
			'secure_auth_salt',
			'stripe',
			'paypal',
		);

		$lower_name = strtolower( $name );
		foreach ( $sensitive_patterns as $pattern ) {
			if ( false !== strpos( $lower_name, $pattern ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Get focus keyword for a post from any known SEO meta key.
	 *
	 * Checks all known SEO plugin meta keys in priority order.
	 * Plugin-agnostic: reads whatever is stored, regardless of source.
	 *
	 * @param int $post_id The post ID.
	 * @return string The focus keyword, or empty string if none set.
	 */
	public static function get_focus_keyword( int $post_id ): string {
		$meta_keys = array(
			'_agentic_focus_keyword',
			'rank_math_focus_keyword',
			'_yoast_wpseo_focuskw',
		);

		foreach ( $meta_keys as $key ) {
			$value = get_post_meta( $post_id, $key, true );
			if ( ! empty( $value ) ) {
				return (string) $value;
			}
		}

		return '';
	}

	/**
	 * Get the SEO meta keys to write to.
	 *
	 * Returns the Agentic SEO Engine meta keys — the only write target.
	 * Tools should always write to these keys; reading is done from
	 * rendered HTML or the universal read helpers.
	 *
	 * @return array{description: string, title: string, focus_keyword: string, robots: string, schema: string}
	 */
	public static function get_seo_meta_keys(): array {
		return array(
			'description'   => '_agentic_seo_description',
			'title'         => '_agentic_seo_title',
			'focus_keyword' => '_agentic_focus_keyword',
			'robots'        => '_agentic_robots',
			'schema'        => '_agentic_schema',
		);
	}

	/**
	 * Score link candidates for a source post.
	 *
	 * Finds published posts/pages relevant to the source and scores them
	 * by shared categories, tags, title keywords, and content mentions.
	 * Excludes utility/legal pages and the default category.
	 *
	 * @param \WP_Post $source_post The post to find links for.
	 * @return array Scored candidates sorted by relevance.
	 */
	public static function score_link_candidates( \WP_Post $source_post ): array {
		$utility_slugs = array(
			'checkout',
			'cart',
			'my-account',
			'shop',
			'privacy-policy',
			'terms-and-conditions',
			'cookie-policy',
			'refund-policy',
			'disclaimer',
		);

		$stop_words = array(
			'the',
			'a',
			'an',
			'is',
			'are',
			'was',
			'were',
			'in',
			'on',
			'at',
			'to',
			'for',
			'of',
			'with',
			'and',
			'or',
			'not',
			'it',
			'this',
			'that',
			'by',
			'from',
			'as',
			'be',
			'has',
			'had',
			'have',
			'do',
			'does',
			'did',
			'but',
			'if',
			'so',
			'no',
			'up',
			'out',
			'about',
			'into',
			'over',
			'after',
			'your',
			'you',
			'how',
			'what',
			'why',
			'when',
			'which',
			'who',
			'can',
			'will',
			'our',
			'we',
			'all',
			'its',
			'get',
			'set',
			'agent',
			'builder',
		);

		$default_cat = (int) get_option( 'default_category', 1 );

		$source_cats = wp_get_post_categories( $source_post->ID );
		$source_cats = array_filter( $source_cats, fn( $c ) => $c !== $default_cat );
		$source_tags = wp_get_post_tags( $source_post->ID, array( 'fields' => 'ids' ) );
		$title_words = array_diff(
			array_unique( array_map( 'strtolower', preg_split( '/\W+/', $source_post->post_title ) ) ),
			$stop_words
		);

		$all_posts = get_posts(
			array(
				'post_type'      => array( 'post', 'page' ),
				'post_status'    => 'publish',
				'posts_per_page' => 200, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- Internal link analysis requires full post list.
			)
		);

		// Filter out the source post (avoids exclusionary query parameter).
		$all_posts = array_filter(
			$all_posts,
			static function ( $p ) use ( $source_post ) {
				return $p->ID !== $source_post->ID;
			}
		);

		$scored = array();

		foreach ( $all_posts as $candidate ) {
			$slug = $candidate->post_name;
			if ( in_array( $slug, $utility_slugs, true ) ) {
				continue;
			}

			$score = 0;

			$cand_cats = wp_get_post_categories( $candidate->ID );
			$cand_cats = array_filter( $cand_cats, fn( $c ) => $c !== $default_cat );
			if ( ! empty( array_intersect( $source_cats, $cand_cats ) ) ) {
				$score += 3;
			}

			$cand_tags = wp_get_post_tags( $candidate->ID, array( 'fields' => 'ids' ) );
			if ( ! empty( array_intersect( $source_tags, $cand_tags ) ) ) {
				$score += 2;
			}

			$cand_title_words = array_diff(
				array_unique( array_map( 'strtolower', preg_split( '/\W+/', $candidate->post_title ) ) ),
				$stop_words
			);
			if ( ! empty( array_intersect( $title_words, $cand_title_words ) ) ) {
				$score += 2;
			}

			$source_content_lower = strtolower( $source_post->post_content );
			$cand_title_lower     = strtolower( $candidate->post_title );
			if ( false !== strpos( $source_content_lower, $cand_title_lower ) ) {
				$score += 3;
			}

			if ( $candidate->post_type === $source_post->post_type ) {
				++$score;
			}

			if ( $score > 0 ) {
				$scored[] = array(
					'post_id' => $candidate->ID,
					'title'   => $candidate->post_title,
					'url'     => wp_make_link_relative( get_permalink( $candidate->ID ) ),
					'type'    => $candidate->post_type,
					'score'   => $score,
				);
			}
		}

		usort( $scored, fn( $a, $b ) => $b['score'] - $a['score'] );

		return $scored;
	}

	/**
	 * Get the meta description for a post.
	 *
	 * Reads from all known SEO meta keys in priority order,
	 * falling back to the post excerpt. Plugin-agnostic.
	 *
	 * @param int    $post_id The post ID.
	 * @param string $excerpt The post excerpt (fallback).
	 * @return string The meta description.
	 */
	public static function get_meta_description( int $post_id, string $excerpt = '' ): string {
		$meta_keys = array(
			'_agentic_seo_description',
			'rank_math_description',
			'_yoast_wpseo_metadesc',
		);

		foreach ( $meta_keys as $key ) {
			$meta = get_post_meta( $post_id, $key, true );
			if ( ! empty( $meta ) ) {
				return (string) $meta;
			}
		}

		return $excerpt;
	}

	/**
	 * Extract linkable phrases from HTML content.
	 *
	 * Finds headings, bold text, and notable word pairs that could
	 * serve as anchor text for contextual links.
	 *
	 * @param string $content HTML content to extract from.
	 * @return array{headings: string[], bold_text: string[], word_pairs: string[]}
	 */
	public static function extract_linkable_phrases( string $content ): array {
		$headings  = array();
		$bold_text = array();

		if ( preg_match_all( '/<h[2-4][^>]*>(.*?)<\/h[2-4]>/si', $content, $matches ) ) {
			foreach ( $matches[1] as $heading ) {
				$clean = wp_strip_all_tags( $heading );
				if ( strlen( $clean ) > 3 && strlen( $clean ) < 80 ) {
					$headings[] = $clean;
				}
			}
		}

		if ( preg_match_all( '/<strong>(.*?)<\/strong>/si', $content, $matches ) ) {
			foreach ( $matches[1] as $bold ) {
				$clean = wp_strip_all_tags( $bold );
				if ( strlen( $clean ) > 3 && strlen( $clean ) < 60 && ! str_contains( $clean, '<' ) ) {
					$bold_text[] = $clean;
				}
			}
		}

		$text_only  = wp_strip_all_tags( $content );
		$words      = preg_split( '/\s+/', $text_only );
		$word_pairs = array();
		$stop_words = array( 'the', 'a', 'an', 'is', 'are', 'in', 'on', 'to', 'for', 'of', 'and', 'or', 'it', 'this', 'that', 'by', 'with' );
		$word_count = count( $words ) - 1;

		for ( $i = 0; $i < $word_count; $i++ ) {
			$w1 = strtolower( $words[ $i ] );
			$w2 = strtolower( $words[ $i + 1 ] );
			if ( strlen( $w1 ) > 3 && strlen( $w2 ) > 3 && ! in_array( $w1, $stop_words, true ) && ! in_array( $w2, $stop_words, true ) ) {
				$pair = $words[ $i ] . ' ' . $words[ $i + 1 ];
				if ( ! in_array( $pair, $word_pairs, true ) && count( $word_pairs ) < 10 ) {
					$word_pairs[] = $pair;
				}
			}
		}

		return array(
			'headings'   => array_slice( $headings, 0, 5 ),
			'bold_text'  => array_slice( array_unique( $bold_text ), 0, 5 ),
			'word_pairs' => $word_pairs,
		);
	}

	/**
	 * Estimate syllable count using a vowel-group heuristic.
	 *
	 * Good enough for Flesch-Kincaid scoring — not a dictionary lookup.
	 *
	 * @param string $text Plain text to count syllables in.
	 * @return int Estimated total syllable count.
	 */
	public static function count_syllables( string $text ): int {
		$words = preg_split( '/\s+/', mb_strtolower( $text ), -1, PREG_SPLIT_NO_EMPTY );
		$total = 0;

		foreach ( $words as $word ) {
			$word = preg_replace( '/[^a-z]/', '', $word );
			if ( ! $word ) {
				continue;
			}
			$count = preg_match_all( '/[aeiouy]+/', $word );
			if ( strlen( $word ) > 2 && str_ends_with( $word, 'e' ) ) {
				--$count;
			}
			$total += max( 1, $count );
		}

		return $total;
	}

	/**
	 * AI bot definitions used by AI Radar tools.
	 *
	 * @return array<string, array{label: string, severity: string, points: int}>
	 */
	public static function get_ai_bots(): array {
		return array(
			'GPTBot'          => array(
				'label'    => 'ChatGPT / OpenAI',
				'severity' => 'critical',
				'points'   => 10,
			),
			'ChatGPT-User'    => array(
				'label'    => 'ChatGPT Browsing',
				'severity' => 'critical',
				'points'   => 8,
			),
			'ClaudeBot'       => array(
				'label'    => 'Claude / Anthropic',
				'severity' => 'high',
				'points'   => 7,
			),
			'anthropic-ai'    => array(
				'label'    => 'Anthropic AI (alternative UA)',
				'severity' => 'high',
				'points'   => 0,
			),
			'Google-Extended' => array(
				'label'    => 'Google AI Overview / Gemini',
				'severity' => 'high',
				'points'   => 3,
			),
			'PerplexityBot'   => array(
				'label'    => 'Perplexity',
				'severity' => 'high',
				'points'   => 2,
			),
		);
	}

	/**
	 * Read robots.txt from disk (physical file) or via HTTP (virtual).
	 *
	 * @return array{content: string, source: string, path: string, writable: bool}
	 */
	public static function read_robots_txt(): array {
		$physical_path = rtrim( ABSPATH, '/' ) . '/robots.txt';

		if ( file_exists( $physical_path ) ) {
			return array(
				'source'   => 'physical_file',
				'path'     => $physical_path,
				'content'  => (string) file_get_contents( $physical_path ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local filesystem read, wp_remote_get() is for HTTP.
				'writable' => wp_is_writable( $physical_path ),
			);
		}

		$response = wp_remote_get(
			home_url( '/robots.txt' ),
			array(
				'timeout'   => 10,
				'sslverify' => false,
			)
		);
		$content  = is_wp_error( $response ) ? '' : (string) wp_remote_retrieve_body( $response );

		return array(
			'source'   => 'virtual',
			'path'     => $physical_path,
			'content'  => $content,
			'writable' => wp_is_writable( ABSPATH ),
			'note'     => 'WordPress generates this file dynamically. Creating a physical robots.txt overrides it.',
		);
	}

	/**
	 * Parse robots.txt content and determine per-bot access.
	 *
	 * @param  string $content Raw robots.txt content.
	 * @return array{bot_access: array<string,string>, blanket_block: bool, explicit_bots: array<string,bool>}
	 */
	public static function parse_robots_bots( string $content ): array {
		$lines          = preg_split( '/\r\n|\n|\r/', $content );
		$groups         = array();
		$current_agents = array();
		$current_rules  = array();
		$explicit_bots  = array();

		$flush_group = function () use ( &$groups, &$current_agents, &$current_rules, &$explicit_bots ) {
			foreach ( $current_agents as $agent ) {
				$key = strtolower( $agent );
				if ( '*' !== $key ) {
					$explicit_bots[ $key ] = true;
				}
				if ( ! isset( $groups[ $key ] ) ) {
					$groups[ $key ] = array();
				}
				$groups[ $key ] = array_merge( $groups[ $key ], $current_rules );
			}
			$current_agents = array();
			$current_rules  = array();
		};

		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line || '#' === ( $line[0] ?? '' ) ) {
				if ( ! empty( $current_agents ) ) {
					$flush_group();
				}
				continue;
			}
			if ( preg_match( '/^User-agent:\s*(.+)$/i', $line, $m ) ) {
				if ( ! empty( $current_rules ) ) {
					$flush_group();
				}
				$current_agents[] = trim( $m[1] );
			} elseif ( preg_match( '/^Disallow:\s*(.*)$/i', $line, $m ) ) {
				$current_rules[] = array(
					'type' => 'disallow',
					'path' => trim( $m[1] ),
				);
			} elseif ( preg_match( '/^Allow:\s*(.*)$/i', $line, $m ) ) {
				$current_rules[] = array(
					'type' => 'allow',
					'path' => trim( $m[1] ),
				);
			}
		}
		if ( ! empty( $current_agents ) ) {
			$flush_group();
		}

		$blanket_block = false;
		foreach ( $groups['*'] ?? array() as $rule ) {
			if ( 'disallow' === $rule['type'] && '/' === $rule['path'] ) {
				$blanket_block = true;
				break;
			}
		}

		$ai_bots    = self::get_ai_bots();
		$bot_access = array();
		foreach ( array_keys( $ai_bots ) as $bot ) {
			$bot_lower  = strtolower( $bot );
			$specific   = $groups[ $bot_lower ] ?? null;
			$wild       = $groups['*'] ?? array();
			$applicable = $specific ?? $wild;

			$access = 'allowed';
			foreach ( $applicable as $rule ) {
				if ( 'disallow' === $rule['type'] && '/' === $rule['path'] ) {
					$access = 'blocked';
					break;
				}
				if ( 'disallow' === $rule['type'] && '' === $rule['path'] ) {
					$access = 'allowed';
					break;
				}
			}
			$bot_access[ $bot ] = $access;
		}

		return array(
			'bot_access'    => $bot_access,
			'blanket_block' => $blanket_block,
			'explicit_bots' => $explicit_bots,
		);
	}

	/**
	 * Convert numeric AI Radar score to letter grade.
	 *
	 * @param int|float $score Score from 0 to 100.
	 * @return string
	 */
	public static function score_to_grade( int|float $score ): string {
		return match ( true ) {
			$score >= 90 => 'A — Your site is well-optimised for AI search',
			$score >= 75 => 'B — Good foundation, a few gaps to close',
			$score >= 50 => 'C — Partially visible, significant improvements needed',
			$score >= 25 => 'D — Mostly invisible to AI search engines',
			default      => 'F — AI search engines cannot see your site',
		};
	}

	/**
	 * Get the agent library directory path.
	 *
	 * @return string
	 */
	public static function get_library_path(): string {
		return AGENT_BUILDER_DIR . 'library/agents/';
	}

	/**
	 * Base path the agent can access (wp-content).
	 *
	 * @return string
	 */
	public static function get_allowed_repo_base(): string {
		return WP_CONTENT_DIR;
	}

	/**
	 * Check if a relative path stays inside uploads/.
	 *
	 * Previously also allowed plugins/ and themes/, but nothing in this
	 * repo ever creates a code_change proposal targeting those, and a
	 * write into another plugin's or the active theme's directory is
	 * exactly the "generates code intended to run on the site" pattern
	 * the WordPress.org Plugin Developer FAQ prohibits — narrowed to the
	 * one scope (uploads) File_Manager::is_allowed_path() actually permits
	 * a code_change write to reach anyway, rather than leaving a broader
	 * allowlist here that depends on File_Manager's roots never changing.
	 *
	 * @param string $path Relative path.
	 * @return bool
	 */
	public static function is_allowed_subpath( string $path ): bool {
		$clean = ltrim( str_replace( '..', '', $path ), '/\\' );

		return 'uploads' === $clean || str_starts_with( $clean, 'uploads/' );
	}

	/**
	 * Determine the directory scope from a relative path.
	 *
	 * @param string $path Relative path (e.g. 'plugins/my-plugin/file.php').
	 * @return string Scope name: 'plugins', 'themes', 'uploads', or empty string.
	 */
	public static function get_path_scope( string $path ): string {
		$clean = ltrim( str_replace( '..', '', $path ), '/\\' );

		foreach ( array( 'plugins', 'themes', 'uploads' ) as $scope ) {
			if ( $scope === $clean || str_starts_with( $clean, $scope . '/' ) ) {
				return $scope;
			}
		}

		return '';
	}

	/**
	 * Check if a scoped operation is allowed.
	 *
	 * Uses the agentic_tool_scopes option which stores an associative array
	 * of 'scope:operation' => bool entries (e.g. 'plugins:read' => true).
	 * When a scope entry is missing it defaults to allowed (true).
	 *
	 * @param string $scope     Directory scope ('plugins', 'themes', 'uploads').
	 * @param string $operation Operation type ('read' or 'write').
	 * @return bool Whether the operation is permitted.
	 */
	public static function is_scope_allowed( string $scope, string $operation ): bool {
		$scopes = get_option( 'agentic_tool_scopes', array() );
		if ( ! is_array( $scopes ) ) {
			return true;
		}

		$key = $scope . ':' . $operation;
		return ! isset( $scopes[ $key ] ) || (bool) $scopes[ $key ];
	}

	/**
	 * Create a timestamped backup of a file before modification.
	 *
	 * Backups are stored in wp-content/agentic-backups/ with the format
	 * {Ymd-His}_{safe_path}. Returns the backup path on success or null
	 * if the source file does not exist or the copy fails.
	 *
	 * @param string $full_path Absolute path to the file to back up.
	 * @return string|null Backup file path or null on failure.
	 */
	public static function backup_file( string $full_path ): ?string {
		if ( ! file_exists( $full_path ) ) {
			return null;
		}

		$backup_dir = AGENTIC_BACKUPS_DIR;

		File_Manager::ensure_protected_dir( $backup_dir );

		$relative    = str_replace( array( ABSPATH, WP_CONTENT_DIR . '/' ), '', $full_path );
		$safe_name   = str_replace( '/', '__', $relative );
		$timestamp   = gmdate( 'Ymd-His' );
		$backup_path = $backup_dir . '/' . $timestamp . '_' . $safe_name;

		if ( File_Manager::copy( $full_path, $backup_path ) ) {
			return $backup_path;
		}

		return null;
	}

	/**
	 * List all backup files with metadata.
	 *
	 * Each entry includes the backup filename, timestamp, original path,
	 * file size, and whether the original file still exists.
	 *
	 * @return array<int, array{file: string, path: string, original: string, original_exists: bool, size: int, created: string}>
	 */
	public static function get_backups(): array {
		$backup_dir = AGENTIC_BACKUPS_DIR;

		if ( ! is_dir( $backup_dir ) ) {
			return array();
		}

		$files   = glob( $backup_dir . '/*' );
		$backups = array();

		if ( ! is_array( $files ) ) {
			return array();
		}

		foreach ( $files as $file ) {
			if ( is_dir( $file ) ) {
				continue;
			}

			$basename = basename( $file );

			// Skip index.php or .htaccess security files.
			if ( in_array( $basename, array( 'index.php', '.htaccess' ), true ) ) {
				continue;
			}

			// Parse timestamp and original path from filename: {Ymd-His}_{safe_path}.
			if ( ! preg_match( '/^(\d{8}-\d{6})_(.+)$/', $basename, $m ) ) {
				continue;
			}

			$timestamp_raw = $m[1];
			$safe_name     = $m[2];

			// Reconstruct the original path.
			$relative = str_replace( '__', '/', $safe_name );

			// Determine original absolute path. Files from ABSPATH (like robots.txt, llms.txt)
			// won't have a wp-content prefix. Files from wp-content will.
			if ( str_starts_with( $relative, 'plugins/' ) || str_starts_with( $relative, 'themes/' ) || str_starts_with( $relative, 'agentic-agents/' ) ) {
				$original = WP_CONTENT_DIR . '/' . $relative;
			} else {
				$original = ABSPATH . $relative;
			}

			// Parse the timestamp.
			$dt = \DateTimeImmutable::createFromFormat( 'Ymd-His', $timestamp_raw, new \DateTimeZone( 'UTC' ) );

			$backups[] = array(
				'file'            => $basename,
				'path'            => $file,
				'original'        => $original,
				'original_rel'    => $relative,
				'original_exists' => file_exists( $original ),
				'size'            => (int) filesize( $file ),
				'created'         => $dt ? $dt->format( 'Y-m-d H:i:s' ) : '',
			);
		}

		// Sort newest first.
		usort( $backups, fn( $a, $b ) => strcmp( $b['file'], $a['file'] ) );

		return $backups;
	}

	/**
	 * Restore a backup by copying it over the original file.
	 *
	 * @param string $backup_filename The backup filename (not a full path).
	 * @return array{success: bool, message: string, original?: string}
	 */
	public static function restore_backup( string $backup_filename ): array {
		$backup_dir  = AGENTIC_BACKUPS_DIR;
		$backup_path = $backup_dir . '/' . $backup_filename;

		// Prevent directory traversal.
		if ( basename( $backup_filename ) !== $backup_filename || ! file_exists( $backup_path ) ) {
			return array(
				'success' => false,
				'message' => 'Backup file not found.',
			);
		}

		// Parse the original path from the filename.
		if ( ! preg_match( '/^\d{8}-\d{6}_(.+)$/', $backup_filename, $m ) ) {
			return array(
				'success' => false,
				'message' => 'Invalid backup filename format.',
			);
		}

		$relative = str_replace( '__', '/', $m[1] );

		if ( str_starts_with( $relative, 'plugins/' ) || str_starts_with( $relative, 'themes/' ) || str_starts_with( $relative, 'agentic-agents/' ) ) {
			$original = WP_CONTENT_DIR . '/' . $relative;
		} else {
			$original = ABSPATH . $relative;
		}

		// Back up the current version before restoring (so restore is itself reversible).
		self::backup_file( $original );

		if ( File_Manager::copy( $backup_path, $original, true ) ) {
			return array(
				'success'  => true,
				'message'  => 'Restored ' . $relative . ' from backup.',
				'original' => $original,
			);
		}

		return array(
			'success' => false,
			'message' => 'Failed to copy backup file. Check permissions.',
		);
	}

	// =========================================================================
	// Database table backups
	// =========================================================================

	/**
	 * Subdirectory for database table backups inside the backup directory.
	 */
	const DB_BACKUP_SUBDIR = 'db';

	/**
	 * Maximum number of backup copies to keep per table.
	 */
	const DB_BACKUP_KEEP = 3;

	/**
	 * Row count threshold. Tables with more rows than this use row-level
	 * backup (only the affected rows) instead of a full table dump.
	 */
	const DB_BACKUP_ROW_THRESHOLD = 1000;

	/**
	 * Tool → table map with row-key hints for targeted row-level backups.
	 *
	 * Each table entry is either a plain string (full-table backup only) or
	 * an array with 'arg' (tool argument name holding the row ID) and 'col'
	 * (column to match in that table).
	 *
	 * @return array<string, array<string, array{arg: string, col: string}|string>>
	 */
	public static function get_tool_tables_map(): array {
		// Shorthand helpers — most tools use post_id → posts.ID / postmeta.post_id.
		$post = array(
			'arg' => 'post_id',
			'col' => 'ID',
		);
		$meta = array(
			'arg' => 'post_id',
			'col' => 'post_id',
		);
		$rels = array(
			'arg' => 'post_id',
			'col' => 'object_id',
		);

		return array(
			// Posts & content.
			'create_post_content'        => array(
				'posts'              => 'posts',
				'postmeta'           => 'postmeta',
				'term_relationships' => 'term_relationships',
			),
			'update_post_content'        => array(
				'posts'              => $post,
				'postmeta'           => $meta,
				'term_relationships' => $rels,
			),
			'db_create_post'             => array( 'posts' => 'posts' ),
			'db_update_post'             => array(
				'posts' => array(
					'arg' => 'id',
					'col' => 'ID',
				),
			),
			'optimize_post_title'        => array(
				'posts'    => $post,
				'postmeta' => $meta,
			),
			'insert_contextual_link'     => array( 'posts' => $post ),
			'add_related_links_section'  => array( 'posts' => $post ),
			'fix_orphan_pages'           => array( 'posts' => 'posts' ),

			// SEO / meta.
			'update_post_seo'            => array(
				'posts'    => $post,
				'postmeta' => $meta,
			),
			'add_faq_schema'             => array( 'postmeta' => $meta ),
			'update_attachment_alt_text' => array(
				'posts'    => array(
					'arg' => 'attachment_id',
					'col' => 'ID',
				),
				'postmeta' => array(
					'arg' => 'attachment_id',
					'col' => 'post_id',
				),
			),

			// Media (creates new rows — no row-level key to back up).
			'generate_image'             => array(
				'posts'    => 'posts',
				'postmeta' => 'postmeta',
			),
			'edit_image'                 => array(
				'posts'    => 'posts',
				'postmeta' => 'postmeta',
			),
			'upscale_image'              => array(
				'posts'    => 'posts',
				'postmeta' => 'postmeta',
			),

			// Taxonomy.
			'manage_categories'          => array(
				'terms'         => 'terms',
				'term_taxonomy' => 'term_taxonomy',
			),
			'manage_tags'                => array(
				'terms'         => 'terms',
				'term_taxonomy' => 'term_taxonomy',
			),

			// Options.
			'db_update_option'           => array(
				'options' => array(
					'arg' => 'option',
					'col' => 'option_name',
				),
			),

			// Forms.
			'create_form'                => array(
				'posts'    => 'posts',
				'postmeta' => 'postmeta',
			),
			'update_form'                => array(
				'posts'    => array(
					'arg' => 'form_id',
					'col' => 'ID',
				),
				'postmeta' => array(
					'arg' => 'form_id',
					'col' => 'post_id',
				),
			),
			'delete_form'                => array(
				'posts' => array(
					'arg' => 'form_id',
					'col' => 'ID',
				),
			),
		);
	}

	/**
	 * Back up database tables that a tool is about to modify.
	 *
	 * Small tables get a full dump. Large tables get a targeted row-level
	 * backup using the tool's arguments to identify affected rows.
	 *
	 * @param string $tool_name Tool about to execute.
	 * @param array  $arguments Tool arguments (used for row-level targeting).
	 * @return string[] List of backup filenames created.
	 */
	public static function backup_tables_for_tool( string $tool_name, array $arguments = array() ): array {
		$map     = self::get_tool_tables_map();
		$entries = $map[ $tool_name ] ?? array();

		if ( empty( $entries ) ) {
			return array();
		}

		$created = array();
		$done    = array(); // Track tables already backed up this call.

		foreach ( $entries as $table => $hint ) {
			// Deduplicate — a table only needs one backup per execution.
			if ( isset( $done[ $table ] ) ) {
				continue;
			}
			$done[ $table ] = true;

			$file = self::backup_table( $table, $hint, $arguments );
			if ( $file ) {
				$created[] = $file;
			}
		}

		return $created;
	}

	/**
	 * Create a JSON backup of a database table (full or row-level).
	 *
	 * - Tables with <= DB_BACKUP_ROW_THRESHOLD rows: full table dump.
	 * - Larger tables: row-level dump using the hint + arguments to build a
	 *   WHERE clause. If no hint is available, falls back to full dump if
	 *   the table is under 50 000 rows, otherwise skips.
	 *
	 * @param string       $table     Unprefixed table name.
	 * @param array|string $hint      Row-key hint {'arg','col'} or plain table name string.
	 * @param array        $arguments Tool arguments for row-level extraction.
	 * @return string|null Backup filename or null on skip/failure.
	 */
	public static function backup_table( string $table, array|string $hint = '', array $arguments = array() ): ?string {
		global $wpdb;

		$full_table = $wpdb->prefix . $table;

		// Verify the table exists.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full_table ) );
		if ( ! $exists ) {
			return null;
		}

		// Protects the shared AGENTIC_BACKUPS_DIR root even if this runs
		// before backup_file() ever has — idempotent, cheap to call again.
		File_Manager::ensure_protected_dir( AGENTIC_BACKUPS_DIR );

		$backup_dir = AGENTIC_BACKUPS_DIR . '/' . self::DB_BACKUP_SUBDIR;
		wp_mkdir_p( $backup_dir );

		// Throttle: skip if a backup for this table was created in the last 60s.
		$existing = glob( $backup_dir . '/*_' . $table . '.json' );
		if ( is_array( $existing ) && ! empty( $existing ) ) {
			sort( $existing );
			$newest = end( $existing );
			if ( filemtime( $newest ) > ( time() - 60 ) ) {
				return null;
			}
		}

		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$full_table}`" );

		// Decide: full dump vs. row-level.
		if ( $row_count <= self::DB_BACKUP_ROW_THRESHOLD ) {
			return self::dump_full_table( $table, $full_table, $backup_dir );
		}

		// Large table — try row-level if we have a hint with an arg+col.
		if ( is_array( $hint ) && ! empty( $hint['arg'] ) && ! empty( $hint['col'] ) ) {
			$key_value = $arguments[ $hint['arg'] ] ?? null;
			if ( null !== $key_value && '' !== $key_value ) {
				return self::dump_table_rows( $table, $full_table, $hint['col'], $key_value, $backup_dir );
			}
		}

		// No row-level hint or no matching argument — skip large tables.
		return null;
	}

	/**
	 * Full table dump to JSON file.
	 *
	 * @param string $table      Unprefixed table name.
	 * @param string $full_table Prefixed table name.
	 * @param string $backup_dir Backup directory path.
	 * @return string|null Filename or null on failure.
	 */
	private static function dump_full_table( string $table, string $full_table, string $backup_dir ): ?string {
		global $wpdb;

		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT * FROM `{$full_table}`", ARRAY_A );

		$timestamp = gmdate( 'Ymd-His' );
		$filename  = $timestamp . '_' . $table . '.json';
		$path      = $backup_dir . '/' . $filename;

		$payload = array(
			'table'     => $full_table,
			'type'      => 'full',
			'created'   => gmdate( 'Y-m-d H:i:s' ),
			'row_count' => count( $rows ),
			'rows'      => $rows,
		);

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$written = file_put_contents( $path, (string) wp_json_encode( $payload ) );
		if ( false === $written ) {
			return null;
		}

		self::prune_table_backups( $table );

		return $filename;
	}

	/**
	 * Row-level dump: backs up only matching rows from a large table.
	 *
	 * @param string     $table      Unprefixed table name.
	 * @param string     $full_table Prefixed table name.
	 * @param string     $column     Column to filter on.
	 * @param string|int $value      Value to match.
	 * @param string     $backup_dir Backup directory path.
	 * @return string|null Filename or null on failure.
	 */
	private static function dump_table_rows( string $table, string $full_table, string $column, string|int $value, string $backup_dir ): ?string {
		global $wpdb;

		$safe_col = preg_replace( '/[^a-zA-Z0-9_]/', '', $column );

		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$wpdb->prepare( "SELECT * FROM `{$full_table}` WHERE `{$safe_col}` = %s", $value ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			ARRAY_A
		);

		// Nothing to back up (new row that doesn't exist yet).
		if ( empty( $rows ) ) {
			return null;
		}

		$timestamp = gmdate( 'Ymd-His' );
		$filename  = $timestamp . '_' . $table . '.json';
		$path      = $backup_dir . '/' . $filename;

		$payload = array(
			'table'      => $full_table,
			'type'       => 'partial',
			'key_column' => $safe_col,
			'key_value'  => $value,
			'created'    => gmdate( 'Y-m-d H:i:s' ),
			'row_count'  => count( $rows ),
			'rows'       => $rows,
		);

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$written = file_put_contents( $path, (string) wp_json_encode( $payload ) );
		if ( false === $written ) {
			return null;
		}

		self::prune_table_backups( $table );

		return $filename;
	}

	/**
	 * Delete oldest backups for a table, keeping at most DB_BACKUP_KEEP copies.
	 *
	 * @param string $table Unprefixed table name.
	 */
	private static function prune_table_backups( string $table ): void {
		$backup_dir = AGENTIC_BACKUPS_DIR . '/' . self::DB_BACKUP_SUBDIR;
		$files      = glob( $backup_dir . '/*_' . $table . '.json' );

		if ( ! is_array( $files ) || count( $files ) <= self::DB_BACKUP_KEEP ) {
			return;
		}

		sort( $files );

		$to_delete = array_slice( $files, 0, count( $files ) - self::DB_BACKUP_KEEP );
		foreach ( $to_delete as $file ) {
			wp_delete_file( $file );
		}
	}

	/**
	 * List all database table backups with metadata.
	 *
	 * @return array<int, array{file: string, path: string, table: string, type: string, row_count: int, size: int, created: string}>
	 */
	public static function get_table_backups(): array {
		$backup_dir = AGENTIC_BACKUPS_DIR . '/' . self::DB_BACKUP_SUBDIR;

		if ( ! is_dir( $backup_dir ) ) {
			return array();
		}

		$files   = glob( $backup_dir . '/*.json' );
		$backups = array();

		if ( ! is_array( $files ) ) {
			return array();
		}

		foreach ( $files as $file ) {
			$basename = basename( $file );

			if ( ! preg_match( '/^(\d{8}-\d{6})_(.+)\.json$/', $basename, $m ) ) {
				continue;
			}

			$timestamp_raw = $m[1];
			$table_short   = $m[2];
			$size          = (int) filesize( $file );

			// Quick parse header fields without loading all rows.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$head = (string) file_get_contents( $file, false, null, 0, 512 );

			$row_count = 0;
			if ( preg_match( '/"row_count"\s*:\s*(\d+)/', $head, $rc ) ) {
				$row_count = (int) $rc[1];
			}

			$type = 'full';
			if ( preg_match( '/"type"\s*:\s*"(full|partial)"/', $head, $tc ) ) {
				$type = $tc[1];
			}

			$dt = \DateTimeImmutable::createFromFormat( 'Ymd-His', $timestamp_raw, new \DateTimeZone( 'UTC' ) );

			global $wpdb;
			$backups[] = array(
				'file'        => $basename,
				'path'        => $file,
				'table'       => $wpdb->prefix . $table_short,
				'table_short' => $table_short,
				'type'        => $type,
				'row_count'   => $row_count,
				'size'        => $size,
				'created'     => $dt ? $dt->format( 'Y-m-d H:i:s' ) : '',
			);
		}

		usort( $backups, fn( $a, $b ) => strcmp( $b['file'], $a['file'] ) );

		return $backups;
	}

	/**
	 * Restore a database table from a JSON backup.
	 *
	 * Handles both full backups (truncate + re-insert) and partial backups
	 * (delete matching rows + re-insert). Creates a backup of the current
	 * state first so restore is itself reversible.
	 *
	 * @param string $backup_filename The backup filename (not a full path).
	 * @return array{success: bool, message: string, table?: string, rows_restored?: int}
	 */
	public static function restore_table_backup( string $backup_filename ): array {
		$backup_dir  = AGENTIC_BACKUPS_DIR . '/' . self::DB_BACKUP_SUBDIR;
		$backup_path = $backup_dir . '/' . $backup_filename;

		if ( basename( $backup_filename ) !== $backup_filename || ! file_exists( $backup_path ) ) {
			return array(
				'success' => false,
				'message' => 'Backup file not found.',
			);
		}

		if ( ! preg_match( '/^\d{8}-\d{6}_(.+)\.json$/', $backup_filename, $m ) ) {
			return array(
				'success' => false,
				'message' => 'Invalid backup filename format.',
			);
		}

		$table_short = $m[1];

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$json = (string) file_get_contents( $backup_path );
		$data = json_decode( $json, true );

		if ( ! is_array( $data ) || empty( $data['table'] ) || ! isset( $data['rows'] ) ) {
			return array(
				'success' => false,
				'message' => 'Invalid or corrupted backup file.',
			);
		}

		global $wpdb;
		$full_table = $wpdb->prefix . $table_short;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full_table ) );
		if ( ! $exists ) {
			return array(
				'success' => false,
				'message' => 'Table ' . $full_table . ' does not exist.',
			);
		}

		$type = $data['type'] ?? 'full';

		// Back up current state before restoring.
		if ( 'partial' === $type && ! empty( $data['key_column'] ) && isset( $data['key_value'] ) ) {
			self::dump_table_rows(
				$table_short,
				$full_table,
				$data['key_column'],
				$data['key_value'],
				AGENTIC_BACKUPS_DIR . '/' . self::DB_BACKUP_SUBDIR
			);
		} else {
			self::dump_full_table(
				$table_short,
				$full_table,
				AGENTIC_BACKUPS_DIR . '/' . self::DB_BACKUP_SUBDIR
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'START TRANSACTION' );

		if ( 'partial' === $type && ! empty( $data['key_column'] ) && isset( $data['key_value'] ) ) {
			// Row-level restore: delete matching rows, then re-insert.
			$safe_col = preg_replace( '/[^a-zA-Z0-9_]/', '', $data['key_column'] );
			// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "DELETE FROM `{$full_table}` WHERE `{$safe_col}` = %s", $data['key_value'] ) );
		} else {
			// Full restore: delete all rows.
			// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DELETE FROM `{$full_table}`" );
		}

		$restored = 0;
		foreach ( $data['rows'] as $row ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			if ( false !== $wpdb->insert( $full_table, $row ) ) {
				++$restored;
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'COMMIT' );

		$label = 'full' === $type ? 'full table' : 'partial';

		return array(
			'success'       => true,
			'message'       => sprintf( 'Restored %s (%s) — %d rows.', $full_table, $label, $restored ),
			'table'         => $full_table,
			'rows_restored' => $restored,
		);
	}
}
