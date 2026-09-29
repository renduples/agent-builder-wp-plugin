<?php
/**
 * Tool: Audit Internal Links
 *
 * Build the site internal link graph. Finds orphan pages (no inbound links),
 * hub pages (many outbound), dead-end pages (no outbound), and suggests
 * cross-linking opportunities.
 *
 * @package Agentic\Tools
 */

declare(strict_types=1);

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Audit_Internal_Links extends \Agentic\Tool_Base {

	public function get_name(): string {
		return 'audit_internal_links';
	}

	public function get_description(): string {
		return 'Build the site internal link graph. Finds orphan pages (no inbound links), hub pages (many outbound), dead-end pages (no outbound), and suggests cross-linking opportunities.';
	}

	public function get_category(): string {
		return 'site-audit';
	}

	public function get_parameters(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'max_posts' => array(
					'type'        => 'integer',
					'description' => 'Maximum number of posts to analyse. Default 50, max 100.',
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
		$max_posts = min( 100, (int) ( $args['max_posts'] ?? 50 ) );
		$home_url  = home_url();

		$posts = get_posts(
			array(
				'post_type'      => array( 'post', 'page' ),
				'post_status'    => 'publish',
				'posts_per_page' => $max_posts,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		$outbound_map = array(); // post_id => [target_urls].
		$inbound_map  = array(); // post_id => count.
		$all_urls     = array(); // url => post_id.

		// Build URL index.
		foreach ( $posts as $post ) {
			$url                      = get_permalink( $post->ID );
			$all_urls[ $url ]         = $post->ID;
			$inbound_map[ $post->ID ] = 0;
		}

		// Parse internal links from each post.
		foreach ( $posts as $post ) {
			$outbound = array();
			preg_match_all( '/href=["\']([^"\']+)["\']/', $post->post_content, $matches );
			foreach ( $matches[1] as $href ) {
				if ( str_starts_with( $href, $home_url ) || str_starts_with( $href, '/' ) ) {
					$full_url   = str_starts_with( $href, '/' ) ? $home_url . $href : $href;
					$outbound[] = $full_url;
					// Credit inbound links.
					foreach ( $all_urls as $target_url => $target_id ) {
						$target_path = wp_parse_url( $target_url, PHP_URL_PATH );
						if ( $target_id !== $post->ID && str_contains( $full_url, $target_path ? $target_path : '' ) ) {
							$inbound_map[ $target_id ] = ( $inbound_map[ $target_id ] ?? 0 ) + 1;
						}
					}
				}
			}
			$outbound_map[ $post->ID ] = $outbound;
		}

		// Classify pages.
		$orphans   = array();
		$dead_ends = array();
		$hubs      = array();
		$home_id   = (int) get_option( 'page_on_front' );

		foreach ( $posts as $post ) {
			$inbound_count  = $inbound_map[ $post->ID ] ?? 0;
			$outbound_count = count( $outbound_map[ $post->ID ] ?? array() );

			if ( 0 === $inbound_count && $post->ID !== $home_id ) {
				$orphans[] = array(
					'post_id' => $post->ID,
					'title'   => $post->post_title,
					'url'     => get_permalink( $post->ID ),
				);
			}
			if ( 0 === $outbound_count ) {
				$dead_ends[] = array(
					'post_id'        => $post->ID,
					'title'          => $post->post_title,
					'outbound_links' => 0,
				);
			}
			if ( $outbound_count >= 5 ) {
				$hubs[] = array(
					'post_id'        => $post->ID,
					'title'          => $post->post_title,
					'outbound_links' => $outbound_count,
				);
			}
		}

		// Cross-linking suggestions: orphans that share categories with hubs.
		$suggestions = array();
		foreach ( array_slice( $orphans, 0, 10 ) as $orphan ) {
			$orphan_cats = wp_get_post_categories( $orphan['post_id'] );
			foreach ( $hubs as $hub ) {
				$hub_cats = wp_get_post_categories( $hub['post_id'] );
				if ( array_intersect( $orphan_cats, $hub_cats ) ) {
					$suggestions[] = "Link from \"{$hub['title']}\" to orphan \"{$orphan['title']}\" (shared category).";
					break;
				}
			}
		}

		return array(
			'posts_analysed' => count( $posts ),
			'orphan_pages'   => array_slice( $orphans, 0, 20 ),
			'orphan_count'   => count( $orphans ),
			'dead_end_pages' => array_slice( $dead_ends, 0, 20 ),
			'dead_end_count' => count( $dead_ends ),
			'hub_pages'      => array_slice( $hubs, 0, 10 ),
			'hub_count'      => count( $hubs ),
			'suggestions'    => array_slice( $suggestions, 0, 10 ),
		);
	}
}

return new Audit_Internal_Links();
