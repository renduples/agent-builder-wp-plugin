<?php
/**
 * Tool: check_file_permissions
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

class Check_File_Permissions extends \Agentic\Tool_Base {

	public function get_name(): string {
		return 'check_file_permissions';
	}

	public function get_description(): string {
		return 'Audit file and directory permissions on key WordPress paths. Detects world-writable files (security risk) and 000-locked files — the latter typically means a hosting provider locked a file after flagging it as suspicious.';
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
		$abspath = trailingslashit( ABSPATH );

		$targets = array(
			array(
				'path'     => $abspath . 'wp-config.php',
				'label'    => 'wp-config.php',
				'type'     => 'file',
				'ideal'    => '640',
				'max_safe' => '644',
			),
			array(
				'path'     => $abspath . '.htaccess',
				'label'    => '.htaccess',
				'type'     => 'file',
				'ideal'    => '644',
				'max_safe' => '644',
			),
			array(
				'path'     => rtrim( $abspath, '/' ),
				'label'    => 'WordPress root',
				'type'     => 'dir',
				'ideal'    => '755',
				'max_safe' => '755',
			),
			array(
				'path'     => WP_CONTENT_DIR,
				'label'    => 'wp-content/',
				'type'     => 'dir',
				'ideal'    => '755',
				'max_safe' => '755',
			),
			array(
				'path'     => (string) wp_upload_dir( null, false )['basedir'],
				'label'    => 'uploads/',
				'type'     => 'dir',
				'ideal'    => '755',
				'max_safe' => '755',
			),
			array(
				'path'     => WP_PLUGIN_DIR,
				'label'    => 'plugins/',
				'type'     => 'dir',
				'ideal'    => '755',
				'max_safe' => '755',
			),
			array(
				'path'     => get_theme_root(),
				'label'    => 'themes/',
				'type'     => 'dir',
				'ideal'    => '755',
				'max_safe' => '755',
			),
			array(
				'path'     => $abspath . 'wp-includes',
				'label'    => 'wp-includes/',
				'type'     => 'dir',
				'ideal'    => '755',
				'max_safe' => '755',
			),
			array(
				'path'     => $abspath . 'wp-admin',
				'label'    => 'wp-admin/',
				'type'     => 'dir',
				'ideal'    => '755',
				'max_safe' => '755',
			),
		);

		$items        = array();
		$warnings     = 0;
		$locked_count = 0;

		foreach ( $targets as $t ) {
			if ( ! file_exists( $t['path'] ) ) {
				continue;
			}

			$raw   = fileperms( $t['path'] );
			$octal = substr( sprintf( '%o', $raw ), -3 );
			$world = (int) substr( $octal, -1 );

			if ( '000' === $octal ) {
				$status = 'locked';
				$note   = 'Permissions are 000 — WordPress cannot read this file. This is typically set by a hosting security scanner after flagging the file as suspicious.';
				++$locked_count;
			} elseif ( $world >= 7 ) {
				$status = 'critical';
				$note   = "World-writable and world-executable ({$octal}). Any server user can modify this. Fix immediately: chmod {$t['ideal']} {$t['label']}";
				++$warnings;
			} elseif ( $world >= 6 ) {
				$status = 'warning';
				$note   = "World-writable ({$octal}). Any server user can modify this file. Should be {$t['ideal']}.";
				++$warnings;
			} elseif ( $octal > $t['max_safe'] ) {
				$status = 'notice';
				$note   = "More permissive than recommended ({$octal}). Ideal: {$t['ideal']}.";
			} else {
				$status = 'ok';
				$note   = 'Permissions look correct.';
			}

			$items[] = array(
				'path'        => $t['label'],
				'type'        => $t['type'],
				'permissions' => $octal,
				'ideal'       => $t['ideal'],
				'status'      => $status,
				'note'        => $note,
			);
		}

		if ( $locked_count > 0 ) {
			$summary = 'host_intervention';
		} elseif ( $warnings > 0 ) {
			$summary = 'insecure';
		} else {
			$summary = 'ok';
		}

		return array(
			'files_checked'  => count( $items ),
			'warnings'       => $warnings,
			'locked_by_host' => $locked_count,
			'summary_status' => $summary,
			'items'          => $items,
			'status'         => match ( $summary ) {
				'host_intervention' => "{$locked_count} file(s) have 000 permissions — your hosting provider has locked them, likely after flagging a plugin as suspicious. WordPress cannot load the affected files.",
				'insecure'          => "{$warnings} file(s) have insecure world-writable permissions. Any user on the server can modify them.",
				default             => 'All key file and directory permissions look correct.',
			},
			'remediation'    => match ( $summary ) {
				'host_intervention' => 'Contact your hosting provider. Ask them to review the flagged files and restore permissions to 644 (files) or 755 (directories). Provide a clean malware scan result to support your case.',
				'insecure'          => 'Fix via your hosting file manager or SSH: chmod 644 <file> or chmod 755 <directory>',
				default             => null,
			},
		);
	}
}

return new Check_File_Permissions();
