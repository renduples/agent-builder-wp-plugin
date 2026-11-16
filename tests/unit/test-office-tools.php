<?php
/**
 * Integration tests for the bundled office-library tools.
 *
 * Exercises every spreadsheet tool (PhpSpreadsheet + ZipStream) and every
 * docx tool (PHPWord) end to end against real files in the uploads
 * directory, so a library upgrade that changes behaviour fails here rather
 * than on a user's site. The docx tools are skipped when PHPWord is absent
 * (the WordPress.org build does not ship it).
 *
 * @package Agentic\Tests
 */

namespace Agentic\Tests;

/**
 * Test case for the office tools.
 */
class Test_Office_Tools extends TestCase {

	/**
	 * Relative (to uploads basedir) paths created during a test.
	 *
	 * @var string[]
	 */
	private array $created = array();

	/**
	 * Load the tool classes under test.
	 */
	public function setUp(): void {
		parent::setUp();
		$tools = array( 'create_spreadsheet', 'read_spreadsheet', 'edit_spreadsheet', 'convert_spreadsheet', 'analyze_spreadsheet', 'create_docx', 'read_docx', 'html_to_docx' );
		foreach ( $tools as $tool ) {
			require_once AGENT_BUILDER_DIR . 'library/tools/' . $tool . '/tool.php';
		}
	}

	/**
	 * Remove generated files.
	 */
	public function tearDown(): void {
		$base = trailingslashit( wp_upload_dir()['basedir'] );
		foreach ( $this->created as $rel ) {
			if ( file_exists( $base . $rel ) ) {
				unlink( $base . $rel );
			}
		}
		parent::tearDown();
	}

	/**
	 * Track a generated file for cleanup.
	 *
	 * @param string $rel Path relative to uploads basedir.
	 * @return string The same path.
	 */
	private function track( string $rel ): string {
		$this->created[] = $rel;
		return $rel;
	}

	/**
	 * Library versions are the upgraded ones.
	 */
	public function test_library_versions_are_current(): void {
		$this->assertTrue( class_exists( \PhpOffice\PhpSpreadsheet\Spreadsheet::class ) );
		$this->assertTrue( class_exists( \ZipStream\ZipStream::class ) );
		$this->assertTrue( version_compare( \Composer\InstalledVersions::getVersion( 'phpoffice/phpspreadsheet' ), '5.0.0', '>=' ) );
		$this->assertTrue( version_compare( \Composer\InstalledVersions::getVersion( 'maennchen/zipstream-php' ), '3.1.0', '>=' ) );
	}

	/**
	 * Create, read, analyze, edit and convert an xlsx (and the csv round trip).
	 */
	public function test_spreadsheet_round_trip(): void {
		$created = ( new \Agentic\Tools\Create_Spreadsheet() )->execute(
			array(
				'filename'      => 'office-test-' . wp_generate_password( 6, false ) . '.xlsx',
				'headers'       => array( 'Name', 'Qty', 'Price' ),
				'rows'          => array(
					array( 'Apple', 3, 1.5 ),
					array( 'Pear', 5, 2 ),
					array( 'Total', '=SUM(B2:B3)', '' ),
				),
				'sheet_name'    => 'Fruit',
				'column_widths' => array(
					'A' => 20,
					'B' => 10,
				),
			)
		);
		$this->assertArrayNotHasKey( 'error', $created, $created['error'] ?? '' );
		$xlsx = $this->track( $created['file_path'] );
		$this->assertSame( 3, $created['row_count'] );

		$read = ( new \Agentic\Tools\Read_Spreadsheet() )->execute( array( 'file_path' => $xlsx ) );
		$this->assertArrayNotHasKey( 'error', $read, $read['error'] ?? '' );
		$this->assertSame( array( 'Fruit' ), $read['sheet_names'] );
		$this->assertSame( 4, $read['total_rows'] );
		$flat = wp_json_encode( $read['data'] );
		$this->assertStringContainsString( 'Apple', $flat );
		$this->assertStringContainsString( 'Pear', $flat );

		$analysis = ( new \Agentic\Tools\Analyze_Spreadsheet() )->execute( array( 'file_path' => $xlsx ) );
		$this->assertArrayNotHasKey( 'error', $analysis, $analysis['error'] ?? '' );
		$this->assertSame( array( 'Name', 'Qty', 'Price' ), $analysis['headers'] );
		$this->assertSame( 3, $analysis['column_count'] );

		$edit = ( new \Agentic\Tools\Edit_Spreadsheet() )->execute(
			array(
				'file_path' => $xlsx,
				'changes'   => array(
					array(
						'op'    => 'set_cell',
						'cell'  => 'A2',
						'value' => 'Green apple',
					),
					array(
						'op'  => 'append_row',
						'row' => array( 'Plum', 7, 0.5 ),
					),
					array(
						'op'   => 'rename_sheet',
						'name' => 'Produce',
					),
				),
			)
		);
		$this->assertArrayNotHasKey( 'error', $edit, $edit['error'] ?? '' );
		$this->assertSame( 3, $edit['changes_applied'] );
		$this->assertSame( array(), $edit['errors'] );

		$reread = ( new \Agentic\Tools\Read_Spreadsheet() )->execute( array( 'file_path' => $xlsx ) );
		$this->assertSame( array( 'Produce' ), $reread['sheet_names'] );
		$this->assertSame( 5, $reread['total_rows'] );
		$flat = wp_json_encode( $reread['data'] );
		$this->assertStringContainsString( 'Green apple', $flat );
		$this->assertStringContainsString( 'Plum', $flat );

		// xlsx -> csv.
		$csv_res = ( new \Agentic\Tools\Convert_Spreadsheet() )->execute(
			array(
				'file_path'     => $xlsx,
				'output_format' => 'csv',
			)
		);
		$this->assertArrayNotHasKey( 'error', $csv_res, $csv_res['error'] ?? '' );
		$csv     = $this->track( $csv_res['file_path'] ?? $csv_res['output_path'] ?? '' );
		$csv_abs = trailingslashit( wp_upload_dir()['basedir'] ) . $csv;
		$this->assertFileExists( $csv_abs );
		$csv_body = (string) file_get_contents( $csv_abs ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- test fixture.
		$this->assertStringContainsString( 'Green apple', $csv_body );
		$this->assertStringContainsString( 'Plum', $csv_body );

		// csv is readable directly.
		$csv_read = ( new \Agentic\Tools\Read_Spreadsheet() )->execute( array( 'file_path' => $csv ) );
		$this->assertArrayNotHasKey( 'error', $csv_read, $csv_read['error'] ?? '' );
		$this->assertStringContainsString( 'Plum', wp_json_encode( $csv_read ) );

		// csv -> xlsx.
		$back = ( new \Agentic\Tools\Convert_Spreadsheet() )->execute(
			array(
				'file_path'       => $csv,
				'output_format'   => 'xlsx',
				'output_filename' => 'office-test-back-' . wp_generate_password( 6, false ) . '.xlsx',
			)
		);
		$this->assertArrayNotHasKey( 'error', $back, $back['error'] ?? '' );
		$back_rel = $this->track( $back['file_path'] ?? $back['output_path'] ?? '' );
		$back_read = ( new \Agentic\Tools\Read_Spreadsheet() )->execute( array( 'file_path' => $back_rel ) );
		$this->assertArrayNotHasKey( 'error', $back_read, $back_read['error'] ?? '' );
		$this->assertStringContainsString( 'Green apple', wp_json_encode( $back_read['data'] ) );
	}

	/**
	 * Create and read a docx (PHPWord), and convert HTML to docx.
	 */
	public function test_docx_round_trip(): void {
		if ( ! class_exists( \PhpOffice\PhpWord\PhpWord::class ) ) {
			$this->markTestSkipped( 'PHPWord is not installed (WordPress.org build).' );
		}

		$created = ( new \Agentic\Tools\Create_Docx() )->execute(
			array(
				'filename' => 'office-test-' . wp_generate_password( 6, false ) . '.docx',
				'title'    => 'Office test',
				'sections' => array(
					array(
						'type' => 'heading1',
						'text' => 'Quarterly report',
					),
					array(
						'type' => 'paragraph',
						'text' => 'Sales went up & costs went down.',
					),
					array(
						'type'  => 'bullet_list',
						'items' => array( 'One', 'Two' ),
					),
					array(
						'type'    => 'table',
						'headers' => array( 'Region', 'Sales' ),
						'rows'    => array( array( 'North', '10' ) ),
					),
				),
			)
		);
		$this->assertArrayNotHasKey( 'error', $created, $created['error'] ?? '' );
		$docx = $this->track( $created['file_path'] );

		$read = ( new \Agentic\Tools\Read_Docx() )->execute( array( 'file_path' => $docx ) );
		$this->assertArrayNotHasKey( 'error', $read, $read['error'] ?? '' );
		$this->assertStringContainsString( 'Sales went up & costs went down.', $read['text'] );
		$this->assertGreaterThanOrEqual( 1, $read['table_count'] );

		$html = ( new \Agentic\Tools\Html_To_Docx() )->execute(
			array(
				'filename' => 'office-test-html-' . wp_generate_password( 6, false ) . '.docx',
				'html'     => '<h1>Hello</h1><p>From <strong>HTML</strong>.</p>',
			)
		);
		$this->assertArrayNotHasKey( 'error', $html, $html['error'] ?? '' );
		$html_rel = $this->track( $html['file_path'] );
		$html_read = ( new \Agentic\Tools\Read_Docx() )->execute( array( 'file_path' => $html_rel ) );
		$this->assertArrayNotHasKey( 'error', $html_read, $html_read['error'] ?? '' );
		$this->assertStringContainsString( 'HTML', $html_read['text'] );
	}
}
