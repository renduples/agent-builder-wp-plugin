<?php
/**
 * Agent Proposals — pending change confirmation system
 *
 * When confirmation mode is "Always Confirm", user-space write tools
 * create a proposal instead of executing immediately. The proposal is
 * rendered in the chat UI with a diff view and Approve/Reject buttons.
 *
 * @package    Agent_Builder
 * @subpackage Includes
 * @author     Agent Builder Team <support@agentic-plugin.com>
 * @license    GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 * @link       https://agentic-plugin.com
 * @since      1.6.0
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages pending change proposals for user-space operations.
 *
 * Proposals are stored in the agent_builder_proposals table (since schema
 * 2.15.2 / M12). Before that they lived in transients; the move makes them
 * durable, filterable via pending(), and cleanable via cleanup_expired().
 */
class Agent_Proposals {

	/**
	 * Proposal table name (sans prefix).
	 */
	private const TABLE = 'agent_builder_proposals';

	/**
	 * Expiry for a chat-originated proposal (no run_id): 1 hour.
	 */
	private const EXPIRY_CHAT = 3600;

	/**
	 * Expiry for a run-backed proposal (run_id set): 7 days.
	 */
	private const EXPIRY_RUN = 7 * 24 * 3600;

	/**
	 * Transient prefix for the per-(agent, listener, tool) pending-proposal
	 * dedupe marker. The marker holds the proposal id and expires with the
	 * proposal it points at, so a gated event listener that keeps firing the
	 * same tool never stacks up duplicate proposals.
	 */
	private const LISTENER_PENDING_PREFIX = 'agentic_listener_pending_';

	/**
	 * Create a new proposal.
	 *
	 * @param string $tool_name   The tool that generated this proposal.
	 * @param array  $params      Tool parameters (path, content, etc.).
	 * @param string $agent_id    Agent that proposed the change.
	 * @param string $description Human-readable description of the change.
	 * @param string $diff        Diff between current and proposed content.
	 * @param string $run_id      Owning Agent_Run id, if this call happened inside a run.
	 * @param int    $created_by  User id to attribute the proposal to. Defaults to
	 *                            the current user when 0 (e.g. a background run
	 *                            acting on behalf of its owner should pass that
	 *                            owner's id explicitly instead).
	 * @param string $listener_id Event-listener id when this proposal came from a
	 *                            gated listener (used to dedupe repeat fires); '' for
	 *                            chat/other origins.
	 * @return array Proposal data with ID.
	 */
	public static function create( string $tool_name, array $params, string $agent_id, string $description, string $diff = '', string $run_id = '', int $created_by = 0, string $listener_id = '' ): array {
		global $wpdb;

		$proposal_id = wp_generate_uuid4();
		$user_id     = $created_by > 0 ? $created_by : get_current_user_id();
		$expiry      = ( '' === $run_id ) ? self::EXPIRY_CHAT : self::EXPIRY_RUN;
		$expires_at  = gmdate( 'Y-m-d H:i:s', time() + $expiry );

		$proposal = array(
			'id'          => $proposal_id,
			'tool'        => $tool_name,
			'params'      => $params,
			'agent_id'    => $agent_id,
			'description' => $description,
			'diff'        => $diff,
			'status'      => 'pending',
			'created_at'  => gmdate( 'Y-m-d H:i:s' ),
			'created_by'  => $user_id,
			'run_id'      => $run_id,
			'session_id'  => null,
			'expires_at'  => $expires_at,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table insert.
		$wpdb->insert(
			$wpdb->prefix . self::TABLE,
			array(
				'id'          => $proposal_id,
				'tool'        => $tool_name,
				'params'      => wp_json_encode( $params ),
				'agent_id'    => $agent_id,
				'description' => $description,
				'diff'        => $diff,
				'status'      => 'pending',
				'created_by'  => $user_id > 0 ? $user_id : null,
				'run_id'      => '' !== $run_id ? $run_id : null,
				'session_id'  => null,
				'created_at'  => gmdate( 'Y-m-d H:i:s' ),
				'expires_at'  => $expires_at,
			)
		);

		// Record the pending marker so a repeat fire of the same listener + tool
		// is deduped instead of stacking a second proposal.
		if ( '' !== $listener_id ) {
			set_transient( self::pending_key( $agent_id, $listener_id, $tool_name ), $proposal_id, $expiry );
		}

		// Log the proposal creation.
		$audit = new Audit_Log();
		$audit->log(
			$agent_id,
			'proposal_created',
			$tool_name,
			array(
				'proposal_id' => $proposal_id,
				'description' => $description,
			)
		);

		return $proposal;
	}

	/**
	 * Get a proposal by ID.
	 *
	 * A still-pending proposal whose expires_at has lapsed is treated as
	 * not found (its stored status is flipped to 'expired' by the daily
	 * cleanup cron, not here).
	 *
	 * @param string $proposal_id Proposal UUID.
	 * @return array|null Proposal data or null if not found/expired.
	 */
	public static function get( string $proposal_id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Single-row custom table lookup.
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}agent_builder_proposals WHERE id = %s", $proposal_id ),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		$proposal = self::normalize_row( $row );

		// Expiry-on-read: a pending proposal whose window has lapsed is gone.
		if ( 'pending' === $proposal['status'] && ! empty( $proposal['expires_at'] ) && strtotime( (string) $proposal['expires_at'] ) < time() ) {
			return null;
		}

		return $proposal;
	}

	/**
	 * Dedupe key for a pending proposal raised by a specific listener + tool.
	 *
	 * @param string $agent_id    Agent slug.
	 * @param string $listener_id Listener id.
	 * @param string $tool_name   Tool slug.
	 * @return string Transient name (bounded length, safe for wp_options).
	 */
	public static function pending_key( string $agent_id, string $listener_id, string $tool_name ): string {
		return self::LISTENER_PENDING_PREFIX . md5( $agent_id . '|' . $listener_id . '|' . $tool_name );
	}

	/**
	 * Return the id of an unexpired, still-pending proposal for the given
	 * agent + listener + tool, or null when none exists.
	 *
	 * Clears a stale marker (one whose proposal has expired or been resolved) so
	 * a future fire is not deduped against a proposal that no longer exists.
	 *
	 * @param string $agent_id    Agent slug.
	 * @param string $listener_id Listener id.
	 * @param string $tool_name   Tool slug.
	 * @return string|null Proposal id, or null.
	 */
	public static function has_pending( string $agent_id, string $listener_id, string $tool_name ): ?string {
		$key         = self::pending_key( $agent_id, $listener_id, $tool_name );
		$proposal_id = get_transient( $key );
		if ( ! is_string( $proposal_id ) || '' === $proposal_id ) {
			return null;
		}

		$proposal = self::get( $proposal_id );
		if ( null === $proposal || 'pending' !== ( $proposal['status'] ?? '' ) ) {
			delete_transient( $key );
			return null;
		}

		return $proposal_id;
	}

	/**
	 * Approve and execute a proposal.
	 *
	 * @param string $proposal_id Proposal UUID.
	 * @return array Result of execution.
	 */
	public static function approve( string $proposal_id ): array {
		$proposal = self::get( $proposal_id );

		if ( ! $proposal ) {
			return array( 'error' => 'Proposal not found or expired.' );
		}

		if ( 'pending' !== $proposal['status'] ) {
			return array( 'error' => 'Proposal already processed.' );
		}

		// Atomically claim the pending row before executing. If a concurrent
		// request won the race (0 affected rows), the proposal was already
		// decided — refuse to execute rather than double-run the tool call.
		if ( ! self::mark_decided( $proposal_id, 'approved' ) ) {
			return array( 'error' => 'Proposal already processed.' );
		}
		$proposal['status'] = 'approved';

		// Execute the already-approved change through Tool_Executor's approved
		// path, which runs the same tool_loader → agent-inline → abilities-bridge
		// fallback chain as execute()'s allow-path (and sets the calling-agent
		// context, needed by delegate_to_agent to detect delegation cycles) and
		// writes the operations ledger for non-readonly tools.
		$agent  = \Agentic_Agent_Registry::get_instance()->get_agent_instance( (string) ( $proposal['agent_id'] ?? '' ) );
		$result = ( new Tool_Executor( Tool_Loader::get_instance(), new Audit_Log() ) )->execute_approved(
			array(
				'tool'       => (string) $proposal['tool'],
				'params'     => $proposal['params'],
				'agent_id'   => (string) ( $proposal['agent_id'] ?? '' ),
				'run_id'     => (string) ( $proposal['run_id'] ?? '' ),
				'created_by' => (int) ( $proposal['created_by'] ?? 0 ),
				'mode'       => Audit_Log::get_mode_context(),
				'invocation' => 'chat',
			),
			$agent
		);

		// Log approval.
		$audit = new Audit_Log();
		$audit->log(
			$proposal['agent_id'],
			'proposal_approved',
			$proposal['tool'],
			array(
				'proposal_id' => $proposal_id,
				'result'      => is_array( $result ) ? ( $result['success'] ?? false ) : false,
			)
		);

		// Only a run-backed proposal has a paused run waiting on this decision —
		// a chat-originated proposal (no run_id) has nothing to resume.
		if ( ! empty( $proposal['run_id'] ) ) {
			do_action( 'agent_builder_approval_resolved', 'proposal', $proposal_id, 'approved', $result, $proposal );
		}

		return $result;
	}

	/**
	 * Reject a proposal.
	 *
	 * @param string $proposal_id Proposal UUID.
	 * @return array Result.
	 */
	public static function reject( string $proposal_id ): array {
		$proposal = self::get( $proposal_id );

		if ( ! $proposal ) {
			return array( 'error' => 'Proposal not found or expired.' );
		}

		if ( 'pending' !== $proposal['status'] ) {
			return array( 'error' => 'Proposal already processed.' );
		}

		// Atomically claim the pending row before firing the resolution action.
		// If a concurrent request already decided it, refuse rather than firing
		// a second resolution for the same proposal.
		if ( ! self::mark_decided( $proposal_id, 'rejected' ) ) {
			return array( 'error' => 'Proposal already processed.' );
		}
		$proposal['status'] = 'rejected';

		// Log rejection.
		$audit = new Audit_Log();
		$audit->log(
			$proposal['agent_id'],
			'proposal_rejected',
			$proposal['tool'],
			array(
				'proposal_id' => $proposal_id,
			)
		);

		// Only a run-backed proposal has a paused run waiting on this decision —
		// a chat-originated proposal (no run_id) has nothing to stop.
		if ( ! empty( $proposal['run_id'] ) ) {
			do_action( 'agent_builder_approval_resolved', 'proposal', $proposal_id, 'rejected', null, $proposal );
		}

		return array(
			'success' => true,
			'message' => 'Proposal rejected.',
		);
	}

	/**
	 * Filterable list of pending, non-expired proposals, newest first.
	 *
	 * This is what the M12 Approvals "Waiting on you" tab and the Approvals
	 * badge count consume.
	 *
	 * @param array $args Optional filters: agent_id (string), run_id (string),
	 *                    created_by (int).
	 * @return array<int, array<string, mixed>> Pending proposals, newest first.
	 */
	public static function pending( array $args = array() ): array {
		global $wpdb;

		$table  = $wpdb->prefix . self::TABLE;
		$where  = array( "status = 'pending'" );
		$values = array();

		if ( ! empty( $args['agent_id'] ) ) {
			$where[]  = 'agent_id = %s';
			$values[] = (string) $args['agent_id'];
		}

		if ( ! empty( $args['run_id'] ) ) {
			$where[]  = 'run_id = %s';
			$values[] = (string) $args['run_id'];
		}

		if ( ! empty( $args['created_by'] ) ) {
			$where[]  = 'created_by = %d';
			$values[] = (int) $args['created_by'];
		}

		// Non-expired only: a null expires_at (never set) is treated as
		// non-expired rather than dropped.
		$where[]  = '( expires_at IS NULL OR expires_at >= %s )';
		$values[] = gmdate( 'Y-m-d H:i:s' );

		$where_sql = implode( ' AND ', $where );
		$query     = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY created_at DESC";

		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Custom table query with dynamic where clause.
		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Custom plugin table.
			$wpdb->prepare( $query, ...$values ),
			ARRAY_A
		);

		$proposals = array();
		foreach ( $rows as $row ) {
			$proposals[] = self::normalize_row( $row );
		}

		return $proposals;
	}

	/**
	 * Expire stale pending proposals, mirroring Approval_Queue::cleanup_expired().
	 *
	 * The row is preserved and its status flipped to 'expired' (never silently
	 * deleted), so an approval that raced the expiry is still auditable.
	 *
	 * @return int Number of rows marked expired.
	 */
	public static function cleanup_expired(): int {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE;

		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Custom table update.
		return (int) $wpdb->query(
			"UPDATE {$table} SET status = 'expired' WHERE status = 'pending' AND expires_at IS NOT NULL AND expires_at < UTC_TIMESTAMP()" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		);
	}

	/**
	 * Create a backup of a file before modification.
	 *
	 * Delegates to Tool_Helpers::backup_file() which is the single
	 * implementation shared by all tool and proposal write paths.
	 *
	 * @param string $full_path Absolute path to the file.
	 * @return string|null Backup file path or null on failure.
	 */
	public static function backup_file( string $full_path ): ?string {
		return Tool_Helpers::backup_file( $full_path );
	}

	/**
	 * Generate a simple unified diff between two strings.
	 *
	 * @param string $old       Original content.
	 * @param string $new_content New content.
	 * @param string $label_old Label for original (e.g., filename).
	 * @param string $label_new Label for new.
	 * @return string Diff output.
	 */
	public static function generate_diff( string $old, string $new_content, string $label_old = 'original', string $label_new = 'proposed' ): string {
		$old_lines = explode( "\n", $old );
		$new_lines = explode( "\n", $new_content );

		$diff = "--- {$label_old}\n+++ {$label_new}\n";

		// Simple line-based diff using PHP's built-in function.
		$max_old = count( $old_lines );
		$max_new = count( $new_lines );
		$max     = max( $max_old, $max_new );

		$changes = array();
		$i_old   = 0;
		$i_new   = 0;

		// Build a basic diff using xdiff-style output.
		while ( $i_old < $max_old || $i_new < $max_new ) {
			if ( $i_old < $max_old && $i_new < $max_new && $old_lines[ $i_old ] === $new_lines[ $i_new ] ) {
				$changes[] = ' ' . $old_lines[ $i_old ];
				++$i_old;
				++$i_new;
			} elseif ( $i_old < $max_old && ( $i_new >= $max_new || ! in_array( $old_lines[ $i_old ], array_slice( $new_lines, $i_new, 5 ), true ) ) ) {
				$changes[] = '-' . $old_lines[ $i_old ];
				++$i_old;
			} else {
				$changes[] = '+' . $new_lines[ $i_new ];
				++$i_new;
			}
		}

		// Only show changed regions with 3 lines of context.
		$output        = array();
		$context       = 3;
		$in_change     = false;
		$change_start  = -1;
		$changes_count = count( $changes );

		for ( $i = 0; $i < $changes_count; $i++ ) {
			$is_change = ' ' !== $changes[ $i ][0];

			if ( $is_change && ! $in_change ) {
				$in_change    = true;
				$change_start = max( 0, $i - $context );
				// Add context before.
				for ( $j = $change_start; $j < $i; $j++ ) {
					$output[] = $changes[ $j ];
				}
			}

			if ( $is_change ) {
				$output[] = $changes[ $i ];
			} elseif ( $in_change ) {
				$output[] = $changes[ $i ];
				// Check if we should close this hunk.
				$next_change = false;
				$max_j       = min( $i + $context, $changes_count - 1 );
				for ( $j = $i + 1; $j <= $max_j; $j++ ) {
					if ( ' ' !== $changes[ $j ][0] ) {
						$next_change = true;
						break;
					}
				}
				if ( ! $next_change ) {
					$in_change = false;
				}
			}
		}

		$diff .= implode( "\n", $output );

		return $diff;
	}

	/**
	 * Normalize a raw proposals table row into the public proposal shape:
	 * decode params from its JSON storage back to an array and coerce the
	 * numeric user-id columns to int (so callers can compare strictly).
	 *
	 * @param array<string, string> $row Raw ARRAY_A row from $wpdb.
	 * @return array<string, mixed> Normalized proposal.
	 */
	private static function normalize_row( array $row ): array {
		$decoded           = json_decode( (string) ( $row['params'] ?? '' ), true );
		$row['params']     = is_array( $decoded ) ? $decoded : array();
		$row['created_by'] = (int) ( $row['created_by'] ?? 0 );
		return $row;
	}

	/**
	 * Persist a decision (approve/reject) against a proposal row — atomically.
	 *
	 * The conditional UPDATE only flips a still-'pending' row, so of two
	 * concurrent approve()/reject() calls for the same id exactly one wins the
	 * claim and proceeds to its side effects; the loser gets 0 affected rows
	 * and must bail. Stamps the deciding user + timestamp.
	 *
	 * @param string $proposal_id Proposal UUID.
	 * @param string $decision    'approved' or 'rejected'.
	 * @return bool True when this call claimed the pending row (1 affected row).
	 */
	private static function mark_decided( string $proposal_id, string $decision ): bool {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE;
		$sql   = "UPDATE {$table} SET status = %s, decision = %s, decided_by = %d, decided_at = %s WHERE id = %s AND status = 'pending'";

		// Atomic claim: the WHERE guard pins status = 'pending', so only the
		// first of two concurrent requests to reach this UPDATE flips the row
		// (1 affected row) and proceeds; the second sees 0 affected rows and
		// bails, so the underlying tool call can never execute twice for the
		// same proposal.
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Custom table conditional claim.
		$updated = $wpdb->query(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Custom plugin table.
			$wpdb->prepare( $sql, $decision, $decision, get_current_user_id(), gmdate( 'Y-m-d H:i:s' ), $proposal_id )
		);

		return 1 === (int) $updated;
	}
}
