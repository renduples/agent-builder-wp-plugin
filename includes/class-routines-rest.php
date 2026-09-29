<?php
/**
 * Routines REST — list, create, edit, delete, pause, resume, test-run and
 * history for user-defined routines.
 *
 * Thin REST wiring over the feature-complete Routines data-access layer. Every
 * route delegates to an existing Routines / Agent_Lifecycle method and adds
 * nothing beyond request/response translation: the underlying save() already
 * validates and sanitizes, and the underlying delete paths already unschedule
 * the cron and delete the Deployments mirror row.
 *
 * @package    Agent_Builder
 * @subpackage REST
 * @since      4.3.0
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Routines REST controller.
 */
class Routines_REST {

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
			'/routines',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_routines' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
					'args'                => array(
						'agent_slug' => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_key',
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'create_routine' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/routines/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( __CLASS__, 'update_routine' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'delete_routine' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/routines/(?P<id>\d+)/pause',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'pause_routine' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NS,
			'/routines/(?P<id>\d+)/resume',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'resume_routine' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NS,
			'/routines/(?P<id>\d+)/test-run',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'test_run_routine' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NS,
			'/routines/(?P<id>\d+)/history',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_history' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'args'                => array(
					'limit' => array(
						'type'              => 'integer',
						'default'           => 20,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * Manage capability — every route, reads and writes, sits behind the same
	 * agent-management capability the Routines screen itself requires.
	 *
	 * @return bool
	 */
	public static function can_manage(): bool {
		return current_user_can( 'agent_builder_manage_agents' );
	}

	/**
	 * GET /routines — list routines, optionally scoped to one agent.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function get_routines( \WP_REST_Request $request ): \WP_REST_Response {
		$agent_slug = sanitize_key( (string) $request->get_param( 'agent_slug' ) );

		return new \WP_REST_Response( array( 'routines' => Routines::list( $agent_slug ) ), 200 );
	}

	/**
	 * POST /routines — create a routine from the request body.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function create_routine( \WP_REST_Request $request ) {
		$result = Routines::save( self::body_params( $request ) );

		return self::save_response( $result, 201 );
	}

	/**
	 * PUT /routines/{id} — edit an existing routine.
	 *
	 * The route's {id} is the Deployments row id; resolve it back to the
	 * option-backed task/trigger id before handing it to Routines::save(), whose
	 * underlying Agent_Lifecycle save expects that id form.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function update_routine( \WP_REST_Request $request ) {
		$row = self::get_routine_row( (int) $request->get_param( 'id' ) );
		if ( null === $row ) {
			return self::not_found();
		}

		$data       = self::body_params( $request );
		$data['id'] = self::option_id( $row );
		$result     = Routines::save( $data );

		return self::save_response( $result, 200 );
	}

	/**
	 * DELETE /routines/{id} — delete a routine.
	 *
	 * Delegates to the existing Agent_Lifecycle delete path for the row's type,
	 * which already unschedules the cron and deletes the Deployments mirror row.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function delete_routine( \WP_REST_Request $request ) {
		$id  = (int) $request->get_param( 'id' );
		$row = self::get_routine_row( $id );
		if ( null === $row ) {
			return self::not_found();
		}

		if ( Deployments::TYPE_SCHEDULED_TASK === (string) $row['type'] ) {
			Agent_Lifecycle::delete_user_scheduled_task( self::option_id( $row ) );
		} else {
			Agent_Lifecycle::delete_user_trigger( self::option_id( $row ) );
		}

		return new \WP_REST_Response(
			array(
				'ok' => true,
				'id' => $id,
			),
			200
		);
	}

	/**
	 * POST /routines/{id}/pause — pause a routine.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function pause_routine( \WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );
		if ( null === self::get_routine_row( $id ) ) {
			return self::not_found();
		}

		return self::action_response( Routines::pause( $id ) );
	}

	/**
	 * POST /routines/{id}/resume — resume a paused routine.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function resume_routine( \WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );
		if ( null === self::get_routine_row( $id ) ) {
			return self::not_found();
		}

		return self::action_response( Routines::resume( $id ) );
	}

	/**
	 * POST /routines/{id}/test-run — run one execution right now.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function test_run_routine( \WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );
		if ( null === self::get_routine_row( $id ) ) {
			return self::not_found();
		}

		return self::action_response( Routines::test_run( $id, get_current_user_id() ) );
	}

	/**
	 * GET /routines/{id}/history — run history for a routine.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get_history( \WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );
		if ( null === self::get_routine_row( $id ) ) {
			return self::not_found();
		}

		$limit = (int) $request->get_param( 'limit' );
		$limit = max( 1, min( 20, $limit ) );

		return new \WP_REST_Response( array( 'history' => Routines::history( $id, $limit ) ), 200 );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Translate a Routines action result into a response: the result body on
	 * success, or a 400 rest_routine_action_failed error when ok is false.
	 *
	 * @param array{ok:bool,error?:string} $result Routines::pause/resume/test_run result.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private static function action_response( array $result ) {
		if ( empty( $result['ok'] ) ) {
			return new \WP_Error(
				'rest_routine_action_failed',
				(string) ( $result['error'] ?? __( 'Routine action failed.', 'agent-builder' ) ),
				array( 'status' => 400 )
			);
		}

		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * Resolve a Deployments row id to its routine row, or null when the row
	 * doesn't exist or isn't a routine.
	 *
	 * @param int $id Deployments row id.
	 * @return array|null
	 */
	private static function get_routine_row( int $id ): ?array {
		$row = Deployments::get( $id );
		if ( null === $row || ! Routines::is_routine( $row ) ) {
			return null;
		}
		return $row;
	}

	/**
	 * The option-backed task/trigger id a routine row mirrors.
	 *
	 * @param array $row Decoded Deployments routine row.
	 * @return string
	 */
	private static function option_id( array $row ): string {
		$config = $row['config'] ?? array();
		if ( Deployments::TYPE_SCHEDULED_TASK === (string) $row['type'] ) {
			return (string) ( $config['task_id'] ?? '' );
		}
		return (string) ( $config['trigger_id'] ?? '' );
	}

	/**
	 * Read the request body as an associative array (JSON, falling back to
	 * form-encoded body params).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	private static function body_params( \WP_REST_Request $request ): array {
		$data = $request->get_json_params();
		if ( ! is_array( $data ) || array() === $data ) {
			$data = $request->get_body_params();
		}
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Translate a Routines::save() result into a create/edit response: a 400
	 * rest_invalid error on failure, or the freshly-saved, decorated row on
	 * success.
	 *
	 * @param array{ok:bool,id?:int,error?:string} $result Save result.
	 * @param int                                  $success_status HTTP status on success (201 create, 200 edit).
	 * @return \WP_REST_Response|\WP_Error
	 */
	private static function save_response( array $result, int $success_status ) {
		if ( empty( $result['ok'] ) ) {
			return new \WP_Error(
				'rest_invalid',
				(string) ( $result['error'] ?? __( 'Invalid routine.', 'agent-builder' ) ),
				array( 'status' => 400 )
			);
		}

		$row = Deployments::get( (int) $result['id'] );

		return new \WP_REST_Response(
			array( 'routine' => null !== $row ? Routines::decorate( $row ) : null ),
			$success_status
		);
	}

	/**
	 * A 404 for a missing or non-routine id.
	 *
	 * @return \WP_Error
	 */
	private static function not_found(): \WP_Error {
		return new \WP_Error(
			'rest_routine_not_found',
			__( 'Routine not found.', 'agent-builder' ),
			array( 'status' => 404 )
		);
	}
}
