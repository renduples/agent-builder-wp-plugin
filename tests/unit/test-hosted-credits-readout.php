<?php
/**
 * Unit Tests for #158 (secondary): surfacing the hosted proxy's reported
 * credit balance (`x_credits_used` / `x_credits_remaining`, sent on every
 * successful hosted "agentic" call) so the admin has some visibility
 * without a portal trip.
 *
 * @package Agentic\Tests
 */

namespace Agentic\Tests;

use Agentic\LLM_Client;

/**
 * Test case for LLM_Client::get_last_hosted_credits() / capture_hosted_credits().
 */
class Test_Hosted_Credits_Readout extends TestCase {

	const OPTION = 'agent_builder_hosted_credits';

	/**
	 * Leave no state behind for other tests.
	 */
	public function tearDown(): void {
		delete_option( self::OPTION );

		parent::tearDown();
	}

	/**
	 * No reading yet: an empty array, not a notice-triggering missing key.
	 */
	public function test_no_reading_yet_yields_empty_array(): void {
		delete_option( self::OPTION );

		$this->assertSame( array(), LLM_Client::get_last_hosted_credits() );
	}

	/**
	 * A successful hosted response's credit fields are captured and readable.
	 */
	public function test_successful_response_captures_credit_balance(): void {
		$client  = new LLM_Client();
		$capture = new \ReflectionMethod( LLM_Client::class, 'capture_hosted_credits' );

		$capture->invoke(
			$client,
			array(
				'x_credits_used'      => 1.0,
				'x_credits_remaining' => 23.5,
			)
		);

		$stored = LLM_Client::get_last_hosted_credits();

		$this->assertSame( 23.5, $stored['remaining'] );
		$this->assertSame( 1.0, $stored['used'] );
		$this->assertNotEmpty( $stored['checked_at'] );
	}

	/**
	 * A response without the credit fields (e.g. a non-agentic provider, or a
	 * proxy response shape that changed) must not invent a stale reading.
	 */
	public function test_response_without_credit_fields_is_ignored(): void {
		delete_option( self::OPTION );

		$client  = new LLM_Client();
		$capture = new \ReflectionMethod( LLM_Client::class, 'capture_hosted_credits' );

		$capture->invoke( $client, array( 'choices' => array() ) );

		$this->assertSame( array(), LLM_Client::get_last_hosted_credits() );
	}
}
