<?php
/**
 * Agent Library — the database store for every agent.
 *
 * One row per agent, whatever its origin: bundled agents seeded from the plugin
 * ZIP, agents purchased from the marketplace, and agents built by the Assistant
 * Trainer. Declarative agents (kind=manifest) are interpreted from their stored
 * manifest by Manifest_Agent — no code is ever written to disk or executed from
 * a writable location. Reviewed PHP agents (kind=php) keep running from their
 * bundled file; their row is a version/record anchor.
 *
 * Modelled on Skills_Registry: static CRUD over a custom table, a runtime plus
 * object-cache layer, and a slug as the stable identity.
 *
 * @package    Agent_Builder
 * @subpackage Includes
 * @author     Agent Builder Team <support@agentic-plugin.com>
 * @license    GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 * @link       https://agentic-plugin.com
 * @since      3.0.0
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Database-backed store for agent definitions.
 */
final class Agent_Library {

	/**
	 * Object-cache key for the full row set.
	 */
	private const CACHE_KEY = 'agentic_agent_library_all';

	/**
	 * Runtime cache (null = not yet loaded).
	 *
	 * @var array<int, array<string, mixed>>|null
	 */
	private static ?array $cache = null;

	// ── Cache ─────────────────────────────────────────────────────────────

	/**
	 * Clear both static and persistent caches.
	 *
	 * @return void
	 */
	public static function bust_cache(): void {
		self::$cache = null;
		wp_cache_delete( self::CACHE_KEY, 'agentic' );
	}

	/**
	 * Fully-qualified table name.
	 *
	 * @return string
	 */
	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'agent_builder_agent_library';
	}

	/**
	 * Whether the backing table exists on this connection.
	 *
	 * Guards against environments where the table was never created (e.g. a
	 * schema-cloning test sandbox) so reads degrade to empty instead of a
	 * DB error on every request.
	 *
	 * @param string $table Fully-qualified table name.
	 * @return bool
	 */
	private static function table_exists( string $table ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off existence check.
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	// ── Read ──────────────────────────────────────────────────────────────

	/**
	 * Get every agent row.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_all(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$cached = wp_cache_get( self::CACHE_KEY, 'agentic' );
		if ( is_array( $cached ) ) {
			self::$cache = $cached;
			return self::$cache;
		}

		global $wpdb;
		$table = self::table();

		if ( ! self::table_exists( $table ) ) {
			self::$cache = array();
			return self::$cache;
		}

		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table, manual cache.
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY name ASC", ARRAY_A );

		self::$cache = is_array( $rows ) ? $rows : array();

		wp_cache_set( self::CACHE_KEY, self::$cache, 'agentic', HOUR_IN_SECONDS );

		return self::$cache;
	}

	/**
	 * Get a single agent by numeric ID.
	 *
	 * @param int $id Row ID.
	 * @return array<string, mixed>|null
	 */
	public static function get( int $id ): ?array {
		global $wpdb;
		$table = self::table();

		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Get a single agent by slug.
	 *
	 * @param string $slug Agent slug.
	 * @return array<string, mixed>|null
	 */
	public static function get_by_slug( string $slug ): ?array {
		foreach ( self::get_all() as $agent ) {
			if ( $slug === $agent['slug'] ) {
				return $agent;
			}
		}
		return null;
	}

	/**
	 * Get every enabled agent row.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_enabled(): array {
		return array_values(
			array_filter(
				self::get_all(),
				static fn( array $a ): bool => '1' === (string) ( $a['enabled'] ?? '0' )
			)
		);
	}

	/**
	 * Count agents grouped by source (bundled / purchased / user).
	 *
	 * @return array<string, int>
	 */
	public static function count_by_source(): array {
		$counts = array();
		foreach ( self::get_all() as $agent ) {
			$src            = (string) ( $agent['source'] ?? 'user' );
			$counts[ $src ] = ( $counts[ $src ] ?? 0 ) + 1;
		}
		return $counts;
	}

	/**
	 * Decode a row's manifest column into an array.
	 *
	 * @param array<string, mixed> $row An agent row.
	 * @return array<string, mixed> Decoded manifest, or empty array on failure.
	 */
	public static function manifest_of( array $row ): array {
		$decoded = json_decode( (string) ( $row['manifest'] ?? '' ), true );
		return is_array( $decoded ) ? $decoded : array();
	}

	// ── Write ─────────────────────────────────────────────────────────────

	/**
	 * Insert an agent, or update the existing row with the same slug.
	 *
	 * Agents are slug-identified, so this is the natural write for both seeding
	 * and installing. The manifest may be passed as an array (it is encoded) or
	 * as a JSON string. The row hash is (re)computed from the canonical manifest
	 * unless an explicit hash is supplied.
	 *
	 * @param array<string, mixed> $data Agent fields. Requires slug, name, manifest.
	 * @return int|false Row ID on success, false on failure or invalid input.
	 */
	public static function upsert( array $data ) {
		$slug = sanitize_key( (string) ( $data['slug'] ?? '' ) );
		$name = sanitize_text_field( (string) ( $data['name'] ?? '' ) );

		if ( '' === $slug || '' === $name ) {
			return false;
		}

		$manifest_json = self::normalize_manifest( $data['manifest'] ?? array() );
		if ( '' === $manifest_json ) {
			return false;
		}

		$hash = isset( $data['hash'] ) && '' !== (string) $data['hash']
			? sanitize_text_field( (string) $data['hash'] )
			: hash( 'sha256', $manifest_json );

		$existing = self::get_by_slug( $slug );

		return $existing
			? self::write_update( (int) $existing['id'], $slug, $name, $manifest_json, $hash, $data )
			: self::write_insert( $slug, $name, $manifest_json, $hash, $data );
	}

	/**
	 * Delete an agent by ID.
	 *
	 * @param int $id Row ID.
	 * @return bool
	 */
	public static function delete( int $id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table delete.
		$result = $wpdb->delete( self::table(), array( 'id' => $id ), array( '%d' ) );

		self::bust_cache();

		return false !== $result && $result > 0;
	}

	/**
	 * Delete an agent by slug.
	 *
	 * @param string $slug Agent slug.
	 * @return bool
	 */
	public static function delete_by_slug( string $slug ): bool {
		$row = self::get_by_slug( $slug );
		return null !== $row && self::delete( (int) $row['id'] );
	}

	/**
	 * Enable or disable an agent by slug.
	 *
	 * @param string $slug    Agent slug.
	 * @param bool   $enabled Desired state.
	 * @return bool
	 */
	public static function set_enabled( string $slug, bool $enabled ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table update.
		$result = $wpdb->update(
			self::table(),
			array(
				'enabled'    => $enabled ? 1 : 0,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'slug' => $slug ),
			array( '%d', '%s' ),
			array( '%s' )
		);

		self::bust_cache();

		return false !== $result;
	}

	// ── Internal ──────────────────────────────────────────────────────────

	/**
	 * Encode a manifest to a canonical JSON string.
	 *
	 * @param mixed $manifest Array or JSON string.
	 * @return string JSON string, or '' when it cannot be encoded.
	 */
	private static function normalize_manifest( $manifest ): string {
		if ( is_string( $manifest ) ) {
			$decoded = json_decode( $manifest, true );
			return is_array( $decoded ) ? (string) wp_json_encode( $decoded ) : '';
		}
		if ( is_array( $manifest ) && ! empty( $manifest ) ) {
			return (string) wp_json_encode( $manifest );
		}
		return '';
	}

	/**
	 * Insert a fresh row.
	 *
	 * @param string               $slug          Sanitized slug.
	 * @param string               $name          Sanitized name.
	 * @param string               $manifest_json Encoded manifest.
	 * @param string               $hash          Manifest hash.
	 * @param array<string, mixed> $data          Original field bag.
	 * @return int|false
	 */
	private static function write_insert( string $slug, string $name, string $manifest_json, string $hash, array $data ) {
		global $wpdb;
		$now = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table insert.
		$result = $wpdb->insert(
			self::table(),
			array(
				'slug'        => $slug,
				'name'        => $name,
				'description' => sanitize_text_field( (string) ( $data['description'] ?? '' ) ),
				'manifest'    => $manifest_json,
				'kind'        => self::clean_kind( $data['kind'] ?? 'manifest' ),
				'path'        => sanitize_text_field( (string) ( $data['path'] ?? '' ) ),
				'source'      => sanitize_key( (string) ( $data['source'] ?? 'user' ) ),
				'origin'      => sanitize_key( (string) ( $data['origin'] ?? '' ) ),
				'source_id'   => sanitize_text_field( (string) ( $data['source_id'] ?? '' ) ),
				'version'     => sanitize_text_field( (string) ( $data['version'] ?? '1.0.0' ) ),
				'author'      => sanitize_text_field( (string) ( $data['author'] ?? '' ) ),
				'hash'        => $hash,
				'enabled'     => ! empty( $data['enabled'] ) || ! array_key_exists( 'enabled', $data ) ? 1 : 0,
				'created_at'  => $now,
				'updated_at'  => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		if ( false === $result ) {
			return false;
		}

		self::bust_cache();
		return (int) $wpdb->insert_id;
	}

	/**
	 * Update an existing row in place.
	 *
	 * @param int                  $id            Row ID.
	 * @param string               $slug          Sanitized slug.
	 * @param string               $name          Sanitized name.
	 * @param string               $manifest_json Encoded manifest.
	 * @param string               $hash          Manifest hash.
	 * @param array<string, mixed> $data          Original field bag.
	 * @return int|false Row ID on success, false on failure.
	 */
	private static function write_update( int $id, string $slug, string $name, string $manifest_json, string $hash, array $data ) {
		global $wpdb;

		$fields = array(
			'name'       => $name,
			'manifest'   => $manifest_json,
			'hash'       => $hash,
			'updated_at' => current_time( 'mysql', true ),
		);
		$format = array( '%s', '%s', '%s', '%s' );

		$optional = array(
			'description' => '%s',
			'kind'        => '%s',
			'path'        => '%s',
			'source'      => '%s',
			'origin'      => '%s',
			'source_id'   => '%s',
			'version'     => '%s',
			'author'      => '%s',
		);
		foreach ( $optional as $key => $fmt ) {
			if ( ! array_key_exists( $key, $data ) ) {
				continue;
			}
			if ( 'kind' === $key ) {
				$fields[ $key ] = self::clean_kind( $data[ $key ] );
			} elseif ( in_array( $key, array( 'source', 'origin' ), true ) ) {
				$fields[ $key ] = sanitize_key( (string) $data[ $key ] );
			} else {
				$fields[ $key ] = sanitize_text_field( (string) $data[ $key ] );
			}
			$format[] = $fmt;
		}

		if ( array_key_exists( 'enabled', $data ) ) {
			$fields['enabled'] = ! empty( $data['enabled'] ) ? 1 : 0;
			$format[]          = '%d';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table update.
		$result = $wpdb->update( self::table(), $fields, array( 'id' => $id ), $format, array( '%d' ) );

		self::bust_cache();

		return false === $result ? false : $id;
	}

	/**
	 * Constrain kind to the two allowed values.
	 *
	 * @param mixed $kind Raw kind.
	 * @return string 'manifest' or 'php'.
	 */
	private static function clean_kind( $kind ): string {
		return 'php' === $kind ? 'php' : 'manifest';
	}
}
