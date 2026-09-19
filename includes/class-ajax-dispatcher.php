<?php
/**
 * Ajax Dispatcher
 *
 * Owns the complete wp_ajax_* dispatch table for the plugin.
 * Only registers hooks when WordPress is actually processing an
 * admin-ajax.php request, keeping every other request type clean.
 *
 * @package    Agent_Builder
 * @subpackage Includes
 * @since      2.9.115
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers all wp_ajax_* handlers for the plugin.
 *
 * Add new AJAX actions to the $handlers table in register() — nowhere else.
 */
class Ajax_Dispatcher {

	/**
	 * Register AJAX handlers for the current request.
	 *
	 * Reads $_REQUEST['action'] once to identify the incoming action, then
	 * registers exactly one wp_ajax_ hook — the one that will fire.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! wp_doing_ajax() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- action used only for routing, not data access.
		$ajax_action = isset( $_REQUEST['action'] ) ? sanitize_key( $_REQUEST['action'] ) : '';

		$handlers = array(
			// Dashboard notices.
			'agentic_dismiss_setup_notice'          => array( Admin_Ajax::class, 'dismiss_setup_notice' ),
			'agentic_dismiss_pro_nudge'             => array( Admin_Ajax::class, 'dismiss_pro_nudge' ),
			'agentic_dismiss_shadowed_agent_notice' => array( Admin_Ajax::class, 'dismiss_shadowed_agent_notice' ),
			'agentic_dismiss_pending_approval_notice' => array( Admin_Ajax::class, 'dismiss_pending_approval_notice' ),

			// Plugin deactivation modal (plugins.php).
			'agentic_plugin_deactivate'          => array( Admin_Ajax::class, 'plugin_deactivate' ),

			// Setup wizard (page=agentic-setup, page=agentic-signup).
			'agentic_signup_api_key'             => array( Admin_Ajax::class, 'signup_api_key' ),
			'agentic_wizard_save_key'            => array( Admin_Ajax::class, 'wizard_save_key' ),
			'agentic_wizard_save_preferences'    => array( Admin_Ajax::class, 'wizard_save_preferences' ),

			// Settings (page=agentic-settings).
			'agentic_save_agent_mode'            => array( Admin_Ajax::class, 'save_agent_mode' ),
			'agentic_remove_provider'            => array( Admin_Ajax::class, 'remove_provider' ),
			'agentic_fetch_provider_models'      => array( Admin_Ajax::class, 'fetch_provider_models' ),
			'agentic_test_connection'            => array( Admin_Ajax::class, 'test_connection' ),

			// Tools (page=agentic-tools).
			'agentic_toggle_tool'                => array( Admin_Ajax::class, 'toggle_tool' ),
			'agentic_toggle_inbound_ability'     => array( Admin_Ajax::class, 'toggle_inbound_ability' ),

			// Deployment (page=agentic-deployment).
			'agentic_save_user_trigger'          => array( Admin_Ajax::class, 'save_user_trigger' ),
			'agentic_delete_user_trigger'        => array( Admin_Ajax::class, 'delete_user_trigger' ),
			'agentic_save_user_scheduled_task'   => array( Admin_Ajax::class, 'save_user_scheduled_task' ),
			'agentic_delete_user_scheduled_task' => array( Admin_Ajax::class, 'delete_user_scheduled_task' ),
			'agentic_cli_save_whitelist'         => array( Admin_Ajax::class, 'cli_save_whitelist' ),
			'agentic_cli_save_privilege'         => array( Admin_Ajax::class, 'cli_save_privilege' ),

			// Run Task (page=agentic-run-task).
			'agentic_run_task'                   => array( Admin_Ajax::class, 'run_task' ),

			// Agents / updates (page=agentic-agents).
			'agentic_agent_update'               => array( Admin_Ajax::class, 'agent_update' ),

			// Costs (page=agentic-costs).
			'agentic_agent_breakdown'            => array( Admin_Ajax::class, 'agent_breakdown' ),
			'agentic_update_model_pricing'       => array( Admin_Ajax::class, 'update_model_pricing' ),

			// Vector Store AJAX lives in Agent Builder Pro (RAG_Manager +
			// Vector_Store::register_ajax_handlers). Free does not register them.

			// Free Knowledge Wiki (OKF).
			'agentic_okf_list'                   => array( Okf_Admin::class, 'ajax_list' ),
			'agentic_okf_get'                    => array( Okf_Admin::class, 'ajax_get' ),
			'agentic_okf_save'                   => array( Okf_Admin::class, 'ajax_save' ),
			'agentic_okf_delete'                 => array( Okf_Admin::class, 'ajax_delete' ),
			'agentic_okf_search'                 => array( Okf_Admin::class, 'ajax_search' ),
			'agentic_okf_import_persona'         => array( Okf_Admin::class, 'ajax_import_persona' ),
			'agentic_okf_export'                 => array( Okf_Admin::class, 'ajax_export' ),
		);

		if ( isset( $handlers[ $ajax_action ] ) ) {
			add_action( 'wp_ajax_' . $ajax_action, $handlers[ $ajax_action ] );
		}
	}
}
