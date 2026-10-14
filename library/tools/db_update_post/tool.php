<?php
/**
 * Tool: db_update_post
 *
 * Update fields on an existing WordPress post.
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
 * Update an existing WordPress post, changing only the provided fields.
 */
class Db_Update_Post extends \Agentic\Tool_Base {

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'db_update_post';
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return 'Update an existing WordPress post. Only the fields you provide will be changed.';
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
				'post_id' => array(
					'type'        => 'integer',
					'description' => 'ID of the post to update.',
				),
				'title'   => array(
					'type'        => 'string',
					'description' => 'New post title.',
				),
				'content' => array(
					'type'        => 'string',
					'description' => 'New post content.',
				),
				'excerpt' => array(
					'type'        => 'string',
					'description' => 'New post excerpt.',
				),
				'status'  => array(
					'type'        => 'string',
					'description' => 'New post status (e.g., draft, pending, publish).',
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

		if ( $post_id < 1 ) {
			return array( 'error' => 'A valid post_id is required.' );
		}

		$post = get_post( $post_id );

		if ( ! $post ) {
			return array( 'error' => "Post {$post_id} not found." );
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

		if ( isset( $arguments['status'] ) ) {
			$post_data['post_status'] = sanitize_key( $arguments['status'] );
		}

		$result = wp_update_post( $post_data, true );

		if ( is_wp_error( $result ) ) {
			return array( 'error' => $result->get_error_message() );
		}

		$updated_post = get_post( $post_id );

		return array(
			'post_id'   => $post_id,
			'title'     => $updated_post->post_title,
			'status'    => $updated_post->post_status,
			'modified'  => $updated_post->post_modified,
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

return new Db_Update_Post();
