<?php
/**
 * Approval Rules
 *
 * Storage and Phase A evaluation engine for the M12 declarative risk-policy
 * rules layer. Rows in `{prefix}agent_builder_approval_rules` can be listed,
 * created, updated and deleted (wp#265), and `evaluate()` hooks
 * `agent_builder_tool_enforcement` to turn matching `ask`/`deny` rules into a
 * stricter enforcement decision. The engine is deliberately fail-closed and
 * tightening-only in this phase: `classify()` is a stub that always returns
 * `'unsure'`, so an `allow`-effect rule never fires and the `compiled` column
 * stays NULL. The reviewer LLM that would replace `classify()` and fill
 * `compiled` is a dedicated follow-up (see wp#268, designs/M12-rules-engine.md).
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
	 * Restrictiveness rank of each enforcement decision, least restrictive first.
	 *
	 * Mirrors the private ordering Risk_Level::clamp_enforcement() uses. evaluate()
	 * relies on it to guarantee a matching rule can only ever tighten — never
	 * loosen — the decision already in flight.
	 *
	 * @var array<string, int>
	 */
	private const ENFORCEMENT_RANK = array(
		'allow'   => 0,
		'confirm' => 1,
		'queue'   => 2,
		'block'   => 3,
	);

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
	 * Checks only the fields present in `$data`: `rule_text` must not be empty,
	 * `effect` must be `ask`, `allow`, or `deny`, and `agent_slug` must be `''`
	 * (all agents) or a slug registered in the agent registry. Returns null when
	 * everything present is valid.
	 *
	 * @param array $data Fields to validate (any subset).
	 * @return \WP_Error|null Error to return, or null when valid.
	 */
	public static function validate( array $data ): ?\WP_Error {
		if ( array_key_exists( 'rule_text', $data ) && '' === trim( (string) $data['rule_text'] ) ) {
			return new \WP_Error(
				'missing_rule_text',
				__( 'A rule description is required.', 'agent-builder' ),
				array( 'status' => 400 )
			);
		}

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

	/**
	 * Register the evaluation engine on the tool-enforcement filter.
	 *
	 * Wired from agent-builder.php's bootstrap alongside the other M12 init
	 * calls. `evaluate()` runs at the filter's default priority 10 — the same
	 * seam the `agent_builder_tool_enforcement` docblock reserves for the rules
	 * layer. It only ever tightens; Risk_Level::clamp_enforcement() remains the
	 * final ceiling downstream, unchanged.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'agent_builder_tool_enforcement', array( __CLASS__, 'evaluate' ), 10, 2 );
	}

	/**
	 * Classify whether a rule's text matches a tool call.
	 *
	 * Phase A stub: always returns 'unsure'. This is the seam Phase B replaces
	 * with a real reviewer-LLM call returning 'match', 'no_match', or 'unsure'.
	 * The stub never returns 'match', so in this phase an 'allow'-effect rule
	 * can never loosen anything (see evaluate()).
	 *
	 * @param string $rule_text Natural-language rule text.
	 * @param array  $ctx       Gate context — see Tool_Executor::execute().
	 * @return string One of 'match', 'no_match', 'unsure' (always 'unsure' here).
	 */
	public static function classify( string $rule_text, array $ctx ): string {
		// Phase A: no real classification yet — the parameters are intentionally
		// unused until Phase B replaces this stub with a reviewer-LLM call that
		// reads both. Always 'unsure' keeps the engine fail-closed and
		// tightening-only until that lands.
		unset( $rule_text, $ctx );

		return 'unsure';
	}

	/**
	 * Load every enabled rule for the given agent plus the global scope.
	 *
	 * Unlike list(), this does not paginate (so an agent with more than 200
	 * rules is never silently truncated) and returns null on a query failure so
	 * the caller can distinguish "no rules" from "could not read rules" and fail
	 * closed. A DB error must never read as "no rules".
	 *
	 * @param string $agent_id Agent slug (already sanitized), or '' for global-only.
	 * @return array<int, array<string, mixed>>|null Rows, or null on query failure.
	 */
	private static function load_enabled_rules( string $agent_id ): ?array {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table read.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE enabled = %d AND ( agent_slug = %s OR agent_slug = %s ) ORDER BY priority ASC, id ASC",
				$table,
				1,
				'',
				$agent_id
			),
			ARRAY_A
		);

		// get_results() collapses a failed query and an empty result set to the
		// same empty array, so a query failure is only distinguishable by a
		// non-empty last_error. Treat a failure as "could not read rules" (null)
		// so the caller fails closed rather than reading the failure as "no rules".
		if ( '' !== $wpdb->last_error ) {
			return null;
		}

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Evaluate enabled approval rules against a tool call and tighten enforcement.
	 *
	 * Filter callback on `agent_builder_tool_enforcement` (priority 10, 2 args).
	 * Loads every enabled rule whose `agent_slug` is empty (all agents) or equal
	 * to `$ctx['agent_id']`, in `priority ASC, id ASC` order, classifies each,
	 * and folds the strongest matching effect into the decision:
	 *
	 *   - `deny` beats `ask`; `ask` beats nothing; `allow` never fires here.
	 *   - `ask` maps to 'confirm'; `deny` maps to 'queue'.
	 *
	 * Fail-closed: an 'unsure' classification counts as a match for `ask`/`deny`
	 * rules (it can only tighten) and is ignored for `allow` rules (an uncertain
	 * allow must never loosen anything). In Phase A `classify()` always returns
	 * 'unsure', so ask/deny rules always tighten and allow rules never fire. A
	 * rule-read query failure also fails closed (tightening to 'confirm') rather
	 * than reading as "no rules".
	 *
	 * The return is always at least as restrictive as `$enforcement`; a rule
	 * whose mapped decision would be looser is dropped rather than applied, so
	 * the engine can never weaken a decision an earlier callback or the baseline
	 * already made. Risk_Level::clamp_enforcement() remains the final ceiling.
	 *
	 * @param string $enforcement Current enforcement ('allow'|'confirm'|'queue'|'block').
	 * @param array  $ctx         Gate context — see Tool_Executor::execute().
	 * @return string The (possibly tightened) enforcement decision.
	 */
	public static function evaluate( string $enforcement, array $ctx ): string {
		$agent_id = sanitize_key( (string) ( $ctx['agent_id'] ?? '' ) );
		$tool     = (string) ( $ctx['tool'] ?? '' );

		$winner      = '';
		$winner_rule = null;

		$rules = self::load_enabled_rules( $agent_id );
		if ( null === $rules ) {
			// Fail closed: a DB error must never read as "no rules". Tighten to
			// 'confirm' rather than letting an otherwise-un-gated call through.
			$audit = new Audit_Log();
			$audit->log( $agent_id, 'rule_eval_db_error', $tool, array( 'risk_level' => (string) ( $ctx['risk'] ?? '' ) ) );

			return self::tighter( $enforcement, 'confirm' );
		}

		foreach ( $rules as $rule ) {
			$effect  = (string) ( $rule['effect'] ?? '' );
			$verdict = self::classify( (string) ( $rule['rule_text'] ?? '' ), $ctx );

			// Fail-closed: a non-matching rule is skipped, and an 'unsure'
			// verdict counts as a match only when it tightens (ask/deny). An
			// 'unsure' allow rule is ignored so it can never loosen anything.
			if ( 'no_match' === $verdict ) {
				continue;
			}
			if ( 'unsure' === $verdict && 'allow' === $effect ) {
				continue;
			}

			if ( 'deny' === $effect ) {
				$winner      = 'deny';
				$winner_rule = $rule;
				break; // deny beats every other effect — stop scanning.
			}

			if ( 'ask' === $effect && '' === $winner ) {
				$winner      = 'ask';
				$winner_rule = $rule;
			}

			// 'allow' is only reachable on an explicit 'match' (Phase B); in
			// Phase A allow rules are always suppressed above, so nothing here
			// ever loosens a decision.
		}

		if ( '' === $winner ) {
			return $enforcement;
		}

		$target = 'deny' === $winner ? 'queue' : 'confirm';

		// Audit on a match, same shape as Tool_Grants::log_grant().
		$audit = new Audit_Log();
		$audit->log(
			$agent_id,
			'rule_matched',
			$tool,
			array(
				'risk_level' => (string) ( $ctx['risk'] ?? '' ),
				'rule_id'    => (int) ( $winner_rule['id'] ?? 0 ),
				'effect'     => $winner,
			)
		);

		// Tightening-only: never return something less restrictive than the
		// decision already in flight.
		return self::tighter( $enforcement, $target );
	}

	/**
	 * Return the more restrictive of two enforcement decisions.
	 *
	 * @param string $a Enforcement decision.
	 * @param string $b Enforcement decision.
	 * @return string The more restrictive (higher-ranked) of the two.
	 */
	private static function tighter( string $a, string $b ): string {
		$rank_a = self::ENFORCEMENT_RANK[ $a ] ?? 0;
		$rank_b = self::ENFORCEMENT_RANK[ $b ] ?? 0;

		return $rank_a >= $rank_b ? $a : $b;
	}
}
