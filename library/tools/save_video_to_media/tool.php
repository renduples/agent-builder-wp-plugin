<?php
/**
 * Tool: save_video_to_media
 *
 * Download a video from a URL (typically the Agentic CDN) and save it to the
 * WordPress media library as an attachment. Useful for sites that prefer
 * self-hosted content or need offline access to generated clips.
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
 * Download a generated video from a CDN URL and save it to the WordPress media library.
 * Returns the attachment ID, local URL, and file size. Useful for self-hosted storage
 * or when CDN delivery is not preferred.
 */
class Save_Video_To_Media extends \Agentic\Tool_Base {

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'save_video_to_media';
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return 'Download a generated video from a CDN URL and save it to the WordPress media library. Returns the attachment ID and local URL. Use this to store videos locally instead of (or in addition to) serving them from the Agentic CDN. Requires upload_files capability.';
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
				'video_url' => array(
					'type'        => 'string',
					'description' => 'URL of the video to download and save (e.g. https://videos.agentic-plugin.com/videos/job-id/output.mp4).',
				),
				'title'     => array(
					'type'        => 'string',
					'description' => 'Title for the media library attachment. Defaults to a timestamp-based name.',
				),
				'post_id'   => array(
					'type'        => 'integer',
					'description' => 'If provided, attach the video to this post ID in the media library.',
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
		if ( ! current_user_can( 'upload_files' ) ) {
			return array( 'error' => 'You do not have permission to upload files.' );
		}

		$video_url = esc_url_raw( $arguments['video_url'] ?? '' );
		if ( empty( $video_url ) ) {
			return array( 'error' => 'video_url is required.' );
		}

		// Only allow https:// URLs to prevent SSRF.
		if ( ! str_starts_with( $video_url, 'https://' ) ) {
			return array( 'error' => 'Only HTTPS URLs are supported.' );
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		// Download to a temp file using WP's built-in downloader.
		$tmp = download_url( $video_url, 300 );
		if ( is_wp_error( $tmp ) ) {
			return array( 'error' => 'Failed to download video: ' . $tmp->get_error_message() );
		}

		$title    = sanitize_text_field( $arguments['title'] ?? '' );
		$post_id  = ! empty( $arguments['post_id'] ) ? (int) $arguments['post_id'] : 0;
		$filename = $this->derive_filename( $video_url, $title );

		$file_array = array(
			'name'     => $filename,
			'tmp_name' => $tmp,
		);

		$attach_id = media_handle_sideload( $file_array, $post_id, $title ? $title : $filename );

		wp_delete_file( $tmp );

		if ( is_wp_error( $attach_id ) ) {
			return array( 'error' => 'Failed to save video to media library: ' . $attach_id->get_error_message() );
		}

		$local_url = wp_get_attachment_url( $attach_id );
		$metadata  = wp_get_attachment_metadata( $attach_id );

		return array(
			'attachment_id' => $attach_id,
			'local_url'     => $local_url,
			'filename'      => $filename,
			'file_size'     => isset( $metadata['filesize'] ) ? $metadata['filesize'] : null,
			'embed_code'    => $local_url ? '<video src="' . esc_url( $local_url ) . '" controls playsinline></video>' : null,
		);
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Derive a safe filename for the downloaded video.
	 *
	 * @param string $url   Source URL.
	 * @param string $title Optional title hint.
	 * @return string Sanitised filename with .mp4 extension.
	 */
	private function derive_filename( string $url, string $title ): string {
		if ( ! empty( $title ) ) {
			return sanitize_file_name( $title ) . '.mp4';
		}

		// Extract filename from URL path.
		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( $path ) {
			$basename = basename( (string) $path );
			if ( str_ends_with( $basename, '.mp4' ) ) {
				return sanitize_file_name( $basename );
			}
		}

		return 'agentic-video-' . gmdate( 'Ymd-His' ) . '.mp4';
	}
}

return new Save_Video_To_Media();
