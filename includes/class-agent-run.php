<?php
/**
 * Agent Run — shared context for a multi-agent (team) orchestration run.
 *
 * A single run spans one top-level invocation and all of the sequential
 * delegations nested beneath it. It enforces delegation depth, fan-out,
 * and cost/token budgets, carries a small shared scratchpad, and persists
 * a summary row to the {prefix}agent_builder_runs table for observability.
 *
 * Free tier: sequential, in-process delegation only. Parallel, durable, and
 * visual workflow orchestration are reserved for Agent Builder Pro.
 *
 * @package    Agent_Builder
 * @subpackage Includes
 * @since      2.11.0
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tracks and bounds a single multi-agent orchestration run.
 */
class Agent_Run {

	/**
	 * Maximum delegation nesting depth (levels of agents calling agents).
	 *
	 * @var int
	 */
	const MAX_DEPTH = 2;

	/**
	 * Maximum number of delegations allowed within a single run.
	 *
	 * @var int
	 */
	const MAX_DELEGATIONS = 5;

	/**
	 * Maximum accumulated tokens before further delegation is blocked.
	 *
	 * @var int
	 */
	const MAX_TOKENS = 200000;

	/**
	 * Maximum accumulated estimated cost (USD) before delegation is blocked.
	 *
	 * @var float
	 */
	const MAX_COST = 5.0;

	/**
	 * The current in-process run, if any.
	 *
	 * @var Agent_Run|null
	 */
	private static ?Agent_Run $current = null;

	/**
	 * Unique run identifier (UUIDv4).
	 *
	 * @var string
	 */
	private string $run_id;

	/**
	 * Agent that started the run.
	 *
	 * @var string
	 */
	private string $root_agent;

	/**
	 * Number of delegation levels currently active.
	 *
	 * @var int
	 */
	private int $depth = 0;

	/**
	 * Greatest depth reached during the run.
	 *
	 * @var int
	 */
	private int $max_depth_reached = 0;

	/**
	 * Total delegation attempts made within the run.
	 *
	 * @var int
	 */
	private int $delegations = 0;

	/**
	 * Accumulated tokens used across delegated runs.
	 *
	 * @var int
	 */
	private int $tokens = 0;

	/**
	 * Accumulated estimated cost across delegated runs.
	 *
	 * @var float
	 */
	private float $cost = 0.0;

	/**
	 * Stack of agent slugs in the active delegation path (root first).
	 *
	 * @var string[]
	 */
	private array $path = array();

	/**
	 * Small shared key/value scratchpad for delegated agents.
	 *
	 * @var array<string, mixed>
	 */
	private array $scratchpad = array();

	/**
	 * Whether the run has been finished (so finish() is idempotent).
	 *
	 * @var bool
	 */
	private bool $finished = false;

	/**
	 * Private constructor — use begin().
	 *
	 * @param string $root_agent Slug of the agent starting the run.
	 */
	private function __construct( string $root_agent ) {
		$this->run_id     = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'run_', true );
		$this->root_agent = $root_agent;
		$this->path[]     = $root_agent;
	}

	/**
	 * Get the current run, if one is active.
	 *
	 * @return Agent_Run|null
	 */
	public static function current(): ?Agent_Run {
		return self::$current;
	}

	/**
	 * Begin a new run (or return the existing one if already active).
	 *
	 * @param string $root_agent Slug of the agent starting the run.
	 * @return Agent_Run
	 */
	public static function begin( string $root_agent ): Agent_Run {
		if ( self::$current instanceof Agent_Run ) {
			return self::$current;
		}

		$run           = new self( $root_agent );
		self::$current = $run;
		$run->persist_start();

		// Safety net: if a fatal error skips finish(), mark the row aborted.
		register_shutdown_function(
			static function () use ( $run ): void {
				if ( ! $run->finished ) {
					$run->finish( 'aborted' );
				}
			}
		);

		return $run;
	}

	/**
	 * Get the run identifier.
	 *
	 * @return string
	 */
	public function get_run_id(): string {
		return $this->run_id;
	}

	/**
	 * Current active delegation depth.
	 *
	 * @return int
	 */
	public function get_depth(): int {
		return $this->depth;
	}

	/**
	 * Whether another delegation is permitted under all configured budgets.
	 *
	 * @return bool
	 */
	public function can_delegate(): bool {
		if ( $this->depth >= self::max_depth() ) {
			return false;
		}
		if ( $this->delegations >= self::max_delegations() ) {
			return false;
		}
		if ( $this->tokens >= self::MAX_TOKENS ) {
			return false;
		}
		if ( $this->cost >= self::MAX_COST ) {
			return false;
		}
		return true;
	}

	/**
	 * Human-readable reason the next delegation is blocked, or '' when allowed.
	 *
	 * @return string
	 */
	public function blocked_reason(): string {
		if ( $this->depth >= self::max_depth() ) {
			return sprintf( 'maximum delegation depth (%d) reached', self::max_depth() );
		}
		if ( $this->delegations >= self::max_delegations() ) {
			return sprintf( 'maximum delegations (%d) reached for this run', self::max_delegations() );
		}
		if ( $this->tokens >= self::MAX_TOKENS ) {
			return 'token budget for this run exhausted';
		}
		if ( $this->cost >= self::MAX_COST ) {
			return 'cost budget for this run exhausted';
		}
		return '';
	}

	/**
	 * Whether the given agent is already active in the delegation path.
	 *
	 * Prevents cycles such as A -> B -> A.
	 *
	 * @param string $agent_slug Candidate target agent.
	 * @return bool
	 */
	public function is_in_path( string $agent_slug ): bool {
		return in_array( $agent_slug, $this->path, true );
	}

	/**
	 * Enter a delegation level for the given target agent.
	 *
	 * @param string $target_agent Target agent slug.
	 * @return void
	 */
	public function enter( string $target_agent ): void {
		++$this->depth;
		++$this->delegations;
		$this->path[]            = $target_agent;
		$this->max_depth_reached = max( $this->max_depth_reached, $this->depth );
	}

	/**
	 * Leave the current delegation level.
	 *
	 * @return void
	 */
	public function leave(): void {
		if ( $this->depth > 0 ) {
			--$this->depth;
			array_pop( $this->path );
		}
	}

	/**
	 * Accumulate usage from a delegated run.
	 *
	 * @param int   $tokens Tokens used.
	 * @param float $cost   Estimated cost.
	 * @return void
	 */
	public function add_usage( int $tokens, float $cost ): void {
		$this->tokens += max( 0, $tokens );
		$this->cost   += max( 0.0, $cost );
	}

	/**
	 * Read a value from the shared scratchpad.
	 *
	 * @param string $key     Key.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	public function scratch_get( string $key, $default = null ) {
		return $this->scratchpad[ $key ] ?? $default;
	}

	/**
	 * Store a value in the shared scratchpad (capped to keep the row small).
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 * @return void
	 */
	public function scratch_set( string $key, $value ): void {
		if ( count( $this->scratchpad ) >= 50 && ! isset( $this->scratchpad[ $key ] ) ) {
			return;
		}
		$this->scratchpad[ $key ] = $value;
	}

	/**
	 * Snapshot of the run state for audit/return payloads.
	 *
	 * @return array<string, mixed>
	 */
	public function summary(): array {
		return array(
			'run_id'      => $this->run_id,
			'root_agent'  => $this->root_agent,
			'depth'       => $this->depth,
			'delegations' => $this->delegations,
			'tokens_used' => $this->tokens,
			'cost'        => round( $this->cost, 6 ),
		);
	}

	/**
	 * Finish the run and persist the final state. Idempotent.
	 *
	 * @param string $status Final status ('completed', 'aborted', 'error').
	 * @return void
	 */
	public function finish( string $status = 'completed' ): void {
		if ( $this->finished ) {
			return;
		}
		$this->finished = true;

		$this->persist_finish( $status );

		if ( self::$current === $this ) {
			self::$current = null;
		}
	}

	/**
	 * Persist a progress update (called after each delegation completes).
	 *
	 * @return void
	 */
	public function persist_progress(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'agent_builder_runs';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			$table,
			array(
				'delegations' => $this->delegations,
				'max_depth'   => $this->max_depth_reached,
				'tokens_used' => $this->tokens,
				'cost'        => round( $this->cost, 6 ),
				'state'       => (string) wp_json_encode( $this->scratchpad ),
			),
			array( 'run_id' => $this->run_id ),
			array( '%d', '%d', '%d', '%f', '%s' ),
			array( '%s' )
		);
	}

	/**
	 * Resolve the effective max depth (filterable).
	 *
	 * @return int
	 */
	private static function max_depth(): int {
		return max( 1, (int) apply_filters( 'agentic_max_delegation_depth', self::MAX_DEPTH ) );
	}

	/**
	 * Resolve the effective max delegations (filterable).
	 *
	 * @return int
	 */
	private static function max_delegations(): int {
		return max( 1, (int) apply_filters( 'agentic_max_delegations', self::MAX_DELEGATIONS ) );
	}

	/**
	 * Insert the initial run row.
	 *
	 * @return void
	 */
	private function persist_start(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'agent_builder_runs';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			$table,
			array(
				'run_id'     => $this->run_id,
				'root_agent' => $this->root_agent,
				'status'     => 'running',
			),
			array( '%s', '%s', '%s' )
		);
	}

	/**
	 * Update the run row with final status and accumulated totals.
	 *
	 * @param string $status Final status.
	 * @return void
	 */
	private function persist_finish( string $status ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'agent_builder_runs';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			$table,
			array(
				'status'      => $status,
				'delegations' => $this->delegations,
				'max_depth'   => $this->max_depth_reached,
				'tokens_used' => $this->tokens,
				'cost'        => round( $this->cost, 6 ),
				'state'       => (string) wp_json_encode( $this->scratchpad ),
				'finished_at' => current_time( 'mysql', true ),
			),
			array( 'run_id' => $this->run_id ),
			array( '%s', '%d', '%d', '%d', '%f', '%s', '%s' ),
			array( '%s' )
		);
	}
}
