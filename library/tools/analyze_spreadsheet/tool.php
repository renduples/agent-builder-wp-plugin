<?php
/**
 * Tool: analyze_spreadsheet
 *
 * Statistical summary of a spreadsheet file's structure and contents.
 *
 * @package    Agent_Builder
 * @subpackage Tools
 * @since      2.6.0
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Analyze the structure and contents of a spreadsheet file.
 */
class Analyze_Spreadsheet extends \Agentic\Tool_Base {

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'analyze_spreadsheet';
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return 'Analyze the structure and contents of a spreadsheet file. Returns row/column counts, inferred column data types, and numeric statistics (min, max, average, blank count) for each column. Use this when the user wants to understand what is in a file before editing or reporting on it.';
	}

	/**
	 * Get the tool category.
	 *
	 * @return string
	 */
	public function get_category(): string {
		return 'files';
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
				'file_path'  => array(
					'type'        => 'string',
					'description' => 'Path to the file relative to the WordPress uploads directory.',
				),
				'sheet_name' => array(
					'type'        => 'string',
					'description' => 'Sheet to analyze. Defaults to the active sheet.',
				),
			),
			'required'   => array( 'file_path' ),
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Validated arguments from the LLM.
	 * @return array Result data.
	 */
	/**
	 * This tool depends on an optional library.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return $this->library_available( '\\PhpOffice\\PhpSpreadsheet\\IOFactory' );
	}

	/**
	 * Explain why the tool cannot run.
	 *
	 * @return string
	 */
	public function get_unavailable_reason(): string {
		return __( 'Spreadsheet tools require the PhpSpreadsheet library, which is not installed on this site.', 'agent-builder' );
	}

	public function execute( array $arguments ): array {
		$path = $this->resolve_upload_path( $arguments['file_path'] ?? '' );
		if ( is_wp_error( $path ) ) {
			return array( 'error' => $path->get_error_message() );
		}

		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

		if ( in_array( $ext, array( 'csv', 'tsv' ), true ) ) {
			$rows = $this->load_csv( $path, 'tsv' === $ext ? "\t" : ',' );
		} else {
			$rows = $this->load_xlsx( $path, $arguments['sheet_name'] ?? null );
			if ( isset( $rows['error'] ) ) {
				return $rows;
			}
		}

		if ( empty( $rows ) ) {
			return array( 'error' => 'The spreadsheet appears to be empty.' );
		}

		$headers   = array_shift( $rows );
		$col_count = count( $headers );
		$columns   = array();

		for ( $c = 0; $c < $col_count; $c++ ) {
			$values    = array_column( $rows, $c );
			$non_empty = array_filter( $values, static fn( $v ) => '' !== $v && null !== $v );
			$numeric   = array_filter( $non_empty, 'is_numeric' );

			$col = array(
				'name'         => $headers[ $c ] ?? 'Column ' . ( $c + 1 ),
				'type'         => count( $numeric ) === count( $non_empty ) ? 'numeric' : 'text',
				'total_rows'   => count( $values ),
				'blank_count'  => count( $values ) - count( $non_empty ),
				'unique_count' => count( array_unique( array_values( $non_empty ) ) ),
			);

			if ( 'numeric' === $col['type'] && ! empty( $numeric ) ) {
				$nums           = array_map( 'floatval', $numeric );
				$col['min']     = min( $nums );
				$col['max']     = max( $nums );
				$col['sum']     = round( array_sum( $nums ), 4 );
				$col['average'] = round( array_sum( $nums ) / count( $nums ), 4 );
			}

			$columns[] = $col;
		}

		return array(
			'row_count'    => count( $rows ),
			'column_count' => $col_count,
			'headers'      => $headers,
			'columns'      => $columns,
		);
	}

	/**
	 * Load all rows from a CSV/TSV file.
	 *
	 * @param string $path      Absolute file path.
	 * @param string $delimiter Field delimiter.
	 * @return array
	 */
	private function load_csv( string $path, string $delimiter ): array {
		$content = \Agentic\File_Manager::get_contents( $path );
		if ( false === $content ) {
			return array();
		}
		$rows = array();
		foreach ( explode( "\n", rtrim( $content ) ) as $line ) {
			if ( '' !== trim( $line ) ) {
				$rows[] = str_getcsv( $line, $delimiter, '"', '\\' );
			}
		}
		return $rows;
	}

	/**
	 * Load all rows from an xlsx file (capped at 10 000 rows for analysis).
	 *
	 * @param string      $path       Absolute file path.
	 * @param string|null $sheet_name Sheet name or null for active sheet.
	 * @return array Rows as nested arrays, or array with 'error' key on failure.
	 */
	private function load_xlsx( string $path, ?string $sheet_name ): array {
		if ( ! class_exists( '\PhpOffice\PhpSpreadsheet\IOFactory' ) ) {
			return array( 'error' => 'PhpSpreadsheet library is not installed. Run: composer install' );
		}

		try {
			$spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load( $path );
		} catch ( \Exception $e ) {
			return array( 'error' => 'Could not load file: ' . $e->getMessage() );
		}

		$sheet = $sheet_name
			? $spreadsheet->getSheetByName( $sheet_name )
			: $spreadsheet->getActiveSheet();

		if ( ! $sheet ) {
			return array( 'error' => 'Sheet not found.' );
		}

		$rows    = array();
		$max_row = min( $sheet->getHighestDataRow(), 10000 );

		foreach ( $sheet->getRowIterator( 1, $max_row ) as $row ) {
			$cells = array();
			$ci    = $row->getCellIterator();
			$ci->setIterateOnlyExistingCells( false );
			foreach ( $ci as $cell ) {
				$cells[] = $cell->getFormattedValue();
			}
			while ( ! empty( $cells ) && '' === end( $cells ) ) {
				array_pop( $cells );
			}
			if ( ! empty( array_filter( $cells ) ) ) {
				$rows[] = $cells;
			}
		}

		return $rows;
	}

	/**
	 * Get tool annotations.
	 *
	 * @return array
	 */
	public function get_annotations(): array {
		return array( 'readonly' => true );
	}
}

return new Analyze_Spreadsheet();
