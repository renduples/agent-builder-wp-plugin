<?php
/**
 * Tool: get_file_structure_summary
 *
 * Get a high-level summary of the WordPress file structure.
 *
 * @package    Agent_Builder
 * @subpackage Tools
 * @since      2.0.0
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get a high-level summary of the WordPress file structure.
 */
class Get_File_Structure_Summary extends \Agentic\Tool_Base {

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'get_file_structure_summary';
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return 'Get a high-level summary of the WordPress file structure: file counts in key directories (uploads, themes, plugins), recent modifications to core files, and flags for common issues like missing .htaccess or oversized uploads.';
	}

	/**
	 * Get the tool category.
	 *
	 * @return string
	 */
	public function get_category(): string {
		return 'WordPress';
	}

	/**
	 * Get the parameter schema.
	 *
	 * @return array
	 */
	public function get_parameters(): array {
		return array(
			'type'       => 'object',
			'properties' => new \stdClass(),
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Validated arguments from the LLM.
	 * @return array Result data.
	 */
	public function execute( array $arguments ): array {
		// Reveals server file-path layout, recent core-file modifications,
		// and missing hardening (.htaccess, etc.) — reconnaissance
		// information for an attacker, not something the tool-generic
		// edit_posts floor a caller might otherwise only need (e.g. a
		// Contributor over WebMCP) should ever see.
		if ( ! current_user_can( 'manage_options' ) ) {
			return array( 'error' => 'You do not have permission to view the file structure summary.' );
		}

		$dirs = array(
			'themes'  => WP_CONTENT_DIR . '/themes',
			'plugins' => WP_CONTENT_DIR . '/plugins',
			'uploads' => WP_CONTENT_DIR . '/uploads',
			'agents'  => AGENT_BUILDER_AGENTS_DIR,
		);

		$summary = array();

		foreach ( $dirs as $label => $path ) {
			if ( ! is_dir( $path ) ) {
				$summary[ $label ] = array( 'exists' => false );
				continue;
			}

			$subdirs = array_filter(
				scandir( $path ),
				function ( $f ) use ( $path ) {
					return '.' !== $f && '..' !== $f && is_dir( $path . '/' . $f );
				}
			);

			$summary[ $label ] = array(
				'exists'     => true,
				'subfolders' => count( $subdirs ),
				'items'      => array_values( $subdirs ),
			);
		}

		if ( is_dir( $dirs['uploads'] ) ) {
			$size_kb = self::directory_size_kb( $dirs['uploads'] );
			if ( $size_kb > 0 ) {
				$summary['uploads']['size_mb'] = round( $size_kb / 1024, 1 );
			}
		}

		$htaccess            = ABSPATH . '.htaccess';
		$summary['htaccess'] = array( 'exists' => file_exists( $htaccess ) );

		if ( file_exists( $htaccess ) ) {
			$summary['htaccess']['size_bytes'] = filesize( $htaccess );
			$summary['htaccess']['modified']   = gmdate( 'Y-m-d H:i:s', filemtime( $htaccess ) );
		}

		$core_files = array( 'wp-config.php', 'wp-settings.php', 'wp-login.php', 'index.php' );
		$core_mods  = array();

		foreach ( $core_files as $cf ) {
			$full = ABSPATH . $cf;

			if ( file_exists( $full ) ) {
				$core_mods[ $cf ] = gmdate( 'Y-m-d H:i:s', filemtime( $full ) );
			}
		}

		$summary['core_file_modified'] = $core_mods;
		$summary['wp_content_items']   = count(
			array_filter(
				scandir( WP_CONTENT_DIR ),
				function ( $f ) {
					return '.' !== $f && '..' !== $f;
				}
			)
		);

		return $summary;
	}

	/**
	 * Get tool annotations.
	 *
	 * @return array
	 */
	public function get_annotations(): array {
		return array(
			'readonly' => true,
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

return new Get_File_Structure_Summary();
