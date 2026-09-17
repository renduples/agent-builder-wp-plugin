<?php
/**
 * Tool: upscale_image
 *
 * Upscale an existing WordPress media library image using the Agentic Image
 * Generation service (Gemini 3.1 Flash Image — semantic re-render at higher
 * resolution, not a pixel-preserving upscale).
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
 * Upscale an image from the media library to 2× or 4× its original resolution.
 * The upscaled result is saved as a new media library item. Costs 23 credits.
 */
class Upscale_Image extends \Agentic\Tool_Base {

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'upscale_image';
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return 'Upscale an existing media library image to 2× (x2) or 4× (x4) its original resolution using AI. This is a semantic re-render at higher resolution, not a pixel-preserving upscale, so fine details may shift slightly. The upscaled result is saved as a new media library item. Costs 23 credits. Requires a connected Agentic AI account.';
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
				'attachment_id'  => array(
					'type'        => 'integer',
					'description' => 'WordPress attachment ID of the image to upscale.',
				),
				'upscale_factor' => array(
					'type'        => 'string',
					'description' => '"x2" (double resolution, default) or "x4" (quadruple resolution).',
					'enum'        => array( 'x2', 'x4' ),
				),
				'filename'       => array(
					'type'        => 'string',
					'description' => 'Base filename for the upscaled image (without extension). Defaults to original filename with the upscale factor appended.',
				),
				'alt_text'       => array(
					'type'        => 'string',
					'description' => 'Alt text for the new attachment. Defaults to the original image alt text.',
				),
			),
			'required'   => array( 'attachment_id' ),
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

		$source_id = (int) $arguments['attachment_id'];

		if ( 'attachment' !== get_post_type( $source_id ) ) {
			return array( 'error' => 'Attachment ID ' . $source_id . ' does not exist in the media library.' );
		}

		$mime = (string) get_post_mime_type( $source_id );
		if ( ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/webp' ), true ) ) {
			return array( 'error' => 'Attachment must be a JPEG, PNG, or WebP image.' );
		}

		$file_path = get_attached_file( $source_id );
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return array( 'error' => 'File for attachment ' . $source_id . ' could not be found on disk.' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local WP upload file for API transmission.
		$binary = file_get_contents( $file_path );
		if ( false === $binary ) {
			return array( 'error' => 'Could not read image file.' );
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding binary for API.
		$b64 = base64_encode( $binary );

		$factor = in_array( $arguments['upscale_factor'] ?? 'x2', array( 'x2', 'x4' ), true )
			? ( $arguments['upscale_factor'] ?? 'x2' )
			: 'x2';

		$response = wp_remote_post(
			\Agentic\Service_Registry::url( 'agentic-imagegen', '/upscale' ),
			array(
				'timeout' => 120,
				'headers' => array(
					'X-API-Key'        => $api_key,
					'Content-Type'     => 'application/json',
					'X-Plugin-Version' => AGENT_BUILDER_VERSION,
				),
				'body'    => wp_json_encode(
					array(
						'user_id'        => $user_id,
						'image'          => $b64,
						'upscale_factor' => $factor,
						'site_url'       => get_site_url(),
					)
				),
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

		if ( empty( $data['images'][0] ) ) {
			return array( 'error' => 'No upscaled image returned by the service.' );
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$original_name = sanitize_file_name( pathinfo( basename( $file_path ), PATHINFO_FILENAME ) );
		$base_name     = sanitize_file_name( $arguments['filename'] ?? ( $original_name . '-' . $factor ) );
		$alt_text      = sanitize_text_field(
			$arguments['alt_text'] ?? (string) get_post_meta( $source_id, '_wp_attachment_image_alt', true )
		);

		$result_b64 = (string) preg_replace( '#^data:image/\w+;base64,#', '', (string) $data['images'][0] );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding trusted API response.
		$result_binary = base64_decode( $result_b64, true );

		if ( false === $result_binary ) {
			return array( 'error' => 'Failed to decode upscaled image from service response.' );
		}

		$filename = $base_name . '.png';

		$tmp = wp_tempnam( 'imagen' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing to local temp file created by wp_tempnam.
		file_put_contents( $tmp, $result_binary );

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
			'attachment_id'     => $attach_id,
			'url'               => wp_get_attachment_url( $attach_id ),
			'filename'          => $filename,
			'upscale_factor'    => $factor,
			'source_id'         => $source_id,
			'credits_used'      => $data['credits_used'] ?? null,
			'credits_remaining' => $data['credits_remaining'] ?? null,
		);
	}
}
return new Upscale_Image();
