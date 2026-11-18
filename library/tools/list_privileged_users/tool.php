<?php
declare(strict_types=1);

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * List Privileged Users Tool
 *
 * Lists users with elevated privileges (administrators and editors)
 * for reviewing access to sensitive site functions.
 *
 * @package Agentic\Tools
 */
class List_Privileged_Users extends \Agentic\Tool_Base {

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'list_privileged_users';
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return 'List users with elevated privileges (administrators and editors). Useful for reviewing who has access to sensitive site functions.';
	}

	/**
	 * Get the tool category.
	 *
	 * @return string
	 */
	public function get_category(): string {
		return 'security';
	}

	/**
	 * Get the tool parameters.
	 *
	 * @return array
	 */
	public function get_parameters(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'role' => array(
					'type'        => 'string',
					'description' => '"administrator" (default), "editor", or "all_elevated" (both roles).',
				),
			),
		);
	}

	/**
	 * Execute the privileged users listing.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array
	 */
	public function execute( array $arguments ): array {
		// Discloses other users' usernames and email addresses — the
		// tool-generic edit_posts floor a caller might otherwise only need
		// (e.g. a Contributor over WebMCP) is not remotely enough; this
		// needs the same capability wp-admin's own Users list requires.
		if ( ! current_user_can( 'list_users' ) ) {
			return array( 'error' => 'You do not have permission to view user accounts.' );
		}

		$role_arg = $arguments['role'] ?? 'administrator';
		$roles    = 'all_elevated' === $role_arg ? array( 'administrator', 'editor' ) : array( $role_arg );
		$users    = get_users(
			array(
				'role__in' => $roles,
				'orderby'  => 'registered',
				'order'    => 'DESC',
			)
		);

		return array(
			'roles' => $roles,
			'total' => count( $users ),
			'users' => array_map(
				fn( $u ) => array(
					'id'           => (int) $u->ID,
					'display_name' => $u->display_name,
					'user_login'   => $u->user_login,
					'user_email'   => $u->user_email,
					'roles'        => $u->roles,
					'registered'   => $u->user_registered,
				),
				$users
			),
		);
	}

	/**
	 * Get the tool annotations.
	 *
	 * @return array
	 */
	public function get_annotations(): array {
		return array(
			'readonly' => true,
			'personal_data' => true,
		);
	}
}

return new List_Privileged_Users();
