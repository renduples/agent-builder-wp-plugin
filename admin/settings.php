<?php
/**
 * Agentic Settings Page
 *
 * @package    Agent_Builder
 * @subpackage Admin
 * @author     Agent Builder Team <support@agentic-plugin.com>
 * @license    GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 * @link       https://agentic-plugin.com
 * @since      0.1.0
 *
 * php version 8.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Handle form submission.
if ( ! current_user_can( 'agentic_manage_settings' ) && ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'You do not have permission to access this page.', 'agent-builder' ) );
}

// Account tab removed — see Admin_Menu_Handler::maybe_redirect_removed_account_tab().

\Agentic\Plugin::get_instance()->load_chat_components();

// Handle third-party API key save/delete (PRG pattern — redirect after action).
if ( isset( $_POST['agentic_api_action'] ) && check_admin_referer( 'agentic_api_key_nonce' ) ) {
	$agentic_api_action      = sanitize_key( wp_unslash( $_POST['agentic_api_action'] ) );
	$agentic_api_slug        = sanitize_key( wp_unslash( $_POST['agentic_api_slug'] ?? '' ) );
	$agentic_api_audit_acted = false;

	// Map slug → option key (whitelist approach).
	$agentic_api_option_map = array(
		'google_psi' => 'agentic_psi_api_key',
	);

	if ( isset( $agentic_api_option_map[ $agentic_api_slug ] ) ) {
		$agentic_api_option = $agentic_api_option_map[ $agentic_api_slug ];

		if ( 'save' === $agentic_api_action ) {
			$agentic_api_key_val_raw = sanitize_text_field( wp_unslash( $_POST['agentic_api_key_value'] ?? '' ) );
			if ( ! empty( $agentic_api_key_val_raw ) ) {
				update_option( $agentic_api_option, $agentic_api_key_val_raw );
				delete_option( 'agentic_psi_notice_dismissed' );
				$agentic_api_audit_acted = true;
			}
		} elseif ( 'delete' === $agentic_api_action ) {
			delete_option( $agentic_api_option );
			$agentic_api_audit_acted = true;
		}
	}

	if ( $agentic_api_audit_acted ) {
		\Agentic\Security_Log::log_system(
			'settings_changed',
			'api_keys',
			array(
				'setting' => 'api_key_' . $agentic_api_action,
				'changes' => array(
					'api_slug' => $agentic_api_slug,
					'action'   => $agentic_api_action,
				),
			)
		);
	}

	wp_safe_redirect( admin_url( 'admin.php?page=agentic-settings&tab=apis&saved=1' ) );
	exit;
}

// Provider CRUD is handled on admin_init (Admin_Menu_Handler::handle_provider_actions)
// so redirects run before admin-header output (avoids blank page after Set Default).

if ( isset( $_POST['agentic_save_settings'] ) && check_admin_referer( 'agentic_settings_nonce' ) ) {
	$agentic_save_tab      = sanitize_text_field( wp_unslash( $_POST['tab'] ?? 'agents' ) );
	$agentic_reset_section = sanitize_key( wp_unslash( $_POST['agentic_reset_section'] ?? '' ) );

	// ── Reset to Defaults ────────────────────────────────────────────────────
	if ( '' !== $agentic_reset_section ) {
		$agentic_reset_defaults = array(
			'agents_llm'      => array( // Reset global provider to Agentic AI and clear per-agent overrides.
				'handler' => static function () {
					global $wpdb;
					// Reset global provider to Agentic AI (fresh-install default).
					$agentic_prov  = \Agentic\Provider_Registry::get( 'agentic' );
					$default_model = $agentic_prov['default_model'] ?? 'gemini-2.5-flash';
					update_option( 'agentic_llm_provider', 'agentic' );
					update_option( 'agentic_model', $default_model );
					delete_option( 'agentic_vision_model' );
					// Clear per-agent provider/model/mode overrides from agent_settings table.
					$agentic_llm_keys = array( 'override_provider', 'override_model', 'override_vision_model', 'override_mode', 'weak_model_tool_guidance', 'max_tool_retries' );
					foreach ( $agentic_llm_keys as $agentic_ok ) {
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- custom indexed table
						$wpdb->delete( $wpdb->prefix . 'agentic_agent_settings', array( 'meta_key' => $agentic_ok ), array( '%s' ) );
					}
					\Agentic\Agent_Settings::bust_cache();
				},
			),
			'agents_features' => array( // Clear per-agent chat feature overrides.
				'handler' => static function () {
					global $wpdb;
					$agentic_feat_keys = array( 'override_audio', 'override_tts', 'override_vision', 'override_costs', 'override_cache' );
					foreach ( $agentic_feat_keys as $agentic_ok ) {
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- custom indexed table
						$wpdb->delete( $wpdb->prefix . 'agentic_agent_settings', array( 'meta_key' => $agentic_ok ), array( '%s' ) );
					}
					\Agentic\Agent_Settings::bust_cache();
				},
			),
			'styles_chat'     => array( // Global chat settings defaults.
				'handler' => static function () {
					update_option( 'agentic_chat_audio', '1' );
					update_option( 'agentic_chat_tts', '1' );
					update_option( 'agentic_chat_vision', '1' );
					update_option( 'agentic_chat_whitelabel', '1' );
					update_option( 'agentic_show_whatsapp_cta', '0' );
					update_option( 'agentic_response_cache_enabled', true );
					update_option( 'agentic_response_cache_ttl', 3600 );
				},
			),
			'styles_theme'    => array( // Theme default.
				'handler' => static function () {
					update_option( 'agentic_chat_theme', 'light' );
				},
			),
			'security'        => array( // Security tab defaults.
				'handler' => static function () {
					update_option( 'agentic_agent_mode', 'supervised' );
					update_option( 'agentic_security_enabled', true );
					update_option( 'agentic_turnstile_site_key', '' );
					update_option( 'agentic_turnstile_secret_key', '' );
					update_option( 'agentic_turnstile_require_anonymous', true );
					update_option( 'agentic_turnstile_require_all', false );
					update_option( 'agentic_ip_anonymize', true );
					update_option( 'agentic_retention_conversations', 30 );
					update_option( 'agentic_retention_audit_log', 30 );
					update_option( 'agentic_chat_consent_enabled', false );
					update_option( 'agentic_chat_consent_text', 'By chatting you agree to your messages being processed by an AI. We do not share your data with third parties.' );
					update_option( 'agentic_allow_platform_sync', '0' );
				},
			),
			'users'           => array( // Users tab defaults.
				'handler' => static function () {
					update_option( 'agentic_allow_anonymous_chat', false );
					update_option( 'agentic_rate_limit_authenticated', 30 );
					update_option( 'agentic_rate_limit_anonymous', 10 );
					update_option( \Agentic\Usage_Limits::OPTION_KEY, \Agentic\Usage_Limits::get_install_defaults() );
					delete_option( \Agentic\User_Roles::OPTION_KEY ); // Reverts to computed defaults.
				},
			),
			'personas'        => array( // Clear all persona customisations.
				'handler' => static function () {
					global $wpdb;
					$agentic_persona_keys = array( 'persona_welcome_message', 'persona_notes', 'persona_response_style', 'persona_suggested_prompts' );
					foreach ( $agentic_persona_keys as $agentic_pk ) {
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- custom indexed table
						$wpdb->delete( $wpdb->prefix . 'agentic_agent_settings', array( 'meta_key' => $agentic_pk ), array( '%s' ) );
					}
					\Agentic\Agent_Settings::bust_cache();
				},
			),
		);

		if ( isset( $agentic_reset_defaults[ $agentic_reset_section ] ) ) {
			$agentic_reset_defaults[ $agentic_reset_section ]['handler']();
			\Agentic\Security_Log::log_system( 'settings_changed', 'reset_defaults', array( 'section' => $agentic_reset_section ) );
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings reset to defaults.', 'agent-builder' ) . '</p></div>';
		}
	}

	// Only save settings for the active tab to avoid overwriting other tabs with defaults.
	if ( '' === $agentic_reset_section && 'agents' === $agentic_save_tab ) {
		// Save per-agent overrides (provider, model, mode per agent slug).
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each field is sanitized individually in the foreach loop below.
		$agentic_raw_overrides   = isset( $_POST['agentic_agent_overrides'] ) && is_array( $_POST['agentic_agent_overrides'] )
			? wp_unslash( $_POST['agentic_agent_overrides'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			: array();
		$agentic_valid_modes     = array( '', 'disabled', 'supervised', 'autonomous' );
		$agentic_valid_providers = array_merge( array( '' ), \Agentic\Provider_Registry::get_slugs() );
		foreach ( $agentic_raw_overrides as $agentic_slug => $agentic_ov ) {
			$agentic_slug = sanitize_key( $agentic_slug );
			if ( empty( $agentic_slug ) ) {
				continue;
			}
			$agentic_ov_provider     = sanitize_text_field( $agentic_ov['provider'] ?? '' );
			$agentic_ov_model        = sanitize_text_field( $agentic_ov['model'] ?? '' );
			$agentic_ov_vision_model = sanitize_text_field( $agentic_ov['vision_model'] ?? '' );
			$agentic_ov_mode         = sanitize_text_field( $agentic_ov['mode'] ?? '' );
			// Only store if provider and mode are valid values.
			if (
				in_array( $agentic_ov_provider, $agentic_valid_providers, true )
				&& in_array( $agentic_ov_mode, $agentic_valid_modes, true )
			) {
				\Agentic\Agent_Settings::update( $agentic_slug, 'override_provider', $agentic_ov_provider );
				\Agentic\Agent_Settings::update( $agentic_slug, 'override_model', $agentic_ov_model );
				\Agentic\Agent_Settings::update( $agentic_slug, 'override_vision_model', $agentic_ov_vision_model );
				\Agentic\Agent_Settings::update( $agentic_slug, 'override_mode', $agentic_ov_mode );
				\Agentic\Agent_Settings::update( $agentic_slug, 'override_audio', isset( $agentic_ov['audio'] ) ? '1' : '' );
				\Agentic\Agent_Settings::update( $agentic_slug, 'override_tts', isset( $agentic_ov['tts'] ) ? '1' : '' );
				\Agentic\Agent_Settings::update( $agentic_slug, 'override_vision', isset( $agentic_ov['vision'] ) ? '1' : '' );
				\Agentic\Agent_Settings::update( $agentic_slug, 'override_costs', isset( $agentic_ov['costs'] ) ? '1' : '' );
				\Agentic\Agent_Settings::update( $agentic_slug, 'override_cache', isset( $agentic_ov['cache'] ) ? '1' : '' );

				// P0 Item 3: Tool reliability controls for weaker models
				\Agentic\Agent_Settings::update( $agentic_slug, 'weak_model_tool_guidance', isset( $agentic_ov['weak_model_tool_guidance'] ) ? '1' : '' );
				$agentic_ov_retries = isset( $agentic_ov['max_tool_retries'] ) ? sanitize_text_field( $agentic_ov['max_tool_retries'] ) : '';
				\Agentic\Agent_Settings::update( $agentic_slug, 'max_tool_retries', $agentic_ov_retries );
			}
		}
	}

	// Addressing fields are saved with Interface (React) or when classic interface form posts them.

	// Appearance (font / accent) saves via agentic/v1/ui-settings REST (Interface React panel).

	if ( '' === $agentic_reset_section && ( 'global' === $agentic_save_tab || 'cache' === $agentic_save_tab ) ) {
		$agentic_cache_bef = array(
			'response_cache_enabled' => (bool) get_option( 'agentic_response_cache_enabled', true ),
			'response_cache_ttl'     => (int) get_option( 'agentic_response_cache_ttl', 3600 ),
		);

		update_option( 'agentic_response_cache_enabled', isset( $_POST['agentic_response_cache_enabled'] ) );
		update_option( 'agentic_response_cache_ttl', absint( $_POST['agentic_response_cache_ttl'] ?? 3600 ) );

		// Handle cache clear.
		if ( isset( $_POST['agentic_clear_cache'] ) ) {
			$agentic_cleared = \Agentic\Response_Cache::clear_all();
			echo '<div class="notice notice-info"><p>Cleared ' . esc_html( $agentic_cleared ) . ' cached responses.</p></div>';
		}

		$agentic_cache_aft  = array(
			'response_cache_enabled' => (bool) get_option( 'agentic_response_cache_enabled' ),
			'response_cache_ttl'     => (int) get_option( 'agentic_response_cache_ttl' ),
		);
		$agentic_cache_diff = array();
		foreach ( $agentic_cache_aft as $agentic_ck => $agentic_cv ) {
			if ( $agentic_cache_bef[ $agentic_ck ] !== $agentic_cv ) {
				$agentic_cache_diff[ $agentic_ck ] = array(
					'before' => $agentic_cache_bef[ $agentic_ck ],
					'after'  => $agentic_cv,
				);
			}
		}
		if ( ! empty( $agentic_cache_diff ) ) {
			\Agentic\Security_Log::log_system(
				'settings_changed',
				'cache_settings',
				array(
					'setting' => 'cache_tab',
					'changes' => $agentic_cache_diff,
				)
			);
		}
	}

	if ( '' === $agentic_reset_section && 'security' === $agentic_save_tab ) {
		// Capture previous values before saving so we can log what changed.
		$agentic_sec_before = array(
			'agent_mode'                  => (string) get_option( 'agentic_agent_mode', 'supervised' ),
			'security_enabled'            => (bool) get_option( 'agentic_security_enabled', false ),
			'turnstile_require_anonymous' => (bool) get_option( 'agentic_turnstile_require_anonymous', false ),
			'turnstile_require_all'       => (bool) get_option( 'agentic_turnstile_require_all', false ),
			'ip_anonymize'                => (bool) get_option( 'agentic_ip_anonymize', true ),
			'retention_conversations'     => (int) get_option( 'agentic_retention_conversations', 30 ),
			'retention_audit_log'         => (int) get_option( 'agentic_retention_audit_log', 30 ),
			'chat_consent_enabled'        => (bool) get_option( 'agentic_chat_consent_enabled', false ),
			'chat_consent_text'           => (string) get_option( 'agentic_chat_consent_text', '' ),
			'local_memory_enabled'        => (bool) ( '1' === get_option( 'agentic_local_memory_enabled', '0' ) ),
			'allow_platform_sync'         => (string) get_option( 'agentic_allow_platform_sync', '0' ),
		);

		$agentic_valid_modes = array( 'disabled', 'supervised', 'autonomous' );
		$agentic_new_mode    = sanitize_key( wp_unslash( $_POST['agentic_agent_mode'] ?? 'supervised' ) );
		if ( in_array( $agentic_new_mode, $agentic_valid_modes, true ) ) {
			update_option( 'agentic_agent_mode', $agentic_new_mode );
		}
		update_option( 'agentic_security_enabled', isset( $_POST['agentic_security_enabled'] ) );
		update_option( 'agentic_turnstile_site_key', sanitize_text_field( wp_unslash( $_POST['agentic_turnstile_site_key'] ?? '' ) ) );
		update_option( 'agentic_turnstile_secret_key', sanitize_text_field( wp_unslash( $_POST['agentic_turnstile_secret_key'] ?? '' ) ) );
		update_option( 'agentic_turnstile_require_anonymous', isset( $_POST['agentic_turnstile_require_anonymous'] ) );
		update_option( 'agentic_turnstile_require_all', isset( $_POST['agentic_turnstile_require_all'] ) );
		// GDPR settings.
		update_option( 'agentic_ip_anonymize', isset( $_POST['agentic_ip_anonymize'] ) );
		update_option( 'agentic_retention_conversations', absint( $_POST['agentic_retention_conversations'] ?? 0 ) );
		update_option( 'agentic_retention_audit_log', absint( $_POST['agentic_retention_audit_log'] ?? 0 ) );
		update_option( 'agentic_chat_consent_enabled', isset( $_POST['agentic_chat_consent_enabled'] ) );
		update_option( 'agentic_chat_consent_text', sanitize_textarea_field( wp_unslash( $_POST['agentic_chat_consent_text'] ?? '' ) ) );
		update_option( 'agentic_local_memory_enabled', isset( $_POST['agentic_local_memory_enabled'] ) ? '1' : '0' );
		update_option( 'agentic_allow_platform_sync', isset( $_POST['agentic_allow_platform_sync'] ) ? '1' : '0' );

		// Log any changes to the audit log.
		$agentic_sec_after = array(
			'agent_mode'                  => (string) get_option( 'agentic_agent_mode' ),
			'security_enabled'            => (bool) get_option( 'agentic_security_enabled' ),
			'turnstile_require_anonymous' => (bool) get_option( 'agentic_turnstile_require_anonymous' ),
			'turnstile_require_all'       => (bool) get_option( 'agentic_turnstile_require_all' ),
			'ip_anonymize'                => (bool) get_option( 'agentic_ip_anonymize' ),
			'retention_conversations'     => (int) get_option( 'agentic_retention_conversations' ),
			'retention_audit_log'         => (int) get_option( 'agentic_retention_audit_log' ),
			'chat_consent_enabled'        => (bool) get_option( 'agentic_chat_consent_enabled' ),
			'chat_consent_text'           => (string) get_option( 'agentic_chat_consent_text' ),
			'local_memory_enabled'        => (bool) ( '1' === get_option( 'agentic_local_memory_enabled', '0' ) ),
			'allow_platform_sync'         => (string) get_option( 'agentic_allow_platform_sync', '0' ),
		);
		$agentic_sec_diff  = array();
		foreach ( $agentic_sec_after as $agentic_sec_key => $agentic_sec_val ) {
			if ( $agentic_sec_before[ $agentic_sec_key ] !== $agentic_sec_val ) {
				$agentic_sec_diff[ $agentic_sec_key ] = array(
					'before' => $agentic_sec_before[ $agentic_sec_key ],
					'after'  => $agentic_sec_val,
				);
			}
		}
		if ( ! empty( $agentic_sec_diff ) ) {
			\Agentic\Security_Log::log_system(
				'settings_changed',
				'security_settings',
				array(
					'setting' => 'security_tab',
					'changes' => $agentic_sec_diff,
				)
			);
		}
	}

	if ( '' === $agentic_reset_section && 'users' === $agentic_save_tab ) {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised per-field inside save_settings()
		$agentic_roles_raw = isset( $_POST['agentic_user_roles'] ) && is_array( $_POST['agentic_user_roles'] )
			? wp_unslash( $_POST['agentic_user_roles'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			: array();
		\Agentic\User_Roles::save_settings( $agentic_roles_raw );

		// Per-role usage limits.
		$agentic_limits_raw = isset( $_POST['agentic_usage_limits'] ) && is_array( $_POST['agentic_usage_limits'] )
			? wp_unslash( $_POST['agentic_usage_limits'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			: array();
		\Agentic\Usage_Limits::save_limits( $agentic_limits_raw );

		// Anonymous frontend chat access (checkbox lives in the AI Agents merged table).
		$agentic_users_bef = array(
			'allow_anonymous_chat'     => (bool) get_option( 'agentic_allow_anonymous_chat', false ),
			'rate_limit_authenticated' => (int) get_option( 'agentic_rate_limit_authenticated', 30 ),
			'rate_limit_anonymous'     => (int) get_option( 'agentic_rate_limit_anonymous', 10 ),
		);
		update_option( 'agentic_allow_anonymous_chat', isset( $_POST['agentic_allow_anonymous_chat'] ) );

		// Per-minute rate limits (IP-based, applied before role-based daily limits).
		update_option( 'agentic_rate_limit_authenticated', absint( $_POST['agentic_rate_limit_authenticated'] ?? 30 ) );
		update_option( 'agentic_rate_limit_anonymous', absint( $_POST['agentic_rate_limit_anonymous'] ?? 10 ) );

		$agentic_users_aft  = array(
			'allow_anonymous_chat'     => (bool) get_option( 'agentic_allow_anonymous_chat' ),
			'rate_limit_authenticated' => (int) get_option( 'agentic_rate_limit_authenticated' ),
			'rate_limit_anonymous'     => (int) get_option( 'agentic_rate_limit_anonymous' ),
		);
		$agentic_users_diff = array();
		foreach ( $agentic_users_aft as $agentic_uk => $agentic_uv ) {
			if ( $agentic_users_bef[ $agentic_uk ] !== $agentic_uv ) {
				$agentic_users_diff[ $agentic_uk ] = array(
					'before' => $agentic_users_bef[ $agentic_uk ],
					'after'  => $agentic_uv,
				);
			}
		}
		if ( ! empty( $agentic_users_diff ) ) {
			\Agentic\Security_Log::log_system(
				'settings_changed',
				'user_settings',
				array(
					'setting' => 'users_tab',
					'changes' => $agentic_users_diff,
				)
			);
		}
	}

	if ( '' === $agentic_reset_section && in_array( $agentic_save_tab, array( 'personas', 'instructions' ), true ) ) {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised per-field below
		$agentic_personas_raw     = isset( $_POST['agentic_agent_personas'] ) && is_array( $_POST['agentic_agent_personas'] )
			? wp_unslash( $_POST['agentic_agent_personas'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			: array();
		$agentic_personas_changed = false;
		foreach ( $agentic_personas_raw as $agentic_p_slug => $agentic_p_data ) {
			$agentic_p_slug = sanitize_key( $agentic_p_slug );
			if ( empty( $agentic_p_slug ) || ! is_array( $agentic_p_data ) ) {
				continue;
			}

			$agentic_p_welcome = sanitize_textarea_field( $agentic_p_data['welcome_message'] ?? '' );
			$agentic_p_notes   = sanitize_textarea_field( $agentic_p_data['persona_notes'] ?? '' );
			$agentic_p_style   = sanitize_key( $agentic_p_data['response_style'] ?? '' );
			$agentic_p_prompts = array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $agentic_p_data['suggested_prompts'] ?? array() ) ) ) );

			// Detect changes before writing.
			$agentic_p_old_welcome  = \Agentic\Agent_Settings::get( $agentic_p_slug, 'persona_welcome_message' );
			$agentic_p_old_notes    = \Agentic\Agent_Settings::get( $agentic_p_slug, 'persona_notes' );
			$agentic_p_old_style    = \Agentic\Agent_Settings::get( $agentic_p_slug, 'persona_response_style' );
			$agentic_p_prompts_json = (string) wp_json_encode( $agentic_p_prompts );
			$agentic_p_old_prompts  = \Agentic\Agent_Settings::get( $agentic_p_slug, 'persona_suggested_prompts' );

			\Agentic\Agent_Settings::update( $agentic_p_slug, 'persona_welcome_message', $agentic_p_welcome );
			\Agentic\Agent_Settings::update( $agentic_p_slug, 'persona_notes', $agentic_p_notes );
			\Agentic\Agent_Settings::update( $agentic_p_slug, 'persona_response_style', $agentic_p_style );
			\Agentic\Agent_Settings::update( $agentic_p_slug, 'persona_suggested_prompts', $agentic_p_prompts_json );

			if ( $agentic_p_old_welcome !== $agentic_p_welcome
				|| $agentic_p_old_notes !== $agentic_p_notes
				|| $agentic_p_old_style !== $agentic_p_style
				|| $agentic_p_old_prompts !== $agentic_p_prompts_json ) {
				$agentic_personas_changed = true;
			}

			// Save knowledge file and update abilities.json.
			$agentic_p_knowledge = sanitize_textarea_field( $agentic_p_data['knowledge'] ?? '' );
			$agentic_p_kn_dir    = AGENT_BUILDER_KNOWLEDGE_DIR . '/';
			$agentic_p_kn_file   = $agentic_p_kn_dir . $agentic_p_slug . '-knowledge.txt';
			$agentic_p_kn_rel    = $agentic_p_slug . '-knowledge.txt';

			\Agentic\File_Manager::ensure_protected_dir( $agentic_p_kn_dir );

			// Load the agent's abilities.json manifest.
			$agentic_p_manifest_path = \Agentic\Abilities_Manifest::resolve_path( $agentic_p_slug );

			if ( ! empty( trim( $agentic_p_knowledge ) ) ) {
				// Write the knowledge file.
				\Agentic\File_Manager::put_contents( $agentic_p_kn_file, $agentic_p_knowledge );

				// Add to abilities.json if not already present.
				if ( $agentic_p_manifest_path ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
					$agentic_p_manifest = json_decode( file_get_contents( $agentic_p_manifest_path ), true );
					if ( is_array( $agentic_p_manifest ) ) {
						$agentic_p_kn_files = $agentic_p_manifest['knowledge_files'] ?? array();
						if ( ! in_array( $agentic_p_kn_rel, $agentic_p_kn_files, true ) ) {
							$agentic_p_kn_files[]                  = $agentic_p_kn_rel;
							$agentic_p_manifest['knowledge_files'] = array_values( $agentic_p_kn_files );
							\Agentic\File_Manager::put_contents( $agentic_p_manifest_path, wp_json_encode( $agentic_p_manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
							\Agentic\Abilities_Manifest::clear_cache( $agentic_p_slug );
							\Agentic\Abilities_Manifest::save_integrity_hash( $agentic_p_slug );
						}
					}
				}
			} else {
				// Knowledge cleared — remove file and abilities.json entry.
				if ( \Agentic\File_Manager::exists( $agentic_p_kn_file ) ) {
					\Agentic\File_Manager::delete( $agentic_p_kn_file );
				}
				if ( $agentic_p_manifest_path ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
					$agentic_p_manifest = json_decode( file_get_contents( $agentic_p_manifest_path ), true );
					if ( is_array( $agentic_p_manifest ) && ! empty( $agentic_p_manifest['knowledge_files'] ) ) {
						$agentic_p_kn_files = array_filter(
							$agentic_p_manifest['knowledge_files'],
							function ( $f ) use ( $agentic_p_kn_rel ) {
								return $f !== $agentic_p_kn_rel;
							}
						);
						if ( count( $agentic_p_kn_files ) !== count( $agentic_p_manifest['knowledge_files'] ) ) {
							if ( empty( $agentic_p_kn_files ) ) {
								unset( $agentic_p_manifest['knowledge_files'] );
							} else {
								$agentic_p_manifest['knowledge_files'] = array_values( $agentic_p_kn_files );
							}
							\Agentic\File_Manager::put_contents( $agentic_p_manifest_path, wp_json_encode( $agentic_p_manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
							\Agentic\Abilities_Manifest::clear_cache( $agentic_p_slug );
							\Agentic\Abilities_Manifest::save_integrity_hash( $agentic_p_slug );
						}
					}
				}
			}
		}
		if ( $agentic_personas_changed ) {
			\Agentic\Security_Log::log_system(
				'settings_changed',
				'personas_settings',
				array(
					'setting' => 'personas_tab',
					'changes' => array( 'personas_updated' => true ),
				)
			);
		}
	}

	if ( '' === $agentic_reset_section && 'global' === $agentic_save_tab ) {
		$agentic_styles_bef = array(
			'chat_theme'      => (string) get_option( 'agentic_chat_theme', 'light' ),
			'chat_audio'      => (string) get_option( 'agentic_chat_audio', '0' ),
			'chat_tts'        => (string) get_option( 'agentic_chat_tts', '1' ),
			'chat_vision'     => (string) get_option( 'agentic_chat_vision', '0' ),
			'chat_whitelabel' => (string) get_option( 'agentic_chat_whitelabel', '1' ),
		);

		$agentic_valid_themes = array( 'dark', 'light', 'midnight', 'ocean' );
		$agentic_chosen_theme = sanitize_key( wp_unslash( $_POST['agentic_chat_theme'] ?? 'light' ) );
		if ( in_array( $agentic_chosen_theme, $agentic_valid_themes, true ) ) {
			update_option( 'agentic_chat_theme', $agentic_chosen_theme );
		}

		update_option( 'agentic_chat_audio', isset( $_POST['agentic_chat_audio'] ) ? '1' : '0' );
		update_option( 'agentic_chat_tts', isset( $_POST['agentic_chat_tts'] ) ? '1' : '0' );
		update_option( 'agentic_chat_vision', isset( $_POST['agentic_chat_vision'] ) ? '1' : '0' );
		update_option( 'agentic_chat_whitelabel', isset( $_POST['agentic_chat_whitelabel'] ) ? '1' : '0' );
		update_option( 'agentic_show_whatsapp_cta', isset( $_POST['agentic_show_whatsapp_cta'] ) ? '1' : '0' );

		// Item 3: Tool reliability for weaker models (global)
		update_option( 'agentic_enable_weak_model_tool_guidance', isset( $_POST['agentic_enable_weak_model_tool_guidance'] ) ? '1' : '0' );
		update_option( 'agentic_max_tool_retries', max( 1, absint( $_POST['agentic_max_tool_retries'] ?? 3 ) ) );

		$agentic_styles_aft = array(
			'chat_theme'      => (string) get_option( 'agentic_chat_theme', 'light' ),
			'chat_audio'      => (string) get_option( 'agentic_chat_audio', '0' ),
			'chat_tts'        => (string) get_option( 'agentic_chat_tts', '1' ),
			'chat_vision'     => (string) get_option( 'agentic_chat_vision', '0' ),
			'chat_whitelabel' => (string) get_option( 'agentic_chat_whitelabel', '1' ),
		);
		$agentic_styles_diff = array();
		foreach ( $agentic_styles_aft as $agentic_sk => $agentic_sv ) {
			if ( $agentic_styles_bef[ $agentic_sk ] !== $agentic_sv ) {
				$agentic_styles_diff[ $agentic_sk ] = array(
					'before' => $agentic_styles_bef[ $agentic_sk ],
					'after'  => $agentic_sv,
				);
			}
		}
		if ( ! empty( $agentic_styles_diff ) ) {
			\Agentic\Security_Log::log_system(
				'settings_changed',
				'style_settings',
				array(
					'setting' => 'styles_tab',
					'changes' => $agentic_styles_diff,
				)
			);
		}
	}

	// Handle system check completion flag.
	if ( isset( $_POST['agentic_system_check_done'] ) ) {
		update_option( 'agentic_system_check_done', true );
	}

	if ( '' === $agentic_reset_section ) {
		if ( 'agents' === $agentic_save_tab ) {
			$agentic_global_provider      = get_option( 'agentic_llm_provider', 'agentic' );
			$agentic_global_prov_reg      = \Agentic\Provider_Registry::get( $agentic_global_provider );
			$agentic_global_provider_name = $agentic_global_prov_reg['name'] ?? ucfirst( $agentic_global_provider );
			/* translators: %s is the name of the LLM provider set as global default. */
			echo '<div class="notice notice-success"><p>' . sprintf( esc_html__( 'Settings saved with %s as Global provider.', 'agent-builder' ), '<strong>' . esc_html( $agentic_global_provider_name ) . '</strong>' ) . '</p></div>';
		} else {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'agent-builder' ) . '</p></div>';
		}
	}
}

// Get current values.
$agentic_llm_provider_val = get_option( 'agentic_llm_provider', 'agentic' );
$agentic_api_key_val      = \Agentic\Provider_Registry::get( $agentic_llm_provider_val )['api_key'] ?? '';
$agentic_model_val        = get_option( 'agentic_model', 'gpt-4o' );
$agentic_vision_model_val = get_option( 'agentic_vision_model', $agentic_model_val );
$agentic_agent_mode_val   = get_option( 'agentic_agent_mode', 'supervised' );
$agentic_ollama_url_val   = get_option( 'agentic_ollama_url', 'http://localhost:11434' );

// Cache settings.
$agentic_cache_enabled = get_option( 'agentic_response_cache_enabled', true );
$agentic_cache_ttl     = get_option( 'agentic_response_cache_ttl', 3600 );
$agentic_cache_stats   = \Agentic\Response_Cache::get_stats();

// Security settings.
$agentic_security_enabled       = get_option( 'agentic_security_enabled', true );
$agentic_allow_anon_chat        = get_option( 'agentic_allow_anonymous_chat', false );
$agentic_turnstile_site_key     = get_option( 'agentic_turnstile_site_key', '' );
$agentic_turnstile_secret_key   = get_option( 'agentic_turnstile_secret_key', '' );
$agentic_turnstile_require_anon = get_option( 'agentic_turnstile_require_anonymous', true );
$agentic_turnstile_require_all  = get_option( 'agentic_turnstile_require_all', false );
// GDPR settings.
$agentic_ip_anonymize = get_option( 'agentic_ip_anonymize', true );

// Item 3: Tool reliability for weaker models (global defaults)
$agentic_weak_guidance_global    = get_option( 'agentic_enable_weak_model_tool_guidance', '1' );
$agentic_max_retries_global      = get_option( 'agentic_max_tool_retries', '3' );
$agentic_retention_conversations = get_option( 'agentic_retention_conversations', 30 );
$agentic_retention_audit_log     = get_option( 'agentic_retention_audit_log', 30 );
$agentic_chat_consent_enabled    = get_option( 'agentic_chat_consent_enabled', false );
$agentic_chat_consent_text       = get_option( 'agentic_chat_consent_text', 'By chatting you agree to your messages being processed by an AI. We do not share your data with third parties.' );
$agentic_local_memory_enabled    = '1' === get_option( 'agentic_local_memory_enabled', '0' );
$agentic_allow_platform_sync     = '1' === get_option( 'agentic_allow_platform_sync', '0' );
// Rate limit settings (read near Users tab).
$agentic_rate_limit_auth = get_option( 'agentic_rate_limit_authenticated', 30 );
$agentic_rate_limit_anon = get_option( 'agentic_rate_limit_anonymous', 10 );
?>
<div class="wrap agentic-admin agentic-settings-wrap">
	<h1><?php esc_html_e( 'Settings', 'agent-builder' ); ?></h1>

	<?php
	$agentic_active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'interface';
	// Removed tabs → current homes.
	if ( 'general' === $agentic_active_tab ) {
		$agentic_active_tab = 'interface';
	}
	if ( 'global' === $agentic_active_tab ) {
		$agentic_active_tab = 'agents';
	}
	// Instructions + Memory live under Knowledge.
	if ( 'instructions' === $agentic_active_tab ) {
		wp_safe_redirect( admin_url( 'admin.php?page=agentic-train-data&tab=instructions' ) );
		exit;
	}
	if ( 'memory' === $agentic_active_tab ) {
		wp_safe_redirect( admin_url( 'admin.php?page=agentic-train-data&tab=memory' ) );
		exit;
	}
	$agentic_tabs = array(
		'interface' => __( 'Interface', 'agent-builder' ),
		'agents'    => __( 'Agents', 'agent-builder' ),
		'providers' => __( 'Providers', 'agent-builder' ),
		'users'     => __( 'Users', 'agent-builder' ),
		'security'  => __( 'Security', 'agent-builder' ),
		'apis'      => __( 'APIs', 'agent-builder' ),
		'endpoints' => __( 'Endpoints', 'agent-builder' ),
	);

	// Allow add-ons (for example Pro) to add settings tabs without editing free core files.
	$agentic_tabs = apply_filters( 'agentic_settings_tabs', $agentic_tabs );

	// Full routing allowlist — every tab stays reachable by direct URL.
	$agentic_all_tabs = $agentic_tabs;
	if ( ! isset( $agentic_all_tabs[ $agentic_active_tab ] ) ) {
		$agentic_active_tab = 'interface';
	}

	// Group the flat settings tabs into labelled clusters (see UX/IA §5). Tabs are
	// matched by slug; any tab not explicitly placed (for example a future add-on
	// tab) falls back to the Basic cluster so it can never disappear from the UI.
	$agentic_tab_groups = array(
		'basic'    => array(
			'label' => __( 'Basic', 'agent-builder' ),
			// Users sits immediately above Security.
			'slugs' => array( 'interface', 'agents', 'providers', 'license', 'users', 'security', 'health' ),
		),
		'advanced' => array(
			'label' => __( 'Advanced', 'agent-builder' ),
			'slugs' => array( 'apis', 'endpoints' ),
		),
	);

	// Distribute the available tabs into their groups, preserving the order above.
	$agentic_grouped      = array();
	$agentic_placed_slugs = array();
	foreach ( $agentic_tab_groups as $agentic_group_key => $agentic_group ) {
		$agentic_grouped[ $agentic_group_key ] = array();
		foreach ( $agentic_group['slugs'] as $agentic_group_slug ) {
			if ( isset( $agentic_tabs[ $agentic_group_slug ] ) ) {
				$agentic_grouped[ $agentic_group_key ][ $agentic_group_slug ] = $agentic_tabs[ $agentic_group_slug ];
				$agentic_placed_slugs[]                                       = $agentic_group_slug;
			}
		}
	}
	// Any tab not matched above is appended to the Basic cluster.
	foreach ( $agentic_tabs as $agentic_slug => $agentic_label ) {
		if ( ! in_array( $agentic_slug, $agentic_placed_slugs, true ) ) {
			$agentic_grouped['basic'][ $agentic_slug ] = $agentic_label;
		}
	}
	?>

	<div class="agentic-settings-shell">
		<aside class="agentic-settings-sidebar">
			<div class="agentic-settings-search">
				<input type="search" id="agentic-settings-filter" autocomplete="off" placeholder="<?php esc_attr_e( 'Search settings…', 'agent-builder' ); ?>" aria-label="<?php esc_attr_e( 'Search settings', 'agent-builder' ); ?>" />
			</div>
			<nav class="agentic-settings-nav" aria-label="<?php esc_attr_e( 'Settings sections', 'agent-builder' ); ?>">
				<?php foreach ( $agentic_tab_groups as $agentic_group_key => $agentic_group ) : ?>
					<?php
					$agentic_group_tabs = $agentic_grouped[ $agentic_group_key ];
					if ( empty( $agentic_group_tabs ) ) {
						continue;
					}
					?>
					<div class="agentic-settings-nav-group">
						<span class="agentic-settings-nav-group__label"><?php echo esc_html( $agentic_group['label'] ); ?></span>
						<ul class="agentic-settings-nav-list">
							<?php foreach ( $agentic_group_tabs as $agentic_tab_slug => $agentic_tab_label ) : ?>
								<li>
									<a href="?page=agentic-settings&tab=<?php echo esc_attr( $agentic_tab_slug ); ?>" class="agentic-settings-nav-item <?php echo esc_attr( $agentic_tab_slug === $agentic_active_tab ? 'is-active' : '' ); ?>" data-filter-label="<?php echo esc_attr( strtolower( $agentic_tab_label ) ); ?>"<?php echo $agentic_tab_slug === $agentic_active_tab ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $agentic_tab_label ); ?></a>
								</li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endforeach; ?>
				<p class="agentic-settings-nav-empty" hidden><?php esc_html_e( 'No settings match your search.', 'agent-builder' ); ?></p>
			</nav>
		</aside>

		<div class="agentic-settings-body">
		<?php
		$agentic_panel_title = $agentic_all_tabs[ $agentic_active_tab ] ?? '';
		$agentic_is_react    = ( 'interface' === $agentic_active_tab );
		$agentic_panel_class = $agentic_is_react
			? 'agentic-settings-panel agentic-settings-panel--react'
			: 'agentic-settings-panel';
		?>
		<div class="<?php echo esc_attr( $agentic_panel_class ); ?>">
			<?php if ( ! $agentic_is_react && '' !== $agentic_panel_title ) : ?>
			<div class="agentic-settings-panel__header">
				<h2 class="agentic-settings-panel__title"><?php echo esc_html( $agentic_panel_title ); ?></h2>
			</div>
			<?php endif; ?>
			<div class="agentic-settings-panel__body">

	<?php if ( 'providers' === $agentic_active_tab ) : ?>
		<?php include AGENT_BUILDER_DIR . 'admin/providers.php'; ?>
	<?php elseif ( 'interface' === $agentic_active_tab ) : ?>
		<?php require_once __DIR__ . '/settings-interface.php'; ?>
	<?php elseif ( 'apis' === $agentic_active_tab ) : ?>
		<?php include AGENT_BUILDER_DIR . 'admin/apis.php'; ?>
	<?php elseif ( 'endpoints' === $agentic_active_tab ) : ?>
		<?php require_once __DIR__ . '/settings-endpoints.php'; ?>
	<?php elseif ( 'memory' === $agentic_active_tab ) : ?>
		<?php require_once __DIR__ . '/settings-memory.php'; ?>
	<?php else : // all other tabs use the outer settings form. ?>
	<form method="post" action="" class="agentic-settings-form">
		<?php wp_nonce_field( 'agentic_settings_nonce' ); ?>
		<input type="hidden" name="tab" value="<?php echo esc_attr( $agentic_active_tab ); ?>" />
		<input type="hidden" name="agentic_reset_section" id="agentic-reset-section" value="" />

		<?php
		$agentic_settings_form_tabs = array( 'agents', 'security', 'users' );
		$agentic_settings_form_tabs = apply_filters( 'agentic_settings_form_tabs', $agentic_settings_form_tabs );
		if ( in_array( $agentic_active_tab, $agentic_settings_form_tabs, true ) ) {
			$agentic_default_tab_file = __DIR__ . '/settings/tab-' . $agentic_active_tab . '.php';
			if ( file_exists( $agentic_default_tab_file ) ) {
				require $agentic_default_tab_file;
			} else {
				do_action( 'agentic_render_settings_tab', $agentic_active_tab );
			}
		} else {
			do_action( 'agentic_render_settings_tab', $agentic_active_tab );
		}
		?>

		<div class="agentic-settings-panel__footer">
			<button type="submit" name="agentic_save_settings" class="button button-primary agentic-settings-save" value="1">
				<?php esc_html_e( 'Save changes', 'agent-builder' ); ?>
			</button>
		</div>
	</form>
	<?php endif; // end providers vs settings form. ?>

			</div><!-- .agentic-settings-panel__body -->
		</div><!-- .agentic-settings-panel -->

		</div><!-- .agentic-settings-body -->
	</div><!-- .agentic-settings-shell -->

</div>

<script>
document.querySelectorAll('.agentic-reset-defaults').forEach(function(link) {
	link.addEventListener('click', async function(e) {
		e.preventDefault();
		if (!await agenticUI.confirm('Reset these settings to their fresh-install defaults? This cannot be undone.', { danger: true, confirmText: 'Reset' })) return;
		document.getElementById('agentic-reset-section').value = this.getAttribute('data-section');
		this.closest('form').querySelector('[name="agentic_save_settings"]').click();
	});
});
</script>
