<?php
/**
 * Runs the JavaScript markdown renderer tests (tests/js/markdown.test.js).
 *
 * @package Agentic\Tests
 */

namespace Agentic\Tests;

/**
 * Wraps the node test script so it runs with the PHP suite.
 */
class Test_Markdown_Renderer extends TestCase {

	/**
	 * The chat markdown renderer passes its node test cases.
	 */
	public function test_markdown_renderer_cases_pass(): void {
		$node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );
		if ( '' === $node ) {
			$this->markTestSkipped( 'node is not available.' );
		}

		$script = AGENT_BUILDER_DIR . 'tests/js/markdown.test.js';
		$output = array();
		$status = 0;
		exec( escapeshellarg( $node ) . ' ' . escapeshellarg( $script ) . ' 2>&1', $output, $status );

		$this->assertSame( 0, $status, implode( "\n", $output ) );
	}
}
