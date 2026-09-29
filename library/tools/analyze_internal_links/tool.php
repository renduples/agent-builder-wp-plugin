<?php
/**
 * Tool: analyze_internal_links
 *
 * Build the site's internal link graph and identify structural issues.
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

/**
 * Build the site's internal link graph across all published content.
 */
class Analyze_Internal_Links extends \Agentic\Tool_Base {

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'analyze_internal_links';
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return 'Build the site\'s internal link graph across all published posts and pages. Returns orphan pages (no inbound links — invisible to crawlers), dead-end pages (no outbound internal links), and hub pages (5+ outbound links). Use this to diagnose internal linking issues before fixing them.';
	}

	/**
	 * Get the tool category.
	 *
	 * @return string
	 */
	public function get_category(): string {
		return 'seo';
	}

	/**
	 * Get the parameter schema.
	 *
	 * @return array
	 */
	public function get_parameters(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'post_type' => array(
					'type'        => 'string',
					'description' => '"any" (default), "post", or "page".',
				),
			),
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Validated arguments from the LLM.
	 * @return array Result data.
	 */
	public function execute( array $arguments ): array {
		$post_type  = $arguments['post_type'] ?? 'any';
		$query_type = 'any' === $post_type ? array( 'post', 'page' ) : $post_type;
		$site_url   = untrailingslashit( get_bloginfo( 'url' ) );

		$posts = get_posts(
			array(
				'post_type'      => $query_type,
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		if ( empty( $posts ) ) {
			return array( 'error' => 'No published posts found.' );
		}

		$url_to_id = array();
		foreach ( $posts as $p ) {
			$permalink                             = get_permalink( $p->ID );
			$url_to_id[ $permalink ]               = $p->ID;
			$url_to_id[ rtrim( $permalink, '/' ) ] = $p->ID;
			$path                                  = wp_parse_url( $permalink, PHP_URL_PATH );
			if ( $path ) {
				$url_to_id[ $path ]               = $p->ID;
				$url_to_id[ rtrim( $path, '/' ) ] = $p->ID;
			}
		}

		$outbound = array();
		$inbound  = array();
		foreach ( $posts as $p ) {
			$outbound[ $p->ID ] = array();
			if ( ! isset( $inbound[ $p->ID ] ) ) {
				$inbound[ $p->ID ] = array();
			}

			preg_match_all( '/<a[^>]+href=["\']([^"\']+)["\'][^>]*>/i', $p->post_content, $matches );
			foreach ( $matches[1] as $href ) {
				if ( str_starts_with( $href, '/' ) && ! str_starts_with( $href, '//' ) ) {
					$href_full = $site_url . $href;
				} elseif ( str_starts_with( $href, $site_url ) ) {
					$href_full = $href;
				} else {
					continue;
				}

				$clean_href = strtok( $href_full, '?#' );
				$target_id  = $url_to_id[ $clean_href ] ?? $url_to_id[ rtrim( $clean_href, '/' ) ] ?? null;
				if ( $target_id && $target_id !== $p->ID && ! in_array( $target_id, $outbound[ $p->ID ], true ) ) {
					$outbound[ $p->ID ][] = $target_id;
					if ( ! isset( $inbound[ $target_id ] ) ) {
						$inbound[ $target_id ] = array();
					}
					$inbound[ $target_id ][] = $p->ID;
				}
			}
		}

		$home_id   = (int) get_option( 'page_on_front' );
		$orphans   = array();
		$dead_ends = array();
		$hubs      = array();

		foreach ( $posts as $p ) {
			$in_count  = count( $inbound[ $p->ID ] ?? array() );
			$out_count = count( $outbound[ $p->ID ] ?? array() );
			$entry     = array(
				'post_id'        => $p->ID,
				'title'          => $p->post_title,
				'url'            => get_permalink( $p->ID ),
				'type'           => $p->post_type,
				'inbound_links'  => $in_count,
				'outbound_links' => $out_count,
			);

			if ( 0 === $in_count && $p->ID !== $home_id ) {
				$orphans[] = $entry;
			}
			if ( 0 === $out_count ) {
				$dead_ends[] = $entry;
			}
			if ( $out_count >= 5 ) {
				$hubs[] = $entry;
			}
		}

		usort( $orphans, fn( $a, $b ) => strcmp( $a['title'], $b['title'] ) );
		usort( $dead_ends, fn( $a, $b ) => strcmp( $a['title'], $b['title'] ) );
		usort( $hubs, fn( $a, $b ) => $b['outbound_links'] <=> $a['outbound_links'] );

		$total_links = array_sum( array_map( 'count', $outbound ) );

		return array(
			'posts_analysed' => count( $posts ),
			'total_links'    => $total_links,
			'orphan_pages'   => $orphans,
			'orphan_count'   => count( $orphans ),
			'dead_end_pages' => $dead_ends,
			'dead_end_count' => count( $dead_ends ),
			'hub_pages'      => array_slice( $hubs, 0, 10 ),
			'hub_count'      => count( $hubs ),
			'health_note'    => count( $orphans ) === 0 && count( $dead_ends ) === 0
				? 'All pages have both inbound and outbound internal links.'
				: sprintf( '%d orphan page(s) need inbound links and %d dead-end page(s) need outbound links.', count( $orphans ), count( $dead_ends ) ),
		);
	}

	/**
	 * Get tool annotations.
	 *
	 * @return array
	 */
	public function get_annotations(): array {
		return array(
			'readonly' => true,
		);
	}
}

return new Analyze_Internal_Links();
