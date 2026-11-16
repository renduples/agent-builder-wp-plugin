<?php
/**
 * Tool: convert_spreadsheet
 *
 * Convert a spreadsheet file between xlsx, csv, and tsv formats.
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
 * Convert spreadsheet files between xlsx, csv, and tsv.
 */
class Convert_Spreadsheet extends \Agentic\Tool_Base {

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'convert_spreadsheet';
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return 'Convert a spreadsheet file to a different format. Supported conversions: xlsx ↔ csv, xlsx → tsv, csv ↔ tsv. The output file is saved alongside the source file. Returns the relative path and public URL of the converted file.';
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
				'file_path'       => array(
					'type'        => 'string',
					'description' => 'Path to the source file relative to the WordPress uploads directory.',
				),
				'output_format'   => array(
					'type'        => 'string',
					'enum'        => array( 'xlsx', 'csv', 'tsv' ),
					'description' => 'Target format.',
				),
				'output_filename' => array(
					'type'        => 'string',
					'description' => 'Optional output filename. Defaults to the source filename with the new extension.',
				),
				'sheet_name'      => array(
					'type'        => 'string',
					'description' => 'For xlsx → csv/tsv: which sheet to export. Defaults to the active sheet.',
				),
			),
			'required'   => array( 'file_path', 'output_format' ),
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
		$rel = ltrim( $arguments['file_path'] ?? '', '/\\' );
		if ( '' === $rel ) {
			return array( 'error' => 'file_path is required.' );
		}

		$uploads  = wp_upload_dir();
		$base     = trailingslashit( $uploads['basedir'] );
		$base_url = trailingslashit( $uploads['baseurl'] );
		$path     = realpath( $base . $rel );

		if ( ! $path || ! str_starts_with( $path, $base ) || ! file_exists( $path ) ) {
			return array( 'error' => "File not found: {$rel}" );
		}

		$src_ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		$format  = strtolower( $arguments['output_format'] ?? '' );

		if ( $src_ext === $format ) {
			return array( 'error' => 'Source and target formats are the same.' );
		}

		$out_filename = sanitize_file_name(
			$arguments['output_filename'] ?? pathinfo( basename( $path ), PATHINFO_FILENAME ) . '.' . $format
		);
		$out_dir      = dirname( $path );
		$out_dir_rel  = dirname( $rel );
		$out_path     = $out_dir . '/' . $out_filename;
		$out_rel      = ( '.' === $out_dir_rel ? '' : trailingslashit( $out_dir_rel ) ) . $out_filename;

		try {
			if ( 'xlsx' === $src_ext && in_array( $format, array( 'csv', 'tsv' ), true ) ) {
				$result = $this->xlsx_to_delimited( $path, $out_path, $format, $arguments['sheet_name'] ?? null );
			} elseif ( in_array( $src_ext, array( 'csv', 'tsv' ), true ) && 'xlsx' === $format ) {
				$result = $this->delimited_to_xlsx( $path, $out_path, 'tsv' === $src_ext ? "\t" : ',' );
			} elseif ( 'csv' === $src_ext && 'tsv' === $format ) {
				$result = $this->rewrite_delimited( $path, $out_path, ',', "\t" );
			} elseif ( 'tsv' === $src_ext && 'csv' === $format ) {
				$result = $this->rewrite_delimited( $path, $out_path, "\t", ',' );
			} else {
				return array( 'error' => "Unsupported conversion: {$src_ext} → {$format}" );
			}

			if ( is_wp_error( $result ) ) {
				return array( 'error' => $result->get_error_message() );
			}

			return array(
				'file_path' => $out_rel,
				'url'       => $base_url . $out_rel,
			);

		} catch ( \Exception $e ) {
			return array( 'error' => 'Conversion failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Export an xlsx sheet to a delimited text file.
	 *
	 * @param string      $src        Source xlsx path.
	 * @param string      $dest       Destination file path.
	 * @param string      $format     'csv' or 'tsv'.
	 * @param string|null $sheet_name Sheet to export, or null for active sheet.
	 * @return true|\WP_Error
	 */
	private function xlsx_to_delimited( string $src, string $dest, string $format, ?string $sheet_name ): bool|\WP_Error {
		if ( ! class_exists( '\PhpOffice\PhpSpreadsheet\IOFactory' ) ) {
			return new \WP_Error( 'missing_lib', 'PhpSpreadsheet library is not installed. Run: composer install' );
		}

		$spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load( $src );
		$sheet       = $sheet_name
			? $spreadsheet->getSheetByName( $sheet_name )
			: $spreadsheet->getActiveSheet();

		if ( ! $sheet ) {
			return new \WP_Error( 'not_found', 'Sheet not found.' );
		}

		$delimiter = 'tsv' === $format ? "\t" : ',';
		$output    = '';

		foreach ( $sheet->getRowIterator() as $row ) {
			$cells = array();
			foreach ( $row->getCellIterator( 'A', $sheet->getHighestDataColumn() ) as $cell ) {
				$cells[] = $cell->getFormattedValue();
			}
			$output .= $this->array_to_csv_line( $cells, $delimiter );
		}

		if ( false === \Agentic\File_Manager::put_contents( $dest, $output ) ) {
			return new \WP_Error( 'write', 'Could not write output file.' );
		}

		return true;
	}

	/**
	 * Convert a delimited text file to xlsx.
	 *
	 * @param string $src       Source file path.
	 * @param string $dest      Destination xlsx path.
	 * @param string $delimiter Source delimiter.
	 * @return true|\WP_Error
	 */
	private function delimited_to_xlsx( string $src, string $dest, string $delimiter ): bool|\WP_Error {
		if ( ! class_exists( '\PhpOffice\PhpSpreadsheet\Spreadsheet' ) ) {
			return new \WP_Error( 'missing_lib', 'PhpSpreadsheet library is not installed. Run: composer install' );
		}

		$content = \Agentic\File_Manager::get_contents( $src );
		if ( false === $content ) {
			return new \WP_Error( 'read', 'Could not open source file.' );
		}

		$spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
		$sheet       = $spreadsheet->getActiveSheet();
		$row_num     = 1;

		foreach ( explode( "\n", rtrim( $content ) ) as $line ) {
			if ( '' !== trim( $line ) ) {
				$row = str_getcsv( $line, $delimiter, '"', '\\' );
				$sheet->fromArray( array( $row ), null, "A{$row_num}" );
				++$row_num;
			}
		}

		( new \PhpOffice\PhpSpreadsheet\Writer\Xlsx( $spreadsheet ) )->save( $dest );

		return true;
	}

	/**
	 * Re-write a delimited file with a different delimiter.
	 *
	 * @param string $src  Source file path.
	 * @param string $dest Destination file path.
	 * @param string $from Source delimiter.
	 * @param string $to   Target delimiter.
	 * @return true|\WP_Error
	 */
	private function rewrite_delimited( string $src, string $dest, string $from, string $to ): bool|\WP_Error {
		$content = \Agentic\File_Manager::get_contents( $src );
		if ( false === $content ) {
			return new \WP_Error( 'io', 'Could not open source file for conversion.' );
		}

		$output = '';
		foreach ( explode( "\n", rtrim( $content ) ) as $line ) {
			if ( '' !== trim( $line ) ) {
				$output .= $this->array_to_csv_line( str_getcsv( $line, $from, '"', '\\' ), $to );
			}
		}

		if ( false === \Agentic\File_Manager::put_contents( $dest, $output ) ) {
			return new \WP_Error( 'io', 'Could not write output file for conversion.' );
		}

		return true;
	}

	private function array_to_csv_line( array $fields, string $delimiter ): string {
		$parts = array();
		foreach ( $fields as $field ) {
			$field = (string) $field;
			if ( strpbrk( $field, $delimiter . '"' . "\n\r" ) !== false ) {
				$parts[] = '"' . str_replace( '"', '""', $field ) . '"';
			} else {
				$parts[] = $field;
			}
		}
		return implode( $delimiter, $parts ) . "\n";
	}
}

return new Convert_Spreadsheet();
