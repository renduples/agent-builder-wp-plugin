<?php
/**
 * Run Resumer
 *
 * Bridges a human's approve/reject decision back to the autonomous run that
 * paused for it. Without this, an approved or rejected item just sits in the
 * queue/transient with nothing telling the waiting run to continue or stop.
 *
 * @package    Agent_Builder
 * @subpackage Includes
 * @author     Agent Builder Team <support@agentic-plugin.com>
 * @license    GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 * @link       https://agentic-plugin.com
 * @since      4.1.0
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
 * Listens for `agent_builder_approval_resolved` and either dispatches a
 * resume job for the paused run (approve) or stops it (reject).
 */
class Run_Resumer {

	/**
	 * Hook this class into `agent_builder_approval_resolved`.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'agent_builder_approval_resolved', array( self::class, 'handle' ), 10, 5 );
	}

	/**
	 * Handle a resolved approval/proposal.
	 *
	 * A clean no-op when: the row carries no run_id (a chat-originated
	 * approval/proposal has nothing to resume — callers are expected to
	 * only fire the action when run_id is set, but this is defensive), the
	 * run_id doesn't load, or the run is no longer 'waiting' (already
	 * resumed or resolved by something else).
	 *
	 * @param string     $type     'approval' or 'proposal'.
	 * @param int|string $id       Approval queue row id, or proposal UUID.
	 * @param string     $decision 'approved' or 'rejected'.
	 * @param array|null $result   Execution result on approve; null on reject.
	 * @param array      $row      The approval/proposal row (has run_id).
	 * @return void
	 */
	public static function handle( string $type, $id, string $decision, ?array $result, array $row ): void {
		$run_id = (string) ( $row['run_id'] ?? '' );
		if ( '' === $run_id ) {
			return;
		}

		$run = Agent_Run::load( $run_id );
		if ( null === $run ) {
			return;
		}

		if ( 'waiting' !== $run->to_array()['status'] ) {
			return;
		}

		do_action( 'agent_builder_run_waiting', $run );

		if ( 'approved' === $decision ) {
			$extra = array( 'resume' => $run->resume_state() );
			if ( is_array( $result ) ) {
				$extra['tool_result'] = $result;
			}

			Agent_Task_Job_Processor::dispatch( $run, $extra );
			return;
		}

		// Rejected: the run should stop, not resume. Approval-queue rows and
		// proposal rows name the tool differently ('action' vs 'tool').
		$tool_name = (string) ( $row['action'] ?? ( $row['tool'] ?? 'action' ) );
		$run->finish( 'completed', array( 'text' => "Stopped: you denied {$tool_name}" ) );

		do_action( 'agent_builder_run_finished', $run );
	}
}
