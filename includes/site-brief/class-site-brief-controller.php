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
			'/site-brief/cards/(?P<id>[a-zA-Z0-9_.:-]+)/assign',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'assign_card' ),
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
			'/site-brief/opening/(?P<token>[a-zA-Z0-9]+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'get_opening' ),
				'permission_callback' => array( self::class, 'can_view' ),
				'args'                => array(
					'token' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
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
	 * POST /site-brief/cards/{id}/assign
	 *
	 * Records the assignment, stores a one-time opening message, and returns
	 * a chat deep-link with the recommended agent preselected. The finding
	 * itself is never put on the query string.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function assign_card( \WP_REST_Request $request ) {
		$card = self::find_card( (string) $request->get_param( 'id' ) );
		if ( null === $card ) {
			return new \WP_Error(
				'site_brief_unknown_card',
				__( 'That Site Brief card was not found.', 'agent-builder' ),
				array( 'status' => 404 )
			);
		}

		$agent       = sanitize_key( (string) ( $card['agent'] ?? '' ) );
		$agent_label = sanitize_text_field( (string) ( $card['agent_label'] ?? '' ) );
		if ( '' === $agent ) {
			$agent       = 'wordpress-assistant';
			$agent_label = __( 'WordPress Assistant', 'agent-builder' );
		}
		if ( '' === $agent_label ) {
			$agent_label = $agent;
		}

		self::maybe_activate_agent( $agent );
		Site_Brief_Store::assign( (string) $card['id'], $agent, $agent_label );

		$token = self::store_opening( self::compose_opening_message( $card ), $agent, (string) $card['id'] );

		$audit = new Audit_Log();
		$audit->log(
			'site-brief',
			'site_brief_assign',
			(string) ( $card['checker_id'] ?? '' ),
			array(
				'card_id'     => $card['id'],
				'agent'       => $agent,
				'agent_label' => $agent_label,
				'tool_names'  => $card['tool_slugs'] ?? array(),
			)
		);

		$payload                 = self::present( Site_Brief_Store::get() );
		$payload['redirect_url'] = admin_url(
			'admin.php?page=agentic-chat&agent=' . rawurlencode( $agent ) . '&brief=' . rawurlencode( $token )
		);
		return new \WP_REST_Response( $payload, 200 );
	}

	/**
	 * GET /site-brief/opening/{token} — one-time finding payload for chat.
	 *
	 * Consumes the transient so a reload cannot replay the opening message.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get_opening( \WP_REST_Request $request ) {
		$message = self::consume_opening( (string) $request->get_param( 'token' ) );
		if ( '' === $message ) {
			return new \WP_Error(
				'site_brief_opening_gone',
				__( 'That assignment message is no longer available.', 'agent-builder' ),
				array( 'status' => 404 )
			);
		}
		return new \WP_REST_Response( array( 'message' => $message ), 200 );
	}

	/**
	 * Consume a one-time Site Brief opening payload.
	 *
	 * @param string $token Short token from the chat deep-link.
	 * @return string Empty when missing, expired, or already consumed.
	 */
	public static function consume_opening( string $token ): string {
		$token = sanitize_key( $token );
		if ( '' === $token ) {
			return '';
		}
		$key  = self::opening_transient_key( $token );
		$data = get_transient( $key );
		delete_transient( $key );
		if ( ! is_array( $data ) ) {
			return '';
		}
		return (string) ( $data['message'] ?? '' );
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

		$dismissed    = (array) ( $data['dismissed'] ?? array() );
		$assigned_map = is_array( $data['assigned'] ?? null ) ? $data['assigned'] : array();

		$cards = array();
		foreach ( (array) ( $data['cards'] ?? array() ) as $card ) {
			if ( ! is_array( $card ) ) {
				continue;
			}
			// A dismissed card stays hidden until its evidence changes — mirror the
			// runner's filter here so a dismissal takes effect immediately, not only
			// after the next scan.
			if ( Site_Brief_Store::is_dismissed( (string) ( $card['id'] ?? '' ), (string) ( $card['evidence_hash'] ?? '' ), $dismissed ) ) {
				continue;
			}
			$card_id = (string) ( $card['id'] ?? '' );
			if ( isset( $assigned_map[ $card_id ] ) && is_array( $assigned_map[ $card_id ] ) ) {
				$entry            = $assigned_map[ $card_id ];
				$card['assigned'] = array(
					'agent'       => (string) ( $entry['agent'] ?? '' ),
					'agent_label' => (string) ( $entry['agent_label'] ?? '' ),
					'at'          => (string) ( $entry['at'] ?? '' ),
				);
				if ( '' === $card['assigned']['agent_label'] ) {
					$card['assigned']['agent_label'] = $card['assigned']['agent'];
				}
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
				'run' => $can_run,
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

	/**
	 * Transient key for a one-time opening payload.
	 *
	 * @param string $token Short token.
	 * @return string
	 */
	private static function opening_transient_key( string $token ): string {
		return 'agent_builder_brief_open_' . $token;
	}

	/**
	 * Persist the finding as a short-lived one-time payload.
	 *
	 * @param string $message Opening user message.
	 * @param string $agent   Recommended agent slug.
	 * @param string $card_id Card id.
	 * @return string Token used in the chat deep-link.
	 */
	private static function store_opening( string $message, string $agent, string $card_id ): string {
		$token = strtolower( wp_generate_password( 16, false, false ) );
		set_transient(
			self::opening_transient_key( $token ),
			array(
				'message' => $message,
				'agent'   => $agent,
				'card_id' => $card_id,
			),
			10 * MINUTE_IN_SECONDS
		);
		return $token;
	}

	/**
	 * Compose the collaborative opening message from a card.
	 *
	 * @param array<string, mixed> $card Stored card.
	 * @return string
	 */
	private static function compose_opening_message( array $card ): string {
		$title  = trim( (string) ( $card['title'] ?? '' ) );
		$ev     = trim( (string) ( $card['evidence'] ?? '' ) );
		$action = trim( (string) ( $card['proposed_action'] ?? '' ) );

		$parts   = array();
		$parts[] = sprintf(
			/* translators: %s: Site Brief card title. */
			__( 'Site Brief flagged this on my site: "%s".', 'agent-builder' ),
			$title
		);
		if ( '' !== $ev ) {
			$parts[] = $ev;
		}
		if ( '' !== $action ) {
			$parts[] = $action;
		}
		$parts[] = __(
			'Please investigate this on my site and help me fix it safely — explain what you find, and ask me before making any change.',
			'agent-builder'
		);
		return implode( ' ', $parts );
	}

	/**
	 * Activate the recommended agent when it is installed but inactive.
	 *
	 * Failures are ignored — the chat page already falls back to an accessible
	 * agent when the slug is unknown or still inactive.
	 *
	 * @param string $slug Agent slug.
	 * @return void
	 */
	private static function maybe_activate_agent( string $slug ): void {
		if ( '' === $slug || ! class_exists( '\Agentic_Agent_Registry' ) ) {
			return;
		}
		$registry = \Agentic_Agent_Registry::get_instance();
		if ( $registry->is_agent_active( $slug ) ) {
			return;
		}
		$registry->activate_agent( $slug );
	}
}
