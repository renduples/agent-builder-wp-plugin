<?php
/**
 * Skill Drafter REST — draft a skill from an existing conversation.
 *
 * Thin REST wiring over Skill_Drafter: the route delegates to the drafter and
 * adds nothing beyond request/response translation. It sits behind the same
 * tool-management capability the Tools screen itself requires.
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
 * Skill_Drafter_REST
 *
 * @since 4.4.0
 */
class Skill_Drafter_REST {

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
			'/skills/draft-from-conversation',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'draft_from_conversation' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'args'                => array(
					'session_id' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'message_id' => array(
						'type'              => 'integer',
						'required'          => false,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * Manage capability — the route sits behind the tool-management capability.
	 *
	 * @return bool
	 */
	public static function can_manage(): bool {
		return current_user_can( 'agent_builder_manage_tools' );
	}

	/**
	 * POST /skills/draft-from-conversation — draft a skill from a conversation.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function draft_from_conversation( \WP_REST_Request $request ) {
		$session_id = sanitize_text_field( (string) $request->get_param( 'session_id' ) );
		$message_id = $request->get_param( 'message_id' );
		$up_to_id   = ( null !== $message_id && '' !== $message_id ) ? (int) $message_id : null;

		$result = Skill_Drafter::from_conversation( $session_id, $up_to_id );

		if ( empty( $result['ok'] ) ) {
			return new \WP_Error(
				'rest_skill_draft_failed',
				(string) ( $result['error'] ?? __( 'Could not draft the skill.', 'agent-builder' ) ),
				array( 'status' => 400 )
			);
		}

		return new \WP_REST_Response( $result, 201 );
	}
}
