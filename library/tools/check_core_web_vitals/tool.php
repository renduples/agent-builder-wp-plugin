<?php
/**
 * Tool: check_core_web_vitals
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

class Check_Core_Web_Vitals extends Tool_Base {
	public function get_name(): string {
		return 'check_core_web_vitals';
	}

	public function get_description(): string {
		return 'Check Core Web Vitals (LCP, INP, CLS) for a URL using Google PageSpeed Insights API.';
	}

	public function get_category(): string {
		return 'site-health';
	}

	public function get_parameters(): array {
		return array(
			'url'      => array(
				'type'        => 'string',
				'description' => 'The URL to test. Defaults to homepage.',
				'required'    => false,
			),
			'strategy' => array(
				'type'        => 'string',
				'description' => "Device strategy. Defaults to 'mobile'.",
				'required'    => false,
				'enum'        => array( 'mobile', 'desktop' ),
			),
		);
	}

	public function get_annotations(): array {
		return array(
			'read_only'   => true,
			'destructive' => false,
		);
	}

	public function execute( array $args ): array {
		$url      = $args['url'] ?? home_url( '/' );
		$strategy = in_array( $args['strategy'] ?? 'mobile', array( 'mobile', 'desktop' ), true ) ? ( $args['strategy'] ?? 'mobile' ) : 'mobile';

		// Free plugin: PageSpeed Insights requires the site's own Google API
		// key — no bundled/shared key ships here. (Agent Builder Pro has its
		// own separate, managed PageSpeed channel; this tool doesn't call it.)
		$api_key = (string) get_option( 'agent_builder_psi_api_key', '' );
		if ( empty( $api_key ) ) {
			return array(
				'error'     => 'Core Web Vitals checks need a free Google PageSpeed Insights API key — add yours in Settings > APIs (takes about 2 minutes, no cost). See: https://agentic-plugin.com/pagespeed-insights-api-key/.',
				'setup_url' => 'https://agentic-plugin.com/pagespeed-insights-api-key/',
			);
		}

		$query_args = array(
			'url'      => $url,
			'strategy' => $strategy,
			'category' => 'performance',
			'key'      => $api_key,
		);

		$api_url = add_query_arg( $query_args, 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed' );

		$response = wp_remote_get( $api_url, array( 'timeout' => 60 ) );
		if ( is_wp_error( $response ) ) {
			return array( 'error' => 'PageSpeed API request failed: ' . $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || empty( $body ) ) {
			$error_msg = $body['error']['message'] ?? 'Unknown API error (HTTP ' . $code . ')';
			return array( 'error' => 'PageSpeed API error: ' . $error_msg );
		}

		$field_data        = $body['loadingExperience'] ?? array();
		$metrics           = $field_data['metrics'] ?? array();
		$origin_data       = $body['originLoadingExperience'] ?? array();
		$origin_metrics    = $origin_data['metrics'] ?? array();
		$effective_metrics = ! empty( $metrics ) ? $metrics : $origin_metrics;
		$data_source       = ! empty( $metrics ) ? 'page' : ( ! empty( $origin_metrics ) ? 'origin' : 'none' );

		$cwv = array();

		if ( isset( $effective_metrics['LARGEST_CONTENTFUL_PAINT_MS'] ) ) {
			$lcp_ms     = $effective_metrics['LARGEST_CONTENTFUL_PAINT_MS']['percentile'] ?? 0;
			$cwv['lcp'] = array(
				'value_ms'  => $lcp_ms,
				'value_s'   => round( $lcp_ms / 1000, 2 ),
				'rating'    => $lcp_ms <= 2500 ? 'good' : ( $lcp_ms <= 4000 ? 'needs_improvement' : 'poor' ),
				'threshold' => '≤ 2.5s good, ≤ 4.0s needs improvement',
			);
		}

		if ( isset( $effective_metrics['INTERACTION_TO_NEXT_PAINT'] ) ) {
			$inp_ms     = $effective_metrics['INTERACTION_TO_NEXT_PAINT']['percentile'] ?? 0;
			$cwv['inp'] = array(
				'value_ms'  => $inp_ms,
				'rating'    => $inp_ms <= 200 ? 'good' : ( $inp_ms <= 500 ? 'needs_improvement' : 'poor' ),
				'threshold' => '≤ 200ms good, ≤ 500ms needs improvement',
			);
		}

		if ( isset( $effective_metrics['CUMULATIVE_LAYOUT_SHIFT_SCORE'] ) ) {
			$cls_raw    = $effective_metrics['CUMULATIVE_LAYOUT_SHIFT_SCORE']['percentile'] ?? 0;
			$cls        = $cls_raw / 100;
			$cwv['cls'] = array(
				'value'     => $cls,
				'rating'    => $cls <= 0.1 ? 'good' : ( $cls <= 0.25 ? 'needs_improvement' : 'poor' ),
				'threshold' => '≤ 0.1 good, ≤ 0.25 needs improvement',
			);
		}

		$lighthouse = $body['lighthouseResult'] ?? array();
		$perf_score = isset( $lighthouse['categories']['performance']['score'] ) ? round( $lighthouse['categories']['performance']['score'] * 100 ) : null;

		$lab_audits = $lighthouse['audits'] ?? array();
		$lab_data   = array();
		foreach ( array( 'largest-contentful-paint', 'total-blocking-time', 'cumulative-layout-shift', 'speed-index', 'first-contentful-paint', 'interactive' ) as $audit_key ) {
			if ( isset( $lab_audits[ $audit_key ] ) ) {
				$lab_data[ $audit_key ] = array(
					'display_value' => $lab_audits[ $audit_key ]['displayValue'] ?? null,
					'score'         => $lab_audits[ $audit_key ]['score'] ?? null,
				);
			}
		}

		$opportunities = array();
		if ( isset( $lighthouse['audits'] ) ) {
			foreach ( $lighthouse['audits'] as $key => $audit ) {
				if ( isset( $audit['details']['type'] ) && 'opportunity' === $audit['details']['type'] && ( $audit['score'] ?? 1 ) < 0.9 ) {
					$opportunities[] = array(
						'audit'   => $key,
						'title'   => $audit['title'] ?? $key,
						'savings' => $audit['details']['overallSavingsMs'] ?? null,
					);
				}
			}
			usort( $opportunities, fn( $a, $b ) => ( $b['savings'] ?? 0 ) - ( $a['savings'] ?? 0 ) );
			$opportunities = array_slice( $opportunities, 0, 5 );
		}

		$all_good = true;
		foreach ( $cwv as $metric ) {
			if ( 'good' !== ( $metric['rating'] ?? '' ) ) {
				$all_good = false;
				break;
			}
		}

		$result = array(
			'url'               => $url,
			'strategy'          => $strategy,
			'data_source'       => $data_source,
			'core_web_vitals'   => $cwv,
			'all_cwv_passing'   => $all_good,
			'performance_score' => $perf_score,
			'lab_data'          => $lab_data,
			'opportunities'     => $opportunities,
		);

		return $result;
	}
}

return new Check_Core_Web_Vitals();
