<?php
/**
 * Tool: create_post_content
 *
 * Create a new WordPress post or page.
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
 * Create a new WordPress post or page. Saves as draft by default.
 */
class Create_Post_Content extends \Agentic\Tool_Base {

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'create_post_content';
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return 'Create a new WordPress post or page. Saves as draft by default. Always confirm with the user before publishing.';
	}

	/**
	 * Get the tool category.
	 *
	 * @return string
	 */
	public function get_category(): string {
		return 'content';
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
				'title'        => array(
					'type'        => 'string',
					'description' => 'The post title.',
				),
				'content'      => array(
					'type'        => 'string',
					'description' => 'The post body in HTML or plain text.',
				),
				'excerpt'      => array(
					'type'        => 'string',
					'description' => 'Short summary (150–160 characters recommended).',
				),
				'status'       => array(
					'type'        => 'string',
					'description' => '"draft" (default), "publish", or "future" (requires publish_date).',
				),
				'publish_date' => array(
					'type'        => 'string',
					'description' => 'Schedule publication date in Y-m-d H:i:s format (e.g. "2026-04-01 09:00:00"). Only used when status is "future".',
				),
				'post_type'    => array(
					'type'        => 'string',
					'description' => '"post" (default) or "page".',
				),
				'author_id'    => array(
					'type'        => 'integer',
					'description' => 'WordPress user ID. Omit to use current user.',
				),
				'categories'   => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'integer' ),
					'description' => 'Category IDs to assign.',
				),
				'tags'         => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => 'Tag names (created if they do not exist).',
				),
				'slug'         => array(
					'type'        => 'string',
					'description' => 'URL slug.',
				),
			),
			'required'   => array( 'title', 'content' ),
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Validated arguments from the LLM.
	 * @return array Result data.
	 */
	public function execute( array $arguments ): array {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return array( 'error' => 'You do not have permission to create posts.' );
		}

		$post_type = in_array( $arguments['post_type'] ?? 'post', array( 'post', 'page' ), true ) ? ( $arguments['post_type'] ?? 'post' ) : 'post';
		$status    = in_array( $arguments['status'] ?? 'draft', array( 'draft', 'publish', 'pending', 'private', 'future' ), true ) ? ( $arguments['status'] ?? 'draft' ) : 'draft';

		if ( in_array( $status, array( 'publish', 'future' ), true ) && ! current_user_can( 'publish_posts' ) ) {
			return array( 'error' => 'You do not have permission to publish posts.' );
		}

		if ( 'future' === $status && empty( $arguments['publish_date'] ) ) {
			return array( 'error' => 'publish_date is required when status is "future". Use Y-m-d H:i:s format.' );
		}

		$post_data = array(
			'post_title'   => sanitize_text_field( $arguments['title'] ),
			'post_content' => wp_kses_post( $arguments['content'] ),
			'post_status'  => $status,
			'post_type'    => $post_type,
		);

		if ( 'future' === $status && ! empty( $arguments['publish_date'] ) ) {
			$post_data['post_date']     = sanitize_text_field( $arguments['publish_date'] );
			$post_data['post_date_gmt'] = get_gmt_from_date( $arguments['publish_date'] );
		}
		if ( ! empty( $arguments['excerpt'] ) ) {
			$post_data['post_excerpt'] = sanitize_textarea_field( $arguments['excerpt'] );
		}
		if ( ! empty( $arguments['slug'] ) ) {
			$post_data['post_name'] = sanitize_title( $arguments['slug'] );
		}
		if ( ! empty( $arguments['author_id'] ) ) {
			$post_data['post_author'] = (int) $arguments['author_id'];
		}

		$post_id = wp_insert_post( $post_data, true );
		if ( is_wp_error( $post_id ) ) {
			return array( 'error' => $post_id->get_error_message() );
		}

		if ( ! empty( $arguments['categories'] ) && is_array( $arguments['categories'] ) ) {
			wp_set_post_categories( $post_id, array_map( 'intval', $arguments['categories'] ) );
		}
		if ( ! empty( $arguments['tags'] ) && is_array( $arguments['tags'] ) ) {
			wp_set_post_tags( $post_id, array_map( 'sanitize_text_field', $arguments['tags'] ) );
		}

		return array(
			'success'  => true,
			'post_id'  => $post_id,
			'status'   => $status,
			// The sanitized input, not get_the_title(): get_the_title() runs the
			// the_title filter (wptexturize), which turns " - ", quotes and "&"
			// into HTML entities. The chat UI HTML-escapes the result a second
			// time, so those entities surfaced literally (e.g. "&#8211;").
			'title'    => $post_data['post_title'],
			'url'      => get_permalink( $post_id ),
			'edit_url' => get_edit_post_link( $post_id, 'raw' ),
			'message'  => match ( $status ) {
				'publish' => 'Post published at ' . get_permalink( $post_id ),
				'future'  => 'Post scheduled for ' . ( $arguments['publish_date'] ?? '' ) . ' at ' . get_permalink( $post_id ),
				default   => "Draft saved (ID: {$post_id}).",
			},
		);
	}

	/**
	 * Get tool annotations.
	 *
	 * @return array
	 */
	public function get_annotations(): array {
		return array(
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		);
	}
}

return new Create_Post_Content();
