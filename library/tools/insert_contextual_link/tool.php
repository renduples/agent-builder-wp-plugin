<?php
/**
 * Tool: insert_contextual_link
 *
 * Find an unlinked phrase in a post and wrap it in an anchor tag.
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
 * Find an unlinked phrase in a post's content and wrap it in an anchor tag.
 */
class Insert_Contextual_Link extends \Agentic\Tool_Base {

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'insert_contextual_link';
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return 'Find an unlinked phrase in a post\'s content and wrap it in an anchor tag. If the exact anchor text is not found, returns up to 10 linkable phrases from the post content so you can choose an alternative. Only links the first unlinked occurrence.';
	}

	/**
	 * Get the tool category.
	 *
	 * @return string
	 */
	public function get_category(): string {
		return 'seo';
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
				'post_id'     => array(
					'type'        => 'integer',
					'description' => 'The WordPress post ID to edit.',
				),
				'anchor_text' => array(
					'type'        => 'string',
					'description' => 'The exact text to find and linkify (case-insensitive match).',
				),
				'target_url'  => array(
					'type'        => 'string',
					'description' => 'The URL to link to (relative or absolute).',
				),
			),
			'required'   => array( 'post_id', 'anchor_text', 'target_url' ),
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Validated arguments from the LLM.
	 * @return array Result data.
	 */
	public function execute( array $arguments ): array {
		$post_id     = (int) ( $arguments['post_id'] ?? 0 );
		$anchor_text = $arguments['anchor_text'] ?? '';
		$target_url  = $arguments['target_url'] ?? '';

		if ( empty( $anchor_text ) || empty( $target_url ) ) {
			return array( 'error' => 'Both anchor_text and target_url are required.' );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return array( 'error' => 'Post not found.' );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return array( 'error' => 'You do not have permission to edit this post.' );
		}

		$content        = $post->post_content;
		$escaped_anchor = preg_quote( $anchor_text, '/' );

		if ( preg_match( '/<a[^>]*>[^<]*' . $escaped_anchor . '[^<]*<\/a>/i', $content ) ) {
			return array(
				'error'   => 'The text "' . $anchor_text . '" is already linked in this post.',
				'post_id' => $post_id,
			);
		}

		$safe_url    = esc_url( $target_url );
		$pattern     = '/(?<![<\/a-zA-Z])(' . $escaped_anchor . ')(?![^<]*<\/a>)(?![^<]*>)/i';
		$new_content = preg_replace( $pattern, '<a href="' . $safe_url . '">$1</a>', $content, 1, $count );

		if ( 0 === $count ) {
			$linkable  = \Agentic\Tool_Helpers::extract_linkable_phrases( $content );
			$available = array_merge(
				$linkable['headings'] ?? array(),
				$linkable['bold_text'] ?? array(),
				$linkable['word_pairs'] ?? array()
			);

			return array(
				'error'             => 'Could not find the text "' . $anchor_text . '" in the post content.',
				'post_id'           => $post_id,
				'title'             => $post->post_title,
				'available_phrases' => array_slice( $available, 0, 10 ),
				'hint'              => 'Try one of the available phrases above as anchor_text instead.',
			);
		}

		$result = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $new_content,
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			return array( 'error' => $result->get_error_message() );
		}

		return array(
			'success'     => true,
			'post_id'     => $post_id,
			'title'       => $post->post_title,
			'anchor_text' => $anchor_text,
			'target_url'  => $safe_url,
			'url'         => get_permalink( $post_id ),
			'message'     => sprintf( 'Linked "%s" → %s in "%s".', $anchor_text, $safe_url, $post->post_title ),
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

return new Insert_Contextual_Link();
