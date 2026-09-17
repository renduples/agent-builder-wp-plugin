<?php
/**
 * Tools Registry — database-backed tool management
 *
 * Maintains the wp_agent_builder_tools table as the single source of truth for
 * tool enabled/disabled state, categories, and metadata.
 *
 * @package    Agent_Builder
 * @subpackage Includes
 * @author     Agent Builder Team <support@agentic-plugin.com>
 * @license    GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 * @link       https://agentic-plugin.com
 * @since      1.9.5
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Database-backed registry for all agent tools.
 */
class Tools_Registry {

	/**
	 * Runtime cache of tools keyed by name.
	 *
	 * @var array<string, array>|null
	 */
	private static ?array $cache = null;

	/**
	 * Tools that ship disabled by default.
	 *
	 * These are write/destructive capabilities (DB writes, WP-CLI, cache
	 * flushes that can affect a whole server) that an administrator must
	 * explicitly enable on the Tools screen before an agent can use them or
	 * the WP Abilities bridge republishes them. Because register() only sets
	 * `enabled` on first insert, every code path that can be the FIRST to
	 * insert a tool row (Tool_Loader::sync_to_registry() and
	 * seed_core_tools()) must consult this list so the default sticks.
	 *
	 * @var string[]
	 */
	private const DEFAULT_DISABLED = array(
		'db_update_option',
		'db_create_post',
		'db_update_post',
		'db_delete_post',
		'run_wp_cli',
		'manage_cache',
	);

	/**
	 * Whether a tool ships disabled by default.
	 *
	 * @param string $name Tool name.
	 * @return bool
	 */
	public static function is_disabled_by_default( string $name ): bool {
		return in_array( $name, self::DEFAULT_DISABLED, true );
	}

	/**
	 * Bust the in-memory cache, forcing a fresh DB read on next access.
	 */
	public static function bust_cache(): void {
		self::$cache = null;
		wp_cache_delete( 'agentic_tools_all', 'agentic' );
	}

	/**
	 * Get all tools from the database.
	 *
	 * @return array<string, array> Tools keyed by name.
	 */
	public static function get_all(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		// Check the persistent object cache first (shared across processes on Redis/Memcached).
		$cached = wp_cache_get( 'agentic_tools_all', 'agentic' );
		if ( is_array( $cached ) ) {
			self::$cache = $cached;
			return self::$cache;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, runtime and object cached.
		$rows = $wpdb->get_results(
			"SELECT * FROM {$wpdb->prefix}agent_builder_tools ORDER BY category ASC, name ASC",
			ARRAY_A
		);

		self::$cache = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$row['enabled']              = (bool) $row['enabled'];
				$row['risk_level']           = $row['risk_level'] ?? 'none';
				$row['parameters']           = $row['parameters'] ? json_decode( $row['parameters'], true ) : array();
				self::$cache[ $row['name'] ] = $row;
			}
		}

		wp_cache_set( 'agentic_tools_all', self::$cache, 'agentic', HOUR_IN_SECONDS );

		return self::$cache;
	}

	/**
	 * Get a single tool by name.
	 *
	 * @param string $name Tool name.
	 * @return array|null Tool row or null.
	 */
	public static function get( string $name ): ?array {
		$all = self::get_all();
		return $all[ $name ] ?? null;
	}

	/**
	 * Check if a tool is enabled.
	 *
	 * Falls back to true for unknown tools (agent tools registered at runtime
	 * that haven't been synced yet).
	 *
	 * @param string $name Tool name.
	 * @return bool
	 */
	public static function is_enabled( string $name ): bool {
		$tool = self::get( $name );
		return $tool ? $tool['enabled'] : true;
	}

	/**
	 * Get names of all disabled tools.
	 *
	 * @return string[]
	 */
	public static function get_disabled_names(): array {
		$disabled = array();
		foreach ( self::get_all() as $name => $tool ) {
			if ( ! $tool['enabled'] ) {
				$disabled[] = $name;
			}
		}
		return $disabled;
	}

	/**
	 * Enable or disable a tool.
	 *
	 * @param string $name    Tool name.
	 * @param bool   $enabled Whether to enable.
	 * @return bool Success.
	 */
	public static function set_enabled( string $name, bool $enabled ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table update.
		$result = $wpdb->update(
			$wpdb->prefix . 'agent_builder_tools',
			array(
				'enabled'    => $enabled ? 1 : 0,
				'updated_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'name' => $name ),
			array( '%d', '%s' ),
			array( '%s' )
		);

		self::bust_cache();

		return false !== $result;
	}

	/**
	 * Bulk-enable tools whose risk is at or below a max level; disable the rest.
	 *
	 * Extreme tools are always disabled unless $max_level is extreme.
	 *
	 * @param string $max_level Max allowed risk (none|low|medium|high|extreme).
	 * @return array{enabled:int,disabled:int,max_level:string}
	 */
	public static function apply_max_risk_level( string $max_level ): array {
		global $wpdb;

		if ( ! Risk_Level::is_valid( $max_level ) ) {
			$max_level = Risk_Level::LOW;
		}

		$max_w    = Risk_Level::weight( $max_level );
		$now      = gmdate( 'Y-m-d H:i:s' );
		$enabled  = 0;
		$disabled = 0;
		$table    = $wpdb->prefix . 'agent_builder_tools';

		foreach ( self::get_all() as $name => $tool ) {
			$risk = (string) ( $tool['risk_level'] ?? Risk_Level::NONE );
			if ( ! Risk_Level::is_valid( $risk ) ) {
				$risk = Risk_Level::NONE;
			}
			$should = Risk_Level::weight( $risk ) <= $max_w;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk profile apply.
			$wpdb->update(
				$table,
				array(
					'enabled'    => $should ? 1 : 0,
					'updated_at' => $now,
				),
				array( 'name' => $name ),
				array( '%d', '%s' ),
				array( '%s' )
			);
			if ( $should ) {
				++$enabled;
			} else {
				++$disabled;
			}
		}

		self::bust_cache();

		return array(
			'enabled'   => $enabled,
			'disabled'  => $disabled,
			'max_level' => $max_level,
		);
	}

	/**
	 * Infer the highest risk level among currently enabled tools.
	 *
	 * @return string
	 */
	public static function enabled_max_risk_level(): string {
		$max = Risk_Level::NONE;
		foreach ( self::get_all() as $tool ) {
			if ( empty( $tool['enabled'] ) ) {
				continue;
			}
			$risk = (string) ( $tool['risk_level'] ?? Risk_Level::NONE );
			$max  = Risk_Level::max( $max, $risk );
		}
		return $max;
	}

	/**
	 * Register or update a tool in the database.
	 *
	 * Uses INSERT ... ON DUPLICATE KEY UPDATE to upsert.
	 *
	 * @param array $tool Tool definition with keys: name, description, category, source, enabled, risk_level, parameters.
	 * @return bool True on success, false on failure.
	 */
	public static function register( array $tool ): bool {
		global $wpdb;

		$name        = $tool['name'] ?? '';
		$description = $tool['description'] ?? '';
		$category    = $tool['category'] ?? 'WordPress';
		$source      = $tool['source'] ?? 'core';
		$enabled     = $tool['enabled'] ?? true;
		$risk_level  = $tool['risk_level'] ?? 'none';
		$parameters  = isset( $tool['parameters'] ) ? wp_json_encode( $tool['parameters'] ) : '[]';

		if ( empty( $name ) ) {
			return false;
		}

		$now   = gmdate( 'Y-m-d H:i:s' );
		$table = $wpdb->prefix . 'agent_builder_tools';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is $wpdb->prefix . 'agent_builder_tools', safe. // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$result = $wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$wpdb->prepare(
				"INSERT INTO {$table} (name, description, category, source, enabled, risk_level, parameters, created_at, updated_at)
				VALUES (%s, %s, %s, %s, %d, %s, %s, %s, %s)
				ON DUPLICATE KEY UPDATE description = VALUES(description), category = VALUES(category),
					source = VALUES(source), risk_level = VALUES(risk_level), parameters = VALUES(parameters), updated_at = VALUES(updated_at)",
				$name,
				$description,
				$category,
				$source,
				$enabled ? 1 : 0,
				$risk_level,
				$parameters,
				$now,
				$now
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		self::bust_cache();
		return false !== $result;
	}

	/**
	 * Remove a tool from the registry.
	 *
	 * @param string $name Tool name.
	 * @return bool Success.
	 */
	public static function unregister( string $name ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table delete.
		$result = $wpdb->delete(
			$wpdb->prefix . 'agent_builder_tools',
			array( 'name' => $name ),
			array( '%s' )
		);

		self::bust_cache();
		return false !== $result;
	}

	/**
	 * Get tools filtered by category.
	 *
	 * @param string $category Category slug.
	 * @return array<string, array>
	 */
	public static function get_by_category( string $category ): array {
		return array_filter(
			self::get_all(),
			fn( $tool ) => $tool['category'] === $category
		);
	}

	/**
	 * Get tools filtered by source.
	 *
	 * @param string $source 'core' or agent slug.
	 * @return array<string, array>
	 */
	public static function get_by_source( string $source ): array {
		return array_filter(
			self::get_all(),
			fn( $tool ) => $tool['source'] === $source
		);
	}

	/**
	 * Get the risk level for a tool.
	 *
	 * @param string $name Tool name.
	 * @return string Risk level (none, low, medium, high, extreme). Defaults to 'none'.
	 */
	public static function get_risk_level( string $name ): string {
		$tool = self::get( $name );
		return $tool['risk_level'] ?? 'none';
	}

	/**
	 * Count tools per category.
	 *
	 * @return array<string, int>
	 */
	public static function count_by_category(): array {
		$counts = array();
		foreach ( self::get_all() as $tool ) {
			$cat            = $tool['category'];
			$counts[ $cat ] = ( $counts[ $cat ] ?? 0 ) + 1;
		}
		return $counts;
	}

	/**
	 * Seed the core tools into the database.
	 *
	 * Reads definitions from Tool_Loader and inserts any that don't yet exist.
	 * Existing tools keep their enabled state.
	 *
	 * @param array<string, string> $category_map Tool name → category slug.
	 * @return int Number of tools seeded.
	 */
	public static function seed_core_tools( array $category_map = array() ): int {
		$loader      = Tool_Loader::get_instance();
		$definitions = $loader->get_all_definitions();
		$count       = 0;

		// Core tool names (agent-contributed tools excluded).
		$core_names = array(
			'db_update_option',
			'db_create_post',
			'db_update_post',
			'db_delete_post',
			'agents_available',
			'fetch_rendered_page',
			'manage_cache',
		);

		$loader = Tool_Loader::get_instance();

		foreach ( $definitions as $def ) {
			$name = $def['function']['name'] ?? '';
			if ( ! in_array( $name, $core_names, true ) ) {
				continue;
			}

			// Resolve category from tool instance, then map, then fallback.
			$tool_instance = $loader->get( $name );
			$category      = $tool_instance ? $tool_instance->get_category() : ( $category_map[ $name ] ?? 'database' );
			$risk_level    = $tool_instance ? $tool_instance->get_risk_level() : 'none';

			// Don't overwrite existing rows (preserves user's enabled state).
			if ( self::get( $name ) ) {
				// Update description and parameters in case they changed,
				// but preserve private underscore-prefixed keys (e.g. _allowed_commands).
				$existing_params = self::get( $name )['parameters'];
				if ( ! is_array( $existing_params ) ) {
					$existing_params = array();
				}
				$private_keys  = array_filter(
					$existing_params,
					fn( $k ) => str_starts_with( $k, '_' ),
					ARRAY_FILTER_USE_KEY
				);
				$merged_params = array_merge(
					$def['function']['parameters']['properties'] ?? array(),
					$private_keys
				);

				self::register(
					array(
						'name'        => $name,
						'description' => $def['function']['description'] ?? '',
						'category'    => $category,
						'source'      => 'core',
						'enabled'     => self::get( $name )['enabled'],
						'risk_level'  => $risk_level,
						'parameters'  => $merged_params,
					)
				);
				continue;
			}

			self::register(
				array(
					'name'        => $name,
					'description' => $def['function']['description'] ?? '',
					'category'    => $category,
					'source'      => 'core',
					'enabled'     => ! self::is_disabled_by_default( $name ),
					'risk_level'  => $risk_level,
					'parameters'  => $def['function']['parameters']['properties'] ?? array(),
				)
			);
			++$count;
		}

		return $count;
	}

	/**
	 * Sync agent-contributed tools into the registry.
	 *
	 * Called when agents are activated. Registers tools with source = agent slug.
	 *
	 * @param string $agent_slug Agent identifier.
	 * @param array  $tools      Tool definitions from the agent.
	 * @param array  $category_map Tool name → category mapping.
	 */
	public static function sync_agent_tools( string $agent_slug, array $tools, array $category_map = array() ): void {
		$loader = Tool_Loader::get_instance();

		foreach ( $tools as $tool ) {
			$name = $tool['function']['name'] ?? $tool['name'] ?? '';
			if ( empty( $name ) ) {
				continue;
			}

			// Resolve category: tool instance > explicit map > fallback.
			$category      = $category_map[ $name ] ?? null;
			$tool_instance = $loader->get( $name );
			if ( ! $category && $tool_instance ) {
				$category = $tool_instance->get_category();
			}

			$existing   = self::get( $name );
			$risk_level = $tool_instance ? $tool_instance->get_risk_level() : 'none';
			self::register(
				array(
					'name'        => $name,
					'description' => $tool['function']['description'] ?? $tool['description'] ?? '',
					'category'    => $category ?? 'wordpress',
					'source'      => $existing ? $existing['source'] : $agent_slug,
					'enabled'     => $existing ? $existing['enabled'] : true,
					'risk_level'  => $risk_level,
					'parameters'  => $tool['function']['parameters']['properties'] ?? array(),
				)
			);
		}
	}


	/**
	 * Clear the runtime cache (useful after bulk operations).
	 */
	public static function flush_cache(): void {
		self::bust_cache();
	}
}
