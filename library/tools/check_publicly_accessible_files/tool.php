<?php
/**
 * Tool: check_publicly_accessible_files
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

class Check_Publicly_Accessible_Files extends \Agentic\Tool_Base {

	public function get_name(): string {
		return 'check_publicly_accessible_files';
	}

	public function get_description(): string {
		return 'Check whether sensitive files (debug.log, wp-config backups, .sql dumps, .env) are publicly accessible over HTTP. Exposed files leak credentials, database passwords, and server configuration.';
	}

	public function get_category(): string {
		return 'security';
	}

	public function get_parameters(): array {
		return array(
			'type'       => 'object',
			'properties' => array(),
		);
	}

	public function get_annotations(): array {
		return array( 'readonly' => true );
	}

	public function execute( array $arguments ): array {
		$site_url = trailingslashit( get_site_url() );
		$abspath  = trailingslashit( ABSPATH );

		$candidates = array();

		// debug.log — always web-accessible unless explicitly blocked.
		$candidates['wp-content/debug.log'] = WP_CONTENT_DIR . '/debug.log';

		// wp-config backups left in webroot.
		foreach ( array( 'wp-config.php.bak', 'wp-config.php.old', 'wp-config.bak', 'wp-config.txt', 'wp-config.php~' ) as $name ) {
			$candidates[ $name ] = $abspath . $name;
		}

		// .env in webroot.
		$candidates['.env'] = $abspath . '.env';

		// SQL dumps dropped in wp-content or webroot.
		foreach ( array( WP_CONTENT_DIR, rtrim( $abspath, '/' ) ) as $scan_dir ) {
			if ( ! is_dir( $scan_dir ) ) {
				continue;
			}
			$iter = new \DirectoryIterator( $scan_dir );
			foreach ( $iter as $file ) {
				if ( $file->isDot() || $file->isDir() ) {
					continue;
				}
				if ( ( in_array( strtolower( $file->getExtension() ), array( 'sql', 'gz' ), true )
					&& str_ends_with( strtolower( $file->getFilename() ), '.sql' ) )
					|| str_ends_with( strtolower( $file->getFilename() ), '.sql.gz' ) ) {
					$rel                = ( WP_CONTENT_DIR === $scan_dir )
						? 'wp-content/' . $file->getFilename()
						: $file->getFilename();
					$candidates[ $rel ] = $file->getPathname();
				}
			}
		}

		$exposed   = array();
		$protected = array();

		foreach ( $candidates as $url_path => $disk_path ) {
			if ( ! file_exists( $disk_path ) ) {
				continue;
			}

			$url      = $site_url . ltrim( $url_path, '/' );
			$response = wp_remote_get(
				$url,
				array(
					'timeout'   => 5,
					'sslverify' => false,
				)
			);

			$http_code  = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
			$accessible = ( 200 === $http_code );

			$entry = array(
				'file'      => $url_path,
				'url'       => $url,
				'http_code' => $http_code,
				'severity'  => $accessible ? 'critical' : 'low',
				'note'      => $accessible
					? 'Publicly downloadable. Block immediately.'
					: 'Exists on disk but not HTTP-accessible (server rule or .htaccess protecting it).',
			);

			if ( $accessible ) {
				$exposed[] = $entry;
			} else {
				$protected[] = $entry;
			}
		}

		// Inspect .htaccess for known protection rules.
		$htaccess_path = $abspath . '.htaccess';
		$htaccess      = array( 'exists' => file_exists( $htaccess_path ) );
		if ( $htaccess['exists'] ) {
			$htaccess_content       = (string) file_get_contents( $htaccess_path );
			$htaccess['blocks_log'] = false !== stripos( $htaccess_content, 'debug.log' );
			$htaccess['blocks_env'] = false !== stripos( $htaccess_content, '.env' );
			$htaccess['blocks_sql'] = false !== stripos( $htaccess_content, '.sql' );
		}

		$critical = count( $exposed );

		return array(
			'critical_count'  => $critical,
			'files_checked'   => count( $exposed ) + count( $protected ),
			'exposed_files'   => $exposed,
			'protected_files' => $protected,
			'htaccess'        => $htaccess,
			'status'          => 0 === $critical
				? 'No sensitive files are publicly accessible.'
				: "{$critical} sensitive file(s) are publicly accessible and must be blocked immediately.",
			'remediation'     => $critical > 0
				? 'Add to your .htaccess (Apache): <FilesMatch "\.(log|sql|sql\.gz|bak|env)$"> Require all denied </FilesMatch>  For Nginx: location ~* \.(log|sql|bak|env)$ { deny all; }'
				: null,
		);
	}
}

return new Check_Publicly_Accessible_Files();
