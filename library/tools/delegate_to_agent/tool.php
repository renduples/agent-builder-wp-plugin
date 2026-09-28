<?php
/**
 * Tool: delegate_to_agent
 *
 * Lets a team-lead agent hand a subtask to another active agent and get its
 * result back. Delegation is sequential and in-process; depth, fan-out, and
 * cost/token budgets are enforced by Agent_Run. Parallel, durable, and visual
 * workflow orchestration are reserved for Agent Builder Pro.
 *
 * @package    Agent_Builder
 * @subpackage Tools
 * @since      2.11.0
 *
 * php version 8.1
 */

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Agentic\Tool_Base;
use Agentic\Agent_Run;
use Agentic\Agent_Controller;
use Agentic\Agent_Permissions;
use Agentic\Audit_Log;

class Delegate_To_Agent extends Tool_Base {

	public function get_name(): string {
		return 'delegate_to_agent';
	}

	public function get_description(): string {
		return 'Delegate a focused subtask to another active agent and return its result. '
			. 'Use this to coordinate a team: pick the specialist best suited to the subtask, '
			. 'give it a clear, self-contained instruction, and incorporate its answer into your own work. '
			. 'Delegation runs the target agent autonomously, so be specific about the desired output.';
	}

	public function get_category(): string {
		return 'orchestration';
	}

	public function get_parameters(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'agent' => array(
					'type'        => 'string',
					'description' => 'Slug of the active agent to delegate to (see the [TEAM] roster in your system prompt).',
				),
				'task'  => array(
					'type'        => 'string',
					'description' => 'A clear, self-contained instruction for the delegated agent, including any context it needs.',
				),
			),
			'required'   => array( 'agent', 'task' ),
		);
	}

	public function get_risk_level(): string {
		return \Agentic\Risk_Level::MEDIUM;
	}

	public function get_annotations(): array {
		return array(
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		);
	}

	public function execute( array $args ): array {
		$target = sanitize_key( $args['agent'] ?? '' );
		$task   = trim( (string) ( $args['task'] ?? '' ) );
		$caller = $this->get_calling_agent_slug();

		if ( '' === $target || '' === $task ) {
			return array( 'error' => 'Both "agent" (target slug) and "task" (instruction) are required.' );
		}

		// Establish the shared run context. The outermost delegate call owns the
		// run lifecycle; nested calls reuse the same run so budgets accumulate.
		$created = false;
		$run     = Agent_Run::current();
		if ( ! $run instanceof Agent_Run ) {
			$run     = Agent_Run::begin( '' !== $caller ? $caller : $target, array( 'kind' => 'delegation' ) );
			$created = true;
		}

		$result = $this->run_delegation( $run, $caller, $target, $task );

		if ( $created ) {
			$run->finish( isset( $result['error'] ) ? 'error' : 'completed' );
		} else {
			$run->persist_progress();
		}

		return $result;
	}

	/**
	 * Validate budgets, run the target agent, and accumulate usage.
	 *
	 * @param Agent_Run $run    Shared run context.
	 * @param string    $caller Calling agent slug.
	 * @param string    $target Target agent slug.
	 * @param string    $task   Instruction for the target agent.
	 * @return array<string, mixed>
	 */
	private function run_delegation( Agent_Run $run, string $caller, string $target, string $task ): array {
		if ( $target === $caller ) {
			return array( 'error' => 'An agent cannot delegate to itself.' );
		}

		if ( $run->is_in_path( $target ) ) {
			return array( 'error' => sprintf( 'Delegation cycle blocked: "%s" is already active in this run.', $target ) );
		}

		if ( ! $run->can_delegate() ) {
			$reason = $run->blocked_reason();
			$this->audit_delegation( $run, $caller, $target, 'blocked', 0, 0.0, $reason );
			return array( 'error' => sprintf( 'Delegation blocked: %s.', $reason ) );
		}

		$registry = \Agentic_Agent_Registry::get_instance();
		if ( ! $registry->is_agent_active( $target ) ) {
			return array( 'error' => sprintf( 'Agent "%s" is not active. Activate it first or choose another from your team.', $target ) );
		}

		$target_instance = $registry->get_agent_instance( $target );
		if ( ! $target_instance instanceof \Agentic\Agent_Base ) {
			return array( 'error' => sprintf( 'Agent "%s" could not be loaded.', $target ) );
		}

		// Snapshot static execution context so the nested autonomous run cannot
		// leak its mode/audit/caller state back into the calling agent's loop.
		$prev_mode  = Agent_Permissions::get_mode_override();
		$prev_audit = Audit_Log::get_mode_context();

		$run->enter( $target );

		try {
			$controller = new Agent_Controller();
			$response   = $controller->run_autonomous_task( $target_instance, $task, 'delegation_' . $run->get_run_id() );
		} finally {
			$run->leave();
			Agent_Permissions::set_mode_override( $prev_mode );
			Audit_Log::set_mode_context( $prev_audit );
			Tool_Base::set_calling_agent( $caller );
		}

		if ( null === $response ) {
			$this->audit_delegation( $run, $caller, $target, 'failed', 0, 0.0, 'LLM not configured or run failed' );
			return array( 'error' => sprintf( 'Agent "%s" could not complete the task (LLM not configured or the run failed).', $target ) );
		}

		$tokens = (int) ( $response['tokens_used'] ?? 0 );
		$cost   = (float) ( $response['cost'] ?? 0 );
		$run->add_usage( $tokens, $cost );

		$this->audit_delegation( $run, $caller, $target, 'completed', $tokens, $cost, '' );

		return array(
			'success'     => true,
			'agent'       => $target,
			'response'    => (string) ( $response['response'] ?? '' ),
			'tools_used'  => $response['tools_used'] ?? array(),
			'tokens_used' => $tokens,
			'cost'        => round( $cost, 6 ),
			'run'         => $run->summary(),
		);
	}

	/**
	 * Write a delegation audit entry with full lineage.
	 *
	 * @param Agent_Run $run    Run context.
	 * @param string    $caller Calling agent.
	 * @param string    $target Target agent.
	 * @param string    $status Outcome status.
	 * @param int       $tokens Tokens used.
	 * @param float     $cost   Estimated cost.
	 * @param string    $note   Optional reason/note.
	 * @return void
	 */
	private function audit_delegation( Agent_Run $run, string $caller, string $target, string $status, int $tokens, float $cost, string $note ): void {
		$audit = new Audit_Log();
		$audit->log(
			'' !== $caller ? $caller : $run->summary()['root_agent'],
			'delegate_to_agent',
			'agent',
			array(
				'run_id' => $run->get_run_id(),
				'target' => $target,
				'depth'  => $run->get_depth(),
				'status' => $status,
				'note'   => $note,
			),
			$note,
			$tokens,
			$cost
		);
	}
}

return new Delegate_To_Agent();
