<?php
/**
 * WP-CLI subcommands for the WordPress 7.0+ AI substrate (and AgentStructor bridge).
 *
 * Provides first-class access to detection, native Abilities, Connectors preference,
 * and the extensibility bridge we built so everything (and better) works on older WP too.
 *
 * Examples:
 *   wp agent wp-ai status
 *   wp agent wp-ai status --format=json
 *   wp agent wp-ai abilities --format=table
 *   wp agent wp-ai test-execute wp-extended/get-posts --input='{"per_page":2}' --format=yaml
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
 * Commands under `wp agent wp-ai`.
 */
class WP_AI_Command extends \WP_CLI_Command {

	/**
	 * Show the current WP7 AI substrate status + bridge mode.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format. table, json, csv, yaml.
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
	 *     wp agent wp-ai status
	 *     wp agent wp-ai status --format=json
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Named args.
	 */
	public function status( array $args, array $assoc_args ): void {
		if ( ! class_exists( '\\Agentic\\WP_AI_Detection' ) ) {
			\WP_CLI::error( 'WP_AI_Detection not available. Is the AgentStructor plugin loaded?' );
		}

		$matrix = \Agentic\WP_AI_Detection::get_feature_matrix();
		$label  = \Agentic\WP_AI_Detection::get_mode_label();
		$format = $assoc_args['format'] ?? 'table';

		\WP_CLI::log( $label );

		if ( in_array( $format, array( 'json', 'yaml' ), true ) ) {
			// For complex single-row matrix, format_items still works but we pretty-print for json/yaml.
			\WP_CLI::print_value( $matrix, array( 'format' => $format ) );
		} else {
			\WP_CLI\Utils\format_items( $format, array( $matrix ), array_keys( $matrix ) );
		}
	}

	/**
	 * List registered abilities (core WP + AgentStructor providers + bridge enhancements).
	 *
	 * This surfaces both native WP 7.0 abilities and the ones we emit/enhance for
	 * consistent agent experience across WP versions (the "bridge").
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
	 * [--source=<source>]
	 * : Filter source. core, agent-builder, all.
	 * ---
	 * default: all
	 * options:
	 *   - core
	 *   - agent-builder
	 *   - all
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp agent wp-ai abilities
	 *     wp agent wp-ai abilities --source=agent-builder --format=json
	 *
	 * @subcommand abilities
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function abilities( array $args, array $assoc_args ): void {
		$format = $assoc_args['format'] ?? 'table';
		$source = $assoc_args['source'] ?? 'all';

		$rows = array();

		// Core WP abilities (when Abilities API present).
		if ( WP_Optional_API::has( 'wp_get_abilities' ) ) {
			foreach ( WP_Optional_API::get_abilities() as $ability ) {
				$name    = $ability->get_name();
				$is_ours = str_starts_with( $name, 'agent-builder/' ) || str_starts_with( $name, 'wp-extended/' );

				if ( 'agent-builder' === $source && ! $is_ours ) {
					continue;
				}
				if ( 'core' === $source && $is_ours ) {
					continue;
				}

				$rows[] = array(
					'source'      => $is_ours ? 'agent-builder' : 'core',
					'name'        => $name,
					'description' => $ability->get_description() ?? '',
				);
			}
		}

		// Additional abilities registered via our Ability_Provider extensibility (WP7 bridge).
		if ( ( 'all' === $source || 'agent-builder' === $source )
			&& class_exists( '\\Agentic\\Ability_Provider_Registry' )
		) {
			foreach ( \Agentic\Ability_Provider_Registry::get_all() as $provider ) {
				foreach ( $provider->get_abilities() as $ab ) {
					$name = is_array( $ab )
						? ( $ab['name'] ?? ( $ab['slug'] ?? 'unknown' ) )
						: ( method_exists( $ab, 'get_name' ) ? $ab->get_name() : 'unknown' );

					// Avoid exact dups if already listed via wp_get_abilities.
					$exists = false;
					foreach ( $rows as $r ) {
						if ( $r['name'] === $name ) {
							$exists = true;
							break;
						}
					}
					if ( ! $exists ) {
						$rows[] = array(
							'source'      => $provider->get_slug(),
							'name'        => $name,
							'description' => is_array( $ab ) ? ( $ab['description'] ?? '' ) : '',
						);
					}
				}
			}
		}

		if ( empty( $rows ) ) {
			\WP_CLI::warning( 'No abilities found for the given filter.' );
			return;
		}

		\WP_CLI\Utils\format_items( $format, $rows, array( 'source', 'name', 'description' ) );
	}

	/**
	 * Test-execute an ability (native WP7 or via AgentStructor bridge/tools).
	 *
	 * Useful for verifying that abilities registered by core, other plugins, or
	 * AgentStructor (including enhanced ones and Cloudflare etc. tools) actually work.
	 *
	 * Respects permission callbacks where possible.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : The full ability name (e.g. wp-extended/get-posts, get_posts, or agent-builder cloudflare tool slug).
	 *
	 * [--input=<json>]
	 * : JSON-encoded arguments object to pass to the ability.
	 * ---
	 * default: {}
	 * ---
	 *
	 * [--dry-run]
	 * : Print what would be executed without actually running it. Recommended first step.
	 *
	 * [--format=<format>]
	 * : Output format for the result.
	 * ---
	 * default: yaml
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp agent wp-ai test-execute wp-extended/get-posts --input='{"per_page":1}' --dry-run
	 *     wp agent wp-ai test-execute wp-extended/get-posts --input='{"per_page":2}'
	 *     wp agent wp-ai test-execute get_posts --input='{"post_type":"post"}' --format=json
	 *
	 * @subcommand test-execute
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function test_execute( array $args, array $assoc_args ): void {
		if ( empty( $args[0] ) ) {
			\WP_CLI::error( 'Ability name is required. See: wp help agent wp-ai test-execute' );
		}

		$name      = $args[0];
		$input_raw = $assoc_args['input'] ?? '{}';
		// WP-CLI can sometimes pass arrays for assoc args in edge cases with flags; coerce to string.
		$input_json = is_string( $input_raw ) ? $input_raw : '{}';
		$input      = json_decode( $input_json, true );
		if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $input ) ) {
			$input = array();
		}

		$dry_run = \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$format  = $assoc_args['format'] ?? 'yaml';

		if ( $dry_run ) {
			\WP_CLI::log( "DRY-RUN: test-execute '{$name}'" );
			\WP_CLI::log( 'Input: ' . wp_json_encode( $input ) );
			\WP_CLI::success( 'Dry run complete. Remove --dry-run to execute.' );
			return;
		}

		$result = $this->perform_test_execute( $name, $input );

		if ( isset( $result['error'] ) ) {
			\WP_CLI::error( $result['error'] );
		}

		if ( ! empty( $result['success'] ) || isset( $result['result'] ) ) {
			\WP_CLI::success( "Executed '{$name}' successfully." );
		}

		// Pretty output of the result payload.
		$payload = $result['result'] ?? $result;
		if ( is_array( $payload ) ) {
			if ( 'json' === $format ) {
				\WP_CLI::print_value( $payload, array( 'format' => 'json' ) );
			} else {
				\WP_CLI\Utils\format_items( $format, array( $payload ), array_keys( $payload ) );
			}
		} else {
			\WP_CLI::print_value( $payload, array( 'format' => $format ) );
		}
	}

	/**
	 * Internal execution for test-execute.
	 *
	 * Tries (in order):
	 * 1. Direct WP ability by exact name (wp_get_abilities + execute + perm check).
	 * 2. AgentStructor bridge execute_ability (for function_name style names).
	 * 3. Tool_Loader for agent-builder/* tools (maps names).
	 *
	 * @param string $name  Ability or tool identifier.
	 * @param array  $input Arguments.
	 * @return array{success?:bool, result?:mixed, error?:string}
	 */
	private function perform_test_execute( string $name, array $input ): array {
		// Path 1: Direct native / extended ability.
		if ( WP_Optional_API::has( 'wp_get_abilities' ) ) {
			foreach ( WP_Optional_API::get_abilities() as $ability ) {
				if ( $ability->get_name() === $name ) {
					$perm = $ability->check_permissions( $input ?: null );
					if ( is_wp_error( $perm ) ) {
						return array( 'error' => $perm->get_error_message() );
					}
					if ( false === $perm ) {
						return array( 'error' => 'Permission denied for ability: ' . $name );
					}

					$exec = $ability->execute( $input ?: null );
					if ( is_wp_error( $exec ) ) {
						return array( 'error' => $exec->get_error_message() );
					}
					return array(
						'success' => true,
						'result'  => $exec,
					);
				}
			}
		}

		// Path 2: Bridge (handles name mapping for tools we exposed as abilities).
		if ( class_exists( '\\Agentic\\Abilities_Bridge' ) ) {
			$bridge = new \Agentic\Abilities_Bridge();
			if ( method_exists( $bridge, 'execute_ability' ) ) {
				$bridge_result = $bridge->execute_ability( $name, $input );
				if ( is_array( $bridge_result ) ) {
					if ( isset( $bridge_result['error'] ) ) {
						return $bridge_result;
					}
					return array(
						'success' => true,
						'result'  => $bridge_result,
					);
				}
			}
		}

		// Path 3: Tool loader (for agent-builder tools, Cloudflare, etc.).
		if ( class_exists( '\\Agentic\\Tool_Loader' ) ) {
			$loader = \Agentic\Tool_Loader::get_instance();
			// Normalize possible slugs: agent-builder/foo-bar or get_foo etc.
			$tool_name   = str_replace( array( 'agent-builder/', '-', '/' ), array( '', '_', '_' ), $name );
			$tool_result = $loader->execute( $tool_name, $input );

			if ( is_array( $tool_result ) && isset( $tool_result['error'] ) ) {
				// Try original name as last resort.
				$tool_result = $loader->execute( $name, $input );
			}

			return array(
				'success' => ! isset( $tool_result['error'] ),
				'result'  => $tool_result,
			);
		}

		return array(
			'error' => "No execution path found for '{$name}'. Is the ability/tool registered and AgentStructor fully loaded?",
		);
	}
}
