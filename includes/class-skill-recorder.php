<?php
/**
 * Skill Recorder — capture a live, human-demonstrated task as a sequence of
 * tool-call steps so Skill_Drafter can turn it into a teachable skill.
 *
 * A recording is a per-user transient (`agentic_skill_recording_{$user_id}`)
 * holding the session being recorded and the steps captured so far. Every
 * resolved tool execution fires `agent_builder_tool_executed`; this class
 * listens for it and appends a step only when the call belongs to the
 * recording user's session. Other users' or other sessions' calls are silently
 * ignored, so a recording never leaks neighbouring activity.
 *
 * M15 is schema-free: nothing here writes `agent_builder_skills` rows, and the
 * raw captured steps are handed off (via `stop()`) for Skill_Drafter to shape
 * into a draft — this class is the recorder only.
 *
 * @package    Agent_Builder
 * @subpackage Includes
 * @since      4.4.0
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Skill_Recorder
 *
 * @since 4.4.0
 */
class Skill_Recorder {

	/**
	 * How long a recording survives before expiring untouched.
	 *
	 * @var int
	 */
	private const RECORDING_TTL = 2 * HOUR_IN_SECONDS;

	/**
	 * Boot the recorder: listen for resolved tool executions.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'agent_builder_tool_executed', array( __CLASS__, 'on_tool_executed' ), 10, 4 );
	}

	/**
	 * Start recording a session for a user.
	 *
	 * Refuses to start when a recording is already active for that user, or
	 * when the session id is empty.
	 *
	 * @param int    $user_id    User to record on behalf of.
	 * @param string $session_id Browser-tab session id being recorded.
	 * @return array{ok:bool,session_id?:string,started_at?:int,error?:string}
	 */
	public static function start( int $user_id, string $session_id ): array {
		if ( '' === $session_id ) {
			return array(
				'ok'    => false,
				'error' => __( 'A session id is required to start recording.', 'agent-builder' ),
			);
		}

		if ( false !== get_transient( self::transient_key( $user_id ) ) ) {
			return array(
				'ok'    => false,
				'error' => __( 'A skill recording is already active for this user.', 'agent-builder' ),
			);
		}

		$recording = array(
			'session_id' => $session_id,
			'started_at' => time(),
			'steps'      => array(),
		);

		set_transient( self::transient_key( $user_id ), $recording, self::RECORDING_TTL );

		return array(
			'ok'         => true,
			'session_id' => $session_id,
			'started_at' => $recording['started_at'],
		);
	}

	/**
	 * Stop recording and return the captured steps.
	 *
	 * Consumes the recording (the transient is deleted), so a second stop
	 * reports nothing was recording.
	 *
	 * @param int $user_id User whose recording to stop.
	 * @return array{ok:bool,steps?:array<int,array<string,mixed>>,error?:string}
	 */
	public static function stop( int $user_id ): array {
		$key       = self::transient_key( $user_id );
		$recording = get_transient( $key );

		if ( false === $recording || ! is_array( $recording ) ) {
			return array(
				'ok'    => false,
				'error' => __( 'No skill recording is active.', 'agent-builder' ),
			);
		}

		delete_transient( $key );

		return array(
			'ok'    => true,
			'steps' => isset( $recording['steps'] ) && is_array( $recording['steps'] ) ? $recording['steps'] : array(),
		);
	}

	/**
	 * Return the captured steps without consuming the recording.
	 *
	 * The recording stays active, so the caller can inspect the steps and only
	 * call stop() to delete them once they are safely handed off — e.g. after a
	 * draft row has been created.
	 *
	 * @param int $user_id User whose recording to peek at.
	 * @return array{ok:bool,steps?:array<int,array<string,mixed>>,error?:string}
	 */
	public static function peek( int $user_id ): array {
		$recording = get_transient( self::transient_key( $user_id ) );

		if ( false === $recording || ! is_array( $recording ) ) {
			return array(
				'ok'    => false,
				'error' => __( 'No skill recording is active.', 'agent-builder' ),
			);
		}

		return array(
			'ok'    => true,
			'steps' => isset( $recording['steps'] ) && is_array( $recording['steps'] ) ? $recording['steps'] : array(),
		);
	}

	/**
	 * Report the current recording state without consuming it.
	 *
	 * @param int $user_id User to query.
	 * @return array{ok:bool,recording:bool,session_id?:string,started_at?:int,step_count?:int}
	 */
	public static function status( int $user_id ): array {
		$recording = get_transient( self::transient_key( $user_id ) );

		if ( false === $recording || ! is_array( $recording ) ) {
			return array(
				'ok'        => true,
				'recording' => false,
			);
		}

		$steps = isset( $recording['steps'] ) && is_array( $recording['steps'] ) ? $recording['steps'] : array();

		return array(
			'ok'         => true,
			'recording'  => true,
			'session_id' => (string) ( $recording['session_id'] ?? '' ),
			'started_at' => (int) ( $recording['started_at'] ?? 0 ),
			'step_count' => count( $steps ),
		);
	}

	/**
	 * Append a step to the active recording, when the executed call belongs to
	 * the recording user's session.
	 *
	 * Wired to `agent_builder_tool_executed`. Any call whose user or session
	 * does not match the active recording is silently ignored — never appended,
	 * never errored.
	 *
	 * @param string $tool_name Executed tool name.
	 * @param array  $arguments Arguments the tool ran with.
	 * @param mixed  $result    Tool result.
	 * @param array  $ctx       Gate context — see Tool_Executor::execute().
	 * @return void
	 */
	public static function on_tool_executed( string $tool_name, array $arguments, $result, array $ctx ): void {
		$user_id = isset( $ctx['user_id'] ) ? (int) $ctx['user_id'] : 0;
		if ( $user_id <= 0 ) {
			return;
		}

		$recording = get_transient( self::transient_key( $user_id ) );
		if ( false === $recording || ! is_array( $recording ) ) {
			return;
		}

		$session_id           = (string) ( $ctx['session_id'] ?? '' );
		$recording_session_id = (string) ( $recording['session_id'] ?? '' );
		if ( $session_id !== $recording_session_id ) {
			return;
		}

		$steps   = isset( $recording['steps'] ) && is_array( $recording['steps'] ) ? $recording['steps'] : array();
		$steps[] = array(
			'tool'         => $tool_name,
			'action'       => (string) ( $ctx['action'] ?? '' ),
			'args_summary' => Tool_Executor::summarize_arguments( $arguments ),
			'success'      => self::result_success( $result ),
		);

		$recording['steps'] = $steps;
		set_transient( self::transient_key( $user_id ), $recording, self::RECORDING_TTL );
	}

	/**
	 * Judge a tool result the same way the audit trail does: an explicit
	 * `success` flag wins, otherwise a non-empty `error` key is failure.
	 *
	 * @param mixed $result Tool result.
	 * @return bool
	 */
	private static function result_success( $result ): bool {
		if ( ! is_array( $result ) ) {
			return true;
		}

		if ( isset( $result['success'] ) ) {
			return (bool) $result['success'];
		}

		if ( isset( $result['error'] ) && $result['error'] ) {
			return false;
		}

		return true;
	}

	/**
	 * The transient key holding a user's active recording.
	 *
	 * @param int $user_id User id.
	 * @return string
	 */
	private static function transient_key( int $user_id ): string {
		return 'agentic_skill_recording_' . $user_id;
	}
}
