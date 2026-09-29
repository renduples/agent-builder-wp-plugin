<?php
declare(strict_types=1);

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Force Password Reset Tool
 *
 * Sends a password reset email to a specified user, forcing them
 * to change their password on next login. Does not lock the account
 * or change the current password — the user simply receives a
 * standard WordPress password reset link.
 *
 * @package Agentic\Tools
 */
class Force_Password_Reset extends \Agentic\Tool_Base {

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'force_password_reset';
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return 'Send a password reset email to a user, forcing them to change their password. Useful after detecting compromised credentials or suspicious login activity.';
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
				'user_id' => array(
					'type'        => 'integer',
					'description' => 'The WordPress user ID to send a password reset email to.',
				),
			),
			'required'   => array( 'user_id' ),
		);
	}

	/**
	 * Execute the password reset.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array
	 */
	public function execute( array $arguments ): array {
		$user_id = (int) ( $arguments['user_id'] ?? 0 );

		if ( ! $user_id ) {
			return array(
				'success' => false,
				'error'   => 'user_id is required.',
			);
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return array(
				'success' => false,
				'error'   => 'User not found with ID ' . $user_id . '.',
			);
		}

		// Prevent resetting the current user's own password via the agent.
		if ( get_current_user_id() === $user_id ) {
			return array(
				'success' => false,
				'error'   => 'Cannot force a password reset on your own account via an agent.',
			);
		}

		// Generate reset key and send the email.
		$key    = get_password_reset_key( $user );
		$result = false;

		if ( ! is_wp_error( $key ) ) {
			$reset_url = network_site_url( "wp-login.php?action=rp&key={$key}&login=" . rawurlencode( $user->user_login ), 'login' );

			$message = sprintf( __( 'A password reset has been requested for the following account:', 'agent-builder' ) ) . "\r\n\r\n";
			/* translators: %s: site name */
			$message .= sprintf( __( 'Site: %s', 'agent-builder' ), get_bloginfo( 'name' ) ) . "\r\n";
			/* translators: %s: username */
			$message .= sprintf( __( 'Username: %s', 'agent-builder' ), $user->user_login ) . "\r\n\r\n";
			$message .= __( 'To reset your password, visit the following address:', 'agent-builder' ) . "\r\n\r\n";
			$message .= $reset_url . "\r\n\r\n";
			$message .= __( 'This password reset was initiated by your site administrator for security purposes.', 'agent-builder' ) . "\r\n";

			$subject = sprintf( '[%s] Password Reset (Security)', get_bloginfo( 'name' ) );

			$result = wp_mail( $user->user_email, $subject, $message );
		}

		if ( $result ) {
			return array(
				'success'      => true,
				'user_id'      => $user_id,
				'display_name' => $user->display_name,
				'user_login'   => $user->user_login,
				'email_sent'   => true,
				'message'      => 'Password reset email sent to ' . $user->display_name . ' (' . $user->user_login . '). They will need to change their password before logging in again.',
			);
		}

		return array(
			'success' => false,
			'error'   => 'Failed to send password reset email. Check your site mail configuration.',
		);
	}

	/**
	 * Get the tool annotations.
	 *
	 * @return array
	 */
	public function get_annotations(): array {
		return array(
			'readonly' => false,
		);
	}
}

return new Force_Password_Reset();
