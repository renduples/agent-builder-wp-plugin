<?php
/**
 * Unit tests for Provider_Registry::upsert() API-key preservation.
 *
 * The Providers settings form never echoes the stored (decrypted) API key back
 * into the password input, so every re-save of an existing provider submits an
 * empty `provider_api_key`. upsert() must treat that blank value as "leave the
 * stored key unchanged" — otherwise editing any other field would silently wipe
 * the key.
 *
 * @package Agentic\Tests
 */

namespace Agentic\Tests;

use Agentic\Provider_Registry;

/**
 * Test case for blank-key handling in Provider_Registry::upsert().
 */
class Test_Provider_Upsert_Blank_Key extends TestCase {

	/**
	 * Start every test without a stored key.
	 */
	public function setUp(): void {
		parent::setUp();

		Provider_Registry::save_api_key( 'openai', '' );
		Provider_Registry::invalidate();
	}

	/**
	 * Leave no key behind for other tests.
	 */
	public function tearDown(): void {
		Provider_Registry::save_api_key( 'openai', '' );
		Provider_Registry::invalidate();

		parent::tearDown();
	}

	/**
	 * Re-saving the provider form with an empty key field keeps the stored key.
	 */
	public function test_blank_api_key_on_resave_keeps_existing_key(): void {
		Provider_Registry::upsert( array( 'slug' => 'openai', 'api_key' => 'sk-test-keep-me' ) );
		Provider_Registry::invalidate();
		$this->assertSame( 'sk-test-keep-me', Provider_Registry::get( 'openai' )['api_key'] ?? null, 'precondition: key stored' );

		// What the Providers form posts when the admin leaves the key input blank.
		Provider_Registry::upsert( array( 'slug' => 'openai', 'api_key' => '' ) );
		Provider_Registry::invalidate();

		$this->assertSame( 'sk-test-keep-me', Provider_Registry::get( 'openai' )['api_key'] ?? null );
	}

	/**
	 * A non-empty value still replaces the stored key.
	 */
	public function test_new_api_key_replaces_existing_key(): void {
		Provider_Registry::upsert( array( 'slug' => 'openai', 'api_key' => 'sk-test-old' ) );
		Provider_Registry::invalidate();

		Provider_Registry::upsert( array( 'slug' => 'openai', 'api_key' => 'sk-test-new' ) );
		Provider_Registry::invalidate();

		$this->assertSame( 'sk-test-new', Provider_Registry::get( 'openai' )['api_key'] ?? null );
	}
}
