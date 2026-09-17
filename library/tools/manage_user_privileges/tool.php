<?php
/**
 * Tool: manage_user_privileges
 *
 * Read and change which WordPress roles can administer Agent Builder or
 * interact with its AI agents, per-role daily usage limits, and whether
 * anonymous visitors may use frontend chat — the same settings the classic
 * Settings > Users tab manages, via the shared User_Roles/Usage_Limits data
 * layer.
 *
 * @package    Agent_Builder
 * @subpackage Tools
 * @since      3.3.76
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Agentic\User_Roles;
use Agentic\Usage_Limits;

/**
 * Manage role-based plugin/agent privileges and per-role usage limits.
 */
class Manage_User_Privileges extends \Agentic\Tool_Base {

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'manage_user_privileges';
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return 'Read or change which WordPress roles can administer Agent Builder and use its AI agents, set per-role daily usage limits, or allow anonymous frontend chat. Administrators always retain full access regardless of these settings.';
	}

	/**
	 * Get the tool category.
	 *
	 * @return string
	 */
	public function get_category(): string {
		return 'user-assistant';
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
				'action'   => array(
					'type'        => 'string',
					'enum'        => array( 'get', 'set_privilege', 'set_usage_limit', 'set_anonymous_chat' ),
					'description' => 'get: read every role, privilege, and limit. set_privilege: grant or revoke one plugin/agent privilege for one role. set_usage_limit: change a role\'s daily query/token cap. set_anonymous_chat: allow or block not-logged-in visitors from using frontend chat.',
				),
				'category' => array(
					'type'        => 'string',
					'enum'        => array( 'plugin', 'agent' ),
					'description' => '"plugin": administering Agent Builder itself (dashboard, agents, audit log, tools, settings). "agent": interacting with AI agents (chat surfaces, viewing the agent list). Required for set_privilege.',
				),
				'key'      => array(
					'type'        => 'string',
					'description' => 'The privilege key to change, e.g. "manage_settings" or "chat_admin_bar" — call action: get first to see the exact keys and what each one means. Required for set_privilege.',
				),
				'role'     => array(
					'type'        => 'string',
					'description' => 'WordPress role slug (e.g. "editor", "subscriber"), or "anonymous" for not-logged-in visitors when setting a usage limit. Required for set_privilege/set_usage_limit.',
				),
				'allowed'  => array(
					'type'        => 'boolean',
					'description' => 'Whether the role should have this privilege. Required for set_privilege.',
				),
				'queries'  => array(
					'type'        => 'integer',
					'description' => 'Max chat messages per day for this role. 0 = unlimited. Omit to leave unchanged.',
				),
				'tokens'   => array(
					'type'        => 'integer',
					'description' => 'Max tokens per day for this role. 0 = unlimited. Omit to leave unchanged.',
				),
				'enabled'  => array(
					'type'        => 'boolean',
					'description' => 'Required for set_anonymous_chat — whether visitors who are not logged in may use frontend chat widgets/shortcodes.',
				),
			),
			'required'   => array( 'action' ),
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Validated arguments from the LLM.
	 * @return array Result data.
	 */
	public function execute( array $arguments ): array {
		$denied = \Agentic\Tool_Helpers::deny_unless_admin_user();
		if ( null !== $denied ) {
			return $denied;
		}

		$action = sanitize_key( (string) ( $arguments['action'] ?? '' ) );

		if ( 'get' === $action ) {
			return $this->do_get();
		}
		if ( 'set_privilege' === $action ) {
			return $this->do_set_privilege( $arguments );
		}
		if ( 'set_usage_limit' === $action ) {
			return $this->do_set_usage_limit( $arguments );
		}
		if ( 'set_anonymous_chat' === $action ) {
			return $this->do_set_anonymous_chat( $arguments );
		}

		return array( 'error' => 'Unknown action. Use get, set_privilege, set_usage_limit, or set_anonymous_chat.' );
	}

	/**
	 * Read every role, privilege, and usage limit.
	 *
	 * @return array
	 */
	private function do_get(): array {
		return array(
			'ok' => true,
		) + $this->snapshot();
	}

	/**
	 * Grant or revoke one plugin/agent privilege for one non-administrator role.
	 *
	 * @param array $args Tool arguments.
	 * @return array
	 */
	private function do_set_privilege( array $args ): array {
		$category = sanitize_key( (string) ( $args['category'] ?? '' ) );
		$key      = sanitize_key( (string) ( $args['key'] ?? '' ) );
		$role     = sanitize_key( (string) ( $args['role'] ?? '' ) );

		if ( ! isset( $args['allowed'] ) ) {
			return array( 'error' => 'allowed (true/false) is required.' );
		}
		$allowed = (bool) $args['allowed'];

		if ( ! in_array( $category, array( 'plugin', 'agent' ), true ) ) {
			return array( 'error' => 'category must be "plugin" or "agent".' );
		}

		$definitions = 'plugin' === $category ? User_Roles::get_plugin_privileges() : User_Roles::get_agent_privileges();
		if ( ! isset( $definitions[ $key ] ) ) {
			return array( 'error' => sprintf( 'Unknown %s privilege "%s". Call action: get to see the available keys.', $category, $key ) );
		}

		$all_roles = User_Roles::get_all_wp_roles();
		if ( ! isset( $all_roles[ $role ] ) ) {
			return array( 'error' => sprintf( 'Unknown role "%s".', $role ) );
		}
		if ( 'administrator' === $role ) {
			return array( 'error' => 'Administrators always have full access — this can\'t be changed.' );
		}

		$settings         = User_Roles::get_settings();
		$section          = 'plugin' === $category ? 'plugin' : 'agents';
		$current_roles    = (array) ( $settings[ $section ][ $key ] ?? array() );
		$has_role         = in_array( $role, $current_roles, true );

		if ( $allowed && ! $has_role ) {
			$current_roles[] = $role;
		} elseif ( ! $allowed && $has_role ) {
			$current_roles = array_values( array_diff( $current_roles, array( $role ) ) );
		}
		$settings[ $section ][ $key ] = $current_roles;

		User_Roles::save_settings( $settings );

		return array( 'ok' => true ) + $this->snapshot();
	}

	/**
	 * Change a role's (or anonymous visitors') daily usage limits.
	 *
	 * @param array $args Tool arguments.
	 * @return array
	 */
	private function do_set_usage_limit( array $args ): array {
		$role = sanitize_key( (string) ( $args['role'] ?? '' ) );
		if ( '' === $role ) {
			return array( 'error' => 'role is required — a WordPress role slug, or "anonymous".' );
		}
		if ( 'anonymous' !== $role && ! isset( User_Roles::get_all_wp_roles()[ $role ] ) ) {
			return array( 'error' => sprintf( 'Unknown role "%s".', $role ) );
		}
		if ( ! isset( $args['queries'] ) && ! isset( $args['tokens'] ) ) {
			return array( 'error' => 'Provide at least one of queries or tokens.' );
		}

		$limits         = Usage_Limits::get_limits();
		$existing       = $limits[ $role ] ?? array(
			'queries' => 0,
			'tokens'  => 0,
		);
		$limits[ $role ] = array(
			'queries' => isset( $args['queries'] ) ? max( 0, absint( $args['queries'] ) ) : (int) $existing['queries'],
			'tokens'  => isset( $args['tokens'] ) ? max( 0, absint( $args['tokens'] ) ) : (int) $existing['tokens'],
		);

		Usage_Limits::save_limits( $limits );

		return array( 'ok' => true ) + $this->snapshot();
	}

	/**
	 * Allow or block anonymous (not logged in) frontend chat.
	 *
	 * @param array $args Tool arguments.
	 * @return array
	 */
	private function do_set_anonymous_chat( array $args ): array {
		if ( ! isset( $args['enabled'] ) ) {
			return array( 'error' => 'enabled (true/false) is required.' );
		}
		update_option( 'agent_builder_allow_anonymous_chat', ! empty( $args['enabled'] ) ? 1 : 0 );

		return array( 'ok' => true ) + $this->snapshot();
	}

	/**
	 * Flatten the current roles/privileges/limits picture for the LLM.
	 *
	 * @return array
	 */
	private function snapshot(): array {
		$roles_out = array();
		foreach ( User_Roles::get_all_wp_roles() as $slug => $role_data ) {
			$roles_out[] = array(
				'slug' => (string) $slug,
				'name' => translate_user_role( (string) ( $role_data['name'] ?? $slug ) ),
			);
		}

		$settings     = User_Roles::get_settings();
		$plugin_privs = array();
		foreach ( User_Roles::get_plugin_privileges() as $key => $info ) {
			$plugin_privs[] = array(
				'key'         => (string) $key,
				'label'       => (string) ( $info['label'] ?? $key ),
				'description' => (string) ( $info['description'] ?? '' ),
				'roles'       => array_values( (array) ( $settings['plugin'][ $key ] ?? array() ) ),
			);
		}
		$agent_privs = array();
		foreach ( User_Roles::get_agent_privileges() as $key => $info ) {
			$agent_privs[] = array(
				'key'         => (string) $key,
				'label'       => (string) ( $info['label'] ?? $key ),
				'description' => (string) ( $info['description'] ?? '' ),
				'roles'       => array_values( (array) ( $settings['agents'][ $key ] ?? array() ) ),
			);
		}

		return array(
			'roles'                => $roles_out,
			'plugin_privileges'    => $plugin_privs,
			'agent_privileges'     => $agent_privs,
			'usage_limits'         => Usage_Limits::get_limits(),
			'allow_anonymous_chat' => (bool) get_option( 'agent_builder_allow_anonymous_chat', false ),
		);
	}

	/**
	 * Get tool annotations.
	 *
	 * @return array
	 */
	public function get_annotations(): array {
		return array(
			'readonly'    => false,
			'destructive' => false,
		);
	}
}

return new Manage_User_Privileges();
