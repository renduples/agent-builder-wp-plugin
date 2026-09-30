<?php
/**
 * Tool: analyze_search_intent
 *
 * Analyse a page's content to determine what search intent it satisfies.
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
 * Analyse a page's content to determine search intent alignment.
 */
class Analyze_Search_Intent extends \Agentic\Tool_Base {

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'analyze_search_intent';
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return 'Analyse a page\'s content to determine what search intent it satisfies (informational, transactional, navigational, commercial) and how well it matches. Checks title, headings, content patterns, CTAs, and URL structure. If Google Site Kit is connected, compares against actual search queries driving traffic to this page.';
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
		$post_id = (int) ( $arguments['post_id'] ?? 0 );
		$post    = $this->get_viewable_post( $post_id );

		if ( ! $post ) {
			return array( 'error' => 'Post not found.' );
		}

		$title   = $post->post_title;
		$content = wp_strip_all_tags( $post->post_content );
		$url     = get_permalink( $post_id );
		$slug    = $post->post_name;

		$signals = array(
			'informational' => 0,
			'transactional' => 0,
			'navigational'  => 0,
			'commercial'    => 0,
		);

		$lower_title   = strtolower( $title );
		$lower_content = strtolower( $content );

		// Informational signals.
		$info_patterns = array( 'how to', 'what is', 'why ', 'guide', 'tutorial', 'learn', 'explain', 'definition', 'example', 'tips', 'understand' );

		foreach ( $info_patterns as $p ) {
			if ( str_contains( $lower_title, $p ) ) {
				$signals['informational'] += 15;
			}
			if ( str_contains( $lower_content, $p ) ) {
				$signals['informational'] += 3;
			}
		}

		// Transactional signals.
		$trans_patterns = array( 'buy', 'price', 'discount', 'order', 'shop', 'add to cart', 'checkout', 'purchase', 'deal', 'coupon', 'free shipping' );

		foreach ( $trans_patterns as $p ) {
			if ( str_contains( $lower_title, $p ) ) {
				$signals['transactional'] += 15;
			}
			if ( str_contains( $lower_content, $p ) ) {
				$signals['transactional'] += 3;
			}
		}

		// Navigational signals.
		$nav_patterns = array( 'login', 'sign in', 'contact', 'about us', 'my account', 'dashboard', 'support', 'help center' );

		foreach ( $nav_patterns as $p ) {
			if ( str_contains( $lower_title, $p ) ) {
				$signals['navigational'] += 15;
			}
			if ( str_contains( $lower_content, $p ) ) {
				$signals['navigational'] += 3;
			}
		}

		// Commercial signals.
		$comm_patterns = array( 'best', 'review', 'comparison', 'vs ', 'top ', 'alternative', 'vs.', 'recommend' );

		foreach ( $comm_patterns as $p ) {
			if ( str_contains( $lower_title, $p ) ) {
				$signals['commercial'] += 15;
			}
			if ( str_contains( $lower_content, $p ) ) {
				$signals['commercial'] += 3;
			}
		}

		// URL slug signals.
		if ( preg_match( '/how-to|guide|tutorial|what-is/', $slug ) ) {
			$signals['informational'] += 10;
		}
		if ( preg_match( '/buy|shop|pricing|order/', $slug ) ) {
			$signals['transactional'] += 10;
		}
		if ( preg_match( '/contact|login|about|account/', $slug ) ) {
			$signals['navigational'] += 10;
		}
		if ( preg_match( '/best|review|compare|vs/', $slug ) ) {
			$signals['commercial'] += 10;
		}

		// CTA / button signals.
		if ( preg_match( '/wp-block-button|<button|<a[^>]+class="[^"]*btn/', $post->post_content ) ) {
			$signals['transactional'] += 5;
			$signals['commercial']    += 5;
		}

		arsort( $signals );
		$primary_intent = array_key_first( $signals );
		$max_score      = max( $signals );
		$total          = array_sum( $signals ) ? array_sum( $signals ) : 1;
		$confidence     = round( $max_score / $total * 100 );

		$sorted    = array_values( $signals );
		$secondary = null;

		if ( count( $sorted ) > 1 && $sorted[1] > 0 && $sorted[1] >= $max_score * 0.7 ) {
			$keys      = array_keys( $signals );
			$secondary = $keys[1];
		}

		$result = array(
			'post_id'          => $post_id,
			'title'            => $title,
			'url'              => $url,
			'primary_intent'   => $primary_intent,
			'confidence'       => $confidence,
			'secondary_intent' => $secondary,
			'signal_scores'    => $signals,
			'issues'           => array(),
		);

		if ( $confidence < 40 ) {
			$result['issues'][] = 'Low confidence — page has no clear intent signal. Consider focusing the content around a single intent.';
		}

		if ( $secondary ) {
			$result['issues'][] = "Mixed intent detected ({$primary_intent} + {$secondary}). Consider splitting into separate pages or committing to one intent.";
		}

		// Google Site Kit / Search Console data (optional).
		$search_queries = $this->get_search_console_queries( $post_id, $url );

		if ( $search_queries ) {
			$result['search_console'] = $search_queries;

			foreach ( $search_queries['top_queries'] as $q ) {
				$query_lower = strtolower( $q['query'] );

				if ( ! str_contains( $lower_title, $query_lower ) && ! str_contains( $lower_content, $query_lower ) ) {
					$result['issues'][] = "Top query \"{$q['query']}\" ({$q['clicks']} clicks) not reflected in title or content.";
				}
			}
		}

		return $result;
	}

	/**
	 * Get Search Console queries for a URL via Google Site Kit.
	 *
	 * @param int    $post_id The post ID.
	 * @param string $url     The post URL.
	 * @return array|null Query data or null if unavailable.
	 */
	private function get_search_console_queries( int $post_id, string $url ): ?array {
		if ( ! class_exists( '\\Google\\Site_Kit\\Plugin' ) || ! function_exists( 'google_site_kit' ) ) {
			return null;
		}

		$request = new \WP_REST_Request( 'POST', '/google-site-kit/v1/modules/search-console/data/searchanalytics' );
		$request->set_body_params(
			array(
				'startDate'  => gmdate( 'Y-m-d', strtotime( '-28 days' ) ),
				'endDate'    => gmdate( 'Y-m-d', strtotime( '-1 day' ) ),
				'dimensions' => array( 'query' ),
				'url'        => $url,
				'limit'      => 10,
			)
		);

		$response = rest_do_request( $request );

		if ( $response->is_error() ) {
			return null;
		}

		$data = $response->get_data();

		if ( empty( $data ) || ! is_array( $data ) ) {
			return null;
		}

		$queries = array();

		foreach ( array_slice( $data, 0, 10 ) as $row ) {
			$queries[] = array(
				'query'       => $row['keys'][0] ?? $row['query'] ?? '',
				'clicks'      => (int) ( $row['clicks'] ?? 0 ),
				'impressions' => (int) ( $row['impressions'] ?? 0 ),
				'ctr'         => round( (float) ( $row['ctr'] ?? 0 ) * 100, 1 ),
				'position'    => round( (float) ( $row['position'] ?? 0 ), 1 ),
			);
		}

		return array(
			'period'      => 'last 28 days',
			'top_queries' => $queries,
		);
	}

	/**
	 * Get tool annotations.
	 *
	 * @return array
	 */
	public function get_annotations(): array {
		return array(
			'readonly' => true,
		);
	}
}

return new Analyze_Search_Intent();
