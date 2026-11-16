<?php
/**
 * WP-CLI commands for the general abilities namespace (`wp agent abilities`).
 *
 * Primarily a convenience / inspector for the WP Abilities API (and our bridge).
 * For the fullest WP7 + Siteloom experience use the `wp agent wp-ai` family.
 *
 * @package    Agent_Builder
 * @subpackage CLI
 * @since      2.11.0
 */

declare( strict_types=1 );

namespace Agentic\CLI;

use Agentic\WP_Optional_API;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * `wp agent abilities` subcommands.
 */
class Abilities_Command extends \WP_CLI_Command {

	/**
	 * List abilities visible to WordPress (core + registered by plugins including Siteloom).
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp agent abilities list
	 *     wp agent abilities list --format=json
	 *
	 * @subcommand list
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function list_( array $args, array $assoc_args ): void {
		$format = $assoc_args['format'] ?? 'table';

		if ( ! WP_Optional_API::has( 'wp_get_abilities' ) ) {
			\WP_CLI::log( 'Abilities API not available on this WordPress version (WP 6.9+ / 7.0+ recommended).' );
			return;
		}

		$all  = WP_Optional_API::get_abilities();
		$rows = array();

		foreach ( $all as $ability ) {
			$name   = $ability->get_name();
			$rows[] = array(
				'name'        => $name,
				'description' => $ability->get_description() ?? '',
				'category'    => $ability->get_category() ?? '',
			);
		}

		\WP_CLI::log( count( $rows ) . ' abilities registered in WordPress.' );

		if ( ! empty( $rows ) ) {
			\WP_CLI\Utils\format_items( $format, $rows, array( 'name', 'description', 'category' ) );
		}
	}

	/**
	 * Test execute an ability by name (alias to `wp agent wp-ai test-execute` for discoverability).
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function test( array $args, array $assoc_args ): void {
		\WP_CLI::log( 'Delegating to `wp agent wp-ai test-execute` (the richest implementation).' );
		if ( class_exists( '\\Agentic\\CLI\\WP_AI_Command' ) ) {
			$wp_ai = new WP_AI_Command();
			// $args here for the `test` subcommand already has the ability name as $args[0]
			$wp_ai->test_execute( $args, $assoc_args );
		} else {
			\WP_CLI::error( 'WP_AI_Command not available for delegation.' );
		}
	}
}
