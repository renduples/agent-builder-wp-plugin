<?php
/**
 * Tool: add_faq_schema
 *
 * Extract Q&A pairs from a post and save FAQPage JSON-LD schema.
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
 * Extract Q&A pairs from post content and save FAQPage JSON-LD schema markup.
 */
class Add_Faq_Schema extends \Agentic\Tool_Base {

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'add_faq_schema';
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return 'Extract Q&A pairs from a post\'s content (headings phrased as questions + answer paragraphs) and save FAQPage JSON-LD schema. Use dry_run=true to preview the extracted Q&A pairs first.';
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
				'post_id' => array(
					'type'        => 'integer',
					'description' => 'The WordPress post ID to extract FAQ schema from.',
				),
				'dry_run' => array(
					'type'        => 'boolean',
					'description' => 'If true, returns the extracted Q&A pairs without saving. Defaults to true.',
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
		$dry_run = (bool) ( $arguments['dry_run'] ?? true );
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return array( 'error' => 'Post not found.' );
		}

		$seo_keys = \Agentic\Tool_Helpers::get_seo_meta_keys();

		$content  = $post->post_content;
		$qa_pairs = array();
		$parts    = preg_split( '/(<h[23][^>]*>.*?<\/h[23]>)/is', $content, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );

		$parts_count = count( $parts );
		for ( $i = 0; $i < $parts_count; $i++ ) {
			if ( preg_match( '/<h[23][^>]*>(.*?)<\/h[23]>/is', $parts[ $i ], $heading_match ) ) {
				$question = trim( wp_strip_all_tags( $heading_match[1] ) );
				if ( str_contains( $question, '?' ) || preg_match( '/^(?:what|how|why|when|where|who|which|can|does|is|are|do|should|will|would)\b/i', $question ) ) {
					$answer = '';
					if ( isset( $parts[ $i + 1 ] ) && ! preg_match( '/^<h[23]/i', trim( $parts[ $i + 1 ] ) ) ) {
						$answer = trim( wp_strip_all_tags( $parts[ $i + 1 ] ) );
					}
					if ( ! empty( $answer ) && mb_strlen( $answer ) > 10 ) {
						$qa_pairs[] = array(
							'question' => $question,
							'answer'   => $answer,
						);
					}
				}
			}
		}

		if ( empty( $qa_pairs ) ) {
			return array(
				'post_id'  => $post_id,
				'title'    => $post->post_title,
				'qa_found' => 0,
				'message'  => 'No Q&A pairs detected. Ensure the post has H2 or H3 headings phrased as questions (with ? or starting with What/How/Why) followed by answer paragraphs.',
			);
		}

		$faq_schema = array(
			'@context'   => 'https://schema.org',
			'@type'      => 'FAQPage',
			'mainEntity' => array_map(
				fn( $qa ) => array(
					'@type'          => 'Question',
					'name'           => $qa['question'],
					'acceptedAnswer' => array(
						'@type' => 'Answer',
						'text'  => $qa['answer'],
					),
				),
				$qa_pairs
			),
		);

		$result = array(
			'post_id'  => $post_id,
			'title'    => $post->post_title,
			'qa_found' => count( $qa_pairs ),
			'qa_pairs' => $qa_pairs,
			'schema'   => $faq_schema,
			'dry_run'  => $dry_run,
		);

		if ( ! $dry_run ) {
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return array( 'error' => 'You do not have permission to edit this post.' );
			}
			update_post_meta( $post_id, $seo_keys['schema'], wp_json_encode( $faq_schema ) );
			$result['saved']   = true;
			$result['message'] = 'FAQ schema saved for post ' . $post_id . '. Google may take days to pick up the change.';
		} else {
			$result['message'] = 'Preview mode — schema not saved. Call again with dry_run=false to save.';
		}

		return $result;
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

return new Add_Faq_Schema();
