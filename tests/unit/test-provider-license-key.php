<?php
/**
 * Unit Tests for the hosted-provider billing identity.
 *
 * Regression cover for issue #136: `agent_builder_license_key` was read in four
 * places and written in none, so on a fresh install the hosted Agentic provider
 * had no identity to send. Requests were rejected by the relay, and
 * has_usable_provider() decided nothing was configured — which gates the whole
 * admin menu, so completing signup sent the user straight back to signup.
 *
 * @package Agentic\Tests
 */

namespace Agentic\Tests;

use Agentic\Provider_Registry;

/**
 * Test case for Provider_Registry::get_license_key().
 */
class Test_Provider_License_Key extends TestCase {

	/**
	 * Reset the option and provider cache between tests.
	 */
	public function setUp(): void {
		parent::setUp();

		delete_option( 'agent_builder_license_key' );
		Provider_Registry::save_api_key( 'agentic', '' );
		Provider_Registry::invalidate();
	}

	/**
	 * Leave no key behind for other tests.
	 */
	public function tearDown(): void {
		delete_option( 'agent_builder_license_key' );
		Provider_Registry::save_api_key( 'agentic', '' );
		Provider_Registry::invalidate();

		parent::tearDown();
	}

	/**
	 * The bug: signup stores the credential on the provider row and never sets
	 * the option, so the identity has to be resolvable from the row alone.
	 */
	public function test_identity_falls_back_to_the_provider_row(): void {
		$this->assertSame( '', Provider_Registry::get_license_key(), 'precondition: nothing configured' );

		Provider_Registry::save_api_key( 'agentic', 'relay-key-abc123' );
		Provider_Registry::invalidate();

		$this->assertSame(
			'relay-key-abc123',
			Provider_Registry::get_license_key(),
			'With the option unset, the identity must come from the agentic provider row.'
		);
	}

	/**
	 * A site that already has the option set keeps using it.
	 */
	public function test_explicit_option_overrides_the_provider_row(): void {
		Provider_Registry::save_api_key( 'agentic', 'row-key' );
		Provider_Registry::invalidate();
		update_option( 'agent_builder_license_key', 'explicit-key' );

		$this->assertSame( 'explicit-key', Provider_Registry::get_license_key() );
	}

	/**
	 * No key anywhere means no identity — not a stray empty string from a
	 * half-populated row.
	 */
	public function test_no_key_anywhere_yields_empty(): void {
		$this->assertSame( '', Provider_Registry::get_license_key() );
	}

	/**
	 * The admin-menu gate: a connected site must read as usable.
	 *
	 * This is the half of #136 that produced the signup loop — the request
	 * failure was visible, but this one silently funnelled the user back to
	 * Quick Start no matter how many times they completed setup.
	 */
	public function test_connected_site_counts_as_usable(): void {
		Provider_Registry::save_api_key( 'agentic', 'relay-key-abc123' );
		Provider_Registry::invalidate();
		update_option( 'agent_builder_llm_provider', 'agentic' );

		$this->assertTrue(
			Provider_Registry::has_usable_provider(),
			'A site that completed signup must not be funnelled back to setup.'
		);
	}

	/**
	 * The identity actually reaches the request body sent to the relay.
	 *
	 * Asserts the whole path rather than the resolver alone: the relay rejects
	 * a request whose user_id is empty, which is what a fresh install sent.
	 */
	public function test_identity_is_attached_to_the_hosted_request(): void {
		Provider_Registry::save_api_key( 'agentic', 'relay-key-abc123' );
		Provider_Registry::invalidate();
		update_option( 'agent_builder_llm_provider', 'agentic' );
		update_option( 'agent_builder_model', 'gemini-2.5-flash' );

		$client = new \Agentic\LLM_Client();

		// Private methods are invokable via reflection without setAccessible()
		// since PHP 8.1, where it is a no-op and deprecated as of 8.5.
		$format = new \ReflectionMethod( \Agentic\LLM_Client::class, 'format_request' );

		$body = $format->invoke( $client, array( array( 'role' => 'user', 'content' => 'hello' ) ), array(), false );

		$this->assertSame( 'relay-key-abc123', $body['user_id'] ?? '', 'The relay meters on user_id; an empty one is rejected.' );
		$this->assertNotEmpty( $body['site_url'] ?? '' );
	}
}
