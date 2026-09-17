<?php
/**
 * Response Cache
 *
 * Caches LLM responses for identical messages to save tokens and reduce latency.
 * Uses WordPress transients for storage with configurable TTL.
 *
 * @package    Agent_Builder
 * @subpackage Includes
 * @author     Agent Builder Team <support@agentic-plugin.com>
 * @license    GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 * @link       https://agentic-plugin.com
 * @since      0.1.0
 *
 * php version 8.1
 */

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Response Cache Manager
 *
 * Provides exact-match caching for chat responses. When the same message
 * is sent to the same agent, returns cached response instead of calling LLM.
 */
class Response_Cache {

	/**
	 * Cache key prefix.
	 *
	 * @var string
	 */
	private const CACHE_PREFIX = 'agentic_resp_';

	/**
	 * Default TTL in seconds (1 hour).
	 *
	 * @var int
	 */
	private const DEFAULT_TTL = HOUR_IN_SECONDS;

	/**
	 * Maximum TTL (24 hours).
	 *
	 * @var int
	 */
	private const MAX_TTL = DAY_IN_SECONDS;

	/**
	 * Minimum message length to cache (skip very short messages).
	 *
	 * @var int
	 */
	private const MIN_MESSAGE_LENGTH = 10;

	/**
	 * Tools with side effects that prevent caching (writes, deletions, mutations).
	 *
	 * Read-only tools (read_file, list_directory, etc.) are safe to cache.
	 *
	 * @var array<string>
	 */
	private const WRITE_TOOLS = array(
		'db_update_option',
		'db_create_post',
		'db_update_post',
		'db_delete_post',
		'write_file',
		'modify_option',
		'manage_transients',
		'modify_postmeta',
		'request_code_change',
		'manage_schedules',
	);

	/**
	 * Phrases that indicate context-dependent queries (don't cache).
	 *
	 * @var array<string>
	 */
	private const CONTEXT_DEPENDENT_PHRASES = array(
		'this page',
		'this post',
		'this product',
		'my site',
		'my website',
		'current',
		'now',
		'today',
		'yesterday',
		'last week',
		'recent',
		'latest',
	);

	/**
	 * Check if caching is enabled (globally or per-agent override).
	 *
	 * @param string $agent_id Optional agent slug for per-agent override check.
	 * @return bool
	 */
	public static function is_enabled( string $agent_id = '' ): bool {
		$global = (bool) get_option( 'agent_builder_response_cache_enabled', true );

		if ( $agent_id ) {
			$ov_cache = Agent_Settings::get( $agent_id, 'override_cache' );
			if ( '' !== $ov_cache ) {
				return '1' === $ov_cache;
			}
		}

		return $global;
	}

	/**
	 * Get configured TTL.
	 *
	 * @return int TTL in seconds.
	 */
	public static function get_ttl(): int {
		$ttl = (int) get_option( 'agent_builder_response_cache_ttl', self::DEFAULT_TTL );
		return min( max( $ttl, 60 ), self::MAX_TTL ); // Between 1 min and 24 hours.
	}

	/**
	 * Generate cache key for a message.
	 *
	 * Key components:
	 * - Message content (normalized)
	 * - Agent ID (different agents = different responses)
	 * - User role (admin might get different response than subscriber)
	 *
	 * @param string $message  The user message.
	 * @param string $agent_id Agent identifier.
	 * @param int    $user_id  User ID (for role detection).
	 * @return string Cache key.
	 */
	public static function generate_key( string $message, string $agent_id, int $user_id = 0 ): string {
		// Normalize message: lowercase, trim, collapse whitespace.
		$normalized = strtolower( trim( preg_replace( '/\s+/', ' ', $message ) ) );

		// Get user role bucket (not exact role, just privilege level).
		$role_bucket = self::get_role_bucket( $user_id );

		// Build cache key components.
		$key_data = implode(
			'|',
			array(
				$normalized,
				$agent_id,
				$role_bucket,
			)
		);

		// Hash it (MD5 is fine for cache keys, not security).
		return self::CACHE_PREFIX . md5( $key_data );
	}

	/**
	 * Get role bucket for caching.
	 *
	 * Groups roles into buckets to increase cache hit rate while
	 * still respecting privilege differences.
	 *
	 * @param int $user_id User ID.
	 * @return string Role bucket (admin, editor, user, guest).
	 */
	private static function get_role_bucket( int $user_id ): string {
		if ( 0 === $user_id ) {
			return 'guest';
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return 'guest';
		}

		// Admin bucket: administrators, super admins.
		if ( user_can( $user, 'manage_options' ) ) {
			return 'admin';
		}

		// Editor bucket: editors, shop managers, etc.
		if ( user_can( $user, 'edit_others_posts' ) ) {
			return 'editor';
		}

		// User bucket: subscribers, customers, contributors, authors.
		return 'user';
	}

	/**
	 * Check if a message should be cached.
	 *
	 * Some messages shouldn't be cached because:
	 * - Too short (likely follow-up)
	 * - Contains context-dependent phrases
	 * - Has conversation history (context matters)
	 *
	 * @param string $message  The user message.
	 * @param array  $history  Conversation history.
	 * @param string $agent_id Optional agent slug for per-agent override check.
	 * @return bool Whether to use cache.
	 */
	public static function should_cache( string $message, array $history = array(), string $agent_id = '' ): bool {
		// Caching disabled globally or for this agent.
		if ( ! self::is_enabled( $agent_id ) ) {
			return false;
		}

		// Too short - likely a follow-up or clarification.
		if ( strlen( $message ) < self::MIN_MESSAGE_LENGTH ) {
			return false;
		}

		// Has conversation history - context matters.
		if ( ! empty( $history ) ) {
			return false;
		}

		// Check for context-dependent phrases.
		$message_lower = strtolower( $message );
		foreach ( self::CONTEXT_DEPENDENT_PHRASES as $phrase ) {
			if ( strpos( $message_lower, $phrase ) !== false ) {
				return false;
			}
		}

		/**
		 * Filter whether a specific message should be cached.
		 *
		 * @param bool   $should_cache Whether to cache.
		 * @param string $message      The user message.
		 * @param array  $history      Conversation history.
		 */
		return apply_filters( 'agentic_should_cache_response', true, $message, $history );
	}

	/**
	 * Get cached response.
	 *
	 * @param string $message  The user message.
	 * @param string $agent_id Agent identifier.
	 * @param int    $user_id  User ID.
	 * @return array|null Cached response or null if not found.
	 */
	public static function get( string $message, string $agent_id, int $user_id = 0 ): ?array {
		$key    = self::generate_key( $message, $agent_id, $user_id );
		$cached = get_transient( $key );

		if ( false === $cached ) {
			return null;
		}

		// Validate cached data structure.
		if ( ! is_array( $cached ) || empty( $cached['response'] ) ) {
			delete_transient( $key );
			return null;
		}

		// Mark as cached in response.
		$cached['cached']    = true;
		$cached['cache_hit'] = true;

		/**
		 * Fires when a cache hit occurs.
		 *
		 * @param string $message  The user message.
		 * @param string $agent_id Agent identifier.
		 * @param array  $cached   Cached response.
		 */
		do_action( 'agentic_cache_hit', $message, $agent_id, $cached );

		return $cached;
	}

	/**
	 * Store response in cache.
	 *
	 * @param string $message  The user message.
	 * @param string $agent_id Agent identifier.
	 * @param array  $response Response data to cache.
	 * @param int    $user_id  User ID.
	 * @return bool Whether caching succeeded.
	 */
	public static function set( string $message, string $agent_id, array $response, int $user_id = 0 ): bool {
		// Don't cache error responses.
		if ( ! empty( $response['error'] ) ) {
			return false;
		}

		// Don't cache empty responses.
		if ( empty( $response['response'] ) ) {
			return false;
		}

		// Don't cache if write/side-effect tools were called (mutations differ between requests).
		// Read-only tools (read_file, agents_available, etc.) are safe to cache.
		if ( ! empty( $response['tools_used'] ) ) {
			$has_writes = array_intersect( $response['tools_used'], self::WRITE_TOOLS );
			if ( ! empty( $has_writes ) ) {
				return false;
			}
		}

		$key = self::generate_key( $message, $agent_id, $user_id );
		$ttl = self::get_ttl();

		// Store with timestamp.
		$cache_data              = $response;
		$cache_data['cached_at'] = time();
		unset( $cache_data['cached'], $cache_data['cache_hit'] ); // Clean up.

		$result = set_transient( $key, $cache_data, $ttl );

		if ( $result ) {
			/**
			 * Fires when a response is cached.
			 *
			 * @param string $message  The user message.
			 * @param string $agent_id Agent identifier.
			 * @param int    $ttl      Cache TTL in seconds.
			 */
			do_action( 'agentic_response_cached', $message, $agent_id, $ttl );
		}

		return $result;
	}

	/**
	 * Invalidate cache for a specific message.
	 *
	 * @param string $message  The user message.
	 * @param string $agent_id Agent identifier.
	 * @param int    $user_id  User ID.
	 * @return bool Whether deletion succeeded.
	 */
	public static function invalidate( string $message, string $agent_id, int $user_id = 0 ): bool {
		$key = self::generate_key( $message, $agent_id, $user_id );
		return delete_transient( $key );
	}

	/**
	 * Clear all cached responses.
	 *
	 * Uses direct database query since transients don't support prefix deletion.
	 *
	 * @return int Number of cache entries cleared.
	 */
	public static function clear_all(): int {
		global $wpdb;

		// For database transients.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Bulk cleanup operation.
		$count = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				'_transient_' . self::CACHE_PREFIX . '%',
				'_transient_timeout_' . self::CACHE_PREFIX . '%'
			)
		);

		/**
		 * Fires when cache is cleared.
		 *
		 * @param int $count Number of entries cleared.
		 */
		do_action( 'agentic_cache_cleared', $count );

		// Also clear object cache if available.
		if ( function_exists( 'wp_cache_flush_group' ) ) {
			wp_cache_flush_group( 'agentic_responses' );
		}

		return (int) ( $count / 2 ); // Divide by 2 (transient + timeout).
	}

	/**
	 * Get cache statistics.
	 *
	 * @return array{enabled: bool, ttl: int, entry_count: int}
	 */
	public static function get_stats(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Stats query.
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
				'_transient_' . self::CACHE_PREFIX . '%'
			)
		);

		return array(
			'enabled'     => self::is_enabled(),
			'ttl'         => self::get_ttl(),
			'entry_count' => $count,
		);
	}
}
