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
 */
class Agent_Proposals {

	/**
	 * Transient prefix for pending proposals.
	 */
	private const TRANSIENT_PREFIX = 'agentic_proposal_';

	/**
	 * Proposal expiry in seconds (1 hour).
	 */
	private const EXPIRY = 3600;

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
	 * @return array Proposal data with ID.
	 */
	public static function create( string $tool_name, array $params, string $agent_id, string $description, string $diff = '', string $run_id = '', int $created_by = 0 ): array {
		$proposal_id = wp_generate_uuid4();

		$proposal = array(
			'id'          => $proposal_id,
			'tool'        => $tool_name,
			'params'      => $params,
			'agent_id'    => $agent_id,
			'description' => $description,
			'diff'        => $diff,
			'status'      => 'pending',
			'created_at'  => gmdate( 'Y-m-d H:i:s' ),
			'created_by'  => $created_by > 0 ? $created_by : get_current_user_id(),
			'run_id'      => $run_id,
		);

		set_transient( self::TRANSIENT_PREFIX . $proposal_id, $proposal, self::EXPIRY );

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
	 * Get a pending proposal by ID.
	 *
	 * @param string $proposal_id Proposal UUID.
	 * @return array|null Proposal data or null if not found/expired.
	 */
	public static function get( string $proposal_id ): ?array {
		$proposal = get_transient( self::TRANSIENT_PREFIX . $proposal_id );
		return is_array( $proposal ) ? $proposal : null;
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

		// Mark as approved before executing.
		$proposal['status']      = 'approved';
		$proposal['approved_at'] = gmdate( 'Y-m-d H:i:s' );
		$proposal['approved_by'] = get_current_user_id();
		set_transient( self::TRANSIENT_PREFIX . $proposal_id, $proposal, self::EXPIRY );

		// Execute the change via Tool_Loader. This path bypasses Tool_Executor
		// entirely, so the calling-agent context (needed by tools like
		// delegate_to_agent to detect delegation cycles) must be set explicitly.
		Tool_Base::set_calling_agent( (string) ( $proposal['agent_id'] ?? '' ) );

		$result = Tool_Loader::get_instance()->execute(
			$proposal['tool'],
			$proposal['params']
		);

		if ( null === $result ) {
			$result = array( 'error' => "Unknown tool: {$proposal['tool']}" );
		}

		// Log to operations ledger (confirm-path tools bypass Agent_Controller).
		if ( ! isset( $result['error'] ) ) {
			$queue = new Approval_Queue();
			$queue->log_executed(
				$proposal['agent_id'],
				$proposal['tool'],
				$proposal['params'],
				'medium',
				Audit_Log::get_mode_context(),
				'chat'
			);
		}

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

		// Clean up transient.
		delete_transient( self::TRANSIENT_PREFIX . $proposal_id );

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

		// Clean up.
		delete_transient( self::TRANSIENT_PREFIX . $proposal_id );

		return array(
			'success' => true,
			'message' => 'Proposal rejected.',
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
	 * @param string $old     Original content.
	 * @param string $new_content     New content.
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
}
