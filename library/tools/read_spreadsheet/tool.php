<?php
/**
 * Tool: read_spreadsheet
 *
 * Read data from an Excel or CSV/TSV file in the WordPress uploads directory.
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
 * Read data from a spreadsheet file.
 */
class Read_Spreadsheet extends \Agentic\Tool_Base {

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'read_spreadsheet';
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return 'Read data from a spreadsheet file (.xlsx, .xlsm, .csv, .tsv) stored in the WordPress uploads directory. Returns all sheet names, row/column counts, and up to max_rows rows of data. Call this first whenever the user references a spreadsheet file.';
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
					'description' => 'Path to the file relative to the WordPress uploads directory, e.g. "2024/01/report.xlsx", or the file_path a document tool returned.',
				),
				'sheet_name' => array(
					'type'        => 'string',
					'description' => 'Sheet name to read. Omit to read the first (active) sheet.',
				),
				'max_rows'   => array(
					'type'        => 'integer',
					'description' => 'Maximum rows to return (default 200, max 2000). Use a small number for a quick preview.',
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
		$max = min( (int) ( $arguments['max_rows'] ?? 200 ), 2000 );

		if ( in_array( $ext, array( 'csv', 'tsv' ), true ) ) {
			return $this->read_csv( $path, 'tsv' === $ext ? "\t" : ',', $max );
		}

		return $this->read_xlsx( $path, $arguments['sheet_name'] ?? null, $max );
	}

	/**
	 * Read an xlsx/xlsm file.
	 *
	 * @param string      $path       Absolute file path.
	 * @param string|null $sheet_name Sheet to read, or null for the active sheet.
	 * @param int         $max        Max rows to return.
	 * @return array
	 */
	private function read_xlsx( string $path, ?string $sheet_name, int $max ): array {
		if ( ! class_exists( '\PhpOffice\PhpSpreadsheet\IOFactory' ) ) {
			return array( 'error' => 'PhpSpreadsheet library is not installed. Run: composer install' );
		}

		try {
			$spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load( $path );
		} catch ( \Exception $e ) {
			return array( 'error' => 'Could not load file: ' . $e->getMessage() );
		}

		if ( null !== $sheet_name ) {
			$sheet = $spreadsheet->getSheetByName( $sheet_name );
			if ( ! $sheet ) {
				return array(
					'error' => "Sheet '{$sheet_name}' not found. Available: " . implode( ', ', $spreadsheet->getSheetNames() ),
				);
			}
		} else {
			$sheet = $spreadsheet->getActiveSheet();
		}

		$total_rows = $sheet->getHighestDataRow();
		$total_cols = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString( $sheet->getHighestDataColumn() );
		$rows       = array();
		$count      = 0;

		foreach ( $sheet->getRowIterator( 1, $max ) as $row ) {
			$cells = array();
			foreach ( $row->getCellIterator( 'A', $sheet->getHighestDataColumn() ) as $cell ) {
				$cells[] = $cell->getFormattedValue();
			}
			while ( ! empty( $cells ) && '' === end( $cells ) ) {
				array_pop( $cells );
			}
			$rows[] = $cells;
			++$count;
		}

		$result = array(
			'sheet_names'   => $spreadsheet->getSheetNames(),
			'active_sheet'  => $sheet->getTitle(),
			'total_rows'    => $total_rows,
			'total_columns' => $total_cols,
			'rows_returned' => $count,
			'data'          => $rows,
		);

		if ( $total_rows > $max ) {
			$result['truncated'] = true;
			$result['note']      = "Only first {$max} rows returned. Pass max_rows (up to 2000) for more.";
		}

		return $result;
	}

	/**
	 * Read a CSV or TSV file.
	 *
	 * @param string $path      Absolute file path.
	 * @param string $delimiter Field delimiter.
	 * @param int    $max       Max rows to return.
	 * @return array
	 */
	private function read_csv( string $path, string $delimiter, int $max ): array {
		$content = \Agentic\File_Manager::get_contents( $path );
		if ( false === $content ) {
			return array( 'error' => 'Could not open file.' );
		}

		$lines = explode( "\n", rtrim( $content ) );
		$rows  = array();
		foreach ( $lines as $line ) {
			if ( count( $rows ) >= $max ) {
				break;
			}
			if ( '' !== trim( $line ) ) {
				$rows[] = str_getcsv( $line, $delimiter );
			}
		}
		$truncated = count( $lines ) > $max;

		return array(
			'sheet_names'   => array( 'Sheet1' ),
			'active_sheet'  => 'Sheet1',
			'rows_returned' => count( $rows ),
			'data'          => $rows,
			'truncated'     => $truncated,
		);
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

return new Read_Spreadsheet();
