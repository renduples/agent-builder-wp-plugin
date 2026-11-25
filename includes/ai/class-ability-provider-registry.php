<?php
/**
 * Ability Provider Registry
 *
 * Central registry for Ability Providers. AgentStructor's Abilities_Bridge
 * consumes providers registered here to offer a rich, extensible set of
 * abilities to agents and the native WP 7.0+ AI Client.
 *
 * Third-party plugins simply do:
 *   Ability_Provider_Registry::register( new MyPlugin_Ability_Provider() );
 *
 * This is the core of the "developer-loved extensibility" architecture
 * described in the WP7 plan.
 *
 * @package    Agent_Builder
 * @subpackage AI
 * @since      2.11.0
 */

declare( strict_types = 1 );

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ability_Provider_Registry {

	/** @var Ability_Provider[] */
	private static array $providers = array();

	public static function register( Ability_Provider $provider ): void {
		self::$providers[ $provider->get_slug() ] = $provider;
	}

	public static function get( string $slug ): ?Ability_Provider {
		return self::$providers[ $slug ] ?? null;
	}

	public static function get_all(): array {
		return self::$providers;
	}

	/**
	 * Get all abilities contributed by all registered providers.
	 */
	public static function get_all_abilities(): array {
		$all = array();
		foreach ( self::$providers as $provider ) {
			$abilities = $provider->get_abilities();
			foreach ( $abilities as $ability ) {
				$all[] = $ability;
			}
		}
		return $all;
	}
}
