<?php
/**
 * Tool: lock_user_account
 *
 * @package    Agent_Builder
 * @subpackage Tools
 * @since      2.0.0
 *
 * php version 8.1
 */

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Agentic\Tool_Base;

class Lock_User_Account extends Tool_Base {
	public function get_name(): string {
		return 'lock_user_account';
	}

	public function get_description(): string {
		return 'Lock a user account: revoke all capabilities and destroy active sessions. Cannot lock administrators unless the current user is also an administrator and is not locking themselves.';
	}

	public function get_category(): string {
		return 'users';
	}

	public function get_parameters(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'user_id' => array(
					'type'        => 'integer',
					'description' => 'ID of the user account to lock.',
				),
				'reason'  => array(
					'type'        => 'string',
					'description' => 'Optional reason for locking the account (stored in usermeta).',
				),
			),
			'required'   => array( 'user_id' ),
		);
	}

	public function get_annotations(): array {
		return array(
			'read_only'   => false,
			'destructive' => false,
		);
	}

	public function execute( array $args ): array {
		$user_id = (int) ( $args['user_id'] ?? 0 );
		$reason  = sanitize_text_field( $args['reason'] ?? '' );

		if ( ! $user_id ) {
			return array( 'error' => 'user_id is required.' );
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return array( 'error' => 'User not found.' );
		}

		$current_user = wp_get_current_user();

		// Prevent locking administrators unless current user is also admin and not locking themselves.
		if ( in_array( 'administrator', (array) $user->roles, true ) ) {
			// phpcs:ignore WordPress.WP.Capabilities.RoleFound -- deliberate role-name check; a capability swap would change lock behavior.
			if ( ! current_user_can( 'administrator' ) ) {
				return array( 'error' => 'You must be an administrator to lock another administrator account.' );
			}
			if ( $current_user->ID === $user_id ) {
				return array( 'error' => 'You cannot lock your own account.' );
			}
		}

		// Revoke all caps.
		$wp_user = new \WP_User( $user_id );
		$wp_user->remove_all_caps();

		// Store lock metadata.
		update_user_meta( $user_id, 'agentic_account_locked', time() );
		if ( $reason ) {
			update_user_meta( $user_id, 'agentic_account_locked_reason', $reason );
		}

		// Destroy all active sessions.
		$session_manager = \WP_Session_Tokens::get_instance( $user_id );
		$session_manager->destroy_all();

		return array(
			'locked'             => true,
			'user_id'            => $user_id,
			'user_display_name'  => $user->display_name,
			'sessions_destroyed' => true,
			'reason'             => $reason ? $reason : null,
			'note'               => 'To unlock: restore the user\'s role via wp-admin Users screen or wp_update_user(). Delete the agentic_account_locked usermeta to clear the lock flag.',
		);
	}
}

return new Lock_User_Account();
