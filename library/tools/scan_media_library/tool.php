<?php
/**
 * Tool: scan_media_library
 *
 * @package    Agent_Builder
 * @subpackage Tools
 * @since      2.0.0
 *
 * php version 8.1
 */

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Agentic\Tool_Base;

class Scan_Media_Library extends Tool_Base {
	public function get_name(): string {
		return 'scan_media_library';
	}

	public function get_description(): string {
		return 'Analyse the media library for oversized images, orphaned attachments, storage usage, and file-type breakdown.';
	}

	public function get_category(): string {
		return 'site-health';
	}

	public function get_parameters(): array {
		return array(
			'size_threshold_kb' => array(
				'type'        => 'integer',
				'description' => 'Flag files larger than this (KB). Defaults to 500.',
				'required'    => false,
			),
			'limit'             => array(
				'type'        => 'integer',
				'description' => 'Max oversized files to return (1-50). Defaults to 10.',
				'required'    => false,
			),
		);
	}

	public function get_annotations(): array {
		return array(
			'read_only'   => true,
			'destructive' => false,
		);
	}

	public function execute( array $args ): array {
		global $wpdb;
		$threshold = max( 1, (int) ( $args['size_threshold_kb'] ?? 500 ) );
		$limit     = min( 50, max( 1, (int) ( $args['limit'] ?? 10 ) ) );
		$upload    = wp_upload_dir();
		$basedir   = $upload['basedir'];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table.
		$total_attachments = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment'" );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table.
		$orphan_count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} AS a LEFT JOIN {$wpdb->posts} AS p ON a.post_parent = p.ID AND p.post_status != 'trash' WHERE a.post_type = 'attachment' AND a.post_parent > 0 AND p.ID IS NULL"
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table.
		$unattached_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_parent = 0" );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table.
		$mime_rows      = $wpdb->get_results( "SELECT post_mime_type, COUNT(*) AS cnt FROM {$wpdb->posts} WHERE post_type = 'attachment' GROUP BY post_mime_type ORDER BY cnt DESC" );
		$type_breakdown = array();
		foreach ( $mime_rows as $row ) {
			$type_breakdown[ $row->post_mime_type ] = (int) $row->cnt;
		}

		$threshold_bytes = $threshold * 1024;
		$oversized       = array();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table.
		$attachments = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_title, p.post_mime_type, pm.meta_value AS file_path FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_wp_attached_file' WHERE p.post_type = 'attachment' ORDER BY p.ID DESC LIMIT %d",
				500
			)
		);

		foreach ( $attachments as $att ) {
			$full_path = $basedir . '/' . $att->file_path;
			if ( ! file_exists( $full_path ) ) {
				continue;
			}
			$size = filesize( $full_path );
			if ( $size >= $threshold_bytes ) {
				$oversized[] = array(
					'id'        => (int) $att->ID,
					'title'     => $att->post_title,
					'file'      => $att->file_path,
					'mime_type' => $att->post_mime_type,
					'size_kb'   => round( $size / 1024, 1 ),
				);
			}
			if ( count( $oversized ) >= $limit ) {
				break;
			}
		}

		usort( $oversized, fn( $a, $b ) => $b['size_kb'] <=> $a['size_kb'] );

		$total_size_estimate = 0;
		if ( is_dir( $basedir ) ) {
			$total_size_estimate = self::directory_size_kb( $basedir );
		}

		return array(
			'total_attachments'    => $total_attachments,
			'orphaned_attachments' => $orphan_count,
			'unattached'           => $unattached_count,
			'type_breakdown'       => $type_breakdown,
			'uploads_size_mb'      => $total_size_estimate > 0 ? round( $total_size_estimate / 1024, 1 ) : 'unknown',
			'oversized_threshold'  => $threshold . ' KB',
			'oversized_count'      => count( $oversized ),
			'oversized_files'      => $oversized,
		);
	}

	/**
	 * Calculate the total size of a directory in kilobytes using native PHP.
	 *
	 * Replaces a shell `du` call so the tool works on hosts where exec() is
	 * disabled and complies with the WordPress.org guidelines (no shell access).
	 *
	 * @param string $dir Absolute directory path.
	 * @return int Size in KB (0 on failure).
	 */
	private static function directory_size_kb( string $dir ): int {
		if ( ! is_dir( $dir ) ) {
			return 0;
		}

		$bytes = 0;
		try {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::LEAVES_ONLY
			);
			foreach ( $iterator as $file ) {
				if ( $file->isFile() ) {
					$bytes += $file->getSize();
				}
			}
		} catch ( \Throwable $e ) {
			return 0;
		}

		return (int) round( $bytes / 1024 );
	}
}

return new Scan_Media_Library();
