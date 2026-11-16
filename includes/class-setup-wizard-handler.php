<?php
/**
 * Setup Wizard Handler
 *
 * Handles the setup wizard's non-AJAX form submission ("save provider") and
 * the "skip setup" link on admin_init, before any output is sent, so both can
 * finish with a real server-side redirect (wp_safe_redirect) instead of
 * printing a JavaScript redirect from the page template.
 *
 * @package    Agent_Builder
 * @subpackage Includes
 * @since      4.4.1
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Processes admin.php?page=agentic-setup submissions.
 */
class Setup_Wizard_Handler {

	/**
	 * Handle a setup-wizard POST or skip request. Hooked to admin_init.
	 *
	 * @return void
	 */
	public static function maybe_handle(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Page identification only; each action below verifies its own nonce.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( 'agentic-setup' !== $page || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( isset( $_POST['agentic_setup_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['agentic_setup_nonce'] ) ), 'agentic_setup' ) ) {
			$provider = sanitize_text_field( wp_unslash( $_POST['agent_builder_llm_provider'] ?? '' ) );
			$api_key  = sanitize_text_field( wp_unslash( $_POST['agentic_llm_api_key'] ?? '' ) );

			$allowed_providers = Provider_Registry::get_slugs();
			$no_key_providers  = Provider_Registry::get_no_key_slugs();

			if ( in_array( $provider, $allowed_providers, true ) && ( ! empty( $api_key ) || in_array( $provider, $no_key_providers, true ) ) ) {
				$reg_entry        = Provider_Registry::get( $provider );
				$provider_default = $reg_entry['default_model'] ?? 'gpt-4o';

				Provider_Registry::save_api_key( $provider, $api_key );
				update_option( 'agent_builder_llm_provider', $provider );
				update_option( 'agent_builder_model', $provider_default );
				update_option( 'agent_builder_onboarding_complete', true );

				$audit = new Audit_Log();
				$audit->log(
					'system',
					'settings_changed',
					'setup',
					array(
						'setting' => 'initial_setup',
						'changes' => array( 'provider' => $provider ),
					)
				);

				wp_safe_redirect( admin_url( 'admin.php?page=agent-builder&onboarding=1' ) );
				exit;
			}
		}

		if ( isset( $_GET['skip_setup'] ) && check_admin_referer( 'agentic_skip_setup' ) ) {
			update_option( 'agent_builder_onboarding_complete', true );
			wp_safe_redirect( admin_url( 'admin.php?page=agent-builder' ) );
			exit;
		}
	}
}
