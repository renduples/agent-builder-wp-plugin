<?php
/**
 * Tool: update_post_content
 *
 * Update an existing post or page.
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
 * Update an existing post or page. Only supplied fields are changed.
 */
class Update_Post_Content extends \Agentic\Tool_Base {

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'update_post_content';
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return 'Update an existing post or page. Only the fields you supply are changed; omitted fields are left as-is. Always call get_post_content first to review the current content.';
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
				'post_id'    => array(
					'type'        => 'integer',
					'description' => 'The WordPress post ID.',
				),
				'title'      => array(
					'type'        => 'string',
					'description' => 'The post title.',
				),
				'content'    => array(
					'type'        => 'string',
					'description' => 'The post body in HTML or plain text.',
				),
				'excerpt'    => array(
					'type'        => 'string',
					'description' => 'Short summary.',
				),
				'status'     => array(
					'type'        => 'string',
					'description' => '"draft", "publish", "pending", or "private".',
				),
				'categories' => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'integer' ),
					'description' => 'Category IDs to assign.',
				),
				'tags'       => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => 'Tag names (created if they do not exist).',
				),
				'slug'       => array(
					'type'        => 'string',
					'description' => 'URL slug.',
				),
			),
			'required'   => array( 'post_id' ),
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Validated arguments from the LLM.
	 * @return array Result data.
	 */
	public function execute( array $arguments ): array {
		$post_id = (int) ( $arguments['post_id'] ?? 0 );
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return array( 'error' => 'Post not found.' );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return array( 'error' => 'You do not have permission to edit this post.' );
		}

		$post_data = array( 'ID' => $post_id );
		if ( isset( $arguments['title'] ) ) {
			$post_data['post_title'] = sanitize_text_field( $arguments['title'] );
		}
		if ( isset( $arguments['content'] ) ) {
			$post_data['post_content'] = wp_kses_post( $arguments['content'] );
			// Save block markup so the post opens as real blocks, not one Classic block.
			if ( \Agentic\Tool_Helpers::should_save_as_blocks( $post->post_type ) ) {
				$post_data['post_content'] = \Agentic\Tool_Helpers::to_block_markup( $post_data['post_content'] );
			}
		}
		if ( isset( $arguments['excerpt'] ) ) {
			$post_data['post_excerpt'] = sanitize_textarea_field( $arguments['excerpt'] );
		}
		if ( isset( $arguments['slug'] ) ) {
			$post_data['post_name'] = sanitize_title( $arguments['slug'] );
		}

		if ( isset( $arguments['status'] ) ) {
			$allowed    = array( 'draft', 'publish', 'pending', 'private' );
			$new_status = in_array( $arguments['status'], $allowed, true ) ? $arguments['status'] : null;
			if ( $new_status ) {
				if ( 'publish' === $new_status && ! current_user_can( 'publish_posts' ) ) {
					return array( 'error' => 'You do not have permission to publish posts.' );
				}
				$post_data['post_status'] = $new_status;
			}
		}

		$result = wp_update_post( $post_data, true );
		if ( is_wp_error( $result ) ) {
			return array( 'error' => $result->get_error_message() );
		}

		if ( isset( $arguments['categories'] ) && is_array( $arguments['categories'] ) ) {
			wp_set_post_categories( $post_id, array_map( 'intval', $arguments['categories'] ) );
		}
		if ( isset( $arguments['tags'] ) && is_array( $arguments['tags'] ) ) {
			wp_set_post_tags( $post_id, array_map( 'sanitize_text_field', $arguments['tags'] ) );
		}

		return array(
			'success' => true,
			'post_id' => $post_id,
			'status'  => get_post_status( $post_id ),
			'url'     => get_permalink( $post_id ),
			'message' => "Post ID {$post_id} updated successfully.",
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
			'idempotent'  => true,
		);
	}
}

return new Update_Post_Content();
