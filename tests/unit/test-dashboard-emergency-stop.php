<?php
/**
 * Unit Tests for the dashboard payload's emergency-stop flag.
 *
 * Regression cover for #220: while Emergency Stop is active, Provider_Registry
 * reads the pre-stop snapshot, so has_configured_provider() keeps returning
 * true and the Quick Actions card stayed on its "online" path — the frontend
 * never showed the stopped state. get_dashboard() now always reports an
 * explicit `emergency_stop` boolean so the frontend can distinguish a stopped
 * (but still-configured) site from a genuinely offline one.
 *
 * @package Agentic\Tests
 */

namespace Agentic\Tests;

use Agentic\Dashboard_REST;
use Agentic\Emergency_Stop;
use Agentic\Provider_Registry;

/**
 * Test case for the dashboard emergency-stop payload.
 */
class Test_Dashboard_Emergency_Stop extends TestCase {

	/**
	 * Clear the stop options and provider identity before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		delete_option( Emergency_Stop::OPTION_ENABLED );
		delete_option( Emergency_Stop::OPTION_SNAPSHOT );
		delete_option( 'agent_builder_license_key' );
		Provider_Registry::save_api_key( 'agentic', '' );
		Provider_Registry::invalidate();
	}

	/**
	 * Leave no stop state behind for other tests.
	 */
	public function tearDown(): void {
		delete_option( Emergency_Stop::OPTION_ENABLED );
		delete_option( Emergency_Stop::OPTION_SNAPSHOT );
		delete_option( 'agent_builder_license_key' );
		Provider_Registry::save_api_key( 'agentic', '' );
		Provider_Registry::invalidate();

		parent::tearDown();
	}

	/**
	 * The dashboard payload exposes the stop as an explicit, true flag when the
	 * emergency stop is active — the signal the frontend uses to show "All
	 * agents are stopped" instead of "online".
	 */
	public function test_dashboard_reports_emergency_stop_when_active(): void {
		update_option( Emergency_Stop::OPTION_ENABLED, '1' );

		$data = Dashboard_REST::get_dashboard()->get_data();

		$this->assertTrue(
			$data['emergency_stop'],
			'get_dashboard() must flag the emergency stop in the payload.'
		);
	}

	/**
	 * With no stop, the flag is explicitly false — not absent, not truthy.
	 */
	public function test_dashboard_reports_emergency_stop_when_inactive(): void {
		update_option( Emergency_Stop::OPTION_ENABLED, '0' );

		$data = Dashboard_REST::get_dashboard()->get_data();

		$this->assertFalse(
			$data['emergency_stop'],
			'get_dashboard() must report the stop as false when it is not active.'
		);
	}

	/**
	 * The actual regression: a stopped site whose snapshot still holds a
	 * provider reads as *configured* while the stop is active. The payload must
	 * carry both is_configured=true and emergency_stop=true, so the frontend can
	 * stop showing "online" during a safety halt.
	 */
	public function test_stopped_site_is_configured_but_flagged(): void {
		Provider_Registry::save_api_key( 'agentic', 'relay-key-abc123' );
		Provider_Registry::invalidate();
		update_option( 'agent_builder_license_key', 'AGNT-TEST-KEY' );

		// Mirror the snapshot enable() persists — the provider captured with its key.
		update_option(
			Emergency_Stop::OPTION_SNAPSHOT,
			array(
				'providers' => array(
					'agentic' => array(
						'slug'          => 'agentic',
						'name'          => 'Agentic',
						'had_key'       => true,
						'encrypted_key' => 'enc:relay-key-abc123',
						'default_model' => '',
						'auth_type'     => 'bearer',
					),
				),
			)
		);
		update_option( Emergency_Stop::OPTION_ENABLED, '1' );

		$data = Dashboard_REST::get_dashboard()->get_data();

		$this->assertTrue(
			$data['is_configured'],
			'A stopped site with a provider in its snapshot is still configured.'
		);
		$this->assertTrue(
			$data['emergency_stop'],
			'The stop must be flagged alongside is_configured so the UI shows the stopped state.'
		);
	}
}
