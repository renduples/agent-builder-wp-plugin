<?php
/**
 * Site Brief REST controller.
 *
 * Namespace stays agentic/v1. Every route requires a logged-in user plus
 * the documented capability. Cookie auth already verifies the REST nonce.
 *
 * @package    Agent_Builder
 * @subpackage Site_Brief
 * @since      3.4.2
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic\Site_Brief;

use Agentic\Admin_Menu_Handler;
use Agentic\Approval_Queue;
use Agentic\Audit_Log;
use Agentic\Provider_Registry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST routes for Site Brief.
 */
class Site_Brief_Controller {

	/**
	 * REST namespace.
	 */
	public const NS = 'agentic/v1';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/**
	 * Register /site-brief routes.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::NS,
			'/site-brief',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'get_brief' ),
				'permission_callback' => array( self::class, 'can_view' ),
			)
		);

		register_rest_route(
			self::NS,
			'/site-brief/run',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'run_brief' ),
				'permission_callback' => array( self::class, 'can_run' ),
			)
		);

		register_rest_route(
			self::NS,
			'/site-brief/cards/(?P<id>[a-zA-Z0-9_.:-]+)/dismiss',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'dismiss_card' ),
				'permission_callback' => array( self::class, 'can_run' ),
				'args'                => array(
					'id' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/site-brief/cards/(?P<id>[a-zA-Z0-9_.:-]+)/approve',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'approve_card' ),
				'permission_callback' => array( self::class, 'can_approve' ),
				'args'                => array(
					'id' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * View capability (see the panel and last scan).
	 *
	 * @return bool|\WP_Error
	 */
	public static function can_view() {
		return self::check_cap( 'agent_builder_view_dashboard' );
	}

	/**
	 * Run / dismiss capability.
	 *
	 * @return bool|\WP_Error
	 */
	public static function can_run() {
		return self::check_cap( 'agent_builder_manage_agents' );
	}

	/**
	 * Approve capability — same as the Approvals screen.
	 *
	 * @return bool|\WP_Error
	 */
	public static function can_approve() {
		return self::check_cap( 'agent_builder_manage_agents' );
	}

	/**
	 * Logged-in + current_user_can. Unauthenticated → 401, lacking cap → 403.
	 *
	 * @param string $cap Plugin capability.
	 * @return bool|\WP_Error
	 */
	private static function check_cap( string $cap ) {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error(
				'rest_not_logged_in',
				__( 'You must be logged in to use Site Brief.', 'agent-builder' ),
				array( 'status' => 401 )
			);
		}
		if ( ! current_user_can( $cap ) && ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to do this.', 'agent-builder' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * GET /site-brief — stored result only. Does not invoke tools.
	 *
	 * @return \WP_REST_Response
	 */
	public static function get_brief(): \WP_REST_Response {
		return new \WP_REST_Response( self::present( Site_Brief_Store::get() ), 200 );
	}

	/**
	 * POST /site-brief/run — observe-mode scan with a 15s budget and run lock.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function run_brief() {
		if ( ! Provider_Registry::has_usable_provider() ) {
			return new \WP_Error(
				'site_brief_no_provider',
				__( 'Connect an AI provider before scanning. Site Brief does not run without one.', 'agent-builder' ),
				array( 'status' => 400 )
			);
		}

		$lock = get_transient( Site_Brief_Runner::RUN_LOCK );
		if ( false !== $lock && '' !== $lock && 0 !== $lock ) {
			$payload           = self::present( Site_Brief_Store::get() );
			$payload['status'] = 'running';
			return new \WP_REST_Response( $payload, 200 );
		}

		set_transient( Site_Brief_Runner::RUN_LOCK, get_current_user_id(), 60 );

		try {
			$runner = new Site_Brief_Runner();
			$result = $runner->run();
			Site_Brief_Store::save( $result );

			$audit = new Audit_Log();
			$audit->log(
				'site-brief',
				'site_brief_run',
				'scan',
				array(
					'status'     => $result['status'] ?? '',
					'card_count' => is_array( $result['cards'] ?? null ) ? count( $result['cards'] ) : 0,
					'skipped'    => $result['skipped'] ?? array(),
				)
			);
		} finally {
			delete_transient( Site_Brief_Runner::RUN_LOCK );
		}

		return new \WP_REST_Response( self::present( $result ), 200 );
	}

	/**
	 * POST /site-brief/cards/{id}/dismiss
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function dismiss_card( \WP_REST_Request $request ) {
		$card = self::find_card( (string) $request->get_param( 'id' ) );
		if ( null === $card ) {
			return new \WP_Error(
				'site_brief_unknown_card',
				__( 'That Site Brief card was not found.', 'agent-builder' ),
				array( 'status' => 404 )
			);
		}

		$hash = (string) ( $card['evidence_hash'] ?? '' );
		Site_Brief_Store::dismiss( (string) $card['id'], $hash );

		$audit = new Audit_Log();
		$audit->log(
			'site-brief',
			'site_brief_dismiss',
			(string) ( $card['checker_id'] ?? '' ),
			array(
				'card_id'    => $card['id'],
				'tool_names' => $card['tool_slugs'] ?? array(),
			)
		);

		return new \WP_REST_Response( self::present( Site_Brief_Store::get() ), 200 );
	}

	/**
	 * POST /site-brief/cards/{id}/approve
	 *
	 * Queues a write via Approval_Queue or returns an admin URL. Never runs
	 * the write in this request (and never in the scan request).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function approve_card( \WP_REST_Request $request ) {
		$card = self::find_card( (string) $request->get_param( 'id' ) );
		if ( null === $card ) {
			return new \WP_Error(
				'site_brief_unknown_card',
				__( 'That Site Brief card was not found.', 'agent-builder' ),
				array( 'status' => 404 )
			);
		}

		$approve = is_array( $card['approve'] ?? null ) ? $card['approve'] : array();
		$type    = (string) ( $approve['type'] ?? 'none' );

		$audit = new Audit_Log();
		$audit->log(
			'site-brief',
			'site_brief_approve',
			(string) ( $card['checker_id'] ?? '' ),
			array(
				'card_id'    => $card['id'],
				'type'       => $type,
				'tool_names' => $card['tool_slugs'] ?? array(),
			)
		);

		if ( 'queue' === $type && ! empty( $approve['tool'] ) ) {
			$params               = is_array( $approve['arguments'] ?? null ) ? $approve['arguments'] : array();
			$params['source']     = 'site_brief';
			$params['checker_id'] = (string) ( $card['checker_id'] ?? '' );

			$queue = new Approval_Queue();
			$qid   = $queue->add(
				(string) ( $approve['agent'] ?? $card['agent'] ?? 'wordpress-assistant' ),
				(string) $approve['tool'],
				$params,
				(string) ( $card['proposed_action'] ?? '' ),
				7,
				(string) ( $approve['risk'] ?? $card['action_risk'] ?? 'medium' ),
				'supervised',
				'site_brief'
			);

			if ( ! $qid ) {
				return new \WP_Error(
					'site_brief_queue_failed',
					__( 'Could not add this job to the approval queue.', 'agent-builder' ),
					array( 'status' => 500 )
				);
			}

			$payload                  = self::present( Site_Brief_Store::get() );
			$payload['approval_id']   = (int) $qid;
			$payload['approvals_url'] = admin_url( 'admin.php?page=agentic-approvals' );
			return new \WP_REST_Response( $payload, 200 );
		}

		if ( 'admin_url' === $type && ! empty( $approve['url'] ) ) {
			$redirect                = wp_validate_redirect( (string) $approve['url'], admin_url() );
			$payload                 = self::present( Site_Brief_Store::get() );
			$payload['redirect_url'] = $redirect;
			return new \WP_REST_Response( $payload, 200 );
		}

		return new \WP_Error(
			'site_brief_no_write',
			__( 'This card has no write action. Dismiss it if you are done.', 'agent-builder' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Locate a stored card by id.
	 *
	 * @param string $id Card id.
	 * @return array<string, mixed>|null
	 */
	private static function find_card( string $id ): ?array {
		$data = Site_Brief_Store::get();
		foreach ( (array) ( $data['cards'] ?? array() ) as $card ) {
			if ( is_array( $card ) && isset( $card['id'] ) && (string) $card['id'] === $id ) {
				return $card;
			}
		}
		return null;
	}

	/**
	 * Shape a store payload for the dashboard panel.
	 *
	 * @param array<string, mixed> $data Store payload.
	 * @return array<string, mixed>
	 */
	private static function present( array $data ): array {
		$advanced = class_exists( Admin_Menu_Handler::class )
			&& Admin_Menu_Handler::is_advanced_mode( 'dashboard' );

		$cards = array();
		foreach ( (array) ( $data['cards'] ?? array() ) as $card ) {
			if ( ! is_array( $card ) ) {
				continue;
			}
			if ( ! $advanced ) {
				unset( $card['tool_slugs'], $card['raw'] );
			}
			$cards[] = $card;
		}

		$can_run = current_user_can( 'agent_builder_manage_agents' )
			|| current_user_can( 'manage_options' );

		return array(
			'version'             => (int) ( $data['version'] ?? 1 ),
			'last_run'            => (string) ( $data['last_run'] ?? '' ),
			'last_run_user'       => (int) ( $data['last_run_user'] ?? 0 ),
			'status'              => (string) ( $data['status'] ?? 'idle' ),
			'cards'               => $cards,
			'store_stats'         => $data['store_stats'] ?? null,
			'skipped'             => array_values( (array) ( $data['skipped'] ?? array() ) ),
			'has_usable_provider' => Provider_Registry::has_usable_provider(),
			'is_advanced'         => $advanced,
			'healthy'             => empty( $cards ) && '' !== (string) ( $data['last_run'] ?? '' ),
			'caps'                => array(
				'run'     => $can_run,
				'approve' => $can_run,
			),
			'urls'                => array(
				'approvals' => admin_url( 'admin.php?page=agentic-approvals' ),
			),
			'checkers'            => array(
				'plugin_updates',
				'site_health',
				'comments_queue',
				'oversized_media',
				'forms',
				'wc_unpaid',
			),
		);
	}
}
