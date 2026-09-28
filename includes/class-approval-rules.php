<?php
/**
 * Approval Rules
 *
 * CRUD store for the M12 declarative risk-policy rules layer. This task is
 * deliberately scoped to storage only: rows in
 * `{prefix}agent_builder_approval_rules` can be listed, created, updated and
 * deleted so a later task can manage them through a UI. The `compiled` column
 * is left NULL, there is no `evaluate()` method, and nothing here hooks
 * `agent_builder_tool_enforcement` — the rules engine that would actually
 * consume these rows (and fill `compiled` via a reviewer LLM) is a dedicated
 * follow-up (see wp#265).
 *
 * @package    Agent_Builder
 * @subpackage Includes
 * @author     Agent Builder Team <support@agentic-plugin.com>
 * @license    GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 * @link       https://agentic-plugin.com
 * @since      4.2.0
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Approval rule rows and their validation.
 *
 * A rule names an agent (or `''` for every agent) and an `effect` — `ask`,
 * `allow`, or `deny` — described by a natural-language `rule_text`. Validation
 * lives here so both the REST layer and any future consumer share one check:
 * `effect` must be one of the three allowed values and `agent_slug` must either
 * be empty (all agents) or a slug actually registered in the agent registry.
 */
class Approval_Rules {

	/**
	 * Table name (unprefixed).
	 *
	 * @var string
	 */
	const TABLE = 'agent_builder_approval_rules';

	/**
	 * The only effects a rule may declare.
	 *
	 * @var string[]
	 */
	const EFFECTS = array( 'ask', 'allow', 'deny' );

	/**
	 * List approval rules, optionally filtered.
	 *
	 * @param array $args Optional: 'agent_slug' (string), 'enabled' (bool),
	 *                    'per_page' (int), 'page' (int, 1-based).
	 * @return array<int, array<string, mixed>> Rule rows (associative), priority then id.
	 */
	public static function list( array $args = array() ): array {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE;

		$where  = array();
		$values = array();

		if ( isset( $args['agent_slug'] ) && '' !== (string) $args['agent_slug'] ) {
			$where[]  = 'agent_slug = %s';
			$values[] = sanitize_key( (string) $args['agent_slug'] );
		}

		if ( isset( $args['enabled'] ) ) {
			$where[]  = 'enabled = %d';
			$values[] = (int) (bool) $args['enabled'];
		}

		$where_sql = $where ? ' WHERE ' . implode( ' AND ', $where ) : '';

		$per_page = isset( $args['per_page'] ) ? max( 1, min( 500, (int) $args['per_page'] ) ) : 200;
		$page     = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		$offset   = ( $page - 1 ) * $per_page;

		$query_args = array_merge( array( $table ), $values, array( $per_page, $offset ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table read.
		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $where_sql holds only fixed fragments; placeholders are filled by $query_args.
			$wpdb->prepare(
				"SELECT * FROM %i{$where_sql} ORDER BY priority ASC, id ASC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where_sql holds only fixed fragments; placeholders are filled by $query_args.
				...$query_args // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Fetch a single rule by id.
	 *
	 * @param int $id Rule id.
	 * @return array<string, mixed>|null Row (associative), or null when absent.
	 */
	public static function get( int $id ): ?array {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Single-row custom table read.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Validate the fields a caller wants to write.
	 *
	 * Checks only the fields present in `$data`: `effect` must be `ask`, `allow`,
	 * or `deny`, and `agent_slug` must be `''` (all agents) or a slug registered
	 * in the agent registry. Returns null when everything present is valid.
	 *
	 * @param array $data Fields to validate (any subset).
	 * @return \WP_Error|null Error to return, or null when valid.
	 */
	public static function validate( array $data ): ?\WP_Error {
		if ( array_key_exists( 'effect', $data ) ) {
			$effect = (string) $data['effect'];
			if ( ! in_array( $effect, self::EFFECTS, true ) ) {
				return new \WP_Error(
					'invalid_effect',
					/* translators: %s: the invalid effect value. */
					sprintf( __( 'Effect must be one of ask, allow, or deny; got "%s".', 'agent-builder' ), $effect ),
					array( 'status' => 400 )
				);
			}
		}

		if ( array_key_exists( 'agent_slug', $data ) ) {
			$slug = sanitize_key( (string) $data['agent_slug'] );
			if ( '' !== $slug && null === \Agentic_Agent_Registry::get_instance()->get_agent_instance( $slug ) ) {
				return new \WP_Error(
					'invalid_agent',
					/* translators: %s: the agent slug. */
					sprintf( __( 'Unknown agent "%s".', 'agent-builder' ), $slug ),
					array( 'status' => 400 )
				);
			}
		}

		return null;
	}

	/**
	 * Insert a new rule row.
	 *
	 * Applies defaults for `agent_slug` (''), `priority` (10) and `enabled`
	 * (true). `compiled` is intentionally left NULL — synthesising it is the
	 * rules engine's job in a later task, not this CRUD layer's.
	 *
	 * @param array $data Fields: agent_slug, rule_text, effect, priority, enabled,
	 *                    and optionally created_by.
	 * @return int New row id, or 0 when the insert failed.
	 */
	public static function create( array $data ): int {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE;

		$insert  = array(
			'agent_slug' => sanitize_key( (string) ( $data['agent_slug'] ?? '' ) ),
			'rule_text'  => trim( (string) ( $data['rule_text'] ?? '' ) ),
			'effect'     => (string) ( $data['effect'] ?? '' ),
			'priority'   => array_key_exists( 'priority', $data ) ? (int) $data['priority'] : 10,
			'enabled'    => array_key_exists( 'enabled', $data ) ? ( (bool) $data['enabled'] ? 1 : 0 ) : 1,
		);
		$formats = array( '%s', '%s', '%s', '%d', '%d' );

		if ( array_key_exists( 'created_by', $data ) && (int) $data['created_by'] > 0 ) {
			$insert['created_by'] = (int) $data['created_by'];
			$formats[]            = '%d';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table insert.
		$inserted = $wpdb->insert( $table, $insert, $formats );

		if ( false === $inserted ) {
			return 0;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update a rule's fields.
	 *
	 * Partial: only the fields present in `$data` change. `compiled` is never
	 * touched. Returns false when the row does not exist.
	 *
	 * @param int   $id   Rule id.
	 * @param array $data Fields to update: agent_slug, rule_text, effect, priority, enabled.
	 * @return bool Whether the row existed and the update was applied.
	 */
	public static function update( int $id, array $data ): bool {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE;

		if ( null === self::get( $id ) ) {
			return false;
		}

		$row     = array();
		$formats = array();

		if ( array_key_exists( 'agent_slug', $data ) ) {
			$row['agent_slug'] = sanitize_key( (string) $data['agent_slug'] );
			$formats[]         = '%s';
		}
		if ( array_key_exists( 'rule_text', $data ) ) {
			$row['rule_text'] = trim( (string) $data['rule_text'] );
			$formats[]        = '%s';
		}
		if ( array_key_exists( 'effect', $data ) ) {
			$row['effect'] = (string) $data['effect'];
			$formats[]     = '%s';
		}
		if ( array_key_exists( 'priority', $data ) ) {
			$row['priority'] = (int) $data['priority'];
			$formats[]       = '%d';
		}
		if ( array_key_exists( 'enabled', $data ) ) {
			$row['enabled'] = (bool) $data['enabled'] ? 1 : 0;
			$formats[]      = '%d';
		}

		if ( empty( $row ) ) {
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table update.
		$result = $wpdb->update( $table, $row, array( 'id' => $id ), $formats, array( '%d' ) );

		return false !== $result;
	}

	/**
	 * Delete a rule row.
	 *
	 * @param int $id Rule id.
	 * @return bool Whether the row existed and was deleted.
	 */
	public static function delete( int $id ): bool {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE;

		if ( null === self::get( $id ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table delete.
		return false !== $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
	}
}
