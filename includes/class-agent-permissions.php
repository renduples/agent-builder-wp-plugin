<?php
/**
 * Agent Permissions — granular capability toggles for user-space operations
 *
 * Two zones:
 * 1. Plugin/Repo code → request_code_change (git branch, human review)
 * 2. User space → write_file, modify_option, manage_transients, modify_postmeta
 *    (permission-gated, confirmation-required by default)
 *
 * @package    Agent_Builder
 * @subpackage Includes
 * @author     Agent Builder Team <support@agentic-plugin.com>
 * @license    GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 * @link       https://agentic-plugin.com
 * @since      1.6.0
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages agent permission settings for user-space operations.
 */
class Agent_Permissions {

	/**
	 * WordPress option key for permission settings.
	 */
	public const OPTION_KEY = 'agent_builder_agent_permissions';

	/**
	 * Available permission scopes.
	 *
	 * @var array<string, array{label: string, description: string}>
	 */
	private const SCOPES = array(
		'write_theme_files'    => array(
			'label'       => 'Write Theme Files',
			'description' => 'Allow agents to create or modify files in the active theme (CSS, PHP, templates).',
		),
		'write_custom_plugins' => array(
			'label'       => 'Create Custom Plugins',
			'description' => 'Allow agents to create or modify plugin files in wp-content/plugins/agentic-custom/.',
		),
		'modify_options'       => array(
			'label'       => 'Modify Options',
			'description' => 'Allow agents to create, update, or delete WordPress options.',
		),
		'manage_transients'    => array(
			'label'       => 'Manage Transients',
			'description' => 'Allow agents to list, delete, or flush transients.',
		),
		'modify_postmeta'      => array(
			'label'       => 'Modify Post Meta',
			'description' => 'Allow agents to update or delete post meta fields.',
		),
		'query_database_write' => array(
			'label'       => 'Database Write Queries',
			'description' => 'Allow agents to execute INSERT, UPDATE, and DELETE SQL queries (use with extreme caution).',
		),
	);

	/**
	 * Confirmation modes.
	 */
	public const MODE_CONFIRM = 'confirm';
	public const MODE_AUTO    = 'auto';

	/**
	 * Per-request confirmation mode override (set by Agent_Controller for per-agent mode).
	 *
	 * Values: null (use global option), 'confirm' (supervised/proposals), or 'auto' (autonomous).
	 *
	 * @var string|null
	 */
	private static ?string $mode_override = null;

	/**
	 * Override the confirmation mode for the current request lifecycle.
	 *
	 * @param string|null $mode MODE_CONFIRM, MODE_AUTO, or null to clear override.
	 * @return void
	 */
	public static function set_mode_override( ?string $mode ): void {
		self::$mode_override = $mode;
	}

	/**
	 * Get the current confirmation mode override.
	 *
	 * Used to snapshot and restore execution context around nested agent runs.
	 *
	 * @return string|null MODE_CONFIRM, MODE_AUTO, or null when unset.
	 */
	public static function get_mode_override(): ?string {
		return self::$mode_override;
	}

	/**
	 * Get all available permission scopes.
	 *
	 * @return array<string, array{label: string, description: string}>
	 */
	public static function get_scopes(): array {
		return self::SCOPES;
	}

	/**
	 * Get current permission settings.
	 *
	 * @return array{permissions: array<string, bool>, confirmation_mode: string}
	 */
	public static function get_settings(): array {
		$defaults = array(
			'permissions'       => array(),
			'confirmation_mode' => self::MODE_CONFIRM,
		);

		foreach ( array_keys( self::SCOPES ) as $scope ) {
			$defaults['permissions'][ $scope ] = false;
		}

		$saved = get_option( self::OPTION_KEY, array() );

		return wp_parse_args( $saved, $defaults );
	}

	/**
	 * Check if a specific permission is granted.
	 *
	 * @param string $scope Permission scope key.
	 * @return bool
	 */
	public static function is_allowed( string $scope ): bool {
		$settings = self::get_settings();
		return ! empty( $settings['permissions'][ $scope ] );
	}

	/**
	 * Check if confirmation is required before executing user-space writes.
	 *
	 * @return bool
	 */
	public static function requires_confirmation(): bool {
		// Per-agent override takes priority over global setting.
		if ( null !== self::$mode_override ) {
			return self::MODE_CONFIRM === self::$mode_override;
		}
		$settings = self::get_settings();
		return self::MODE_CONFIRM === ( $settings['confirmation_mode'] ?? self::MODE_CONFIRM );
	}

	/**
	 * Save permission settings.
	 *
	 * @param array<string, bool> $permissions Permission scope toggles.
	 * @param string              $mode        Confirmation mode.
	 * @return bool
	 */
	public static function save_settings( array $permissions, string $mode = self::MODE_CONFIRM ): bool {
		$clean = array(
			'permissions'       => array(),
			'confirmation_mode' => in_array( $mode, array( self::MODE_CONFIRM, self::MODE_AUTO ), true ) ? $mode : self::MODE_CONFIRM,
		);

		foreach ( array_keys( self::SCOPES ) as $scope ) {
			$clean['permissions'][ $scope ] = ! empty( $permissions[ $scope ] );
		}

		return update_option( self::OPTION_KEY, $clean );
	}

	/**
	 * Determine if a file path is in user-space (active theme or agentic-custom).
	 *
	 * @param string $relative_path Relative path from wp-content.
	 * @return bool True if user-space, false if plugin/repo code.
	 */
	public static function is_user_space_path( string $relative_path ): bool {
		$clean = ltrim( str_replace( '..', '', $relative_path ), '/\\' );

		// Active theme files.
		$theme_dir = 'themes/' . get_stylesheet();
		if ( str_starts_with( $clean, $theme_dir . '/' ) || $clean === $theme_dir ) {
			return true;
		}

		// Also check parent theme if child theme is active.
		$parent = get_template();
		if ( get_stylesheet() !== $parent ) {
			$parent_dir = 'themes/' . $parent;
			if ( str_starts_with( $clean, $parent_dir . '/' ) || $clean === $parent_dir ) {
				return true;
			}
		}

		// Custom plugin sandbox directory.
		if ( str_starts_with( $clean, 'plugins/agentic-custom/' ) || 'plugins/agentic-custom' === $clean ) {
			return true;
		}

		return false;
	}

	/**
	 * Get the required permission scope for a user-space file path.
	 *
	 * @param string $relative_path Relative path from wp-content.
	 * @return string Permission scope key.
	 */
	public static function get_write_scope_for_path( string $relative_path ): string {
		$clean = ltrim( str_replace( '..', '', $relative_path ), '/\\' );

		if ( str_starts_with( $clean, 'themes/' ) ) {
			return 'write_theme_files';
		}

		if ( str_starts_with( $clean, 'plugins/agentic-custom' ) ) {
			return 'write_custom_plugins';
		}

		return '';
	}
}
