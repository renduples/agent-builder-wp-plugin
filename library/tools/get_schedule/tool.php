<?php
/**
 * Tool: get_schedule
 *
 * List scheduled (future) posts and drafts with their publish dates,
 * giving a calendar view of the content pipeline.
 *
 * @package    Agent_Builder
 * @subpackage Tools
 * @since      2.7.0
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get a content schedule: scheduled posts, recent drafts, and publishing gaps.
 */
class Get_Schedule extends \Agentic\Tool_Base {

	public function get_name(): string {
		return 'get_schedule';
	}

	public function get_description(): string {
		return 'Return a content schedule showing scheduled (future) posts and recent drafts. Includes the number of days since the last published post and any publishing gaps. Use this to review the content pipeline, find quiet periods, or plan upcoming publications.';
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
					'description' => 'Post type to query: "post" (default), "page", or a custom post type slug.',
				),
				'limit'       => array(
					'type'        => 'integer',
					'description' => 'Max items per status group (default 20, max 50).',
				),
				'date_after'  => array(
					'type'        => 'string',
					'description' => 'Only return scheduled posts on or after this date (YYYY-MM-DD).',
				),
				'date_before' => array(
					'type'        => 'string',
					'description' => 'Only return scheduled posts on or before this date (YYYY-MM-DD).',
				),
			),
		);
	}

	public function execute( array $arguments ): array {
		$post_type = sanitize_key( $arguments['post_type'] ?? 'post' );
		if ( ! post_type_exists( $post_type ) ) {
			$post_type = 'post';
		}
		$limit = min( max( (int) ( $arguments['limit'] ?? 20 ), 1 ), 50 );

		// Scheduled posts.
		$future_args = array(
			'post_type'      => $post_type,
			'post_status'    => 'future',
			'posts_per_page' => $limit,
			'orderby'        => 'date',
			'order'          => 'ASC',
		);

		$date_after  = sanitize_text_field( $arguments['date_after'] ?? '' );
		$date_before = sanitize_text_field( $arguments['date_before'] ?? '' );

		if ( $date_after || $date_before ) {
			$dq = array( 'inclusive' => true );
			if ( $date_after ) {
				$dq['after'] = $date_after;
			}
			if ( $date_before ) {
				$dq['before'] = $date_before;
			}
			$future_args['date_query'] = array( $dq );
		}

		$future_posts = get_posts( $future_args );

		// Recent drafts (last 30 days).
		$drafts = get_posts(
			array(
				'post_type'      => $post_type,
				'post_status'    => 'draft',
				'posts_per_page' => $limit,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'date_query'     => array(
					array(
						'after'     => '30 days ago',
						'inclusive' => true,
						'column'    => 'post_modified',
					),
				),
			)
		);

		// Last published post date (to surface gaps).
		$last_published = get_posts(
			array(
				'post_type'      => $post_type,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		$days_since_last   = null;
		$last_publish_date = null;
		if ( ! empty( $last_published ) ) {
			$last_publish_date = $last_published[0]->post_date;
			$diff              = ( new \DateTime() )->diff( new \DateTime( $last_publish_date ) );
			$days_since_last   = (int) $diff->days;
		}

		$format_post = static function ( \WP_Post $p ) use ( $post_type ): array {
			return array(
				'id'       => $p->ID,
				'title'    => $p->post_title ? $p->post_title : '(no title)',
				'status'   => $p->post_status,
				'date'     => $p->post_date,
				'modified' => $p->post_modified,
				'url'      => get_edit_post_link( $p->ID, 'raw' ) ?? '',
			);
		};

		return array(
			'post_type'               => $post_type,
			'days_since_last_publish' => $days_since_last,
			'last_published_date'     => $last_publish_date,
			'scheduled_count'         => count( $future_posts ),
			'scheduled'               => array_map( $format_post, $future_posts ),
			'recent_drafts_count'     => count( $drafts ),
			'recent_drafts'           => array_map( $format_post, $drafts ),
		);
	}

	public function get_annotations(): array {
		return array(
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		);
	}
}

return new Get_Schedule();
