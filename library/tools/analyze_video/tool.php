<?php
/**
 * Tool: analyze_video
 *
 * Probe a video URL with ffprobe to extract metadata (duration, resolution,
 * FPS, codecs, file size). Optionally request a Gemini AI description (5 credits).
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
 * Probe a video URL for technical metadata using ffprobe (free).
 * Optionally add a Gemini AI scene description for 5 credits.
 */
class Analyze_Video extends \Agentic\Tool_Base {

	public function get_name(): string {
		return 'analyze_video';
	}

	public function get_description(): string {
		return 'Probe a video URL to extract technical metadata: duration, resolution, FPS, codecs, file size, and aspect ratio. Free for basic ffprobe data. Add ai_description=true for a Gemini AI scene description (5 credits).';
	}

	public function get_category(): string {
		return 'media';
	}

	public function get_parameters(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'video_url'      => array(
					'type'        => 'string',
					'description' => 'HTTPS URL of the video to analyze (e.g. https://videos.agentic-plugin.com/videos/job-id/output.mp4).',
				),
				'ai_description' => array(
					'type'        => 'boolean',
					'description' => 'Request a Gemini AI natural-language description of the video content (5 credits). Default false.',
				),
			),
			'required'   => array( 'video_url' ),
		);
	}

	public function get_annotations(): array {
		return array(
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		);
	}

	public function execute( array $arguments ): array {
		$config = $this->get_videogen_config();
		if ( isset( $config['error'] ) ) {
			return $config;
		}

		$video_url      = esc_url_raw( $arguments['video_url'] ?? '' );
		$ai_description = (bool) ( $arguments['ai_description'] ?? false );

		if ( empty( $video_url ) || ! str_starts_with( $video_url, 'https://' ) ) {
			return array( 'error' => 'video_url must be a valid HTTPS URL.' );
		}

		$response = wp_remote_post(
			\Agentic\Service_Registry::url( 'agentic-videogen', '/analyze' ),
			array(
				'timeout' => 60,
				'headers' => array(
					'X-API-Key'        => $config['api_key'],
					'Content-Type'     => 'application/json',
					'X-Plugin-Version' => AGENT_BUILDER_VERSION,
				),
				'body'    => wp_json_encode(
					array(
						'user_id'        => $config['user_id'],
						'video_url'      => $video_url,
						'ai_description' => $ai_description,
						'site_url'       => get_site_url(),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'error' => 'Analyze request failed: ' . $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 400 ) {
			return array( 'error' => isset( $data['detail'] ) ? (string) $data['detail'] : "Service error (HTTP $code)" );
		}

		return is_array( $data ) ? $data : array( 'error' => 'Unexpected response from analyze service.' );
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

return new Analyze_Video();
