<?php
/**
 * REST controller backing the Agent Creation Wizard (the "Train an Agent" flow).
 *
 * Exposes two admin-only endpoints under the agentic/v1 namespace:
 *   - GET  /agent-wizard/options : form data (providers, categories, modes, tools).
 *   - POST /agent-wizard/create  : create + activate a new user agent.
 *
 * Agent creation is delegated to the audited `create_agent_files` tool, which
 * builds the declarative agent.json manifest, generates and signs abilities.json,
 * writes the system prompt template, clears caches and activates the agent. This
 * controller never writes agent files itself; it validates input, maps it to the
 * tool, persists optional per-agent provider/model/mode overrides, and returns
 * follow-up URLs for the wizard's success screen.
 *
 * @package Agent_Builder
 * @since   2.14.0
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Agent Creation Wizard REST controller.
 */
class Agent_Wizard_REST {

	const NS = 'agentic/v1';

	/**
	 * Agent categories the manifest validator accepts (mirrors
	 * Agent_Manifest_Validator::ALLOWED_CATEGORIES, which is private).
	 *
	 * @var string[]
	 */
	const CATEGORIES = array( 'content', 'admin', 'ecommerce', 'frontend', 'developer', 'seo', 'security', 'media', 'support' );

	/**
	 * Execution modes a new agent may be given.
	 *
	 * @var string[]
	 */
	const MODES = array( 'disabled', 'supervised', 'autonomous' );

	/**
	 * Safe, read-only tools assigned when the user selects none. An agent must
	 * declare at least one valid tool to generate the abilities.json manifest
	 * required for activation, so this keeps a wizard-created agent immediately
	 * usable. Non-existent tools are skipped by the create tool.
	 *
	 * @var string[]
	 */
	const DEFAULT_TOOLS = array( 'search_content', 'list_posts', 'get_post_content', 'get_site_context' );

	/**
	 * Register the rest_api_init hook.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register the wizard routes.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::NS,
			'/agent-wizard/options',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_options' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NS,
			'/agent-wizard/create',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'create_agent' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			)
		);
	}

	/**
	 * Creating agents writes files under wp-content and can grant tool access,
	 * so this flow is restricted to full administrators.
	 *
	 * @return bool
	 */
	public static function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Return the data needed to populate the wizard form.
	 *
	 * Provider records are reduced to a strict DTO so decrypted API keys and
	 * other secrets in the registry are never exposed to the browser.
	 *
	 * @return \WP_REST_Response
	 */
	public static function get_options(): \WP_REST_Response {
		$providers = array();
		if ( class_exists( '\Agentic\Provider_Registry' ) ) {
			foreach ( Provider_Registry::get_all() as $provider ) {
				$requires_key = ! empty( $provider['requires_key'] );
				$providers[]  = array(
					'slug'          => (string) ( $provider['slug'] ?? '' ),
					'name'          => (string) ( $provider['name'] ?? ( $provider['slug'] ?? '' ) ),
					'models'        => array_values( array_filter( (array) ( $provider['models'] ?? array() ), 'is_string' ) ),
					'default_model' => (string) ( $provider['default_model'] ?? '' ),
					'configured'    => ! $requires_key || ! empty( $provider['api_key'] ),
				);
			}
		}

		$tools = array();
		if ( class_exists( '\Agentic\Tool_Loader' ) ) {
			$loader = Tool_Loader::get_instance();
			$loader->load();
			foreach ( $loader->get_all() as $tool ) {
				$tools[] = array(
					'name'        => $tool->get_name(),
					'description' => $tool->get_description(),
					'category'    => $tool->get_category(),
					'risk'        => $tool->get_risk_level(),
				);
			}
			usort(
				$tools,
				static function ( array $a, array $b ): int {
					return strcmp( $a['name'], $b['name'] );
				}
			);
		}

		return new \WP_REST_Response(
			array(
				'categories' => self::CATEGORIES,
				'modes'      => self::MODES,
				'providers'  => $providers,
				'tools'      => $tools,
			),
			200
		);
	}

	/**
	 * Create and activate a new user agent from the wizard payload.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function create_agent( \WP_REST_Request $request ) {
		$name = sanitize_text_field( (string) $request->get_param( 'name' ) );
		if ( '' === trim( $name ) ) {
			return new \WP_Error( 'missing_name', __( 'An agent name is required.', 'agent-builder' ), array( 'status' => 400 ) );
		}

		$description = sanitize_textarea_field( (string) $request->get_param( 'description' ) );
		if ( '' === trim( $description ) ) {
			return new \WP_Error( 'missing_description', __( 'A short description is required.', 'agent-builder' ), array( 'status' => 400 ) );
		}

		$slug = sanitize_title( (string) $request->get_param( 'slug' ) );
		if ( '' === $slug ) {
			$slug = sanitize_title( $name );
		}
		if ( '' === $slug ) {
			return new \WP_Error( 'invalid_slug', __( 'Could not derive a valid slug from the agent name.', 'agent-builder' ), array( 'status' => 400 ) );
		}

		// Reject collisions early with a friendly message (the underlying tool
		// also guards this, but here we can return a precise 409).
		$registry = \Agentic_Agent_Registry::get_instance();
		$existing = $registry->get_installed_agents();
		if ( isset( $existing[ $slug ] ) || is_dir( AGENT_BUILDER_AGENTS_DIR . '/' . $slug ) ) {
			return new \WP_Error(
				'slug_exists',
				/* translators: %s: agent slug. */
				sprintf( __( 'An agent named "%s" already exists. Choose a different name.', 'agent-builder' ), $slug ),
				array( 'status' => 409 )
			);
		}

		$category = sanitize_key( (string) $request->get_param( 'category' ) );
		if ( ! in_array( $category, self::CATEGORIES, true ) ) {
			$category = 'admin';
		}

		$icon = sanitize_text_field( (string) $request->get_param( 'icon' ) );
		if ( '' === $icon ) {
			$icon = '🤖';
		}

		$system_prompt = sanitize_textarea_field( (string) $request->get_param( 'system_prompt' ) );

		$suggested_prompts = array();
		foreach ( (array) $request->get_param( 'suggested_prompts' ) as $prompt ) {
			$prompt = sanitize_text_field( (string) $prompt );
			if ( '' !== trim( $prompt ) ) {
				$suggested_prompts[] = $prompt;
			}
		}

		// Map selected tool slugs to the tool array shape the create tool expects.
		$tool_args = array();
		foreach ( (array) $request->get_param( 'tools' ) as $tool_name ) {
			$tool_name = sanitize_text_field( (string) $tool_name );
			if ( '' !== $tool_name ) {
				$tool_args[] = array( 'name' => $tool_name );
			}
		}

		// An agent needs at least one valid tool to build the abilities.json
		// manifest required for activation. If the user picked none, fall back to
		// a safe read-only default set so the new agent works right away.
		$used_default_tools = false;
		if ( empty( $tool_args ) ) {
			$used_default_tools = true;
			foreach ( self::DEFAULT_TOOLS as $tool_name ) {
				$tool_args[] = array( 'name' => $tool_name );
			}
		}

		$create_tool = null;
		if ( class_exists( '\Agentic\Tool_Loader' ) ) {
			$loader = Tool_Loader::get_instance();
			$loader->load();
			$create_tool = $loader->get( 'create_agent_files' );
		}
		if ( null === $create_tool ) {
			return new \WP_Error( 'tool_unavailable', __( 'The agent creation tool is unavailable.', 'agent-builder' ), array( 'status' => 500 ) );
		}

		$result = $create_tool->execute(
			array(
				'slug'              => $slug,
				'name'              => $name,
				'description'       => $description,
				'category'          => $category,
				'icon'              => $icon,
				'capabilities'      => array( 'read' ),
				'tools'             => $tool_args,
				'suggested_prompts' => $suggested_prompts,
				'system_prompt'     => $system_prompt,
				'overwrite'         => false,
			)
		);

		if ( ! empty( $result['error'] ) ) {
			return new \WP_Error( 'create_failed', (string) $result['error'], array( 'status' => 400 ) );
		}

		// Persist optional per-agent provider/model/mode overrides, validating
		// each against the known allowlists before writing.
		if ( class_exists( '\Agentic\Agent_Settings' ) ) {
			$provider = sanitize_text_field( (string) $request->get_param( 'provider' ) );
			$model    = sanitize_text_field( (string) $request->get_param( 'model' ) );
			$mode     = sanitize_key( (string) $request->get_param( 'mode' ) );

			$valid_providers = array();
			if ( class_exists( '\Agentic\Provider_Registry' ) ) {
				foreach ( Provider_Registry::get_all() as $p ) {
					$valid_providers[] = (string) ( $p['slug'] ?? '' );
				}
			}
			if ( '' !== $provider && in_array( $provider, $valid_providers, true ) ) {
				Agent_Settings::update( $slug, 'override_provider', $provider );
				if ( '' !== $model ) {
					Agent_Settings::update( $slug, 'override_model', $model );
				}
			}
			if ( in_array( $mode, self::MODES, true ) ) {
				Agent_Settings::update( $slug, 'override_mode', $mode );
			}
		}

		$response = array(
			'success'       => true,
			'slug'          => $slug,
			'name'          => $name,
			'activated'     => ! empty( $result['activated'] ),
			'default_tools' => $used_default_tools,
			'knowledge_url' => admin_url( 'admin.php?page=agentic-train-data' ),
			'chat_url'      => admin_url( 'admin.php?page=agentic-chat' ),
			'agents_url'    => admin_url( 'admin.php?page=agentic-agents' ),
			'configure_url' => admin_url( 'admin.php?page=agentic-settings&tab=instructions&edit_persona=' . rawurlencode( $slug ) ),
			'deploy_url'    => admin_url( 'admin.php?page=agentic-deploy-wizard&agent=' . rawurlencode( $slug ) ),
		);

		if ( ! empty( $result['warning'] ) ) {
			$response['warning'] = (string) $result['warning'];
		}

		// The wizard calls create_agent_files directly (not through
		// Tool_Executor), which is where the generic tool_call audit entry
		// normally comes from — log it explicitly here so agent creation via
		// the wizard leaves the same trail as any other admin action.
		if ( class_exists( Audit_Log::class ) ) {
			Audit_Log::log_admin(
				'agent_installed',
				$slug,
				array(
					'name'          => $name,
					'category'      => $category,
					'tools_count'   => count( $tool_args ),
					'default_tools' => $used_default_tools,
					'via'           => 'agent_wizard',
				)
			);
		}

		return new \WP_REST_Response( $response, 201 );
	}
}
