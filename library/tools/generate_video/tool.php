<?php
/**
 * Tool: generate_video
 *
 * Generate a short video clip from a text prompt via the Agentic Video Generation
 * service (Vertex AI Veo 2 / Veo 3). Returns a CDN URL for immediate embedding.
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
 * Generate a short video clip (5–8 seconds) from a text prompt using Vertex AI Veo.
 * Returns a CDN URL (https://videos.agentic-plugin.com/...) for immediate embedding.
 * Credit costs: Veo 2 = 50 credits/second, Veo 3 = 85 credits/second (min 5 seconds billed).
 */
class Generate_Video extends \Agentic\Tool_Base {

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'generate_video';
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return 'Generate a video clip from a text prompt using Vertex AI Veo 2 or Veo 3. Supported durations: 6 or 8 seconds (requested value is snapped to the nearest — the service rejects 4-second requests). Returns a CDN URL for immediate embedding. Credit costs: 50 credits/second (veo-2) or 85 credits/second (veo-3 with native audio). 1 credit = $0.01 USD. Requires a connected Agentic AI account.';
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
					'description' => 'Detailed text description of the video scene to generate. Include: subject, action, visual style, lighting, camera movement, and mood for best results.',
				),
				'model'            => array(
					'type'        => 'string',
					'description' => '"veo-2" (50 credits/sec, visual only, default) or "veo-3" (85 credits/sec, includes native audio/music/SFX). Use veo-3 when audio is required.',
					'enum'        => array( 'veo-2', 'veo-3' ),
				),
				'duration_seconds' => array(
					'type'        => 'integer',
					'description' => 'Desired clip duration in seconds. The service only accepts 6 or 8 seconds — any other value is snapped to the nearest. Default 8.',
					'minimum'     => 6,
					'maximum'     => 8,
				),
				'aspect_ratio'     => array(
					'type'        => 'string',
					'description' => 'Aspect ratio: "16:9" (landscape, default), "9:16" (vertical/social), "1:1" (square).',
					'enum'        => array( '16:9', '9:16', '1:1' ),
				),
				'generate_audio'   => array(
					'type'        => 'boolean',
					'description' => 'Generate native audio (music, SFX, ambient sound) alongside the video. Only available with veo-3. Default false.',
				),
				'enhance_prompt'   => array(
					'type'        => 'boolean',
					'description' => 'Let the service automatically enhance the prompt for better visual quality. Default true. Set to false only when providing a carefully crafted cinematic prompt.',
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
		$config = $this->get_videogen_config();
		if ( isset( $config['error'] ) ) {
			return $config;
		}

		$default_video_model = in_array( get_option( 'agent_builder_video_model', 'veo-2' ), array( 'veo-2', 'veo-3' ), true )
			? get_option( 'agent_builder_video_model', 'veo-2' )
			: 'veo-2';
		$model           = in_array( $arguments['model'] ?? $default_video_model, array( 'veo-2', 'veo-3' ), true )
			? ( $arguments['model'] ?? $default_video_model )
			: $default_video_model;
		// Only 6 and 8 are accepted end-to-end — the service's request validation
		// rejects 4 (and anything below 5) before the business-rule check runs.
		$valid_durations = array( 6, 8 );
		$requested_dur   = (int) ( $arguments['duration_seconds'] ?? 8 );
		$duration        = $valid_durations[ array_search( min( array_map( fn( $d ) => abs( $d - $requested_dur ), $valid_durations ) ), array_map( fn( $d ) => abs( $d - $requested_dur ), $valid_durations ) ) ];
		$aspect_ratio    = in_array( $arguments['aspect_ratio'] ?? '16:9', array( '16:9', '9:16', '1:1' ), true )
			? ( $arguments['aspect_ratio'] ?? '16:9' )
			: '16:9';
		$generate_audio  = (bool) ( $arguments['generate_audio'] ?? false );
		$enhance_prompt  = isset( $arguments['enhance_prompt'] ) ? (bool) $arguments['enhance_prompt'] : true;

		// Audio only supported on Veo 3; silently correct mismatched config.
		if ( $generate_audio && 'veo-3' !== $model ) {
			$generate_audio = false;
		}

		$result = $this->videogen_post(
			'/generate',
			$config,
			array(
				'prompt'           => sanitize_text_field( $arguments['prompt'] ),
				'model'            => $model,
				'duration_seconds' => $duration,
				'aspect_ratio'     => $aspect_ratio,
				'generate_audio'   => $generate_audio,
				'enhance_prompt'   => $enhance_prompt,
			)
		);

		if ( isset( $result['error'] ) ) {
			return $result;
		}

		// Build a friendly summary for the LLM to relay.
		$video_url = $result['video_url'] ?? null;
		return array(
			'job_id'            => $result['job_id'] ?? null,
			'video_url'         => $video_url,
			'embed_code'        => $video_url ? '<video src="' . esc_url( $video_url ) . '" controls playsinline></video>' : null,
			'model'             => $result['model'] ?? $model,
			'duration_seconds'  => $result['duration_seconds'] ?? $duration,
			'aspect_ratio'      => $result['aspect_ratio'] ?? $aspect_ratio,
			'has_audio'         => $result['has_audio'] ?? false,
			'credits_used'      => $result['credits_used'] ?? null,
			'credits_remaining' => $result['credits_remaining'] ?? null,
		);
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

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
	 * @param string $endpoint Path (e.g. '/generate').
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
				'timeout' => 300, // Veo generation can take 1–3 minutes.
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

return new Generate_Video();
