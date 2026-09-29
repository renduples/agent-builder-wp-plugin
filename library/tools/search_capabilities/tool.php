<?php
/**
 * Tool: search_capabilities
 *
 * Search for AI agents by what they can do — tool name, skill, or purpose.
 * Covers bundled agents and all marketplace agents. Use this to answer
 * "which agent can do X?" or "what tools handle Y?".
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
	 * Get the tool name.
	 */
	public function get_name(): string {
		return 'search_capabilities';
	}

	/**
	 * Get the tool description.
	 */
	public function get_description(): string {
		return 'Search for AI agents and tools by capability. Returns bundled agents and marketplace agents that match a query — by tool name, purpose, or keyword. Use this when a user asks "which agent can do X?", "is there an agent for Y?", or when you need to recommend a specialist agent.';
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
				'q'      => array(
					'type'        => 'string',
					'description' => 'Search term — tool name (e.g. "generate_image"), keyword (e.g. "SEO"), or agent purpose (e.g. "WooCommerce orders").',
				),
				'type'   => array(
					'type'        => 'string',
					'enum'        => array( 'all', 'agent', 'tool' ),
					'description' => 'Limit results to agents, tools, or all. Default: all.',
				),
				'source' => array(
					'type'        => 'string',
					'enum'        => array( 'all', 'bundled', 'marketplace' ),
					'description' => 'Limit to bundled agents, marketplace agents, or all. Default: all.',
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
		$query      = sanitize_text_field( $arguments['q'] ?? '' );
		$type       = sanitize_key( $arguments['type'] ?? 'all' );
		$source     = sanitize_key( $arguments['source'] ?? 'all' );
		$api_base   = \Agentic\Service_Registry::url( 'agentic-api' );
		$query_args = array_filter(
			array(
				'q'      => $query,
				'type'   => $type,
				'source' => $source,
			)
		);

		// This remote index covers bundled *definitions* and marketplace agents,
		// but does not know this site's actual install/activation state. Match
		// against the local registry first so a query naming a real, already-
		// installed agent never comes back as a false "not found" — regardless
		// of what the remote index returns or whether it's reachable at all.
		$local_matches = ( 'marketplace' !== $source && in_array( $type, array( 'all', 'agent' ), true ) )
			? $this->find_local_agent_matches( $query )
			: array();

		$cache_key = 'agentic_search_caps_' . md5( wp_json_encode( $query_args ) );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $this->merge_local_matches( $cached, $local_matches );
		}

		$url      = add_query_arg( $query_args, $api_base . '/wp-json/agentic/v1/search-capabilities' );
		$response = wp_remote_get(
			$url,
			array(
				'timeout'   => 10,
				'sslverify' => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			if ( ! empty( $local_matches ) ) {
				return $this->merge_local_matches(
					array(
						'results' => array(),
						'total'   => 0,
					),
					$local_matches
				);
			}
			return array( 'error' => 'Could not reach the capabilities index: ' . $response->get_error_message() );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body ) || ! isset( $body['results'] ) ) {
			return $this->merge_local_matches(
				array(
					'results' => array(),
					'total'   => 0,
					'note'    => 'No results found.',
				),
				$local_matches
			);
		}

		set_transient( $cache_key, $body, 10 * MINUTE_IN_SECONDS );

		return $this->merge_local_matches( $body, $local_matches );
	}

	/**
	 * Find bundled/installed agents on this site whose slug, name, or
	 * description match the query. This is the local source of truth for
	 * install/activation state, unlike the remote capabilities index.
	 *
	 * @param string $query Search term.
	 * @return array<int, array<string, mixed>> Matching agents, local shape.
	 */
	private function find_local_agent_matches( string $query ): array {
		$query = trim( $query );
		if ( '' === $query || ! class_exists( '\Agentic_Agent_Registry' ) ) {
			return array();
		}

		// Common connector words that would otherwise false-match almost any
		// agent's natural-language description (e.g. "the", "your", "with").
		static $stopwords = array( 'the', 'and', 'for', 'with', 'that', 'this', 'your', 'from', 'agent', 'agents' );

		// Match on individual words too, so "Support Triage agent" still finds
		// the "Support Triage" agent despite the trailing, non-matching word.
		$words   = preg_split( '/\s+/', strtolower( $query ) );
		$words   = is_array( $words ) ? $words : array();
		$words   = array_diff( array_unique( array_filter( $words, static fn( $w ) => strlen( $w ) >= 4 ) ), $stopwords );
		$needles = array_filter( array_merge( array( $query ), $words ) );

		$matches = array();
		foreach ( \Agentic_Agent_Registry::get_instance()->get_installed_agents() as $slug => $data ) {
			$haystack = strtolower( ( $data['name'] ?? (string) $slug ) . ' ' . (string) $slug . ' ' . ( $data['description'] ?? '' ) );

			foreach ( $needles as $needle ) {
				if ( false !== strpos( $haystack, strtolower( $needle ) ) ) {
					$matches[] = array(
						'slug'        => $data['slug'] ?? (string) $slug,
						'name'        => $data['name'] ?? (string) $slug,
						'description' => $data['description'] ?? '',
						'active'      => ! empty( $data['active'] ),
						'installed'   => true,
						'source'      => 'local',
					);
					break;
				}
			}
		}

		return $matches;
	}

	/**
	 * Prepend local agent matches to a (possibly remote) result set, deduping
	 * by slug so a locally-confirmed install/active state always wins over
	 * whatever the remote index says about the same agent.
	 *
	 * @param array $body          Result body (from cache or the remote API).
	 * @param array $local_matches Local matches from find_local_agent_matches().
	 * @return array Merged result body.
	 */
	private function merge_local_matches( array $body, array $local_matches ): array {
		if ( empty( $local_matches ) ) {
			return $body;
		}

		$results         = is_array( $body['results'] ?? null ) ? $body['results'] : array();
		$local_slugs     = array_column( $local_matches, 'slug' );
		$results         = array_values(
			array_filter(
				$results,
				static fn( $r ) => ! in_array( $r['slug'] ?? null, $local_slugs, true )
			)
		);
		$body['results'] = array_merge( $local_matches, $results );
		$body['total']   = count( $body['results'] );
		unset( $body['note'] );

		return $body;
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
