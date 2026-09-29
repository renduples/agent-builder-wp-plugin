<?php
/**
 * Tool: html_to_docx
 *
 * Convert an HTML string to a .docx file using PhpWord's HTML parser.
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
 * Convert HTML content to a Word document.
 */
class Html_To_Docx extends \Agentic\Tool_Base {

	public function get_name(): string {
		return 'html_to_docx';
	}

	public function get_description(): string {
		return 'Convert an HTML string to a Word document (.docx) and save it to the WordPress uploads directory. Useful for exporting WordPress post content, page content, or any HTML-formatted text as a downloadable Word file. Supports headings, paragraphs, lists, basic tables, bold, italic, and links.';
	}

	public function get_category(): string {
		return 'files';
	}

	public function get_parameters(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'filename' => array(
					'type'        => 'string',
					'description' => 'Output filename, e.g. "post-export.docx". The .docx extension is added if omitted.',
				),
				'html'     => array(
					'type'        => 'string',
					'description' => 'HTML content to convert. Standard WordPress post HTML works well. Avoid complex CSS or JavaScript.',
				),
				'title'    => array(
					'type'        => 'string',
					'description' => 'Optional document title stored in file metadata.',
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
		return $this->library_available( '\\PhpOffice\\PhpWord\\PhpWord' );
	}

	/**
	 * Explain why the tool cannot run.
	 *
	 * @return string
	 */
	public function get_unavailable_reason(): string {
		return __( 'Word document tools require the PhpWord library, which is not installed on this site.', 'agent-builder' );
	}

	public function execute( array $arguments ): array {
		if ( ! class_exists( '\PhpOffice\PhpWord\PhpWord' ) ) {
			return array( 'error' => 'phpoffice/phpword is not installed. Run: composer install' );
		}

		$filename = sanitize_file_name( $arguments['filename'] ?? 'export.docx' );
		if ( ! str_ends_with( strtolower( $filename ), '.docx' ) ) {
			$filename .= '.docx';
		}

		$uploads = wp_upload_dir();
		$dir     = trailingslashit( $uploads['basedir'] ) . 'agentic-exports/';
		$dir_url = trailingslashit( $uploads['baseurl'] ) . 'agentic-exports/';

		if ( ! wp_mkdir_p( $dir ) ) {
			return array( 'error' => 'Could not create exports directory.' );
		}

		$html  = $arguments['html'] ?? '';
		$title = $arguments['title'] ?? '';

		if ( '' === $html ) {
			return array( 'error' => 'html is required.' );
		}

		// PhpWord's HTML parser requires a full <html> wrapper.
		if ( ! str_contains( strtolower( $html ), '<html' ) ) {
			$html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>';
		}

		try {
			$php_word = new \PhpOffice\PhpWord\PhpWord();
			$php_word->setDefaultFontName( 'Calibri' );
			$php_word->setDefaultFontSize( 11 );

			if ( '' !== $title ) {
				$php_word->getDocInfo()->setTitle( sanitize_text_field( $title ) );
			}

			$section = $php_word->addSection();

			\PhpOffice\PhpWord\Shared\Html::addHtml( $section, $html, false, false );

			$file_path = $dir . $filename;
			$writer    = \PhpOffice\PhpWord\IOFactory::createWriter( $php_word, 'Word2007' );
			$writer->save( $file_path );

		} catch ( \Exception $e ) {
			return array( 'error' => 'Conversion failed: ' . $e->getMessage() );
		}

		if ( ! file_exists( $file_path ) ) {
			return array( 'error' => 'Document file was not created.' );
		}

		return array(
			'file_path'    => 'agentic-exports/' . $filename,
			'url'          => $dir_url . $filename,
			'file_size_kb' => round( filesize( $file_path ) / 1024, 1 ),
		);
	}
}

return new Html_To_Docx();
