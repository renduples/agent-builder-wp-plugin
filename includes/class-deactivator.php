<?php
/**
 * Plugin deactivation handler.
 *
 * Everything that runs on register_deactivation_hook.
 * Uninstall cleanup lives in uninstall.php (WordPress convention).
 *
 * @package Agent_Builder
 * @since   2.3.0
 */

declare(strict_types=1);

namespace Agentic;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deactivator
 *
 * @since 2.3.0
 */
final class Deactivator {

	/**
	 * Run all deactivation tasks.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		// Clear all plugin-level cron events. Agent-specific cron hooks are
		// handled by Agent_Lifecycle::on_agent_deactivated when agents are toggled.
		wp_clear_scheduled_hook( 'agent_builder_cleanup_audit_log' );
		wp_clear_scheduled_hook( 'agent_builder_costs_check_alerts' );
		wp_clear_scheduled_hook( 'agent_builder_gdpr_cleanup' );
		wp_clear_scheduled_hook( 'agent_builder_cleanup_jobs' );
		wp_clear_scheduled_hook( 'agent_builder_process_job' );
		wp_clear_scheduled_hook( 'agent_builder_refresh_provider_models' );
		flush_rewrite_rules();

		// Reset the agent-updates opt-in so the consent prompt reappears on next activation.
		delete_option( 'agent_builder_agent_updates_optin' );

		// Reset onboarding so the signup prompt reappears on next activation.
		delete_option( 'agent_builder_onboarding_complete' );

		// Log the deactivation while the table still exists (uninstall.php drops it).
		\Agentic\Security_Log::log_system(
			'plugin_deactivated',
			'agent-builder',
			array(
				'version'    => AGENT_BUILDER_VERSION,
				'wp_version' => get_bloginfo( 'version' ),
			)
		);
	}
}
