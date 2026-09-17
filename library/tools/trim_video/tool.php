<?php
/**
 * Tool: trim_video
 *
 * Trim a video to a specific time range using FFmpeg stream copy.
 * Fast and lossless — no re-encode. Cost: 5 credits flat.
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
 * Trim a video to [start, end] seconds using FFmpeg stream copy.
 * Returns a CDN URL for the trimmed clip. Cost: 5 credits flat.
 */
class Trim_Video extends \Agentic\Tool_Base {

	public function get_name(): string {
		return 'trim_video';
	}

	public function get_description(): string {
		return 'Trim a video to a specific time range [start_seconds, end_seconds] using FFmpeg stream copy (no re-encode, fast). Returns a CDN URL for the trimmed clip. Cost: 5 credits. Use analyze_video first to know the exact duration.';
	}

	public function get_category(): string {
		return 'media';
	}

	public function get_parameters(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'video_url'     => array(
					'type'        => 'string',
					'description' => 'HTTPS URL of the source video to trim.',
				),
				'start_seconds' => array(
					'type'        => 'number',
					'description' => 'Start time in seconds (inclusive). Default 0.',
					'minimum'     => 0,
				),
				'end_seconds'   => array(
					'type'        => 'number',
					'description' => 'End time in seconds (exclusive). Must be greater than start_seconds.',
					'minimum'     => 0.1,
				),
			),
			'required'   => array( 'video_url', 'end_seconds' ),
		);
	}

	public function execute( array $arguments ): array {
		$config = $this->get_videogen_config();
		if ( isset( $config['error'] ) ) {
			return $config;
		}

		$video_url     = esc_url_raw( $arguments['video_url'] ?? '' );
		$start_seconds = max( 0.0, (float) ( $arguments['start_seconds'] ?? 0.0 ) );
		$end_seconds   = (float) ( $arguments['end_seconds'] ?? 0.0 );

		if ( empty( $video_url ) || ! str_starts_with( $video_url, 'https://' ) ) {
			return array( 'error' => 'video_url must be a valid HTTPS URL.' );
		}
		if ( $end_seconds <= 0 || $end_seconds <= $start_seconds ) {
			return array( 'error' => 'end_seconds must be greater than start_seconds.' );
		}

		$response = wp_remote_post(
			\Agentic\Service_Registry::url( 'agentic-videogen', '/trim' ),
			array(
				'timeout' => 120,
				'headers' => array(
					'X-API-Key'        => $config['api_key'],
					'Content-Type'     => 'application/json',
					'X-Plugin-Version' => AGENT_BUILDER_VERSION,
				),
				'body'    => wp_json_encode(
					array(
						'user_id'       => $config['user_id'],
						'video_url'     => $video_url,
						'start_seconds' => $start_seconds,
						'end_seconds'   => $end_seconds,
						'site_url'      => get_site_url(),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'error' => 'Trim request failed: ' . $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 400 ) {
			return array( 'error' => isset( $data['detail'] ) ? (string) $data['detail'] : "Service error (HTTP $code)" );
		}

		return is_array( $data ) ? $data : array( 'error' => 'Unexpected response from trim service.' );
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

return new Trim_Video();
