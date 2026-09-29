<?php
/**
 * Tool: read_docx
 *
 * Extract text and structure from a Word document in the uploads directory.
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
 * Read and extract text content from a .docx file.
 */
class Read_Docx extends \Agentic\Tool_Base {

	public function get_name(): string {
		return 'read_docx';
	}

	public function get_description(): string {
		return 'Extract text content and structure from a Word document (.docx) stored in the WordPress uploads directory. Returns the full text, a heading outline, and table data. Use this to read uploaded contracts, reports, or any .docx file before editing or summarising it.';
	}

	public function get_category(): string {
		return 'files';
	}

	public function get_parameters(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'file_path'      => array(
					'type'        => 'string',
					'description' => 'Path to the .docx file relative to the WordPress uploads directory.',
				),
				'include_tables' => array(
					'type'        => 'boolean',
					'description' => 'Whether to extract table contents. Default true.',
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
		return $this->library_available( '\\PhpOffice\\PhpWord\\IOFactory' );
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
		if ( ! class_exists( '\PhpOffice\PhpWord\IOFactory' ) ) {
			return array( 'error' => 'phpoffice/phpword is not installed. Run: composer install' );
		}

		$path = $this->resolve_upload_path( $arguments['file_path'] ?? '' );
		if ( is_wp_error( $path ) ) {
			return array( 'error' => $path->get_error_message() );
		}

		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'docx', 'odt' ), true ) ) {
			return array( 'error' => 'Unsupported format. Only .docx and .odt files are supported.' );
		}

		$include_tables = $arguments['include_tables'] ?? true;

		try {
			$php_word = \PhpOffice\PhpWord\IOFactory::load( $path );
		} catch ( \Exception $e ) {
			return array( 'error' => 'Could not load document: ' . $e->getMessage() );
		}

		$sections   = $php_word->getSections();
		$full_text  = '';
		$headings   = array();
		$tables     = array();
		$para_count = 0;

		foreach ( $sections as $section ) {
			foreach ( $section->getElements() as $element ) {
				if ( $element instanceof \PhpOffice\PhpWord\Element\TextRun
					|| $element instanceof \PhpOffice\PhpWord\Element\Text ) {
					$text = $this->extract_text_from_element( $element );
					if ( '' !== $text ) {
						$full_text .= $text . "\n";
						++$para_count;
					}
				} elseif ( $element instanceof \PhpOffice\PhpWord\Element\Paragraph ) {
					$style = $element->getParagraphStyle();
					$text  = $this->extract_text_from_element( $element );
					if ( '' !== $text ) {
						$full_text .= $text . "\n";
						++$para_count;

						// Capture headings.
						if ( $style instanceof \PhpOffice\PhpWord\Style\Paragraph ) {
							$name = $style->getStyleName() ?? '';
							if ( str_starts_with( strtolower( $name ), 'heading' ) ) {
								$headings[] = array(
									'style' => $name,
									'text'  => $text,
								);
							}
						}
					}
				} elseif ( $include_tables && $element instanceof \PhpOffice\PhpWord\Element\Table ) {
					$table_data = array();
					foreach ( $element->getRows() as $row ) {
						$row_data = array();
						foreach ( $row->getCells() as $cell ) {
							$cell_text = '';
							foreach ( $cell->getElements() as $cel ) {
								$cell_text .= $this->extract_text_from_element( $cel );
							}
							$row_data[] = trim( $cell_text );
						}
						$table_data[] = $row_data;
					}
					if ( ! empty( $table_data ) ) {
						$tables[] = $table_data;
						// Also include table text in full_text.
						foreach ( $table_data as $row ) {
							$full_text .= implode( "\t", $row ) . "\n";
						}
					}
				}
			}
		}

		return array(
			'paragraph_count' => $para_count,
			'heading_count'   => count( $headings ),
			'table_count'     => count( $tables ),
			'headings'        => $headings,
			'tables'          => $tables,
			'text'            => mb_substr( $full_text, 0, 100000 ),
			'truncated'       => mb_strlen( $full_text ) > 100000,
		);
	}

	/**
	 * Recursively extract plain text from a PhpWord element.
	 *
	 * @param mixed $element PhpWord element.
	 * @return string
	 */
	private function extract_text_from_element( mixed $element ): string {
		if ( $element instanceof \PhpOffice\PhpWord\Element\Text ) {
			return $element->getText();
		}
		if ( $element instanceof \PhpOffice\PhpWord\Element\TextRun
			|| $element instanceof \PhpOffice\PhpWord\Element\Paragraph ) {
			$text = '';
			foreach ( $element->getElements() as $child ) {
				$text .= $this->extract_text_from_element( $child );
			}
			return $text;
		}
		if ( $element instanceof \PhpOffice\PhpWord\Element\Link ) {
			return $element->getText();
		}
		return '';
	}

	public function get_annotations(): array {
		return array( 'readonly' => true );
	}
}

return new Read_Docx();
