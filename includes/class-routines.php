<?php
/**
 * Routines data-access layer.
 *
 * A "routine" is a user-created scheduled task or event listener: a Deployments
 * row of type TYPE_SCHEDULED_TASK or TYPE_EVENT_LISTENER whose config['source']
 * is 'user' — the option-backed kind written by
 * Agent_Lifecycle::save_user_scheduled_task() / save_user_trigger(). Built-in or
 * code-sourced deployments are not routines and are left alone.
 *
 * @package Agentic
 */

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Routines data-access layer.
 *
 * List / pause / resume / history / next_run over user-defined scheduled tasks
 * and event listeners.
 */
class Routines {

	/**
	 * Whether a Deployments row is a routine (a user-created scheduled task or
	 * event listener).
	 *
	 * @param array $row Decoded Deployments row.
	 * @return bool
	 */
	private static function is_routine( array $row ): bool {
		$type = (string) ( $row['type'] ?? '' );
		if ( Deployments::TYPE_SCHEDULED_TASK !== $type && Deployments::TYPE_EVENT_LISTENER !== $type ) {
			return false;
		}

		return 'user' === (string) ( $row['config']['source'] ?? '' );
	}

	/**
	 * List routines, optionally scoped to a single agent.
	 *
	 * @param string $agent_slug Agent slug to filter by (empty = all agents).
	 * @return array<int, array<string, mixed>> Decoded Deployments rows, each decorated with `next_run`.
	 */
	public static function list( string $agent_slug = '' ): array {
		$routines = array();

		foreach ( array( Deployments::TYPE_SCHEDULED_TASK, Deployments::TYPE_EVENT_LISTENER ) as $type ) {
			foreach ( Deployments::all( $type, $agent_slug ) as $row ) {
				if ( ! self::is_routine( $row ) ) {
					continue;
				}
				$row['next_run'] = self::next_run( (int) $row['id'] );
				$routines[]      = $row;
			}
		}

		return $routines;
	}

	/**
	 * Pause a routine so it stops executing.
	 *
	 * For a scheduled task this clears its WP-Cron event, so the task really
	 * stops — not just its listing. For an event listener this flips the
	 * Deployments row's enabled flag, which
	 * Agent_Lifecycle::execute_event_listener() gates on (a real enforcement
	 * point, not a cosmetic one).
	 *
	 * @param int $id Routine (Deployments row) ID.
	 * @return array{ok:bool,error?:string}
	 */
	public static function pause( int $id ): array {
		return self::set_paused( $id, true );
	}

	/**
	 * Resume a paused routine.
	 *
	 * @param int $id Routine (Deployments row) ID.
	 * @return array{ok:bool,error?:string}
	 */
	public static function resume( int $id ): array {
		return self::set_paused( $id, false );
	}

	/**
	 * Shared pause/resume implementation.
	 *
	 * @param int  $id     Routine (Deployments row) ID.
	 * @param bool $paused True to pause, false to resume.
	 * @return array{ok:bool,error?:string}
	 */
	private static function set_paused( int $id, bool $paused ): array {
		$row = Deployments::get( $id );
		if ( null === $row ) {
			return array(
				'ok'    => false,
				'error' => __( 'Routine not found.', 'agent-builder' ),
			);
		}

		if ( ! self::is_routine( $row ) ) {
			return array(
				'ok'    => false,
				'error' => __( 'Not a routine.', 'agent-builder' ),
			);
		}

		$config     = $row['config'] ?? array();
		$agent_slug = (string) ( $row['agent_slug'] ?? '' );
		$type       = (string) ( $row['type'] ?? '' );

		if ( Deployments::TYPE_SCHEDULED_TASK === $type ) {
			$task_id = (string) ( $config['task_id'] ?? '' );
			$hook    = Agent_Lifecycle::user_task_cron_hook( $agent_slug, $task_id );

			wp_clear_scheduled_hook( $hook );

			if ( ! $paused ) {
				$schedule = (string) ( $config['schedule'] ?? 'daily' );
				wp_schedule_event( time(), $schedule, $hook );
			}
		}

		Deployments::update_config( $id, array( 'paused_at' => $paused ? current_time( 'mysql' ) : null ) );

		if ( $paused ) {
			Deployments::disable( $id );
		} else {
			Deployments::enable( $id );
		}

		return array( 'ok' => true );
	}

	/**
	 * Run history for a routine.
	 *
	 * Runs are written with a `routine:<id>` source_ref (id = the Deployments
	 * row's own integer id, see Agent_Lifecycle::execute_scheduled_task() /
	 * handle_async_event()), so this query matches the runs those execution
	 * paths actually create.
	 *
	 * @param int $id    Routine (Deployments row) ID.
	 * @param int $limit Maximum number of runs to return.
	 * @return array<int, array<string, mixed>>
	 */
	public static function history( int $id, int $limit = 20 ): array {
		return Agent_Run::query(
			array(
				'source_ref' => 'routine:' . $id,
				'per_page'   => $limit,
			)
		);
	}

	/**
	 * Next run time for a routine, formatted in the site's local date/time.
	 *
	 * @param int $id Routine (Deployments row) ID.
	 * @return string|null Formatted next-run time, or null when the routine is
	 *                     event-triggered (no schedule) or nothing is scheduled (paused).
	 */
	public static function next_run( int $id ): ?string {
		$row = Deployments::get( $id );
		if ( null === $row ) {
			return null;
		}

		if ( Deployments::TYPE_SCHEDULED_TASK !== (string) ( $row['type'] ?? '' ) ) {
			return null;
		}

		$config     = $row['config'] ?? array();
		$task_id    = (string) ( $config['task_id'] ?? '' );
		$agent_slug = (string) ( $row['agent_slug'] ?? '' );

		if ( '' === $task_id ) {
			return null;
		}

		$ts = wp_next_scheduled( Agent_Lifecycle::user_task_cron_hook( $agent_slug, $task_id ) );
		if ( false === $ts ) {
			return null;
		}

		return wp_date(
			get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
			$ts,
			wp_timezone()
		);
	}

	/**
	 * Whether a user-defined event listener is paused.
	 *
	 * The execution gate for event listeners: Agent_Lifecycle::execute_event_listener()
	 * consults this before firing a user trigger, so pausing is a real stop
	 * rather than a display-only flag. Built-in manifest listeners carry no
	 * trigger_id-backed Deployments row and are never paused by this path.
	 *
	 * @param string $trigger_id User trigger id (config.trigger_id).
	 * @return bool
	 */
	public static function is_event_listener_paused( string $trigger_id ): bool {
		foreach ( Deployments::all( Deployments::TYPE_EVENT_LISTENER ) as $row ) {
			if ( (string) ( $row['config']['trigger_id'] ?? '' ) === $trigger_id ) {
				return empty( $row['enabled'] );
			}
		}

		return false;
	}

	/**
	 * Find the Deployments row id that mirrors a user-defined scheduled task,
	 * matched on its option-backed task id (config.task_id).
	 *
	 * Mirrors the scan Agent_Lifecycle::save_user_scheduled_task() /
	 * delete_user_scheduled_task() use to locate the mirror row, so the id
	 * linkage stays identical to how those rows are written.
	 *
	 * @param string $task_id Option-backed task id (e.g. us_…).
	 * @return int|null The Deployments row id, or null when no mirror row exists
	 *                  (a built-in/code-sourced task with no Deployments row).
	 */
	public static function deployment_id_for_task( string $task_id ): ?int {
		foreach ( Deployments::all( Deployments::TYPE_SCHEDULED_TASK ) as $row ) {
			if ( (string) ( $row['config']['task_id'] ?? '' ) === $task_id ) {
				return (int) $row['id'];
			}
		}

		return null;
	}

	/**
	 * Find the Deployments row id that mirrors a user-defined event listener,
	 * matched on its option-backed trigger id (config.trigger_id).
	 *
	 * Mirrors the scan Agent_Lifecycle::save_user_trigger() /
	 * delete_user_trigger() use to locate the mirror row.
	 *
	 * @param string $trigger_id Option-backed trigger id (e.g. ut_…).
	 * @return int|null The Deployments row id, or null when no mirror row exists
	 *                  (a built-in manifest listener with no Deployments row).
	 */
	public static function deployment_id_for_trigger( string $trigger_id ): ?int {
		foreach ( Deployments::all( Deployments::TYPE_EVENT_LISTENER ) as $row ) {
			if ( (string) ( $row['config']['trigger_id'] ?? '' ) === $trigger_id ) {
				return (int) $row['id'];
			}
		}

		return null;
	}

	/**
	 * Create or update a routine (a user-created scheduled task or event listener).
	 *
	 * A thin wrapper over Agent_Lifecycle::save_user_scheduled_task() /
	 * save_user_trigger() — the args those methods already accept are passed
	 * through unchanged, and only the Deployments-row linkage they already
	 * dual-write is resolved back, so the caller gets the Deployments row id
	 * every other Routines method keys on.
	 *
	 * `skill_slug` and `timezone` are routine-level config layered onto the
	 * mirror row after the underlying save (which rewrites the whole config
	 * blob, wiping anything not part of its own schema, so this must run after
	 * it). They are stored only — see designs/M14-routines.md "Deferred":
	 * `skill_slug` is not wired into execution because there is no existing
	 * reusable injection path to point at (run_autonomous_task() has no
	 * skill_slug option, and handle_chat() has no /<slug> skill-invocation), so
	 * adding one here would invent a second mechanism; `timezone` is
	 * informational, and next_run() keeps using the site timezone.
	 *
	 * @param array $args {
	 *     @type string $kind        'scheduled_task' or 'event_listener'. Required.
	 *     @type string $id          Existing task/trigger id to update, or '' for a new routine.
	 *     @type string $agent_slug  Required. Agent to run the routine (passed through).
	 *     @type string $name        Optional display name (passed through).
	 *     @type string $prompt      Prompt/instructions (passed through).
	 *     @type string $description Optional description (scheduled_task only, passed through).
	 *     @type string $schedule    Recurrence key (scheduled_task only, passed through).
	 *     @type string $hook        WP action hook (event_listener only, passed through).
	 *     @type int    $priority    add_action() priority (event_listener only, passed through).
	 *     @type string $skill_slug  Optional skill slug to store in the row's config.
	 *     @type string $timezone    Optional timezone to store in the row's config (informational).
	 * }
	 * @return array{ok:bool,id?:int,error?:string}
	 */
	public static function save( array $args ): array {
		$kind       = (string) ( $args['kind'] ?? '' );
		$skill_slug = sanitize_key( (string) ( $args['skill_slug'] ?? '' ) );
		$timezone   = sanitize_text_field( (string) ( $args['timezone'] ?? '' ) );

		unset( $args['kind'], $args['skill_slug'], $args['timezone'] );

		if ( 'scheduled_task' === $kind ) {
			$result = Agent_Lifecycle::save_user_scheduled_task( $args );
		} elseif ( 'event_listener' === $kind ) {
			$result = Agent_Lifecycle::save_user_trigger( $args );
		} else {
			return array(
				'ok'    => false,
				'error' => __( 'Invalid routine kind.', 'agent-builder' ),
			);
		}

		if ( empty( $result['ok'] ) ) {
			return array(
				'ok'    => false,
				'error' => (string) ( $result['error'] ?? '' ),
			);
		}

		$deployment_id = 'scheduled_task' === $kind
			? self::deployment_id_for_task( (string) $result['id'] )
			: self::deployment_id_for_trigger( (string) $result['id'] );

		if ( null === $deployment_id ) {
			return array(
				'ok'    => false,
				'error' => __( 'Routine was saved but its deployment row could not be resolved.', 'agent-builder' ),
			);
		}

		$patch = array();
		if ( '' !== $skill_slug ) {
			$patch['skill_slug'] = $skill_slug;
		}
		if ( '' !== $timezone ) {
			$patch['timezone'] = $timezone;
		}
		if ( ! empty( $patch ) ) {
			Deployments::update_config( $deployment_id, $patch );
		}

		return array(
			'ok' => true,
			'id' => $deployment_id,
		);
	}

	/**
	 * Manually run one execution of a routine right now, outside its schedule or
	 * trigger, for the "Test run" action.
	 *
	 * Look up the routine, confirm it is one, resolve its agent, then run it
	 * synchronously through the same Agent_Lifecycle execution path its schedule
	 * or trigger would use. Execution is currently inline (async dispatch is a
	 * documented deferred item), so once this returns the run has finished; the
	 * resulting run id is read back from the mirror row's last_run_id, written by
	 * Agent_Lifecycle::record_routine_completion().
	 *
	 * @param int                 $id         Routine (Deployments row) ID.
	 * @param int                 $user_id    Accepted for future audit/ownership use; not consumed yet.
	 * @param Agent_Controller|null $controller Optional controller (tests inject a fake-LLM one).
	 * @return array{ok:bool,run_id?:string,error?:string}
	 */
	public static function test_run( int $id, int $user_id, ?Agent_Controller $controller = null ): array {
		unset( $user_id ); // Reserved for future audit/ownership use.

		$row = Deployments::get( $id );
		if ( null === $row ) {
			return array(
				'ok'    => false,
				'error' => __( 'Routine not found.', 'agent-builder' ),
			);
		}

		if ( ! self::is_routine( $row ) ) {
			return array(
				'ok'    => false,
				'error' => __( 'Not a routine.', 'agent-builder' ),
			);
		}

		$agent_slug = (string) ( $row['agent_slug'] ?? '' );
		$type       = (string) ( $row['type'] ?? '' );
		$config     = $row['config'] ?? array();

		$agent = \Agentic_Agent_Registry::get_instance()->get_agent_instance( $agent_slug );
		if ( ! $agent ) {
			return array(
				'ok'    => false,
				'error' => __( 'Agent not found or not active.', 'agent-builder' ),
			);
		}

		if ( Deployments::TYPE_SCHEDULED_TASK === $type ) {
			$task_id   = (string) ( $config['task_id'] ?? '' );
			$user_task = Agent_Lifecycle::find_user_scheduled_task( $task_id );
			if ( null === $user_task ) {
				return array(
					'ok'    => false,
					'error' => __( 'Routine task not found.', 'agent-builder' ),
				);
			}

			Agent_Lifecycle::execute_scheduled_task( $agent, Agent_Lifecycle::user_task_to_definition( $user_task ), $controller );
		} else {
			// Event listener: no direct "run this trigger's prompt now" entry point
			// exists, so drive handle_async_event() with empty synthetic hook args.
			Agent_Lifecycle::handle_async_event(
				$agent_slug,
				(string) ( $config['trigger_id'] ?? '' ),
				(string) ( $config['prompt'] ?? '' ),
				array(),
				$controller
			);
		}

		$row = Deployments::get( $id );

		return array(
			'ok'     => true,
			'run_id' => (string) ( $row['config']['last_run_id'] ?? '' ),
		);
	}
}
