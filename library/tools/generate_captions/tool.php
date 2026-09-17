<?php
/**
 * Tool: generate_captions
 *
 * Transcribe speech in a video using Gemini AI and optionally burn the
 * SRT subtitles into the video using FFmpeg libass.
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
 * Transcribe speech in a video to SRT subtitles using Gemini AI (10 credits)
 * and optionally burn captions into the video with FFmpeg (15 credits total).
 * Provide srt_content to skip AI transcription and just burn (5 credits).
 */
class Generate_Captions extends \Agentic\Tool_Base {

	public function get_name(): string {
		return 'generate_captions';
	}

	public function get_description(): string {
		return 'Transcribe speech in a video to SRT subtitles using Gemini AI (10 credits) and optionally burn them into the video with FFmpeg (15 credits total). Provide srt_content to skip AI transcription and burn custom captions instead (5 credits). Returns the SRT content and optionally a CDN URL for the captioned video.';
	}

	public function get_category(): string {
		return 'media';
	}

	public function get_parameters(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'video_url'   => array(
					'type'        => 'string',
					'description' => 'HTTPS URL of the video to transcribe/caption.',
				),
				'burn'        => array(
					'type'        => 'boolean',
					'description' => 'Burn the captions into the video (creates a new video with subtitles rendered in). Default true. Set false to only return the SRT text.',
				),
				'srt_content' => array(
					'type'        => 'string',
					'description' => 'Custom SRT subtitle content to use instead of AI transcription. Useful when you already have a transcript or want to add translated captions.',
				),
				'font_size'   => array(
					'type'        => 'integer',
					'description' => 'Subtitle font size in pixels (12–72). Default 24.',
					'minimum'     => 12,
					'maximum'     => 72,
				),
			),
			'required'   => array( 'video_url' ),
		);
	}

	public function execute( array $arguments ): array {
		$config = $this->get_videogen_config();
		if ( isset( $config['error'] ) ) {
			return $config;
		}

		$video_url   = esc_url_raw( $arguments['video_url'] ?? '' );
		$burn        = isset( $arguments['burn'] ) ? (bool) $arguments['burn'] : true;
		$srt_content = sanitize_textarea_field( $arguments['srt_content'] ?? '' );
		$font_size   = min( 72, max( 12, (int) ( $arguments['font_size'] ?? 24 ) ) );

		if ( empty( $video_url ) || ! str_starts_with( $video_url, 'https://' ) ) {
			return array( 'error' => 'video_url must be a valid HTTPS URL.' );
		}

		$response = wp_remote_post(
			\Agentic\Service_Registry::url( 'agentic-videogen', '/captions' ),
			array(
				'timeout' => 300, // Transcription + burn can take several minutes.
				'headers' => array(
					'X-API-Key'        => $config['api_key'],
					'Content-Type'     => 'application/json',
					'X-Plugin-Version' => AGENT_BUILDER_VERSION,
				),
				'body'    => wp_json_encode(
					array(
						'user_id'     => $config['user_id'],
						'video_url'   => $video_url,
						'burn'        => $burn,
						'srt_content' => $srt_content,
						'font_size'   => $font_size,
						'site_url'    => get_site_url(),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'error' => 'Captions request failed: ' . $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 400 ) {
			return array( 'error' => isset( $data['detail'] ) ? (string) $data['detail'] : "Service error (HTTP $code)" );
		}

		return is_array( $data ) ? $data : array( 'error' => 'Unexpected response from captions service.' );
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

return new Generate_Captions();
