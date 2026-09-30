<?php
/**
 * Skill Recorder REST — start, stop and inspect a live skill recording.
 *
 * Thin REST wiring over Skill_Recorder: every route delegates to the recorder
 * and adds nothing beyond request/response translation. All routes sit behind
 * the same tool-management capability the Tools screen itself requires.
 *
 * @package    Agent_Builder
 * @subpackage REST
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
 * Skill_Recorder_REST
 *
 * @since 4.4.0
 */
class Skill_Recorder_REST {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	const NS = 'agentic/v1';

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
			'/skills/recording/start',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'start_recording' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'args'                => array(
					'session_id' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/skills/recording/stop',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'stop_recording' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NS,
			'/skills/recording',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_status' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			)
		);
	}

	/**
	 * Manage capability — every route sits behind the tool-management capability.
	 *
	 * @return bool
	 */
	public static function can_manage(): bool {
		return current_user_can( 'agent_builder_manage_tools' );
	}

	/**
	 * POST /skills/recording/start — begin recording the current user's session.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function start_recording( \WP_REST_Request $request ) {
		$session_id = sanitize_text_field( (string) $request->get_param( 'session_id' ) );

		$result = Skill_Recorder::start( get_current_user_id(), $session_id );

		if ( empty( $result['ok'] ) ) {
			return new \WP_Error(
				'rest_skill_recording_start_failed',
				(string) ( $result['error'] ?? __( 'Could not start recording.', 'agent-builder' ) ),
				array( 'status' => 400 )
			);
		}

		return new \WP_REST_Response( $result, 201 );
	}

	/**
	 * POST /skills/recording/stop — stop recording and return the captured steps.
	 *
	 * @param \WP_REST_Request $_request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function stop_recording( \WP_REST_Request $_request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- REST callback signature.
		$result = Skill_Recorder::stop( get_current_user_id() );

		if ( empty( $result['ok'] ) ) {
			return new \WP_Error(
				'rest_skill_recording_stop_failed',
				(string) ( $result['error'] ?? __( 'No recording to stop.', 'agent-builder' ) ),
				array( 'status' => 400 )
			);
		}

		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * GET /skills/recording — report the current recording state.
	 *
	 * @param \WP_REST_Request $_request Request.
	 * @return \WP_REST_Response
	 */
	public static function get_status( \WP_REST_Request $_request ): \WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- REST callback signature.
		return new \WP_REST_Response( Skill_Recorder::status( get_current_user_id() ), 200 );
	}
}
