<?php
/**
 * Tool: run_prompt_tests
 *
 * Runs a small slice of the prompt-test catalog from inside a chat turn and
 * returns what happened — verdicts, which tools each agent actually reached
 * for, tokens, cost. This is the "judge" half of the self-improvement loop:
 * the agent that maintains the catalog can also watch it run, then reason about
 * the gaps with analyze_prompt_results.
 *
 * Hard-capped, because every prompt is a real paid LLM call made inside someone's
 * chat request. The WP-CLI command (`wp agent prompt-test`) is the right tool for
 * a full sweep.
 *
 * @package    Agent_Builder
 * @subpackage Tools
 * @since      3.4.1
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Agentic\Prompt_Testing\Prompt_Catalog;
use Agentic\Prompt_Testing\Prompt_Test_Runner;
use Agentic\Prompt_Testing\Verdict_Factory;

/**
 * Replay a few catalog prompts and report the results.
 */
class Run_Prompt_Tests extends \Agentic\Tool_Base {

	/**
	 * Most prompts one call may run.
	 *
	 * A chat turn is not the place for a fifty-prompt sweep: it would take
	 * minutes, spend several dollars, and sit inside an HTTP request that will
	 * time out long before it finishes.
	 */
	private const MAX_PROMPTS = 5;

	/**
	 * Hard ceiling on spend per call, whatever was asked for.
	 */
	private const MAX_COST = 1.0;

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'run_prompt_tests';
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return 'Replay up to five prompts from the prompt-test catalog against the bundled agents and report what happened: pass/fail, which tools each agent actually called, whether it stopped for approval, tokens and cost. Makes real, paid LLM calls. Use it to check whether a specific agent still handles its prompts, or to confirm a fix worked. For the whole catalog use the WP-CLI command instead.';
	}

	/**
	 * Get the tool category.
	 *
	 * @return string
	 */
	public function get_category(): string {
		return 'assistant-trainer';
	}

	/**
	 * Get the parameter schema.
	 *
	 * @return array
	 */
	public function get_parameters(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'agent'    => array(
					'type'        => 'string',
					'description' => 'Only run prompts assigned to this agent slug.',
				),
				'ids'      => array(
					'type'        => 'string',
					'description' => 'Comma-separated prompt IDs to run, for example "P07,P12". Takes precedence over agent.',
				),
				'rank'     => array(
					'type'        => 'string',
					'description' => 'Rank range to run, for example "1-5".',
				),
				'limit'    => array(
					'type'        => 'integer',
					'description' => 'How many prompts to run, up to 5. Defaults to 3.',
				),
				'judge'    => array(
					'type'        => 'boolean',
					'description' => 'Also grade each answer 1-5 with the configured provider. Roughly doubles the cost.',
				),
				'max_cost' => array(
					'type'        => 'number',
					'description' => 'Stop once this much has been spent, in USD. Capped at 1.00 regardless.',
				),
			),
		);
	}

	/**
	 * Execute.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array
	 */
	public function execute( array $arguments ): array {
		$denied = \Agentic\Tool_Helpers::deny_unless_admin_user();
		if ( null !== $denied ) {
			return $denied;
		}

		$path = Manage_Prompt_Catalog::catalog_path();

		if ( ! is_readable( $path ) ) {
			return array(
				'error' => 'The prompt catalog is not present on this install — prompt testing is only available on a source checkout.',
			);
		}

		try {
			$catalog = Prompt_Catalog::load( $path );
		} catch ( \RuntimeException $e ) {
			return array( 'error' => $e->getMessage() );
		}

		$limit = (int) ( $arguments['limit'] ?? 3 );
		$limit = max( 1, min( self::MAX_PROMPTS, $limit ) );

		$rows = Prompt_Catalog::filter(
			$catalog['rows'],
			array(
				'id'    => sanitize_text_field( (string) ( $arguments['ids'] ?? '' ) ),
				'agent' => sanitize_text_field( (string) ( $arguments['agent'] ?? '' ) ),
				'rank'  => sanitize_text_field( (string) ( $arguments['rank'] ?? '' ) ),
				'limit' => $limit,
			)
		);

		if ( empty( $rows ) ) {
			return array( 'error' => 'No catalog prompts matched that selection.' );
		}

		$max_cost = (float) ( $arguments['max_cost'] ?? self::MAX_COST );
		$max_cost = $max_cost <= 0 ? self::MAX_COST : min( self::MAX_COST, $max_cost );

		$runner = new Prompt_Test_Runner(
			Verdict_Factory::make( 'assert' ),
			array(
				'mode'     => 'chat',
				'max_cost' => $max_cost,
				'judge'    => ! empty( $arguments['judge'] ),
			)
		);

		$preflight = $runner->preflight();

		if ( ! $preflight['ok'] ) {
			return array(
				'error'   => 'Cannot run prompts right now.',
				'reasons' => $preflight['errors'],
			);
		}

		try {
			$records = $runner->run( $rows );
		} catch ( \RuntimeException $e ) {
			// Re-entrancy guard: a catalog prompt asked an agent to run the tests.
			return array( 'error' => $e->getMessage() );
		}

		$totals = $runner->get_totals();

		return array(
			'success' => true,
			'context' => $preflight['context'],
			'totals'  => array(
				'run'    => $totals['run'],
				'passed' => $totals['pass'],
				'failed' => $totals['fail'],
				'gated'  => $totals['gated'],
				'tokens' => $totals['tokens'],
				'cost'   => round( (float) $totals['cost'], 4 ),
			),
			'results' => array_map(
				static fn( array $r ): array => array(
					'id'                   => $r['id'],
					'agent'                => $r['assigned_agent'],
					'answered_by'          => $r['actual_agent'],
					'prompt'               => $r['prompt'],
					'verdict'              => $r['verdict'],
					'why'                  => $r['verdict_reasons'],
					'expected_tools'       => $r['expect_tools'],
					'tools_called'         => $r['tools_used'],
					'stopped_for_approval' => (bool) $r['gated'],
					'iterations'           => $r['iterations'],
					'tokens'               => $r['tokens'],
					'cost'                 => round( (float) $r['cost'], 4 ),
					'judge_score'          => $r['judge_score'],
					'judge_comment'        => $r['judge_comment'],
					'answer_excerpt'       => mb_substr( (string) $r['response'], 0, 600 ),
				),
				$records
			),
			'note'    => 'A verdict of PASS with stopped_for_approval true means the agent correctly paused for permission — that is the safety model working, not a failure.',
		);
	}

	/**
	 * MCP/Abilities annotations.
	 *
	 * @return array
	 */
	public function get_annotations(): array {
		return array(
			'readonly'    => false,
			'destructive' => false,
		);
	}
}

return new Run_Prompt_Tests();
