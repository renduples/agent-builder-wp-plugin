<?php
/**
 * Unit Tests for Prompt_Catalog_Writer.
 *
 * The writer edits a file that is half data and half hand-written research. The
 * thing worth testing is not that an edit lands, but that nothing else moves:
 * the prose, the decorative tables and the untouched rows all have to survive an
 * agent changing one row in the middle of the document.
 *
 * @package Agentic\Tests
 */

namespace Agentic\Tests;

use Agentic\Prompt_Testing\Prompt_Catalog;
use Agentic\Prompt_Testing\Prompt_Catalog_Writer;

require_once AGENT_BUILDER_DIR . 'includes/prompt-testing/class-prompt-catalog.php';
require_once AGENT_BUILDER_DIR . 'includes/prompt-testing/class-prompt-catalog-writer.php';

/**
 * Test case for Prompt_Catalog_Writer.
 */
class Test_Prompt_Catalog_Writer extends TestCase {

	/**
	 * Working copy of the catalog under test.
	 *
	 * @var string
	 */
	private string $path = '';

	/**
	 * Point the writer at a scratch file inside an allowed write root.
	 *
	 * File_Manager only permits the real catalog path under the plugin
	 * directory, so the fixture lives in uploads instead — which also proves the
	 * writer goes through File_Manager rather than around it.
	 */
	public function setUp(): void {
		parent::setUp();

		$uploads    = wp_upload_dir( null, false );
		$this->path = trailingslashit( $uploads['basedir'] ) . 'prompt-catalog-test.md';

		file_put_contents( $this->path, $this->fixture() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/**
	 * Remove the scratch file.
	 */
	public function tearDown(): void {
		if ( file_exists( $this->path ) ) {
			unlink( $this->path );
		}

		parent::tearDown();
	}

	/**
	 * A catalog with prose, a decorative table and two agent sections.
	 *
	 * @return string
	 */
	private function fixture(): string {
		return <<<'MD'
# Most Popular Prompts

Intro prose that must survive every edit.

## Ranked index

| Rank | Topic        |
| ---- | ------------ |
| 1    | Site is slow |

## Content Writer (content-writer)

| ID | Rank | Agent | Prompt | Expect Tools | Coverage | Notes | Source |
|---|---|---|---|---|---|---|---|
| P01 | 1 | content-writer | Write me a post about pruning | create_post_content | full | first | content |
| P02 | 2 | content-writer | Make this page readable | rewrite_for_readability | partial | second | content |

## SEO Optimizer (seo-optimizer)

| ID | Rank | Agent | Prompt | Expect Tools | Coverage | Notes | Source |
|---|---|---|---|---|---|---|---|
| P03 | 3 | seo-optimizer | Which posts need SEO work? | list_posts_needing_seo | full | third | seo |

## Coverage gaps

Closing prose that must also survive.
MD;
	}

	/**
	 * The file's prose and decorative tables are untouched by an edit.
	 */
	public function test_editing_a_row_preserves_the_surrounding_document(): void {
		$result = Prompt_Catalog_Writer::update_row( $this->path, 'P02', array( 'rank' => 9 ) );

		$this->assertTrue( $result['success'], $result['message'] );

		$after = file_get_contents( $this->path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$this->assertStringContainsString( 'Intro prose that must survive every edit.', $after );
		$this->assertStringContainsString( 'Closing prose that must also survive.', $after );
		$this->assertStringContainsString( '## Ranked index', $after );
		$this->assertStringContainsString( '| 1    | Site is slow |', $after );
		$this->assertStringContainsString( '## SEO Optimizer (seo-optimizer)', $after );
	}

	/**
	 * Only the named row changes; its neighbours are byte-identical.
	 */
	public function test_updating_one_row_leaves_the_others_alone(): void {
		$before = Prompt_Catalog::load( $this->path )['rows'];

		Prompt_Catalog_Writer::update_row( $this->path, 'P02', array( 'rank' => 9 ) );

		$after = Prompt_Catalog::load( $this->path )['rows'];
		$index = array_column( $after, null, 'id' );

		$this->assertCount( count( $before ), $after );
		$this->assertSame( 9, $index['P02']['rank'] );
		$this->assertSame( 'Make this page readable', $index['P02']['prompt'] );
		$this->assertSame( 'second', $index['P02']['notes'] );

		$this->assertSame( 1, $index['P01']['rank'] );
		$this->assertSame( 'Write me a post about pruning', $index['P01']['prompt'] );
		$this->assertSame( 'Which posts need SEO work?', $index['P03']['prompt'] );
	}

	/**
	 * Several fields can be set at once, and expect_tools accepts a CSV string.
	 */
	public function test_update_sets_multiple_fields(): void {
		Prompt_Catalog_Writer::update_row(
			$this->path,
			'P01',
			array(
				'prompt'       => 'Draft a post about hedge trimming',
				'expect_tools' => 'create_post_content, list_posts',
				'coverage'     => 'partial',
				'notes'        => 'rewritten',
			)
		);

		$row = array_column( Prompt_Catalog::load( $this->path )['rows'], null, 'id' )['P01'];

		$this->assertSame( 'Draft a post about hedge trimming', $row['prompt'] );
		$this->assertSame( array( 'create_post_content', 'list_posts' ), $row['expect_tools'] );
		$this->assertSame( 'partial', $row['coverage'] );
		$this->assertSame( 'rewritten', $row['notes'] );
	}

	/**
	 * A prompt containing a pipe round-trips instead of splitting the row.
	 */
	public function test_a_pipe_in_a_prompt_round_trips(): void {
		$prompt = 'What does wp post list | wc -l tell me?';

		Prompt_Catalog_Writer::update_row( $this->path, 'P01', array( 'prompt' => $prompt ) );

		$parsed = Prompt_Catalog::load( $this->path );

		$this->assertSame( array(), $parsed['warnings'] );
		$this->assertCount( 3, $parsed['rows'] );
		$this->assertSame( $prompt, array_column( $parsed['rows'], null, 'id' )['P01']['prompt'] );
	}

	/**
	 * A new row joins its own agent's table, not whichever was last in the file.
	 */
	public function test_add_row_lands_in_the_right_agent_section(): void {
		$result = Prompt_Catalog_Writer::add_row(
			$this->path,
			array(
				'agent'        => 'content-writer',
				'prompt'       => 'Write an About page for my plumbing business',
				'rank'         => 12,
				'expect_tools' => 'create_post_content',
				'coverage'     => 'full',
			)
		);

		$this->assertTrue( $result['success'], $result['message'] );
		$this->assertSame( 'P04', $result['row']['id'] );

		$contents = file_get_contents( $this->path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$content_writer_section = strpos( $contents, '## Content Writer' );
		$seo_section            = strpos( $contents, '## SEO Optimizer' );
		$new_row                = strpos( $contents, 'plumbing business' );

		$this->assertGreaterThan( $content_writer_section, $new_row );
		$this->assertLessThan( $seo_section, $new_row, 'The new row should sit inside the content-writer table.' );

		$rows = array_column( Prompt_Catalog::load( $this->path )['rows'], null, 'id' );
		$this->assertSame( 'content-writer', $rows['P04']['agent'] );
		$this->assertSame( 12, $rows['P04']['rank'] );
	}

	/**
	 * Adding for an agent with no section is refused with an explanation.
	 */
	public function test_add_row_refuses_an_agent_with_no_section(): void {
		$result = Prompt_Catalog_Writer::add_row(
			$this->path,
			array(
				'agent'  => 'site-health-sentinel',
				'prompt' => 'Is my site healthy?',
			)
		);

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'no table for "site-health-sentinel"', $result['message'] );
	}

	/**
	 * Removing a row drops only that row.
	 */
	public function test_remove_row( ): void {
		$this->assertTrue( Prompt_Catalog_Writer::remove_row( $this->path, 'P02' )['success'] );

		$ids = array_column( Prompt_Catalog::load( $this->path )['rows'], 'id' );

		$this->assertSame( array( 'P01', 'P03' ), $ids );

		$contents = file_get_contents( $this->path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertStringContainsString( 'Closing prose that must also survive.', $contents );
	}

	/**
	 * An unknown ID is reported, not silently ignored.
	 */
	public function test_unknown_id_is_reported(): void {
		$result = Prompt_Catalog_Writer::update_row( $this->path, 'P99', array( 'rank' => 1 ) );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'P99', $result['message'] );
	}

	/**
	 * A write outside File_Manager's allowed roots is refused.
	 *
	 * The writer must not be a way around the plugin's file-write policy.
	 */
	public function test_writing_outside_an_allowed_root_is_refused(): void {
		$outside = WP_CONTENT_DIR . '/prompt-catalog-outside-test.md';

		file_put_contents( $outside, $this->fixture() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$result = Prompt_Catalog_Writer::update_row( $outside, 'P01', array( 'rank' => 5 ) );

		$this->assertFalse( $result['success'] );
		$this->assertSame( $this->fixture(), file_get_contents( $outside ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		unlink( $outside );
	}
}
