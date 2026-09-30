<?php
/**
 * Tool: cleanup_post_revisions
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

class Cleanup_Post_Revisions extends Tool_Base {
	public function get_name(): string {
		return 'cleanup_post_revisions';
	}

	public function get_description(): string {
		return 'Delete old post revisions, keeping only the most recent N revisions per post. Can target a single post or all posts. Supports dry_run mode to count without deleting.';
	}

	public function get_category(): string {
		return 'content';
	}

	public function get_parameters(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'post_id'     => array(
					'type'        => 'integer',
					'description' => 'If provided, only clean up revisions for this post. Omit to process all posts.',
				),
				'keep_latest' => array(
					'type'        => 'integer',
					'description' => 'Number of most recent revisions to keep per post. Defaults to 5.',
				),
				'dry_run'     => array(
					'type'        => 'boolean',
					'description' => 'If true, count revisions that would be deleted without actually deleting them. Defaults to false.',
				),
			),
			'required'   => array(),
		);
	}

	public function get_annotations(): array {
		return array(
			'read_only'   => false,
			'destructive' => true,
		);
	}

	public function execute( array $args ): array {
		$post_id     = isset( $args['post_id'] ) ? (int) $args['post_id'] : null;
		$keep_latest = (int) ( $args['keep_latest'] ?? 5 );
		$dry_run     = (bool) ( $args['dry_run'] ?? false );
		$keep_latest = max( 1, $keep_latest );

		$deleted_count  = 0;
		$posts_affected = 0;

		if ( $post_id ) {
			$post_ids = array( $post_id );
		} else {
			$post_ids = get_posts(
				array(
					'post_type'      => 'any',
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude
					'exclude'        => array(),
				)
			);
		}

		foreach ( $post_ids as $pid ) {
			$revisions = wp_get_post_revisions(
				$pid,
				array(
					'numberposts' => -1,
					'order'       => 'DESC',
					'orderby'     => 'date',
				)
			);

			if ( count( $revisions ) <= $keep_latest ) {
				continue;
			}

			$to_delete = array_slice( $revisions, $keep_latest );
			if ( ! empty( $to_delete ) ) {
				++$posts_affected;
			}

			foreach ( $to_delete as $revision ) {
				if ( ! $dry_run ) {
					wp_delete_post_revision( $revision->ID );
				}
				++$deleted_count;
			}
		}

		return array(
			'deleted_count'  => $deleted_count,
			'posts_affected' => $posts_affected,
			'keep_latest'    => $keep_latest,
			'dry_run'        => $dry_run,
			'scope'          => $post_id ? "post #{$post_id}" : 'all posts',
		);
	}
}

return new Cleanup_Post_Revisions();
