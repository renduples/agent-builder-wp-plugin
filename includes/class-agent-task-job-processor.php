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

		$run->make_current();

		$agent_id = (string) ( $request_data['agent_id'] ?? '' );
		$agent    = \Agentic_Agent_Registry::get_instance()->get_agent_instance( $agent_id );

		if ( null === $agent ) {
			$run->finish( 'failed', array( 'error' => 'Agent not found: ' . $agent_id ) );
			throw new \Exception( 'Agent not found: ' . esc_html( $agent_id ) );
		}

		$prompt = (string) ( $request_data['prompt'] ?? '' );

		$progress_callback( 20, 'Running autonomous task…' );

		$controller = new Agent_Controller();
		$result     = $controller->run_autonomous_task( $agent, $prompt, $run_id );

		if ( null === $result ) {
			$run->finish( 'failed', array( 'error' => 'Autonomous task failed to start.' ) );
			throw new \Exception( 'Autonomous task failed to start.' );
		}

		$run->finish(
			'completed',
			array(
				'text' => (string) ( $result['response'] ?? '' ),
			)
		);

		$progress_callback( 100, 'Completed' );

		return $result;
	}
}
