<?php
/**
 * Abilities API Adapter — single compatibility wrapper for the WP Abilities API
 *
 * Centralises every call to the WordPress Abilities API (introduced in
 * WordPress 6.9) in one place. The rest of the plugin calls these static
 * methods instead of touching the API directly. This keeps the plugin safe on
 * WordPress < 6.9 — where the API does not exist — because each method is
 * runtime-guarded with class_exists().
 *
 * Implementation note: this adapter talks to the Abilities API registry
 * classes (WP_Abilities_Registry / WP_Ability_Categories_Registry) directly
 * rather than the procedural wp_register_ability()/wp_get_abilities() wrappers.
 * The registry methods are exactly what those global functions call internally
 * (see wp-includes/abilities-api.php), so behaviour — including the
 * wp_abilities_api_init / _categories_init timing safeguards — is identical.
 * Using the OOP API keeps AgentStructor's "Requires at least: 6.4" intact (the
 * deliberate pre-WordPress-7 bridge positioning) while avoiding hard
 * references to global functions that do not exist on older WordPress.
 *
 * @package    Agent_Builder
 * @subpackage Includes
 * @author     AgentStructor Team <support@agentic-plugin.com>
 * @license    GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 * @link       https://agentstructor.com
 * @since      2.9.272
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin, guarded wrapper around the WordPress Abilities API (WP 6.9+).
 */
final class Abilities_API {

	/**
	 * Whether the WordPress Abilities API is available on this site.
	 *
	 * @return bool True on WordPress 6.9+ where the Abilities API exists.
	 */
	public static function is_available(): bool {
		return class_exists( 'WP_Abilities_Registry' );
	}

	/**
	 * Register an ability. No-op on WordPress < 6.9.
	 *
	 * Mirrors wp_register_ability(): abilities may only be registered on the
	 * wp_abilities_api_init action, so registration outside it is ignored.
	 *
	 * @param string $name Ability name (e.g. 'agent-builder/get-posts').
	 * @param array  $args Ability arguments.
	 * @return mixed The registered ability instance, or null when unavailable.
	 */
	public static function register( string $name, array $args ): mixed {
		if ( ! class_exists( 'WP_Abilities_Registry' ) || ! doing_action( 'wp_abilities_api_init' ) ) {
			return null;
		}
		$registry = \WP_Abilities_Registry::get_instance();
		if ( null === $registry ) {
			return null;
		}
		return $registry->register( $name, $args );
	}

	/**
	 * Register an ability category. No-op on WordPress < 6.9.
	 *
	 * Mirrors wp_register_ability_category(): categories may only be registered
	 * on the wp_abilities_api_categories_init action.
	 *
	 * @param string $slug Category slug.
	 * @param array  $args Category arguments.
	 * @return mixed The registered category, or null when unavailable.
	 */
	public static function register_category( string $slug, array $args ): mixed {
		if ( ! class_exists( 'WP_Ability_Categories_Registry' ) || ! doing_action( 'wp_abilities_api_categories_init' ) ) {
			return null;
		}
		$registry = \WP_Ability_Categories_Registry::get_instance();
		if ( null === $registry ) {
			return null;
		}
		return $registry->register( $slug, $args );
	}

	/**
	 * Get all registered abilities. Empty array on WordPress < 6.9.
	 *
	 * @return array List of registered ability objects.
	 */
	public static function get_all(): array {
		if ( ! class_exists( 'WP_Abilities_Registry' ) ) {
			return array();
		}
		$registry = \WP_Abilities_Registry::get_instance();
		if ( null === $registry ) {
			return array();
		}
		return $registry->get_all_registered();
	}

	/**
	 * Get a single ability by name. Null on WordPress < 6.9 or if not found.
	 *
	 * @param string $name Ability name.
	 * @return \WP_Ability|null The ability, or null when unavailable/not found.
	 */
	public static function get( string $name ): ?\WP_Ability {
		if ( ! class_exists( 'WP_Abilities_Registry' ) ) {
			return null;
		}
		$registry = \WP_Abilities_Registry::get_instance();
		if ( null === $registry ) {
			return null;
		}
		return $registry->get_registered( $name );
	}
}
