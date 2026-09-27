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
	 * run_id doesn't load, or the run can't be claimed out of 'waiting'
	 * (already resumed/resolved by something else, or a concurrent
	 * resolution for the same run won the claim first).
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

		// Atomically claim the run out of 'waiting' on this specific item:
		// two concurrent resolutions for the same run must not both
		// dispatch/finish it, and a stale/duplicate resolution for a wait
		// the run has already moved past must not hijack a newer one.
		if ( ! $run->claim_waiting( $type, (string) $id ) ) {
			return;
		}

		do_action( 'agent_builder_run_waiting', $run );

		// Approval-queue rows and proposal rows name the tool differently
		// ('action' vs 'tool').
		$tool_name = (string) ( $row['action'] ?? ( $row['tool'] ?? 'action' ) );

		if ( 'approved' === $decision ) {
			$extra = array(
				'resume' => $run->resume_state(),
			);

			// Only a real execution result becomes a tool message on resume —
			// fabricating one when $result is null (a real approve can still
			// carry no execution result) would put a bogus tool reply in the
			// model's transcript.
			if ( is_array( $result ) ) {
				// The controller's resume branch reads the tool name from
				// tool_result['tool'] to reconstruct the tool message —
				// neither execute_approved_action() nor
				// Agent_Proposals::approve()'s return value carries that key,
				// so it must be added here.
				$result['tool']       = $tool_name;
				$extra['tool_result'] = $result;
			}

			$job_id = Agent_Task_Job_Processor::dispatch( $run, $extra );

			if ( '' === $job_id ) {
				// claim_waiting() already flipped the run to 'running'; a
				// refused dispatch (e.g. Emergency Stop) must not leave it
				// stuck there forever with nothing left to resume it.
				$run->finish(
					'failed',
					array( 'error' => "Could not resume: dispatching the continuation for {$tool_name} was refused." )
				);
			}

			return;
		}

		// Rejected: the run should stop, not resume.
		$run->finish( 'completed', array( 'text' => "Stopped: you denied {$tool_name}" ) );
	}
}
