<?php
/**
 * Unit Tests for Routines_Payload.
 *
 * @package Agentic\Tests
 */

namespace Agentic\Tests;

use Agentic\Admin_Pages\Routines_Payload;

/**
 * Test case for the Routines page payload builder.
 */
class Test_Routines_Payload extends TestCase {

	/**
	 * build() returns the static page shell only — the routines list itself is
	 * fetched client-side by RoutinesView.js, so the payload must not carry it.
	 */
	public function test_payload_build_returns_page_shell_only(): void {
		$payload = Routines_Payload::build();

		$this->assertSame( 'routines', $payload['page'] );
		$this->assertSame( 'Routines', $payload['title'] );
		$this->assertArrayHasKey( 'description', $payload );
		$this->assertArrayHasKey( 'docs_url', $payload );
		// The list is deliberately not part of the payload.
		$this->assertArrayNotHasKey( 'routines', $payload );
	}
}
