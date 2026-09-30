<?php
declare(strict_types=1);

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get Engagement Overview Tool
 *
 * Analyses user engagement signals across the site: comment activity,
 * content freshness, average word count, and integration with
 * Google Site Kit and Jetpack Stats if available.
 *
 * @package Agentic\Tools
 */
class Get_Engagement_Overview extends \Agentic\Tool_Base {

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'get_engagement_overview';
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return 'Analyse user engagement signals across the site: comment activity, content freshness, average word count, and integration with Google Site Kit and Jetpack Stats if available.';
	}

	/**
	 * Get the tool category.
	 *
	 * @return string
	 */
	public function get_category(): string {
		return 'analytics';
	}

	/**
	 * Get the tool parameters.
	 *
	 * @return array
	 */
	public function get_parameters(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'days' => array(
					'type'        => 'integer',
					'description' => 'Look back this many days. Defaults to 30.',
				),
			),
		);
	}

	/**
	 * Execute the engagement overview analysis.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array
	 */
	public function execute( array $arguments ): array {
		$days  = max( 1, (int) ( $arguments['days'] ?? 30 ) );
		$since = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		$result = array( 'period' => "last {$days} days" );

		// Google Site Kit integration.
		if ( function_exists( 'google_site_kit' ) || class_exists( 'Google\\Site_Kit\\Plugin' ) ) {
			$analytics_settings = get_option( 'googlesitekit_analytics-4_settings', array() );
			$result['site_kit'] = array(
				'connected'   => true,
				'analytics_4' => ! empty( $analytics_settings ),
				'note'        => 'For detailed GA4 metrics (bounce rate, session duration, pages per session), view the Site Kit dashboard. Agent Builder reads available cached data.',
			);
		} else {
			$result['site_kit'] = array(
				'connected' => false,
				'note'      => 'Install Google Site Kit to access GA4 engagement data (bounce rate, session duration, engagement rate) directly from WordPress.',
			);
		}

		// Jetpack Stats integration.
		if ( function_exists( 'stats_get_csv' ) ) {
			$csv = stats_get_csv(
				'views',
				array(
					'days'      => min( $days, 30 ),
					'limit'     => 1,
					'summarize' => true,
				)
			);
			if ( ! empty( $csv[0] ) ) {
				$result['jetpack_stats'] = array(
					'total_views' => (int) ( $csv[0]['views'] ?? 0 ),
					'period'      => "last {$days} days",
				);
			}
		}

		// WordPress content signals.
		$posts = get_posts(
			array(
				'post_type'      => array( 'post', 'page' ),
				'post_status'    => 'publish',
				'posts_per_page' => 200,
			)
		);

		$total_comments      = 0;
		$total_words         = 0;
		$posts_with_comments = 0;

		foreach ( $posts as $post ) {
			$comments        = (int) $post->comment_count;
			$total_comments += $comments;
			if ( $comments > 0 ) {
				++$posts_with_comments;
			}
			$total_words += str_word_count( wp_strip_all_tags( $post->post_content ) );
		}

		$post_count = max( 1, count( $posts ) );

		$recent_comments = get_comments(
			array(
				'number'  => 20,
				'status'  => 'approve',
				'orderby' => 'comment_date',
				'order'   => 'DESC',
			)
		);

		$comments_in_period = 0;
		foreach ( $recent_comments as $comment ) {
			if ( strtotime( $comment->comment_date_gmt ) >= strtotime( $since ) ) {
				++$comments_in_period;
			}
		}

		$result['wordpress_signals'] = array(
			'total_published_content' => count( $posts ),
			'avg_word_count'          => round( $total_words / $post_count ),
			'total_comments'          => $total_comments,
			'posts_with_comments'     => $posts_with_comments,
			'comments_in_period'      => $comments_in_period,
			'avg_comments_per_post'   => round( $total_comments / $post_count, 1 ),
			'comment_engagement_rate' => round( ( $posts_with_comments / $post_count ) * 100, 1 ) . '%',
		);

		// Content freshness.
		$stale_count     = 0;
		$stale_threshold = time() - ( 180 * DAY_IN_SECONDS );
		foreach ( $posts as $post ) {
			if ( strtotime( $post->post_modified_gmt ) < $stale_threshold ) {
				++$stale_count;
			}
		}

		$result['content_freshness'] = array(
			'stale_posts' => $stale_count,
			'stale_pct'   => round( ( $stale_count / $post_count ) * 100, 1 ),
			'note'        => $stale_count > $post_count * 0.3
				? 'Over 30% of content hasn\'t been updated in 6+ months. Fresh content signals relevance to search engines.'
				: 'Content freshness is reasonable.',
		);

		// Engagement score.
		$engagement_score  = 100;
		$engagement_issues = array();

		if ( 0 === $total_comments ) {
			$engagement_score   -= 20;
			$engagement_issues[] = 'No comments on any content — consider enabling comments or adding CTAs.';
		}
		if ( round( $total_words / $post_count ) < 300 ) {
			$engagement_score   -= 15;
			$engagement_issues[] = 'Average content length is under 300 words — longer content typically drives more engagement.';
		}
		if ( $stale_count > $post_count * 0.5 ) {
			$engagement_score   -= 15;
			$engagement_issues[] = 'Over 50% of content is stale (not updated in 6+ months).';
		}

		$result['engagement_score']  = max( 0, $engagement_score );
		$result['engagement_issues'] = $engagement_issues;

		return $result;
	}

	/**
	 * Get the tool annotations.
	 *
	 * @return array
	 */
	public function get_annotations(): array {
		return array(
			'readonly' => true,
		);
	}
}

return new Get_Engagement_Overview();
