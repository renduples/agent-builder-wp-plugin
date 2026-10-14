<?php
/**
 * Unit Tests for Tool_Helpers::to_block_markup() and its use by the post tools.
 *
 * Agent-written posts used to be stored as classic HTML, so the block editor
 * opened them as a single Classic block. The create/update post tools now
 * convert plain HTML to block markup and leave existing block markup alone.
 *
 * @package Agentic\Tests
 */

namespace Agentic\Tests;

use Agentic\Tool_Helpers;

/**
 * Test case for block markup conversion.
 */
class Test_Block_Markup extends TestCase {

	/**
	 * Paragraphs become core/paragraph blocks.
	 */
	public function test_paragraphs_become_paragraph_blocks(): void {
		$out = Tool_Helpers::to_block_markup( '<p>Hello <strong>world</strong>.</p><p>Second &amp; last.</p>' );

		$this->assertSame(
			"<!-- wp:paragraph -->\n<p>Hello <strong>world</strong>.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Second &amp; last.</p>\n<!-- /wp:paragraph -->",
			$out
		);

		$blocks = array_values( array_filter( parse_blocks( $out ), static fn( $b ) => null !== $b['blockName'] ) );
		$this->assertCount( 2, $blocks );
		$this->assertSame( 'core/paragraph', $blocks[0]['blockName'] );
	}

	/**
	 * Plain text paragraphs (blank-line separated) become paragraph blocks.
	 */
	public function test_plain_text_becomes_paragraph_blocks(): void {
		$out    = Tool_Helpers::to_block_markup( "First paragraph.\n\nSecond paragraph." );
		$blocks = array_values( array_filter( parse_blocks( $out ), static fn( $b ) => null !== $b['blockName'] ) );

		$this->assertCount( 2, $blocks );
		$this->assertStringContainsString( '<p>First paragraph.</p>', $blocks[0]['innerHTML'] );
		$this->assertStringContainsString( '<p>Second paragraph.</p>', $blocks[1]['innerHTML'] );
	}

	/**
	 * Headings carry their level and the core class; lists get list-item blocks.
	 */
	public function test_headings_and_lists(): void {
		$out = Tool_Helpers::to_block_markup( '<h2>Intro</h2><h3>Sub</h3><ul><li>One</li><li>Two</li></ul><ol><li>A</li></ol>' );

		$this->assertStringContainsString( "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Intro</h2>", $out );
		$this->assertStringContainsString( "<!-- wp:heading {\"level\":3} -->\n<h3 class=\"wp-block-heading\">Sub</h3>", $out );
		$this->assertStringContainsString( "<!-- wp:list-item -->\n<li>One</li>\n<!-- /wp:list-item -->", $out );
		$this->assertStringContainsString( '<!-- wp:list {"ordered":true} -->', $out );

		$names = array_values( array_filter( wp_list_pluck( parse_blocks( $out ), 'blockName' ) ) );
		$this->assertSame( array( 'core/heading', 'core/heading', 'core/list', 'core/list' ), $names );
	}

	/**
	 * Elements a core block would not reproduce exactly fall back to core/html.
	 */
	public function test_complex_elements_become_html_blocks(): void {
		$out   = Tool_Helpers::to_block_markup( '<p class="lead">Styled</p><table><tr><td>x</td></tr></table>' );
		$names = array_values( array_filter( wp_list_pluck( parse_blocks( $out ), 'blockName' ) ) );

		$this->assertSame( array( 'core/html', 'core/html' ), $names );
		$this->assertStringContainsString( '<p class="lead">Styled</p>', $out );
	}

	/**
	 * Content that already has block comments is returned untouched.
	 */
	public function test_existing_block_markup_untouched(): void {
		$content = "<!-- wp:paragraph -->\n<p>Already blocks</p>\n<!-- /wp:paragraph -->";
		$this->assertSame( $content, Tool_Helpers::to_block_markup( $content ) );
		$this->assertSame( '', Tool_Helpers::to_block_markup( '' ) );
	}

	/**
	 * Non-ASCII text survives the conversion unchanged.
	 */
	public function test_utf8_preserved(): void {
		$out = Tool_Helpers::to_block_markup( '<p>Café — naïve “quotes”</p>' );
		$this->assertStringContainsString( '<p>Café — naïve “quotes”</p>', $out );
	}

	/**
	 * The create_post_content tool saves block markup for posts.
	 */
	public function test_create_post_content_tool_saves_blocks(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$tool   = $this->load_tool( 'create_post_content', 'Agentic\\Tools\\Create_Post_Content' );
		$result = $tool->execute(
			array(
				'title'   => 'Block test',
				'content' => '<p>One</p><p>Two</p>',
			)
		);

		$this->assertTrue( $result['success'] ?? false );
		$content = get_post_field( 'post_content', $result['post_id'] );
		$this->assertTrue( has_blocks( $content ) );
		$this->assertStringContainsString( '<!-- wp:paragraph -->', $content );
	}

	/**
	 * Load a library tool instance once per process.
	 *
	 * @param string $slug  Tool directory.
	 * @param string $class Tool class name.
	 * @return \Agentic\Tool_Base
	 */
	private function load_tool( string $slug, string $class ) {
		if ( ! class_exists( $class ) ) {
			return require AGENT_BUILDER_DIR . 'library/tools/' . $slug . '/tool.php';
		}
		return new $class();
	}
}
