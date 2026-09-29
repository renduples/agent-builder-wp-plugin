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
	 * NOTE: no M14 task creates runs with the `routine:<id>` source_ref yet —
	 * that wiring lands in the next M14 task (execution wiring). Until then
	 * this returns an empty array; it is correct now, just empty.
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
}
