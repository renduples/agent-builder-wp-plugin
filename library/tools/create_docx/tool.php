<?php
/**
 * Tool: create_docx
 *
 * Create a Word document (.docx) from structured content.
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
 * Create a .docx file from structured content blocks.
 */
class Create_Docx extends \Agentic\Tool_Base {

	public function get_name(): string {
		return 'create_docx';
	}

	public function get_description(): string {
		return 'Create a Word document (.docx) from structured content blocks: headings, paragraphs, bullet lists, numbered lists, and tables. Returns the file path and public URL. Use this to export posts as Word documents, generate contracts, letters, or reports.';
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
					'description' => 'Output filename, e.g. "report.docx". The .docx extension is added if omitted.',
				),
				'title'    => array(
					'type'        => 'string',
					'description' => 'Optional document title stored in metadata.',
				),
				'author'   => array(
					'type'        => 'string',
					'description' => 'Optional document author stored in metadata.',
				),
				'sections' => array(
					'type'        => 'array',
					'description' => 'Ordered content blocks. Each block has a "type" and type-specific fields.',
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'type'    => array(
								'type'        => 'string',
								'enum'        => array( 'heading1', 'heading2', 'heading3', 'paragraph', 'bullet_list', 'numbered_list', 'table', 'page_break' ),
								'description' => 'Block type.',
							),
							'text'    => array(
								'type'        => 'string',
								'description' => 'Text content for heading and paragraph blocks.',
							),
							'bold'    => array(
								'type'        => 'boolean',
								'description' => 'Bold the paragraph text. Default false.',
							),
							'items'   => array(
								'type'        => 'array',
								'items'       => array( 'type' => 'string' ),
								'description' => 'List items for bullet_list and numbered_list.',
							),
							'headers' => array(
								'type'        => 'array',
								'items'       => array( 'type' => 'string' ),
								'description' => 'Column header strings for a table block.',
							),
							'rows'    => array(
								'type'        => 'array',
								'items'       => array(
									'type'  => 'array',
									'items' => array( 'type' => 'string' ),
								),
								'description' => 'Data rows for a table block (array of string arrays).',
							),
						),
						'required'   => array( 'type' ),
					),
				),
			),
			'required'   => array( 'filename', 'sections' ),
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

		$filename = sanitize_file_name( $arguments['filename'] ?? 'document.docx' );
		if ( ! str_ends_with( strtolower( $filename ), '.docx' ) ) {
			$filename .= '.docx';
		}

		$uploads = wp_upload_dir();
		$dir     = trailingslashit( $uploads['basedir'] ) . 'agentic-exports/';
		$dir_url = trailingslashit( $uploads['baseurl'] ) . 'agentic-exports/';

		if ( ! wp_mkdir_p( $dir ) ) {
			return array( 'error' => 'Could not create exports directory.' );
		}

		try {
			$php_word = new \PhpOffice\PhpWord\PhpWord();
			$php_word->getSettings()->setUpdateFields( true );

			// Document metadata.
			$props = $php_word->getDocInfo();
			if ( ! empty( $arguments['title'] ) ) {
				$props->setTitle( sanitize_text_field( $arguments['title'] ) );
			}
			if ( ! empty( $arguments['author'] ) ) {
				$props->setCreator( sanitize_text_field( $arguments['author'] ) );
			}

			// Default styles.
			$php_word->setDefaultFontName( 'Calibri' );
			$php_word->setDefaultFontSize( 11 );

			$section = $php_word->addSection();

			foreach ( (array) ( $arguments['sections'] ?? array() ) as $block ) {
				$type = $block['type'] ?? '';

				switch ( $type ) {
					case 'heading1':
					case 'heading2':
					case 'heading3':
						$level = (int) substr( $type, -1 );
						$section->addTitle( $block['text'] ?? '', $level );
						break;

					case 'paragraph':
						$font_style = array();
						if ( ! empty( $block['bold'] ) ) {
							$font_style['bold'] = true;
						}
						$section->addText(
							htmlspecialchars( $block['text'] ?? '', ENT_QUOTES ),
							$font_style ? $font_style : null
						);
						break;

					case 'bullet_list':
						$list_style = array( 'listType' => \PhpOffice\PhpWord\Style\ListItem::TYPE_BULLET_FILLED );
						foreach ( (array) ( $block['items'] ?? array() ) as $item ) {
							$section->addListItem( htmlspecialchars( (string) $item, ENT_QUOTES ), 0, null, $list_style );
						}
						break;

					case 'numbered_list':
						$list_style = array( 'listType' => \PhpOffice\PhpWord\Style\ListItem::TYPE_NUMBER );
						foreach ( (array) ( $block['items'] ?? array() ) as $item ) {
							$section->addListItem( htmlspecialchars( (string) $item, ENT_QUOTES ), 0, null, $list_style );
						}
						break;

					case 'table':
						$headers = (array) ( $block['headers'] ?? array() );
						$rows    = (array) ( $block['rows'] ?? array() );
						if ( empty( $headers ) && empty( $rows ) ) {
							break;
						}
						$col_count = max( count( $headers ), empty( $rows ) ? 0 : max( array_map( 'count', $rows ) ) );
						$col_width = $col_count > 0 ? (int) floor( 9360 / $col_count ) : 9360;

						$table = $section->addTable(
							array(
								'borderSize'  => 6,
								'borderColor' => 'cccccc',
								'cellMargin'  => 80,
							)
						);

						if ( ! empty( $headers ) ) {
							$table->addRow();
							foreach ( $headers as $header ) {
								$cell = $table->addCell( $col_width, array( 'bgColor' => 'f0f0f0' ) );
								$cell->addText( htmlspecialchars( (string) $header, ENT_QUOTES ), array( 'bold' => true ) );
							}
						}
						foreach ( $rows as $row ) {
							$table->addRow();
							foreach ( (array) $row as $cell_val ) {
								$cell = $table->addCell( $col_width );
								$cell->addText( htmlspecialchars( (string) $cell_val, ENT_QUOTES ) );
							}
						}
						break;

					case 'page_break':
						$section->addPageBreak();
						break;
				}
			}

			$file_path = $dir . $filename;
			$writer    = \PhpOffice\PhpWord\IOFactory::createWriter( $php_word, 'Word2007' );
			$writer->save( $file_path );

		} catch ( \Exception $e ) {
			return array( 'error' => 'Failed to create document: ' . $e->getMessage() );
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

return new Create_Docx();
