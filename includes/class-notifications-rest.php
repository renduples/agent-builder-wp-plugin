<?php
/**
 * Notifications REST — list and mark-read.
 *
 * Backs the "notification centre" half of M11 §7: GET /notifications returns the
 * current user's inbox (optionally unread-only), and POST /notifications/read
 * marks specific rows — or everything — read, so the admin-bar/Tasks badge can
 * finally shrink instead of only ever growing.
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
 * Notifications REST controller.
 */
class Notifications_REST {

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
			'/notifications',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_notifications' ),
				'permission_callback' => array( __CLASS__, 'can_view' ),
				'args'                => array(
					'unread'   => array(
						'type'              => 'boolean',
						'default'           => false,
						'sanitize_callback' => 'rest_sanitize_boolean',
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
			'/notifications/read',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'mark_read' ),
				'permission_callback' => array( __CLASS__, 'can_view' ),
				'args'                => array(
					'ids' => array(
						'type'              => 'array',
						'items'             => array( 'type' => 'integer' ),
						'sanitize_callback' => array( __CLASS__, 'sanitize_ids' ),
					),
					'all' => array(
						'type'              => 'boolean',
						'default'           => false,
						'sanitize_callback' => 'rest_sanitize_boolean',
					),
				),
			)
		);
	}

	/**
	 * View capability — reading and marking the current user's notifications.
	 *
	 * Matches the admin-bar inbox node and PLAN §7's view_dashboard gate. The
	 * handlers themselves always scope to the current user, so this capability
	 * never reaches another user's rows.
	 *
	 * @return bool
	 */
	public static function can_view(): bool {
		return current_user_can( 'manage_options' ) || current_user_can( 'agent_builder_view_dashboard' );
	}

	/**
	 * GET /notifications — the current user's inbox, newest first.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function get_notifications( \WP_REST_Request $request ): \WP_REST_Response {
		$unread   = (bool) $request->get_param( 'unread' );
		$per_page = max( 1, min( 200, (int) $request->get_param( 'per_page' ) ) );
		$page     = max( 1, (int) $request->get_param( 'page' ) );

		$rows = Notifications::list( get_current_user_id(), $unread, $per_page, $page );

		return new \WP_REST_Response( array( 'notifications' => $rows ), 200 );
	}

	/**
	 * POST /notifications/read — mark specific rows, or everything, read.
	 *
	 * `all` (or an omitted/empty `ids`) marks every unread row read, mirroring
	 * Notifications::mark_read()'s empty-list "all unread" behaviour.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function mark_read( \WP_REST_Request $request ): \WP_REST_Response {
		$user_id = get_current_user_id();
		$all     = (bool) $request->get_param( 'all' );

		if ( $all ) {
			Notifications::mark_read( $user_id );
		} else {
			Notifications::mark_read( $user_id, self::sanitize_ids( $request->get_param( 'ids' ) ) );
		}

		return new \WP_REST_Response( array( 'unread' => Notifications::unread_count( $user_id ) ), 200 );
	}

	/**
	 * Sanitize a list of notification row ids to a clean list of positive ints.
	 *
	 * @param mixed $value Raw ids value (array or scalar).
	 * @return int[]
	 */
	public static function sanitize_ids( $value ): array {
		$ids = array_map( 'absint', (array) $value );

		return array_values( array_filter( $ids ) );
	}
}
