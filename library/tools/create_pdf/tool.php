<?php
/**
 * Tool: create_pdf
 *
 * Generate a PDF file from HTML content using mPDF.
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
 * Create a PDF from an HTML string, saved to the uploads directory.
 */
class Create_Pdf extends \Agentic\Tool_Base {

	public function get_name(): string {
		return 'create_pdf';
	}

	public function get_description(): string {
		return 'Generate a PDF file from HTML content and save it to the WordPress uploads directory. Supports full HTML with inline CSS: headings, tables, lists, images (by URL), page breaks, headers, and footers. Returns the relative file path and public URL. Ideal for invoices, reports, letters, and certificates.';
	}

	public function get_category(): string {
		return 'files';
	}

	public function get_parameters(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'filename'    => array(
					'type'        => 'string',
					'description' => 'Output filename, e.g. "invoice-123.pdf". The .pdf extension is added if omitted.',
				),
				'html'        => array(
					'type'        => 'string',
					'description' => 'HTML content to render as a PDF. Use inline or <style> CSS for formatting. Supports tables, lists, headings, and most standard HTML elements.',
				),
				'css'         => array(
					'type'        => 'string',
					'description' => 'Optional additional CSS to apply globally (appended to a <style> block).',
				),
				'orientation' => array(
					'type'        => 'string',
					'enum'        => array( 'portrait', 'landscape' ),
					'description' => 'Page orientation. Default "portrait".',
				),
				'format'      => array(
					'type'        => 'string',
					'description' => 'Page format: "A4" (default), "Letter", "A3", "A5", or "Legal".',
				),
				'margin'      => array(
					'type'        => 'integer',
					'description' => 'Page margin in mm applied to all sides. Default 15.',
				),
			),
			'required'   => array( 'filename', 'html' ),
		);
	}

	/**
	 * This tool depends on an optional library.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return $this->library_available( '\\Mpdf\\Mpdf' );
	}

	/**
	 * Explain why the tool cannot run.
	 *
	 * @return string
	 */
	public function get_unavailable_reason(): string {
		return __( 'Generating PDFs requires the mPDF library, which is not bundled with the free plugin.', 'agent-builder' );
	}

	public function execute( array $arguments ): array {
		if ( ! $this->is_available() ) {
			return $this->tool_error( 'library_missing', $this->get_unavailable_reason() );
		}
		$filename = sanitize_file_name( $arguments['filename'] ?? 'document.pdf' );
		if ( ! str_ends_with( strtolower( $filename ), '.pdf' ) ) {
			$filename .= '.pdf';
		}

		$html        = $arguments['html'] ?? '';
		$css         = $arguments['css'] ?? '';
		$orientation = 'landscape' === strtolower( $arguments['orientation'] ?? '' ) ? 'L' : 'P';
		$format      = strtoupper( $arguments['format'] ?? 'A4' );
		$margin      = max( 5, min( 50, (int) ( $arguments['margin'] ?? 15 ) ) );

		$allowed_formats = array( 'A3', 'A4', 'A5', 'LETTER', 'LEGAL' );
		if ( ! in_array( $format, $allowed_formats, true ) ) {
			$format = 'A4';
		}

		$uploads = wp_upload_dir();
		$dir     = trailingslashit( $uploads['basedir'] ) . 'agentic-exports/';
		$dir_url = trailingslashit( $uploads['baseurl'] ) . 'agentic-exports/';
		$tmp_dir = trailingslashit( sys_get_temp_dir() ) . 'agentic-mpdf/';

		if ( ! wp_mkdir_p( $dir ) ) {
			return array( 'error' => 'Could not create exports directory.' );
		}

		// mPDF needs a writable temp directory for font caching.
		if ( ! wp_mkdir_p( $tmp_dir ) ) {
			$tmp_dir = sys_get_temp_dir();
		}

		try {
			$mpdf = new \Mpdf\Mpdf(
				array(
					'mode'          => 'utf-8',
					'format'        => $format,
					'orientation'   => $orientation,
					'margin_left'   => $margin,
					'margin_right'  => $margin,
					'margin_top'    => $margin,
					'margin_bottom' => $margin,
					'tempDir'       => $tmp_dir,
				)
			);

			// Suppress mPDF notices about unsupported CSS.
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- mPDF third-party property name.
			$mpdf->showImageErrors     = false;
			$mpdf->ignore_invalid_utf8 = true;

			if ( '' !== $css ) {
				$mpdf->WriteHTML( '<style>' . $css . '</style>', \Mpdf\HTMLParserMode::HEADER_CSS );
			}

			$mpdf->WriteHTML( $html, \Mpdf\HTMLParserMode::HTML_BODY );

			$file_path = $dir . $filename;
			$mpdf->Output( $file_path, \Mpdf\Output\Destination::FILE );

		} catch ( \Exception $e ) {
			return array( 'error' => 'PDF generation failed: ' . $e->getMessage() );
		}

		if ( ! file_exists( $file_path ) ) {
			return array( 'error' => 'PDF file was not created.' );
		}

		return array(
			'file_path'    => 'agentic-exports/' . $filename,
			'url'          => $dir_url . $filename,
			'file_size_kb' => round( filesize( $file_path ) / 1024, 1 ),
		);
	}
}

return new Create_Pdf();
