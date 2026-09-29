<?php
/**
 * Tool: find_posts_without_featured_image
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

class Find_Posts_Without_Featured_Image extends Tool_Base {
	public function get_name(): string {
		return 'find_posts_without_featured_image';
	}

	public function get_description(): string {
		return 'Find published posts that do not have a featured image set. Useful for content audits before launching a campaign or improving visual consistency.';
	}

	public function get_category(): string {
		return 'content';
	}

	public function get_parameters(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'post_type'   => array(
					'type'        => 'string',
					'description' => 'Post type to check. Defaults to "post".',
				),
				'post_status' => array(
					'type'        => 'string',
					'description' => 'Post status to filter by. Defaults to "publish".',
				),
				'limit'       => array(
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
		$post_type   = sanitize_key( $args['post_type'] ?? 'post' );
		$post_status = sanitize_key( $args['post_status'] ?? 'publish' );
		$limit       = (int) ( $args['limit'] ?? 50 );
		$limit       = min( max( 1, $limit ), 500 );

		$query = new \WP_Query(
			array(
				'post_type'      => $post_type,
				'post_status'    => $post_status,
				'posts_per_page' => $limit,
				'fields'         => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query'     => array(
					array(
						'key'     => '_thumbnail_id',
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		$posts = array();
		foreach ( $query->posts as $post_id ) {
			$post    = get_post( $post_id );
			$posts[] = array(
				'ID'        => $post_id,
				'title'     => $post->post_title,
				'permalink' => get_permalink( $post_id ),
				'post_date' => $post->post_date,
			);
		}

		return array(
			'posts'       => $posts,
			'total_found' => $query->found_posts,
			'showing'     => count( $posts ),
			'post_type'   => $post_type,
			'post_status' => $post_status,
		);
	}
}

return new Find_Posts_Without_Featured_Image();
