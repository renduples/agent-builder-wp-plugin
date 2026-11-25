<?php
/**
 * Tool: read_pdf
 *
 * Extract text from a PDF file in the WordPress uploads directory.
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
 * Extract text content from a PDF file.
 */
class Read_Pdf extends \Agentic\Tool_Base {

	public function get_name(): string {
		return 'read_pdf';
	}

	public function get_description(): string {
		return 'Extract text content from a PDF file stored in the WordPress uploads directory. Returns text per page. Works on text-based PDFs; scanned/image PDFs will return little or no text. Use get_pdf_info first to check page count and confirm the file is text-based.';
	}

	public function get_category(): string {
		return 'files';
	}

	public function get_parameters(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'file_path'  => array(
					'type'        => 'string',
					'description' => 'Path to the PDF relative to the WordPress uploads directory, e.g. "2024/01/contract.pdf".',
				),
				'page_start' => array(
					'type'        => 'integer',
					'description' => 'First page to extract (1-indexed). Defaults to 1.',
				),
				'page_end'   => array(
					'type'        => 'integer',
					'description' => 'Last page to extract inclusive. Defaults to all pages, capped at 50.',
				),
			),
			'required'   => array( 'file_path' ),
		);
	}

	/**
	 * This tool depends on an optional library.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return $this->library_available( '\\Smalot\\PdfParser\\Parser' );
	}

	/**
	 * Explain why the tool cannot run.
	 *
	 * @return string
	 */
	public function get_unavailable_reason(): string {
		return __( 'Reading PDFs requires the PdfParser library, which is not installed on this site.', 'agentstructor' );
	}

	public function execute( array $arguments ): array {
		if ( ! class_exists( '\Smalot\PdfParser\Parser' ) ) {
			return array( 'error' => 'smalot/pdfparser is not installed. Run: composer install' );
		}

		$path = $this->resolve_upload_path( $arguments['file_path'] ?? '' );
		if ( is_wp_error( $path ) ) {
			return array( 'error' => $path->get_error_message() );
		}

		if ( strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) !== 'pdf' ) {
			return array( 'error' => 'File is not a PDF.' );
		}

		try {
			$config = new \Smalot\PdfParser\Config();
			$config->setRetainImageContent( false );
			$parser = new \Smalot\PdfParser\Parser( array(), $config );
			$pdf    = $parser->parseFile( $path );
			$pages  = $pdf->getPages();
		} catch ( \Exception $e ) {
			return array( 'error' => 'Could not parse PDF: ' . $e->getMessage() );
		}

		$total      = count( $pages );
		$page_start = max( 1, (int) ( $arguments['page_start'] ?? 1 ) );
		$page_end   = min( $total, (int) ( $arguments['page_end'] ?? $total ), $page_start + 49 );

		$result_pages = array();
		for ( $i = $page_start; $i <= $page_end; $i++ ) {
			try {
				$text = $pages[ $i - 1 ]->getText();
			} catch ( \Exception $e ) {
				$text = '';
			}
			$result_pages[] = array(
				'page' => $i,
				'text' => trim( $text ),
			);
		}

		$total_chars = array_sum( array_map( static fn( $p ) => strlen( $p['text'] ), $result_pages ) );

		return array(
			'total_pages'    => $total,
			'pages_returned' => count( $result_pages ),
			'page_range'     => "{$page_start}–{$page_end}",
			'pages'          => $result_pages,
			'total_chars'    => $total_chars,
			'likely_scanned' => $total_chars < 100 && $total > 0,
			'note'           => $total > $page_end
				? "Only pages {$page_start}–{$page_end} returned. Pass page_start/page_end to read more."
				: null,
		);
	}

	public function get_annotations(): array {
		return array( 'readonly' => true );
	}
}

return new Read_Pdf();
