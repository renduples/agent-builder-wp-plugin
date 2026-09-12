<?php
/**
 * UI Settings REST API
 *
 * Backs the React "Interface" settings panel. Exposes a small, self-contained
 * set of UI preferences (interface mode + dashboard onboarding visibility)
 * via the agentic/v1/ui-settings route. The site-wide mode write goes
 * through Admin_Settings_REST::set_ui_mode() so this fallback stays
 * reachable when the settings-app build is missing, without a second
 * update_option() path.
 *
 * @package    Agent_Builder
 * @subpackage Includes
 * @since      2.11.0
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and handles the UI settings REST route.
 */
class UI_Settings_REST {

	/**
	 * Hook route registration.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register the agentic/v1/ui-settings route (GET + POST).
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			'agentic/v1',
			'/ui-settings',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get_settings' ),
					'permission_callback' => array( __CLASS__, 'check_permission' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'update_settings' ),
					'permission_callback' => array( __CLASS__, 'check_permission' ),
				),
			)
		);
	}

	/**
	 * Only users who can manage settings may read or change UI preferences.
	 *
	 * @return bool
	 */
	public static function check_permission(): bool {
		return current_user_can( 'agentic_manage_settings' ) || current_user_can( 'manage_options' );
	}

	/**
	 * Current UI settings payload.
	 *
	 * @return \WP_REST_Response
	 */
	public static function get_settings(): \WP_REST_Response {
		return new \WP_REST_Response( self::current(), 200 );
	}

	/**
	 * Persist UI settings from the request.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public static function update_settings( \WP_REST_Request $request ): \WP_REST_Response {
		$mode = $request->get_param( 'ui_mode' );
		if ( is_string( $mode ) ) {
			Admin_Settings_REST::set_ui_mode( $mode, 'ui_settings_rest' );
		}

		$show_onboarding = $request->get_param( 'show_onboarding' );
		if ( null !== $show_onboarding ) {
			update_option( 'agentic_show_onboarding', rest_sanitize_boolean( $show_onboarding ) ? '1' : '0', false );
		}

		// Appearance — chat font + accent.
		if ( null !== $request->get_param( 'global_font' ) ) {
			$font_allow = class_exists( Chat_Assets::class )
				? Chat_Assets::global_font_allowlist()
				: array( '' );
			$font_in    = sanitize_text_field( (string) $request->get_param( 'global_font' ) );
			if ( in_array( $font_in, $font_allow, true ) ) {
				update_option( 'agentic_global_font', $font_in, false );
			}
		}

		if ( null !== $request->get_param( 'use_theme_accent' ) || null !== $request->get_param( 'global_accent' ) ) {
			$use_theme = rest_sanitize_boolean( $request->get_param( 'use_theme_accent' ) );
			if ( $use_theme || '' === (string) $request->get_param( 'global_accent' ) ) {
				update_option( 'agentic_global_accent', '', false );
			} else {
				$accent = sanitize_hex_color( (string) $request->get_param( 'global_accent' ) );
				if ( ! empty( $accent ) ) {
					update_option( 'agentic_global_accent', $accent, false );
				}
			}
		}

		// Emergency kill switch — side effects, not a simple option write.
		$disable_all        = $request->get_param( 'disable_all_agents' );
		$emergency_warnings = array();
		if ( null !== $disable_all ) {
			$want = rest_sanitize_boolean( $disable_all );
			if ( $want && ! Emergency_Stop::is_active() ) {
				Emergency_Stop::enable();
			} elseif ( ! $want && Emergency_Stop::is_active() ) {
				$emergency_warnings = Emergency_Stop::disable()['warnings'] ?? array();
			}
		}

		return new \WP_REST_Response( self::current( $emergency_warnings ), 200 );
	}

	/**
	 * Read the current settings as a normalized array.
	 *
	 * @param string[] $warnings Optional warnings from the last mutation (e.g. emergency-stop restore failures).
	 * @return array<string,mixed>
	 */
	private static function current( array $warnings = array() ): array {
		return array(
			'ui_mode'            => 'advanced' === get_option( 'agentic_ui_mode', 'basic' ) ? 'advanced' : 'basic',
			'show_onboarding'    => '0' !== get_option( 'agentic_show_onboarding', '1' ),
			'disable_all_agents' => Emergency_Stop::is_active(),
			'global_font'        => (string) get_option( 'agentic_global_font', '' ),
			'global_accent'      => (string) get_option( 'agentic_global_accent', '' ),
			'themes_url'         => admin_url( 'admin.php?page=agentic-settings&tab=global' ),
			'warnings'           => $warnings,
		);
	}
}
