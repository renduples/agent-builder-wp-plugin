<?php
/**
 * Tool: find_posts_with_no_excerpt
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

class Find_Posts_With_No_Excerpt extends Tool_Base {
	public function get_name(): string {
		return 'find_posts_with_no_excerpt';
	}

	public function get_description(): string {
		return 'Find published posts that have no manual excerpt set. Useful for SEO audits, as meta descriptions often fall back to the excerpt.';
	}

	public function get_category(): string {
		return 'content';
	}

	public function get_parameters(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'post_type' => array(
					'type'        => 'string',
					'description' => 'Post type to check. Defaults to "post".',
				),
				'limit'     => array(
					'type'        => 'integer',
					'description' => 'Maximum number of posts to return. Defaults to 50.',
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
		$post_type = sanitize_key( $args['post_type'] ?? 'post' );
		$limit     = (int) ( $args['limit'] ?? 50 );
		$limit     = min( max( 1, $limit ), 500 );

		// Fetch in batches and filter in PHP since there's no native WP_Query meta for empty post_excerpt.
		$paged  = 1;
		$result = array();
		$total  = 0;

		do {
			$query = new \WP_Query(
				array(
					'post_type'      => $post_type,
					'post_status'    => 'publish',
					'posts_per_page' => 100,
					'paged'          => $paged,
				)
			);

			if ( 1 === $paged ) {
				$total = $query->found_posts;
			}

			foreach ( $query->posts as $post ) {
				if ( empty( $post->post_excerpt ) ) {
					if ( count( $result ) >= $limit ) {
						break 2;
					}

					$word_count = str_word_count( wp_strip_all_tags( $post->post_content ) );
					$result[]   = array(
						'ID'         => $post->ID,
						'title'      => $post->post_title,
						'permalink'  => get_permalink( $post->ID ),
						'word_count' => $word_count,
					);
				}
			}

			++$paged;
		} while ( $query->max_num_pages >= $paged );

		return array(
			'posts'       => $result,
			'total_found' => count( $result ),
		);
	}
}

return new Find_Posts_With_No_Excerpt();
