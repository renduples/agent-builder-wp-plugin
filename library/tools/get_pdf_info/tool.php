<?php
/**
 * Tool: get_pdf_info
 *
 * Return metadata and structural information about a PDF file.
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
 * Return metadata for a PDF file without extracting full text.
 */
class Get_Pdf_Info extends \Agentic\Tool_Base {

	public function get_name(): string {
		return 'get_pdf_info';
	}

	public function get_description(): string {
		return 'Return metadata and structural information about a PDF file: page count, title, author, creation date, file size, and whether the file appears to be text-based or scanned. Call this before read_pdf to plan your extraction.';
	}

	public function get_category(): string {
		return 'files';
	}

	public function get_parameters(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'file_path' => array(
					'type'        => 'string',
					'description' => 'Path to the PDF relative to the WordPress uploads directory.',
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
		return __( 'Reading PDFs requires the PdfParser library, which is not installed on this site.', 'siteloom' );
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
			$parser  = new \Smalot\PdfParser\Parser( array(), $config );
			$pdf     = $parser->parseFile( $path );
			$details = $pdf->getDetails();
			$pages   = $pdf->getPages();
		} catch ( \Exception $e ) {
			return array( 'error' => 'Could not parse PDF: ' . $e->getMessage() );
		}

		$page_count = count( $pages );

		// Sample first page to estimate if text-based.
		$sample_text = '';
		if ( $page_count > 0 ) {
			try {
				$sample_text = $pages[0]->getText();
			} catch ( \Exception $e ) {
				$sample_text = '';
			}
		}

		// Normalise detail keys (they vary by PDF producer).
		$title    = $details['Title'] ?? $details['title'] ?? '';
		$author   = $details['Author'] ?? $details['author'] ?? '';
		$created  = $details['CreationDate'] ?? $details['created'] ?? '';
		$producer = $details['Producer'] ?? $details['producer'] ?? '';
		$creator  = $details['Creator'] ?? $details['creator'] ?? '';

		return array(
			'page_count'     => $page_count,
			'file_size'      => filesize( $path ),
			'file_size_kb'   => round( filesize( $path ) / 1024, 1 ),
			'title'          => (string) $title,
			'author'         => (string) $author,
			'created'        => (string) $created,
			'producer'       => (string) $producer,
			'creator'        => (string) $creator,
			'likely_scanned' => strlen( trim( $sample_text ) ) < 50,
			'text_sample'    => mb_substr( trim( $sample_text ), 0, 300 ),
		);
	}

	public function get_annotations(): array {
		return array( 'readonly' => true );
	}
}

return new Get_Pdf_Info();
