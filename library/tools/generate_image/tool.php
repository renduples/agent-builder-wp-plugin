<?php
/**
 * Tool: generate_image
 *
 * Generate images from a text prompt via the Agentic Image Generation service
 * (Gemini 3.1 Flash Image) and save them to the WordPress media library.
 *
 * @package    Agent_Builder
 * @subpackage Tools
 * @since      2.2.7
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generate one or more images from a text prompt and save them to the media library.
 * Powered by Agentic Image Generation (Gemini 3.1 Flash Image). Requires a connected Agentic AI account.
 * Credit costs: imagen-4-fast = 5/image, imagen-4 = 10/image, imagen-4-ultra = 23/image.
 */
class Generate_Image extends \Agentic\Tool_Base {

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'generate_image';
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return 'Generate one or more images from a text prompt and save them to the WordPress media library. Powered by Agentic Image Generation (Gemini 3.1 Flash Image). Credit costs: 5 credits for imagen-4-fast, 10 for imagen-4 (default), 23 for imagen-4-ultra — per image. Requires a connected Agentic AI account.';
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
				'prompt'          => array(
					'type'        => 'string',
					'description' => 'Text description of the image to generate.',
				),
				'negative_prompt' => array(
					'type'        => 'string',
					'description' => 'Elements to exclude from the image (optional).',
				),
				'model'           => array(
					'type'        => 'string',
					'description' => '"imagen-4-fast" (5 credits/image), "imagen-4" (10 credits/image, default), "imagen-4-ultra" (23 credits/image, best quality).',
					'enum'        => array( 'imagen-4-fast', 'imagen-4', 'imagen-4-ultra' ),
				),
				'count'           => array(
					'type'        => 'integer',
					'description' => 'Number of images to generate (1–4). Default 1.',
					'minimum'     => 1,
					'maximum'     => 4,
				),
				'aspect_ratio'    => array(
					'type'        => 'string',
					'description' => 'Aspect ratio: "1:1" (default), "16:9", "9:16", "4:3", "3:4".',
					'enum'        => array( '1:1', '16:9', '9:16', '4:3', '3:4' ),
				),
				'filename'        => array(
					'type'        => 'string',
					'description' => 'Base filename for the saved image (without extension). Defaults to a slug derived from the prompt.',
				),
				'alt_text'        => array(
					'type'        => 'string',
					'description' => 'Alt text to assign to the uploaded image. Defaults to the prompt.',
				),
				'post_id'         => array(
					'type'        => 'integer',
					'description' => 'If provided, the first generated image will be set as the featured image for this post.',
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
			return array( 'error' => 'You do not have permission to upload files.' );
		}

		$config = $this->get_imagegen_config();
		if ( isset( $config['error'] ) ) {
			return $config;
		}

		$model        = in_array( $arguments['model'] ?? 'imagen-4', array( 'imagen-4-fast', 'imagen-4', 'imagen-4-ultra' ), true )
			? ( $arguments['model'] ?? 'imagen-4' )
			: 'imagen-4';
		$count        = min( max( (int) ( $arguments['count'] ?? 1 ), 1 ), 4 );
		$aspect_ratio = in_array( $arguments['aspect_ratio'] ?? '1:1', array( '1:1', '16:9', '9:16', '4:3', '3:4' ), true )
			? ( $arguments['aspect_ratio'] ?? '1:1' )
			: '1:1';

		$result = $this->imagegen_post(
			'/generate',
			$config,
			array(
				'prompt'          => sanitize_text_field( $arguments['prompt'] ),
				'negative_prompt' => sanitize_text_field( $arguments['negative_prompt'] ?? '' ),
				'model'           => $model,
				'count'           => $count,
				'aspect_ratio'    => $aspect_ratio,
				'safe_filter'     => true,
			)
		);

		if ( isset( $result['error'] ) ) {
			return $result;
		}

		if ( empty( $result['images'] ) || ! is_array( $result['images'] ) ) {
			return array( 'error' => 'No images returned by the generation service.' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Required by wp_handle_sideload chain.
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$prompt_slug = sanitize_title( wp_trim_words( $arguments['prompt'], 5, '' ) );
		$base_name   = sanitize_file_name( $arguments['filename'] ?? $prompt_slug );
		$alt_text    = sanitize_text_field( $arguments['alt_text'] ?? $arguments['prompt'] );
		$attachments = array();

		foreach ( $result['images'] as $i => $b64 ) {
			$attach = $this->sideload_base64( $b64, $base_name, $count, $i, $alt_text );
			if ( isset( $attach['error'] ) ) {
				$attachments[] = $attach;
				continue;
			}

			// Set as featured image if post_id provided and this is the first image.
			if ( 0 === $i && ! empty( $arguments['post_id'] ) ) {
				set_post_thumbnail( (int) $arguments['post_id'], $attach['attachment_id'] );
				$attach['set_as_featured'] = true;
			}

			$attachments[] = $attach;
		}

		return array(
			'images'            => $attachments,
			'model'             => $result['model'] ?? $model,
			'credits_used'      => $result['credits_used'] ?? null,
			'credits_remaining' => $result['credits_remaining'] ?? null,
			'count'             => count( $attachments ),
		);
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Read and validate the imagegen service configuration.
	 *
	 * @return array Config with api_key + user_id, or array with 'error' key.
	 */
	private function get_imagegen_config(): array {
		$api_key = (string) get_option( 'agent_builder_rag_api_secret', '' );
		if ( empty( $api_key ) && defined( 'AGENTIC_RAG_API_KEY' ) ) {
			$api_key = AGENTIC_RAG_API_KEY;
		}
		if ( empty( $api_key ) ) {
			$api_key = (string) ( \Agentic\Provider_Registry::get( 'agentic' )['api_key'] ?? '' );
		}
		$user_id = $api_key;

		if ( empty( $api_key ) ) {
			return array( 'error' => 'Agentic Image Generation requires connecting an Agentic AI account — go to Agent Builder → Settings → Providers and connect Agentic AI.' );
		}

		return array(
			'api_key' => $api_key,
			'user_id' => $user_id,
		);
	}

	/**
	 * POST to the imagegen service.
	 *
	 * @param string $endpoint Path (e.g. '/generate').
	 * @param array  $config   Config from get_imagegen_config().
	 * @param array  $body     Request payload (user_id will be injected).
	 * @return array Decoded response or array with 'error' key.
	 */
	private function imagegen_post( string $endpoint, array $config, array $body ): array {
		$body['user_id']  = $config['user_id'];
		$body['site_url'] = get_site_url();

		$response = wp_remote_post(
			\Agentic\Service_Registry::url( 'agentic-imagegen', $endpoint ),
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
			return array( 'error' => 'Service request failed: ' . $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 400 ) {
			if ( 429 === (int) $code || 402 === (int) $code ) {
				$msg = isset( $data['detail'] ) ? (string) $data['detail'] : 'Image generation service error (HTTP ' . $code . ')';
				return array( 'error' => $msg );
			}
			$msg = isset( $data['detail'] ) ? (string) $data['detail'] : 'Image generation service error (HTTP ' . $code . ')';
			return array( 'error' => $msg );
		}

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Decode a base64 image and sideload it into the WP media library.
	 *
	 * @param string $b64       Base64-encoded image data (with or without data URI prefix).
	 * @param string $base_name Filename base (no extension).
	 * @param int    $total     Total images in this batch (affects suffix logic).
	 * @param int    $index     Zero-based index in the batch.
	 * @param string $alt_text  Alt text to attach.
	 * @return array Attachment info or array with 'error' key.
	 */
	private function sideload_base64( string $b64, string $base_name, int $total, int $index, string $alt_text ): array {
		// Strip data URI prefix if present.
		$b64 = (string) preg_replace( '#^data:image/\w+;base64,#', '', $b64 );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding trusted API response from our own service.
		$binary = base64_decode( $b64, true );
		if ( false === $binary ) {
			return array( 'error' => 'Failed to decode image data from service response.' );
		}

		$suffix   = $total > 1 ? '-' . ( $index + 1 ) : '';
		$filename = $base_name . $suffix . '.png';

		$tmp = wp_tempnam( 'imagen' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing to local temp file created by wp_tempnam.
		file_put_contents( $tmp, $binary );

		$attach_id = media_handle_sideload(
			array(
				'name'     => $filename,
				'tmp_name' => $tmp,
			),
			0,
			sanitize_text_field( $base_name . $suffix )
		);

		wp_delete_file( $tmp );

		if ( is_wp_error( $attach_id ) ) {
			return array( 'error' => $attach_id->get_error_message() );
		}

		update_post_meta( $attach_id, '_wp_attachment_image_alt', $alt_text );

		return array(
			'attachment_id' => $attach_id,
			'url'           => wp_get_attachment_url( $attach_id ),
			'filename'      => $filename,
			'alt_text'      => $alt_text,
		);
	}
}

return new Generate_Image();
