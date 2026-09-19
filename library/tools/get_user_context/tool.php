<?php
/**
 * Tool: get_user_context
 *
 * Read-only lookup of the real, currently authenticated WordPress user this
 * conversation belongs to: display name, WP roles, and the specific
 * agent_builder_* / manage_options capabilities that actually gate what they can
 * do in this plugin (including whether they can resolve an item in the
 * Approval Queue). Lets an agent tailor its guidance — e.g. explaining that
 * only an administrator can approve a high-risk action — without ever
 * trusting anything the model itself claims about the user.
 *
 * @package    Agent_Builder
 * @subpackage Tools
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reports the current chat user's identity and Agent Builder privileges.
 */
class Get_User_Context extends \Agentic\Tool_Base {

	/**
	 * Get the tool name.
	 */
	public function get_name(): string {
		return 'get_user_context';
	}

	/**
	 * Get the tool description.
	 */
	public function get_description(): string {
		return 'Get information about the person you are currently chatting with: their display name, WordPress role(s), and whether they can manage Agent Builder or approve high-risk actions. Use this before promising something only an administrator can do (e.g. approving a queued high-risk action) — never assume the user\'s access level.';
	}

	/**
	 * Get the tool category.
	 */
	public function get_category(): string {
		return 'agents';
	}

	/**
	 * Get the parameter schema. No inputs — always reports the real,
	 * currently authenticated user of this conversation.
	 */
	public function get_parameters(): array {
		return array(
			'type'       => 'object',
			'properties' => new \stdClass(),
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Unused — no parameters.
	 * @return array Result data.
	 */
	public function execute( array $arguments ): array {
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return $this->success(
				array(
					'is_logged_in' => false,
					'summary'      => 'This person is not logged in to WordPress, so they have no Agent Builder privileges here.',
				)
			);
		}

		$user             = wp_get_current_user();
		$is_admin         = current_user_can( 'manage_options' );
		$can_manage_agents = $is_admin || current_user_can( 'agent_builder_manage_agents' );

		$capabilities = array();
		foreach ( \Agentic\User_Roles::get_plugin_privileges() as $key => $info ) {
			$capabilities[ $key ] = current_user_can( \Agentic\User_Roles::CAP_PREFIX . $key );
		}
		foreach ( \Agentic\User_Roles::get_agent_privileges() as $key => $info ) {
			$capabilities[ $key ] = current_user_can( \Agentic\User_Roles::CAP_PREFIX . $key );
		}

		$roles = array_values( (array) $user->roles );

		if ( $is_admin ) {
			$summary = sprintf(
				'%s is a WordPress Administrator — full access, including approving high-risk actions directly in this chat.',
				$user->display_name
			);
		} elseif ( $can_manage_agents ) {
			$summary = sprintf(
				'%s can manage agents and approve high-risk actions in this chat, but does not have full manage_options access.',
				$user->display_name
			);
		} else {
			$summary = sprintf(
				'%s (role: %s) cannot manage Agent Builder or approve high-risk actions. If something needs admin approval, a site administrator will need to do it.',
				$user->display_name,
				$roles ? implode( ', ', $roles ) : 'none'
			);
		}

		return $this->success(
			array(
				'is_logged_in'                  => true,
				'user_id'                       => $user_id,
				'display_name'                  => $user->display_name,
				'roles'                         => $roles,
				'is_administrator'              => $is_admin,
				'can_approve_high_risk_actions' => $can_manage_agents,
				'capabilities'                  => $capabilities,
				'summary'                       => $summary,
			)
		);
	}

	/**
	 * Get tool annotations.
	 */
	public function get_annotations(): array {
		return array(
			'readonly'    => true,
			'destructive' => false,
		);
	}
}

return new Get_User_Context();
