<?php
/**
 * Tool: db_update_option
 *
 * Update a WordPress option value with security safeguards.
 *
 * @package    Agent_Builder
 * @subpackage Tools
 * @since      2.0.0
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Update a WordPress option, blocking sensitive and protected options.
 */
class Db_Update_Option extends \Agentic\Tool_Base {

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'db_update_option';
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return 'Update a WordPress option value. Sensitive options and critical site settings (siteurl, home, active_plugins) are blocked.';
	}

	/**
	 * Get the tool category.
	 *
	 * @return string
	 */
	public function get_category(): string {
		return 'database';
	}

	/**
	 * Get the parameter schema.
	 *
	 * @return array
	 */
	public function get_parameters(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'name'  => array(
					'type'        => 'string',
					'description' => 'Option name to update.',
				),
				'value' => array(
					'type'        => 'string',
					'description' => 'New option value. For arrays or objects, pass a JSON string.',
				),
			),
			'required'   => array( 'name', 'value' ),
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Validated arguments from the LLM.
	 * @return array Result data.
	 */
	public function execute( array $arguments ): array {
		$name = $arguments['name'] ?? '';

		if ( empty( $name ) ) {
			return array( 'error' => 'Option name is required.' );
		}

		if ( \Agentic\Tool_Helpers::is_sensitive_name( $name ) ) {
			return array(
				'name'  => $name,
				'error' => 'This option is blocked for security reasons.',
			);
		}

		$protected_options = array(
			'siteurl',
			'home',
			'admin_email',
			'users_can_register',
			'default_role',
			'active_plugins',
			'template',
			'stylesheet',
			'db_version',
			'initial_db_version',
			'wp_user_roles',
			'agent_builder_disabled_tools',
			'agent_builder_tool_scopes',
			'agent_builder_active_agents',
		);

		if ( in_array( $name, $protected_options, true ) ) {
			return array(
				'name'  => $name,
				'error' => 'This option is protected and cannot be changed by agents.',
			);
		}

		$value   = $arguments['value'] ?? '';
		$decoded = json_decode( $value, true );

		if ( null !== $decoded && json_last_error() === JSON_ERROR_NONE ) {
			$value = $decoded;
		}

		$updated = update_option( $name, $value );

		return array(
			'name'    => $name,
			'updated' => $updated,
		);
	}

	/**
	 * Get tool annotations.
	 *
	 * @return array
	 */
	public function get_annotations(): array {
		return array(
			'destructive' => true,
			'idempotent'  => true,
		);
	}
}

return new Db_Update_Option();
