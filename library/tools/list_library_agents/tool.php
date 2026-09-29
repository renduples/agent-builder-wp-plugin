<?php
/**
 * Tool: list_library_agents
 *
 * @package    Agent_Builder
 * @subpackage Tools
 * @since      2.0.0
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Agentic\Tool_Base;

class List_Library_Agents extends Tool_Base {

	public function get_name(): string {
		return 'list_library_agents';
	}

	public function get_description(): string {
		return 'List all agents in the library directory.';
	}

	public function get_category(): string {
		return 'assistant-trainer';
	}

	public function get_risk_level(): string {
		return 'none';
	}

	public function get_annotations(): array {
		return array(
			'read_only'   => true,
			'destructive' => false,
		);
	}

	public function get_parameters(): array {
		return array(
			'include_code_sample' => array(
				'type'        => 'boolean',
				'description' => 'Include class name and tool count for each agent.',
				'required'    => false,
			),
		);
	}

	public function execute( array $args ): array {
		$include_code = $args['include_code_sample'] ?? false;
		$library_path = \Agentic\Tool_Helpers::get_library_path();

		if ( ! is_dir( $library_path ) ) {
			return array( 'error' => 'Library directory not found' );
		}

		$agents = array();
		$dirs   = @scandir( $library_path );

		foreach ( $dirs as $dir ) {
			if ( '.' === $dir || '..' === $dir ) {
				continue;
			}

			$php_file  = $library_path . $dir . '/agent.php';
			$json_file = $library_path . $dir . '/agent.json';

			if ( file_exists( $php_file ) ) {
				$agent_info = array(
					'slug'    => $dir,
					'type'    => 'php',
					'file'    => $php_file,
					'headers' => $this->parse_agent_headers( $php_file ),
				);

				if ( $include_code ) {
					$content = (string) file_get_contents( $php_file );

					if ( preg_match( '/class\s+(\w+)\s+extends/', $content, $matches ) ) {
						$agent_info['class_name'] = $matches[1];
					}

					$agent_info['tool_count'] = substr_count( $content, "'type' => 'function'" );
					$agent_info['line_count'] = substr_count( $content, "\n" );
				}

				$agents[] = $agent_info;
			} elseif ( file_exists( $json_file ) ) {
				$manifest = json_decode( (string) file_get_contents( $json_file ), true );

				if ( ! is_array( $manifest ) ) {
					continue;
				}

				$agents[] = array(
					'slug'        => $manifest['slug'] ?? $dir,
					'type'        => 'manifest',
					'file'        => $json_file,
					'name'        => $manifest['name'] ?? $dir,
					'description' => $manifest['description'] ?? '',
					'category'    => $manifest['category'] ?? '',
					'tool_count'  => ( isset( $manifest['abilities'] ) && is_array( $manifest['abilities'] ) ) ? count( $manifest['abilities'] ) : 0,
				);
			}
		}

		return array(
			'agents' => $agents,
			'count'  => count( $agents ),
			'path'   => $library_path,
		);
	}

	private function parse_agent_headers( string $file_path ): array {
		$content  = file_get_contents( $file_path );
		$headers  = array(
			'name'        => '',
			'version'     => '',
			'description' => '',
			'category'    => '',
			'icon'        => '',
		);
		$patterns = array(
			'name'        => '/Agent Name:\s*(.+)/i',
			'version'     => '/Version:\s*(.+)/i',
			'description' => '/Description:\s*(.+)/i',
			'category'    => '/Category:\s*(.+)/i',
			'icon'        => '/Icon:\s*(.+)/i',
		);

		foreach ( $patterns as $key => $pattern ) {
			if ( preg_match( $pattern, $content, $matches ) ) {
				$headers[ $key ] = trim( $matches[1] );
			}
		}

		return $headers;
	}
}

return new List_Library_Agents();
