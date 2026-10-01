<?php
/**
 * Runs REST — list, create, detail, cancel and retry for autonomous runs.
 *
 * Backs the Tasks screen's list and detail drawer: the frontend assigns a task
 * here (which creates a queued Agent_Run and hands it to a background job),
 * polls the list while a run is active, and cancels/retries from the drawer.
 *
 * @package    Agent_Builder
 * @subpackage REST
 * @since      4.1.0
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs REST controller.
 */
class Runs_REST {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	const NS = 'agentic/v1';

	/**
	 * Number of transcript messages surfaced in the detail excerpt. The full
	 * transcript is already capped for persistence by Agent_Run; this keeps the
	 * REST payload small while still showing recent context.
	 *
	 * @var int
	 */
	const TRANSCRIPT_EXCERPT_MESSAGES = 20;

	/**
	 * Boot.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Routes.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::NS,
			'/runs',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_runs' ),
				'permission_callback' => array( __CLASS__, 'can_list_runs' ),
				'args'                => array(
					'status'   => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
					'agent'    => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
					'kind'     => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
					'user'     => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'per_page' => array(
						'type'              => 'integer',
						'default'           => 20,
						'sanitize_callback' => 'absint',
					),
					'page'     => array(
						'type'              => 'integer',
						'default'           => 1,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/runs',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'create_run' ),
				'permission_callback' => array( __CLASS__, 'can_run' ),
				'args'                => array(
					'agent_id'   => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
					'task'       => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'skill_slug' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/runs/(?P<run_id>[a-zA-Z0-9_.-]+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_run' ),
				'permission_callback' => array( __CLASS__, 'can_access_run' ),
			)
		);

		register_rest_route(
			self::NS,
			'/runs/(?P<run_id>[a-zA-Z0-9_.-]+)/cancel',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'cancel_run' ),
				'permission_callback' => array( __CLASS__, 'can_access_run' ),
			)
		);

		register_rest_route(
			self::NS,
			'/runs/(?P<run_id>[a-zA-Z0-9_.-]+)/retry',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'retry_run' ),
				'permission_callback' => array( __CLASS__, 'can_access_run' ),
			)
		);
	}

	/**
	 * View capability — listing and reading runs.
	 *
	 * @return bool
	 */
	public static function can_view(): bool {
		return current_user_can( 'manage_options' ) || current_user_can( 'agent_builder_view_dashboard' );
	}

	/**
	 * List capability — a user who can view the dashboard OR run tasks manually
	 * may list runs. The handler still scopes non-admins to their own runs, so
	 * run_tasks_manually alone never leaks another user's run.
	 *
	 * @return bool
	 */
	public static function can_list_runs(): bool {
		return self::can_view() || self::can_run();
	}

	/**
	 * Run capability — creating/cancelling/retrying a run.
	 *
	 * @return bool
	 */
	public static function can_run(): bool {
		return current_user_can( 'manage_options' ) || current_user_can( 'agent_builder_run_tasks_manually' );
	}

	/**
	 * Agent-management capability — governs cross-user access to runs.
	 *
	 * @return bool
	 */
	public static function can_manage_agents(): bool {
		return current_user_can( 'manage_options' ) || current_user_can( 'agent_builder_manage_agents' );
	}

	/**
	 * Gate for the detail/cancel/retry routes. Any of the three run-relevant
	 * capabilities admits a caller to the handler, which then applies the finer
	 * owner/manage_agents check against the specific run.
	 *
	 * @return bool
	 */
	public static function can_access_run(): bool {
		return self::can_view() || self::can_run() || self::can_manage_agents();
	}

	/**
	 * GET /runs — filtered, paginated list.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function get_runs( \WP_REST_Request $request ): \WP_REST_Response {
		$args = array();

		$status = sanitize_key( (string) $request->get_param( 'status' ) );
		if ( '' !== $status ) {
			$args['status'] = $status;
		}

		$agent = sanitize_key( (string) $request->get_param( 'agent' ) );
		if ( '' !== $agent ) {
			$args['agent'] = $agent;
		}

		$kind = sanitize_key( (string) $request->get_param( 'kind' ) );
		if ( '' !== $kind ) {
			$args['kind'] = $kind;
		}

		// Non-admins (no manage_agents) only ever see their own runs, regardless
		// of any `user` param they pass. Admins may filter by an explicit user.
		if ( self::can_manage_agents() ) {
			$user = $request->get_param( 'user' );
			if ( isset( $user ) && '' !== $user ) {
				$args['user_id'] = (int) $user;
			}
		} else {
			$args['user_id'] = get_current_user_id();
		}

		$args['per_page'] = max( 1, min( 200, (int) $request->get_param( 'per_page' ) ) );
		$args['page']     = max( 1, (int) $request->get_param( 'page' ) );

		// Opportunistic self-heal: a pending run whose backing job lost its
		// WP-Cron event would otherwise sit 'pending' forever. The Tasks screen
		// polls this endpoint, so re-arming the event here recovers it promptly.
		Job_Manager::reschedule_stale_pending_jobs();

		$runs = Agent_Run::query( $args );

		/**
		 * Filters the GET /runs result set.
		 *
		 * No-op in the free build — Pro injects team runs and trace links here.
		 *
		 * @param array           $runs    Run rows (Agent_Run::to_array() shape).
		 * @param \WP_REST_Request $request The current request.
		 */
		$runs = apply_filters( 'agent_builder_runs_list', $runs, $request );

		return new \WP_REST_Response( array( 'runs' => $runs ), 200 );
	}

	/**
	 * POST /runs — create a queued run and dispatch its background job.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function create_run( \WP_REST_Request $request ) {
		$agent_id = sanitize_key( (string) $request->get_param( 'agent_id' ) );
		$task     = (string) $request->get_param( 'task' );

		if ( '' === $agent_id ) {
			return new \WP_Error( 'missing_agent', __( 'An agent is required.', 'agent-builder' ), array( 'status' => 400 ) );
		}

		if ( '' === trim( $task ) ) {
			return new \WP_Error( 'missing_task', __( 'A task description is required.', 'agent-builder' ), array( 'status' => 400 ) );
		}

		// Validate the agent against the real registry before creating anything,
		// and that the current user may actually reach it (the Tasks composer
		// only lists accessible agents, so an out-of-list slug must be refused).
		$error = self::validate_agent_access( $agent_id );
		if ( null !== $error ) {
			return $error;
		}

		/**
		 * Filters a task dispatch just before the run is queued, after the agent
		 * has passed access validation and before any run row exists.
		 *
		 * A callback returning a \WP_REST_Response or \WP_Error short-circuits
		 * the request (the value is returned as-is and no run is created); any
		 * other value (null) lets the run proceed normally.
		 *
		 * @param mixed            $pre      Null by default.
		 * @param string           $agent_id Agent slug.
		 * @param string           $task     Task description.
		 * @param \WP_REST_Request $request  Raw request (Pro reads `team`/`members`).
		 */
		$dispatch = apply_filters( 'agent_builder_before_task_dispatch', null, $agent_id, $task, $request );
		if ( $dispatch instanceof \WP_REST_Response || is_wp_error( $dispatch ) ) {
			return $dispatch;
		}

		// create_queued() (not begin()): the run must survive the creating
		// request so the WP-Cron worker can adopt it later (see wp#230).
		$run = Agent_Run::create_queued(
			$agent_id,
			array(
				'kind'       => 'task',
				'user_id'    => get_current_user_id(),
				'task_text'  => $task,
				'invocation' => 'rest',
				'source_ref' => 'rest',
			)
		);

		$extra = array();
		$skill = sanitize_key( (string) $request->get_param( 'skill_slug' ) );
		if ( '' !== $skill ) {
			$extra['skill_slug'] = $skill;
		}

		$job_id = Agent_Task_Job_Processor::dispatch( $run, $extra );

		return new \WP_REST_Response(
			array(
				'run'    => $run->to_array(),
				'job_id' => $job_id,
			),
			201
		);
	}

	/**
	 * GET /runs/{run_id} — row, awaiting payload, steps and transcript excerpt.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get_run( \WP_REST_Request $request ) {
		$run = self::load_run( $request );
		if ( is_wp_error( $run ) ) {
			return $run;
		}

		$data = $run->to_array();

		if ( ! self::is_owner( $data ) && ! self::can_manage_agents() ) {
			return new \WP_Error( 'forbidden', __( 'Permission denied.', 'agent-builder' ), array( 'status' => 403 ) );
		}

		$payload = array(
			'run'                => $data,
			'awaiting'           => self::awaiting_payload( $run ),
			'steps'              => self::run_steps( (string) $data['run_id'] ),
			'transcript_excerpt' => self::transcript_excerpt( $run ),
		);

		/**
		 * Filters the GET /runs/{run_id} payload.
		 *
		 * No-op in the free build — Pro injects team-run badges and trace links.
		 *
		 * @param array           $payload Row + awaiting + steps + transcript excerpt.
		 * @param Agent_Run       $run     The loaded run.
		 * @param \WP_REST_Request $request The current request.
		 */
		$payload = apply_filters( 'agent_builder_run_detail', $payload, $run, $request );

		return new \WP_REST_Response( $payload, 200 );
	}

	/**
	 * POST /runs/{run_id}/cancel — cooperative cancel.
	 *
	 * Sets the same cancel_requested flag the autonomous loop polls (via
	 * Agent_Run::request_cancel()), rather than any second mechanism.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function cancel_run( \WP_REST_Request $request ) {
		$run = self::load_run( $request );
		if ( is_wp_error( $run ) ) {
			return $run;
		}

		if ( ! self::can_cancel( $run ) ) {
			return new \WP_Error( 'forbidden', __( 'Permission denied.', 'agent-builder' ), array( 'status' => 403 ) );
		}

		$run->request_cancel();

		return new \WP_REST_Response( array( 'run' => $run->to_array() ), 200 );
	}

	/**
	 * POST /runs/{run_id}/retry — clone task_text into a brand-new run.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function retry_run( \WP_REST_Request $request ) {
		$run = self::load_run( $request );
		if ( is_wp_error( $run ) ) {
			return $run;
		}

		if ( ! self::can_cancel( $run ) ) {
			return new \WP_Error( 'forbidden', __( 'Permission denied.', 'agent-builder' ), array( 'status' => 403 ) );
		}

		$data = $run->to_array();
		$task = (string) $data['task_text'];

		if ( '' === trim( $task ) ) {
			return new \WP_Error( 'empty_task', __( 'This run has no task text to retry.', 'agent-builder' ), array( 'status' => 400 ) );
		}

		// A still-active run must not be retried: cloning a queued/running run
		// would spawn a second concurrent run against the same task_text. The
		// same holds for a run paused on the user (`waiting`) or mid-resume
		// (`continuing`) — none of the non-terminal states may be retried.
		if ( in_array( $data['status'], array( 'queued', 'running', 'continuing', 'waiting' ), true ) ) {
			return new \WP_Error( 'already_active', __( 'This run is still active and cannot be retried.', 'agent-builder' ), array( 'status' => 409 ) );
		}

		// Validate the agent against the real registry before creating the retry
		// (the original run's agent may have been removed since it ran), and that
		// the current user may still reach it.
		$error = self::validate_agent_access( (string) $data['root_agent'] );
		if ( null !== $error ) {
			return $error;
		}

		// Carry the original run's skill_slug forward so a retry is dispatched
		// with the same Pro-boundary extra the original had.
		$extra = array();
		$skill = self::recover_skill_slug( (string) $data['run_id'] );
		if ( '' !== $skill ) {
			$extra['skill_slug'] = $skill;
		}

		// A retry is a brand-new queued run, never a resume of the old row.
		$retry = Agent_Run::create_queued(
			(string) $data['root_agent'],
			array(
				'kind'       => 'task',
				'user_id'    => get_current_user_id(),
				'task_text'  => $task,
				'invocation' => 'rest',
				'source_ref' => 'rest',
			)
		);

		$job_id = Agent_Task_Job_Processor::dispatch( $retry, $extra );

		return new \WP_REST_Response(
			array(
				'run'    => $retry->to_array(),
				'job_id' => $job_id,
			),
			201
		);
	}

	/**
	 * Load a run by the route's run_id, or return a 404 WP_Error.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return Agent_Run|\WP_Error
	 */
	private static function load_run( \WP_REST_Request $request ) {
		$run_id = sanitize_text_field( (string) $request->get_param( 'run_id' ) );
		$run    = Agent_Run::load( $run_id );

		if ( null === $run ) {
			return new \WP_Error( 'not_found', __( 'Run not found.', 'agent-builder' ), array( 'status' => 404 ) );
		}

		return $run;
	}

	/**
	 * Whether the current user owns the given run row.
	 *
	 * @param array $data Run row from Agent_Run::to_array().
	 * @return bool
	 */
	private static function is_owner( array $data ): bool {
		return get_current_user_id() === (int) $data['user_id'];
	}

	/**
	 * Whether the current user may cancel or retry this run: the owner, or
	 * anyone with manage_agents.
	 *
	 * run_tasks_manually alone does not grant cross-user access — a caller with
	 * that capability may still cancel/retry their *own* runs (they are the
	 * owner), but only manage_agents reaches another user's run, matching
	 * get_run()'s owner-or-manage_agents gate.
	 *
	 * @param Agent_Run $run Run.
	 * @return bool
	 */
	private static function can_cancel( Agent_Run $run ): bool {
		if ( self::can_manage_agents() ) {
			return true;
		}

		return self::is_owner( $run->to_array() );
	}

	/**
	 * Recover the skill_slug the original run was dispatched with, from its
	 * backing job's request_data (skill_slug is not a column on the run row, so
	 * it can only be read back out of the dispatched job).
	 *
	 * @param string $run_id Run identifier.
	 * @return string Sanitized skill slug, or '' when none was recorded.
	 */
	private static function recover_skill_slug( string $run_id ): string {
		global $wpdb;
		$table = $wpdb->prefix . 'agent_builder_jobs';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Single-row lookup to recover the run's dispatch extra.
		$request = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT request_data FROM %i WHERE request_data LIKE %s ORDER BY created_at ASC, id ASC LIMIT 1',
				$table,
				'%"run_id":"' . $wpdb->esc_like( $run_id ) . '"%'
			)
		);

		if ( ! is_string( $request ) || '' === $request ) {
			return '';
		}

		$decoded = json_decode( $request, true );
		if ( ! is_array( $decoded ) ) {
			return '';
		}

		return sanitize_key( (string) ( $decoded['skill_slug'] ?? '' ) );
	}

	/**
	 * Validate an agent slug for create/retry: it must exist in the registry and
	 * be in the current user's accessible list (the same list the Tasks composer
	 * renders). An unknown slug is 400; a known-but-inaccessible slug is 403.
	 *
	 * @param string $agent_id Agent slug.
	 * @return \WP_Error|null Error to return, or null when the agent is usable.
	 */
	private static function validate_agent_access( string $agent_id ): ?\WP_Error {
		$registry = \Agentic_Agent_Registry::get_instance();

		if ( null === $registry->get_agent_instance( $agent_id ) ) {
			return new \WP_Error(
				'invalid_agent',
				/* translators: %s: agent slug. */
				sprintf( __( 'Unknown agent "%s".', 'agent-builder' ), $agent_id ),
				array( 'status' => 400 )
			);
		}

		if ( ! isset( $registry->get_accessible_instances()[ $agent_id ] ) ) {
			return new \WP_Error(
				'forbidden',
				__( 'You do not have access to this agent.', 'agent-builder' ),
				array( 'status' => 403 )
			);
		}

		return null;
	}

	/**
	 * Build the "awaiting" payload when the run is paused on an approval or
	 * proposal, reusing the same shapes the approval-queue REST already serves.
	 *
	 * @param Agent_Run $run Run.
	 * @return array|null Pending item payload, or null when not waiting.
	 */
	private static function awaiting_payload( Agent_Run $run ): ?array {
		$type = (string) $run->to_array()['awaiting_type'];
		$id   = (string) $run->to_array()['awaiting_id'];

		if ( '' === $type || '' === $id ) {
			return null;
		}

		if ( 'approval' === $type ) {
			global $wpdb;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Single-row lookup for the run's pending approval.
			$row = $wpdb->get_row(
				$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $wpdb->prefix . 'agent_builder_approval_queue', (int) $id ),
				ARRAY_A
			);

			if ( ! is_array( $row ) ) {
				return null;
			}

			// Mirrors Approval_Queue::get_pending()'s decoded `params`.
			$row['params'] = json_decode( (string) ( $row['params'] ?? '[]' ), true );

			// A title/status/type summary of the tool arguments, matching the
			// chat proposal card's summary, so the Tasks approval card is not
			// blind about *what* the tool is about to touch.
			$row['summary'] = Tool_Executor::summarize_arguments( is_array( $row['params'] ) ? $row['params'] : array() );

			return $row;
		}

		if ( 'proposal' === $type ) {
			return Agent_Proposals::get( $id );
		}

		return null;
	}

	/**
	 * Audit-log steps for a run, oldest first.
	 *
	 * @param string $run_id Run identifier.
	 * @return array<int, array<string, mixed>>
	 */
	private static function run_steps( string $run_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Filtered step list for a single run.
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE run_id = %s ORDER BY created_at ASC, id ASC', $wpdb->prefix . 'agent_builder_audit_log', $run_id ),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		// Internal bookkeeping (e.g. which duplicate bridged tools were hidden
		// from the model) stays in the audit log but is not a step the user
		// needs to see, so keep it out of the run's step list.
		$hidden = (array) apply_filters( 'agent_builder_run_steps_hidden_actions', array( 'tool_suppressed' ) );
		$rows   = array_values(
			array_filter(
				$rows,
				static fn( $row ) => ! in_array( (string) ( $row['action'] ?? '' ), $hidden, true )
			)
		);

		foreach ( $rows as &$row ) {
			$decoded = json_decode( (string) ( $row['details'] ?? '' ), true );
			if ( is_array( $decoded ) ) {
				$row['details'] = $decoded;
			}
		}
		unset( $row );

		return $rows;
	}

	/**
	 * Last N transcript messages, reusing Agent_Run's own (already capped and
	 * image-stripped) transcript accessor.
	 *
	 * @param Agent_Run $run Run.
	 * @return array<int, mixed>
	 */
	private static function transcript_excerpt( Agent_Run $run ): array {
		$messages = $run->resume_state()['messages'] ?? array();

		return array_slice( $messages, -self::TRANSCRIPT_EXCERPT_MESSAGES );
	}
}
