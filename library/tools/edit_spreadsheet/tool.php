<?php
/**
 * Tool: edit_spreadsheet
 *
 * Edit an existing Excel spreadsheet file in the WordPress uploads directory.
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
 * Edit cells, append rows, or rename sheets in an existing xlsx file.
 */
class Edit_Spreadsheet extends \Agentic\Tool_Base {

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'edit_spreadsheet';
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return 'Edit an existing Excel spreadsheet: set cell values or formulas, append rows to a sheet, or rename a sheet tab. Call read_spreadsheet first to understand the current structure. Changes are applied in order and the file is saved in place.';
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
					'description' => 'Sheet to edit. Defaults to the active sheet.',
				),
				'changes'    => array(
					'type'        => 'array',
					'description' => 'Ordered list of change operations to apply.',
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'op'    => array(
								'type'        => 'string',
								'enum'        => array( 'set_cell', 'append_row', 'rename_sheet' ),
								'description' => 'Operation type.',
							),
							'cell'  => array(
								'type'        => 'string',
								'description' => 'Cell reference for set_cell, e.g. "B3" or "C10".',
							),
							'value' => array(
								'description' => 'Value for set_cell. Strings starting with "=" are written as Excel formulas.',
							),
							'row'   => array(
								'type'        => 'array',
								'description' => 'Array of values for append_row.',
							),
							'name'  => array(
								'type'        => 'string',
								'description' => 'New sheet tab name for rename_sheet (max 31 characters).',
							),
						),
						'required'   => array( 'op' ),
					),
				),
			),
			'required'   => array( 'file_path', 'changes' ),
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
		return __( 'Spreadsheet tools require the PhpSpreadsheet library, which is not installed on this site.', 'siteloom' );
	}

	public function execute( array $arguments ): array {
		if ( ! class_exists( '\PhpOffice\PhpSpreadsheet\IOFactory' ) ) {
			return array( 'error' => 'PhpSpreadsheet library is not installed. Run: composer install' );
		}

		$path = $this->resolve_upload_path( $arguments['file_path'] ?? '' );
		if ( is_wp_error( $path ) ) {
			return array( 'error' => $path->get_error_message() );
		}

		$changes = $arguments['changes'] ?? array();
		if ( empty( $changes ) ) {
			return array( 'error' => 'No changes provided.' );
		}

		try {
			$spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load( $path );
		} catch ( \Exception $e ) {
			return array( 'error' => 'Could not load file: ' . $e->getMessage() );
		}

		$sheet_name = $arguments['sheet_name'] ?? null;
		$sheet      = $sheet_name
			? $spreadsheet->getSheetByName( $sheet_name )
			: $spreadsheet->getActiveSheet();

		if ( ! $sheet ) {
			return array( 'error' => 'Sheet not found.' );
		}

		$applied = 0;
		$errors  = array();

		foreach ( $changes as $change ) {
			$op = $change['op'] ?? '';

			switch ( $op ) {
				case 'set_cell':
					$cell_ref = strtoupper( $change['cell'] ?? '' );
					if ( ! $cell_ref ) {
						$errors[] = 'set_cell missing cell reference.';
						break;
					}
					$this->write_cell_value( $sheet->getCell( $cell_ref ), $change['value'] ?? '' );
					++$applied;
					break;

				case 'append_row':
					$row_data = $change['row'] ?? array();
					if ( empty( $row_data ) ) {
						$errors[] = 'append_row missing row data.';
						break;
					}
					$next_row = $sheet->getHighestDataRow() + 1;
					foreach ( (array) $row_data as $c => $value ) {
						$col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex( $c + 1 );
						$this->write_cell_value( $sheet->getCell( $col . $next_row ), $value );
					}
					++$applied;
					break;

				case 'rename_sheet':
					$new_name = substr( $change['name'] ?? '', 0, 31 );
					if ( $new_name ) {
						$sheet->setTitle( $new_name );
						++$applied;
					} else {
						$errors[] = 'rename_sheet missing name.';
					}
					break;

				default:
					$errors[] = "Unknown operation: {$op}";
			}
		}

		try {
			( new \PhpOffice\PhpSpreadsheet\Writer\Xlsx( $spreadsheet ) )->save( $path );
		} catch ( \Exception $e ) {
			return array( 'error' => 'Failed to save file: ' . $e->getMessage() );
		}

		return array(
			'changes_applied' => $applied,
			'errors'          => $errors,
			'file_path'       => $arguments['file_path'],
		);
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

return new Edit_Spreadsheet();
