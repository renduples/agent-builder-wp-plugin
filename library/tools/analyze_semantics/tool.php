<?php
/**
 * Tool: Analyze Semantics
 *
 * Analyse a post for semantic completeness. Checks focus keyword placement in
 * title, H1, first paragraph, URL slug, and meta description. Flags keyword
 * stuffing.
 *
 * @package Agentic\Tools
 */

declare(strict_types=1);

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Analyze_Semantics extends \Agentic\Tool_Base {

	public function get_name(): string {
		return 'analyze_semantics';
	}

	public function get_description(): string {
		return 'Analyse a post for semantic completeness. Checks focus keyword placement in title, H1, first paragraph, URL slug, and meta description. Flags keyword stuffing.';
	}

	public function get_category(): string {
		return 'site-audit';
	}

	public function get_parameters(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'post_id' => array(
					'type'        => 'integer',
					'description' => 'The post ID to analyse.',
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
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return array( 'error' => 'Post not found.' );
		}

		$title   = $post->post_title;
		$slug    = $post->post_name;
		$content = wp_strip_all_tags( $post->post_content );

		// Get focus keyword — plugin-agnostic.
		$focus_kw = \Agentic\Tool_Helpers::get_focus_keyword( $post_id );

		// Meta description — plugin-agnostic.
		$meta_desc = \Agentic\Tool_Helpers::get_meta_description( $post_id, $post->post_excerpt );

		$result = array(
			'post_id'       => $post_id,
			'title'         => $title,
			'focus_keyword' => $focus_kw ? $focus_kw : '(none set)',
			'placement'     => array(),
			'issues'        => array(),
		);

		if ( ! $focus_kw ) {
			$result['issues'][] = 'No focus keyword set. Set one in your SEO plugin to enable semantic analysis.';
			return $result;
		}

		$kw_lower = strtolower( $focus_kw );

		// Check keyword placement.
		$result['placement']['in_title']     = str_contains( strtolower( $title ), $kw_lower );
		$result['placement']['in_slug']      = str_contains( strtolower( $slug ), str_replace( ' ', '-', $kw_lower ) );
		$result['placement']['in_meta_desc'] = ! empty( $meta_desc ) && str_contains( strtolower( $meta_desc ), $kw_lower );

		// First paragraph.
		$paragraphs                                = preg_split( '/\n\n+/', $content, 3 );
		$first_para                                = ! empty( $paragraphs[0] ) ? strtolower( $paragraphs[0] ) : '';
		$result['placement']['in_first_paragraph'] = str_contains( $first_para, $kw_lower );

		// H2/H3 headings.
		preg_match_all( '/<h[23][^>]*>(.*?)<\/h[23]>/is', $post->post_content, $headings );
		$heading_texts = array_map( fn( $h ) => strtolower( wp_strip_all_tags( $h ) ), $headings[1] ?? array() );
		$kw_in_heading = false;
		foreach ( $heading_texts as $ht ) {
			if ( str_contains( $ht, $kw_lower ) ) {
				$kw_in_heading = true;
				break; }
		}
		$result['placement']['in_heading'] = $kw_in_heading;

		// Keyword density.
		$lower_content = strtolower( $content );
		$kw_count      = substr_count( $lower_content, $kw_lower );
		$total_words   = str_word_count( $content );
		$kw_words      = str_word_count( $focus_kw );
		$density       = $total_words > 0 ? round( ( $kw_count * $kw_words / $total_words ) * 100, 1 ) : 0;

		$result['keyword_count']   = $kw_count;
		$result['word_count']      = $total_words;
		$result['keyword_density'] = $density;

		// Issue detection.
		if ( ! $result['placement']['in_title'] ) {
			$result['issues'][] = "Focus keyword \"{$focus_kw}\" not found in title.";
		}
		if ( ! $result['placement']['in_slug'] ) {
			$result['issues'][] = 'Focus keyword not found in URL slug.';
		}
		if ( ! $result['placement']['in_first_paragraph'] ) {
			$result['issues'][] = 'Focus keyword not found in first paragraph.';
		}
		if ( ! $result['placement']['in_meta_desc'] ) {
			$result['issues'][] = 'Focus keyword not found in meta description.';
		}
		if ( $density > 3 ) {
			$result['issues'][] = "Keyword density is {$density}% — over-optimised. Aim for 0.5–2.5%.";
		}
		if ( $density < 0.3 && $total_words > 300 ) {
			$result['issues'][] = "Keyword density is {$density}% — very low. The keyword may not be prominent enough.";
		}

		// Top terms extraction.
		$words = str_word_count( strtolower( $content ), 1 );
		$stop  = array( 'the', 'a', 'an', 'is', 'are', 'was', 'were', 'in', 'on', 'at', 'to', 'for', 'of', 'and', 'or', 'but', 'with', 'this', 'that', 'it', 'be', 'as', 'by', 'from', 'not', 'has', 'have', 'had', 'do', 'does', 'did', 'will', 'can', 'may', 'if', 'so', 'no', 'up', 'out', 'one', 'all', 'your', 'you', 'we', 'our', 'they', 'their', 'its', 'my', 'i', 'me', 'he', 'she', 'him', 'her', 'us', 'them', 'who', 'which', 'what', 'when', 'where', 'how', 'more', 'also', 'than', 'then', 'just' );
		$words = array_filter( $words, fn( $w ) => strlen( $w ) > 3 && ! in_array( $w, $stop ) );
		$freq  = array_count_values( $words );
		arsort( $freq );
		$result['top_terms'] = array_slice( $freq, 0, 15, true );

		return $result;
	}
}
return new Analyze_Semantics();
