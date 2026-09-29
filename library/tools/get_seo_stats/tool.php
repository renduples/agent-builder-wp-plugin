<?php
/**
 * Tool: Get SEO Stats
 *
 * Audit SEO fundamentals: missing titles, meta descriptions, H1 issues,
 * alt text coverage, noindex pages, SEO plugin detection, orphan pages,
 * and schema types.
 *
 * @package Agentic\Tools
 */

declare(strict_types=1);

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Get_Seo_Stats extends \Agentic\Tool_Base {

	public function get_name(): string {
		return 'get_seo_stats';
	}

	public function get_description(): string {
		return 'Audit SEO fundamentals: missing titles, meta descriptions, H1 issues, alt text coverage, noindex pages, SEO plugin detection, orphan pages, and schema types.';
	}

	public function get_category(): string {
		return 'site-audit';
	}

	public function get_parameters(): array {
		return array(
			'type'       => 'object',
			'properties' => array(),
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
		$posts = get_posts(
			array(
				'post_type'      => array( 'post', 'page' ),
				'post_status'    => 'publish',
				'posts_per_page' => -1,
			)
		);
		$total = count( $posts );

		$missing_titles     = 0;
		$missing_meta       = 0;
		$pages_no_h1        = 0;
		$pages_multiple_h1  = 0;
		$orphaned_count     = 0;
		$images_missing_alt = 0;
		$noindex_count      = 0;

		// SEO title meta keys — check all known sources in priority order.
		$title_meta_keys = array( '_agentic_seo_title', 'rank_math_title', '_yoast_wpseo_title' );

		// Robots/noindex meta keys.
		$robots_meta_keys = array(
			'_agentic_robots'                  => 'string',   // contains 'noindex'
			'rank_math_robots'                 => 'array',    // array with 'noindex' element
			'_yoast_wpseo_meta-robots-noindex' => 'boolean',  // '1' = noindex
		);

		// Schema detection: fetch actual rendered HTML (what search engines see).
		$schema_types = array();
		$rendered     = \Agentic\Page_Renderer::fetch( home_url( '/' ) );
		if ( $rendered['success'] ) {
			$schema_types = $rendered['meta']['schema_types'] ?? array();
		}

		// Build internal link map for orphan detection.
		$internal_link_targets = array();
		$post_ids              = wp_list_pluck( $posts, 'ID' );

		foreach ( $posts as $post ) {
			// Title — check all SEO meta keys, then post title.
			$title = '';
			foreach ( $title_meta_keys as $key ) {
				$val = get_post_meta( $post->ID, $key, true );
				if ( ! empty( $val ) ) {
					$title = $val;
					break;
				}
			}
			if ( empty( $title ) ) {
				$title = $post->post_title;
			}
			if ( empty( $title ) ) {
				++$missing_titles;
			}

			// Meta description — plugin-agnostic helper.
			$meta = \Agentic\Tool_Helpers::get_meta_description( $post->ID );
			if ( empty( $meta ) ) {
				++$missing_meta;
			}

			// noindex — check all known meta keys.
			foreach ( $robots_meta_keys as $key => $format ) {
				$val = get_post_meta( $post->ID, $key, true );
				if ( empty( $val ) ) {
					continue;
				}
				if ( 'string' === $format && str_contains( (string) $val, 'noindex' ) ) {
					++$noindex_count;
					break;
				}
				if ( 'array' === $format && in_array( 'noindex', (array) $val, true ) ) {
					++$noindex_count;
					break;
				}
				if ( 'boolean' === $format && '1' == $val ) {
					++$noindex_count;
					break;
				}
			}

			// Heading H1 count.
			preg_match_all( '/<h1[^>]*>/i', $post->post_content, $h1_matches );
			$h1_count = count( $h1_matches[0] );
			if ( 0 === $h1_count ) {
				++$pages_no_h1;
			} elseif ( $h1_count > 1 ) {
				++$pages_multiple_h1;
			}

			// Images missing alt.
			preg_match_all( '/<img[^>]+>/i', $post->post_content, $img_matches );
			foreach ( $img_matches[0] as $img ) {
				if ( ! preg_match( '/alt=["\'][^"\']+["\']/', $img ) ) {
					++$images_missing_alt;
				}
			}

			// Internal links for orphan detection.
			preg_match_all( '/href=["\']' . preg_quote( home_url(), '/' ) . '[^"\']*["\']/', $post->post_content, $link_matches );
			foreach ( $link_matches[0] as $lm ) {
				preg_match( '/href=["\']([^"\']+)["\']/', $lm, $href );
				if ( ! empty( $href[1] ) ) {
					$internal_link_targets[] = $href[1];
				}
			}
		}

		// Orphan count: pages with no internal links pointing to them.
		foreach ( $posts as $post ) {
			$is_linked = false;
			foreach ( $internal_link_targets as $target ) {
				if ( str_contains( $target, $post->post_name ) ) {
					$is_linked = true;
					break;
				}
			}
			if ( ! $is_linked && intval( get_option( 'page_on_front' ) ) !== $post->ID ) {
				++$orphaned_count;
			}
		}

		$permalink = get_option( 'permalink_structure' );
		$permalink = $permalink ? $permalink : 'default';

		return array(
			'total_pages'               => $total,
			'missing_titles'            => $missing_titles,
			'missing_meta_descriptions' => $missing_meta,
			'pages_no_h1'               => $pages_no_h1,
			'pages_multiple_h1'         => $pages_multiple_h1,
			'orphaned_pages'            => $orphaned_count,
			'images_missing_alt'        => $images_missing_alt,
			'noindex_page_count'        => $noindex_count,
			'search_discouraged'        => ! (bool) get_option( 'blog_public', 1 ),
			'permalink_structure'       => $permalink,
			'schema_types_detected'     => $schema_types,
		);
	}
}

return new Get_Seo_Stats();
