<?php
/**
 * Tool: stitch_videos
 *
 * Concatenate multiple video clips into a single video using FFmpeg.
 * Clips are stitched in order using stream copy (no re-encode — fast).
 * Cost: 5 credits per input clip.
 *
 * @package    Agent_Builder
 * @subpackage Tools
 * @since      3.1.0
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Concatenate 2–20 video clips into one using FFmpeg stream copy.
 * Returns a CDN URL for the stitched video. Cost: 5 credits per clip.
 */
class Stitch_Videos extends \Agentic\Tool_Base {

	public function get_name(): string {
		return 'stitch_videos';
	}

	public function get_description(): string {
		return 'Concatenate 2–20 video clips into a single video in the order provided, using FFmpeg stream copy (no re-encode). Returns a CDN URL for the combined video. Cost: 5 credits per input clip. All clips must be HTTPS video URLs (e.g. CDN URLs returned by generate_video).';
	}

	public function get_category(): string {
		return 'media';
	}

	public function get_parameters(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'video_urls' => array(
					'type'        => 'array',
					'description' => 'Ordered list of HTTPS video URLs to concatenate (2–20 clips). Earlier items appear first in the output.',
					'items'       => array( 'type' => 'string' ),
					'minItems'    => 2,
					'maxItems'    => 20,
				),
			),
			'required'   => array( 'video_urls' ),
		);
	}

	public function execute( array $arguments ): array {
		$config = $this->get_videogen_config();
		if ( isset( $config['error'] ) ) {
			return $config;
		}

		$video_urls = $arguments['video_urls'] ?? array();
		if ( ! is_array( $video_urls ) || count( $video_urls ) < 2 ) {
			return array( 'error' => 'Provide at least 2 video URLs.' );
		}
		if ( count( $video_urls ) > 20 ) {
			return array( 'error' => 'Maximum 20 clips per stitch.' );
		}

		// Sanitise URLs
		$safe_urls = array_values(
			array_filter(
				array_map( 'esc_url_raw', $video_urls ),
				static fn( string $u ) => str_starts_with( $u, 'https://' )
			)
		);

		if ( count( $safe_urls ) < 2 ) {
			return array( 'error' => 'All video_urls must be valid HTTPS URLs.' );
		}

		$response = wp_remote_post(
			\Agentic\Service_Registry::url( 'agentic-videogen', '/stitch' ),
			array(
				'timeout' => 600, // Downloading + stitching can take several minutes.
				'headers' => array(
					'X-API-Key'        => $config['api_key'],
					'Content-Type'     => 'application/json',
					'X-Plugin-Version' => AGENT_BUILDER_VERSION,
				),
				'body'    => wp_json_encode(
					array(
						'user_id'    => $config['user_id'],
						'video_urls' => $safe_urls,
						'site_url'   => get_site_url(),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'error' => 'Stitch request failed: ' . $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 400 ) {
			return array( 'error' => isset( $data['detail'] ) ? (string) $data['detail'] : "Service error (HTTP $code)" );
		}

		return is_array( $data ) ? $data : array( 'error' => 'Unexpected response from stitch service.' );
	}

	private function get_videogen_config(): array {
		$api_key = (string) get_option( 'agent_builder_rag_api_secret', '' );
		if ( empty( $api_key ) && defined( 'AGENTIC_RAG_API_KEY' ) ) {
			$api_key = AGENTIC_RAG_API_KEY;
		}
		if ( empty( $api_key ) ) {
			$api_key = (string) ( \Agentic\Provider_Registry::get( 'agentic' )['api_key'] ?? '' );
		}
		$user_id = $api_key;

		if ( empty( $api_key ) ) {
			return array( 'error' => 'Agentic Video requires connecting an Agentic AI account — go to Agent Builder → Settings → Providers and connect Agentic AI.' );
		}
		return array(
			'api_key' => $api_key,
			'user_id' => $user_id,
		);
	}
}

return new Stitch_Videos();
