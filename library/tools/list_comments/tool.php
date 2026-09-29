<?php
/**
 * Tool: list_comments
 *
 * List WordPress comments with filtering by status, post, or author.
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
 * List WordPress comments with filtering.
 */
class List_Comments extends \Agentic\Tool_Base {

	public function get_name(): string {
		return 'list_comments';
	}

	public function get_description(): string {
		return 'List WordPress comments filtered by status, post ID, or author. Returns comment content, author, date, status, and the post it belongs to. Use to review pending comments, find spam, or audit comment activity.';
	}

	public function get_category(): string {
		return 'content';
	}

	public function get_parameters(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'status'  => array(
					'type'        => 'string',
					'description' => 'Filter by status: "approve" (approved), "hold" (pending), "spam", "trash", or "all". Defaults to "hold" to surface pending comments.',
				),
				'post_id' => array(
					'type'        => 'integer',
					'description' => 'Filter by a specific post ID.',
				),
				'search'  => array(
					'type'        => 'string',
					'description' => 'Search in comment content, author name, or email.',
				),
				'limit'   => array(
					'type'        => 'integer',
					'description' => 'Max results (default 20, max 50).',
				),
				'offset'  => array(
					'type'        => 'integer',
					'description' => 'Pagination offset. Defaults to 0.',
				),
			),
		);
	}

	public function execute( array $arguments ): array {
		$status = sanitize_key( $arguments['status'] ?? 'hold' );
		$limit  = min( max( (int) ( $arguments['limit'] ?? 20 ), 1 ), 50 );
		$offset = max( (int) ( $arguments['offset'] ?? 0 ), 0 );

		$valid_statuses = array( 'approve', 'hold', 'spam', 'trash', 'all' );
		if ( ! in_array( $status, $valid_statuses, true ) ) {
			$status = 'hold';
		}

		// Approved comments are already public on the site — no extra
		// capability needed. Anything else (pending/spam/trash/all) is the
		// moderation queue, which requires moderate_comments (editor+), not
		// just the tool-generic edit_posts floor a caller (e.g. a Contributor
		// over WebMCP) might otherwise only need.
		if ( 'approve' !== $status && ! current_user_can( 'moderate_comments' ) ) {
			return array( 'error' => 'You do not have permission to view comments in this status.' );
		}

		$args = array(
			'status'  => $status,
			'number'  => $limit,
			'offset'  => $offset,
			'orderby' => 'comment_date',
			'order'   => 'DESC',
		);

		if ( ! empty( $arguments['post_id'] ) ) {
			$args['post_id'] = (int) $arguments['post_id'];
		}
		if ( ! empty( $arguments['search'] ) ) {
			$args['search'] = sanitize_text_field( $arguments['search'] );
		}

		$comments = get_comments( $args );
		$total    = (int) get_comments(
			array_merge(
				$args,
				array(
					'count'  => true,
					'number' => 0,
					'offset' => 0,
				)
			)
		);

		$results = array();
		foreach ( $comments as $comment ) {
			$post_title = get_the_title( $comment->comment_post_ID );
			$results[]  = array(
				'id'           => (int) $comment->comment_ID,
				'post_id'      => (int) $comment->comment_post_ID,
				'post_title'   => $post_title ? $post_title : '(no title)',
				'author'       => $comment->comment_author,
				'author_email' => $comment->comment_author_email,
				'author_url'   => $comment->comment_author_url,
				'content'      => wp_trim_words( $comment->comment_content, 40 ),
				'date'         => $comment->comment_date,
				'status'       => wp_get_comment_status( $comment->comment_ID ),
				'parent'       => (int) $comment->comment_parent,
				'edit_link'    => admin_url( "comment.php?action=editcomment&c={$comment->comment_ID}" ),
			);
		}

		return array(
			'status'         => $status,
			'total'          => $total,
			'total_returned' => count( $results ),
			'offset'         => $offset,
			'comments'       => $results,
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

return new List_Comments();
