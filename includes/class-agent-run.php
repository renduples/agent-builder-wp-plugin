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
	 * The original LLM tool-call id (assistant message's tool_calls[].id) for
	 * the pending tool call, kept separate from awaiting_id (the
	 * proposal/approval queue's own business id) so a resumed request can
	 * reconstruct a tool-role message that correctly pairs with the assistant
	 * message a provider will validate it against.
	 *
	 * @var string
	 */
	private string $awaiting_tool_call_id = '';

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
	 * Create a queued run row without making it current or arming the shutdown
	 * guard.
	 *
	 * For a run dispatched through a background job from a web/REST request (the
	 * M11 POST /runs path): begin() would make the run current and register a
	 * shutdown guard that calls finish('aborted') when the creating request
	 * ends — before the WP-Cron worker can adopt it. create_queued() instead
	 * inserts the row with status 'queued' and returns it inert, so the worker
	 * adopts the row later (claim_queued() → make_current()) and arms the guard
	 * there.
	 *
	 * @param string $agent_id Slug of the agent that will run the task.
	 * @param array  $opts     See __construct().
	 * @return Agent_Run
	 */
	public static function create_queued( string $agent_id, array $opts = array() ): Agent_Run {
		$run         = new self( $agent_id, $opts );
		$run->status = 'queued';
		$run->persist_start();

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
	 * Current status ('queued', 'running', 'waiting', 'continuing', or a
	 * terminal status such as 'completed'/'failed'/'aborted'/'cancelled').
	 *
	 * @return string
	 */
	public function get_status(): string {
		return $this->status;
	}

	/**
	 * Slug of the agent that started this run.
	 *
	 * @return string
	 */
	public function get_root_agent(): string {
		return $this->root_agent;
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
	 * @param string $type          'approval' or 'proposal'.
	 * @param string $id            Id of the approval/proposal.
	 * @param array  $transcript    Conversation messages to persist for resume.
	 * @param string $tool_call_id  Original LLM tool-call id (assistant message's
	 *                              tool_calls[].id) the resumed tool message must
	 *                              reuse, kept separate from $id above.
	 * @return void
	 */
	public function mark_waiting( string $type, string $id, array $transcript, string $tool_call_id = '' ): void {
		$this->status                = 'waiting';
		$this->awaiting_type         = $type;
		$this->awaiting_id           = $id;
		$this->awaiting_tool_call_id = $tool_call_id;
		$this->messages              = self::sanitize_transcript( $transcript );

		$now = current_time( 'mysql', true );

		$fields  = array(
			'status'        => $this->status,
			'awaiting_type' => $this->awaiting_type,
			'awaiting_id'   => $this->awaiting_id,
			'state'         => $this->encode_state(),
			'updated_at'    => $now,
		);
		$formats = array( '%s', '%s', '%s', '%s', '%s' );

		// The awaiting_tool_call_id column is added by a version-independent
		// migration that only runs from admin_init (see Activator::maybe_upgrade()).
		// A cron/REST/frontend request on a site that has not run it yet would
		// otherwise include an unknown column in the UPDATE, failing the whole
		// write and silently leaving the run 'running' when it should be
		// 'waiting'. When the column is absent, persist the waiting transition
		// without it — the transcript already carries the assistant tool_calls[]
		// id, which resume_state()'s consumer derives when the value is empty.
		if ( self::has_awaiting_tool_call_id_column() ) {
			$fields['awaiting_tool_call_id'] = $this->awaiting_tool_call_id;
			$formats[]                       = '%s';
		}

		$persisted = $this->persist( $fields, $formats );

		$this->updated_at = $now;

		// A waiting run has handed off to an external event; this in-process
		// instance is settled and must not have the shutdown safety net
		// overwrite it with 'aborted' when the current request ends. Only
		// settle it once the DB write has actually landed — a transient
		// failure leaves the row 'running', and keeping $finished false lets
		// the shutdown net mark it 'aborted' at request end instead of
		// stranding it (mirrors mark_continuing()).
		if ( ! $persisted ) {
			return;
		}

		$this->finished = true;

		// The instance is settled; release the current-run pointer so a later
		// begin() in the same request starts a fresh run rather than handing
		// back this settled one (mirrors finish()).
		if ( self::$current === $this ) {
			self::$current = null;
		}
	}

	/**
	 * Atomically claim this run out of 'waiting' on a specific pending item.
	 *
	 * Two concurrent approval/proposal resolutions for the same run (a
	 * double-submit, a retried REST request, two admins racing the same
	 * approval) must not both resume/stop it. This mirrors the
	 * `UPDATE ... WHERE status = 'pending'` claim Job_Manager::process_job()
	 * uses: only the caller whose UPDATE actually flips the row wins.
	 *
	 * The claim also matches on `awaiting_type`/`awaiting_id`: if the run has
	 * already moved on to waiting on a *different* proposal/approval by the
	 * time a stale or duplicate resolution for the old one arrives, that
	 * resolution must not hijack the new wait.
	 *
	 * @param string $type 'approval' or 'proposal' — must match what this run is awaiting.
	 * @param string $id   Id of the approval/proposal — must match what this run is awaiting.
	 * @return bool True if this call performed the claim (exactly one row
	 *              moved out of 'waiting'); false if the run was no longer
	 *              'waiting' on this specific item (already claimed, resolved
	 *              by something else, or waiting on something newer).
	 */
	public function claim_waiting( string $type, string $id ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'agent_builder_runs';
		$now   = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic claim, keyed by run_id + the specific pending item; %i quotes the table name.
		$claimed = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET status = %s, updated_at = %s WHERE run_id = %s AND status = %s AND awaiting_type = %s AND awaiting_id = %s',
				$table,
				'running',
				$now,
				$this->run_id,
				'waiting',
				$type,
				$id
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
			'messages'              => $this->messages,
			'scratchpad'            => $this->scratchpad,
			'awaiting_type'         => $this->awaiting_type,
			'awaiting_id'           => $this->awaiting_id,
			'awaiting_tool_call_id' => $this->awaiting_tool_call_id,
			'iterations'            => $this->iterations,
			'tools_used'            => array_values( $this->tools_used ),
		);
	}

	/**
	 * Whether the runs table currently has the `awaiting_tool_call_id` column.
	 *
	 * The column is added by a version-independent migration that only runs
	 * from admin_init (Activator::maybe_upgrade()), so a cron/REST/frontend
	 * request on a not-yet-migrated site sees a table without it. mark_waiting()
	 * uses this to skip the column in its UPDATE rather than fail the whole
	 * write (and leave the run stuck at 'running') on an unknown column.
	 *
	 * @return bool True when the column exists.
	 */
	private static function has_awaiting_tool_call_id_column(): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'agent_builder_runs';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is $wpdb->prefix-derived internal name, not user input.
		$column = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'awaiting_tool_call_id' ) );

		return is_string( $column ) && '' !== $column;
	}

	/**
	 * Mark this run as handed off to a background continuation job after the
	 * ~70% elapsed-time guard fires, without transitioning to a terminal
	 * status — so a resumed request can pick it back up.
	 *
	 * Like mark_waiting(), this settles the in-process instance so the
	 * shutdown safety net does not overwrite the hand-off with 'aborted' when
	 * the current request ends.
	 *
	 * @return void
	 */
	public function mark_continuing(): void {
		$this->status = 'continuing';

		$now = current_time( 'mysql', true );

		$persisted = $this->persist(
			array(
				'status'     => $this->status,
				'updated_at' => $now,
			),
			array( '%s', '%s' )
		);

		$this->updated_at = $now;

		// Only settle this in-process instance once the DB write has actually
		// landed. A transient write failure leaves the row still 'running';
		// keeping $finished false lets the shutdown safety net mark it
		// 'aborted' at request end instead of stranding it.
		if ( ! $persisted ) {
			return;
		}

		$this->finished = true;

		if ( self::$current === $this ) {
			self::$current = null;
		}
	}

	/**
	 * Atomically claim this run for resume, transitioning it from a
	 * non-terminal hand-off status ('waiting' or 'continuing') to 'running'
	 * in a single compare-and-set UPDATE.
	 *
	 * A caller resuming a run typically reads its status via get_status()
	 * first, but that read and the subsequent work (calling the LLM,
	 * executing the pending tool call) are not atomic with each other — two
	 * concurrent resume attempts for the same run_id (a duplicate job
	 * dispatch, or a retry racing the original) could otherwise both pass
	 * that check and both execute the same pending tool call. This method
	 * closes that window: only the request whose UPDATE actually matches a
	 * row still in 'waiting'/'continuing' wins the claim.
	 *
	 * @return bool True if this call claimed the run, false if another
	 *              process already claimed it (or it is no longer in a
	 *              resumable status).
	 */
	public function claim_resume(): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'agent_builder_runs';
		$now   = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic compare-and-set keyed by run_id + current status; no caching benefit.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = %s, updated_at = %s WHERE run_id = %s AND status IN ('waiting','continuing')",
				$table,
				'running',
				$now,
				$this->run_id
			)
		);

		if ( 1 !== $updated ) {
			return false;
		}

		$this->status     = 'running';
		$this->updated_at = $now;
		$this->finished   = false;

		return true;
	}

	/**
	 * Atomically claim this queued run out of 'queued' into 'running'.
	 *
	 * The controller adopts a queued run when a fresh background job starts:
	 * only the worker whose UPDATE actually flips the row from 'queued' to
	 * 'running' wins. A duplicate dispatch, or a retry racing the original,
	 * loses the claim and must not re-adopt the row.
	 *
	 * @return bool True if this call performed the claim (exactly one row
	 *              moved out of 'queued'); false if the run was no longer
	 *              'queued' (already claimed, or finished by something else).
	 */
	public function claim_queued(): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'agent_builder_runs';
		$now   = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic compare-and-set keyed by run_id + status='queued'; no caching benefit.
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET status = %s, updated_at = %s WHERE run_id = %s AND status = %s',
				$table,
				'running',
				$now,
				$this->run_id,
				'queued'
			)
		);

		if ( 1 !== $updated ) {
			return false;
		}

		$this->status     = 'running';
		$this->updated_at = $now;

		return true;
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
	 * Finish the run and persist the final state. Idempotent — also fires
	 * `agent_builder_run_finished` exactly once, guarded by the same
	 * `$this->finished` check so every terminal path (normal completion,
	 * failure, abort, or Run_Resumer's reject/failed-resume paths) notifies
	 * subscribers without double-firing.
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

		do_action( 'agent_builder_run_finished', $this );
	}

	/**
	 * Full snapshot of the run, for REST/return payloads.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'run_id'                => $this->run_id,
			'root_agent'            => $this->root_agent,
			'kind'                  => $this->kind,
			'status'                => $this->status,
			'user_id'               => $this->user_id,
			'task_text'             => $this->task_text,
			'parent_run_id'         => $this->parent_run_id,
			'job_id'                => $this->job_id,
			'session_id'            => $this->session_id,
			'invocation'            => $this->invocation,
			'source_ref'            => $this->source_ref,
			'depth'                 => $this->depth,
			'delegations'           => $this->delegations,
			'max_depth'             => $this->max_depth_reached,
			'iterations'            => $this->iterations,
			'tokens_used'           => $this->tokens,
			'cost'                  => round( $this->cost, 6 ),
			'tools_used'            => array_values( $this->tools_used ),
			'result_summary'        => array(
				'text'  => $this->result_text,
				'cards' => $this->result_cards,
			),
			'error'                 => $this->error,
			'awaiting_type'         => $this->awaiting_type,
			'awaiting_id'           => $this->awaiting_id,
			'awaiting_tool_call_id' => $this->awaiting_tool_call_id,
			'cancel_requested'      => $this->cancel_requested,
			'started_at'            => $this->started_at,
			'updated_at'            => $this->updated_at,
			'finished_at'           => $this->finished_at,
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
	 * Count runs currently paused in 'waiting' (awaiting an approval/proposal).
	 *
	 * Used by the Tasks admin menu badge and the dashboard Tasks card. Pass a
	 * user id to scope to that owner, or 0 for the global total (admins see
	 * every user's waiting runs, mirroring the global Approvals badge).
	 *
	 * @param int $user_id Owning user id, or 0 for all users.
	 * @return int Number of waiting runs.
	 */
	public static function count_waiting( int $user_id = 0 ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'agent_builder_runs';

		if ( $user_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Small aggregate, not worth caching.
			$count = $wpdb->get_var(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is $wpdb->prefix-derived; only user_id is bound via prepare().
				$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = 'waiting' AND user_id = %d", $user_id )
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is $wpdb->prefix-derived; no user input in the query.
			$count = $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'waiting'" );
		}

		return (int) $count;
	}

	/**
	 * Count runs currently active (queued, running, or continuing).
	 *
	 * Used by the dashboard Tasks card to report an exact active total that
	 * is not capped by the paginated run listing. Pass a user id to scope to
	 * that owner, or 0 for the global total (admins see every user's active
	 * runs, mirroring the global Approvals badge).
	 *
	 * @param int $user_id Owning user id, or 0 for all users.
	 * @return int Number of active runs.
	 */
	public static function count_active( int $user_id = 0 ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'agent_builder_runs';

		if ( $user_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Small aggregate, not worth caching.
			$count = $wpdb->get_var(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is $wpdb->prefix-derived; only user_id is bound via prepare().
				$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status IN ('queued','running','continuing') AND user_id = %d", $user_id )
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is $wpdb->prefix-derived; no user input in the query.
			$count = $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status IN ('queued','running','continuing')" );
		}

		return (int) $count;
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

		$run->run_id                = (string) ( $row['run_id'] ?? $run->run_id );
		$run->kind                  = (string) ( $row['kind'] ?? 'task' );
		$run->status                = (string) ( $row['status'] ?? 'running' );
		$run->user_id               = (int) ( $row['user_id'] ?? 0 );
		$run->task_text             = (string) ( $row['task_text'] ?? '' );
		$run->parent_run_id         = (string) ( $row['parent_run_id'] ?? '' );
		$run->job_id                = (string) ( $row['job_id'] ?? '' );
		$run->session_id            = (string) ( $row['session_id'] ?? '' );
		$run->invocation            = (string) ( $row['invocation'] ?? '' );
		$run->source_ref            = (string) ( $row['source_ref'] ?? '' );
		$run->delegations           = (int) ( $row['delegations'] ?? 0 );
		$run->max_depth_reached     = (int) ( $row['max_depth'] ?? 0 );
		$run->iterations            = (int) ( $row['iterations'] ?? 0 );
		$run->tokens                = (int) ( $row['tokens_used'] ?? 0 );
		$run->cost                  = (float) ( $row['cost'] ?? 0.0 );
		$run->tools_used            = self::decode_list( $row['tools_used'] ?? '' );
		$run->error                 = (string) ( $row['error'] ?? '' );
		$run->awaiting_type         = (string) ( $row['awaiting_type'] ?? '' );
		$run->awaiting_id           = (string) ( $row['awaiting_id'] ?? '' );
		$run->awaiting_tool_call_id = (string) ( $row['awaiting_tool_call_id'] ?? '' );
		$run->cancel_requested      = ! empty( $row['cancel_requested'] );
		$run->started_at            = (string) ( $row['started_at'] ?? '' );
		$run->updated_at            = (string) ( $row['updated_at'] ?? '' );
		$run->finished_at           = (string) ( $row['finished_at'] ?? '' );

		$summary           = self::decode_assoc( $row['result_summary'] ?? '' );
		$run->result_text  = (string) ( $summary['text'] ?? '' );
		$run->result_cards = is_array( $summary['cards'] ?? null ) ? $summary['cards'] : array();

		$state = self::decode_assoc( $row['state'] ?? '' );

		// Two shapes have ever been written to this column: the current
		// (>= 2.15.0) {scratchpad, messages} wrapper, and the legacy
		// pre-2.15.0 shape where the whole decoded value *is* the scratchpad
		// (with no transcript). Tell them apart by shape alone — exactly two
		// keys, a 'scratchpad' array plus a 'messages' list of role-bearing
		// message objects — and treat anything else (a legacy flat scratchpad
		// that happens to hold similarly-named keys, or an empty/garbled
		// value) as the legacy scratchpad with no transcript.
		$has_wrapper = 2 === count( $state )
			&& array_key_exists( 'scratchpad', $state )
			&& is_array( $state['scratchpad'] )
			&& array_key_exists( 'messages', $state )
			&& is_array( $state['messages'] )
			&& self::is_transcript_list( $state['messages'] );

		if ( $has_wrapper ) {
			$run->scratchpad = $state['scratchpad'];
			$run->messages   = $state['messages'];
		} else {
			$run->scratchpad = $state;
			$run->messages   = array();
		}

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
	 * Whether a decoded state's "messages" value has the current (>= 2.15.0)
	 * transcript shape: a list of message objects, each carrying a `role` key.
	 *
	 * The current shape's messages are always produced by sanitize_transcript(),
	 * which re-indexes with array_values() (so always a list) over message
	 * objects built with a `role` key. This is what distinguishes the current
	 * {scratchpad, messages} wrapper from a legacy flat scratchpad that happens
	 * to hold a "messages"-shaped key of its own (see from_row()) — which
	 * scratch_set() never writes.
	 *
	 * @param array $messages Candidate messages value.
	 * @return bool
	 */
	private static function is_transcript_list( array $messages ): bool {
		if ( array() === $messages ) {
			return true;
		}

		$expected = 0;
		foreach ( $messages as $index => $message ) {
			if ( $index !== $expected ) {
				return false; // Not a list.
			}
			if ( ! is_array( $message ) || ! array_key_exists( 'role', $message ) ) {
				return false;
			}
			++$expected;
		}

		return true;
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
		$stripped = array_values( array_map( array( self::class, 'strip_message_images' ), $messages ) );

		// Never drop the system message: providers reject a conversation whose
		// first message is not the system prompt, so it is held aside while the
		// rest of the transcript is capped.
		$system = array();
		if ( ! empty( $stripped ) && 'system' === ( $stripped[0]['role'] ?? '' ) ) {
			$system = array( array_shift( $stripped ) );
		}

		// Sweep any leading orphan tool messages (a tool message with no
		// preceding assistant call) — invalid on their own, and a provider
		// would reject them as the first message of a resumed conversation.
		while ( ! empty( $stripped ) && 'tool' === ( $stripped[0]['role'] ?? '' ) ) {
			array_shift( $stripped );
		}

		// Drop the oldest whole turns (an assistant message together with all
		// its tool results) until the remaining transcript fits the cap.
		// Dropping a turn as a unit — plus the leading orphan-tool sweep inside
		// drop_oldest_turn() — guarantees no tool message survives its
		// assistant call, which providers reject on resume.
		while ( ! empty( $stripped ) ) {
			$encoded = wp_json_encode( array_merge( $system, $stripped ) );
			if ( is_string( $encoded ) && strlen( $encoded ) <= self::TRANSCRIPT_CAP_BYTES ) {
				break;
			}
			$stripped = self::drop_oldest_turn( $stripped );
		}

		return array_merge( $system, $stripped );
	}

	/**
	 * Drop the oldest turn from the front of a transcript.
	 *
	 * A "turn" is an assistant message together with the tool-result messages
	 * that immediately follow it (the results of its tool_calls). Leading
	 * orphan tool messages — a tool message with no preceding assistant call —
	 * are invalid on their own and are swept first, so they can never survive
	 * as the first message of a resumed conversation.
	 *
	 * @param array $messages Transcript without its system message.
	 * @return array Re-indexed transcript with one fewer turn.
	 */
	private static function drop_oldest_turn( array $messages ): array {
		while ( ! empty( $messages ) && 'tool' === ( $messages[0]['role'] ?? '' ) ) {
			array_shift( $messages );
		}

		if ( empty( $messages ) ) {
			return array();
		}

		$first = array_shift( $messages );
		if ( 'assistant' === ( $first['role'] ?? '' ) && ! empty( $first['tool_calls'] ) ) {
			while ( ! empty( $messages ) && 'tool' === ( $messages[0]['role'] ?? '' ) ) {
				array_shift( $messages );
			}
		}

		return array_values( $messages );
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
	 * @return bool True when the write actually landed, false on a DB error
	 *              (a $wpdb->update() false return). Callers that settle the
	 *              in-process instance (mark_continuing()) must gate on this,
	 *              so a transient failure does not disable the shutdown net
	 *              while the row is still 'running' in storage.
	 */
	private function persist( array $fields, array $formats ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'agent_builder_runs';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table update, keyed by run_id.
		return false !== $wpdb->update( $table, $fields, array( 'run_id' => $this->run_id ), $formats, array( '%s' ) );
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
