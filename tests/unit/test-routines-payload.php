<?php
/**
 * Unit Tests for Routines_Payload.
 *
 * @package Agentic\Tests
 */

namespace Agentic\Tests;

use Agentic\Admin_Pages\Routines_Payload;
use Agentic\Site_Health;

/**
 * Test case for the Routines page payload builder.
 */
class Test_Routines_Payload extends TestCase {

	/**
	 * Remove the cron last-tick option before each test so a previous test's
	 * tick can't leak into the next (options are not covered by the base
	 * cleanup_test_data() option filter).
	 */
	public function setUp(): void {
		parent::setUp();
		delete_option( 'agent_builder_cron_last_tick' );
	}

	/**
	 * Remove the cron last-tick option again on teardown.
	 */
	public function tearDown(): void {
		delete_option( 'agent_builder_cron_last_tick' );
		parent::tearDown();
	}

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

	/**
	 * A fresh cron tick surfaces cron_stale=false in the payload, mirroring
	 * Site_Health::cron_is_stale() rather than a second threshold copy.
	 */
	public function test_payload_exposes_fresh_cron_tick(): void {
		update_option( 'agent_builder_cron_last_tick', time() );

		$payload = Routines_Payload::build();

		$this->assertArrayHasKey( 'cron_stale', $payload );
		$this->assertFalse( $payload['cron_stale'] );
		$this->assertSame( Site_Health::cron_is_stale(), $payload['cron_stale'] );
	}

	/**
	 * A missing cron tick (never recorded) surfaces cron_stale=true.
	 */
	public function test_payload_exposes_missing_cron_tick(): void {
		$payload = Routines_Payload::build();

		$this->assertArrayHasKey( 'cron_stale', $payload );
		$this->assertTrue( $payload['cron_stale'] );
		$this->assertSame( Site_Health::cron_is_stale(), $payload['cron_stale'] );
	}

	/**
	 * A cron tick older than the 2-hour threshold surfaces cron_stale=true.
	 */
	public function test_payload_exposes_stale_cron_tick(): void {
		update_option( 'agent_builder_cron_last_tick', time() - ( 3 * HOUR_IN_SECONDS ) );

		$payload = Routines_Payload::build();

		$this->assertArrayHasKey( 'cron_stale', $payload );
		$this->assertTrue( $payload['cron_stale'] );
		$this->assertSame( Site_Health::cron_is_stale(), $payload['cron_stale'] );
	}
}
