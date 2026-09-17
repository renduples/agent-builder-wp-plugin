<?php
/**
 * Tool: add_audio_track
 *
 * Mix a royalty-free audio track onto a video via the Agentic Video Generation
 * service. Audio is looped to match the video duration; any existing audio on
 * the source video is replaced.
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
 * Mix an audio track (URL or WordPress attachment) onto a video clip.
 * Audio is looped to fill the video duration. Any existing audio is replaced.
 * Credit cost: 10 credits flat.
 */
class Add_Audio_Track extends \Agentic\Tool_Base {

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'add_audio_track';
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return 'Mix an audio track onto a video clip. Audio is looped to fill the full video duration and any existing audio is replaced. Pass a preview_url from search_free_music, a direct HTTPS URL to an MP3/WAV/AAC file, or a WordPress media library attachment ID. Credit cost: 10 credits flat.';
	}

	/**
	 * Get the tool category.
	 *
	 * @return string
	 */
	public function get_category(): string {
		return 'media';
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
				'video_url'     => array(
					'type'        => 'string',
					'description' => 'HTTPS URL of the source video (e.g. the video_url returned by generate_video or stitch_videos).',
				),
				'audio_url'     => array(
					'type'        => 'string',
					'description' => 'HTTPS URL of the audio file to mix in (MP3, AAC, WAV). Use an audio_url from search_free_music, or any public direct-download URL.',
				),
				'attachment_id' => array(
					'type'        => 'integer',
					'description' => 'WordPress media library attachment ID of an audio file. Use this instead of audio_url when the audio is already in the media library.',
				),
				'volume'        => array(
					'type'        => 'number',
					'description' => 'Volume multiplier for the audio track (0.0 = silent, 1.0 = original level, 2.0 = double). Default 1.0.',
					'minimum'     => 0.0,
					'maximum'     => 2.0,
				),
			),
			'required'   => array( 'video_url' ),
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Validated arguments from the LLM.
	 * @return array Result data.
	 */
	public function execute( array $arguments ): array {
		$config = $this->get_videogen_config();
		if ( isset( $config['error'] ) ) {
			return $config;
		}

		$video_url = (string) ( $arguments['video_url'] ?? '' );
		if ( empty( $video_url ) || ! str_starts_with( $video_url, 'https://' ) ) {
			return array( 'error' => 'video_url must be a valid HTTPS URL.' );
		}

		// Resolve audio: prefer attachment_id, fall back to audio_url.
		$audio_url = '';
		if ( ! empty( $arguments['attachment_id'] ) ) {
			$resolved = $this->attachment_to_url( (int) $arguments['attachment_id'] );
			if ( isset( $resolved['error'] ) ) {
				return $resolved;
			}
			$audio_url = $resolved['url'];
		} elseif ( ! empty( $arguments['audio_url'] ) ) {
			$audio_url = (string) $arguments['audio_url'];
		} else {
			return array( 'error' => 'Provide either audio_url or attachment_id for the audio track.' );
		}

		if ( ! str_starts_with( $audio_url, 'https://' ) ) {
			return array( 'error' => 'audio_url must be a valid HTTPS URL.' );
		}

		$volume = isset( $arguments['volume'] ) ? (float) $arguments['volume'] : 1.0;
		$volume = max( 0.0, min( 2.0, $volume ) );

		$result = $this->videogen_post(
			'/add-audio',
			$config,
			array(
				'video_url' => $video_url,
				'audio_url' => $audio_url,
				'volume'    => $volume,
			)
		);

		if ( isset( $result['error'] ) ) {
			return $result;
		}

		$video_url_out = $result['video_url'] ?? null;
		return array(
			'job_id'            => $result['job_id'] ?? null,
			'video_url'         => $video_url_out,
			'embed_code'        => $video_url_out ? '<video src="' . esc_url( $video_url_out ) . '" controls playsinline></video>' : null,
			'duration_seconds'  => $result['duration_seconds'] ?? null,
			'credits_used'      => $result['credits_used'] ?? null,
			'credits_remaining' => $result['credits_remaining'] ?? null,
		);
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Resolve a media library attachment to a public URL.
	 *
	 * @param int $attachment_id WP attachment ID.
	 * @return array{url: string}|array{error: string}
	 */
	private function attachment_to_url( int $attachment_id ): array {
		$url = wp_get_attachment_url( $attachment_id );
		if ( empty( $url ) ) {
			return array( 'error' => 'Attachment #' . $attachment_id . ' not found or has no URL.' );
		}

		$mime = get_post_mime_type( $attachment_id );
		if ( ! $mime || ! str_starts_with( $mime, 'audio/' ) ) {
			return array( 'error' => 'Attachment #' . $attachment_id . ' is not an audio file.' );
		}

		return array( 'url' => $url );
	}

	/**
	 * Read and validate the videogen service configuration.
	 *
	 * @return array Config with api_key + user_id, or array with 'error' key.
	 */
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
			return array( 'error' => 'Agentic Video Generation requires connecting an Agentic AI account — go to Agent Builder → Settings → Providers and connect Agentic AI.' );
		}

		return array(
			'api_key' => $api_key,
			'user_id' => $user_id,
		);
	}

	/**
	 * POST to the videogen service.
	 *
	 * @param string $endpoint Path (e.g. '/add-audio').
	 * @param array  $config   Config from get_videogen_config().
	 * @param array  $body     Request payload (user_id will be injected).
	 * @return array Decoded response or array with 'error' key.
	 */
	private function videogen_post( string $endpoint, array $config, array $body ): array {
		$body['user_id']  = $config['user_id'];
		$body['site_url'] = get_site_url();

		$response = wp_remote_post(
			\Agentic\Service_Registry::url( 'agentic-videogen', $endpoint ),
			array(
				'timeout' => 120,
				'headers' => array(
					'X-API-Key'        => $config['api_key'],
					'Content-Type'     => 'application/json',
					'X-Plugin-Version' => AGENT_BUILDER_VERSION,
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'error' => 'Video service request failed: ' . $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 400 ) {
			$msg = isset( $data['detail'] ) ? (string) $data['detail'] : 'Video service error (HTTP ' . $code . ')';
			return array( 'error' => $msg );
		}

		return is_array( $data ) ? $data : array();
	}
}

return new Add_Audio_Track();
