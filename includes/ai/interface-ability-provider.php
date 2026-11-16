<?php
/**
 * Ability Provider Interface
 *
 * Allows plugins and themes to expose groups of WordPress Abilities
 * that Siteloom (and the native WP AI Client on 7.0+) can discover,
 * orchestrate, and safely execute.
 *
 * This is the fundamental extensibility point that makes Siteloom
 * loved by developers: any plugin can register an Ability Provider and
 * instantly make its capabilities available to agents without custom
 * integration code.
 *
 * @package    Agent_Builder
 * @subpackage AI
 * @since      2.11.0
 */

declare( strict_types=1 );

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface Ability_Provider {

	/**
	 * Unique slug for this provider.
	 */
	public function get_slug(): string;

	/**
	 * Human name for admin / debugging surfaces.
	 */
	public function get_name(): string;

	/**
	 * Return an array of WP_Ability instances (or ability arg arrays)
	 * that this provider contributes.
	 *
	 * These will be registered via wp_register_ability() when the bridge
	 * is active.
	 *
	 * @return array
	 */
	public function get_abilities(): array;

	/**
	 * Optional rich instructions / guidance for AI models about when and
	 * how to use the abilities from this provider.
	 */
	public function get_instructions(): string;
}
