<?php
/**
 * Tool: optimize_post_title
 *
 * Rewrite a post title to improve search intent alignment.
 *
 * @package    Agent_Builder
 * @subpackage Tools
 * @since      2.2.1
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rewrite and apply a post title optimised for search intent alignment.
 */
class Optimize_Post_Title extends \Agentic\Tool_Base {

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'optimize_post_title';
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return 'Analyse a post title for search intent alignment and rewrite it. Runs analyze_search_intent internally, detects the gap between the current title and ideal intent patterns, generates an improved title, and applies it. Updates both post_title and SEO title meta. Always get explicit user approval before calling this.';
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
				'post_id'       => array(
					'type'        => 'integer',
					'description' => 'The WordPress post ID to optimise.',
				),
				'target_intent' => array(
					'type'        => 'string',
					'description' => 'Override the detected intent. One of: informational, transactional, navigational, commercial. Defaults to the detected primary intent.',
				),
				'focus_keyword' => array(
					'type'        => 'string',
					'description' => 'Override focus keyword. If omitted, uses the stored focus keyword or extracts from content.',
				),
				'new_title'     => array(
					'type'        => 'string',
					'description' => 'If provided, apply this exact title instead of generating one. The tool will still validate it against intent patterns and warn if weak.',
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

		$old_title = $post->post_title;

		// Run intent analysis.
		$analysis = \Agentic\Tool_Loader::get_instance()->execute(
			'analyze_search_intent',
			array( 'post_id' => $post_id )
		);
		if ( isset( $analysis['error'] ) ) {
			return $analysis;
		}

		$valid_intents = array( 'informational', 'transactional', 'navigational', 'commercial' );
		$target_intent = strtolower( $arguments['target_intent'] ?? $analysis['primary_intent'] );
		if ( ! in_array( $target_intent, $valid_intents, true ) ) {
			return array( 'error' => 'Invalid target_intent. Must be one of: ' . implode( ', ', $valid_intents ) );
		}

		// Resolve focus keyword.
		$focus_keyword = '';
		if ( ! empty( $arguments['focus_keyword'] ) ) {
			$focus_keyword = sanitize_text_field( $arguments['focus_keyword'] );
		} else {
			$focus_keyword = \Agentic\Tool_Helpers::get_focus_keyword( $post_id );
		}
		if ( ! $focus_keyword ) {
			$focus_keyword = $this->extract_keyword_from_content( $post );
		}

		// Determine the new title.
		if ( ! empty( $arguments['new_title'] ) ) {
			$new_title = sanitize_text_field( $arguments['new_title'] );
			$source    = 'user_provided';
		} else {
			$new_title = $this->generate_title( $old_title, $target_intent, $focus_keyword, $post );
			$source    = 'generated';
		}

		// Score both titles against intent.
		$old_score = $this->score_title( $old_title, $target_intent, $focus_keyword );
		$new_score = $this->score_title( $new_title, $target_intent, $focus_keyword );

		$warnings = array();
		if ( strlen( $new_title ) > 60 ) {
			$warnings[] = 'Title exceeds 60 characters — may be truncated in search results.';
		}
		if ( strlen( $new_title ) < 20 ) {
			$warnings[] = 'Title is very short — consider adding more descriptive keywords.';
		}
		if ( $new_score < $old_score ) {
			$warnings[] = 'New title scores lower than original. Review carefully.';
		}
		if ( $focus_keyword && ! str_contains( strtolower( $new_title ), strtolower( $focus_keyword ) ) ) {
			$warnings[] = "Focus keyword \"{$focus_keyword}\" not found in new title.";
		}

		// Apply the title.
		$result = wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => $new_title,
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			return array( 'error' => $result->get_error_message() );
		}

		// Update SEO title meta.
		$seo_keys = \Agentic\Tool_Helpers::get_seo_meta_keys();
		update_post_meta( $post_id, $seo_keys['title'], $new_title );

		$updated = array( 'post_title', $seo_keys['title'] );

		return array(
			'success'         => true,
			'post_id'         => $post_id,
			'url'             => get_permalink( $post_id ),
			'old_title'       => $old_title,
			'new_title'       => $new_title,
			'source'          => $source,
			'target_intent'   => $target_intent,
			'focus_keyword'   => $focus_keyword,
			'old_score'       => $old_score,
			'new_score'       => $new_score,
			'updated_fields'  => $updated,
			'warnings'        => $warnings,
			'intent_analysis' => array(
				'primary'    => $analysis['primary_intent'],
				'confidence' => $analysis['confidence'],
				'signals'    => $analysis['signal_scores'],
			),
		);
	}

	/**
	 * Generate an improved title based on intent patterns.
	 *
	 * @param string   $old_title     Current title.
	 * @param string   $target_intent Target search intent.
	 * @param string   $focus_keyword Focus keyword.
	 * @param \WP_Post $post          The post object.
	 * @return string  Improved title.
	 */
	private function generate_title( string $old_title, string $target_intent, string $focus_keyword, \WP_Post $post ): string {
		$keyword = $focus_keyword ? $focus_keyword : $this->clean_title_for_reuse( $old_title );

		// Intent-specific title patterns.
		$patterns = array(
			'informational' => array(
				'{keyword}: A Complete Guide',
				'How to {keyword} — Step-by-Step Guide',
				'What Is {keyword}? Everything You Need to Know',
				'{keyword} Explained: Tips and Best Practices',
				'Understanding {keyword}: A Beginner\'s Guide',
			),
			'transactional' => array(
				'Get {keyword} — Start Today',
				'{keyword}: Pricing, Features & How to Buy',
				'Try {keyword} Free — No Setup Required',
				'{keyword} — Get Started in Minutes',
				'Buy {keyword}: Plans Starting at $0',
			),
			'navigational'  => array(
				'{keyword} — Official Page',
				'{keyword} — Help & Support',
				'Log In to {keyword}',
				'{keyword} — Your Dashboard',
				'Contact {keyword} — Get Help',
			),
			'commercial'    => array(
				'Best {keyword} Options in ' . gmdate( 'Y' ),
				'{keyword} Review: Pros, Cons & Verdict',
				'{keyword} vs Alternatives — Honest Comparison',
				'Top {keyword} Solutions Compared',
				'{keyword}: Is It Worth It? Full Review',
			),
		);

		$intent_patterns = $patterns[ $target_intent ] ?? $patterns['informational'];

		// Pick the best pattern based on existing title structure.
		$best_pattern = $this->pick_best_pattern( $old_title, $intent_patterns, $target_intent );

		$new_title = str_replace( '{keyword}', $keyword, $best_pattern );

		// Trim to 60 chars at a word boundary.
		if ( strlen( $new_title ) > 60 ) {
			$new_title = $this->trim_at_word( $new_title, 60 );
		}

		return $new_title;
	}

	/**
	 * Pick the best pattern for a title based on what the current title already has.
	 *
	 * @param string $old_title      Current title.
	 * @param array  $patterns       Available patterns.
	 * @param string $target_intent  Target intent type.
	 * @return string Best matching pattern.
	 */
	private function pick_best_pattern( string $old_title, array $patterns, string $target_intent ): string {
		$lower = strtolower( $old_title );

		// If the title already has strong intent signals, prefer patterns that build on that.
		$intent_markers = array(
			'informational' => array( 'how to', 'guide', 'what is', 'tutorial', 'learn', 'tips' ),
			'transactional' => array( 'buy', 'get', 'start', 'try', 'pricing', 'free' ),
			'navigational'  => array( 'login', 'contact', 'about', 'support', 'dashboard' ),
			'commercial'    => array( 'best', 'review', 'vs', 'compare', 'top', 'alternative' ),
		);

		$markers = $intent_markers[ $target_intent ] ?? array();

		// If title already contains a marker, pick a pattern that uses a different one for variety.
		foreach ( $patterns as $pattern ) {
			$pattern_lower        = strtolower( $pattern );
			$has_different_marker = false;
			foreach ( $markers as $marker ) {
				if ( str_contains( $pattern_lower, $marker ) && ! str_contains( $lower, $marker ) ) {
					$has_different_marker = true;
					break;
				}
			}
			if ( $has_different_marker ) {
				return $pattern;
			}
		}

		// Fallback: return the first pattern.
		return $patterns[0];
	}

	/**
	 * Score a title for intent alignment.
	 *
	 * @param string $title         Title to score.
	 * @param string $target_intent Target intent.
	 * @param string $focus_keyword Focus keyword.
	 * @return int Score (0–100).
	 */
	private function score_title( string $title, string $target_intent, string $focus_keyword ): int {
		$score = 0;
		$lower = strtolower( $title );

		// Length score (30-60 chars ideal).
		$len = strlen( $title );
		if ( $len >= 30 && $len <= 60 ) {
			$score += 20;
		} elseif ( $len >= 20 && $len <= 70 ) {
			$score += 10;
		}

		// Focus keyword presence.
		if ( $focus_keyword && str_contains( $lower, strtolower( $focus_keyword ) ) ) {
			$score += 25;
			// Bonus: keyword near the start.
			if ( strpos( $lower, strtolower( $focus_keyword ) ) < 20 ) {
				$score += 10;
			}
		}

		// Intent signal words.
		$intent_words = array(
			'informational' => array( 'how to', 'what is', 'guide', 'tutorial', 'learn', 'tips', 'explained', 'understanding' ),
			'transactional' => array( 'buy', 'get', 'start', 'try', 'pricing', 'free', 'order', 'download' ),
			'navigational'  => array( 'login', 'contact', 'about', 'support', 'dashboard', 'help', 'official' ),
			'commercial'    => array( 'best', 'review', 'vs', 'compare', 'top', 'alternative', 'comparison', 'rated' ),
		);

		$words        = $intent_words[ $target_intent ] ?? array();
		$intent_match = false;
		foreach ( $words as $word ) {
			if ( str_contains( $lower, $word ) ) {
				$score       += 15;
				$intent_match = true;
				break;
			}
		}

		// Secondary intent signal bonus.
		$signal_count = 0;
		foreach ( $words as $word ) {
			if ( str_contains( $lower, $word ) ) {
				++$signal_count;
			}
		}
		if ( $signal_count > 1 ) {
			$score += 5;
		}

		// Power words / emotional triggers.
		$power_words = array( 'ultimate', 'essential', 'complete', 'proven', 'definitive', 'powerful', 'simple', 'easy', 'fast', 'free' );
		foreach ( $power_words as $pw ) {
			if ( str_contains( $lower, $pw ) ) {
				$score += 5;
				break;
			}
		}

		// Number in title bonus.
		if ( preg_match( '/\d+/', $title ) ) {
			$score += 5;
		}

		// Separator usage (—, |, :).
		if ( preg_match( '/[—|:]/', $title ) ) {
			$score += 5;
		}

		// No intent signal penalty.
		if ( ! $intent_match && '' === $focus_keyword ) {
			$score = max( 0, $score - 10 );
		}

		return min( 100, $score );
	}

	/**
	 * Extract a likely keyword from post content.
	 *
	 * @param \WP_Post $post The post.
	 * @return string Extracted keyword or empty string.
	 */
	private function extract_keyword_from_content( \WP_Post $post ): string {
		// Try H1 from content first.
		if ( preg_match( '/<h1[^>]*>(.*?)<\/h1>/i', $post->post_content, $m ) ) {
			return sanitize_text_field( wp_strip_all_tags( $m[1] ) );
		}

		// Fall back to first H2.
		if ( preg_match( '/<h2[^>]*>(.*?)<\/h2>/i', $post->post_content, $m ) ) {
			return sanitize_text_field( wp_strip_all_tags( $m[1] ) );
		}

		// Fall back to title slug cleaned up.
		return $this->clean_title_for_reuse( $post->post_title );
	}

	/**
	 * Clean a title for reuse as a keyword base.
	 *
	 * @param string $title The title.
	 * @return string Cleaned keyword.
	 */
	private function clean_title_for_reuse( string $title ): string {
		// Remove common stop patterns.
		$cleaned = preg_replace( '/\s*[—|:]\s*.*$/', '', $title );
		$cleaned = preg_replace( '/^(how to|what is|guide to|the)\s+/i', '', $cleaned );
		$cleaned = trim( $cleaned );
		return $cleaned ? $cleaned : $title;
	}

	/**
	 * Trim text at a word boundary.
	 *
	 * @param string $text      Text to trim.
	 * @param int    $max_chars Maximum characters.
	 * @return string Trimmed text.
	 */
	private function trim_at_word( string $text, int $max_chars ): string {
		if ( strlen( $text ) <= $max_chars ) {
			return $text;
		}
		$trimmed    = substr( $text, 0, $max_chars );
		$last_space = strrpos( $trimmed, ' ' );
		if ( false !== $last_space ) {
			$trimmed = substr( $trimmed, 0, $last_space );
		}
		return $trimmed;
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

return new Optimize_Post_Title();
