<?php
/**
 * Agent Settings — per-agent key/value store backed by wp_agent_builder_agent_settings.
 *
 * This is the single source of truth for all per-agent configuration that is not
 * baked into the agent's PHP file (system prompt, tools, etc.). Examples:
 *   - WhatsApp channel enable/mode/keyword/welcome
 *   - Future: Slack, Telegram, Email channels
 *
 * Usage:
 *   Agent_Settings::get( 'content-writer', 'whatsapp_enabled' );         // '' or '1'
 *   Agent_Settings::update( 'content-writer', 'whatsapp_enabled', '1' );
 *   Agent_Settings::get_all( 'content-writer' );                          // [ key => value, … ]
 *   Agent_Settings::delete_agent( 'content-writer' );                     // prune on uninstall
 *
 * @package    Agent_Builder
 * @subpackage Includes
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
 * Class Agent_Settings
 *
 * Static CRUD wrapper for the wp_agent_builder_agent_settings table.
 */
class Agent_Settings {

	/**
	 * In-memory cache keyed by agent slug.
	 *
	 * @var array<string,array<string,string>>
	 */
	private static array $cache = array();

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Get a single setting for an agent.
	 *
	 * @param string $slug     Agent slug.
	 * @param string $key      Setting key.
	 * @param string $fallback Returned when the key does not exist.
	 * @return string
	 */
	public static function get( string $slug, string $key, string $fallback = '' ): string {
		$all = self::get_all( $slug );
		return $all[ $key ] ?? $fallback;
	}

	/**
	 * Get all settings for an agent.
	 *
	 * @param string $slug Agent slug.
	 * @return array<string,string>
	 */
	public static function get_all( string $slug ): array {
		if ( isset( self::$cache[ $slug ] ) ) {
			return self::$cache[ $slug ];
		}

		global $wpdb;

		$result = array();

		if ( self::table_exists() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT meta_key, meta_value FROM {$wpdb->prefix}agent_builder_agent_settings WHERE agent_slug = %s",
					$slug
				),
				ARRAY_A
			);

			if ( is_array( $rows ) ) {
				foreach ( $rows as $row ) {
					$result[ $row['meta_key'] ] = $row['meta_value'];
				}
			}
		}

		self::$cache[ $slug ] = $result;
		return $result;
	}

	/**
	 * Get a specific setting for every agent at once.
	 *
	 * Useful for building the per-agent table in the admin UI without N+1 queries.
	 *
	 * @param string $key Setting key.
	 * @return array<string,string>  slug => value
	 */
	public static function get_all_agents_key( string $key ): array {
		global $wpdb;

		$result = array();

		if ( ! self::table_exists() ) {
			return $result;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT agent_slug, meta_value FROM {$wpdb->prefix}agent_builder_agent_settings WHERE meta_key = %s",
				$key
			),
			ARRAY_A
		);

		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$result[ $row['agent_slug'] ] = $row['meta_value'];
			}
		}
		return $result;
	}

	/**
	 * Insert or update a single setting.
	 *
	 * @param string $slug  Agent slug.
	 * @param string $key   Setting key.
	 * @param string $value Setting value.
	 * @return bool
	 */
	public static function update( string $slug, string $key, string $value ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->prefix}agent_builder_agent_settings (agent_slug, meta_key, meta_value)
				 VALUES (%s, %s, %s)
				 ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value), updated_at = NOW()",
				$slug,
				$key,
				$value
			)
		);

		// Bust cache for this slug.
		unset( self::$cache[ $slug ] );

		return false !== $result;
	}

	/**
	 * Save all settings for an agent in a single transaction-like batch.
	 *
	 * Keys not present in $settings are left untouched (use delete_agent() to wipe all).
	 *
	 * @param string               $slug     Agent slug.
	 * @param array<string,string> $settings Key => value map.
	 * @return bool
	 */
	public static function update_many( string $slug, array $settings ): bool {
		$success = true;
		foreach ( $settings as $key => $value ) {
			if ( ! self::update( $slug, (string) $key, (string) $value ) ) {
				$success = false;
			}
		}
		return $success;
	}

	/**
	 * Delete all settings for an agent (e.g. when agent is uninstalled).
	 *
	 * @param string $slug Agent slug.
	 * @return bool
	 */
	public static function delete_agent( string $slug ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->delete(
			"{$wpdb->prefix}agent_builder_agent_settings",
			array( 'agent_slug' => $slug ),
			array( '%s' )
		);
		unset( self::$cache[ $slug ] );
		return false !== $result;
	}

	/**
	 * Temporarily override a setting in the in-memory cache only (no DB write).
	 *
	 * Use for request-scoped overrides (e.g. Gutenberg block attributes) that
	 * must not persist to the database. The cache is populated from DB first so
	 * other keys for the slug are not lost.
	 *
	 * @param string $slug  Agent slug.
	 * @param string $key   Setting key.
	 * @param string $value Value.
	 */
	public static function put_cache( string $slug, string $key, string $value ): void {
		// Warm cache from DB if not yet loaded.
		if ( ! isset( self::$cache[ $slug ] ) ) {
			self::get_all( $slug );
		}
		self::$cache[ $slug ][ $key ] = $value;
	}

	/**
	 * Pre-load settings for multiple agents in a single DB query.
	 *
	 * Call this once after agents are loaded so that every subsequent
	 * Agent_Settings::get() is served from the in-memory cache with
	 * no additional database hits.
	 *
	 * @param string[] $slugs Agent slugs to load. Empty array loads the whole table.
	 */
	public static function preload_all( array $slugs = array() ): void {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return;
		}

		if ( ! empty( $slugs ) ) {
			$placeholders = implode( ', ', array_fill( 0, count( $slugs ), '%s' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders are %s tokens from array_fill; PHPCS can't see runtime count.
				$wpdb->prepare(
					"SELECT agent_slug, meta_key, meta_value FROM {$wpdb->prefix}agent_builder_agent_settings WHERE agent_slug IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders is only %s tokens; safe.
					...$slugs
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_results(
				"SELECT agent_slug, meta_key, meta_value FROM {$wpdb->prefix}agent_builder_agent_settings",
				ARRAY_A
			);
		}

		if ( ! is_array( $rows ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			$s = $row['agent_slug'];
			if ( ! isset( self::$cache[ $s ] ) ) {
				self::$cache[ $s ] = array();
			}
			self::$cache[ $s ][ $row['meta_key'] ] = $row['meta_value'];
		}

		// Mark slugs that had no rows so get_all() doesn't re-query them.
		foreach ( $slugs as $s ) {
			if ( ! isset( self::$cache[ $s ] ) ) {
				self::$cache[ $s ] = array();
			}
		}
	}

	/**
	 * Write default settings for an agent — only for keys not already stored.
	 *
	 * Call this during agent activation to ensure every key has a baseline
	 * value without overwriting admin customisations.
	 *
	 * @param string               $slug     Agent slug.
	 * @param array<string,string> $defaults Key => default-value map.
	 */
	public static function seed_defaults( string $slug, array $defaults ): void {
		$existing = self::get_all( $slug );
		foreach ( $defaults as $key => $value ) {
			if ( ! array_key_exists( (string) $key, $existing ) ) {
				self::update( $slug, (string) $key, (string) $value );
			}
		}
	}

	/**
	 * Bust the in-memory cache (useful after bulk updates in the same request).
	 *
	 * @param string|null $slug Bust only this slug, or all if null.
	 */
	public static function bust_cache( ?string $slug = null ): void {
		if ( null === $slug ) {
			self::$cache = array();
		} else {
			unset( self::$cache[ $slug ] );
		}
	}

	// -------------------------------------------------------------------------
	// Internal
	// -------------------------------------------------------------------------

	/**
	 * Whether the backing table exists on this connection.
	 *
	 * Guards against environments where the table was never created (e.g. a
	 * schema-cloning test sandbox) so reads degrade to empty instead of a
	 * DB error on every request.
	 *
	 * @return bool
	 */
	private static function table_exists(): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'agent_builder_agent_settings';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off existence check.
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}
}
