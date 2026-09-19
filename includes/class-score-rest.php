<?php
/**
 * Score REST API
 *
 * A minimal, standalone read/write pair for the Agent-Ready Score, separate
 * from the shared agentic/v1/admin-page controller. Two consumers need this
 * data independently of the wp-admin page shell: the check_agent_readiness
 * MCP tool (in-process, does not use this route at all) calls
 * Agent_Ready_Score directly, but this route exists for any external caller
 * — a JS widget, a future Site Passport verification hit — that wants the
 * score without going through the admin-page payload shape.
 *
 * @package    Agent_Builder
 * @subpackage Includes
 * @since      3.3.90
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and handles the agentic/v1/score routes.
 */
class Score_REST {

	/**
	 * Hook route registration.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register agentic/v1/score (GET) and agentic/v1/score/rescan (POST).
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			'agentic/v1',
			'/score',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_score' ),
				'permission_callback' => array( __CLASS__, 'can_view' ),
			)
		);

		register_rest_route(
			'agentic/v1',
			'/score/rescan',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rescan' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			)
		);
	}

	/**
	 * Any admin-area user with dashboard access may read the score.
	 *
	 * @return bool
	 */
	public static function can_view(): bool {
		return current_user_can( 'agent_builder_view_dashboard' ) || current_user_can( 'manage_options' );
	}

	/**
	 * Only users who can manage settings may force a re-scan.
	 *
	 * @return bool
	 */
	public static function can_manage(): bool {
		return current_user_can( 'agent_builder_manage_settings' ) || current_user_can( 'manage_options' );
	}

	/**
	 * GET agentic/v1/score.
	 *
	 * @return \WP_REST_Response
	 */
	public static function get_score(): \WP_REST_Response {
		return new \WP_REST_Response( Agent_Ready_Score::get_latest(), 200 );
	}

	/**
	 * POST agentic/v1/score/rescan.
	 *
	 * @return \WP_REST_Response
	 */
	public static function rescan(): \WP_REST_Response {
		return new \WP_REST_Response( Agent_Ready_Score::rescan(), 200 );
	}
}
