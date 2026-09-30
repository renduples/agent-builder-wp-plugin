<?php
/**
 * Tool: convert_image
 *
 * Convert an image between formats (JPEG, PNG, WebP, GIF) using WP_Image_Editor.
 *
 * @package    Agent_Builder
 * @subpackage Tools
 * @since      2.7.0
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Convert a media library image to a different format.
 */
class Convert_Image extends \Agentic\Tool_Base {

	private const SUPPORTED = array(
		'jpeg' => 'image/jpeg',
		'jpg'  => 'image/jpeg',
		'png'  => 'image/png',
		'webp' => 'image/webp',
		'gif'  => 'image/gif',
	);

	public function get_name(): string {
		return 'convert_image';
	}

	public function get_description(): string {
		return 'Convert a media library image to a different format (JPEG, PNG, WebP, GIF). Saves the converted image as a new media library item. WebP conversion is ideal for reducing web page weight. Does not use AI credits.';
	}

	public function get_category(): string {
		return 'media';
	}

	public function get_parameters(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'attachment_id' => array(
					'type'        => 'integer',
					'description' => 'ID of the source media library attachment.',
				),
				'format'        => array(
					'type'        => 'string',
					'description' => 'Target format: "jpeg", "png", "webp", or "gif".',
				),
				'quality'       => array(
					'type'        => 'integer',
					'description' => 'Output quality 1–100 (for JPEG/WebP). Defaults to 85.',
				),
			),
			'required'   => array( 'attachment_id', 'format' ),
		);
	}

	public function execute( array $arguments ): array {
		$att_id  = (int) ( $arguments['attachment_id'] ?? 0 );
		$format  = strtolower( trim( $arguments['format'] ?? '' ) );
		$quality = min( max( (int) ( $arguments['quality'] ?? 85 ), 1 ), 100 );

		if ( $att_id <= 0 ) {
			return array( 'error' => 'attachment_id is required.' );
		}

		if ( ! array_key_exists( $format, self::SUPPORTED ) ) {
			return array( 'error' => 'Unsupported format. Use: jpeg, png, webp, or gif.' );
		}

		$target_ext  = 'jpeg' === $format ? 'jpg' : $format;
		$target_mime = self::SUPPORTED[ $format ];

		$file = get_attached_file( $att_id );
		if ( ! $file || ! file_exists( $file ) ) {
			return array( 'error' => "Attachment {$att_id} file not found." );
		}

		$editor = wp_get_image_editor( $file );
		if ( is_wp_error( $editor ) ) {
			return array( 'error' => 'Could not open image: ' . $editor->get_error_message() );
		}

		$editor->set_quality( $quality );

		$path_parts = pathinfo( $file );
		$out_path   = $path_parts['dirname'] . '/' . $path_parts['filename'] . '.' . $target_ext;

		// Avoid overwriting the original.
		if ( realpath( $out_path ) === realpath( $file ) ) {
			$out_path = $path_parts['dirname'] . '/' . $path_parts['filename'] . '-converted.' . $target_ext;
		}

		$saved = $editor->save( $out_path, $target_mime );
		if ( is_wp_error( $saved ) ) {
			return array( 'error' => 'Conversion failed: ' . $saved->get_error_message() );
		}

		$new_id = wp_insert_attachment(
			array(
				'post_title'     => get_the_title( $att_id ) . " ({$format})",
				'post_mime_type' => $target_mime,
				'post_status'    => 'inherit',
			),
			$saved['path']
		);

		if ( is_wp_error( $new_id ) ) {
			return array( 'error' => 'Could not register new attachment: ' . $new_id->get_error_message() );
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata( $new_id, wp_generate_attachment_metadata( $new_id, $saved['path'] ) );

		$original_size = filesize( $file );
		$new_size      = filesize( $saved['path'] );

		return array(
			'attachment_id'   => $new_id,
			'url'             => wp_get_attachment_url( $new_id ),
			'format'          => $target_mime,
			'original_size'   => size_format( $original_size ),
			'converted_size'  => size_format( $new_size ),
			'size_change_pct' => $original_size > 0 ? round( ( $new_size / $original_size - 1 ) * 100, 1 ) : 0,
		);
	}

	public function get_annotations(): array {
		return array(
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		);
	}
}

return new Convert_Image();
