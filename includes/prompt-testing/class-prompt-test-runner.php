<?php
/**
 * Prompt-test runner.
 *
 * Replays catalog prompts against the bundled agents through the same code path
 * a real chat request uses, and records what happened. The WP-CLI command and
 * the self-improvement tools are both thin wrappers around this class, so it
 * reports progress through a callback rather than printing anything itself.
 *
 * @package    Agent_Builder
 * @subpackage Prompt_Testing
 * @since      3.4.1
 *
 * php version 8.1
 */

declare( strict_types=1 );

namespace Agentic\Prompt_Testing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Executes prompt cases and builds result records.
 */
final class Prompt_Test_Runner {

	public const VERDICT_PASS    = 'PASS';
	public const VERDICT_FAIL    = 'FAIL';
	public const VERDICT_SKIP    = 'SKIP';
	public const VERDICT_ERROR   = 'ERROR';
	public const VERDICT_INFO    = 'INFO';
	public const VERDICT_NOT_RUN = 'NOT RUN';

	/**
	 * Guards against a prompt test recursively starting another prompt test.
	 *
	 * The self-improvement tools let an agent run the suite from inside a chat
	 * turn. Without this, a catalog prompt that asked an agent to "run the
	 * prompt tests" would fork a run inside a run, and the cost cap of the outer
	 * run would not see the inner run's spend.
	 *
	 * @var bool
	 */
	private static bool $running = false;

	/**
	 * Verdict strategy.
	 *
	 * @var Verdict_Strategy
	 */
	private Verdict_Strategy $verdict;

	/**
	 * Run options.
	 *
	 * @var array<string, mixed>
	 */
	private array $options;

	/**
	 * Progress callback: fn( string $event, array $data ): void.
	 *
	 * @var callable|null
	 */
	private $logger;

	/**
	 * Running totals.
	 *
	 * @var array<string, float|int>
	 */
	private array $totals = array();

	/**
	 * Constructor.
	 *
	 * @param Verdict_Strategy     $verdict Verdict strategy.
	 * @param array<string, mixed> $options Run options.
	 * @param callable|null        $logger  Progress callback.
	 */
	public function __construct( Verdict_Strategy $verdict, array $options = array(), ?callable $logger = null ) {
		$this->verdict = $verdict;
		$this->logger  = $logger;
		$this->options = wp_parse_args(
			$options,
			array(
				'mode'               => 'chat',
				'max_cost'           => 2.0,
				'max_iterations'     => 10,
				'timeout'            => 0,
				'delay'              => 0,
				'judge'              => false,
				'allow_skips'        => false,
				'max_response_chars' => 2000,
			)
		);

		$this->reset_totals();
	}

	/**
	 * Zero the running totals.
	 */
	private function reset_totals(): void {
		$this->totals = array(
			'run'          => 0,
			'pass'         => 0,
			'fail'         => 0,
			'skip'         => 0,
			'error'        => 0,
			'info'         => 0,
			'not_run'      => 0,
			'gated'        => 0,
			'tokens'       => 0,
			'cost'         => 0.0,
			'judge_tokens' => 0,
			'judge_cost'   => 0.0,
			'duration_ms'  => 0.0,
		);
	}

	/**
	 * Check that the site can actually run prompts before spending anything.
	 *
	 * Each of these would otherwise make all fifty prompts fail in the same
	 * confusing way, several minutes and several dollars into a run.
	 *
	 * @return array{ok: bool, errors: string[], context: array<string, mixed>}
	 */
	public function preflight(): array {
		$errors = array();

		if ( class_exists( '\\Agentic\\Emergency_Stop' ) && \Agentic\Emergency_Stop::is_active() ) {
			$errors[] = 'Emergency Stop is active — every agent call would be blocked. Turn it off in Settings → Security first.';
		}

		$llm = new \Agentic\LLM_Client();

		if ( ! $llm->is_configured() ) {
			$errors[] = 'No LLM provider is configured. Set a provider and API key in Settings → APIs first.';
		}

		$agent_mode = (string) get_option( 'agent_builder_agent_mode', 'supervised' );

		return array(
			'ok'      => empty( $errors ),
			'errors'  => $errors,
			'context' => array(
				'provider'       => $llm->get_provider(),
				'model'          => $llm->get_model(),
				'agent_mode'     => $agent_mode,
				'site_url'       => home_url(),
				'wp_version'     => get_bloginfo( 'version' ),
				'php_version'    => PHP_VERSION,
				'plugin_version' => defined( 'AGENT_BUILDER_VERSION' ) ? AGENT_BUILDER_VERSION : '',
			),
		);
	}

	/**
	 * Install the filters that make a run reproducible.
	 *
	 * Returns a callable that removes them again, so a caller running inside a
	 * web request (the self-improvement tools) does not leave them installed for
	 * the rest of the request.
	 *
	 * @return callable(): void
	 */
	public function install_filters(): callable {
		$timeout        = (int) $this->options['timeout'];
		$max_iterations = (int) $this->options['max_iterations'];

		// Without this the harness silently lies. Agent_Controller::chat() consults
		// Response_Cache on both the read and the write side, so a second run of
		// the same prompt would replay the first run's answer — same tokens, same
		// tools, no LLM call — and look like a pass. Note the cache key is
		// md5( message | agent_id | role_bucket ): the session id is NOT part of
		// it, so varying the session id does nothing at all.
		add_filter( 'agentic_should_cache_response', '__return_false', PHP_INT_MAX );

		// Recall of earlier runs' memories would make run N+1 incomparable to run N.
		add_filter( 'agent_builder_local_memory_enabled', '__return_false', PHP_INT_MAX );

		$iteration_filter = static function () use ( $max_iterations ): int {
			return $max_iterations;
		};
		add_filter( 'agentic_max_tool_iterations', $iteration_filter, PHP_INT_MAX );

		$timeout_filter = null;
		if ( $timeout > 0 ) {
			$timeout_filter = static function () use ( $timeout ): int {
				return $timeout;
			};
			add_filter( 'agentic_llm_request_timeout', $timeout_filter, PHP_INT_MAX );
		}

		return static function () use ( $iteration_filter, $timeout_filter ): void {
			remove_filter( 'agentic_should_cache_response', '__return_false', PHP_INT_MAX );
			remove_filter( 'agent_builder_local_memory_enabled', '__return_false', PHP_INT_MAX );
			remove_filter( 'agentic_max_tool_iterations', $iteration_filter, PHP_INT_MAX );
			if ( null !== $timeout_filter ) {
				remove_filter( 'agentic_llm_request_timeout', $timeout_filter, PHP_INT_MAX );
			}
		};
	}

	/**
	 * Run a set of catalog rows.
	 *
	 * @param array<int, array<string, mixed>> $rows      Catalog rows.
	 * @param callable|null                    $on_result Called with each record as it completes.
	 * @return array<int, array<string, mixed>> Result records.
	 */
	public function run( array $rows, ?callable $on_result = null ): array {
		if ( self::$running ) {
			throw new \RuntimeException( 'A prompt-test run is already in progress in this process.' );
		}

		self::$running = true;
		$this->reset_totals();

		$remove_filters = $this->install_filters();
		$records        = array();
		$max_cost       = (float) $this->options['max_cost'];
		$delay          = (int) $this->options['delay'];
		$capped         = false;

		try {
			foreach ( $rows as $row ) {
				if ( $capped ) {
					$record = $this->build_stub_record( $row, self::VERDICT_NOT_RUN, 'stopped by the cost cap' );
				} elseif ( $max_cost > 0 && $this->totals['cost'] >= $max_cost ) {
					$capped = true;
					$this->log(
						'cost_cap',
						array(
							'spent' => $this->totals['cost'],
							'cap'   => $max_cost,
						)
					);
					$record = $this->build_stub_record( $row, self::VERDICT_NOT_RUN, 'stopped by the cost cap' );
				} else {
					$record = $this->run_one( $row );
				}

				$this->tally( $record );
				$records[] = $record;

				if ( null !== $on_result ) {
					$on_result( $record, $records );
				}

				$this->log( 'result', $record );

				if ( $delay > 0 && ! $capped ) {
					sleep( $delay );
				}
			}
		} finally {
			$remove_filters();
			self::$running = false;
		}

		return $records;
	}

	/**
	 * Run a single catalog row, isolating every failure mode.
	 *
	 * @param array<string, mixed> $row Catalog row.
	 * @return array<string, mixed>
	 */
	private function run_one( array $row ): array {
		$slug     = (string) $row['agent'];
		$registry = \Agentic_Agent_Registry::get_instance();
		$agent    = $registry->get_agent_instance( $slug );

		if ( ! $agent ) {
			return $this->build_stub_record(
				$row,
				self::VERDICT_SKIP,
				sprintf( 'agent "%s" is not installed or not active', $slug )
			);
		}

		$autonomous = 'autonomous' === $this->options['mode'];

		// run_autonomous_task() deliberately bypasses capability checks; chat()
		// does not, so pre-check rather than letting set_agent() fail with a
		// generic false that says nothing about which capability was missing.
		if ( ! $autonomous ) {
			$missing = $this->missing_capabilities( $agent );

			if ( ! empty( $missing ) ) {
				return $this->build_stub_record(
					$row,
					self::VERDICT_SKIP,
					sprintf( 'current user lacks: %s', implode( ', ', $missing ) )
				);
			}
		}

		$queue          = class_exists( '\\Agentic\\Approval_Queue' ) ? new \Agentic\Approval_Queue() : null;
		$pending_before = $queue ? $queue->get_pending_count() : 0;
		$started        = hrtime( true );

		$this->log( 'start', $row );

		try {
			// A fresh controller per prompt: it carries mutable per-request state
			// (selected agent, in-place model overrides for vision) that would
			// otherwise bleed from one agent's run into the next.
			$controller = new \Agentic\Agent_Controller();
			$controller->set_invocation_context( 'cli' );

			$session_id = sprintf( 'prompttest_%s_%s', strtolower( (string) $row['id'] ), wp_generate_password( 8, false ) );

			if ( $autonomous ) {
				$result = $controller->run_autonomous_task( $agent, (string) $row['prompt'], $session_id );

				if ( null === $result ) {
					return $this->build_stub_record(
						$row,
						self::VERDICT_FAIL,
						'run_autonomous_task() returned null — the provider errored or is not configured',
						$this->elapsed_ms( $started )
					);
				}
			} else {
				$result = $controller->chat(
					(string) $row['prompt'],
					array(),
					get_current_user_id(),
					$session_id,
					$slug,
					null,
					'',
					'cli'
				);
			}

			$pending_after = $queue ? $queue->get_pending_count() : 0;

			return $this->build_record( $row, $result, $started, $pending_after > $pending_before );
		} catch ( \Throwable $e ) {
			return $this->build_error_record( $row, $e, $started );
		} finally {
			// Agent_Controller::chat() clears its own permission override, but
			// run_autonomous_task() does not, and neither clears the audit mode
			// context. In a web request that is invisible; across fifty prompts in
			// one process it would silently relax the risk gate for every prompt
			// after the first autonomous one.
			$this->reset_global_state();
		}
	}

	/**
	 * Reset process-global agent state left behind by a run.
	 */
	private function reset_global_state(): void {
		if ( class_exists( '\\Agentic\\Agent_Permissions' ) ) {
			\Agentic\Agent_Permissions::set_mode_override( null );
		}

		if ( class_exists( '\\Agentic\\Audit_Log' ) && method_exists( '\\Agentic\\Audit_Log', 'set_mode_context' ) ) {
			\Agentic\Audit_Log::set_mode_context( '' );
		}

		if ( class_exists( '\\Agentic\\Tool_Base' ) && method_exists( '\\Agentic\\Tool_Base', 'set_calling_agent' ) ) {
			\Agentic\Tool_Base::set_calling_agent( '' );
		}

		// A delegation that threw mid-flight never reaches its own finish(), which
		// would leave prompt N+1 inheriting N's token and cost budget and getting
		// blocked for reasons that have nothing to do with it.
		if ( class_exists( '\\Agentic\\Agent_Run' ) && method_exists( '\\Agentic\\Agent_Run', 'current' ) ) {
			$run = \Agentic\Agent_Run::current();

			if ( $run && method_exists( $run, 'finish' ) ) {
				$run->finish( 'completed' );
			}
		}
	}

	/**
	 * Capabilities the current user is missing for an agent.
	 *
	 * @param \Agentic\Agent_Base $agent Agent instance.
	 * @return string[]
	 */
	private function missing_capabilities( \Agentic\Agent_Base $agent ): array {
		if ( ! method_exists( $agent, 'get_required_capabilities' ) ) {
			return array();
		}

		$missing = array();

		foreach ( (array) $agent->get_required_capabilities() as $cap ) {
			if ( ! current_user_can( (string) $cap ) ) {
				$missing[] = (string) $cap;
			}
		}

		return $missing;
	}

	/**
	 * Build the record for a completed run.
	 *
	 * @param array<string, mixed> $row     Catalog row.
	 * @param array<string, mixed> $result  Controller result.
	 * @param float                $started hrtime() at start.
	 * @param bool                 $queued  Whether the approval queue grew.
	 * @return array<string, mixed>
	 */
	private function build_record( array $row, array $result, float $started, bool $queued ): array {
		$gated = ! empty( $result['pending_proposal'] ) || $queued;

		$decision = $this->verdict->evaluate(
			$row,
			$result,
			array(
				'max_iterations' => (int) $this->options['max_iterations'],
				'gated'          => $gated,
			)
		);

		$record = $this->base_record( $row );

		$record['verdict']         = $decision['verdict'];
		$record['verdict_reasons'] = $decision['reasons'];
		$record['gated']           = $gated;
		$record['actual_agent']    = (string) ( $result['agent_id'] ?? $row['agent'] );
		$record['response']        = (string) ( $result['response'] ?? '' );
		$record['response_length'] = mb_strlen( $record['response'] );
		$record['tools_used']      = array_values( (array) ( $result['tools_used'] ?? array() ) );
		$record['iterations']      = (int) ( $result['iterations'] ?? 0 );
		$record['tokens']          = (int) ( $result['tokens_used'] ?? 0 );
		$record['cost']            = (float) ( $result['cost'] ?? 0 );
		$record['session_id']      = (string) ( $result['session_id'] ?? '' );
		$record['reasoning']       = (string) ( $result['reasoning'] ?? '' );
		$record['duration_ms']     = $this->elapsed_ms( $started );

		if ( ! empty( $result['error'] ) ) {
			$record['error'] = (string) ( $result['response'] ?? 'unknown error' );
		}

		if ( isset( $result['proposal'] ) && is_array( $result['proposal'] ) ) {
			$record['approval_kind']   = (string) ( $result['proposal']['kind'] ?? '' );
			$record['approval_id']     = (string) ( $result['proposal']['id'] ?? '' );
			$record['approval_detail'] = (string) ( $result['proposal']['description'] ?? '' );
		}

		$record['delegated_to'] = $this->delegation_targets( $record['tools_used'], $record['actual_agent'], (string) $row['agent'] );

		if ( ! empty( $this->options['judge'] ) && self::VERDICT_SKIP !== $record['verdict'] ) {
			$record = array_merge( $record, $this->judge( $row, $record ) );
		}

		return $record;
	}

	/**
	 * Build the record for a prompt that threw.
	 *
	 * @param array<string, mixed> $row     Catalog row.
	 * @param \Throwable           $e       The throwable.
	 * @param float                $started hrtime() at start.
	 * @return array<string, mixed>
	 */
	private function build_error_record( array $row, \Throwable $e, float $started ): array {
		$record = $this->base_record( $row );

		$record['verdict']         = self::VERDICT_ERROR;
		$record['verdict_reasons'] = array( sprintf( '%s: %s', get_class( $e ), $e->getMessage() ) );
		$record['error']           = $e->getMessage();
		$record['error_class']     = get_class( $e );
		$record['error_location']  = $e->getFile() . ':' . $e->getLine();
		$record['error_trace']     = implode( "\n", array_slice( explode( "\n", $e->getTraceAsString() ), 0, 5 ) );
		$record['duration_ms']     = $this->elapsed_ms( $started );

		return $record;
	}

	/**
	 * Build the record for a prompt that never ran.
	 *
	 * @param array<string, mixed> $row         Catalog row.
	 * @param string               $verdict     Verdict constant.
	 * @param string               $reason      Why.
	 * @param float                $duration_ms Elapsed time, if any.
	 * @return array<string, mixed>
	 */
	private function build_stub_record( array $row, string $verdict, string $reason, float $duration_ms = 0.0 ): array {
		$record = $this->base_record( $row );

		$record['verdict']         = $verdict;
		$record['verdict_reasons'] = array( $reason );
		$record['duration_ms']     = $duration_ms;

		if ( self::VERDICT_FAIL === $verdict || self::VERDICT_ERROR === $verdict ) {
			$record['error'] = $reason;
		}

		return $record;
	}

	/**
	 * The fields every record carries, whether or not the prompt ran.
	 *
	 * @param array<string, mixed> $row Catalog row.
	 * @return array<string, mixed>
	 */
	private function base_record( array $row ): array {
		return array(
			'id'              => (string) $row['id'],
			'rank'            => (int) ( $row['rank'] ?? 0 ),
			'prompt'          => (string) $row['prompt'],
			'assigned_agent'  => (string) $row['agent'],
			'actual_agent'    => '',
			'expect_tools'    => (array) ( $row['expect_tools'] ?? array() ),
			'coverage'        => (string) ( $row['coverage'] ?? '' ),
			'notes'           => (string) ( $row['notes'] ?? '' ),
			'verdict'         => self::VERDICT_SKIP,
			'verdict_reasons' => array(),
			'gated'           => false,
			'response'        => '',
			'response_length' => 0,
			'tools_used'      => array(),
			'delegated_to'    => array(),
			'iterations'      => 0,
			'tokens'          => 0,
			'cost'            => 0.0,
			'judge_score'     => 0,
			'judge_comment'   => '',
			'judge_tokens'    => 0,
			'judge_cost'      => 0.0,
			'duration_ms'     => 0.0,
			'session_id'      => '',
			'reasoning'       => '',
			'approval_kind'   => '',
			'approval_id'     => '',
			'approval_detail' => '',
			'error'           => '',
			'error_class'     => '',
			'error_location'  => '',
			'error_trace'     => '',
		);
	}

	/**
	 * Grade an answer with the configured provider.
	 *
	 * The score is recorded beside the verdict and never folded into it. A
	 * grader that can change PASS to FAIL makes the suite nondeterministic in
	 * exactly the way the structural assertions exist to avoid.
	 *
	 * @param array<string, mixed> $row    Catalog row.
	 * @param array<string, mixed> $record Result record so far.
	 * @return array<string, mixed> Judge fields to merge.
	 */
	private function judge( array $row, array $record ): array {
		$rubric = trim( (string) ( $row['rubric'] ?? '' ) );

		$system = 'You grade a WordPress AI agent\'s answer for a non-technical site owner. '
			. 'Reply with exactly two lines: "SCORE: <1-5>" then "WHY: <one sentence>". '
			. '5 means it fully answers the question and is usable as-is. '
			. '3 means partially useful. 1 means it did not answer or is wrong. '
			. 'An answer that correctly stops to ask permission before a risky change is not penalised.';

		$user = sprintf(
			"The site owner asked:\n%s\n\nTools the agent called: %s\n\n%sThe agent answered:\n%s",
			$row['prompt'],
			empty( $record['tools_used'] ) ? 'none' : implode( ', ', $record['tools_used'] ),
			'' === $rubric ? '' : sprintf( "Success criterion: %s\n\n", $rubric ),
			mb_substr( (string) $record['response'], 0, 4000 )
		);

		try {
			// Built outside the agent flow on purpose, so a per-agent model
			// override does not end up choosing the grader.
			$llm      = new \Agentic\LLM_Client();
			$response = $llm->chat(
				array(
					array(
						'role'    => 'system',
						'content' => $system,
					),
					array(
						'role'    => 'user',
						'content' => $user,
					),
				)
			);
		} catch ( \Throwable $e ) {
			return array( 'judge_comment' => 'judge failed: ' . $e->getMessage() );
		}

		if ( is_wp_error( $response ) ) {
			return array( 'judge_comment' => 'judge failed: ' . $response->get_error_message() );
		}

		$text = (string) ( $response['content'] ?? '' );

		$score   = 0;
		$matches = array();
		if ( preg_match( '/SCORE:\s*([1-5])/i', $text, $matches ) ) {
			$score = (int) $matches[1];
		}

		$comment = '';
		if ( preg_match( '/WHY:\s*(.+)/i', $text, $matches ) ) {
			$comment = trim( $matches[1] );
		}

		$usage  = is_array( $response['usage'] ?? null ) ? $response['usage'] : array();
		$tokens = (int) ( $usage['total_tokens'] ?? 0 );
		$cost   = class_exists( '\\Agentic\\Costs_Manager' )
			? (float) \Agentic\Costs_Manager::estimate_cost(
				$llm->get_provider(),
				(int) ( $usage['prompt_tokens'] ?? 0 ),
				(int) ( $usage['completion_tokens'] ?? 0 ),
				$llm->get_model()
			)
			: 0.0;

		return array(
			'judge_score'   => $score,
			'judge_comment' => '' === $comment ? trim( (string) preg_replace( '/\s+/', ' ', $text ) ) : $comment,
			'judge_tokens'  => $tokens,
			'judge_cost'    => $cost,
		);
	}

	/**
	 * Work out which agents a run delegated to.
	 *
	 * @param string[] $tools_used   Tools called.
	 * @param string   $actual_agent Agent that answered.
	 * @param string   $assigned     Agent the catalog assigned.
	 * @return string[]
	 */
	private function delegation_targets( array $tools_used, string $actual_agent, string $assigned ): array {
		if ( ! in_array( 'delegate_to_agent', $tools_used, true ) ) {
			return array();
		}

		// The controller result names the agent that produced the final answer but
		// not the intermediate targets, so record what can be known for certain.
		return $actual_agent !== $assigned ? array( $actual_agent ) : array( '(see audit log)' );
	}

	/**
	 * Milliseconds since an hrtime() mark.
	 *
	 * @param float $started hrtime( true ) value.
	 * @return float
	 */
	private function elapsed_ms( float $started ): float {
		return round( ( hrtime( true ) - $started ) / 1e6, 1 );
	}

	/**
	 * Fold a record into the running totals.
	 *
	 * @param array<string, mixed> $record Result record.
	 */
	private function tally( array $record ): void {
		$bucket = match ( $record['verdict'] ) {
			self::VERDICT_PASS    => 'pass',
			self::VERDICT_FAIL    => 'fail',
			self::VERDICT_SKIP    => 'skip',
			self::VERDICT_ERROR   => 'error',
			self::VERDICT_INFO    => 'info',
			self::VERDICT_NOT_RUN => 'not_run',
			default               => 'info',
		};

		++$this->totals[ $bucket ];

		if ( self::VERDICT_SKIP !== $record['verdict'] && self::VERDICT_NOT_RUN !== $record['verdict'] ) {
			++$this->totals['run'];
		}

		if ( ! empty( $record['gated'] ) ) {
			++$this->totals['gated'];
		}

		$this->totals['tokens']       += (int) $record['tokens'];
		$this->totals['cost']         += (float) $record['cost'];
		$this->totals['judge_tokens'] += (int) $record['judge_tokens'];
		$this->totals['judge_cost']   += (float) $record['judge_cost'];
		$this->totals['duration_ms']  += (float) $record['duration_ms'];
	}

	/**
	 * Current totals.
	 *
	 * @return array<string, float|int>
	 */
	public function get_totals(): array {
		return $this->totals;
	}

	/**
	 * Emit a progress event.
	 *
	 * @param string               $event Event name.
	 * @param array<string, mixed> $data  Event data.
	 */
	private function log( string $event, array $data ): void {
		if ( null !== $this->logger ) {
			( $this->logger )( $event, $data );
		}
	}
}
