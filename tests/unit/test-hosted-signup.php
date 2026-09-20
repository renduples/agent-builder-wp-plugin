<?php
/**
 * Unit tests for the hosted (Agentic AI) signup credential handling.
 *
 * Regression coverage for #135: a fresh install signed up "successfully" but
 * never stored the license key the hosted relay meters by, so the provider
 * was unusable. The register endpoint returns `api_key` and `license_key`;
 * both must be stored, and the failure modes must be reported, not swallowed.
 *
 * @package    Agent_Builder
 * @subpackage Tests
 */

namespace Agentic\Tests;

use Agentic\Admin_Ajax;
use Agentic\Provider_Registry;

/**
 * Test case for Admin_Ajax::persist_hosted_signup() and
 * Provider_Registry::hosted_license_missing().
 */
class Test_Hosted_Signup extends TestCase {

	/**
	 * Make sure the providers table exists, then start from a clean hosted
	 * provider: no API key, no license key, no remembered account email.
	 */
	public function setUp(): void {
		parent::setUp();
		self::ensure_providers_table();
		$this->reset_hosted_state();
	}

	/**
	 * Leave no hosted credentials behind for other tests.
	 */
	public function tearDown(): void {
		$this->reset_hosted_state();
		parent::tearDown();
	}

	/**
	 * Clear the API key, license key and remembered email.
	 */
	private function reset_hosted_state(): void {
		Provider_Registry::invalidate();
		Provider_Registry::save_api_key( 'agentic', '' );
		Provider_Registry::invalidate();
		delete_option( 'agent_builder_license_key' );
		delete_option( 'agent_builder_hosted_account_email' );
	}

	/**
	 * The test bootstrap creates only some plugin tables by hand; create the
	 * providers table if this environment lacks it (same shape as the
	 * activator's). Provider_Registry seeds the built-in rows itself.
	 */
	private static function ensure_providers_table(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'agent_builder_providers';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset_collate = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				slug varchar(100) NOT NULL,
				name varchar(255) NOT NULL DEFAULT '',
				endpoint text,
				default_model varchar(255) NOT NULL DEFAULT '',
				vision_model varchar(255) NOT NULL DEFAULT '',
				auth_type varchar(50) NOT NULL DEFAULT 'bearer',
				req_format varchar(50) NOT NULL DEFAULT 'openai',
				resp_format varchar(50) NOT NULL DEFAULT 'openai',
				requires_key tinyint(1) NOT NULL DEFAULT 1,
				api_key text,
				key_url varchar(2048) NOT NULL DEFAULT '',
				icon text,
				models longtext,
				model_pricing longtext,
				is_builtin tinyint(1) NOT NULL DEFAULT 0,
				sort_order int(11) NOT NULL DEFAULT 99,
				provider_type varchar(32) NOT NULL DEFAULT 'llm',
				created_at datetime DEFAULT CURRENT_TIMESTAMP,
				updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				UNIQUE KEY slug (slug),
				KEY sort_order (sort_order),
				KEY provider_type (provider_type)
			) {$charset_collate};"
		);
		Provider_Registry::invalidate();
	}

	/**
	 * The plaintext API key currently stored for the hosted provider.
	 */
	private function stored_api_key(): string {
		Provider_Registry::invalidate();
		$p = Provider_Registry::get( 'agentic' );
		return (string) ( $p['api_key'] ?? '' );
	}

	/**
	 * A current server answers with both keys: both are stored and the
	 * account email is remembered for reconnects.
	 */
	public function test_stores_api_key_and_license_key(): void {
		$result = Admin_Ajax::persist_hosted_signup(
			array(
				'api_key'     => 'ak_live_123',
				'license_key' => 'lic_456',
			),
			'owner@example.com'
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'ak_live_123', $result['api_key'] );
		$this->assertSame( 'lic_456', $result['license_key'] );
		$this->assertSame( 'ak_live_123', $this->stored_api_key() );
		$this->assertSame( 'lic_456', get_option( 'agent_builder_license_key' ) );
		$this->assertSame( 'owner@example.com', get_option( 'agent_builder_hosted_account_email' ) );
		$this->assertFalse( Provider_Registry::hosted_license_missing() );
	}

	/**
	 * Servers older than marketplace 2.9.38 returned only license_key and
	 * expected it to double as the API key. That still works, and the license
	 * key is stored as well.
	 */
	public function test_legacy_license_only_response_is_used_for_both(): void {
		$result = Admin_Ajax::persist_hosted_signup( array( 'license_key' => 'lic_only' ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'lic_only', $this->stored_api_key() );
		$this->assertSame( 'lic_only', get_option( 'agent_builder_license_key' ) );
		$this->assertTrue( Provider_Registry::has_usable_provider() );
	}

	/**
	 * An API key without a license key is saved (so a retry can re-resolve
	 * the license) but reported as an error, and the registry flags the state.
	 */
	public function test_api_key_without_license_is_reported_not_swallowed(): void {
		$result = Admin_Ajax::persist_hosted_signup( array( 'api_key' => 'ak_only' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'agentic_license_key_missing', $result->get_error_code() );
		$this->assertSame( 'ak_only', $this->stored_api_key() );
		$this->assertSame( '', (string) get_option( 'agent_builder_license_key', '' ) );
		$this->assertTrue( Provider_Registry::hosted_license_missing() );
		$this->assertFalse( Provider_Registry::has_usable_provider() );
	}

	/**
	 * No key at all: nothing is stored and the server's message is surfaced.
	 */
	public function test_empty_response_returns_server_message(): void {
		$result = Admin_Ajax::persist_hosted_signup( array( 'message' => 'Email already registered with another site.' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'agentic_no_api_key', $result->get_error_code() );
		$this->assertSame( 'Email already registered with another site.', $result->get_error_message() );
		$this->assertSame( '', $this->stored_api_key() );
		$this->assertFalse( Provider_Registry::hosted_license_missing() );
	}

	/**
	 * A later response without a license key must not wipe one already stored.
	 */
	public function test_existing_license_key_is_not_cleared_by_a_keyless_response(): void {
		update_option( 'agent_builder_license_key', 'lic_existing' );

		$result = Admin_Ajax::persist_hosted_signup( array( 'api_key' => 'ak_new' ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'ak_new', $this->stored_api_key() );
		$this->assertSame( 'lic_existing', get_option( 'agent_builder_license_key' ) );
	}

	/**
	 * hosted_license_missing() is false for a site that never connected at all.
	 */
	public function test_license_missing_is_false_without_an_api_key(): void {
		$this->assertFalse( Provider_Registry::hosted_license_missing() );
	}
}
