<?php
/**
 * Approval Rules REST — list, create, update and delete declarative risk rules.
 *
 * Backs the Approvals-tab rules editor: GET /approval-rules lists the rules a
 * manage_agents user may review, and POST/PUT/DELETE let an administrator
 * (manage_options) create, edit and remove them. This is CRUD only — there is
 * deliberately no enforcement hook or evaluation in this task (see wp#265).
 *
 * @package    Agent_Builder
 * @subpackage REST
 * @since      4.2.0
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Approval Rules REST controller.
 */
class Approval_Rules_REST {

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
			'/approval-rules',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_rules' ),
				'permission_callback' => array( __CLASS__, 'can_list_rules' ),
				'args'                => array(
					'agent_slug' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
					'enabled'    => array(
						'type'              => 'boolean',
						'sanitize_callback' => 'rest_sanitize_boolean',
					),
					'per_page'   => array(
						'type'              => 'integer',
						'default'           => 200,
						'sanitize_callback' => 'absint',
					),
					'page'       => array(
						'type'              => 'integer',
						'default'           => 1,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/approval-rules',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'create_rule' ),
				'permission_callback' => array( __CLASS__, 'can_write_rules' ),
				'args'                => self::rule_args(),
			)
		);

		register_rest_route(
			self::NS,
			'/approval-rules/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => array( __CLASS__, 'update_rule' ),
				'permission_callback' => array( __CLASS__, 'can_write_rules' ),
				'args'                => self::rule_args(),
			)
		);

		register_rest_route(
			self::NS,
			'/approval-rules/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'delete_rule' ),
				'permission_callback' => array( __CLASS__, 'can_write_rules' ),
			)
		);
	}

	/**
	 * List capability — reading rules matches the Approvals-tab audience.
	 *
	 * @return bool
	 */
	public static function can_list_rules(): bool {
		return current_user_can( 'manage_options' ) || current_user_can( 'agent_builder_manage_agents' );
	}

	/**
	 * Write capability — creating/editing/deleting rules is admin-only.
	 *
	 * @return bool
	 */
	public static function can_write_rules(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * GET /approval-rules — the rule set, optionally filtered by agent/enabled.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function get_rules( \WP_REST_Request $request ): \WP_REST_Response {
		$args = array();

		$slug = sanitize_key( (string) $request->get_param( 'agent_slug' ) );
		if ( '' !== $slug ) {
			$args['agent_slug'] = $slug;
		}

		if ( null !== $request->get_param( 'enabled' ) ) {
			$args['enabled'] = (bool) $request->get_param( 'enabled' );
		}

		$args['per_page'] = max( 1, min( 500, (int) $request->get_param( 'per_page' ) ) );
		$args['page']     = max( 1, (int) $request->get_param( 'page' ) );

		return new \WP_REST_Response( array( 'rules' => Approval_Rules::list( $args ) ), 200 );
	}

	/**
	 * POST /approval-rules — create a rule.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function create_rule( \WP_REST_Request $request ) {
		$data = self::payload( $request );

		if ( '' === trim( (string) ( $data['rule_text'] ?? '' ) ) ) {
			return new \WP_Error( 'missing_rule_text', __( 'A rule description is required.', 'agent-builder' ), array( 'status' => 400 ) );
		}

		if ( '' === (string) ( $data['effect'] ?? '' ) ) {
			return new \WP_Error( 'missing_effect', __( 'An effect (ask, allow, or deny) is required.', 'agent-builder' ), array( 'status' => 400 ) );
		}

		$error = Approval_Rules::validate( $data );
		if ( null !== $error ) {
			return $error;
		}

		$data['created_by'] = get_current_user_id();

		$id = Approval_Rules::create( $data );
		if ( 0 === $id ) {
			return new \WP_Error( 'create_failed', __( 'The rule could not be saved.', 'agent-builder' ), array( 'status' => 500 ) );
		}

		return new \WP_REST_Response( array( 'rule' => Approval_Rules::get( $id ) ), 201 );
	}

	/**
	 * PUT /approval-rules/{id} — update a rule's provided fields.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function update_rule( \WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );

		if ( null === Approval_Rules::get( $id ) ) {
			return new \WP_Error( 'not_found', __( 'Rule not found.', 'agent-builder' ), array( 'status' => 404 ) );
		}

		$data = self::payload( $request );

		$error = Approval_Rules::validate( $data );
		if ( null !== $error ) {
			return $error;
		}

		Approval_Rules::update( $id, $data );

		return new \WP_REST_Response( array( 'rule' => Approval_Rules::get( $id ) ), 200 );
	}

	/**
	 * DELETE /approval-rules/{id} — remove a rule.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function delete_rule( \WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );

		if ( null === Approval_Rules::get( $id ) ) {
			return new \WP_Error( 'not_found', __( 'Rule not found.', 'agent-builder' ), array( 'status' => 404 ) );
		}

		Approval_Rules::delete( $id );

		return new \WP_REST_Response(
			array(
				'success' => true,
				'id'      => $id,
			),
			200
		);
	}

	/**
	 * Shared argument schema for the rule-writing routes.
	 *
	 * No defaults here: the model applies its own defaults on create, and a PUT
	 * that omits a field must leave that column unchanged rather than resetting it.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function rule_args(): array {
		return array(
			'agent_slug' => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
			),
			'rule_text'  => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
			),
			'effect'     => array(
				'type' => 'string',
			),
			'priority'   => array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'enabled'    => array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
			),
		);
	}

	/**
	 * Extract the writable fields present in a request into a clean array.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	private static function payload( \WP_REST_Request $request ): array {
		$data = array();

		if ( null !== $request->get_param( 'agent_slug' ) ) {
			$data['agent_slug'] = sanitize_key( (string) $request->get_param( 'agent_slug' ) );
		}
		if ( null !== $request->get_param( 'rule_text' ) ) {
			$data['rule_text'] = trim( (string) $request->get_param( 'rule_text' ) );
		}
		if ( null !== $request->get_param( 'effect' ) ) {
			$data['effect'] = (string) $request->get_param( 'effect' );
		}
		if ( null !== $request->get_param( 'priority' ) ) {
			$data['priority'] = (int) $request->get_param( 'priority' );
		}
		if ( null !== $request->get_param( 'enabled' ) ) {
			$data['enabled'] = (bool) $request->get_param( 'enabled' );
		}

		return $data;
	}
}
