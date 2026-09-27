<?php
/**
 * Agent Run — general run context shared by every autonomous task, routine,
 * event, delegation, prompt test, and (Pro) workflow.
 *
 * A single run spans one top-level invocation and everything nested beneath
 * it (sequential delegations, resumes after a pause). It enforces delegation
 * depth, fan-out, and cost/token budgets, carries a small shared scratchpad
 * plus a capped transcript for resume, and persists a row to the
 * {prefix}agent_builder_runs table so a run can be loaded, queried, paused,
 * cancelled, and correlated with audit rows from any process.
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
 * Tracks, persists, and bounds a single run context.
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
	 * Statuses that mean "this run will never be touched again" for the
	 * purposes of reconstructing $finished when loading a row back.
	 *
	 * 'error' is kept for backward compatibility: delegate_to_agent/tool.php
	 * has always finished a failed delegation with status 'error', predating
	 * the M10 status vocabulary (completed|failed|aborted|cancelled).
	 *
	 * @var string[]
	 */
	private const TERMINAL_STATUSES = array( 'completed', 'failed', 'aborted', 'cancelled', 'error' );

	/**
	 * Cap on the encoded transcript stored in state.messages, in bytes.
	 *
	 * @var int
	 */
	private const TRANSCRIPT_CAP_BYTES = 200 * 1024;

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
	 * Run kind: task, routine, event, delegation, prompt_test, workflow, chat.
	 *
	 * @var string
	 */
	private string $kind = 'task';

	/**
	 * Current status.
	 *
	 * @var string
	 */
	private string $status = 'running';

	/**
	 * Owning user id, or 0 for none.
	 *
	 * @var int
	 */
	private int $user_id = 0;

	/**
	 * The task/prompt text the run was started with, if any.
	 *
	 * @var string
	 */
	private string $task_text = '';

	/**
	 * Parent run id when this run was itself dispatched from another run
	 * (a delegation, or a Pro workflow node).
	 *
	 * @var string
	 */
	private string $parent_run_id = '';

	/**
	 * Background job id backing this run, if dispatched via Job_Manager.
	 *
	 * @var string
	 */
	private string $job_id = '';

	/**
	 * Chat/session id this run is tied to, if any.
	 *
	 * @var string
	 */
	private string $session_id = '';

	/**
	 * Invocation context (chat, cron, hook, cli, rest).
	 *
	 * @var string
	 */
	private string $invocation = '';

	/**
	 * Free-form origin reference, e.g. "routine:12" or "listener:publish_post".
	 *
	 * @var string
	 */
	private string $source_ref = '';

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
	 * Number of controller loop iterations recorded for this run.
	 *
	 * @var int
	 */
	private int $iterations = 0;

	/**
	 * Accumulated tokens used across the run (and delegated runs).
	 *
	 * @var int
	 */
	private int $tokens = 0;

	/**
	 * Accumulated estimated cost across the run (and delegated runs).
	 *
	 * @var float
	 */
	private float $cost = 0.0;

	/**
	 * Union of tool names used so far in this run.
	 *
	 * @var string[]
	 */
	private array $tools_used = array();

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
	 * Capped conversation transcript, kept for resuming a waiting run.
	 *
	 * @var array<int, mixed>
	 */
	private array $messages = array();

	/**
	 * Human-readable result text, set by finish().
	 *
	 * @var string
	 */
	private string $result_text = '';

	/**
	 * Result cards (see M16), set by finish().
	 *
	 * @var array<int, mixed>
	 */
	private array $result_cards = array();

	/**
	 * Error message, set by finish() or mark_waiting()'s caller via finish().
	 *
	 * @var string
	 */
	private string $error = '';

	/**
	 * What this run is waiting on: 'approval' or 'proposal'.
	 *
	 * @var string
	 */
	private string $awaiting_type = '';

	/**
	 * Id of the approval/proposal this run is waiting on.
	 *
	 * @var string
	 */
	private string $awaiting_id = '';

	/**
	 * Whether a caller has asked this run to stop cooperatively.
	 *
	 * @var bool
	 */
	private bool $cancel_requested = false;

	/**
	 * Whether this in-process instance has already settled (finished, or
	 * handed off via mark_waiting()) — guards finish() idempotency and the
	 * shutdown safety net below.
	 *
	 * @var bool
	 */
	private bool $finished = false;

	/**
	 * Whether the shutdown safety net has already been registered for this
	 * instance.
	 *
	 * @var bool
	 */
	private bool $shutdown_registered = false;

	/**
	 * Timestamps (MySQL 'Y-m-d H:i:s', UTC), tracked in-memory so to_array()
	 * is accurate for a freshly begun run without a round-trip to the DB.
	 *
	 * @var string
	 */
	private string $started_at = '';

	/**
	 * Last-updated timestamp.
	 *
	 * @var string
	 */
	private string $updated_at = '';

	/**
	 * Finished-at timestamp, empty until finish() runs.
	 *
	 * @var string
	 */
	private string $finished_at = '';

	/**
	 * Private constructor — use begin() or load().
	 *
	 * @param string $root_agent Slug of the agent starting the run.
	 * @param array  $opts       {
	 *     Optional. Run context.
	 *
	 *     @type string $kind          Run kind. Default 'task'.
	 *     @type int    $user_id       Owning user id.
	 *     @type string $task_text     Task/prompt text.
	 *     @type string $parent_run_id Parent run id.
	 *     @type string $job_id        Backing job id.
	 *     @type string $session_id    Chat/session id.
	 *     @type string $invocation    Invocation context.
	 *     @type string $source_ref    Origin reference.
	 * }
	 */
	private function __construct( string $root_agent, array $opts = array() ) {
		$this->run_id     = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'run_', true );
		$this->root_agent = $root_agent;
		$this->path[]     = $root_agent;

		$kind                = (string) ( $opts['kind'] ?? '' );
		$this->kind          = '' !== $kind ? $kind : 'task';
		$this->user_id       = isset( $opts['user_id'] ) ? (int) $opts['user_id'] : 0;
		$this->task_text     = (string) ( $opts['task_text'] ?? '' );
		$this->parent_run_id = (string) ( $opts['parent_run_id'] ?? '' );
		$this->job_id        = (string) ( $opts['job_id'] ?? '' );
		$this->session_id    = (string) ( $opts['session_id'] ?? '' );
		$this->invocation    = (string) ( $opts['invocation'] ?? '' );
		$this->source_ref    = (string) ( $opts['source_ref'] ?? '' );

		$this->started_at = current_time( 'mysql', true );
		$this->updated_at = $this->started_at;
	}

	/**
	 * Get the current run, if one is active in this process.
	 *
	 * @return Agent_Run|null
	 */
	public static function current(): ?Agent_Run {
		return self::$current;
	}

	/**
	 * Begin a new run (or return the existing one if already active).
	 *
	 * A nested call (one made while a run is already active in this process)
	 * always returns the existing run and ignores $opts — this is what keeps
	 * delegation semantics unchanged: the outermost begin() owns the run.
	 * Fires `agent_builder_run_started` for a genuinely new run (not a
	 * nested return).
	 *
	 * @param string $root_agent Slug of the agent starting the run.
	 * @param array  $opts       See __construct().
	 * @return Agent_Run
	 */
	public static function begin( string $root_agent, array $opts = array() ): Agent_Run {
		if ( self::$current instanceof Agent_Run ) {
			return self::$current;
		}

		$run           = new self( $root_agent, $opts );
		self::$current = $run;
		$run->persist_start();
		$run->register_shutdown_guard();

		do_action( 'agent_builder_run_started', $run );

		return $run;
	}

	/**
	 * Load a run by id from storage, independent of any in-process run.
	 *
	 * Does not make the loaded run current — call make_current() explicitly
	 * once the caller is ready to own the run's lifecycle in this process
	 * (e.g. a background job resuming a waiting run).
	 *
	 * @param string $run_id Run identifier.
	 * @return Agent_Run|null
	 */
	public static function load( string $run_id ): ?Agent_Run {
		if ( '' === $run_id ) {
			return null;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Single-row lookup, no caching benefit for a run context.
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE run_id = %s', $wpdb->prefix . 'agent_builder_runs', $run_id ),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return null;
		}

		return self::from_row( $row );
	}

	/**
	 * Make this instance the active run for the current process, and arm the
	 * shutdown safety net for it.
	 *
	 * @return void
	 */
	public function make_current(): void {
		self::$current = $this;
		$this->register_shutdown_guard();
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
	 * Get the run kind (task, routine, event, delegation, prompt_test, workflow, chat).
	 *
	 * @return string
	 */
	public function get_kind(): string {
		return $this->kind;
	}

	/**
	 * Get the owning user id, or 0 when the run has none.
	 *
	 * @return int
	 */
	public function get_user_id(): int {
		return $this->user_id;
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
	 * @param mixed  $default_value Default value.
	 * @return mixed
	 */
	public function scratch_get( string $key, $default_value = null ) {
		return $this->scratchpad[ $key ] ?? $default_value;
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
	 * Snapshot of the run's delegation state, for audit/return payloads.
	 *
	 * Kept as its own (smaller, stable) shape — separate from to_array() —
	 * since it is embedded directly in delegate_to_agent's tool result and
	 * audit rows.
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
	 * Mark this run as waiting on an approval or proposal, persisting a
	 * capped transcript so a later request can resume it.
	 *
	 * @param string $type       'approval' or 'proposal'.
	 * @param string $id         Id of the approval/proposal.
	 * @param array  $transcript Conversation messages to persist for resume.
	 * @return void
	 */
	public function mark_waiting( string $type, string $id, array $transcript ): void {
		$this->status        = 'waiting';
		$this->awaiting_type = $type;
		$this->awaiting_id   = $id;
		$this->messages      = self::sanitize_transcript( $transcript );

		$now = current_time( 'mysql', true );

		$this->persist(
			array(
				'status'        => $this->status,
				'awaiting_type' => $this->awaiting_type,
				'awaiting_id'   => $this->awaiting_id,
				'state'         => $this->encode_state(),
				'updated_at'    => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);

		$this->updated_at = $now;

		// A waiting run has handed off to an external event; this in-process
		// instance is settled and must not have the shutdown safety net
		// overwrite it with 'aborted' when the current request ends.
		$this->finished = true;
	}

	/**
	 * Atomically claim this run out of 'waiting'.
	 *
	 * Two concurrent approval/proposal resolutions for the same run (a
	 * double-submit, a retried REST request, two admins racing the same
	 * approval) must not both resume/stop it. This mirrors the
	 * `UPDATE ... WHERE status = 'pending'` claim Job_Manager::process_job()
	 * uses: only the caller whose UPDATE actually flips the row wins.
	 *
	 * @return bool True if this call performed the claim (exactly one row
	 *              moved out of 'waiting'); false if the run was no longer
	 *              'waiting' (already claimed, or resolved by something else).
	 */
	public function claim_waiting(): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'agent_builder_runs';
		$now   = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic claim, keyed by run_id; %i quotes the table name.
		$claimed = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET status = %s, updated_at = %s WHERE run_id = %s AND status = %s',
				$table,
				'running',
				$now,
				$this->run_id,
				'waiting'
			)
		);

		if ( 1 !== $claimed ) {
			return false;
		}

		$this->status     = 'running';
		$this->updated_at = $now;

		return true;
	}

	/**
	 * State needed to resume a waiting run's loop: transcript, scratchpad,
	 * and what it was waiting on.
	 *
	 * @return array<string, mixed>
	 */
	public function resume_state(): array {
		return array(
			'messages'      => $this->messages,
			'scratchpad'    => $this->scratchpad,
			'awaiting_type' => $this->awaiting_type,
			'awaiting_id'   => $this->awaiting_id,
			'iterations'    => $this->iterations,
			'tools_used'    => array_values( $this->tools_used ),
		);
	}

	/**
	 * Record one controller loop iteration: bump the iteration count, union
	 * in the tools used, and add tokens/cost.
	 *
	 * @param string[] $tools  Tool names used this iteration.
	 * @param int      $tokens Tokens used this iteration.
	 * @param float    $cost   Estimated cost this iteration.
	 * @return void
	 */
	public function record_iteration( array $tools, int $tokens, float $cost ): void {
		++$this->iterations;
		$this->tokens += max( 0, $tokens );
		$this->cost   += max( 0.0, $cost );

		$clean            = array_values( array_unique( array_filter( array_map( 'strval', $tools ) ) ) );
		$this->tools_used = array_values( array_unique( array_merge( $this->tools_used, $clean ) ) );

		$now = current_time( 'mysql', true );

		$this->persist(
			array(
				'iterations'  => $this->iterations,
				'tokens_used' => $this->tokens,
				'cost'        => round( $this->cost, 6 ),
				'tools_used'  => (string) wp_json_encode( $this->tools_used ),
				'updated_at'  => $now,
			),
			array( '%d', '%d', '%f', '%s', '%s' )
		);

		$this->updated_at = $now;
	}

	/**
	 * Persist the current transcript into state.messages without changing the
	 * run's status — called by the controller after every loop iteration so
	 * a run can be resumed from its last-known state if the process dies
	 * before finish() or mark_waiting() runs (e.g. a PHP time-limit kill).
	 *
	 * Reuses the same cap/strip logic as mark_waiting() — callers must not
	 * reimplement transcript capping themselves.
	 *
	 * @param array $transcript Conversation messages so far.
	 * @return void
	 */
	public function checkpoint_transcript( array $transcript ): void {
		$this->messages = self::sanitize_transcript( $transcript );

		$now = current_time( 'mysql', true );

		$this->persist(
			array(
				'state'      => $this->encode_state(),
				'updated_at' => $now,
			),
			array( '%s', '%s' )
		);

		$this->updated_at = $now;
	}

	/**
	 * Ask this run to stop cooperatively. Persists immediately so any
	 * process holding a different instance of the same run sees the request
	 * the next time it calls cancel_requested().
	 *
	 * @return void
	 */
	public function request_cancel(): void {
		$this->cancel_requested = true;
		$this->persist( array( 'cancel_requested' => 1 ), array( '%d' ) );
	}

	/**
	 * Whether cancellation has been requested for this run, re-reading the
	 * row so a request made through a different instance/process is seen.
	 *
	 * @return bool
	 */
	public function cancel_requested(): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Must reflect another process's request_cancel() write, not this instance's stale in-memory value.
		$value = $wpdb->get_var(
			$wpdb->prepare( 'SELECT cancel_requested FROM %i WHERE run_id = %s', $wpdb->prefix . 'agent_builder_runs', $this->run_id )
		);

		$this->cancel_requested = ! empty( $value );

		return $this->cancel_requested;
	}

	/**
	 * Finish the run and persist the final state. Idempotent.
	 *
	 * @param string $status  Final status ('completed', 'failed', 'aborted', 'cancelled').
	 * @param array  $summary {
	 *     Optional. Result summary.
	 *
	 *     @type string $text  Human-readable result text.
	 *     @type array  $cards Result cards (see M16).
	 *     @type string $error Error message.
	 * }
	 * @return void
	 */
	public function finish( string $status = 'completed', array $summary = array() ): void {
		if ( $this->finished ) {
			return;
		}
		$this->finished = true;
		$this->status   = $status;

		if ( array_key_exists( 'text', $summary ) ) {
			$this->result_text = (string) $summary['text'];
		}
		if ( isset( $summary['cards'] ) && is_array( $summary['cards'] ) ) {
			$this->result_cards = $summary['cards'];
		}
		if ( isset( $summary['error'] ) ) {
			$this->error = (string) $summary['error'];
		}

		$this->persist_finish( $status );

		if ( self::$current === $this ) {
			self::$current = null;
		}
	}

	/**
	 * Full snapshot of the run, for REST/return payloads.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'run_id'           => $this->run_id,
			'root_agent'       => $this->root_agent,
			'kind'             => $this->kind,
			'status'           => $this->status,
			'user_id'          => $this->user_id,
			'task_text'        => $this->task_text,
			'parent_run_id'    => $this->parent_run_id,
			'job_id'           => $this->job_id,
			'session_id'       => $this->session_id,
			'invocation'       => $this->invocation,
			'source_ref'       => $this->source_ref,
			'depth'            => $this->depth,
			'delegations'      => $this->delegations,
			'max_depth'        => $this->max_depth_reached,
			'iterations'       => $this->iterations,
			'tokens_used'      => $this->tokens,
			'cost'             => round( $this->cost, 6 ),
			'tools_used'       => array_values( $this->tools_used ),
			'result_summary'   => array(
				'text'  => $this->result_text,
				'cards' => $this->result_cards,
			),
			'error'            => $this->error,
			'awaiting_type'    => $this->awaiting_type,
			'awaiting_id'      => $this->awaiting_id,
			'cancel_requested' => $this->cancel_requested,
			'started_at'       => $this->started_at,
			'updated_at'       => $this->updated_at,
			'finished_at'      => $this->finished_at,
		);
	}

	/**
	 * Query runs, newest first.
	 *
	 * @param array $args {
	 *     Optional. Filters.
	 *
	 *     @type string $status        Filter by status.
	 *     @type string $agent         Filter by root_agent.
	 *     @type string $kind          Filter by kind.
	 *     @type int    $user_id       Filter by owning user.
	 *     @type string $source_ref    Filter by source_ref.
	 *     @type string $parent_run_id Filter by parent_run_id.
	 *     @type int    $per_page      Page size (default 20, max 200).
	 *     @type int    $page          1-based page number (default 1).
	 * }
	 * @return array<int, array<string, mixed>>
	 */
	public static function query( array $args = array() ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'agent_builder_runs';

		$where  = array( '1=1' );
		$values = array();

		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$values[] = (string) $args['status'];
		}
		if ( ! empty( $args['agent'] ) ) {
			$where[]  = 'root_agent = %s';
			$values[] = (string) $args['agent'];
		}
		if ( ! empty( $args['kind'] ) ) {
			$where[]  = 'kind = %s';
			$values[] = (string) $args['kind'];
		}
		if ( isset( $args['user_id'] ) && '' !== $args['user_id'] ) {
			$where[]  = 'user_id = %d';
			$values[] = (int) $args['user_id'];
		}
		if ( ! empty( $args['source_ref'] ) ) {
			$where[]  = 'source_ref = %s';
			$values[] = (string) $args['source_ref'];
		}
		if ( ! empty( $args['parent_run_id'] ) ) {
			$where[]  = 'parent_run_id = %s';
			$values[] = (string) $args['parent_run_id'];
		}

		$per_page = max( 1, min( 200, (int) ( $args['per_page'] ?? 20 ) ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$offset   = ( $page - 1 ) * $per_page;

		$where_sql = implode( ' AND ', $where );
		$values[]  = $per_page;
		$values[]  = $offset;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is $wpdb->prefix-derived; $where_sql built only from the fixed condition strings above; every value is bound via prepare()'s variadic args below.
		$sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY started_at DESC, id DESC LIMIT %d OFFSET %d";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Filtered, paginated run listing.
		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Custom plugin table; $sql is bound via prepare() immediately below.
			$wpdb->prepare( $sql, ...$values ),
			ARRAY_A
		);

		return array_map(
			static function ( array $row ): array {
				return self::from_row( $row )->to_array();
			},
			is_array( $rows ) ? $rows : array()
		);
	}

	/**
	 * Count a user's runs by status.
	 *
	 * @param int $user_id Owning user id.
	 * @return array<string, int> Status => count.
	 */
	public static function counts( int $user_id ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'agent_builder_runs';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Small aggregate, not worth caching.
		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is $wpdb->prefix-derived; only user_id is bound, via prepare().
			$wpdb->prepare( "SELECT status, COUNT(*) as total FROM {$table} WHERE user_id = %d GROUP BY status", $user_id ),
			ARRAY_A
		);

		$counts = array();
		foreach ( (array) $rows as $row ) {
			$counts[ (string) $row['status'] ] = (int) $row['total'];
		}

		return $counts;
	}

	/**
	 * Persist a progress update (called after each delegation completes).
	 *
	 * @return void
	 */
	public function persist_progress(): void {
		$now = current_time( 'mysql', true );

		$this->persist(
			array(
				'delegations' => $this->delegations,
				'max_depth'   => $this->max_depth_reached,
				'tokens_used' => $this->tokens,
				'cost'        => round( $this->cost, 6 ),
				'state'       => $this->encode_state(),
				'updated_at'  => $now,
			),
			array( '%d', '%d', '%d', '%f', '%s', '%s' )
		);

		$this->updated_at = $now;
	}

	/**
	 * Clear the in-process current run without persisting anything.
	 *
	 * For test isolation only: PHPUnit runs many tests in one process, so a
	 * run left active (or left "current") by one test would otherwise leak
	 * into the next. Production code should always resolve the active run
	 * via finish() or a natural end of request instead.
	 *
	 * @return void
	 */
	public static function reset_current_for_tests(): void {
		self::$current = null;
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
	 * Register the fatal-error safety net once per instance: if a fatal
	 * error skips both finish() and mark_waiting(), mark the row aborted so
	 * it never sits at 'running' forever.
	 *
	 * @return void
	 */
	private function register_shutdown_guard(): void {
		if ( $this->shutdown_registered ) {
			return;
		}
		$this->shutdown_registered = true;

		register_shutdown_function(
			function (): void {
				if ( ! $this->finished ) {
					$this->finish( 'aborted' );
				}
			}
		);
	}

	/**
	 * Build a run instance from a raw DB row (as returned by $wpdb ARRAY_A).
	 *
	 * @param array $row Raw row.
	 * @return Agent_Run
	 */
	private static function from_row( array $row ): Agent_Run {
		$run = new self( (string) ( $row['root_agent'] ?? '' ) );

		$run->run_id            = (string) ( $row['run_id'] ?? $run->run_id );
		$run->kind              = (string) ( $row['kind'] ?? 'task' );
		$run->status            = (string) ( $row['status'] ?? 'running' );
		$run->user_id           = (int) ( $row['user_id'] ?? 0 );
		$run->task_text         = (string) ( $row['task_text'] ?? '' );
		$run->parent_run_id     = (string) ( $row['parent_run_id'] ?? '' );
		$run->job_id            = (string) ( $row['job_id'] ?? '' );
		$run->session_id        = (string) ( $row['session_id'] ?? '' );
		$run->invocation        = (string) ( $row['invocation'] ?? '' );
		$run->source_ref        = (string) ( $row['source_ref'] ?? '' );
		$run->delegations       = (int) ( $row['delegations'] ?? 0 );
		$run->max_depth_reached = (int) ( $row['max_depth'] ?? 0 );
		$run->iterations        = (int) ( $row['iterations'] ?? 0 );
		$run->tokens            = (int) ( $row['tokens_used'] ?? 0 );
		$run->cost              = (float) ( $row['cost'] ?? 0.0 );
		$run->tools_used        = self::decode_list( $row['tools_used'] ?? '' );
		$run->error             = (string) ( $row['error'] ?? '' );
		$run->awaiting_type     = (string) ( $row['awaiting_type'] ?? '' );
		$run->awaiting_id       = (string) ( $row['awaiting_id'] ?? '' );
		$run->cancel_requested  = ! empty( $row['cancel_requested'] );
		$run->started_at        = (string) ( $row['started_at'] ?? '' );
		$run->updated_at        = (string) ( $row['updated_at'] ?? '' );
		$run->finished_at       = (string) ( $row['finished_at'] ?? '' );

		$summary           = self::decode_assoc( $row['result_summary'] ?? '' );
		$run->result_text  = (string) ( $summary['text'] ?? '' );
		$run->result_cards = is_array( $summary['cards'] ?? null ) ? $summary['cards'] : array();

		$state           = self::decode_assoc( $row['state'] ?? '' );
		$run->scratchpad = is_array( $state['scratchpad'] ?? null ) ? $state['scratchpad'] : array();
		$run->messages   = is_array( $state['messages'] ?? null ) ? $state['messages'] : array();

		$run->finished = in_array( $run->status, self::TERMINAL_STATUSES, true );

		return $run;
	}

	/**
	 * Decode a JSON object column, tolerating empty/invalid input.
	 *
	 * @param mixed $raw Raw column value.
	 * @return array
	 */
	private static function decode_assoc( $raw ): array {
		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Decode a JSON list-of-strings column, tolerating empty/invalid input.
	 *
	 * @param mixed $raw Raw column value.
	 * @return string[]
	 */
	private static function decode_list( $raw ): array {
		$decoded = self::decode_assoc( $raw );
		return array_values( array_filter( $decoded, 'is_string' ) );
	}

	/**
	 * Encode the scratchpad + transcript into the state column.
	 *
	 * @return string
	 */
	private function encode_state(): string {
		return (string) wp_json_encode(
			array(
				'scratchpad' => $this->scratchpad,
				'messages'   => $this->messages,
			)
		);
	}

	/**
	 * Strip image payloads from a transcript and cap its encoded size to
	 * TRANSCRIPT_CAP_BYTES, dropping the oldest messages first (most recent
	 * context matters most for resuming a paused loop).
	 *
	 * @param array $messages Raw transcript.
	 * @return array Sanitized, capped transcript.
	 */
	private static function sanitize_transcript( array $messages ): array {
		$stripped  = array_values( array_map( array( self::class, 'strip_message_images' ), $messages ) );
		$remaining = count( $stripped );

		while ( $remaining > 1 ) {
			$encoded = wp_json_encode( $stripped );
			if ( is_string( $encoded ) && strlen( $encoded ) <= self::TRANSCRIPT_CAP_BYTES ) {
				break;
			}
			array_shift( $stripped );
			--$remaining;
		}

		return $stripped;
	}

	/**
	 * Replace any image parts in a message's content with a small placeholder.
	 *
	 * @param mixed $message One transcript message.
	 * @return mixed
	 */
	private static function strip_message_images( $message ) {
		if ( ! is_array( $message ) || ! is_array( $message['content'] ?? null ) ) {
			return $message;
		}

		$message['content'] = array_map(
			static function ( $part ) {
				if ( is_array( $part ) && in_array( $part['type'] ?? '', array( 'image', 'image_url' ), true ) ) {
					return array(
						'type'    => (string) $part['type'],
						'omitted' => true,
					);
				}
				return $part;
			},
			$message['content']
		);

		return $message;
	}

	/**
	 * Generic partial update of this run's row, keyed by run_id.
	 *
	 * @param array $fields  Column => value.
	 * @param array $formats Matching %s/%d/%f formats.
	 * @return void
	 */
	private function persist( array $fields, array $formats ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'agent_builder_runs';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table update, keyed by run_id.
		$wpdb->update( $table, $fields, array( 'run_id' => $this->run_id ), $formats, array( '%s' ) );
	}

	/**
	 * Insert the initial run row.
	 *
	 * @return void
	 */
	private function persist_start(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'agent_builder_runs';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table insert.
		$wpdb->insert(
			$table,
			array(
				'run_id'        => $this->run_id,
				'root_agent'    => $this->root_agent,
				'kind'          => $this->kind,
				'status'        => $this->status,
				'user_id'       => $this->user_id > 0 ? $this->user_id : null,
				'task_text'     => $this->task_text,
				'parent_run_id' => '' !== $this->parent_run_id ? $this->parent_run_id : null,
				'job_id'        => '' !== $this->job_id ? $this->job_id : null,
				'session_id'    => '' !== $this->session_id ? $this->session_id : null,
				'invocation'    => '' !== $this->invocation ? $this->invocation : null,
				'source_ref'    => '' !== $this->source_ref ? $this->source_ref : null,
				'started_at'    => $this->started_at,
				'updated_at'    => $this->updated_at,
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Update the run row with final status and accumulated totals.
	 *
	 * @param string $status Final status.
	 * @return void
	 */
	private function persist_finish( string $status ): void {
		$now = current_time( 'mysql', true );

		$this->persist(
			array(
				'status'         => $status,
				'delegations'    => $this->delegations,
				'max_depth'      => $this->max_depth_reached,
				'iterations'     => $this->iterations,
				'tokens_used'    => $this->tokens,
				'cost'           => round( $this->cost, 6 ),
				'tools_used'     => (string) wp_json_encode( $this->tools_used ),
				'result_summary' => (string) wp_json_encode(
					array(
						'text'  => $this->result_text,
						'cards' => $this->result_cards,
					)
				),
				'error'          => $this->error,
				'state'          => $this->encode_state(),
				'updated_at'     => $now,
				'finished_at'    => $now,
			),
			array( '%s', '%d', '%d', '%d', '%d', '%f', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		$this->updated_at  = $now;
		$this->finished_at = $now;
	}
}
