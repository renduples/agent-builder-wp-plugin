<?php
/**
 * Tool: analyze_post
 *
 * Analyse a post for readability, word count, heading structure, links, and SEO keyword density.
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
 * Analyse a post for readability, word count, heading structure, links, and SEO keyword density.
 */
class Analyze_Post extends \Agentic\Tool_Base {

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'analyze_post';
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return 'Analyse a post for readability (Flesch-Kincaid score), word count, sentence length, heading structure, image presence, internal/external links, and SEO keyword density. Returns actionable recommendations.';
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
				'post_id'       => array(
					'type'        => 'integer',
					'description' => 'The WordPress post ID.',
				),
				'focus_keyword' => array(
					'type'        => 'string',
					'description' => 'Optional focus keyword to measure density and placement.',
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
		$post = $this->get_viewable_post( (int) ( $arguments['post_id'] ?? 0 ) );
		if ( ! $post ) {
			return array( 'error' => 'Post not found.' );
		}

		$raw_content = $post->post_content;
		$plain_text  = wp_strip_all_tags( $raw_content );
		$word_count  = str_word_count( $plain_text );
		$sentences   = preg_split( '/[.!?]+(?:\s|$)/', $plain_text, -1, PREG_SPLIT_NO_EMPTY );
		$sent_count  = max( 1, count( $sentences ) );

		$syllable_count = \Agentic\Tool_Helpers::count_syllables( $plain_text );
		$fk_score       = 0;
		if ( $word_count > 0 ) {
			$fk_score = 206.835 - ( 1.015 * ( $word_count / $sent_count ) ) - ( 84.6 * ( $syllable_count / $word_count ) );
			$fk_score = round( $fk_score, 1 );
		}
		$fk_label = match ( true ) {
			$fk_score >= 70 => 'Easy (suitable for most readers)',
			$fk_score >= 50 => 'Fairly difficult (college level)',
			$fk_score >= 30 => 'Difficult (college graduate level)',
			default         => 'Very difficult (academic / specialist)',
		};

		preg_match_all( '/<h([1-6])[^>]*>(.*?)<\/h[1-6]>/is', $raw_content, $headings );
		preg_match_all( '/<img[^>]+>/i', $raw_content, $images );
		preg_match_all( '/<a[^>]+href=["\']([^"\']+)["\'][^>]*>/i', $raw_content, $links );

		$internal_links = 0;
		$external_links = 0;
		$site_url       = untrailingslashit( get_bloginfo( 'url' ) );
		foreach ( $links[1] as $href ) {
			if ( str_starts_with( $href, $site_url ) || str_starts_with( $href, '/' ) ) {
				++$internal_links;
			} else {
				++$external_links;
			}
		}

		$keyword_data = array();
		if ( ! empty( $arguments['focus_keyword'] ) ) {
			$kw         = strtolower( trim( $arguments['focus_keyword'] ) );
			$lc_content = strtolower( $plain_text );
			$kw_count   = substr_count( $lc_content, $kw );
			$density    = $word_count > 0 ? round( ( $kw_count / $word_count ) * 100, 2 ) : 0;
			$in_title   = str_contains( strtolower( $post->post_title ), $kw );
			$in_excerpt = str_contains( strtolower( $post->post_excerpt ), $kw );
			$in_h1      = false;
			foreach ( $headings[2] as $i => $h_text ) {
				if ( '1' === $headings[1][ $i ] && str_contains( strtolower( wp_strip_all_tags( $h_text ) ), $kw ) ) {
					$in_h1 = true;
				}
			}
			$keyword_data = array(
				'keyword'         => $arguments['focus_keyword'],
				'occurrences'     => $kw_count,
				'density_percent' => $density,
				'in_title'        => $in_title,
				'in_excerpt'      => $in_excerpt,
				'in_h1'           => $in_h1,
				'density_note'    => $density < 0.5 ? 'Low — try to use the keyword more naturally' : ( $density > 3.0 ? 'High — may read as keyword stuffing' : 'Good range (0.5–3%)' ),
			);
		}

		$recs = array();
		if ( $word_count < 300 ) {
			$recs[] = 'Content is short. Aim for at least 600–800 words for search visibility.';
		}
		if ( ( $word_count / $sent_count ) > 25 ) {
			$recs[] = 'Average sentence length is long. Break sentences up to improve readability.';
		}
		if ( count( $headings[0] ) < 2 && $word_count > 400 ) {
			$recs[] = 'Add subheadings (H2/H3) to break up long content.';
		}
		if ( count( $images[0] ) === 0 ) {
			$recs[] = 'No images found. Adding at least one image improves engagement.';
		}
		if ( empty( $post->post_excerpt ) ) {
			$recs[] = 'No excerpt set. Write a compelling 1–2 sentence excerpt.';
		}
		if ( $fk_score < 50 && $word_count > 100 ) {
			$recs[] = 'Readability score is low. Simplify vocabulary and shorten sentences.';
		}

		$rendered_analysis = null;
		$rendered          = \Agentic\Page_Renderer::fetch_by_post_id( $post->ID );
		if ( $rendered['success'] ) {
			$meta              = $rendered['meta'];
			$rendered_analysis = array(
				'word_count'    => $meta['word_count'] ?? 0,
				'heading_count' => count( $meta['h1'] ?? array() ) + count( $meta['h2'] ?? array() ),
				'h1_count'      => count( $meta['h1'] ?? array() ),
				'h2_count'      => count( $meta['h2'] ?? array() ),
				'image_count'   => $meta['image_count'] ?? 0,
				'link_count'    => $meta['link_count'] ?? 0,
				'schema_types'  => $meta['schema_types'] ?? array(),
				'note'          => 'Rendered counts include theme elements (nav, footer, widgets). Content-only counts above reflect the post body.',
			);
			if ( $meta['word_count'] > $word_count * 1.5 ) {
				$recs[] = 'Rendered page has significantly more words than post content — theme/widgets may be adding substantial text.';
			}
		}

		return array(
			'id'                     => $post->ID,
			'title'                  => $post->post_title,
			'status'                 => $post->post_status,
			'word_count'             => $word_count,
			'sentence_count'         => $sent_count,
			'avg_words_per_sentence' => round( $word_count / $sent_count, 1 ),
			'flesch_kincaid_score'   => $fk_score,
			'readability_level'      => $fk_label,
			'heading_count'          => count( $headings[0] ),
			'image_count'            => count( $images[0] ),
			'internal_links'         => $internal_links,
			'external_links'         => $external_links,
			'has_excerpt'            => ! empty( $post->post_excerpt ),
			'focus_keyword_analysis' => $keyword_data ? $keyword_data : null,
			'rendered_analysis'      => $rendered_analysis,
			'recommendations'        => $recs,
		);
	}

	/**
	 * Get tool annotations.
	 *
	 * @return array
	 */
	public function get_annotations(): array {
		return array(
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		);
	}
}

return new Analyze_Post();
