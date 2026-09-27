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
	 * Job_Manager::process_job() will instantiate and run it.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter(
			'agent_builder_job_processors',
			static function ( array $allowed ): array {
				$allowed[] = self::class;
				return $allowed;
			}
		);
	}

	/**
	 * Create and schedule a background job for an agent run.
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
	 * @param array    $request_data      Job input data (run_id, agent_id, prompt, user_id, kind, source_ref, resume?, tool_result?).
	 * @param callable $progress_callback Progress update callback.
	 * @return array Job result data.
	 * @throws \Exception If the run or agent cannot be resolved, or the autonomous task fails to start.
	 */
	public function execute( array $request_data, callable $progress_callback ): array {
		$progress_callback( 5, 'Loading run…' );

		$run_id = (string) ( $request_data['run_id'] ?? '' );
		$run    = Agent_Run::load( $run_id );

		if ( null === $run ) {
			// Fail cleanly: no run row, nothing to resume or finish.
			throw new \Exception( 'Run not found: ' . esc_html( $run_id ) );
		}

		$agent_id = (string) ( $request_data['agent_id'] ?? '' );
		$agent    = \Agentic_Agent_Registry::get_instance()->get_agent_instance( $agent_id );

		if ( null === $agent ) {
			$run->finish( 'failed', array( 'error' => 'Agent not found: ' . $agent_id ) );
			throw new \Exception( 'Agent not found: ' . esc_html( $agent_id ) );
		}

		$prompt = (string) ( $request_data['prompt'] ?? '' );

		// Resuming a waiting run: hand the controller the run id, its saved
		// resume state, and (when present) the resolved tool result so its
		// resume branch takes over. A fresh run needs no options — the
		// controller begins and owns the run's lifecycle itself.
		$options = array();
		if ( isset( $request_data['resume'] ) && is_array( $request_data['resume'] ) ) {
			$options['run_id']       = $run_id;
			$options['resume_state'] = $request_data['resume'];
			if ( isset( $request_data['tool_result'] ) && is_array( $request_data['tool_result'] ) ) {
				$options['tool_result'] = $request_data['tool_result'];
			}
		}

		$progress_callback( 20, 'Running autonomous task…' );

		$controller = new Agent_Controller();
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
	}
}
