<?php
/**
 * User Roles & Access Control
 *
 * Manages fine-grained access control: which WordPress user roles can use
 * which parts of the Agent Builder plugin and which agents.
 *
 * Settings are stored in the `agent_builder_user_roles` option and are enforced
 * via a `user_has_cap` filter that injects `agentic_*` custom capabilities.
 * Administrators always retain full access regardless of stored settings.
 *
 * @package    Agent_Builder
 * @subpackage Includes
 * @since      2.0.0
 */

declare(strict_types=1);

namespace Agentic;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * User role access-control for the Agent Builder plugin.
 */
class User_Roles {

	/** Option key used to persist settings. */
	const OPTION_KEY = 'agent_builder_user_roles';

	/** Prefix for all custom capabilities injected by this class. */
	const CAP_PREFIX = 'agentic_';

	// -------------------------------------------------------------------------
	// Privilege definitions
	// -------------------------------------------------------------------------

	/**
	 * Plugin administration privilege definitions.
	 *
	 * Keys become the unprefixed capability names (e.g. `view_dashboard` →
	 * `agentic_view_dashboard`).
	 *
	 * @return array<string, array{label: string, description: string}>
	 */
	public static function get_plugin_privileges(): array {
		return array(
			'view_dashboard'     => array(
				'label'       => 'View Dashboard',
				'description' => 'Access the Agent Builder dashboard overview and stats.',
			),
			'manage_agents'      => array(
				'label'       => 'Manage Agents',
				'description' => 'Install, activate, deactivate, edit, and delete agents.',
			),
			'view_audit_log'     => array(
				'label'       => 'View Audit Log',
				'description' => 'Read the full agent action and security audit log.',
			),
			'manage_tools'       => array(
				'label'       => 'Configure Tools',
				'description' => 'Enable or disable individual agent tools.',
			),
			'run_tasks_manually' => array(
				'label'       => 'Run Tasks Manually',
				'description' => 'Trigger scheduled agent tasks by hand from the admin UI.',
			),
			'manage_settings'    => array(
				'label'       => 'Manage Plugin Settings',
				'description' => 'Change AI providers, API keys, caching and security options. Grants access to all plugin settings tabs.',
			),
		);
	}

	/**
	 * AI agent interaction privilege definitions.
	 *
	 * @return array<string, array{label: string, description: string}>
	 */
	public static function get_agent_privileges(): array {
		return array(
			'chat_frontend'       => array(
				'label'       => 'Chat on the Frontend',
				'description' => 'Send messages to agents via page-embedded chat or [agentic_chat] shortcodes.',
			),
			'chat_admin_bar'      => array(
				'label'       => 'Chat in the Admin Bar',
				'description' => 'Use the AI chat overlay embedded in the WordPress admin bar.',
			),
			'view_agent_list'     => array(
				'label'       => 'View Installed Agents',
				'description' => 'Browse the list of installed and active agents in the admin.',
			),
			'use_uploaded_agents' => array(
				'label'       => 'Use Premium / Uploaded Agents',
				'description' => 'Interact with agents downloaded from the marketplace.',
			),
		);
	}

	// -------------------------------------------------------------------------
	// Settings storage
	// -------------------------------------------------------------------------

	/**
	 * Return the default role assignments.
	 *
	 * - Plugin admin pages: administrator only.
	 * - Admin bar chat: administrator only.
	 * - Frontend chat: all roles.
	 *
	 * @return array<string, array<string, list<string>>>
	 */
	public static function get_defaults(): array {
		$defaults = array(
			'plugin' => array(),
			'agents' => array(),
		);

		foreach ( array_keys( self::get_plugin_privileges() ) as $priv ) {
			$defaults['plugin'][ $priv ] = array( 'administrator' );
		}

		foreach ( array_keys( self::get_agent_privileges() ) as $priv ) {
			$defaults['agents'][ $priv ] = array( 'administrator' );
		}

		// Frontend chat is available to all logged-in roles by default.
		$defaults['agents']['chat_frontend'] = array_keys( self::get_all_wp_roles() );

		return $defaults;
	}

	/**
	 * Return stored settings, falling back to defaults if none are saved.
	 *
	 * @return array<string, array<string, list<string>>>
	 */
	public static function get_settings(): array {
		$saved = get_option( self::OPTION_KEY, null );
		if ( ! is_array( $saved ) ) {
			return self::get_defaults();
		}
		return $saved;
	}

	/**
	 * Validate and persist role settings from a form submission.
	 *
	 * @param array<string, mixed> $raw Raw POST data (unsanitised).
	 * @return void
	 */
	public static function save_settings( array $raw ): void {
		$all_roles    = array_keys( self::get_all_wp_roles() );
		$plugin_privs = array_keys( self::get_plugin_privileges() );
		$agent_privs  = array_keys( self::get_agent_privileges() );

		$clean = array(
			'plugin' => array(),
			'agents' => array(),
		);

		foreach ( $plugin_privs as $priv ) {
			$submitted                = isset( $raw['plugin'][ $priv ] ) ? (array) $raw['plugin'][ $priv ] : array();
			$clean['plugin'][ $priv ] = array_values( array_intersect( $submitted, $all_roles ) );
		}

		foreach ( $agent_privs as $priv ) {
			$submitted                = isset( $raw['agents'][ $priv ] ) ? (array) $raw['agents'][ $priv ] : array();
			$clean['agents'][ $priv ] = array_values( array_intersect( $submitted, $all_roles ) );
		}

		update_option( self::OPTION_KEY, $clean );
	}

	// -------------------------------------------------------------------------
	// Permission checks
	// -------------------------------------------------------------------------

	/**
	 * Check whether the currently logged-in user has a given privilege.
	 *
	 * @param string $privilege Unprefixed privilege name (e.g. `view_dashboard`).
	 * @return bool
	 */
	public static function current_user_can( string $privilege ): bool {
		return self::user_can( wp_get_current_user(), $privilege );
	}

	/**
	 * Check whether a specific user has a given privilege.
	 *
	 * Administrators always return `true` regardless of stored settings.
	 *
	 * @param \WP_User $user      The user to check.
	 * @param string   $privilege Unprefixed privilege name.
	 * @return bool
	 */
	public static function user_can( \WP_User $user, string $privilege ): bool {
		if ( ! $user->exists() ) {
			return false;
		}

		// Administrators retain full access as a security fail-safe. Key off the
		// manage_options capability, not the literal 'administrator' role name, so
		// multisite super admins and custom admin-equivalent roles (which can
		// already reach the manage_options-gated pages) are not locked out of the
		// agentic_* -gated pages such as Chat. manage_options is WordPress's own
		// definition of a site administrator.
		if ( in_array( 'administrator', (array) $user->roles, true ) || $user->has_cap( 'manage_options' ) ) {
			return true;
		}

		$settings = self::get_settings();

		// Search both sections.
		$allowed_roles = $settings['plugin'][ $privilege ] ?? $settings['agents'][ $privilege ] ?? array();

		foreach ( (array) $user->roles as $role ) {
			if ( in_array( $role, $allowed_roles, true ) ) {
				return true;
			}
		}

		return false;
	}

	// -------------------------------------------------------------------------
	// WordPress capability injection
	// -------------------------------------------------------------------------

	/**
	 * `user_has_cap` filter callback.
	 *
	 * Dynamically grants `agentic_*` capabilities based on stored role settings.
	 * Hooked in `Plugin::init_hooks()` so it runs for both admin and REST contexts.
	 *
	 * @param array<string, bool> $allcaps All capabilities the user currently has.
	 * @param string[]            $caps    Primitive capabilities being checked.
	 * @param array<int, mixed>   $args    Extra arguments (action, user id, object).
	 * @param \WP_User            $user    The user being checked.
	 * @return array<string, bool>
	 */
	public static function filter_user_has_cap( array $allcaps, array $caps, array $args, \WP_User $user ): array {
		foreach ( $caps as $cap ) {
			if ( ! str_starts_with( $cap, self::CAP_PREFIX ) ) {
				continue;
			}
			$privilege = substr( $cap, strlen( self::CAP_PREFIX ) );
			if ( self::user_can( $user, $privilege ) ) {
				$allcaps[ $cap ] = true;
			}
		}
		return $allcaps;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Return all registered WordPress roles.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_all_wp_roles(): array {
		return (array) wp_roles()->roles;
	}
}
