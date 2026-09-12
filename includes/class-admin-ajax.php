<?php
/**
 * Admin AJAX handlers
 *
 * Extracted from the main Plugin class to reduce file size.
 * Contains all admin-context wp_ajax_ handlers except
 * Train on Data handlers (moved to RAG_Manager).
 *
 * @package    Agent_Builder
 * @subpackage Includes
 * @since      2.5.0
 */

declare(strict_types=1);

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static AJAX handler methods registered via wp_ajax_ hooks.
 */
class Admin_Ajax {

	/**
	 * Allowed values for the agentic_agent_mode option.
	 *
	 * @var array<string>
	 */
	private const ALLOWED_AGENT_MODES = array( 'supervised', 'autonomous', 'restricted' );

	/**
	 * Register plugin-level settings with the WordPress Settings API.
	 *
	 * Hooked on admin_init alongside save_agent_mode so that all agent-mode
	 * logic — registration, sanitisation, and AJAX save — lives in one place.
	 *
	 * @return void
	 */
	public static function register_settings(): void {
		register_setting(
			'agentic_core_settings',
			'agentic_agent_mode',
			array(
				'type'              => 'string',
				'default'           => 'supervised',
				'sanitize_callback' => array( static::class, 'sanitize_agent_mode' ),
			)
		);
	}

	/**
	 * Sanitize the agent mode setting to an allowed enum value.
	 *
	 * Falls back to 'supervised' if an invalid value is provided.
	 *
	 * @param mixed $value Raw setting value.
	 * @return string Validated agent mode.
	 */
	public static function sanitize_agent_mode( $value ): string {
		$value = sanitize_text_field( (string) $value );

		if ( in_array( $value, self::ALLOWED_AGENT_MODES, true ) ) {
			return $value;
		}

		return 'supervised';
	}

	/**
	 * Persist dismissal of the welcome notice.
	 *
	 * @return void
	 */
	public static function dismiss_welcome(): void {
		check_ajax_referer( 'agentic_dismiss_welcome', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Permission denied.' );
		}

		delete_option( 'agentic_show_welcome_notice' );
		wp_send_json_success();
	}

	/**
	 * Persist dismissal of the upgrade nudge on the dashboard.
	 *
	 * @return void
	 */
	public static function dismiss_pro_nudge(): void {
		check_ajax_referer( 'agentic_dismiss_pro_nudge' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Permission denied.' );
		}

		update_user_meta( get_current_user_id(), 'agentic_pro_nudge_dismissed', '1' );
		wp_send_json_success();
	}

	/**
	 * Handle plugin deactivation modal submission.
	 *
	 * Records data-retention preference and (in add-on builds) forwards cancellation
	 * feedback to the Agentic API. WordPress performs the actual deactivation
	 * after the browser follows the deactivation URL.
	 *
	 * @return void
	 */
	public static function plugin_deactivate(): void {
		check_ajax_referer( 'agentic_plugin_deactivate', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Permission denied.' );
		}

		$delete_data = ! empty( $_POST['delete_data'] ) && '1' === $_POST['delete_data'];

		// Store data-retention preference so uninstall.php can respect it.
		update_option( 'agentic_deactivate_delete_data', $delete_data ? '1' : '0' );

		// Forward cancellation feedback when the user has opted in (disclosed in
		// readme.txt's External Services section — a consent-gated survey, not a
		// license-holder perk).
		$has_consent = get_option( 'agentic_service_consent' );
		if ( $has_consent ) {
			$reason = sanitize_key( wp_unslash( $_POST['reason'] ?? '' ) );
			$detail = sanitize_textarea_field( wp_unslash( $_POST['detail'] ?? '' ) );
			if ( ! empty( $reason ) ) {
				$api_base = Service_Registry::url( 'agentic-api' );
				wp_remote_post(
					$api_base . '/wp-json/agentic-license/v1/cancellation-feedback',
					array(
						'timeout'  => 5,
						'blocking' => false,
						'body'     => array(
							'site_url'       => home_url(),
							'reason'         => $reason,
							'detail'         => mb_substr( $detail, 0, 500 ),
							'plugin_version' => defined( 'AGENT_BUILDER_VERSION' ) ? AGENT_BUILDER_VERSION : '',
						),
					)
				);
			}
		}

		wp_send_json_success();
	}

	/**
	 * Save agent mode via AJAX (instant save from Security tab dropdown).
	 *
	 * @return void
	 */
	public static function save_agent_mode(): void {
		check_ajax_referer( 'agentic_save_agent_mode', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Permission denied.' );
		}
		$mode  = sanitize_key( wp_unslash( $_POST['mode'] ?? '' ) );
		$valid = array( 'disabled', 'supervised', 'autonomous' );
		if ( ! in_array( $mode, $valid, true ) ) {
			wp_send_json_error( 'Invalid mode.' );
		}
		update_option( 'agentic_agent_mode', $mode );
		wp_send_json_success( array( 'mode' => $mode ) );
	}

	/**
	 * Persist dismissal of the PSI key notice.
	 *
	 * @return void
	 */
	public static function dismiss_psi_notice(): void {
		check_ajax_referer( 'agentic_dismiss_psi', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Permission denied.' );
		}

		$level = sanitize_key( $_POST['level'] ?? 'daily' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
		update_option( 'agentic_psi_notice_dismissed', $level );
		wp_send_json_success();
	}

	/**
	 * Persist dismissal of the setup-needed notice.
	 *
	 * @return void
	 */
	public static function dismiss_setup_notice(): void {
		check_ajax_referer( 'agentic_dismiss_setup_notice', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Permission denied.' );
		}

		update_user_meta( get_current_user_id(), 'agentic_setup_notice_dismissed', '1' );
		wp_send_json_success();
	}

	/**
	 * Toggle a tool's enabled/disabled state.
	 *
	 * @return void
	 */
	public static function toggle_tool(): void {
		check_ajax_referer( 'agentic_toggle_tool' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'agent-builder' ) );
		}

		$tool_name = sanitize_text_field( wp_unslash( $_POST['tool'] ?? '' ) );
		$enabled   = (bool) ( isset( $_POST['enabled'] ) ? rest_sanitize_boolean( wp_unslash( $_POST['enabled'] ) ) : true );

		if ( empty( $tool_name ) ) {
			wp_send_json_error( __( 'Missing tool name.', 'agent-builder' ) );
		}

		if ( $enabled && class_exists( Risk_Level::class ) ) {
			$risk = Risk_Level::max(
				Tools_Registry::get_risk_level( $tool_name ),
				Risk_Level::get_tool_default( $tool_name )
			);
			if ( Risk_Level::EXTREME === $risk ) {
				wp_send_json_error( __( 'This tool cannot be enabled', 'agent-builder' ) );
			}
		}

		Tools_Registry::set_enabled( $tool_name, $enabled );

		// Log the change.
		Security_Log::log_system( $enabled ? 'tool_enabled' : 'tool_disabled', $tool_name );

		wp_send_json_success(
			array(
				'tool'    => $tool_name,
				'enabled' => $enabled,
			)
		);
	}

	/**
	 * Enable or disable an inbound third-party WordPress ability.
	 *
	 * Maintains the `agentic_disabled_inbound_abilities` block-list. A disabled
	 * ability is no longer auto-ingested as an agent tool and cannot be executed
	 * through the inbound ability bridge.
	 *
	 * @return void
	 */
	public static function toggle_inbound_ability(): void {
		check_ajax_referer( 'agentic_toggle_inbound_ability' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'agent-builder' ) );
		}

		$ability_name = sanitize_text_field( wp_unslash( $_POST['ability'] ?? '' ) );
		$enabled      = (bool) ( isset( $_POST['enabled'] ) ? rest_sanitize_boolean( wp_unslash( $_POST['enabled'] ) ) : true );

		if ( empty( $ability_name ) ) {
			wp_send_json_error( __( 'Missing ability name.', 'agent-builder' ) );
		}

		// Never allow our own outbound abilities into the inbound block-list —
		// those are governed by the per-tool toggle on the Tools tab.
		if ( str_starts_with( $ability_name, 'agent-builder/' ) ) {
			wp_send_json_error( __( 'This ability is managed on the Tools tab.', 'agent-builder' ) );
		}

		// Only accept names that correspond to a currently registered ability.
		if ( ! WP_Optional_API::has( 'wp_get_ability' ) || ! Abilities_API::get( $ability_name ) ) {
			wp_send_json_error( __( 'Unknown ability.', 'agent-builder' ) );
		}

		$disabled = get_option( 'agentic_disabled_inbound_abilities', array() );
		if ( ! is_array( $disabled ) ) {
			$disabled = array();
		}

		if ( $enabled ) {
			$disabled = array_values( array_diff( $disabled, array( $ability_name ) ) );
		} elseif ( ! in_array( $ability_name, $disabled, true ) ) {
			$disabled[] = $ability_name;
		}

		update_option( 'agentic_disabled_inbound_abilities', array_values( array_unique( $disabled ) ), false );

		Security_Log::log_system( $enabled ? 'inbound_ability_enabled' : 'inbound_ability_disabled', $ability_name );

		wp_send_json_success(
			array(
				'ability' => $ability_name,
				'enabled' => $enabled,
			)
		);
	}

	/**
	 * Save or update a user-defined event trigger.
	 *
	 * @return void
	 */
	public static function save_user_trigger(): void {
		check_ajax_referer( 'agentic_user_triggers' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'agent-builder' ) );
		}

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every field is sanitized (sanitize_text_field/sanitize_textarea_field/absint) inside Agent_Lifecycle::save_user_trigger().
		$result = Agent_Lifecycle::save_user_trigger(
			array(
				'id'         => wp_unslash( $_POST['trigger_id'] ?? '' ),
				'agent_slug' => wp_unslash( $_POST['agent_slug'] ?? '' ),
				'hook'       => wp_unslash( $_POST['hook'] ?? '' ),
				'name'       => wp_unslash( $_POST['name'] ?? '' ),
				'prompt'     => wp_unslash( $_POST['prompt'] ?? '' ),
				'priority'   => wp_unslash( $_POST['priority'] ?? 10 ),
			)
		);
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( empty( $result['ok'] ) ) {
			wp_send_json_error( $result['error'] ?? __( 'Could not save the event trigger.', 'agent-builder' ) );
		}

		wp_send_json_success(
			array(
				'id'   => $result['id'],
				'name' => $result['name'],
			)
		);
	}

	/**
	 * Delete a user-defined event trigger.
	 *
	 * @return void
	 */
	public static function delete_user_trigger(): void {
		check_ajax_referer( 'agentic_user_triggers' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'agent-builder' ) );
		}

		$id     = sanitize_text_field( wp_unslash( $_POST['trigger_id'] ?? '' ) );
		$result = Agent_Lifecycle::delete_user_trigger( $id );

		if ( empty( $result['ok'] ) ) {
			wp_send_json_error( $result['error'] ?? __( 'Could not delete the event trigger.', 'agent-builder' ) );
		}

		wp_send_json_success();
	}

	/**
	 * Save or update a user-defined scheduled task.
	 *
	 * @return void
	 */
	public static function save_user_scheduled_task(): void {
		check_ajax_referer( 'agentic_user_scheduled_tasks' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'agent-builder' ) );
		}

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every field is sanitized (sanitize_key/sanitize_text_field/sanitize_textarea_field) inside Agent_Lifecycle::save_user_scheduled_task().
		$result = Agent_Lifecycle::save_user_scheduled_task(
			array(
				'id'          => wp_unslash( $_POST['task_id'] ?? '' ),
				'agent_slug'  => wp_unslash( $_POST['agent_slug'] ?? '' ),
				'name'        => wp_unslash( $_POST['name'] ?? '' ),
				'prompt'      => wp_unslash( $_POST['prompt'] ?? '' ),
				'description' => wp_unslash( $_POST['description'] ?? '' ),
				'schedule'    => wp_unslash( $_POST['schedule'] ?? 'daily' ),
			)
		);
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( empty( $result['ok'] ) ) {
			wp_send_json_error( $result['error'] ?? __( 'Could not save the scheduled task.', 'agent-builder' ) );
		}

		wp_send_json_success(
			array(
				'id'   => $result['id'],
				'name' => $result['name'],
			)
		);
	}

	/**
	 * Delete a user-defined scheduled task.
	 *
	 * @return void
	 */
	public static function delete_user_scheduled_task(): void {
		check_ajax_referer( 'agentic_user_scheduled_tasks' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'agent-builder' ) );
		}

		$id     = sanitize_key( wp_unslash( $_POST['task_id'] ?? '' ) );
		$result = Agent_Lifecycle::delete_user_scheduled_task( $id );

		if ( empty( $result['ok'] ) ) {
			wp_send_json_error( $result['error'] ?? __( 'Could not delete the scheduled task.', 'agent-builder' ) );
		}

		wp_send_json_success();
	}

	/**
	 * Save WP-CLI command whitelist for the run_wp_cli tool.
	 *
	 * @return void
	 */
	public static function cli_save_whitelist(): void {
		check_ajax_referer( 'agentic_cli_whitelist' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'agent-builder' ) );
		}

		$raw = sanitize_text_field( wp_unslash( $_POST['commands'] ?? '' ) );
		if ( empty( $raw ) ) {
			wp_send_json_error( __( 'No commands provided.', 'agent-builder' ) );
		}

		$commands = json_decode( $raw, true );
		if ( ! is_array( $commands ) ) {
			wp_send_json_error( __( 'Invalid commands format.', 'agent-builder' ) );
		}

		// Sanitize each command entry.
		$allowed_groups = array( 'read', 'write', 'admin' );
		$sanitized      = array();
		foreach ( $commands as $cmd ) {
			if ( ! is_array( $cmd ) ) {
				continue;
			}
			$pattern = sanitize_text_field( $cmd['pattern'] ?? '' );
			if ( empty( $pattern ) ) {
				continue;
			}
			$group = sanitize_text_field( $cmd['group'] ?? 'write' );
			if ( ! in_array( $group, $allowed_groups, true ) ) {
				$group = 'write';
			}
			$sanitized[] = array(
				'pattern' => $pattern,
				'label'   => sanitize_text_field( $cmd['label'] ?? $pattern ),
				'group'   => $group,
				'enabled' => ! empty( $cmd['enabled'] ),
			);
		}

		// Load the existing tool row to preserve all other fields.
		$row = Tools_Registry::get( 'run_wp_cli' );
		if ( ! $row ) {
			wp_send_json_error( __( 'run_wp_cli tool not found. Visit the Tools page first.', 'agent-builder' ) );
		}

		$params                      = is_array( $row['parameters'] ) ? $row['parameters'] : array();
		$params['_allowed_commands'] = $sanitized;

		Tools_Registry::register(
			array_merge(
				$row,
				array( 'parameters' => $params )
			)
		);

		// Dual-write to Deployments table — sync the entire CLI whitelist.
		if ( class_exists( '\Agentic\Deployments' ) ) {
			$cli_existing = array();
			foreach ( Deployments::all( Deployments::TYPE_CLI ) as $cli_row ) {
				$cli_existing[ $cli_row['config']['pattern'] ?? '' ] = $cli_row['id'];
			}

			$cli_seen = array();
			foreach ( $sanitized as $cli_cmd ) {
				$cli_pattern = $cli_cmd['pattern'];
				$cli_seen[]  = $cli_pattern;

				$cli_save = array(
					'type'       => Deployments::TYPE_CLI,
					'agent_slug' => '',
					'label'      => $cli_cmd['label'],
					'enabled'    => $cli_cmd['enabled'] ? 1 : 0,
					'source'     => Deployments::SOURCE_ADMIN,
					'config'     => array(
						'pattern' => $cli_pattern,
						'group'   => $cli_cmd['group'],
					),
				);
				if ( isset( $cli_existing[ $cli_pattern ] ) ) {
					$cli_save['id'] = $cli_existing[ $cli_pattern ];
				}
				Deployments::save( $cli_save );
			}

			// Remove rows whose patterns were deleted from the whitelist.
			foreach ( $cli_existing as $cli_pat => $cli_row_id ) {
				if ( ! in_array( $cli_pat, $cli_seen, true ) ) {
					Deployments::delete( $cli_row_id );
				}
			}
		}

		wp_send_json_success( array( 'count' => count( $sanitized ) ) );
	}

	/**
	 * Save a single per-agent CLI privilege toggle.
	 *
	 * @return void
	 */
	public static function cli_save_privilege(): void {
		check_ajax_referer( 'agentic_cli_privileges' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'agent-builder' ) );
		}

		$slug  = sanitize_text_field( wp_unslash( $_POST['agent_slug'] ?? '' ) );
		$key   = sanitize_text_field( wp_unslash( $_POST['meta_key'] ?? '' ) );
		$value = sanitize_text_field( wp_unslash( $_POST['meta_value'] ?? 'no' ) );

		if ( empty( $slug ) ) {
			wp_send_json_error( __( 'Missing agent slug.', 'agent-builder' ) );
		}

		$allowed_keys = array( 'cli_enabled', 'cli_invocation' );
		if ( ! in_array( $key, $allowed_keys, true ) ) {
			wp_send_json_error( __( 'Invalid privilege key.', 'agent-builder' ) );
		}

		$value = ( 'yes' === $value ) ? 'yes' : 'no';

		Agent_Settings::update( $slug, $key, $value );

		wp_send_json_success(
			array(
				'agent_slug' => $slug,
				'meta_key'   => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Required for response data.
				'meta_value' => $value, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Required for response data.
			)
		);
	}

	/**
	 * One-click agent package update — not available in this build. This plugin
	 * never downloads and installs executable agent code from a remote zip
	 * (WordPress.org Guideline 8); bundled agents update with the plugin itself.
	 *
	 * @return void
	 */
	public static function agent_update(): void {
		$slug = isset( $_POST['slug'] ) ? sanitize_title( wp_unslash( $_POST['slug'] ) ) : '';
		check_ajax_referer( 'agentic_agent_update_' . $slug, '_wpnonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'agent-builder' ) );
		}

		wp_send_json_error( __( 'Agent package updates are not available in this build.', 'agent-builder' ) );
	}

	/**
	 * Per-agent breakdown for the Costs page.
	 *
	 * @return void
	 */
	public static function update_model_pricing(): void {
		check_ajax_referer( 'agentic_update_model_pricing', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Permission denied.', 403 );
		}

		$api_url  = Service_Registry::url( 'agentic-api', '/wp-json/agentic/v1/model-pricing' );
		$response = wp_remote_get( $api_url, array( 'timeout' => 15 ) );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			wp_send_json_error( sprintf( 'Marketplace returned HTTP %d.', $code ) );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ( empty( $body['providers'] ) || ! is_array( $body['providers'] ) ) &&
			( empty( $body['operations'] ) || ! is_array( $body['operations'] ) ) ) {
			wp_send_json_error( 'Invalid response from marketplace.' );
		}

		$updated = 0;

		// Update third-party LLM provider pricing (category='llm' rows, USD per 1M tokens).
		foreach ( (array) ( $body['providers'] ?? array() ) as $provider_slug => $models ) {
			$provider_slug = sanitize_key( $provider_slug );
			$provider      = \Agentic\Provider_Registry::get( $provider_slug );
			if ( ! $provider ) {
				continue;
			}
			// Merge remote pricing with existing — remote wins for matching models, existing kept for unlisted.
			$merged = $provider['model_pricing'] ?? array();
			foreach ( $models as $model_name => $rates ) {
				$model_name            = sanitize_text_field( $model_name );
				$merged[ $model_name ] = array(
					'in'  => max( 0.0, (float) ( $rates['in'] ?? 0 ) ),
					'out' => max( 0.0, (float) ( $rates['out'] ?? 0 ) ),
				);
			}
			\Agentic\Provider_Registry::save_model_pricing( $provider_slug, $merged );
			++$updated;
		}

		// Derive Agentic AI pricing from operations.chat (category='operation' rows).
		// Rates are stored as credits/token; convert to USD/1M for display (1 credit = $0.01).
		if ( ! empty( $body['operations']['chat'] ) && is_array( $body['operations']['chat'] ) ) {
			$agentic_pricing = array();
			foreach ( $body['operations']['chat'] as $key => $rate ) {
				$key = sanitize_text_field( $key );
				if ( str_ends_with( $key, '_in' ) ) {
					$model                              = substr( $key, 0, -3 );
					$agentic_pricing[ $model ]['in']    = round( (float) $rate * 1_000_000 * 0.01, 4 );
					$agentic_pricing[ $model ]['out'] ??= 0.0;
				} elseif ( str_ends_with( $key, '_out' ) ) {
					$model                             = substr( $key, 0, -4 );
					$agentic_pricing[ $model ]['out']  = round( (float) $rate * 1_000_000 * 0.01, 4 );
					$agentic_pricing[ $model ]['in'] ??= 0.0;
				}
			}
			if ( ! empty( $agentic_pricing ) ) {
				\Agentic\Provider_Registry::save_model_pricing( 'agentic', $agentic_pricing );
				++$updated;
			}
		}

		$version = sanitize_text_field( $body['version'] ?? gmdate( 'Y-m-d' ) );
		update_option( 'agentic_pricing_version', $version, false );
		wp_cache_delete( 'model_pricing', 'agentic_marketplace_api' );

		wp_send_json_success(
			array(
				'message' => sprintf( 'Pricing updated for %d providers (version: %s).', $updated, $version ),
				'version' => $version,
				'updated' => $updated,
			)
		);
	}

	/**
	 * AJAX: per-agent cost breakdown.
	 *
	 * @return void
	 */
	public static function agent_breakdown(): void {
		check_ajax_referer( 'agentic_agent_breakdown', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Permission denied.', 403 );
		}

		if ( ! class_exists( '\Agentic\Costs_Manager' ) ) {
			wp_send_json_error( 'Requires Agent Builder Pro.', 403 );
		}

		$days = max( 1, min( 365, absint( wp_unslash( $_POST['days'] ?? 30 ) ) ) );
		$rows = Costs_Manager::per_agent_totals( $days );

		$data = array();
		foreach ( $rows as $row ) {
			$data[] = array(
				'label'   => Audit_Log::human_agent( $row['agent_id'] ),
				'actions' => (int) $row['actions'],
				'tokens'  => (int) $row['tokens'],
				'cost'    => round( (float) $row['cost'], 6 ),
			);
		}

		wp_send_json_success( $data );
	}

	/**
	 * Test AI provider connection during onboarding.
	 *
	 * @return void
	 */
	public static function test_connection(): void {
		check_ajax_referer( 'agentic_test_connection', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'agent-builder' ) ) );
		}

		$provider = sanitize_text_field( wp_unslash( $_POST['provider'] ?? '' ) );
		$api_key  = sanitize_text_field( wp_unslash( $_POST['api_key'] ?? '' ) );

		$allowed = array( 'xai', 'openai', 'google', 'anthropic', 'mistral', 'ollama', 'llama', 'cohere', 'kimi', 'deepseek', 'agentic' );
		if ( ! in_array( $provider, $allowed, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid provider.', 'agent-builder' ) ) );
		}

		// Build a minimal test request for each provider.
		$endpoints = array(
			'openai'    => array(
				'url'     => 'https://api.openai.com/v1/chat/completions',
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model'      => 'gpt-4o-mini',
						'messages'   => array(
							array(
								'role'    => 'user',
								'content' => 'Reply with: ready',
							),
						),
						'max_tokens' => 5,
					)
				),
			),
			'anthropic' => array(
				'url'     => 'https://api.anthropic.com/v1/messages',
				'headers' => array(
					'x-api-key'         => $api_key,
					'anthropic-version' => '2023-06-01',
					'content-type'      => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model'      => 'claude-3-haiku-20240307',
						'max_tokens' => 5,
						'messages'   => array(
							array(
								'role'    => 'user',
								'content' => 'Reply with: ready',
							),
						),
					)
				),
			),
			'xai'       => array(
				'url'     => 'https://api.x.ai/v1/chat/completions',
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model'      => 'grok-3',
						'messages'   => array(
							array(
								'role'    => 'user',
								'content' => 'Reply with: ready',
							),
						),
						'max_tokens' => 5,
					)
				),
			),
			'google'    => array(
				'url'     => 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash-exp:generateContent?key=' . rawurlencode( $api_key ),
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( array( 'contents' => array( array( 'parts' => array( array( 'text' => 'Reply with: ready' ) ) ) ) ) ),
			),
			'kimi'      => array(
				'url'     => 'https://api.moonshot.ai/v1/chat/completions',
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model'      => 'kimi-k2.5',
						'messages'   => array(
							array(
								'role'    => 'user',
								'content' => 'Reply with: ready',
							),
						),
						'max_tokens' => 5,
					)
				),
			),
			'deepseek'  => array(
				'url'     => 'https://api.deepseek.com/chat/completions',
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model'      => 'deepseek-v4-flash',
						'messages'   => array(
							array(
								'role'    => 'user',
								'content' => 'Reply with: ready',
							),
						),
						'max_tokens' => 5,
					)
				),
			),
			'mistral'   => array(
				'url'     => 'https://api.mistral.ai/v1/chat/completions',
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model'      => 'mistral-small-latest',
						'messages'   => array(
							array(
								'role'    => 'user',
								'content' => 'Reply with: ready',
							),
						),
						'max_tokens' => 5,
					)
				),
			),
			'llama'     => array(
				'url'     => 'https://api.llama.com/v1/chat/completions',
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model'      => 'Llama-3.3-70B-Instruct',
						'messages'   => array(
							array(
								'role'    => 'user',
								'content' => 'Reply with: ready',
							),
						),
						'max_tokens' => 5,
					)
				),
			),
			'cohere'    => array(
				'url'     => 'https://api.cohere.com/v2/chat',
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model'      => 'command-r-plus-08-2024',
						'messages'   => array(
							array(
								'role'    => 'user',
								'content' => 'Reply with: ready',
							),
						),
						'max_tokens' => 5,
					)
				),
			),
			'ollama'    => array(
				'url'     => rtrim( $api_key, '/' ) . '/api/tags',
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => null,
			),
			'agentic'   => array(
				'url'     => Service_Registry::url( 'agentic-chat', '/health' ),
				'headers' => array(
					'Content-Type'     => 'application/json',
					'X-Plugin-Version' => AGENT_BUILDER_VERSION,
				),
				'body'    => null,
			),
		);

		$cfg      = $endpoints[ $provider ];
		$response = wp_remote_post(
			$cfg['url'],
			array(
				'headers' => $cfg['headers'],
				'body'    => $cfg['body'],
				'timeout' => 15,
				'method'  => in_array( $provider, array( 'ollama', 'agentic' ), true ) ? 'GET' : 'POST',
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => $response->get_error_message() ) );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			wp_send_json_success( array( 'message' => __( 'Connected successfully!', 'agent-builder' ) ) );
		} else {
			$body = wp_remote_retrieve_body( $response );
			$data = json_decode( $body, true );
			/* translators: %d: HTTP status code */
			$msg = $data['error']['message'] ?? $data['error'] ?? sprintf( __( 'Connection failed (HTTP %d). Check your API key.', 'agent-builder' ), $code );
			wp_send_json_error( array( 'message' => $msg ) );
		}
	}

	/**
	 * Save provider key during setup wizard.
	 *
	 * @return void
	 */
	public static function wizard_save_key(): void {
		check_ajax_referer( 'agentic_test_connection', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'agent-builder' ) ) );
		}

		$provider = sanitize_text_field( wp_unslash( $_POST['provider'] ?? '' ) );
		$api_key  = sanitize_text_field( wp_unslash( $_POST['api_key'] ?? '' ) );

		if ( ! in_array( $provider, Provider_Registry::get_slugs(), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid provider.', 'agent-builder' ) ) );
		}

		$provider_record = Provider_Registry::get( $provider );
		if ( empty( $api_key ) && ( $provider_record['requires_key'] ?? true ) ) {
			wp_send_json_error( array( 'message' => __( 'API key is required.', 'agent-builder' ) ) );
		}

		$default_model = $provider_record['default_model'] ?? '';

		Provider_Registry::save_api_key( $provider, $api_key );
		update_option( 'agentic_llm_provider', $provider );
		update_option( 'agentic_model', $default_model );
		update_option( 'agentic_onboarding_complete', true );

		// Activate the WordPress Assistant so it's available immediately in the wizard chat.
		$active_agents = get_option( 'agentic_active_agents', array() );
		if ( ! in_array( 'wordpress-assistant', $active_agents, true ) ) {
			$active_agents[] = 'wordpress-assistant';
			update_option( 'agentic_active_agents', array_unique( $active_agents ) );
		}

		wp_send_json_success( array( 'message' => __( 'Settings saved.', 'agent-builder' ) ) );
	}

	/**
	 * Save model and agent mode preference from setup wizard.
	 *
	 * @return void
	 */
	public static function wizard_save_preferences(): void {
		check_ajax_referer( 'agentic_test_connection', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'agent-builder' ) ) );
		}

		$model         = sanitize_text_field( wp_unslash( $_POST['model'] ?? '' ) );
		$mode          = sanitize_text_field( wp_unslash( $_POST['mode'] ?? 'supervised' ) );
		$allowed_modes = array( 'supervised', 'autonomous', 'disabled' );
		if ( ! in_array( $mode, $allowed_modes, true ) ) {
			$mode = 'supervised';
		}

		if ( $model ) {
			update_option( 'agentic_model', $model );
		}
		update_option( 'agentic_agent_mode', $mode );

		wp_send_json_success();
	}

	/**
	 * Remove a provider's stored API key and model preference.
	 *
	 * @return void
	 */
	public static function remove_provider(): void {
		check_ajax_referer( 'agentic_settings_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'agent-builder' ) ) );
		}

		$provider = sanitize_text_field( wp_unslash( $_POST['provider'] ?? '' ) );
		$allowed  = array( 'xai', 'openai', 'google', 'anthropic', 'mistral', 'ollama', 'llama', 'cohere', 'kimi', 'deepseek', 'agentic' );
		if ( ! in_array( $provider, $allowed, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid provider.', 'agent-builder' ) ) );
		}

		// Remove the API key.
		Provider_Registry::save_api_key( $provider, '' );

		// Remove model preference.
		$all_models = get_option( 'agentic_model_preferences', array() );
		unset( $all_models[ $provider ] );
		update_option( 'agentic_model_preferences', $all_models );

		// Clear Ollama URL if removing Ollama.
		if ( 'ollama' === $provider ) {
			delete_option( 'agentic_ollama_url' );
		}

		// Clean up per-agent overrides that reference this provider.
		$agentic_provider_map = Agent_Settings::get_all_agents_key( 'override_provider' );
		foreach ( $agentic_provider_map as $agentic_ov_slug => $agentic_ov_prov ) {
			if ( $agentic_ov_prov === $provider ) {
				Agent_Settings::update( $agentic_ov_slug, 'override_provider', '' );
				Agent_Settings::update( $agentic_ov_slug, 'override_model', '' );
			}
		}

		// If the removed provider is the active one, switch to the first remaining.
		$current_provider = get_option( 'agentic_llm_provider', 'agentic' );
		if ( $current_provider === $provider ) {
			$agentic_active = Provider_Registry::get_active();
			$next_provider  = ! empty( $agentic_active ) ? $agentic_active[0]['slug'] : 'agentic';

			// Read the default model from the provider table (single source of truth).
			$next_record = Provider_Registry::get( $next_provider );

			update_option( 'agentic_llm_provider', $next_provider );
			update_option( 'agentic_model', $all_models[ $next_provider ] ?? $next_record['default_model'] ?? '' );
		}

		wp_send_json_success( array( 'message' => __( 'Provider removed.', 'agent-builder' ) ) );
	}

	/**
	 * Fetch the live model list for a provider from its own API.
	 *
	 * @return void
	 */
	public static function fetch_provider_models(): void {
		check_ajax_referer( 'agentic_fetch_provider_models', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'agent-builder' ) ) );
		}

		$slug  = sanitize_key( wp_unslash( $_POST['provider'] ?? '' ) );
		$force = ! empty( $_POST['force'] );

		if ( ! Provider_Registry::is_valid( $slug ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid provider.', 'agent-builder' ) ) );
		}

		$cache_key = 'agentic_models_' . $slug;
		if ( ! $force ) {
			// Layer 1: short-term transient (1 hour).
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) && ! empty( $cached ) ) {
				wp_send_json_success(
					array(
						'models' => $cached,
						'cached' => true,
					)
				);
			}

			// Layer 2: registry models field (persistent — survives transient expiry).
			$reg_models = Provider_Registry::get( $slug )['models'] ?? array();
			if ( ! empty( $reg_models ) ) {
				set_transient( $cache_key, $reg_models, HOUR_IN_SECONDS );
				wp_send_json_success(
					array(
						'models' => $reg_models,
						'cached' => true,
					)
				);
			}
		}

		// Shared with the daily agentic_refresh_provider_models cron (see
		// Provider_Registry::fetch_live_models_from_api()) so there is exactly
		// one place that knows how to list models for each provider's API.
		// $explicit=true: this is an admin clicking "Refresh," not the cron,
		// so it bypasses the Settings > Security catalog-sync opt-in gate —
		// same exemption "Get Latest Pricing" already has.
		$models = Provider_Registry::fetch_live_models_from_api( $slug, true );

		if ( empty( $models ) ) {
			wp_send_json_error( array( 'message' => __( 'No models returned. Ensure your API key is saved and valid.', 'agent-builder' ) ) );
		}

		// Persist to provider table (single source of truth) and transient cache.
		$provider_data = Provider_Registry::get( $slug );
		if ( $provider_data ) {
			Provider_Registry::upsert(
				array(
					'slug'   => $slug,
					'models' => $models,
				)
			);
		}
		set_transient( $cache_key, $models, HOUR_IN_SECONDS );
		wp_send_json_success(
			array(
				'models' => $models,
				'cached' => false,
			)
		);
	}

	/**
	 * AJAX handler: register for an Agentic AI API key.
	 *
	 * Called from the sign-up page. POSTs to Agentic AI services,
	 * stores the returned API key, and sets the default provider.
	 *
	 * @return void
	 */
	public static function signup_api_key(): void {
		check_ajax_referer( 'agentic_signup' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'agent-builder' ) ) );
		}

		$email          = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		$site_name      = sanitize_text_field( wp_unslash( $_POST['site_name'] ?? '' ) );
		$site_url       = esc_url_raw( wp_unslash( $_POST['site_url'] ?? home_url() ) );
		$plugin_version = sanitize_text_field( wp_unslash( $_POST['plugin_version'] ?? '' ) );
		$agent_mode     = sanitize_key( wp_unslash( $_POST['agent_mode'] ?? 'supervised' ) );
		$ui_mode        = sanitize_key( wp_unslash( $_POST['ui_mode'] ?? '' ) );
		$chat_model     = sanitize_text_field( wp_unslash( $_POST['chat_model'] ?? 'gemini-2.5-flash' ) );
		$vision_model   = sanitize_text_field( wp_unslash( $_POST['vision_model'] ?? '' ) );
		$tts_voice      = sanitize_text_field( wp_unslash( $_POST['tts_voice'] ?? 'journey-f' ) );
		$video_model    = sanitize_text_field( wp_unslash( $_POST['video_model'] ?? 'veo-2' ) );

		if ( ! in_array( $agent_mode, array( 'disabled', 'supervised', 'autonomous' ), true ) ) {
			$agent_mode = 'supervised';
		}
		if ( '' !== $ui_mode && ! in_array( $ui_mode, array( 'basic', 'advanced' ), true ) ) {
			$ui_mode = 'basic';
		}
		if ( ! in_array( $video_model, array( 'veo-2', 'veo-3' ), true ) ) {
			$video_model = 'veo-2';
		}

		if ( empty( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'Email address is required.', 'agent-builder' ) ) );
		}

		if ( empty( $site_url ) ) {
			$site_url = home_url();
		}

		$response = wp_remote_post(
			Service_Registry::url( 'agentic-api', '/wp-json/agentic/v1/register' ),
			array(
				'timeout' => 15,
				'body'    => array(
					'email'          => $email,
					'site_url'       => $site_url,
					'site_name'      => $site_name,
					'plugin_version' => $plugin_version,
					'plugin_tier'    => 'free',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => $response->get_error_message() ) );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		// Accept either 'api_key' or 'license_key' from the server response.
		$api_key = $body['api_key'] ?? $body['license_key'] ?? '';

		if ( empty( $api_key ) ) {
			$server_msg = $body['message'] ?? __( 'No API key returned. Please try again.', 'agent-builder' );
			wp_send_json_error( array( 'message' => $server_msg ) );
		}

		// Store the API key in the providers table. save_api_key() returns false
		// when the 'agentic' provider row is missing or unwritable (e.g. a providers
		// table that never fully seeded). Reporting success in that case strands the
		// user: no LLM is configured, so the chat page is never registered and the
		// post-signup redirect lands on WordPress's generic "not allowed" page.
		if ( ! Provider_Registry::save_api_key( 'agentic', $api_key ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Your API key was issued but could not be saved on this site. Please deactivate and reactivate Agent Builder to repair its database tables, then try again.', 'agent-builder' ),
				)
			);
		}

		// Set Agentic AI as the default provider with user-chosen configuration.
		update_option( 'agentic_llm_provider', 'agentic' );
		update_option( 'agentic_model', $chat_model );
		update_option( 'agentic_vision_model', $vision_model ? $vision_model : $chat_model );
		update_option( 'agentic_tts_voice', $tts_voice );
		update_option( 'agentic_agent_mode', $agent_mode );
		update_option( 'agentic_video_model', $video_model );
		if ( '' !== $ui_mode ) {
			Admin_Settings_REST::set_ui_mode( $ui_mode, 'signup' );
		}
		update_option( 'agentic_onboarding_complete', true );
		update_option( 'agentic_service_consent', true );

		wp_send_json_success(
			array(
				'message' => __( 'API key saved.', 'agent-builder' ),
				'api_key' => $api_key,
			)
		);
	}

	/**
	 * AJAX handler: execute a scheduled task and return JSON results.
	 *
	 * @return void
	 */
	public static function run_task(): void {
		check_ajax_referer( 'agentic_run_task' );

		if ( ! User_Roles::current_user_can( 'run_tasks_manually' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'agent-builder' ) ) );
		}

		$agent_id = sanitize_text_field( wp_unslash( $_POST['agent'] ?? '' ) );
		$task_id  = sanitize_text_field( wp_unslash( $_POST['task'] ?? '' ) );

		if ( empty( $agent_id ) || empty( $task_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Missing agent or task parameter.', 'agent-builder' ) ) );
		}

		$resolved = Agent_Lifecycle::resolve_task( $agent_id, $task_id );
		if ( ! $resolved ) {
			wp_send_json_error( array( 'message' => __( 'Task not found on this agent.', 'agent-builder' ) ) );
		}

		$instance = $resolved['agent'];
		$task_def = $resolved['task'];

		$start_time = microtime( true );
		$error_msg  = null;

		try {
			Agent_Lifecycle::execute_scheduled_task( $instance, $task_def );
		} catch ( \Throwable $e ) {
			$error_msg = $e->getMessage();
		}

		$duration = round( microtime( true ) - $start_time, 2 );

		// Fetch result from audit log.
		global $wpdb;
		$audit_table = $wpdb->prefix . 'agentic_audit_log';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time admin read.
		$result_json = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT details FROM %i WHERE agent_id = %s AND action = %s AND target_type = %s ORDER BY id DESC LIMIT 1',
				$audit_table,
				$agent_id,
				'scheduled_task_complete',
				$task_id
			)
		);

		$details = $result_json ? json_decode( $result_json, true ) : null;

		// Extract LLM response text.
		$response_text = '';
		if ( is_array( $details ) && ! empty( $details['result'] ) && is_string( $details['result'] ) ) {
			$decoded = json_decode( $details['result'], true );
			if ( is_array( $decoded ) && ! empty( $decoded['response'] ) ) {
				$response_text = $decoded['response'];
			} elseif ( str_starts_with( $details['result'], '{"response":"' ) ) {
				// Handle truncated JSON from older audit entries.
				$inner         = substr( $details['result'], 14 );
				$inner         = rtrim( $inner, '"}' );
				$response_text = str_replace(
					array( '\\n', '\\t', '\\/', '\\"', '\\\\' ),
					array( "\n", "\t", '/', '"', '\\' ),
					$inner
				);
			} else {
				$response_text = $details['result'];
			}
		}

		if ( $error_msg ) {
			wp_send_json_error(
				array(
					'message'  => $error_msg,
					'duration' => $duration,
				)
			);
		}

		wp_send_json_success(
			array(
				'duration'   => $duration,
				'duration_s' => $details['duration_s'] ?? null,
				'response'   => $response_text,
			)
		);
	}
}
