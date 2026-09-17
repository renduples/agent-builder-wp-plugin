<?php
/**
 * Audit Log
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

declare(strict_types=1);

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Audit logging for agent actions
 */
class Audit_Log {

	/**
	 * Current mode context for this request lifecycle.
	 *
	 * @var string
	 */
	private static string $mode_context = '';

	/**
	 * Set the mode context for all subsequent log calls in this request.
	 *
	 * @param string $mode One of 'disabled', 'supervised', 'autonomous'.
	 */
	public static function set_mode_context( string $mode ): void {
		self::$mode_context = $mode;
	}

	/**
	 * Get the current mode context.
	 *
	 * @return string Current mode or empty string.
	 */
	public static function get_mode_context(): string {
		return self::$mode_context;
	}

	/**
	 * Log an action
	 *
	 * @param string $agent_id    Agent identifier.
	 * @param string $action      Action type.
	 * @param string $target_type Target type.
	 * @param mixed  $details     Action details.
	 * @param string $reasoning   Reasoning for action.
	 * @param int    $tokens      Tokens used.
	 * @param float  $cost        Estimated cost.
	 * @param string $provider    LLM provider slug (e.g. 'xai', 'openai').
	 * @return int|false Log entry ID or false.
	 */
	public function log(
		string $agent_id,
		string $action,
		string $target_type = '',
		mixed $details = null,
		string $reasoning = '',
		int $tokens = 0,
		float $cost = 0.0,
		string $provider = ''
	): int|false {
		global $wpdb;

		$identity = self::resolve_agent_identity( $agent_id );

		$data = array(
			'agent_id'      => $agent_id,
			'action'        => $action,
			'target_type'   => $target_type,
			'target_id'     => is_array( $details ) && isset( $details['id'] ) ? (string) $details['id'] : '',
			'details'       => wp_json_encode( $details ),
			'reasoning'     => $reasoning,
			'mode'          => self::$mode_context,
			'provider'      => $provider,
			'tokens_used'   => $tokens,
			'cost'          => $cost,
			'user_id'       => get_current_user_id(),
			'created_at'    => gmdate( 'Y-m-d H:i:s' ),
			'agent_author'  => $identity['author'],
			'agent_version' => $identity['version'],
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table insert.
		$result = $wpdb->insert( $wpdb->prefix . 'agent_builder_audit_log', $data );

		if ( $result ) {
			$insert_id = $wpdb->insert_id;
			// Snapshot the chain hash in the same request as the insert, using
			// the exact $data just written — see Audit_Log_Integrity for why
			// this can't be deferred to a later read of the row.
			Audit_Log_Integrity::record( $insert_id, $data );
			self::bust_query_cache();
			return $insert_id;
		}

		return false;
	}

	/**
	 * Resolve the developer/vendor identity to snapshot into a log row at
	 * write time, so "who was responsible for this action" is answerable
	 * from the row alone even if the agent is later updated, reassigned, or
	 * removed — reading it back out of the current agent.json would give the
	 * agent's *current* attribution, not what was true when the action ran.
	 *
	 * 'human', 'system', and 'unknown' aren't real agents (see human_agent()'s
	 * own special-casing of the same three slugs) — they intentionally get no
	 * author/version rather than a misleading lookup miss.
	 *
	 * @param string $agent_id Agent identifier as passed to log().
	 * @return array{author: string, version: string}
	 */
	private static function resolve_agent_identity( string $agent_id ): array {
		if ( in_array( $agent_id, array( 'human', 'system', 'unknown', '' ), true ) ) {
			return array(
				'author'  => '',
				'version' => '',
			);
		}

		if ( ! class_exists( '\\Agentic_Agent_Registry' ) ) {
			return array(
				'author'  => '',
				'version' => '',
			);
		}

		$agents = \Agentic_Agent_Registry::get_instance()->get_installed_agents();
		$agent  = $agents[ $agent_id ] ?? null;

		return array(
			'author'  => is_array( $agent ) ? (string) ( $agent['author'] ?? '' ) : '',
			'version' => is_array( $agent ) ? (string) ( $agent['version'] ?? '' ) : '',
		);
	}

	/**
	 * Convenience: log an admin / human configuration action into the audit trail.
	 *
	 * Prefer this for settings, tools toggles, profiles, and site-local tools so
	 * Activity stays complete without each caller managing instances.
	 *
	 * @param string $action      Action slug (e.g. tools_profile_applied).
	 * @param string $target_type Optional target type or option key.
	 * @param mixed  $details     Structured details (arrays preferred).
	 * @param string $reasoning   Optional short reason.
	 * @return int|false
	 */
	public static function log_admin( string $action, string $target_type = 'settings', mixed $details = null, string $reasoning = '' ): int|false {
		$log = new self();
		return $log->log( 'human', $action, $target_type, $details, $reasoning );
	}

	/**
	 * Bust all short-lived audit-log query transients.
	 * Called automatically after each log() insert.
	 */
	public static function bust_query_cache(): void {
		delete_transient( 'agentic_audit_agent_ids' );
		delete_transient( 'agentic_audit_action_types' );
		// Pattern-delete the per-filter result transients via option scan (cheap on small sites).
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Targeted transient cleanup.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				'_transient_agentic_audit_%',
				'_transient_timeout_agentic_audit_%'
			)
		);
	}

	/**
	 * Get recent log entries
	 *
	 * @param int         $limit    Maximum entries.
	 * @param string|null $agent_id Filter by agent.
	 * @param string|null $action   Filter by action.
	 * @param string      $period          Time window: 'day' (default), 'week', or 'month'.
	 * @param array       $exclude_actions Optional list of actions to exclude.
	 * @return array Log entries.
	 */
	public function get_recent( int $limit = 50, ?string $agent_id = null, ?string $action = null, string $period = 'day', array $exclude_actions = array() ): array {
		global $wpdb;

		$days = match ( $period ) {
			'week'  => 7,
			'month' => 30,
			default => 1,
		};

		// Build a stable cache key from all filter parameters.
		$cache_key = 'agentic_audit_' . md5( $period . '|' . (string) $limit . '|' . (string) $agent_id . '|' . (string) $action . '|' . implode( ',', $exclude_actions ) );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$where  = array( 'created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)' );
		$params = array( $days );

		if ( $agent_id ) {
			$where[]  = 'agent_id = %s';
			$params[] = $agent_id;
		}

		if ( $action ) {
			$where[]  = 'action = %s';
			$params[] = $action;
		}

		if ( ! empty( $exclude_actions ) ) {
			$placeholders = implode( ', ', array_fill( 0, count( $exclude_actions ), '%s' ) );
			$where[]      = 'action NOT IN (' . $placeholders . ')'; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$params       = array_merge( $params, $exclude_actions );
		}

		$where_clause = 'WHERE ' . implode( ' AND ', $where );
		$params[]     = $limit;

		$query = 'SELECT * FROM ' . $wpdb->prefix . 'agent_builder_audit_log ' . $where_clause . ' ORDER BY created_at DESC LIMIT %d';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table query, where clause built from known safe values.
		$results = $wpdb->get_results( $wpdb->prepare( $query, $params ), ARRAY_A );

		// Cache for 60 seconds — short enough to stay fresh, long enough to absorb rapid reloads.
		set_transient( $cache_key, $results, 60 );

		return $results;
	}

	/**
	 * Get distinct agent IDs present in the audit log (cached).
	 *
	 * @return string[]
	 */
	public function get_agent_ids(): array {
		$cached = get_transient( 'agentic_audit_agent_ids' );
		if ( false !== $cached ) {
			return $cached;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cached immediately below. %i placeholder safely quotes the table name.
		$rows = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT agent_id FROM %i ORDER BY agent_id ASC', $wpdb->prefix . 'agent_builder_audit_log' ) );
		set_transient( 'agentic_audit_agent_ids', $rows, 300 );
		return $rows;
	}

	/**
	 * Get distinct action types present in the audit log (cached).
	 *
	 * @return string[]
	 */
	public function get_action_types(): array {
		$cached = get_transient( 'agentic_audit_action_types' );
		if ( false !== $cached ) {
			return $cached;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cached immediately below.
		$rows = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT action FROM %i ORDER BY action ASC', $wpdb->prefix . 'agent_builder_audit_log' ) );
		set_transient( 'agentic_audit_action_types', $rows, 300 );
		return $rows;
	}

	/**
	 * Get usage statistics
	 *
	 * @param string $period Period (day, week, month).
	 * @return array Statistics.
	 */
	public function get_stats( string $period = 'day' ): array {
		global $wpdb;

		$days = match ( $period ) {
			'week'  => 7,
			'month' => 30,
			default => 1,
		};

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table stats.
		$stats = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT 
                    COUNT(*) as total_actions,
                    SUM(tokens_used) as total_tokens,
                    SUM(cost) as total_cost,
                    COUNT(DISTINCT agent_id) as active_agents
                FROM {$wpdb->prefix}agent_builder_audit_log 
                WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)",
				$days
			),
			ARRAY_A
		);

		return null !== $stats ? $stats : array(
			'total_actions' => 0,
			'total_tokens'  => 0,
			'total_cost'    => 0,
			'active_agents' => 0,
		);
	}

	/**
	 * Clear old log entries
	 *
	 * @param int $days Entries older than this will be deleted.
	 * @return int Number of deleted entries.
	 */
	public function cleanup( int $days = 30 ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table cleanup.
		return $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}agent_builder_audit_log WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)",
				$days
			)
		);
	}

	/**
	 * Run the scheduled retention cleanup.
	 *
	 * Reads the same 'agent_builder_retention_audit_log' option the Settings →
	 * Security tab writes (Admin_Settings_REST::update_tab()) and GDPR::
	 * run_cleanup() already reads for its own, separate audit/security-log
	 * sweep. Previously this method read an unrelated
	 * 'agentic_audit_retention_days' *filter* that nothing in the codebase
	 * ever hooked — so a site owner's configured retention was silently
	 * ignored by this cron path, which always fell back to its own 30-day
	 * default regardless of what Settings showed. That meant the daily
	 * 'agent_builder_cleanup_audit_log' cron (this method) and GDPR::run_cleanup()'s
	 * daily sweep could disagree about how long to keep the exact same rows.
	 * Reading the real option makes both paths agree, and honors "0 = keep
	 * indefinitely" the same way GDPR::run_cleanup() already does.
	 *
	 * @return int Number of deleted entries.
	 */
	public function cleanup_expired(): int {
		$days = (int) get_option( 'agent_builder_retention_audit_log', 30 );

		if ( $days < 1 ) {
			// 0 (or an invalid negative value) means keep indefinitely.
			return 0;
		}

		return $this->cleanup( $days );
	}

	/**
	 * Get a human-readable label for an audit action slug.
	 *
	 * @param string $action Raw action slug from the database.
	 * @return string Human-readable label.
	 */
	public static function human_action( string $action ): string {
		static $labels = array(
			'chat_start'               => 'Conversation started',
			'chat_complete'            => 'Conversation finished',
			'chat_error'               => 'Conversation error',
			'cache_hit'                => 'Served from cache',
			'autonomous_chat_start'    => 'Autonomous task started',
			'autonomous_chat_complete' => 'Autonomous task finished',
			'autonomous_chat_error'    => 'Autonomous task error',
			'tool_call'                => 'Used a tool',
			'tool_choice'              => 'Chose tools (with reasoning)',
			'agent_delegation'         => 'Delegated to another agent',
			'human_escalation'         => 'Escalated to a human',
			'tool_error_feedback'      => 'Tool call failed (feedback to model)',
			'tool_blocked'             => 'Tool blocked (no permission)',
			'tool_enabled'             => 'Tool enabled',
			'tool_disabled'            => 'Tool disabled',
			'scheduled_task_start'     => 'Scheduled task started',
			'scheduled_task_complete'  => 'Scheduled task finished',
			'scheduled_task_error'     => 'Scheduled task error',
			'event_listener_triggered' => 'Noticed a site change',
			'event_listener_complete'  => 'Finished responding to change',
			'event_listener_error'     => 'Error responding to change',
			'proposal_created'         => 'Proposed a change',
			'proposal_approved'        => 'Change approved',
			'proposal_rejected'        => 'Change rejected',
			'approval_approved'        => 'Change approved',
			'approval_rejected'        => 'Change rejected',
			'agent_activated'          => 'Agent activated',
			'agent_deactivated'        => 'Agent deactivated',
			'agent_installed'          => 'Agent installed',
			'agent_deleted'            => 'Agent deleted',
			'endpoint_url_changed'     => 'Changed a service endpoint URL',
			'knowledge_added'          => 'Added knowledge',
		);

		return $labels[ $action ] ?? ucwords( str_replace( '_', ' ', $action ) );
	}

	/**
	 * Get a human-readable label for a target_type slug.
	 *
	 * @param string $target_type Raw target_type from the database.
	 * @return string Human-readable label.
	 */
	public static function human_target( string $target_type ): string {
		static $labels = array(
			'conversation'        => 'Chat',
			'scheduled_task'      => 'Scheduled task',
			'error'               => 'Error',
			'approval'            => 'Approval',
			// Common tool names (includes removed tools for historical log entries).
			'db_get_option'       => 'Read setting',
			'db_update_option'    => 'Update setting',
			'db_get_posts'        => 'List posts',
			'db_get_post'         => 'Read post',
			'db_create_post'      => 'Create post',
			'db_update_post'      => 'Update post',
			'db_delete_post'      => 'Delete post',
			'db_get_users'        => 'List users',
			'db_get_terms'        => 'List terms',
			'db_get_post_meta'    => 'Read post metadata',
			'db_get_comments'     => 'List comments',
			'read_file'           => 'Read file',
			'list_directory'      => 'List directory',
			'search_code'         => 'Search code',
			'write_file'          => 'Write file',
			'modify_option'       => 'Modify setting',
			'manage_transients'   => 'Manage cache',
			'modify_postmeta'     => 'Modify post metadata',
			'request_code_change' => 'Propose code change',
			'manage_schedules'    => 'Manage schedules',
		);

		return $labels[ $target_type ] ?? ucwords( str_replace( '_', ' ', $target_type ) );
	}

	/**
	 * Get a human-readable label for an agent ID slug.
	 *
	 * @param string $agent_id Raw agent_id from the database.
	 * @return string Human-readable label.
	 */
	public static function human_agent( string $agent_id ): string {
		static $labels = array(
			'system'  => 'System',
			'human'   => 'You',
			'unknown' => 'Unknown',
		);

		if ( isset( $labels[ $agent_id ] ) ) {
			return $labels[ $agent_id ];
		}

		return ucwords( str_replace( array( '-', '_' ), ' ', $agent_id ) );
	}

	/**
	 * Get a human-readable label for a mode slug.
	 *
	 * @param string $mode Raw mode from the database.
	 * @return string Human-readable label.
	 */
	public static function human_mode( string $mode ): string {
		static $labels = array(
			'supervised' => 'Supervised',
			'autonomous' => 'Autonomous',
			'disabled'   => 'Disabled',
		);

		return $labels[ $mode ] ?? '';
	}
}
