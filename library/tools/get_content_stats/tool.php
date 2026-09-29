<?php
/**
 * Tool: Get Content Stats
 *
 * Gather content statistics: post counts, word counts, thin content ratio,
 * publishing cadence, age distribution, and empty categories.
 *
 * @package Agentic\Tools
 */

declare(strict_types=1);

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Get_Content_Stats extends \Agentic\Tool_Base {

	public function get_name(): string {
		return 'get_content_stats';
	}

	public function get_description(): string {
		return 'Gather content statistics: post counts, word counts, thin content ratio, publishing cadence, age distribution, and empty categories.';
	}

	public function get_category(): string {
		return 'site-audit';
	}

	public function get_parameters(): array {
		return array(
			'type'       => 'object',
			'properties' => array(),
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
		$posts = get_posts(
			array(
				'post_type'      => array( 'post', 'page' ),
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		$total = count( $posts );

		if ( 0 === $total ) {
			return array(
				'total_posts' => 0,
				'message'     => 'No published posts or pages found.',
			);
		}

		$word_counts     = array();
		$dates           = array();
		$thin_count      = 0;
		$text_only_count = 0;
		$now             = time();
		$age_buckets     = array(
			'0_3'     => 0,
			'3_6'     => 0,
			'6_12'    => 0,
			'12_plus' => 0,
		);

		foreach ( $posts as $id ) {
			$post          = get_post( $id );
			$content       = wp_strip_all_tags( $post->post_content );
			$words         = str_word_count( $content );
			$word_counts[] = $words;

			if ( $words < 300 ) {
				++$thin_count;
			}

			// Media: look for img tags or attachment links.
			if ( ! preg_match( '/<img|<video|\[gallery|\[video/', $post->post_content ) ) {
				$has_featured = has_post_thumbnail( $id );
				if ( ! $has_featured ) {
					++$text_only_count;
				}
			}

			$pub_ts  = strtotime( $post->post_date );
			$dates[] = $pub_ts;

			$age_days = ( $now - $pub_ts ) / DAY_IN_SECONDS;
			if ( $age_days < 90 ) {
				++$age_buckets['0_3']; } elseif ( $age_days < 180 ) {
				++$age_buckets['3_6']; } elseif ( $age_days < 365 ) {
					++$age_buckets['6_12']; } else {
					++$age_buckets['12_plus']; }
		}

		$avg_words       = $total > 0 ? round( array_sum( $word_counts ) / $total ) : 0;
		$pct_older_12    = $total > 0 ? round( ( $age_buckets['12_plus'] / $total ) * 100 ) : 0;
		$days_since_last = $dates ? round( ( $now - max( $dates ) ) / DAY_IN_SECONDS ) : null;
		$text_only_pct   = $total > 0 ? round( ( $text_only_count / $total ) * 100 ) : 0;

		// Publishing cadence (last 6 months).
		$recent_posts = array_filter( $dates, fn( $d ) => ( $now - $d ) < ( 180 * DAY_IN_SECONDS ) );
		sort( $recent_posts );
		$avg_gap = null;
		if ( count( $recent_posts ) >= 2 ) {
			$gaps         = array();
			$recent_count = count( $recent_posts );
			for ( $i = 1; $i < $recent_count; $i++ ) {
				$gaps[] = ( $recent_posts[ $i ] - $recent_posts[ $i - 1 ] ) / DAY_IN_SECONDS;
			}
			$avg_gap = round( array_sum( $gaps ) / count( $gaps ) );
		}

		// Empty categories.
		$empty_cats = 0;
		$cats       = get_categories( array( 'hide_empty' => false ) );
		foreach ( $cats as $cat ) {
			if ( 0 === $cat->count ) {
				++$empty_cats;
			}
		}

		return array(
			'total_posts'               => $total,
			'avg_word_count'            => $avg_words,
			'thin_post_count'           => $thin_count,
			'text_only_post_pct'        => $text_only_pct,
			'age_buckets'               => $age_buckets,
			'pct_older_than_12_months'  => $pct_older_12,
			'days_since_last_post'      => $days_since_last,
			'avg_days_between_posts'    => $avg_gap,
			'empty_categories'          => $empty_cats,
			'potential_duplicate_count' => 0,
		);
	}
}

return new Get_Content_Stats();
