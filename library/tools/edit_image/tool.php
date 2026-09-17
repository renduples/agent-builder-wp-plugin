<?php
/**
 * Tool: edit_image
 *
 * Edit an existing WordPress media library image using the Agentic Image Generation
 * service (Gemini 3.1 Flash Image conversational/semantic edit).
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
 * Edit an existing image from the media library using AI (conversational/semantic edit).
 * The edited result is saved as a new media library item.
 * Credit costs: 5 credits (imagen-4-fast), 10 credits (imagen-4, default), 23 credits (imagen-4-ultra).
 */
class Edit_Image extends \Agentic\Tool_Base {

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'edit_image';
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return 'Edit an existing image from the WordPress media library using AI. Describe what to change — the image is updated and saved as a new media library item. Optionally provide a mask attachment ID as a best-effort hint of which region to change; the edit is applied conversationally from the prompt, not pixel-precise inpainting. Credit costs: 5 (imagen-4-fast), 10 (imagen-4, default), 23 (imagen-4-ultra). Requires a connected Agentic AI account.';
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
				'attachment_id'      => array(
					'type'        => 'integer',
					'description' => 'WordPress attachment ID of the source image to edit.',
				),
				'prompt'             => array(
					'type'        => 'string',
					'description' => 'Description of the change to apply (e.g. "Replace the sky with a dramatic sunset").',
				),
				'mask_attachment_id' => array(
					'type'        => 'integer',
					'description' => 'Optional. Attachment ID of a mask image (PNG) used as a best-effort region hint — the edit is applied conversationally from the prompt, not pixel-precise inpainting, so results near the mask boundary may vary.',
				),
				'model'              => array(
					'type'        => 'string',
					'description' => '"imagen-4-fast" (5 credits), "imagen-4" (10 credits, default), "imagen-4-ultra" (23 credits).',
					'enum'        => array( 'imagen-4-fast', 'imagen-4', 'imagen-4-ultra' ),
				),
				'filename'           => array(
					'type'        => 'string',
					'description' => 'Base filename for the saved result (without extension). Defaults to original filename with "-edited" suffix.',
				),
				'alt_text'           => array(
					'type'        => 'string',
					'description' => 'Alt text for the new attachment. Defaults to the edit prompt.',
				),
				'post_id'            => array(
					'type'        => 'integer',
					'description' => 'If provided, set the edited image as the featured image for this post.',
				),
			),
			'required'   => array( 'attachment_id', 'prompt' ),
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

		// Load and validate source image.
		$source_id  = (int) $arguments['attachment_id'];
		$source_b64 = $this->attachment_to_base64( $source_id );
		if ( isset( $source_b64['error'] ) ) {
			return $source_b64;
		}

		$body = array(
			'prompt' => sanitize_text_field( $arguments['prompt'] ),
			'image'  => $source_b64['data'],
			'model'  => in_array( $arguments['model'] ?? 'imagen-4', array( 'imagen-4-fast', 'imagen-4', 'imagen-4-ultra' ), true )
				? ( $arguments['model'] ?? 'imagen-4' )
				: 'imagen-4',
		);

		// Load mask if provided.
		if ( ! empty( $arguments['mask_attachment_id'] ) ) {
			$mask_b64 = $this->attachment_to_base64( (int) $arguments['mask_attachment_id'] );
			if ( ! isset( $mask_b64['error'] ) ) {
				$body['mask'] = $mask_b64['data'];
			}
		}

		$result = $this->imagegen_post( '/edit', $config, $body );
		if ( isset( $result['error'] ) ) {
			return $result;
		}

		if ( empty( $result['images'][0] ) ) {
			return array( 'error' => 'No edited image returned by the service.' );
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$original_name = pathinfo( get_the_title( $source_id ), PATHINFO_FILENAME );
		$base_name     = sanitize_file_name( $arguments['filename'] ?? ( $original_name . '-edited' ) );
		$alt_text      = sanitize_text_field( $arguments['alt_text'] ?? $arguments['prompt'] );

		$attach = $this->sideload_base64( (string) $result['images'][0], $base_name, $alt_text );
		if ( isset( $attach['error'] ) ) {
			return $attach;
		}

		if ( ! empty( $arguments['post_id'] ) ) {
			set_post_thumbnail( (int) $arguments['post_id'], $attach['attachment_id'] );
			$attach['set_as_featured'] = true;
		}

		return array(
			'image'             => $attach,
			'model'             => $result['model'] ?? $body['model'],
			'credits_used'      => $result['credits_used'] ?? null,
			'credits_remaining' => $result['credits_remaining'] ?? null,
		);
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Read imagegen service configuration.
	 *
	 * @return array Config or array with 'error' key.
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
	 * Read a media-library attachment and return its contents as a base64 string.
	 *
	 * @param int $attachment_id WP attachment ID.
	 * @return array Array with 'data' key (base64 string) or 'error' key.
	 */
	private function attachment_to_base64( int $attachment_id ): array {
		if ( 'attachment' !== get_post_type( $attachment_id ) ) {
			return array( 'error' => 'Attachment ID ' . $attachment_id . ' does not exist in the media library.' );
		}

		$mime = (string) get_post_mime_type( $attachment_id );
		if ( ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/webp' ), true ) ) {
			return array( 'error' => 'Attachment ' . $attachment_id . ' must be a JPEG, PNG, or WebP image.' );
		}

		$file_path = get_attached_file( $attachment_id );
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return array( 'error' => 'File for attachment ' . $attachment_id . ' could not be found on disk.' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local WP upload file for API transmission.
		$binary = file_get_contents( $file_path );
		if ( false === $binary ) {
			return array( 'error' => 'Could not read file for attachment ' . $attachment_id . '.' );
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding binary file for API transmission.
		return array( 'data' => base64_encode( $binary ) );
	}

	/**
	 * POST to the imagegen service.
	 *
	 * @param string $endpoint URL path.
	 * @param array  $config   Config from get_imagegen_config().
	 * @param array  $body     Request payload.
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
			$msg = isset( $data['detail'] ) ? (string) $data['detail'] : 'Image service error (HTTP ' . $code . ')';
			return array( 'error' => $msg );
		}

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Decode a base64 image and sideload it into the WP media library.
	 *
	 * @param string $b64      Base64-encoded image (with or without data URI prefix).
	 * @param string $base_name Filename base (no extension).
	 * @param string $alt_text  Alt text for the attachment.
	 * @return array Attachment info or array with 'error' key.
	 */
	private function sideload_base64( string $b64, string $base_name, string $alt_text ): array {
		$b64 = (string) preg_replace( '#^data:image/\w+;base64,#', '', $b64 );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding trusted API response from our own service.
		$binary = base64_decode( $b64, true );
		if ( false === $binary ) {
			return array( 'error' => 'Failed to decode image data from service response.' );
		}

		$filename = $base_name . '.png';

		$tmp = wp_tempnam( 'imagen' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing to local temp file created by wp_tempnam.
		file_put_contents( $tmp, $binary );

		$attach_id = media_handle_sideload(
			array(
				'name'     => $filename,
				'tmp_name' => $tmp,
			),
			0,
			sanitize_text_field( $base_name )
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
return new Edit_Image();
