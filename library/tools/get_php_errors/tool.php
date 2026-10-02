<?php
/**
 * Tool: get_php_errors
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

class Get_Php_Errors extends Tool_Base {
	public function get_name(): string {
		return 'get_php_errors';
	}

	public function get_description(): string {
		return 'Read the WordPress debug.log file and return recent PHP errors, warnings, and notices.';
	}

	public function get_category(): string {
		return 'site-health';
	}

	public function get_parameters(): array {
		return array(
			'limit' => array(
				'type'        => 'integer',
				'description' => 'Max error lines to return (1-100). Defaults to 30.',
				'required'    => false,
			),
			'level' => array(
				'type'        => 'string',
				'description' => 'Filter by error level.',
				'required'    => false,
				'enum'        => array( 'all', 'fatal', 'warning', 'notice', 'deprecated' ),
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
		$limit        = min( 100, max( 1, (int) ( $args['limit'] ?? 30 ) ) );
		$level_filter = $args['level'] ?? 'all';

		$log_file = WP_CONTENT_DIR . '/debug.log';
		if ( ! file_exists( $log_file ) ) {
			return array(
				'status'       => 'no_log_file',
				'message'      => 'debug.log does not exist. WP_DEBUG_LOG may be disabled.',
				'wp_debug'     => defined( 'WP_DEBUG' ) && WP_DEBUG,
				'wp_debug_log' => defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG,
			);
		}

		$size     = filesize( $log_file );
		$max_read = 512 * 1024; // 512 KB
		$handle   = fopen( $log_file, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- seek() required for large log tail reads.
		if ( ! $handle ) {
			return array( 'error' => 'Cannot read debug.log.' );
		}

		if ( $size > $max_read ) {
			fseek( $handle, -$max_read, SEEK_END );
			fgets( $handle ); // skip partial line
		}

		$lines = array();
		// phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- Idiomatic line-by-line read.
		while ( ( $line = fgets( $handle ) ) !== false ) {
			$line = trim( $line );
			if ( empty( $line ) ) {
				continue;
			}

			if ( 'all' !== $level_filter ) {
				$match = match ( $level_filter ) {
					// Match PHP's own log prefix ("PHP Fatal error:", "Warning:") rather than
					// the bare word, so a logged message that merely mentions "fatal" or
					// "warning" is not miscounted as one.
					'fatal'      => 1 === preg_match( '/\b(?:PHP )?(?:Fatal|Parse|Recoverable fatal) error:/i', $line ),
					'warning'    => 1 === preg_match( '/\b(?:PHP )?Warning:/i', $line ),
					'notice'     => 1 === preg_match( '/\b(?:PHP )?Notice:/i', $line ),
					'deprecated' => 1 === preg_match( '/\b(?:PHP )?Deprecated:/i', $line ),
					default      => true,
				};
				if ( ! $match ) {
					continue;
				}
			}

			$lines[] = $line;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing file handle opened with fopen() for line-by-line log reading.
		fclose( $handle );

		// Take last $limit entries.
		$entries = array_slice( $lines, -$limit );

		return array(
			'log_file'       => $log_file,
			'log_size_kb'    => round( $size / 1024, 1 ),
			'filter'         => $level_filter,
			'total_matching' => count( $lines ),
			'returned'       => count( $entries ),
			'entries'        => $entries,
		);
	}
}

return new Get_Php_Errors();
