<?php
/**
 * Tool: create_spreadsheet
 *
 * Create a new Excel (.xlsx) file in the WordPress uploads directory.
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
 * Create a new Excel spreadsheet from supplied headers and data rows.
 */
class Create_Spreadsheet extends \Agentic\Tool_Base {

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'create_spreadsheet';
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return 'Create a new Excel (.xlsx) spreadsheet file in the WordPress uploads directory. Supply column headers and data rows. Strings starting with "=" are written as Excel formulas. Returns the relative file path and public URL of the created file.';
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
				'filename'      => array(
					'type'        => 'string',
					'description' => 'Filename for the new file, e.g. "monthly-report.xlsx". The .xlsx extension is added if omitted.',
				),
				'headers'       => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => 'Array of column header names for row 1.',
				),
				'rows'          => array(
					'type'        => 'array',
					'items'       => array(
						'type'        => 'array',
						'description' => 'One row of values. Strings, numbers, or Excel formula strings starting with "=".',
					),
					'description' => 'Data rows to write below the header.',
				),
				'sheet_name'    => array(
					'type'        => 'string',
					'description' => 'Worksheet tab name. Defaults to "Sheet1". Max 31 characters.',
				),
				'bold_header'   => array(
					'type'        => 'boolean',
					'description' => 'Bold the header row. Defaults to true.',
				),
				'column_widths' => array(
					'type'                 => 'object',
					'description'          => 'Optional map of column letter to width in characters, e.g. {"A": 20, "B": 35}.',
					'additionalProperties' => array( 'type' => 'number' ),
				),
			),
			'required'   => array( 'filename', 'headers', 'rows' ),
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
		return $this->library_available( '\\PhpOffice\\PhpSpreadsheet\\Spreadsheet' );
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
		if ( ! class_exists( '\PhpOffice\PhpSpreadsheet\Spreadsheet' ) ) {
			return array( 'error' => 'PhpSpreadsheet library is not installed. Run: composer install' );
		}

		$filename = sanitize_file_name( $arguments['filename'] ?? 'spreadsheet.xlsx' );
		if ( ! str_ends_with( strtolower( $filename ), '.xlsx' ) ) {
			$filename .= '.xlsx';
		}

		$uploads = wp_upload_dir();
		$dir     = trailingslashit( $uploads['basedir'] ) . 'agentic-exports/';
		$dir_url = trailingslashit( $uploads['baseurl'] ) . 'agentic-exports/';

		if ( ! wp_mkdir_p( $dir ) ) {
			return array( 'error' => 'Could not create exports directory.' );
		}

		$headers     = $arguments['headers'] ?? array();
		$rows        = $arguments['rows'] ?? array();
		$sheet_name  = substr( $arguments['sheet_name'] ?? 'Sheet1', 0, 31 );
		$bold_header = $arguments['bold_header'] ?? true;
		$col_widths  = (array) ( $arguments['column_widths'] ?? array() );

		try {
			$spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
			$sheet       = $spreadsheet->getActiveSheet();
			$sheet->setTitle( $sheet_name );

			if ( ! empty( $headers ) ) {
				$sheet->fromArray( array( $headers ), null, 'A1' );

				if ( $bold_header ) {
					$last_col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex( count( $headers ) );
					$sheet->getStyle( "A1:{$last_col}1" )->getFont()->setBold( true );
				}
			}

			$start_row = empty( $headers ) ? 1 : 2;
			foreach ( $rows as $i => $row ) {
				$row_num = $start_row + $i;
				foreach ( (array) $row as $c => $value ) {
					$col  = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex( $c + 1 );
					$cell = $sheet->getCell( $col . $row_num );
					$this->write_cell_value( $cell, $value );
				}
			}

			if ( ! empty( $col_widths ) ) {
				foreach ( $col_widths as $letter => $width ) {
					$sheet->getColumnDimension( strtoupper( (string) $letter ) )->setWidth( (float) $width );
				}
			} elseif ( ! empty( $headers ) ) {
				$header_count = count( $headers );
				for ( $c = 1; $c <= $header_count; $c++ ) {
					$sheet->getColumnDimensionByColumn( $c )->setAutoSize( true );
				}
			}

			$file_path = $dir . $filename;
			( new \PhpOffice\PhpSpreadsheet\Writer\Xlsx( $spreadsheet ) )->save( $file_path );

			return array(
				'file_path' => 'agentic-exports/' . $filename,
				'url'       => $dir_url . $filename,
				'row_count' => count( $rows ),
				'col_count' => count( $headers ),
			);

		} catch ( \Exception $e ) {
			return array( 'error' => 'Failed to create spreadsheet: ' . $e->getMessage() );
		}
	}

	/**
	 * Write a value to a cell, treating strings starting with "=" as formulas.
	 *
	 * @param \PhpOffice\PhpSpreadsheet\Cell\Cell $cell  Target cell.
	 * @param mixed                               $value Value to write.
	 * @return void
	 */
	private function write_cell_value( \PhpOffice\PhpSpreadsheet\Cell\Cell $cell, mixed $value ): void {
		if ( is_string( $value ) && str_starts_with( $value, '=' ) ) {
			$cell->setValue( $value );
			return;
		}
		$cell->setValueExplicit(
			$value,
			is_numeric( $value )
				? \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC
				: \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
		);
	}
}

return new Create_Spreadsheet();
