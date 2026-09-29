<?php
/**
 * Tool: check_php_compatibility
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

class Check_Php_Compatibility extends \Agentic\Tool_Base {

	public function get_name(): string {
		return 'check_php_compatibility';
	}

	public function get_description(): string {
		return 'Scan active plugin files for PHP compatibility issues: removed functions, deprecated constructs, and patterns that cause fatal errors or silent admin lockouts on the current PHP version.';
	}

	public function get_category(): string {
		return 'site-health';
	}

	public function get_parameters(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'plugin' => array(
					'type'        => 'string',
					'description' => 'Scan only this plugin folder name (e.g. "agent-builder"). Omit to scan all active plugins.',
				),
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
		$php_major = PHP_MAJOR_VERSION;
		$php_minor = PHP_MINOR_VERSION;

		// Patterns keyed to the PHP version they become fatal/removed.
		$checks = array();

		if ( $php_major >= 8 ) {
			$checks[] = array(
				'pattern'  => '/\bcreate_function\s*\(/',
				'issue'    => 'create_function() removed in PHP 8.0',
				'severity' => 'fatal',
			);
			$checks[] = array(
				'pattern'  => '/\beach\s*\(/',
				'issue'    => 'each() removed in PHP 8.0',
				'severity' => 'fatal',
			);
			$checks[] = array(
				'pattern'  => '/\bget_magic_quotes_gpc\s*\(/',
				'issue'    => 'get_magic_quotes_gpc() removed in PHP 8.0',
				'severity' => 'fatal',
			);
			$checks[] = array(
				'pattern'  => '/\bget_magic_quotes_runtime\s*\(/',
				'issue'    => 'get_magic_quotes_runtime() removed in PHP 8.0',
				'severity' => 'fatal',
			);
		}

		if ( $php_major > 8 || ( 8 === $php_major && $php_minor >= 1 ) ) {
			$checks[] = array(
				'pattern'  => '/FILTER_SANITIZE_STRING\b/',
				'issue'    => 'FILTER_SANITIZE_STRING removed in PHP 8.1',
				'severity' => 'fatal',
			);
			$checks[] = array(
				'pattern'  => '/\bstrftime\s*\(/',
				'issue'    => 'strftime() deprecated in PHP 8.1',
				'severity' => 'deprecated',
			);
		}

		if ( $php_major > 8 || ( 8 === $php_major && $php_minor >= 2 ) ) {
			$checks[] = array(
				'pattern'  => '/\$\{[a-zA-Z_]/',
				'issue'    => 'Dynamic string interpolation ${var} deprecated in PHP 8.2',
				'severity' => 'deprecated',
			);
			$checks[] = array(
				'pattern'  => '/\butf8_encode\s*\(/',
				'issue'    => 'utf8_encode() deprecated in PHP 8.2',
				'severity' => 'deprecated',
			);
			$checks[] = array(
				'pattern'  => '/\butf8_decode\s*\(/',
				'issue'    => 'utf8_decode() deprecated in PHP 8.2',
				'severity' => 'deprecated',
			);
		}

		if ( $php_major > 8 || ( 8 === $php_major && $php_minor >= 4 ) ) {
			// Detect implicitly nullable parameter type declarations: function foo(Foo $bar = null)
			$checks[] = array(
				'pattern'  => '/function\s+\w+\s*\([^)]*\b\w+\s+\$\w+\s*=\s*null/',
				'issue'    => 'Implicitly nullable parameter types deprecated in PHP 8.4 — use ?Type syntax',
				'severity' => 'deprecated',
			);
		}

		$active_plugins = (array) get_option( 'active_plugins', array() );
		$plugin_filter  = isset( $args['plugin'] ) ? sanitize_text_field( $args['plugin'] ) : null;
		$plugins_dir    = WP_PLUGIN_DIR;

		$issues    = array();
		$scanned   = 0;
		$max_files = 300;

		foreach ( $active_plugins as $plugin_file ) {
			$plugin_slug = explode( '/', $plugin_file )[0];

			if ( $plugin_filter && $plugin_slug !== $plugin_filter ) {
				continue;
			}

			$plugin_path = $plugins_dir . '/' . $plugin_slug;
			if ( ! is_dir( $plugin_path ) ) {
				continue;
			}

			$iter = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $plugin_path, \FilesystemIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::LEAVES_ONLY
			);

			foreach ( $iter as $f ) {
				if ( 'php' !== $f->getExtension() ) {
					continue;
				}
				if ( $scanned >= $max_files ) {
					break 2;
				}
				++$scanned;

				$content = @file_get_contents( $f->getPathname() );
				if ( false === $content ) {
					continue;
				}

				// Strip block comments and inline comments to reduce false positives.
				$stripped = preg_replace( '/\/\*.*?\*\//s', '', $content ) ?? $content;
				$stripped = preg_replace( '/\/\/[^\n]*/', '', $stripped ) ?? $stripped;
				$stripped = preg_replace( '/#[^\n]*/', '', $stripped ) ?? $stripped;

				foreach ( $checks as $check ) {
					if ( preg_match( $check['pattern'], $stripped, $match, PREG_OFFSET_CAPTURE ) ) {
						$line_num = substr_count( substr( $content, 0, (int) $match[0][1] ), "\n" ) + 1;
						$issues[] = array(
							'plugin'   => $plugin_slug,
							'file'     => str_replace( $plugins_dir . '/', '', $f->getPathname() ),
							'line'     => $line_num,
							'issue'    => $check['issue'],
							'severity' => $check['severity'],
							'match'    => trim( (string) $match[0][0] ),
						);
					}
				}
			}
		}

		$fatal_count = count( array_filter( $issues, fn( $i ) => 'fatal' === $i['severity'] ) );
		$depr_count  = count( array_filter( $issues, fn( $i ) => 'deprecated' === $i['severity'] ) );

		return array(
			'php_version'   => PHP_VERSION,
			'files_scanned' => $scanned,
			'fatal_issues'  => $fatal_count,
			'deprecated'    => $depr_count,
			'issues'        => $issues,
			'status'        => $fatal_count > 0
				? "{$fatal_count} fatal compatibility issue(s) found. These will cause PHP errors or admin lockouts on PHP {$php_major}.{$php_minor}."
				: ( $depr_count > 0
					? "{$depr_count} deprecation notice(s) found. These will become fatal errors in a future PHP version."
					: 'No PHP compatibility issues detected in scanned plugin files (PHP ' . PHP_VERSION . ').' ),
		);
	}
}

return new Check_Php_Compatibility();
