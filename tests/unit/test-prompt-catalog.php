<?php
/**
 * Unit Tests for Prompt_Catalog.
 *
 * The catalog is a hand-edited markdown file, so the parser's job is to survive
 * the four ways a hand-edited markdown table actually breaks: a pipe inside a
 * cell, a short row, reordered or renamed columns, and a decorative table that
 * isn't data at all. Everything downstream of the parser costs money to run, so
 * these are the cheapest tests in the suite and the ones worth having.
 *
 * @package Agentic\Tests
 */

namespace Agentic\Tests;

use Agentic\Prompt_Testing\Prompt_Catalog;

// The prompt-testing engine is required lazily by its consumers (the WP-CLI
// command and the self-improvement tools), neither of which runs under PHPUnit.
require_once AGENT_BUILDER_DIR . 'includes/prompt-testing/class-prompt-catalog.php';

/**
 * Test case for Prompt_Catalog.
 */
class Test_Prompt_Catalog extends TestCase {

	/**
	 * A minimal well-formed catalog.
	 *
	 * @return string
	 */
	private function sample(): string {
		return <<<'MD'
# Prompts

Some prose.

| ID  | Rank | Agent          | Prompt                        | Expect Tools              | Coverage | Notes | Source |
| --- | ---- | -------------- | ----------------------------- | ------------------------- | -------- | ----- | ------ |
| P01 | 1    | seo-optimizer  | Which posts are thin content? | list_posts_needing_seo    | full     | ok    | wpb    |
| P02 | 2    | content-writer | Write a post about pruning    | create_post_content, list_posts | partial |   | wpb |
MD;
	}

	/**
	 * A well-formed catalog parses into rows with every column mapped.
	 */
	public function test_parses_a_well_formed_table(): void {
		$result = Prompt_Catalog::parse( $this->sample() );

		$this->assertSame( array(), $result['warnings'] );
		$this->assertCount( 2, $result['rows'] );

		$first = $result['rows'][0];
		$this->assertSame( 'P01', $first['id'] );
		$this->assertSame( 1, $first['rank'] );
		$this->assertSame( 'seo-optimizer', $first['agent'] );
		$this->assertSame( 'Which posts are thin content?', $first['prompt'] );
		$this->assertSame( array( 'list_posts_needing_seo' ), $first['expect_tools'] );
		$this->assertSame( 'full', $first['coverage'] );

		$this->assertSame(
			array( 'create_post_content', 'list_posts' ),
			$result['rows'][1]['expect_tools']
		);
	}

	/**
	 * An escaped pipe stays part of the prompt instead of splitting the row.
	 */
	public function test_escaped_pipe_survives_in_a_prompt(): void {
		$md = <<<'MD'
| ID  | Agent          | Prompt                          |
| --- | -------------- | ------------------------------- |
| P01 | content-writer | Explain what `wp post list \| wc -l` does |
MD;

		$result = Prompt_Catalog::parse( $md );

		$this->assertCount( 1, $result['rows'] );
		$this->assertSame(
			'Explain what `wp post list | wc -l` does',
			$result['rows'][0]['prompt']
		);
	}

	/**
	 * Columns are looked up by name, so reordering or renaming them is safe.
	 */
	public function test_columns_are_matched_by_name_not_position(): void {
		$md = <<<'MD'
| Agent         | expect_tools           | Prompt              | ID  |
| ------------- | ---------------------- | ------------------- | --- |
| seo-optimizer | list_posts_needing_seo | Find my thin posts  | P09 |
MD;

		$row = Prompt_Catalog::parse( $md )['rows'][0];

		$this->assertSame( 'P09', $row['id'] );
		$this->assertSame( 'seo-optimizer', $row['agent'] );
		$this->assertSame( 'Find my thin posts', $row['prompt'] );
		$this->assertSame( array( 'list_posts_needing_seo' ), $row['expect_tools'] );
	}

	/**
	 * Optional columns may be absent entirely.
	 */
	public function test_optional_columns_may_be_missing(): void {
		$md = <<<'MD'
| Agent          | Prompt            |
| -------------- | ----------------- |
| content-writer | Draft an About page |
MD;

		$result = Prompt_Catalog::parse( $md );

		$this->assertCount( 1, $result['rows'] );
		$this->assertSame( array(), $result['rows'][0]['expect_tools'] );
		$this->assertSame( 0, $result['rows'][0]['rank'] );
		// No ID column — one gets synthesised, with a warning.
		$this->assertStringStartsWith( 'ROW', $result['rows'][0]['id'] );
		$this->assertNotEmpty( $result['warnings'] );
	}

	/**
	 * An unknown extra column is ignored rather than shifting the others.
	 *
	 * This is what lets a future column (a judge rubric, say) be added to the
	 * catalog without touching the parser.
	 */
	public function test_unknown_columns_are_ignored(): void {
		$md = <<<'MD'
| ID  | Agent          | Rubric              | Prompt          |
| --- | -------------- | ------------------- | --------------- |
| P01 | content-writer | Must mention pricing | Write a post    |
MD;

		$row = Prompt_Catalog::parse( $md )['rows'][0];

		$this->assertSame( 'content-writer', $row['agent'] );
		$this->assertSame( 'Write a post', $row['prompt'] );
	}

	/**
	 * A table without prompt/agent columns is decorative and not parsed.
	 */
	public function test_decorative_table_is_skipped(): void {
		$md = <<<'MD'
## Ranked index

| Rank | Topic              | Where it hurts |
| ---- | ------------------ | -------------- |
| 1    | Site is slow       | Everywhere     |

## Prompts

| ID  | Agent          | Prompt       |
| --- | -------------- | ------------ |
| P01 | content-writer | Write a post |
MD;

		$result = Prompt_Catalog::parse( $md );

		$this->assertCount( 1, $result['rows'] );
		$this->assertSame( 'P01', $result['rows'][0]['id'] );
	}

	/**
	 * A table shown as an example inside a fenced block is documentation, not data.
	 */
	public function test_table_inside_a_fenced_block_is_ignored(): void {
		$md = "## How to add a prompt\n\n"
			. "```markdown\n"
			. "| ID  | Agent          | Prompt        |\n"
			. "| --- | -------------- | ------------- |\n"
			. "| P99 | content-writer | Example only  |\n"
			. "```\n\n"
			. "## Prompts\n\n"
			. "| ID  | Agent          | Prompt       |\n"
			. "| --- | -------------- | ------------ |\n"
			. "| P01 | content-writer | Write a post |\n";

		$result = Prompt_Catalog::parse( $md );

		$this->assertCount( 1, $result['rows'] );
		$this->assertSame( 'P01', $result['rows'][0]['id'] );
	}

	/**
	 * A row missing its prompt is warned about and skipped, not fatal.
	 */
	public function test_malformed_row_warns_and_skips(): void {
		$md = <<<'MD'
| ID  | Agent          | Prompt       |
| --- | -------------- | ------------ |
| P01 | content-writer |              |
| P02 | content-writer | Write a post |
MD;

		$result = Prompt_Catalog::parse( $md );

		$this->assertCount( 1, $result['rows'] );
		$this->assertSame( 'P02', $result['rows'][0]['id'] );
		$this->assertCount( 1, $result['warnings'] );
		$this->assertStringContainsString( 'no prompt', $result['warnings'][0] );
	}

	/**
	 * A duplicate ID keeps the first row and warns about the second.
	 */
	public function test_duplicate_id_keeps_the_first_row(): void {
		$md = <<<'MD'
| ID  | Agent          | Prompt   |
| --- | -------------- | -------- |
| P01 | content-writer | First    |
| P01 | seo-optimizer  | Second   |
MD;

		$result = Prompt_Catalog::parse( $md );

		$this->assertCount( 1, $result['rows'] );
		$this->assertSame( 'First', $result['rows'][0]['prompt'] );
		$this->assertStringContainsString( 'duplicate ID', $result['warnings'][0] );
	}

	/**
	 * Rows from several tables across the document are all collected.
	 */
	public function test_rows_are_collected_across_multiple_tables(): void {
		$md = <<<'MD'
## Content Writer (content-writer)

| ID  | Agent          | Prompt       |
| --- | -------------- | ------------ |
| P01 | content-writer | Write a post |

## SEO Optimizer (seo-optimizer)

| ID  | Agent         | Prompt          |
| --- | ------------- | --------------- |
| P02 | seo-optimizer | Audit my titles |
MD;

		$this->assertCount( 2, Prompt_Catalog::parse( $md )['rows'] );
	}

	/**
	 * Validation rejects an agent slug or tool name that does not exist.
	 */
	public function test_validate_flags_unknown_agents_and_tools(): void {
		$rows = Prompt_Catalog::parse( $this->sample() )['rows'];

		$this->assertSame(
			array(),
			Prompt_Catalog::validate(
				$rows,
				array( 'seo-optimizer', 'content-writer' ),
				array( 'list_posts_needing_seo', 'create_post_content', 'list_posts' )
			)
		);

		$problems = Prompt_Catalog::validate( $rows, array( 'seo-optimizer' ), array( 'list_posts_needing_seo' ) );

		$this->assertNotEmpty( $problems );
		$this->assertStringContainsString( 'unknown agent "content-writer"', implode( "\n", $problems ) );
		$this->assertStringContainsString( 'create_post_content', implode( "\n", $problems ) );
	}

	/**
	 * Range specs cover the four shapes the CLI documents.
	 *
	 * @dataProvider range_spec_provider
	 *
	 * @param string $spec     Range spec.
	 * @param int    $value    Value to test.
	 * @param bool   $expected Whether it should match.
	 */
	public function test_parse_range_spec( string $spec, int $value, bool $expected ): void {
		$matcher = Prompt_Catalog::parse_range_spec( $spec );

		$this->assertSame( $expected, $matcher( $value ), "spec={$spec} value={$value}" );
	}

	/**
	 * Range spec cases.
	 *
	 * @return array<string, array{0:string,1:int,2:bool}>
	 */
	public function range_spec_provider(): array {
		return array(
			'closed range, inside'   => array( '1-10', 5, true ),
			'closed range, outside'  => array( '1-10', 11, false ),
			'closed range, boundary' => array( '1-10', 10, true ),
			'list, present'          => array( '1,3,7', 3, true ),
			'list, absent'           => array( '1,3,7', 4, false ),
			'open end, inside'       => array( '5-', 900, true ),
			'open end, outside'      => array( '5-', 4, false ),
			'open start, inside'     => array( '-10', 2, true ),
			'open start, outside'    => array( '-10', 11, false ),
			'mixed'                  => array( '1,5-7', 6, true ),
			'empty matches nothing'  => array( '', 1, false ),
		);
	}

	/**
	 * Filtering by agent, rank, id and limit.
	 */
	public function test_filter_selects_the_right_rows(): void {
		$rows = Prompt_Catalog::parse( $this->sample() )['rows'];

		$this->assertCount( 1, Prompt_Catalog::filter( $rows, array( 'agent' => 'seo-optimizer' ) ) );
		$this->assertCount( 2, Prompt_Catalog::filter( $rows, array( 'agent' => 'seo-optimizer,content-writer' ) ) );
		$this->assertCount( 1, Prompt_Catalog::filter( $rows, array( 'rank' => '1' ) ) );
		$this->assertCount( 2, Prompt_Catalog::filter( $rows, array( 'rank' => '1-5' ) ) );
		$this->assertCount( 1, Prompt_Catalog::filter( $rows, array( 'id' => 'P02' ) ) );
		$this->assertCount( 1, Prompt_Catalog::filter( $rows, array( 'limit' => 1 ) ) );
		$this->assertSame( array(), Prompt_Catalog::filter( $rows, array( 'agent' => 'nope' ) ) );
	}

	/**
	 * An ID range selects the rows between its endpoints.
	 */
	public function test_filter_supports_id_ranges(): void {
		$md = <<<'MD'
| ID  | Rank | Agent          | Prompt |
| --- | ---- | -------------- | ------ |
| P01 | 1    | content-writer | One    |
| P02 | 2    | content-writer | Two    |
| P03 | 3    | content-writer | Three  |
| P09 | 9    | content-writer | Nine   |
MD;

		$rows     = Prompt_Catalog::parse( $md )['rows'];
		$filtered = Prompt_Catalog::filter( $rows, array( 'id' => 'P01-P03' ) );

		$this->assertSame( array( 'P01', 'P02', 'P03' ), array_column( $filtered, 'id' ) );
	}

	/**
	 * Unfiltered rows come back in rank order regardless of document order.
	 */
	public function test_filter_sorts_by_rank(): void {
		$md = <<<'MD'
| ID  | Rank | Agent          | Prompt |
| --- | ---- | -------------- | ------ |
| P09 | 9    | content-writer | Nine   |
| P01 | 1    | content-writer | One    |
| P05 | 5    | content-writer | Five   |
MD;

		$filtered = Prompt_Catalog::filter( Prompt_Catalog::parse( $md )['rows'], array() );

		$this->assertSame( array( 'P01', 'P05', 'P09' ), array_column( $filtered, 'id' ) );
	}

	/**
	 * Loading a missing file throws rather than returning an empty catalog.
	 */
	public function test_load_throws_on_a_missing_file(): void {
		$this->expectException( \RuntimeException::class );

		Prompt_Catalog::load( '/nonexistent/prompts-' . wp_generate_password( 8, false ) . '.md' );
	}

	/**
	 * The catalog that actually ships parses cleanly and matches real agents.
	 *
	 * This is the regression test that matters day to day: it fails the moment
	 * someone edits the catalog into a shape the runner cannot read, or names an
	 * agent or tool that does not exist — without spending a penny on the LLM.
	 */
	public function test_the_shipped_catalog_is_valid(): void {
		$path = AGENT_BUILDER_DIR . 'tests/most_popular_prompts.md';

		if ( ! is_readable( $path ) ) {
			$this->markTestSkipped( 'Catalog not present (stripped from the WordPress.org build).' );
		}

		$result = Prompt_Catalog::load( $path );

		$this->assertSame( array(), $result['warnings'], "Catalog parse warnings:\n" . implode( "\n", $result['warnings'] ) );
		$this->assertGreaterThanOrEqual( 50, count( $result['rows'] ), 'Expected at least 50 prompts in the catalog.' );

		$agent_slugs = array_map(
			'basename',
			array_filter( glob( AGENT_BUILDER_DIR . 'library/agents/*' ) ?: array(), 'is_dir' )
		);
		$tool_names  = array_map(
			'basename',
			array_filter( glob( AGENT_BUILDER_DIR . 'library/tools/*' ) ?: array(), 'is_dir' )
		);

		$problems = Prompt_Catalog::validate( $result['rows'], $agent_slugs, $tool_names );

		$this->assertSame( array(), $problems, "Catalog validation problems:\n" . implode( "\n", $problems ) );
	}
}
