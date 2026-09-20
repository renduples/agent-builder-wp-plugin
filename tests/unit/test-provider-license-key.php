<?php
/**
 * Unit Tests for the hosted-provider billing identity.
 *
 * Regression cover for #135: on a fresh install `agent_builder_license_key` was
 * never written, so the hosted Agentic provider had no identity to send.
 * Requests were rejected by the relay, and has_usable_provider() decided
 * nothing was configured. The identity is the license key (stored by signup),
 * which the relay meters by — a distinct value from the provider's API key, and
 * the API key must NOT be used as a fallback identity.
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
	 * The identity is the license key, which signup stores in the option — it is
	 * NOT the agentic provider row's API key. The relay meters by the license
	 * key (a distinct value from the API key), so a site that has only an API
	 * key (e.g. an older signup from before #135 stored the license key) has no
	 * usable identity until it reconnects.
	 */
	public function test_api_key_alone_is_not_an_identity(): void {
		$this->assertSame( '', Provider_Registry::get_license_key(), 'precondition: nothing configured' );

		Provider_Registry::save_api_key( 'agentic', 'relay-key-abc123' );
		Provider_Registry::invalidate();

		$this->assertSame(
			'',
			Provider_Registry::get_license_key(),
			'The API key is not the metering identity; without the license-key option there is none.'
		);
	}

	/**
	 * The license key stored in the option is the identity.
	 */
	public function test_identity_comes_from_the_license_option(): void {
		Provider_Registry::save_api_key( 'agentic', 'row-key' );
		Provider_Registry::invalidate();
		update_option( 'agent_builder_license_key', 'AGNT-TEST-KEY' );

		$this->assertSame( 'AGNT-TEST-KEY', Provider_Registry::get_license_key() );
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
		update_option( 'agent_builder_license_key', 'AGNT-TEST-KEY' );
		update_option( 'agent_builder_llm_provider', 'agentic' );

		$this->assertTrue(
			Provider_Registry::has_usable_provider(),
			'A site with both the API key and the license key must not be funnelled back to setup.'
		);
	}

	/**
	 * The other half: an API key but no license key is NOT usable — the site is
	 * sent back to Quick Start to reconnect (which stores the license key).
	 */
	public function test_api_key_without_license_is_not_usable(): void {
		Provider_Registry::save_api_key( 'agentic', 'relay-key-abc123' );
		Provider_Registry::invalidate();
		update_option( 'agent_builder_llm_provider', 'agentic' );

		$this->assertFalse(
			Provider_Registry::has_usable_provider(),
			'Without a license key the hosted provider cannot meter, so it is not usable.'
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
		update_option( 'agent_builder_license_key', 'AGNT-TEST-KEY' );
		update_option( 'agent_builder_llm_provider', 'agentic' );
		update_option( 'agent_builder_model', 'gemini-2.5-flash' );

		$client = new \Agentic\LLM_Client();

		// Private methods are invokable via reflection without setAccessible()
		// since PHP 8.1, where it is a no-op and deprecated as of 8.5.
		$format = new \ReflectionMethod( \Agentic\LLM_Client::class, 'format_request' );

		$body = $format->invoke( $client, array( array( 'role' => 'user', 'content' => 'hello' ) ), array(), false );

		$this->assertSame( 'AGNT-TEST-KEY', $body['user_id'] ?? '', 'The relay meters on the license key, sent as user_id.' );
		$this->assertNotEmpty( $body['site_url'] ?? '' );
	}
}
