<?php
/**
 * Jobs REST API
 *
 * REST API endpoints for job management.
 *
 * @package    Agent_Builder
 * @subpackage Includes
 * @author     Agent Builder Team <support@agentic-plugin.com>
 * @license    GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 * @link       https://agentic-plugin.com
 * @since      0.2.0
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Jobs API class
 */
class Jobs_API {

	/**
	 * Initialize
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register REST routes
	 */
	public static function register_routes(): void {
		register_rest_route(
			'agentic/v1',
			'/jobs',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'create_job' ),
				'permission_callback' => array( __CLASS__, 'check_permission' ),
			)
		);

		register_rest_route(
			'agentic/v1',
			'/jobs/(?P<id>[a-f0-9\-]+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get_job' ),
					'permission_callback' => array( __CLASS__, 'check_permission' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( __CLASS__, 'cancel_job' ),
					'permission_callback' => array( __CLASS__, 'check_permission' ),
				),
			)
		);

		register_rest_route(
			'agentic/v1',
			'/jobs/user/(?P<user_id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_user_jobs' ),
				'permission_callback' => array( __CLASS__, 'check_permission' ),
			)
		);
	}

	/**
	 * Check permission.
	 *
	 * Nothing in this plugin's own UI currently calls this REST surface — the
	 * only real Job_Processor_Interface implementation (Agent_Builder_Job_Processor)
	 * backs the HIGH-risk create_agent_files tool — so this is gated the same
	 * as other admin-only, credential/risk-adjacent routes rather than the
	 * bare is_user_logged_in() it previously used.
	 *
	 * @return bool
	 */
	public static function check_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Create a new job.
	 *
	 * user_id is always the current user, never caller-supplied — a request
	 * parameter of the same name must not let one user attribute a job to
	 * another. Job_Manager::create_job() also validates the processor class
	 * against Job_Processor_Interface before ever instantiating it.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function create_job( \WP_REST_Request $request ) {
		$args            = $request->get_params();
		$args['user_id'] = get_current_user_id();
		$job_id          = Job_Manager::create_job( $args );

		return new \WP_REST_Response(
			array(
				'job_id' => $job_id,
				'status' => Job_Manager::STATUS_PENDING,
			),
			202
		);
	}

	/**
	 * Get job status
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get_job( \WP_REST_Request $request ) {
		$job_id = $request->get_param( 'id' );
		$job    = Job_Manager::get_job( $job_id );

		if ( ! $job ) {
			return new \WP_Error(
				'job_not_found',
				'Job not found',
				array( 'status' => 404 )
			);
		}

		// Check ownership.
		if ( get_current_user_id() !== $job->user_id && ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'forbidden',
				'You do not have permission to access this job',
				array( 'status' => 403 )
			);
		}

		return new \WP_REST_Response(
			array(
				'id'            => $job->id,
				'status'        => $job->status,
				'progress'      => (int) $job->progress,
				'message'       => $job->message,
				'response_data' => $job->response_data,
				'error_message' => $job->error_message,
				'created_at'    => $job->created_at,
				'updated_at'    => $job->updated_at,
			),
			200
		);
	}

	/**
	 * Cancel a job
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function cancel_job( \WP_REST_Request $request ) {
		$job_id = $request->get_param( 'id' );
		$job    = Job_Manager::get_job( $job_id );

		if ( ! $job ) {
			return new \WP_Error(
				'job_not_found',
				'Job not found',
				array( 'status' => 404 )
			);
		}

		// Check ownership.
		if ( get_current_user_id() !== $job->user_id && ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'forbidden',
				'You do not have permission to cancel this job',
				array( 'status' => 403 )
			);
		}

		$cancelled = Job_Manager::cancel_job( $job_id );

		if ( ! $cancelled ) {
			return new \WP_Error(
				'cannot_cancel',
				'Job cannot be cancelled (may already be processing or completed)',
				array( 'status' => 400 )
			);
		}

		return new \WP_REST_Response(
			array(
				'success' => true,
				'message' => 'Job cancelled',
			),
			200
		);
	}

	/**
	 * Get user's jobs
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get_user_jobs( \WP_REST_Request $request ) {
		$user_id = (int) $request->get_param( 'user_id' );

		// Check permission - users can only see their own jobs unless admin.
		if ( get_current_user_id() !== $user_id && ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'forbidden',
				'You do not have permission to view these jobs',
				array( 'status' => 403 )
			);
		}

		$status = $request->get_param( 'status' ) ?? '';
		$limit  = (int) ( $request->get_param( 'limit' ) ?? 50 );

		$jobs = Job_Manager::get_user_jobs( $user_id, $status, $limit );

		// Sanitize output - remove sensitive request data.
		$jobs = array_map(
			function ( $job ) {
				return array(
					'id'         => $job->id,
					'agent_id'   => $job->agent_id,
					'status'     => $job->status,
					'progress'   => (int) $job->progress,
					'message'    => $job->message,
					'created_at' => $job->created_at,
					'updated_at' => $job->updated_at,
				);
			},
			$jobs
		);

		return new \WP_REST_Response(
			array(
				'jobs'  => $jobs,
				'total' => count( $jobs ),
			),
			200
		);
	}
}
