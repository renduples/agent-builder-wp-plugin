<?php
/**
 * Approval Queue
 *
 * Ledger of all non-readonly tool executions. High-risk writes pause for
 * admin approval (status = pending); everything else is recorded immediately
 * (status = executed). Records retained for 30 days.
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

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages the approval queue and write-operations ledger.
 */
class Approval_Queue {

	/**
	 * Number of days to retain records before cleanup.
	 */
	private const RETENTION_DAYS = 30;

	/**
	 * Constructor
	 */
	public function __construct() {
		// Add admin notices for pending approvals.
		add_action( 'admin_notices', array( $this, 'pending_approval_notice' ) );
	}

	/**
	 * Show notice for pending approvals
	 *
	 * @return void
	 */
	public function pending_approval_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$count = $this->get_pending_count();

		if ( $count > 0 ) {
			$url = admin_url( 'admin.php?page=agentic-approvals' );
			printf(
				'<div class="notice notice-warning"><p><strong>Agentic:</strong> %s pending approval(s). <a href="%s">Review now</a></p></div>',
				esc_html( $count ),
				esc_url( $url )
			);
		}
	}

	/**
	 * Get count of pending approvals
	 *
	 * @return int
	 */
	public function get_pending_count(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query.
		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}agent_builder_approval_queue WHERE status = 'pending'"
		);
	}

	/**
	 * Get count of approvals a human has actually acted on (approved or
	 * rejected, including ones later marked executed).
	 *
	 * Deliberately keyed on `approved_by IS NOT NULL` rather than a status
	 * list: this table also holds the general non-readonly tool-execution
	 * ledger (see log_executed()), whose rows land at status = 'executed'
	 * too but were never approval-gated at all (approved_by stays NULL).
	 * Counting those here would make this number mostly ledger noise
	 * instead of a meaningful pairing with get_pending_count(). Expired
	 * items are excluded for the same reason — nobody reviewed them.
	 *
	 * @return int
	 */
	public function get_completed_count(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query.
		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}agent_builder_approval_queue WHERE approved_by IS NOT NULL"
		);
	}

	/**
	 * Get all pending approvals
	 *
	 * @return array
	 */
	public function get_pending(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query.
		$results = $wpdb->get_results(
			"SELECT * FROM {$wpdb->prefix}agent_builder_approval_queue WHERE status = 'pending' ORDER BY created_at DESC",
			ARRAY_A
		);

		foreach ( $results as &$row ) {
			$row['params'] = json_decode( $row['params'], true );
		}

		return $results;
	}

	/**
	 * Add a high-risk item to the approval queue (status = pending).
	 *
	 * @param string $agent_id   Agent identifier.
	 * @param string $action     Tool/action name.
	 * @param array  $params     Tool arguments.
	 * @param string $reasoning  Reason for queuing.
	 * @param int    $expires    Days until pending item expires.
	 * @param string $risk_level Risk level.
	 * @param string $mode       Agent operating mode.
	 * @param string $invocation Invocation context (chat, cron, hook, cli).
	 * @return int|false Queue item ID or false.
	 */
	public function add( string $agent_id, string $action, array $params, string $reasoning = '', int $expires = 7, string $risk_level = 'high', string $mode = '', string $invocation = '' ): int|false {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table insert.
		$result = $wpdb->insert(
			$wpdb->prefix . 'agent_builder_approval_queue',
			array(
				'agent_id'   => $agent_id,
				'action'     => $action,
				'params'     => wp_json_encode( $params ),
				'reasoning'  => $reasoning,
				'risk_level' => $risk_level,
				'status'     => 'pending',
				'mode'       => $mode,
				'invocation' => $invocation,
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
				'expires_at' => gmdate( 'Y-m-d H:i:s', strtotime( "+{$expires} days" ) ),
			)
		);

		if ( ! $result ) {
			return false;
		}

		$id = (int) $wpdb->insert_id;
		self::maybe_email_pending_notice(
			$id,
			$agent_id,
			$action,
			$risk_level,
			$reasoning
		);

		if ( class_exists( Audit_Log::class ) ) {
			( new Audit_Log() )->log(
				$agent_id,
				'approval_queued',
				$action,
				array(
					'id'         => $id,
					'request_id' => $id,
					'risk_level' => $risk_level,
					'mode'       => $mode,
					'invocation' => $invocation,
					'reasoning'  => $reasoning,
				),
				$reasoning
			);
		}

		return $id;
	}

	/**
	 * Email site owner when a new item hits the approval queue (if enabled).
	 *
	 * Rate-limited to avoid flooding (max ~1 email / 5 minutes; pending count noted).
	 *
	 * @param int    $id         Queue id.
	 * @param string $agent_id   Agent.
	 * @param string $action     Tool name.
	 * @param string $risk_level Risk.
	 * @param string $reasoning  Why queued.
	 * @return void
	 */
	public static function maybe_email_pending_notice( int $id, string $agent_id, string $action, string $risk_level, string $reasoning = '' ): void {
		if ( ! get_option( 'agent_builder_approval_email_notify', false ) ) {
			return;
		}

		// Rate limit: at most one email every 5 minutes.
		if ( get_transient( 'agentic_approval_email_cooldown' ) ) {
			// Still bump a counter so next email can mention backlog.
			$count = (int) get_transient( 'agentic_approval_email_skipped' );
			set_transient( 'agentic_approval_email_skipped', $count + 1, 30 * MINUTE_IN_SECONDS );
			return;
		}

		$to = sanitize_email( (string) get_option( 'agent_builder_approval_email_to', '' ) );
		if ( ! is_email( $to ) ) {
			$to = (string) get_option( 'admin_email' );
		}
		if ( ! is_email( $to ) ) {
			return;
		}

		$skipped = (int) get_transient( 'agentic_approval_email_skipped' );
		delete_transient( 'agentic_approval_email_skipped' );

		$queue_url = admin_url( 'admin.php?page=agentic-approvals' );
		$label     = str_replace( '_', ' ', $action );
		$agent     = str_replace( '-', ' ', $agent_id );

		$subject = sprintf(
			/* translators: %s: site name */
			__( '[%s] Agent action waiting for your approval', 'agent-builder' ),
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
		);

		$body  = '<p style="margin:0 0 12px;">' . esc_html__( 'An AI agent wants to make a change that needs your OK before it runs.', 'agent-builder' ) . '</p>';
		$body .= '<ul style="margin:0 0 16px;padding-left:18px;">';
		$body .= '<li><strong>' . esc_html__( 'Action', 'agent-builder' ) . ':</strong> ' . esc_html( $label ) . '</li>';
		$body .= '<li><strong>' . esc_html__( 'Agent', 'agent-builder' ) . ':</strong> ' . esc_html( $agent ) . '</li>';
		$body .= '<li><strong>' . esc_html__( 'Risk', 'agent-builder' ) . ':</strong> ' . esc_html( $risk_level ) . '</li>';
		if ( $reasoning ) {
			$body .= '<li><strong>' . esc_html__( 'Why', 'agent-builder' ) . ':</strong> ' . esc_html( wp_strip_all_tags( $reasoning ) ) . '</li>';
		}
		if ( $skipped > 0 ) {
			$body .= '<li>' . esc_html(
				sprintf(
					/* translators: %d: number of additional pending items since last email */
					__( 'Plus about %d more request(s) while email was paused to avoid spam.', 'agent-builder' ),
					$skipped
				)
			) . '</li>';
		}
		$body .= '</ul>';
		$body .= '<p style="margin:0 0 12px;"><a href="' . esc_url( $queue_url ) . '">' . esc_html__( 'Review approvals in WordPress admin', 'agent-builder' ) . '</a></p>';
		$body .= '<p style="margin:0;font-size:12px;color:#646970;">' . esc_html__( 'You can turn these emails off under Approvals → Preferences.', 'agent-builder' ) . '</p>';

		$sent = false;
		if ( class_exists( '\Agentic_Email_Helper' ) ) {
			$sent = (bool) \Agentic_Email_Helper::send(
				$to,
				$subject,
				array(
					'heading' => __( 'Approval needed', 'agent-builder' ),
					'body'    => $body,
					'from'    => 'Agent Builder <support@agentic-plugin.com>',
				)
			);
		}
		if ( ! $sent ) {
			$headers = array( 'Content-Type: text/html; charset=UTF-8' );
			wp_mail( $to, $subject, $body, $headers );
		}

		set_transient( 'agentic_approval_email_cooldown', 1, 5 * MINUTE_IN_SECONDS );

		// Unused param keeps signature stable for filters later.
		unset( $id );
	}

	/**
	 * Record a write tool that executed immediately (no approval needed).
	 *
	 * Used for tools that pass risk gating (allow/confirm-then-execute) but
	 * are non-readonly, so they need to appear in the operations ledger.
	 *
	 * @param string $agent_id   Agent identifier.
	 * @param string $action     Tool/action name.
	 * @param array  $params     Tool arguments.
	 * @param string $risk_level Effective risk level.
	 * @param string $mode       Agent operating mode.
	 * @param string $invocation Invocation context (chat, cron, hook, cli).
	 * @return int|false Record ID or false.
	 */
	public function log_executed( string $agent_id, string $action, array $params, string $risk_level = 'none', string $mode = '', string $invocation = '' ): int|false {
		global $wpdb;

		$now = gmdate( 'Y-m-d H:i:s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table insert.
		$result = $wpdb->insert(
			$wpdb->prefix . 'agent_builder_approval_queue',
			array(
				'agent_id'    => $agent_id,
				'action'      => $action,
				'params'      => wp_json_encode( $params ),
				'reasoning'   => '',
				'risk_level'  => $risk_level,
				'status'      => 'executed',
				'mode'        => $mode,
				'invocation'  => $invocation,
				'created_at'  => $now,
				'executed_at' => $now,
			)
		);

		return $result ? $wpdb->insert_id : false;
	}

	/**
	 * Approve an item
	 *
	 * @param int $id Queue item ID.
	 * @return bool Success.
	 */
	public function approve( int $id ): bool {
		global $wpdb;

		// Fetch the row before updating so we can log meaningful context.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Single-row lookup before update.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT agent_id, action, risk_level FROM {$wpdb->prefix}agent_builder_approval_queue WHERE id = %d", $id ), ARRAY_A );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table update.
		$result = (bool) $wpdb->update(
			$wpdb->prefix . 'agent_builder_approval_queue',
			array(
				'status'      => 'approved',
				'approved_by' => get_current_user_id(),
				'approved_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => $id )
		);

		if ( $result ) {
			\Agentic\Security_Log::log_system(
				'action_approved',
				'approvals',
				array(
					'queue_id'   => $id,
					'agent_id'   => $row['agent_id'] ?? '',
					'action'     => $row['action'] ?? '',
					'risk_level' => $row['risk_level'] ?? '',
				)
			);
		} else {
			\Agentic\Security_Log::log_system(
				'action_approve_failed',
				'approvals',
				array(
					'queue_id' => $id,
					'agent_id' => $row['agent_id'] ?? '',
					'action'   => $row['action'] ?? '',
					'reason'   => 'db_update_failed',
				)
			);
		}

		return $result;
	}

	/**
	 * Reject an item
	 *
	 * @param int $id Queue item ID.
	 * @return bool Success.
	 */
	public function reject( int $id ): bool {
		global $wpdb;

		// Fetch the row before updating so we can log meaningful context.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Single-row lookup before update.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT agent_id, action, risk_level FROM {$wpdb->prefix}agent_builder_approval_queue WHERE id = %d", $id ), ARRAY_A );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table update.
		$result = (bool) $wpdb->update(
			$wpdb->prefix . 'agent_builder_approval_queue',
			array(
				'status'      => 'rejected',
				'approved_by' => get_current_user_id(),
				'approved_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => $id )
		);

		if ( $result ) {
			\Agentic\Security_Log::log_system(
				'action_rejected',
				'approvals',
				array(
					'queue_id'   => $id,
					'agent_id'   => $row['agent_id'] ?? '',
					'action'     => $row['action'] ?? '',
					'risk_level' => $row['risk_level'] ?? '',
				)
			);
		} else {
			\Agentic\Security_Log::log_system(
				'action_reject_failed',
				'approvals',
				array(
					'queue_id' => $id,
					'agent_id' => $row['agent_id'] ?? '',
					'action'   => $row['action'] ?? '',
					'reason'   => 'db_update_failed',
				)
			);
		}

		return $result;
	}

	/**
	 * Find an approved (but not yet executed) entry for a given agent + tool + params.
	 *
	 * Matching is params-aware so multi-action tools (e.g. manage_cli_settings with
	 * different `action`/args) cannot consume an approval granted for a milder call.
	 *
	 * @param string     $agent_id   Agent identifier.
	 * @param string     $tool_name  Tool/action name.
	 * @param array|null $arguments  Current call arguments. Required for a match;
	 *                               when null, returns null (never match by name alone).
	 * @return array|null The queue row, or null if none found.
	 */
	public function find_approved( string $agent_id, string $tool_name, ?array $arguments = null ): ?array {
		if ( null === $arguments ) {
			return null;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}agent_builder_approval_queue
				 WHERE agent_id = %s AND action = %s AND status = 'approved'
				 AND expires_at > NOW()
				 ORDER BY approved_at DESC LIMIT 20",
				$agent_id,
				$tool_name
			),
			ARRAY_A
		);

		if ( empty( $rows ) || ! is_array( $rows ) ) {
			return null;
		}

		$want = self::canonicalize_params( $arguments );
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$stored_raw = $row['params'] ?? '';
			$stored     = is_string( $stored_raw ) ? json_decode( $stored_raw, true ) : null;
			if ( ! is_array( $stored ) ) {
				continue;
			}
			if ( self::canonicalize_params( $stored ) === $want ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * Stable JSON for comparing approval-queue params to a new call.
	 *
	 * @param array $params Tool arguments.
	 * @return string
	 */
	public static function canonicalize_params( array $params ): string {
		return wp_json_encode( self::ksort_recursive( $params ) ) ?: '';
	}

	/**
	 * Recursively ksort an array for stable JSON.
	 *
	 * @param array $data Input.
	 * @return array
	 */
	private static function ksort_recursive( array $data ): array {
		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				// Distinguish list vs map: re-index only pure lists.
				if ( self::is_list( $value ) ) {
					$data[ $key ] = array_map(
						static function ( $item ) {
							return is_array( $item ) ? self::ksort_recursive( $item ) : $item;
						},
						$value
					);
				} else {
					$data[ $key ] = self::ksort_recursive( $value );
				}
			}
		}
		if ( ! self::is_list( $data ) ) {
			ksort( $data );
		}
		return $data;
	}

	/**
	 * Whether an array is a list (sequential integer keys from 0). Same
	 * check as PHP 8.1's array_is_list(), implemented manually so this
	 * doesn't depend on WordPress's own polyfill for it (only bundled since
	 * WP 6.5 — this plugin's declared minimum is 6.4) or assume every site
	 * actually meets the plugin's declared PHP 8.1 floor at runtime.
	 *
	 * @param array $data Input.
	 * @return bool
	 */
	private static function is_list( array $data ): bool {
		if ( array() === $data ) {
			return true;
		}
		return array_keys( $data ) === range( 0, count( $data ) - 1 );
	}

	/**
	 * Mark an approved entry as executed.
	 *
	 * @param int $id Queue item ID.
	 * @return bool Success.
	 */
	public function mark_executed( int $id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table update.
		return (bool) $wpdb->update(
			$wpdb->prefix . 'agent_builder_approval_queue',
			array(
				'status'      => 'executed',
				'executed_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => $id )
		);
	}

	/**
	 * Get recent operations with optional filtering.
	 *
	 * @param array $args {
	 *     Optional. Query arguments.
	 *     @type string $status   Filter by status (pending, executed, approved, rejected, expired).
	 *     @type string $agent_id Filter by agent.
	 *     @type int    $limit    Number of records (default 50).
	 *     @type int    $offset   Pagination offset (default 0).
	 * }
	 * @return array Records.
	 */
	public function get_recent( array $args = array() ): array {
		global $wpdb;

		$where  = array( '1=1' );
		$values = array();

		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$values[] = $args['status'];
		}

		if ( ! empty( $args['agent_id'] ) ) {
			$where[]  = 'agent_id = %s';
			$values[] = $args['agent_id'];
		}

		$limit  = (int) ( $args['limit'] ?? 50 );
		$offset = (int) ( $args['offset'] ?? 0 );

		$where_sql = implode( ' AND ', $where );
		$query     = "SELECT * FROM {$wpdb->prefix}agent_builder_approval_queue WHERE {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d";
		$values[]  = $limit;
		$values[]  = $offset;

		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Custom table query with dynamic where clause.
		$results = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Custom plugin table.
			$wpdb->prepare( $query, ...$values ),
			ARRAY_A
		);

		foreach ( $results as &$row ) {
			$row['params'] = json_decode( $row['params'] ?? '[]', true );
		}

		return $results;
	}

	/**
	 * Expire stale pending items and delete records older than retention period.
	 *
	 * @return int Number of rows deleted.
	 */
	public function cleanup_expired(): int {
		global $wpdb;

		$table = $wpdb->prefix . 'agent_builder_approval_queue';

		// Mark pending items past their expiry as expired (preserve the record).
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table update.
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			"UPDATE {$table} SET status = 'expired' WHERE status = 'pending' AND expires_at IS NOT NULL AND expires_at < NOW()" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		);

		// Delete all records older than retention period.
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Retention cleanup on custom table.
		return (int) $wpdb->query(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table.
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
				"DELETE FROM {$table} WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
				self::RETENTION_DAYS
			)
		);
	}
}
