<?php
/**
 * Agent Task Job Processor
 *
 * Runs an autonomous agent task as a background job. Backs an Agent_Run with a
 * Job_Manager job, loading the run by id and delegating to the autonomous task
 * path in Agent_Controller.
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
 * Agent Task Job Processor.
 *
 * Executes an autonomous agent task from a background job. The request payload
 * carries the run context; execution rehydrates the Agent_Run, resolves the
 * agent, and hands off to Agent_Controller::run_autonomous_task().
 */
class Agent_Task_Job_Processor implements Job_Processor_Interface {

	/**
	 * Register this processor on the Job_Manager allowlist so
	 * Job_Manager::process_job() will instantiate and run it, and listen for
	 * the elapsed-time continuation seam so a run that hands off does not stop
	 * forever.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter(
			'agent_builder_job_processors',
			static function ( $allowed ): array {
				// An earlier-priority callback may have returned a non-array;
				// cast it back so appending self::class can't throw a TypeError
				// from an `array` type hint before Job_Manager's own fallback runs.
				// Restore the built-in default allowlist entry rather than an
				// empty array, so Agent_Builder_Job_Processor's own jobs are not
				// dropped and later rejected as "processor not allowed".
				if ( ! is_array( $allowed ) ) {
					$allowed = array( Agent_Builder_Job_Processor::class );
				}
				$allowed[] = self::class;
				return $allowed;
			}
		);

		add_action( 'agent_builder_run_needs_continuation', array( self::class, 'handle_continuation' ), 10, 1 );
	}

	/**
	 * Resume a run handed off by the ~70% elapsed-time guard.
	 *
	 * The controller fires `agent_builder_run_needs_continuation` after
	 * mark_continuing(); this listener atomically claims the run out of its
	 * non-terminal hand-off state and dispatches a fresh job that resumes it
	 * via run_autonomous_task()'s resume branch. Without it, a run persisted
	 * 'continuing' would stop forever, since nothing else listens.
	 *
	 * @param string $run_id Run id to resume.
	 * @return void
	 */
	public static function handle_continuation( string $run_id ): void {
		$run = Agent_Run::load( $run_id );
		if ( null === $run ) {
			return;
		}

		// Atomically claim the run out of 'continuing'/'waiting' before
		// dispatching: a duplicate continuation (or a retry racing the
		// original) must not enqueue a second job for the same run.
		if ( ! $run->claim_resume() ) {
			return;
		}

		$job_id = self::dispatch( $run, array( 'resume' => $run->resume_state() ) );

		if ( '' === $job_id ) {
			// claim_resume() already flipped the run to 'running'; a refused
			// dispatch (e.g. Emergency Stop) must not leave it stuck there
			// with nothing left to resume it.
			$run->finish( 'failed', array( 'error' => 'Could not continue: dispatching the continuation job was refused.' ) );
		}
	}

	/**
	 * Create and schedule a background job for an agent run.
	 *
	 * Callers that create the run from a web/REST request (the M11 POST /runs
	 * path) must build it with Agent_Run::create_queued() rather than begin():
	 * begin() arms a shutdown guard that aborts the run when the creating
	 * request ends, before the WP-Cron worker adopts it. A resumed run (a
	 * continuation or approval resolution) is dispatched from an already-claimed
	 * run and needs no such change.
	 *
	 * @param Agent_Run $run   Run to dispatch.
	 * @param array     $extra Optional extra payload keys (resume, tool_result,
	 *                         or any override for the base request shape).
	 * @return string Created job id (empty if job creation was refused).
	 */
	public static function dispatch( Agent_Run $run, array $extra = array() ): string {
		$data = $run->to_array();

		$request = array(
			'run_id'     => $run->get_run_id(),
			'agent_id'   => (string) $data['root_agent'],
			'prompt'     => (string) $data['task_text'],
			'user_id'    => (int) $data['user_id'],
			'kind'       => (string) $data['kind'],
			'source_ref' => (string) $data['source_ref'],
		);

		// Extra keys (resume, tool_result, …) override or extend the payload.
		$request = array_merge( $request, $extra );

		return Job_Manager::create_job(
			array(
				'user_id'      => (int) $data['user_id'],
				'agent_id'     => (string) $data['root_agent'],
				'request_data' => $request,
				'processor'    => self::class,
			)
		);
	}

	/**
	 * Execute the job.
	 *
	 * @param array           $request_data      Job input data (run_id, agent_id, prompt, user_id, kind, source_ref, resume?, tool_result?).
	 * @param callable        $progress_callback Progress update callback.
	 * @param Agent_Controller|null $controller Optional controller to inject (tests); a
	 *                                          production controller is created when omitted.
	 * @return array Job result data.
	 * @throws \Exception If the run or agent cannot be resolved, or the autonomous task fails to start.
	 */
	public function execute( array $request_data, callable $progress_callback, ?Agent_Controller $controller = null ): array {
		$progress_callback( 5, 'Loading run…' );

		$run_id = (string) ( $request_data['run_id'] ?? '' );
		$run    = Agent_Run::load( $run_id );

		if ( null === $run ) {
			// Fail cleanly: no run row, nothing to resume or finish.
			throw new \Exception( 'Run not found: ' . esc_html( $run_id ) );
		}

		// Refuse to run when the owner is gone or no longer authorised: this job
		// will set the current user to the owner for the task's duration, so a
		// deleted or de-privileged owner must fail the run here rather than be
		// silently impersonated. Admins always hold this capability (the dynamic
		// user_has_cap filter grants it to manage_options holders), so the normal
		// administrator-owned background run passes unchanged.
		$owner_id = (int) $run->get_user_id();
		$owner    = get_userdata( $owner_id );
		if ( ! $owner || ! user_can( $owner_id, 'agent_builder_run_tasks_manually' ) ) {
			$message = ! $owner
				? sprintf( 'Run owner (user %d) no longer exists; the run cannot be executed.', $owner_id )
				: sprintf( 'Run owner (user %d) lacks the agent_builder_run_tasks_manually capability.', $owner_id );
			$run->finish( 'failed', array( 'error' => $message ) );
			throw new \Exception( $message );
		}

		$agent_id = (string) ( $request_data['agent_id'] ?? '' );
		$agent    = \Agentic_Agent_Registry::get_instance()->get_agent_instance( $agent_id );

		if ( null === $agent ) {
			$run->finish( 'failed', array( 'error' => 'Agent not found: ' . $agent_id ) );
			throw new \Exception( 'Agent not found: ' . esc_html( $agent_id ) );
		}

		$prompt = (string) ( $request_data['prompt'] ?? '' );

		// Hand the controller the dispatched run id in every case — fresh or
		// resume. A fresh job must adopt the run created with create_queued()
		// (the controller's fresh branch would otherwise begin() a second run
		// and strand the dispatched row at 'queued' forever). The assigning
		// user is carried too, so tool grants, proposal attribution and
		// user_can() checks act as that admin even under cron (where
		// get_current_user_id() is 0).
		$options = array(
			'run_id'  => $run_id,
			'user_id' => (int) ( $request_data['user_id'] ?? 0 ),
		);
		if ( isset( $request_data['resume'] ) && is_array( $request_data['resume'] ) ) {
			$options['resume_state'] = $request_data['resume'];
			if ( isset( $request_data['tool_result'] ) && is_array( $request_data['tool_result'] ) ) {
				$options['tool_result'] = $request_data['tool_result'];
			}
		}

		$progress_callback( 20, 'Running autonomous task…' );

		// Run as the owner for the task's duration: tool grants, proposal
		// attribution and user_can() checks inside the controller must act as the
		// assigning user even under cron (where get_current_user_id() is 0). The
		// previous user — usually 0 in a cron worker, but never assumed — is
		// restored in every path via finally.
		$previous_user_id = get_current_user_id();
		wp_set_current_user( $owner_id );

		try {
			$controller = $controller ?? new Agent_Controller();
			$result     = $controller->run_autonomous_task( $agent, $prompt, $run_id, $options );

			if ( null === $result ) {
				$run->finish( 'failed', array( 'error' => 'Autonomous task failed to start.' ) );
				throw new \Exception( 'Autonomous task failed to start.' );
			}

			// Only a non-terminal 'error' still needs finishing here: 'completed',
			// 'cancelled' and 'aborted' were already finished by the controller, and
			// 'waiting' / 'continuing' must stay in their hand-off state. A
			// 'guard_rejected' error means the resume attempt itself was refused
			// (run not found, wrong status, or agent mismatch) — that is not this
			// job's run to finalize: the target run may still be legitimately
			// waiting/continuing, and forcing it to 'failed' here would destroy
			// that state out from under whatever holds it.
			if ( 'error' === (string) ( $result['status'] ?? 'completed' ) && empty( $result['guard_rejected'] ) ) {
				$run->finish( 'failed', array( 'error' => (string) ( $result['response'] ?? 'Autonomous task errored.' ) ) );
			}

			$progress_callback( 100, 'Completed' );

			return $result;
		} finally {
			wp_set_current_user( $previous_user_id );
		}
	}
}
