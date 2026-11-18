<?php
/**
 * Tool: search_capabilities
 *
 * Search the agents installed on this site, and the tools they can use, by
 * what they do — tool name, keyword, or purpose. Use this to answer "which
 * agent can do X?" or "what tools handle Y?". Runs entirely on this site.
 *
 * @package    Agent_Builder
 * @subpackage Tools
 * @since      2.9.85
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Search agents and tools by capability.
 */
class Search_Capabilities extends \Agentic\Tool_Base {

	/**
	 * Maximum results per type.
	 */
	private const MAX_RESULTS = 25;

	/**
	 * Get the tool name.
	 */
	public function get_name(): string {
		return 'search_capabilities';
	}

	/**
	 * Get the tool description.
	 */
	public function get_description(): string {
		return 'Search the AI agents installed on this site and the tools they can use, by tool name, purpose, or keyword. Returns matching agents (with whether each is active and which tools it has) and matching tools (with which installed agents have them). Use this when a user asks "which agent can do X?", "is there an agent for Y?", or before naming a tool.';
	}

	/**
	 * Get the tool category.
	 */
	public function get_category(): string {
		return 'agents';
	}

	/**
	 * Get the parameter schema.
	 */
	public function get_parameters(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'q'    => array(
					'type'        => 'string',
					'description' => 'Search term — tool name (e.g. "generate_image"), keyword (e.g. "SEO"), or agent purpose (e.g. "WooCommerce orders").',
				),
				'type' => array(
					'type'        => 'string',
					'enum'        => array( 'all', 'agent', 'tool' ),
					'description' => 'Limit results to agents, tools, or all. Default: all.',
				),
			),
			'required'   => array( 'q' ),
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Validated arguments from the LLM.
	 * @return array Result data.
	 */
	public function execute( array $arguments ): array {
		$query = trim( sanitize_text_field( $arguments['q'] ?? '' ) );
		$type  = sanitize_key( $arguments['type'] ?? 'all' );
		if ( ! in_array( $type, array( 'all', 'agent', 'tool' ), true ) ) {
			$type = 'all';
		}

		$needles     = $this->needles( $query );
		$agents      = $this->installed_agents();
		$tool_owners = array();
		foreach ( $agents as $agent ) {
			foreach ( $agent['tools'] as $tool ) {
				$tool_owners[ $tool ][] = $agent['slug'];
			}
		}

		$results = array();

		if ( in_array( $type, array( 'all', 'agent' ), true ) ) {
			foreach ( $agents as $agent ) {
				$haystack = $agent['name'] . ' ' . $agent['slug'] . ' ' . $agent['description'] . ' ' . implode( ' ', $agent['tools'] );
				if ( $this->matches( $haystack, $needles ) ) {
					$results[] = array(
						'kind'        => 'agent',
						'slug'        => $agent['slug'],
						'name'        => $agent['name'],
						'description' => $agent['description'],
						'active'      => $agent['active'],
						'installed'   => true,
						'source'      => 'local',
						'tools'       => $agent['tools'],
					);
				}
				if ( count( $results ) >= self::MAX_RESULTS ) {
					break;
				}
			}
		}

		if ( in_array( $type, array( 'all', 'tool' ), true ) && class_exists( '\Agentic\Tools_Registry' ) ) {
			$found = 0;
			foreach ( \Agentic\Tools_Registry::get_all() as $name => $tool ) {
				$name     = (string) $name;
				$haystack = $name . ' ' . (string) ( $tool['description'] ?? '' ) . ' ' . (string) ( $tool['category'] ?? '' );
				if ( ! $this->matches( $haystack, $needles ) ) {
					continue;
				}
				$results[] = array(
					'kind'        => 'tool',
					'name'        => $name,
					'description' => (string) ( $tool['description'] ?? '' ),
					'category'    => (string) ( $tool['category'] ?? '' ),
					'enabled'     => ! empty( $tool['enabled'] ),
					'agents'      => $tool_owners[ $name ] ?? array(),
				);
				if ( ++$found >= self::MAX_RESULTS ) {
					break;
				}
			}
		}

		$out = array(
			'results' => $results,
			'total'   => count( $results ),
		);
		if ( empty( $results ) ) {
			$out['note'] = 'Nothing installed on this site matches. Say so plainly; Assistant Trainer can build a custom agent.';
		}
		return $out;
	}

	/**
	 * Search needles: the whole query plus each meaningful word in it.
	 *
	 * @param string $query Search term.
	 * @return string[]
	 */
	private function needles( string $query ): array {
		if ( '' === $query ) {
			return array();
		}

		// Common connector words that would otherwise false-match almost any
		// agent's natural-language description (e.g. "the", "your", "with").
		static $stopwords = array( 'the', 'and', 'for', 'with', 'that', 'this', 'your', 'from', 'agent', 'agents', 'tool', 'tools' );

		// Match on individual words too, so "Support Triage agent" still finds
		// the "Support Triage" agent despite the trailing, non-matching word.
		$words = preg_split( '/\s+/', strtolower( $query ) );
		$words = is_array( $words ) ? $words : array();
		$words = array_diff( array_unique( array_filter( $words, static fn( $w ) => strlen( $w ) >= 4 ) ), $stopwords );

		return array_values( array_filter( array_merge( array( strtolower( $query ) ), $words ) ) );
	}

	/**
	 * Whether any needle occurs in the haystack (case-insensitive).
	 *
	 * @param string   $haystack Text to search.
	 * @param string[] $needles  Lower-case needles.
	 * @return bool
	 */
	private function matches( string $haystack, array $needles ): bool {
		$haystack = strtolower( $haystack );
		foreach ( $needles as $needle ) {
			if ( false !== strpos( $haystack, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Installed agents (bundled and owner-added) with their declared tools.
	 *
	 * @return array<int, array{slug: string, name: string, description: string, active: bool, tools: string[]}>
	 */
	private function installed_agents(): array {
		if ( ! class_exists( '\Agentic_Agent_Registry' ) ) {
			return array();
		}

		$out = array();
		foreach ( \Agentic_Agent_Registry::get_instance()->get_installed_agents() as $slug => $data ) {
			if ( ! is_array( $data ) ) {
				continue;
			}
			$out[] = array(
				'slug'        => (string) ( $data['slug'] ?? $slug ),
				'name'        => (string) ( $data['name'] ?? $slug ),
				'description' => (string) ( $data['description'] ?? '' ),
				'active'      => ! empty( $data['active'] ),
				'tools'       => $this->agent_tools( $data ),
			);
		}
		return $out;
	}

	/**
	 * Declared tool list for an installed agent record.
	 *
	 * @param array<string, mixed> $data Agent record from the registry.
	 * @return string[]
	 */
	private function agent_tools( array $data ): array {
		if ( is_array( $data['db_manifest'] ?? null ) ) {
			return array_values( array_map( 'strval', (array) ( $data['db_manifest']['tools'] ?? array() ) ) );
		}

		$file = rtrim( (string) ( $data['directory'] ?? '' ), '/' ) . '/agent.json';
		if ( '/agent.json' === $file || ! file_exists( $file ) ) {
			return array();
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local agent.json, not a remote URL.
		$decoded = json_decode( (string) file_get_contents( $file ), true );
		return is_array( $decoded ) ? array_values( array_map( 'strval', (array) ( $decoded['tools'] ?? array() ) ) ) : array();
	}

	/**
	 * Get tool annotations.
	 */
	public function get_annotations(): array {
		return array(
			'readonly'    => true,
			'destructive' => false,
		);
	}
}

return new Search_Capabilities();
