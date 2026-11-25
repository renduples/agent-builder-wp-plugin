<?php
/**
 * Tool: merge_pdfs
 *
 * Combine multiple PDF files into one using FPDI.
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
 * Merge multiple PDF files from the uploads directory into a single PDF.
 */
class Merge_Pdfs extends \Agentic\Tool_Base {

	public function get_name(): string {
		return 'merge_pdfs';
	}

	public function get_description(): string {
		return 'Combine multiple PDF files from the WordPress uploads directory into a single PDF, in the order supplied. All pages from each source file are included. Returns the relative path and URL of the merged file.';
	}

	public function get_category(): string {
		return 'files';
	}

	public function get_parameters(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'files'    => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => 'Ordered list of PDF paths, each relative to the WordPress uploads directory.',
					'minItems'    => 2,
				),
				'filename' => array(
					'type'        => 'string',
					'description' => 'Output filename, e.g. "combined.pdf". The .pdf extension is added if omitted.',
				),
			),
			'required'   => array( 'files', 'filename' ),
		);
	}

	/**
	 * This tool depends on optional libraries.
	 *
	 * FPDF is checked first: autoloading Fpdi pulls in a class that extends
	 * \FPDF, which raises a fatal Error when FPDF is absent.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return $this->library_available( '\\FPDF' ) && $this->library_available( '\\setasign\\Fpdi\\Fpdi' );
	}

	/**
	 * Explain why the tool cannot run.
	 *
	 * @return string
	 */
	public function get_unavailable_reason(): string {
		return __( 'Merging PDFs requires the FPDF and FPDI libraries, which are not bundled with the free plugin.', 'agentstructor' );
	}

	public function execute( array $arguments ): array {
		if ( ! $this->is_available() ) {
			return $this->tool_error( 'library_missing', $this->get_unavailable_reason() );
		}

		$files    = (array) ( $arguments['files'] ?? array() );
		$filename = sanitize_file_name( $arguments['filename'] ?? 'merged.pdf' );

		if ( count( $files ) < 2 ) {
			return array( 'error' => 'At least two PDF files are required for merging.' );
		}

		if ( ! str_ends_with( strtolower( $filename ), '.pdf' ) ) {
			$filename .= '.pdf';
		}

		$uploads = wp_upload_dir();
		$base    = trailingslashit( $uploads['basedir'] );
		$out_dir = trailingslashit( \Agentic\File_Manager::exports_dir() );
		$out_url = trailingslashit( \Agentic\File_Manager::exports_url() );

		if ( ! \Agentic\File_Manager::ensure_data_dir( 'exports' ) ) {
			return array( 'error' => 'Could not create exports directory.' );
		}

		// Validate and resolve all source paths first.
		$resolved = array();
		foreach ( $files as $rel ) {
			$path = $this->resolve_upload_path( (string) $rel );
			if ( is_wp_error( $path ) ) {
				return array( 'error' => "Invalid file '{$rel}': " . $path->get_error_message() );
			}
			if ( strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) !== 'pdf' ) {
				return array( 'error' => "File is not a PDF: {$rel}" );
			}
			$resolved[] = $path;
		}

		try {
			$fpdi        = new \setasign\Fpdi\Fpdi();
			$total_pages = 0;

			foreach ( $resolved as $src ) {
				$page_count = $fpdi->setSourceFile( $src );
				for ( $p = 1; $p <= $page_count; $p++ ) {
					$tpl = $fpdi->importPage( $p );
					$fpdi->AddPage();
					$fpdi->useTemplate( $tpl );
					++$total_pages;
				}
			}

			$out_path = $out_dir . $filename;
			$fpdi->Output( $out_path, 'F' );

		} catch ( \Exception $e ) {
			return array( 'error' => 'PDF merge failed: ' . $e->getMessage() );
		}

		if ( ! file_exists( $out_path ) ) {
			return array( 'error' => 'Merged PDF was not created.' );
		}

		return array(
			'file_path'    => \Agentic\File_Manager::export_relative_path( $filename ),
			'url'          => $out_url . $filename,
			'total_pages'  => $total_pages,
			'sources'      => count( $resolved ),
			'file_size_kb' => round( filesize( $out_path ) / 1024, 1 ),
		);
	}
}

return new Merge_Pdfs();
