<?php
/**
 * Deployments data-access layer.
 *
 * Single table (wp_agent_builder_deployments) replaces the eight separate WP options
 * that previously tracked where agents are deployed.
 *
 * @package Agentic
 */

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deployments data-access layer.
 *
 * Manages storage and retrieval of agent deployment records in the
 * custom wp_agent_builder_deployments table.
 *
 * @package Agent_Builder
 */
class Deployments {

	/** Deployment type constants. */
	const TYPE_SHORTCODE      = 'shortcode';
	const TYPE_MODAL          = 'modal';
	const TYPE_ADMIN_BAR      = 'admin_bar';
	const TYPE_GUTENBERG      = 'gutenberg';
	const TYPE_ADMIN_UI       = 'admin_ui';
	const TYPE_EVENT_LISTENER = 'event_listener';
	const TYPE_SCHEDULED_TASK = 'scheduled_task';
	const TYPE_CLI            = 'cli';

	/** Source constants. */
	const SOURCE_ADMIN = 'admin';
	const SOURCE_CODE  = 'code';
	const SOURCE_AUTO  = 'auto';

	// -------------------------------------------------------------------------
	// Table management
	// -------------------------------------------------------------------------

	/**
	 * Get the fully qualified deployments table name.
	 *
	 * @return string
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'agent_builder_deployments';
	}

	/**
	 * Create the deployments table if it does not exist.
	 *
	 * @return void
	 */
	public static function create_table(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$table           = self::table();

		$sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id          bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            type        varchar(50)         NOT NULL,
            agent_slug  varchar(100)        NOT NULL DEFAULT '',
            label       varchar(255)        NOT NULL DEFAULT '',
            enabled     tinyint(1)          NOT NULL DEFAULT 1,
            source      varchar(20)         NOT NULL DEFAULT 'admin',
            page_id     bigint(20) unsigned          DEFAULT NULL,
            config      longtext,
            created_at  datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at  datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_type       (type),
            KEY idx_agent_slug (agent_slug),
            KEY idx_enabled    (enabled, type)
        ) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	// -------------------------------------------------------------------------
	// Read
	// -------------------------------------------------------------------------

	/**
	 * Fetch a single deployment by ID.
	 *
	 * @param int $id Deployment ID.
	 * @return array|null
	 */
	public static function get( int $id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), $id ),
			ARRAY_A
		);

		return $row ? self::decode( $row ) : null;
	}

	/**
	 * Fetch deployments, optionally filtered.
	 *
	 * @param string $type        Filter by type (empty = all types).
	 * @param string $agent_slug  Filter by agent slug (empty = all agents).
	 * @param bool   $enabled_only  When true, only return enabled rows.
	 * @return array[]
	 */
	public static function all( string $type = '', string $agent_slug = '', bool $enabled_only = false ): array {
		global $wpdb;

		$where  = array( '1=1' );
		$params = array();

		if ( '' !== $type ) {
			$where[]  = 'type = %s';
			$params[] = $type;
		}

		if ( '' !== $agent_slug ) {
			$where[]  = 'agent_slug = %s';
			$params[] = $agent_slug;
		}

		if ( $enabled_only ) {
			$where[] = 'enabled = 1';
		}

		$sql = 'SELECT * FROM %i WHERE ' . implode( ' AND ', $where ) . ' ORDER BY type, agent_slug, id';
		array_unshift( $params, self::table() );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A );

		return array_map( array( self::class, 'decode' ), $rows ? $rows : array() );
	}

	// -------------------------------------------------------------------------
	// Write
	// -------------------------------------------------------------------------

	/**
	 * Insert or update a deployment row.
	 *
	 * Pass `id` in $data to update an existing row; omit to insert.
	 * Returns the row ID (new or existing).
	 *
	 * @param array $data Row data.
	 * @return int
	 */
	public static function save( array $data ): int {
		global $wpdb;

		$id = isset( $data['id'] ) ? (int) $data['id'] : 0;
		unset( $data['id'] );

		$is_new = $id < 1;

		if ( ! $is_new ) {
			// Callers commonly pass a partial field set (e.g. just label/config)
			// intending a partial update. Merge onto the existing row first so
			// prepare_row() doesn't reconstruct — and blank out — omitted
			// columns like type or created_at.
			$existing = self::get( $id );
			if ( $existing ) {
				$data = array_merge( $existing, $data );
			}
		}

		$row = self::prepare_row( $data );

		if ( ! $is_new ) {
			$row['updated_at'] = current_time( 'mysql' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update( self::table(), $row, array( 'id' => $id ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert( self::table(), $row );
			$id = (int) $wpdb->insert_id;
		}

		if ( $id > 0 && class_exists( Audit_Log::class ) ) {
			Audit_Log::log_admin(
				$is_new ? 'deployment_created' : 'deployment_updated',
				'deployment',
				array(
					'id'         => $id,
					'type'       => $row['type'] ?? '',
					'agent_slug' => $row['agent_slug'] ?? '',
					'label'      => $row['label'] ?? '',
					'enabled'    => $row['enabled'] ?? null,
					'source'     => $row['source'] ?? '',
				)
			);
		}

		return $id;
	}

	/**
	 * Enable a deployment by ID.
	 *
	 * @param int $id Deployment ID.
	 * @return void
	 */
	public static function enable( int $id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			self::table(),
			array(
				'enabled'    => 1,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $id )
		);
		if ( class_exists( Audit_Log::class ) ) {
			Audit_Log::log_admin( 'deployment_enabled', 'deployment', array( 'id' => $id ) );
		}
	}

	/**
	 * Disable a deployment by ID.
	 *
	 * @param int $id Deployment ID.
	 * @return void
	 */
	public static function disable( int $id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			self::table(),
			array(
				'enabled'    => 0,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $id )
		);
		if ( class_exists( Audit_Log::class ) ) {
			Audit_Log::log_admin( 'deployment_disabled', 'deployment', array( 'id' => $id ) );
		}
	}

	/**
	 * Delete a deployment by ID.
	 *
	 * @param int $id Deployment ID.
	 * @return void
	 */
	public static function delete( int $id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( self::table(), array( 'id' => $id ) );
		if ( class_exists( Audit_Log::class ) ) {
			Audit_Log::log_admin( 'deployment_deleted', 'deployment', array( 'id' => $id ) );
		}
	}

	/**
	 * Idempotent auto-registration — called by runtime consumers (shortcode
	 * renderer, etc.) when they detect a deployment not yet in the table.
	 *
	 * Uniqueness key: type + agent_slug + source=auto/code. Does nothing if a
	 * matching row already exists (any source). Returns the row ID.
	 *
	 * @param string   $type       Deployment type.
	 * @param string   $agent_slug Agent slug.
	 * @param array    $config     Config.
	 * @param string   $source     Source.
	 * @param int|null $page_id    Page ID.
	 * @return int
	 */
	public static function auto_register( string $type, string $agent_slug, array $config = array(), string $source = self::SOURCE_AUTO, ?int $page_id = null ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE type = %s AND agent_slug = %s LIMIT 1',
				self::table(),
				$type,
				$agent_slug
			)
		);

		if ( $existing ) {
			return (int) $existing;
		}

		return self::save(
			array(
				'type'       => $type,
				'agent_slug' => $agent_slug,
				'label'      => self::auto_label( $type, $agent_slug ),
				'enabled'    => 1,
				'source'     => $source,
				'page_id'    => $page_id,
				'config'     => $config,
			)
		);
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Decode a DB row (JSON config, type casts).
	 *
	 * @param array $row Raw row.
	 * @return array
	 */
	private static function decode( array $row ): array {
		$decoded_config = json_decode( $row['config'] ?? '{}', true );
		$row['config']  = $decoded_config ? $decoded_config : array();
		$row['enabled'] = (bool) $row['enabled'];
		$row['id']      = (int) $row['id'];
		$row['page_id'] = null !== $row['page_id'] ? (int) $row['page_id'] : null;
		return $row;
	}

	/**
	 * Prepare and sanitize a row for DB insert/update.
	 *
	 * @param array $data Input data.
	 * @return array
	 */
	private static function prepare_row( array $data ): array {
		$config = $data['config'] ?? array();

		return array(
			'type'       => sanitize_key( $data['type'] ?? '' ),
			'agent_slug' => sanitize_key( $data['agent_slug'] ?? '' ),
			'label'      => sanitize_text_field( $data['label'] ?? '' ),
			'enabled'    => isset( $data['enabled'] ) ? (int) (bool) $data['enabled'] : 1,
			'source'     => sanitize_key( $data['source'] ?? self::SOURCE_ADMIN ),
			'page_id'    => isset( $data['page_id'] ) ? (int) $data['page_id'] : null,
			'config'     => wp_json_encode( is_array( $config ) ? $config : array() ),
			'created_at' => $data['created_at'] ?? current_time( 'mysql' ),
		);
	}

	/**
	 * Generate a human-friendly label for a deployment.
	 *
	 * @param string $type       Type.
	 * @param string $agent_slug Slug.
	 * @return string
	 */
	private static function auto_label( string $type, string $agent_slug ): string {
		$type_label  = ucwords( str_replace( '_', ' ', $type ) );
		$agent_label = ucwords( str_replace( '-', ' ', $agent_slug ) );
		return '' !== $agent_slug ? "{$agent_label} ({$type_label})" : $type_label;
	}

	/**
	 * Build the `[agentic_chat ...]` shortcode string for a TYPE_SHORTCODE
	 * deployment row — shared by the classic Deployment → Shortcodes tab
	 * and the manage_agent_shortcode tool, so both hand back the exact same
	 * embed code for the same row.
	 *
	 * @param array $row Deployment row (as returned by get()/all()).
	 * @return string
	 */
	public static function build_shortcode_string( array $row ): string {
		$cfg   = $row['config'] ?? array();
		$style = $cfg['style'] ?? 'inline';
		$parts = array( 'agent="' . esc_attr( $row['agent_slug'] ) . '"' );
		if ( 'inline' !== $style ) {
			$parts[] = 'style="' . esc_attr( $style ) . '"';
		}
		if ( ! empty( $cfg['height'] ) && '500px' !== $cfg['height'] ) {
			$parts[] = 'height="' . esc_attr( $cfg['height'] ) . '"';
		}
		if ( ! empty( $cfg['placeholder'] ) ) {
			$parts[] = 'placeholder="' . esc_attr( $cfg['placeholder'] ) . '"';
		}
		if ( isset( $cfg['show_header'] ) && ! $cfg['show_header'] ) {
			$parts[] = 'show_header="false"';
		}
		return '[agentic_chat ' . implode( ' ', $parts ) . ']';
	}
}
