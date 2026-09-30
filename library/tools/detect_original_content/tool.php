<?php
/**
 * Tool: Detect Original Content
 *
 * Detect whether content contains original data, statistics, case studies, or
 * unique insights. Flags commodity content vs proprietary content.
 *
 * @package Agentic\Tools
 */

declare(strict_types=1);

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Detect_Original_Content extends \Agentic\Tool_Base {

	public function get_name(): string {
		return 'detect_original_content';
	}

	public function get_description(): string {
		return 'Detect whether content contains original data, statistics, case studies, or unique insights. Flags commodity content vs proprietary content.';
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
					'description' => 'Specific post ID to check. Omit for site-wide check of 20 recent posts.',
				),
			),
			'required'   => array(),
		);
	}

	public function get_annotations(): array {
		return array(
			'read_only'   => true,
			'destructive' => false,
		);
	}

	public function execute( array $args ): array {
		$post_id = $args['post_id'] ?? null;

		if ( $post_id ) {
			$posts = array( get_post( (int) $post_id ) );
			if ( ! $posts[0] ) {
				return array( 'error' => 'Post not found.' );
			}
		} else {
			$posts = get_posts(
				array(
					'post_type'      => 'post',
					'post_status'    => 'publish',
					'posts_per_page' => 20,
					'orderby'        => 'date',
					'order'          => 'DESC',
				)
			);
		}

		$results       = array();
		$with_original = 0;

		foreach ( $posts as $post ) {
			$content     = wp_strip_all_tags( $post->post_content );
			$raw_content = $post->post_content;

			$signals = array(
				'has_statistics'       => (bool) preg_match( '/\b\d+(\.\d+)?%|\b\d{1,3}(,\d{3})+\b/', $content ),
				'has_data_table'       => (bool) preg_match( '/<table|wp:table/', $raw_content ),
				'has_case_study'       => (bool) preg_match( '/case study|case-study|real.world example|our experience|we found|we discovered|our data|our research/i', $content ),
				'has_first_person'     => (bool) preg_match( '/\b(I|we|my|our)\b.*\b(found|discovered|tested|built|created|learned|noticed|observed|measured|analysed|analyzed)\b/i', $content ),
				'has_citations'        => (bool) preg_match( '/according to|source:|cited|reference|\[\d+\]/', $content ),
				'has_proprietary_data' => (bool) preg_match( '/our (data|survey|study|analysis|report|findings|research)/', $content ),
				'has_chart_image'      => (bool) preg_match( '/chart|graph|infographic|diagram/i', $content ) && preg_match( '/<img/', $raw_content ),
				'has_methodology'      => (bool) preg_match( '/method(ology)?|how we measured|our approach|process|framework/i', $content ),
			);

			$signal_count = count( array_filter( $signals ) );
			$is_original  = $signal_count >= 2;
			if ( $is_original ) {
				++$with_original;
			}

			$results[] = array(
				'post_id'      => $post->ID,
				'title'        => $post->post_title,
				'signals'      => $signals,
				'signal_count' => $signal_count,
				'is_original'  => $is_original,
				'assessment'   => $signal_count >= 3 ? 'Strong original content' : ( $signal_count >= 2 ? 'Some original elements' : ( 1 === $signal_count ? 'Minimal originality signals' : 'Likely commodity content' ) ),
			);
		}

		$total = count( $results );
		$pct   = $total > 0 ? round( ( $with_original / $total ) * 100 ) : 0;

		return array(
			'posts_analysed'            => $total,
			'with_original_signals'     => $with_original,
			'pct_with_original_signals' => $pct,
			'results'                   => $results,
		);
	}
}
return new Detect_Original_Content();
