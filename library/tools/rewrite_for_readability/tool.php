<?php
/**
 * Tool: rewrite_for_readability
 *
 * Analyse a post's readability structure and return a detailed report with actionable suggestions.
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
 * Analyse a post's readability structure and return a detailed report with actionable suggestions.
 * Does NOT modify the post.
 */
class Rewrite_For_Readability extends \Agentic\Tool_Base {

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'rewrite_for_readability';
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return "Analyse a post's readability structure and return a detailed report with actionable suggestions. Checks heading hierarchy, paragraph length, sentence length, bullet/list usage, front-loaded answers, and passive voice. Does NOT modify the post.";
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
				'post_id' => array(
					'type'        => 'integer',
					'description' => 'The WordPress post ID to analyse.',
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

		if ( $word_count < 20 ) {
			return array(
				'post_id' => $post->ID,
				'title'   => $post->post_title,
				'error'   => 'Post has too little content to analyse (' . $word_count . ' words).',
			);
		}

		$score   = 100;
		$issues  = array();
		$passing = array();

		// 1. Heading hierarchy.
		preg_match_all( '/<h([1-6])[^>]*>(.*?)<\/h[1-6]>/is', $raw_content, $headings );
		$heading_levels = array_map( 'intval', $headings[1] );
		$heading_texts  = array_map( 'wp_strip_all_tags', $headings[2] );
		$h2_count       = count( array_filter( $heading_levels, fn( $l ) => 2 === $l ) );
		$h3_count       = count( array_filter( $heading_levels, fn( $l ) => 3 === $l ) );

		if ( $word_count > 400 && 0 === $h2_count ) {
			$score   -= 15;
			$issues[] = 'No H2 subheadings. Break content into sections with descriptive H2s every 200-300 words.';
		} elseif ( $word_count > 800 && $h2_count < 3 ) {
			$score   -= 8;
			$issues[] = "Only {$h2_count} H2 heading(s) for {$word_count} words. Add more H2s.";
		} else {
			$passing[] = "Heading count is appropriate ({$h2_count} H2, {$h3_count} H3).";
		}

		$hierarchy_issues = array();
		$heading_count    = count( $heading_levels );
		for ( $i = 1; $i < $heading_count; $i++ ) {
			$prev = $heading_levels[ $i - 1 ];
			$curr = $heading_levels[ $i ];
			if ( $curr > $prev + 1 ) {
				$hierarchy_issues[] = "H{$prev} → H{$curr} (skipped level) near \"{$heading_texts[ $i ]}\"";
			}
		}
		if ( ! empty( $hierarchy_issues ) ) {
			$score   -= 5;
			$issues[] = 'Heading hierarchy skips levels: ' . implode( '; ', $hierarchy_issues ) . '.';
		}

		// 2. Paragraph length.
		$paragraphs      = preg_split( '/(?:<\/p>|<p[^>]*>|\n{2,}|<br\s*\/?>)/i', $raw_content, -1, PREG_SPLIT_NO_EMPTY );
		$paragraphs      = array_values( array_filter( array_map( fn( $p ) => trim( wp_strip_all_tags( $p ) ), $paragraphs ) ) );
		$long_paragraphs = array();
		foreach ( $paragraphs as $i => $para ) {
			$pw = str_word_count( $para );
			if ( $pw > 80 ) {
				$long_paragraphs[] = array(
					'paragraph' => $i + 1,
					'words'     => $pw,
					'preview'   => mb_substr( $para, 0, 80 ) . '...',
				);
			}
		}
		if ( count( $long_paragraphs ) > 0 ) {
			$score   -= min( 15, count( $long_paragraphs ) * 5 );
			$issues[] = count( $long_paragraphs ) . ' paragraph(s) exceed 80 words.';
		} else {
			$passing[] = 'All paragraphs are under 80 words.';
		}

		// 3. Sentence length.
		$sentences      = preg_split( '/[.!?]+(?:\s|$)/', $plain_text, -1, PREG_SPLIT_NO_EMPTY );
		$sent_count     = max( 1, count( $sentences ) );
		$avg_sent_len   = round( $word_count / $sent_count, 1 );
		$long_sentences = 0;
		foreach ( $sentences as $sent ) {
			if ( str_word_count( trim( $sent ) ) > 25 ) {
				++$long_sentences;
			}
		}
		$long_pct = $sent_count > 0 ? round( ( $long_sentences / $sent_count ) * 100 ) : 0;

		if ( $avg_sent_len > 25 ) {
			$score   -= 10;
			$issues[] = "Average sentence length is {$avg_sent_len} words. Aim for 15-20.";
		} elseif ( $long_pct > 30 ) {
			$score   -= 5;
			$issues[] = "{$long_pct}% of sentences are over 25 words.";
		} else {
			$passing[] = "Sentence length is good (avg {$avg_sent_len} words).";
		}

		// 4. List usage.
		$has_lists = preg_match( '/<[ou]l[^>]*>/i', $raw_content );
		if ( ! $has_lists && $word_count > 500 ) {
			$score   -= 5;
			$issues[] = 'No bullet or numbered lists found.';
		} elseif ( $has_lists ) {
			$passing[] = 'Content uses lists for scannability.';
		}

		// 5. Front-loaded answers.
		$has_question = (bool) preg_match( '/\?|how|what|why|when|where|who|which|can|does|is|are/i', $post->post_title );
		if ( $has_question && ! empty( $paragraphs[0] ) ) {
			$first_para_words = str_word_count( $paragraphs[0] );
			if ( $first_para_words > 60 ) {
				$score   -= 5;
				$issues[] = "Title is question-phrased but the first paragraph is {$first_para_words} words. Front-load the answer.";
			} else {
				$passing[] = 'First paragraph is concise — answer is front-loaded.';
			}
		}

		// 6. Flesch-Kincaid.
		$syllable_count = \Agentic\Tool_Helpers::count_syllables( $plain_text );
		$fk_score       = 0;
		if ( $word_count > 0 ) {
			$fk_score = 206.835 - ( 1.015 * ( $word_count / $sent_count ) ) - ( 84.6 * ( $syllable_count / $word_count ) );
			$fk_score = round( $fk_score, 1 );
		}
		if ( $fk_score < 30 ) {
			$score   -= 15;
			$issues[] = "Flesch-Kincaid score is {$fk_score} (very difficult).";
		} elseif ( $fk_score < 50 ) {
			$score   -= 8;
			$issues[] = "Flesch-Kincaid score is {$fk_score} (difficult).";
		} else {
			$passing[] = "Flesch-Kincaid readability score is {$fk_score} (" . ( $fk_score >= 70 ? 'easy' : 'moderate' ) . ').';
		}

		// 7. Passive voice.
		$passive_matches = preg_match_all( '/\b(?:is|are|was|were|been|being|be)\s+(?:\w+ed|\w+en)\b/i', $plain_text );
		$passive_pct     = $sent_count > 0 ? round( ( $passive_matches / $sent_count ) * 100 ) : 0;
		if ( $passive_pct > 20 ) {
			$score   -= 5;
			$issues[] = "~{$passive_pct}% passive voice detected.";
		} elseif ( $passive_matches > 0 ) {
			$passing[] = "Passive voice is within acceptable range ({$passive_pct}%).";
		} else {
			$passing[] = 'No passive voice detected.';
		}

		// Rendered page analysis.
		$rendered_note = null;
		$rendered      = \Agentic\Page_Renderer::fetch_by_post_id( $post->ID );
		if ( $rendered['success'] ) {
			$meta        = $rendered['meta'];
			$rendered_h1 = count( $meta['h1'] ?? array() );
			if ( $rendered_h1 > 1 ) {
				$score   -= 5;
				$issues[] = "Rendered page has {$rendered_h1} H1 tags (should be exactly 1).";
			}
			$rendered_note = array(
				'rendered_h1_count'   => $rendered_h1,
				'rendered_h2_count'   => count( $meta['h2'] ?? array() ),
				'rendered_word_count' => $meta['word_count'] ?? 0,
			);
		}

		return array(
			'post_id'             => $post->ID,
			'title'               => $post->post_title,
			'word_count'          => $word_count,
			'readability_score'   => max( 0, $score ),
			'flesch_kincaid'      => $fk_score,
			'sentence_count'      => $sent_count,
			'avg_sentence_length' => $avg_sent_len,
			'long_sentences'      => $long_sentences,
			'long_sentence_pct'   => $long_pct,
			'paragraph_count'     => count( $paragraphs ),
			'long_paragraphs'     => $long_paragraphs,
			'h2_count'            => $h2_count,
			'h3_count'            => $h3_count,
			'heading_hierarchy'   => $hierarchy_issues,
			'has_lists'           => (bool) $has_lists,
			'passive_voice_pct'   => $passive_pct,
			'rendered_analysis'   => $rendered_note,
			'passing'             => $passing,
			'issues'              => $issues,
			'note'                => 'This tool analyses readability structure. Use update_post_content to apply content changes after reviewing the suggestions.',
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

return new Rewrite_For_Readability();
