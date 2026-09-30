<?php
/**
 * Tool: get_post_reading_time
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

class Get_Post_Reading_Time extends Tool_Base {
	public function get_name(): string {
		return 'get_post_reading_time';
	}

	public function get_description(): string {
		return 'Calculate the estimated reading time for a post based on its word count, using an average reading speed of 200 words per minute.';
	}

	public function get_category(): string {
		return 'content';
	}

	public function get_parameters(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'post_id' => array(
					'type'        => 'integer',
					'description' => 'ID of the post to calculate reading time for.',
				),
			),
			'required'   => array( 'post_id' ),
		);
	}

	public function get_annotations(): array {
		return array(
			'read_only'   => true,
			'destructive' => false,
		);
	}

	public function execute( array $args ): array {
		$post_id = (int) ( $args['post_id'] ?? 0 );

		if ( ! $post_id ) {
			return array( 'error' => 'post_id is required.' );
		}

		$content = get_post_field( 'post_content', $post_id );
		if ( is_wp_error( $content ) || '' === $content ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				return array( 'error' => 'Post not found.' );
			}
			$content = '';
		}

		$plain_text   = wp_strip_all_tags( $content );
		$word_count   = str_word_count( $plain_text );
		$minutes      = (int) ceil( $word_count / 200 );
		$minutes      = max( 1, $minutes );
		$reading_time = 1 === $minutes ? '1 min read' : "{$minutes} min read";

		return array(
			'post_id'              => $post_id,
			'post_title'           => get_the_title( $post_id ),
			'word_count'           => $word_count,
			'reading_time_minutes' => $minutes,
			'reading_time_label'   => $reading_time,
		);
	}
}

return new Get_Post_Reading_Time();
