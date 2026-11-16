<?php
/**
 * Filesystem cleanup for a "Delete all plugin data" uninstall.
 *
 * Kept in its own small class (no other plugin code needed) so uninstall.php
 * can require it directly — the plugin itself is not loaded during uninstall
 * — and so the unit tests can exercise it without running uninstall.php.
 *
 * @package    Agent_Builder
 * @subpackage Includes
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Removes the plugin's own data folders: the uploads data root and any
 * pre-4.5 wp-content/agentic-* folder still left over.
 */
class Uninstall_Cleanup {

	/**
	 * Folders that hold only plugin data and are removed in full when the
	 * administrator chose "Delete all plugin data": the plugin's data root in
	 * uploads (user agents, knowledge files, automatic backups, generated
	 * documents) and the pre-4.5 wp-content/agentic-* folders if a storage
	 * migration left any behind.
	 *
	 * @return string[] Absolute directory paths.
	 */
	public static function data_directories(): array {
		$dirs = array( File_Manager::data_dir() );
		foreach ( array_keys( File_Manager::LEGACY_DIRS ) as $kind ) {
			$dirs[] = File_Manager::legacy_dir( $kind );
		}
		return $dirs;
	}

	/**
	 * Recursively delete the given data directories with WP_Filesystem.
	 *
	 * Only the uploads data root itself, or a path directly inside
	 * WP_CONTENT_DIR whose name is one of the legacy agentic-* folders, is
	 * touched, so a filtered or mistaken list can never reach anything else.
	 *
	 * @param string[]|null $dirs Directories to remove (default: data_directories()).
	 * @return string[] Directories that were removed.
	 */
	public static function remove_data_directories( ?array $dirs = null ): array {
		$dirs      = $dirs ?? self::data_directories();
		$removed   = array();
		$content   = trailingslashit( wp_normalize_path( WP_CONTENT_DIR ) );
		$data_root = untrailingslashit( wp_normalize_path( File_Manager::data_dir() ) );

		$dirs = array_filter(
			$dirs,
			static function ( $dir ) use ( $content, $data_root ): bool {
				$dir = untrailingslashit( wp_normalize_path( (string) $dir ) );
				if ( ! is_dir( $dir ) || is_link( $dir ) ) {
					return false;
				}
				if ( $dir === $data_root ) {
					return true;
				}
				return dirname( $dir ) . '/' === $content
					&& in_array( basename( $dir ), File_Manager::LEGACY_DIRS, true );
			}
		);

		if ( empty( $dirs ) ) {
			return $removed;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( ! WP_Filesystem() ) {
			return $removed;
		}

		global $wp_filesystem;

		foreach ( $dirs as $dir ) {
			if ( $wp_filesystem->rmdir( $dir, true ) ) {
				$removed[] = $dir;
			}
		}

		return $removed;
	}
}
