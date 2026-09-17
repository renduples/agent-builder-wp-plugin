<?php
/**
 * Tool: get_short_link_stats
 *
 * @package    Agent_Builder
 * @subpackage Tools
 * @since      2.0.0
 *
 * php version 8.1
 */

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Agentic\Tool_Base;

class Get_Short_Link_Stats extends Tool_Base {
	public function get_name(): string {
		return 'get_short_link_stats';
	}

	public function get_description(): string {
		return 'Get click stats and details for short links created by Agent Builder. Returns all links or a specific slug.';
	}

	public function get_category(): string {
		return 'utility';
	}

	public function get_parameters(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'slug' => array(
					'type'        => 'string',
					'description' => 'Short link slug to look up. Omit to return stats for all short links.',
				),
			),
			'required'   => array(),
		);
	}

	public function get_annotations(): array {
		return array(
			'read_only'   => true,
			'destructive' => false,
		);
	}

	public function execute( array $args ): array {
		$slug  = sanitize_key( $args['slug'] ?? '' );
		$links = (array) get_option( 'agent_builder_short_links', array() );

		if ( $slug ) {
			if ( ! isset( $links[ $slug ] ) ) {
				return array( 'error' => "Short link '{$slug}' not found." );
			}

			$link = $links[ $slug ];
			return array(
				'slug'       => $slug,
				'short_url'  => site_url( '/go/' . $slug ),
				'target_url' => $link['target_url'],
				'clicks'     => (int) ( $link['clicks'] ?? 0 ),
				'created_at' => $link['created_at'] ?? null,
			);
		}

		// Return all.
		$result       = array();
		$total_clicks = 0;

		foreach ( $links as $s => $link ) {
			$clicks        = (int) ( $link['clicks'] ?? 0 );
			$total_clicks += $clicks;
			$result[]      = array(
				'slug'       => $s,
				'short_url'  => site_url( '/go/' . $s ),
				'target_url' => $link['target_url'],
				'clicks'     => $clicks,
				'created_at' => $link['created_at'] ?? null,
			);
		}

		// Sort by clicks descending.
		usort( $result, fn( $a, $b ) => $b['clicks'] <=> $a['clicks'] );

		return array(
			'links'        => $result,
			'total'        => count( $result ),
			'total_clicks' => $total_clicks,
		);
	}
}

return new Get_Short_Link_Stats();
