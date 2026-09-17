<?php
/**
 * Tool: generate_video_from_image
 *
 * Animate a static image into a short video clip via the Agentic Video Generation
 * service (Vertex AI Veo 2 image-to-video). Accepts a base64-encoded image or
 * a WordPress media library attachment ID.
 *
 * @package    Agent_Builder
 * @subpackage Tools
 * @since      3.0.0
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Animate a static image into a short video clip (5–8 seconds) using Vertex AI Veo 2.
 * Accepts a base64-encoded image or a WordPress attachment ID.
 * Only Veo 2 supports image-to-video. Credit cost: 50 credits/second (min 5 seconds billed).
 */
class Generate_Video_From_Image extends \Agentic\Tool_Base {

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'generate_video_from_image';
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return 'Animate a static image into a short video clip (5–8 seconds) using Vertex AI Veo 2 image-to-video. Accepts a WordPress media library attachment ID or a base64-encoded image. Describe the motion to apply (e.g. "slow zoom in", "camera pan left", "clouds drifting"). Credit cost: 50 credits/second, min 5 seconds billed. Only Veo 2 supports image-to-video.';
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
				'prompt'           => array(
					'type'        => 'string',
					'description' => 'Description of the motion to apply to the image (e.g. "slow zoom in on the coffee cup", "gentle camera pan from left to right", "smoke rising from the building").',
				),
				'attachment_id'    => array(
					'type'        => 'integer',
					'description' => 'WordPress media library attachment ID of the source image. Preferred when an image is already in the media library.',
				),
				'image_base64'     => array(
					'type'        => 'string',
					'description' => 'Base64-encoded image data (with or without data URI prefix). Use this when the user has pasted or uploaded an image directly in chat.',
				),
				'duration_seconds' => array(
					'type'        => 'integer',
					'description' => 'Desired clip duration in seconds. The service only accepts 6 or 8 seconds — any other value is snapped to the nearest. Default 8.',
					'minimum'     => 6,
					'maximum'     => 8,
				),
				'aspect_ratio'     => array(
					'type'        => 'string',
					'description' => 'Aspect ratio: "16:9" (landscape, default), "9:16" (vertical/social), "1:1" (square). Should match the source image dimensions when possible.',
					'enum'        => array( '16:9', '9:16', '1:1' ),
				),
				'enhance_prompt'   => array(
					'type'        => 'boolean',
					'description' => 'Let the service automatically enhance the motion prompt. Default true.',
				),
			),
			'required'   => array( 'prompt' ),
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Validated arguments from the LLM.
	 * @return array Result data.
	 */
	public function execute( array $arguments ): array {
		if ( ! current_user_can( 'upload_files' ) ) {
			return array( 'error' => 'You do not have permission to access media files.' );
		}

		$config = $this->get_videogen_config();
		if ( isset( $config['error'] ) ) {
			return $config;
		}

		// Resolve image: prefer attachment_id, fall back to base64.
		$image_b64 = '';
		if ( ! empty( $arguments['attachment_id'] ) ) {
			$resolved = $this->attachment_to_base64( (int) $arguments['attachment_id'] );
			if ( isset( $resolved['error'] ) ) {
				return $resolved;
			}
			$image_b64 = $resolved['base64'];
		} elseif ( ! empty( $arguments['image_base64'] ) ) {
			// Strip data URI prefix if present.
			$image_b64 = (string) preg_replace( '#^data:image/\w+;base64,#', '', $arguments['image_base64'] );
		} else {
			return array( 'error' => 'Provide either attachment_id (media library) or image_base64 (pasted/uploaded image).' );
		}

		if ( empty( $image_b64 ) ) {
			return array( 'error' => 'Could not resolve image data. Check the attachment ID or base64 string.' );
		}

		// Only 6 and 8 are accepted end-to-end — the service's request validation
		// rejects 4 (and anything below 5) before the business-rule check runs.
		$valid_durations = array( 6, 8 );
		$requested_dur   = (int) ( $arguments['duration_seconds'] ?? 8 );
		$duration        = $valid_durations[ array_search( min( array_map( fn( $d ) => abs( $d - $requested_dur ), $valid_durations ) ), array_map( fn( $d ) => abs( $d - $requested_dur ), $valid_durations ), true ) ];
		$aspect_ratio    = in_array( $arguments['aspect_ratio'] ?? '16:9', array( '16:9', '9:16', '1:1' ), true )
			? ( $arguments['aspect_ratio'] ?? '16:9' )
			: '16:9';
		$enhance_prompt  = isset( $arguments['enhance_prompt'] ) ? (bool) $arguments['enhance_prompt'] : true;

		$result = $this->videogen_post(
			'/image-to-video',
			$config,
			array(
				'prompt'           => sanitize_text_field( $arguments['prompt'] ),
				'image'            => $image_b64,
				'model'            => 'veo-2', // Only Veo 2 supports image-to-video.
				'duration_seconds' => $duration,
				'aspect_ratio'     => $aspect_ratio,
				'enhance_prompt'   => $enhance_prompt,
			)
		);

		if ( isset( $result['error'] ) ) {
			return $result;
		}

		$video_url = $result['video_url'] ?? null;
		return array(
			'job_id'            => $result['job_id'] ?? null,
			'video_url'         => $video_url,
			'embed_code'        => $video_url ? '<video src="' . esc_url( $video_url ) . '" controls playsinline></video>' : null,
			'model'             => $result['model'] ?? 'veo-2',
			'duration_seconds'  => $result['duration_seconds'] ?? $duration,
			'aspect_ratio'      => $result['aspect_ratio'] ?? $aspect_ratio,
			'has_audio'         => false, // Veo 2 image-to-video does not produce audio.
			'credits_used'      => $result['credits_used'] ?? null,
			'credits_remaining' => $result['credits_remaining'] ?? null,
		);
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Convert a media library attachment to a base64-encoded string.
	 *
	 * @param int $attachment_id WP attachment ID.
	 * @return array{base64: string}|array{error: string}
	 */
	private function attachment_to_base64( int $attachment_id ): array {
		$path = get_attached_file( $attachment_id );
		if ( empty( $path ) || ! file_exists( $path ) ) {
			return array( 'error' => 'Attachment #' . $attachment_id . ' not found or file does not exist on disk.' );
		}

		$mime = get_post_mime_type( $attachment_id );
		if ( ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif' ), true ) ) {
			return array( 'error' => 'Attachment #' . $attachment_id . ' is not a supported image type (JPEG, PNG, WebP, or GIF).' );
		}

		$b64 = base64_encode( (string) file_get_contents( $path ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Encoding local file for API.

		return array( 'base64' => $b64 );
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
	 * @param string $endpoint Path (e.g. '/image-to-video').
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
				'timeout' => 300,
				'headers' => array(
					'X-API-Key'        => $config['api_key'],
					'Content-Type'     => 'application/json',
					'X-Plugin-Version' => AGENT_BUILDER_VERSION,
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'error' => 'Video generation service request failed: ' . $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 400 ) {
			if ( 429 === (int) $code || 402 === (int) $code ) {
				$msg = isset( $data['detail'] ) ? (string) $data['detail'] : 'Video generation service error (HTTP ' . $code . ')';
				return array( 'error' => $msg );
			}
			$msg = isset( $data['detail'] ) ? (string) $data['detail'] : 'Video generation service error (HTTP ' . $code . ')';
			return array( 'error' => $msg );
		}

		return is_array( $data ) ? $data : array();
	}
}

return new Generate_Video_From_Image();
