<?php
/**
 * Tool: Analyze Topic Coverage
 *
 * Map topic clusters by analysing categories, tags, and content distribution.
 * Identifies coverage gaps, orphan topics, and suggests new content areas.
 *
 * @package Agentic\Tools
 */

declare(strict_types=1);

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Analyze_Topic_Coverage extends \Agentic\Tool_Base {

	public function get_name(): string {
		return 'analyze_topic_coverage';
	}

	public function get_description(): string {
		return 'Map topic clusters by analysing categories, tags, and content distribution. Identifies coverage gaps, orphan topics, and suggests new content areas.';
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
		$categories = get_categories( array( 'hide_empty' => false ) );
		$tags       = get_tags( array( 'hide_empty' => false ) );

		$cat_results = array();
		$weak_cats   = array();
		$orphan_tags = array();

		foreach ( $categories as $cat ) {
			$entry         = array(
				'name'       => $cat->name,
				'slug'       => $cat->slug,
				'post_count' => $cat->count,
				'status'     => $cat->count >= 5 ? 'strong' : ( $cat->count >= 2 ? 'developing' : 'weak' ),
			);
			$cat_results[] = $entry;
			if ( $cat->count < 3 ) {
				$weak_cats[] = array(
					'name'  => $cat->name,
					'count' => $cat->count,
				);
			}
		}

		foreach ( $tags as $tag ) {
			if ( $tag->count <= 1 ) {
				$orphan_tags[] = array(
					'name'  => $tag->name,
					'count' => $tag->count,
				);
			}
		}

		// Content gap suggestions.
		$strong_cats = array_filter( $cat_results, fn( $c ) => 'strong' === $c['status'] );
		$suggestions = array();
		foreach ( $weak_cats as $wc ) {
			$suggestions[] = "Write 3-5 more posts in \"{$wc['name']}\" to build topical authority (currently {$wc['count']} posts).";
		}
		if ( count( $orphan_tags ) > 10 ) {
			$suggestions[] = count( $orphan_tags ) . ' tags used only once — consolidate or remove to reduce tag bloat.';
		}

		return array(
			'total_categories' => count( $categories ),
			'total_tags'       => count( $tags ),
			'categories'       => $cat_results,
			'weak_categories'  => $weak_cats,
			'orphan_tags'      => array_slice( $orphan_tags, 0, 20 ),
			'orphan_tag_count' => count( $orphan_tags ),
			'strong_clusters'  => count( $strong_cats ),
			'suggestions'      => $suggestions,
		);
	}
}

return new Analyze_Topic_Coverage();
