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
 * Removes the plugin's own data folders under wp-content.
 */
class Uninstall_Cleanup {

	/**
	 * Folders that hold only plugin data and are removed in full when the
	 * administrator chose "Delete all plugin data": automatic pre-change
	 * backups (copies of site content) and agent knowledge files.
	 *
	 * wp-content/agentic-agents is deliberately not listed: uninstall.php
	 * only removes it when it holds nothing but the plugin's guard files.
	 *
	 * @return string[] Absolute directory paths.
	 */
	public static function data_directories(): array {
		return array(
			WP_CONTENT_DIR . '/agentic-backups',
			WP_CONTENT_DIR . '/agentic-knowledge',
		);
	}

	/**
	 * Recursively delete the given data directories with WP_Filesystem.
	 *
	 * Only paths directly inside WP_CONTENT_DIR whose name starts with
	 * "agentic-" are touched, so a filtered or mistaken list can never reach
	 * anything else.
	 *
	 * @param string[]|null $dirs Directories to remove (default: data_directories()).
	 * @return string[] Directories that were removed.
	 */
	public static function remove_data_directories( ?array $dirs = null ): array {
		$dirs    = $dirs ?? self::data_directories();
		$removed = array();
		$content = trailingslashit( wp_normalize_path( WP_CONTENT_DIR ) );

		$dirs = array_filter(
			$dirs,
			static function ( $dir ) use ( $content ): bool {
				$dir = untrailingslashit( wp_normalize_path( (string) $dir ) );
				return is_dir( $dir )
					&& ! is_link( $dir )
					&& dirname( $dir ) . '/' === $content
					&& str_starts_with( basename( $dir ), 'agentic-' );
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
