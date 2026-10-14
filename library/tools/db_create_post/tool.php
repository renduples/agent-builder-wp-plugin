<?php
/**
 * Tool: db_create_post
 *
 * Create a new WordPress post or page as a draft.
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
 * Create a new WordPress post or page, defaulting to draft status.
 */
class Db_Create_Post extends \Agentic\Tool_Base {

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'db_create_post';
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return 'Create a new WordPress post or page. Defaults to draft status for review before publishing.';
	}

	/**
	 * Get the tool category.
	 *
	 * @return string
	 */
	public function get_category(): string {
		return 'database';
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
				'title'     => array(
					'type'        => 'string',
					'description' => 'Post title.',
				),
				'content'   => array(
					'type'        => 'string',
					'description' => 'Post content (HTML or Gutenberg blocks).',
				),
				'post_type' => array(
					'type'        => 'string',
					'description' => 'Post type to create (e.g., post, page). Defaults to post.',
					'default'     => 'post',
				),
				'status'    => array(
					'type'        => 'string',
					'description' => 'Post status: draft or pending. Defaults to draft.',
					'default'     => 'draft',
				),
			),
			'required'   => array( 'title' ),
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Validated arguments from the LLM.
	 * @return array Result data.
	 */
	public function execute( array $arguments ): array {
		if ( empty( $arguments['title'] ) ) {
			return array( 'error' => 'Title is required.' );
		}

		$allowed_statuses = array( 'draft', 'pending' );
		$status           = sanitize_key( $arguments['status'] ?? 'draft' );

		if ( ! in_array( $status, $allowed_statuses, true ) ) {
			$status = 'draft';
		}

		$post_data = array(
			'post_title'   => sanitize_text_field( $arguments['title'] ),
			'post_content' => wp_kses_post( $arguments['content'] ?? '' ),
			'post_type'    => sanitize_key( $arguments['post_type'] ?? 'post' ),
			'post_status'  => $status,
		);

		// Save block markup so the post opens as real blocks, not one Classic block.
		if ( \Agentic\Tool_Helpers::should_save_as_blocks( $post_data['post_type'] ) ) {
			$post_data['post_content'] = \Agentic\Tool_Helpers::to_block_markup( $post_data['post_content'] );
		}

		$post_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $post_id ) ) {
			return array( 'error' => $post_id->get_error_message() );
		}

		return array(
			'post_id'   => $post_id,
			'title'     => $post_data['post_title'],
			'status'    => $post_data['post_status'],
			'type'      => $post_data['post_type'],
			'edit_link' => get_edit_post_link( $post_id, 'raw' ),
		);
	}

	/**
	 * Get tool annotations.
	 *
	 * @return array
	 */
	public function get_annotations(): array {
		return array(
			'destructive' => false,
		);
	}
}

return new Db_Create_Post();
