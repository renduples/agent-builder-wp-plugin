<?php
/**
 * Unit Tests for Risk_Level.
 *
 * Covers the mode/risk enforcement matrix and the BASELINE_RISKS floor that
 * keeps dangerous tools from resolving to 'none'. Every tool name asserted
 * against below was verified to exist under library/tools/ in this repo at
 * the time this test was written — see BASELINE_RISKS in
 * includes/class-risk-level.php for the authoritative floor map.
 *
 * @package Agentic\Tests
 */

namespace Agentic\Tests;

use Agentic\Risk_Level;

/**
 * Test case for Risk_Level.
 */
class Test_Risk_Level extends TestCase {

	/**
	 * Drop the memoised registry so each test reads fresh option/table state.
	 */
	public function setUp(): void {
		parent::setUp();
		Risk_Level::bust_cache();
	}

	// ─── Enforcement matrix ──────────────────────────────────────────────────

	/**
	 * Extreme-risk tools are blocked in every mode, including autonomous.
	 */
	public function test_extreme_is_blocked_in_every_mode(): void {
		foreach ( array( 'disabled', 'supervised', 'autonomous' ) as $mode ) {
			$this->assertSame( 'block', Risk_Level::enforcement( Risk_Level::EXTREME, $mode ) );
		}
	}

	/**
	 * Disabled mode blocks everything, down to zero-risk tools.
	 */
	public function test_disabled_mode_blocks_everything(): void {
		foreach ( Risk_Level::ALL as $risk ) {
			$this->assertSame( 'block', Risk_Level::enforcement( $risk, 'disabled' ) );
		}
	}

	/**
	 * Supervised mode runs only zero-risk tools without asking.
	 */
	public function test_supervised_allows_only_none(): void {
		$this->assertSame( 'allow', Risk_Level::enforcement( Risk_Level::NONE, 'supervised' ) );
		$this->assertSame( 'confirm', Risk_Level::enforcement( Risk_Level::LOW, 'supervised' ) );
		$this->assertSame( 'confirm', Risk_Level::enforcement( Risk_Level::MEDIUM, 'supervised' ) );
		$this->assertSame( 'queue', Risk_Level::enforcement( Risk_Level::HIGH, 'supervised' ) );
	}

	/**
	 * Autonomous mode adds low-risk tools, and still queues high-risk ones.
	 */
	public function test_autonomous_allows_up_to_low_and_queues_high(): void {
		$this->assertSame( 'allow', Risk_Level::enforcement( Risk_Level::NONE, 'autonomous' ) );
		$this->assertSame( 'allow', Risk_Level::enforcement( Risk_Level::LOW, 'autonomous' ) );
		$this->assertSame( 'confirm', Risk_Level::enforcement( Risk_Level::MEDIUM, 'autonomous' ) );
		$this->assertSame( 'queue', Risk_Level::enforcement( Risk_Level::HIGH, 'autonomous' ) );
	}

	/**
	 * A site preference (Approvals → Preferences "auto-approve up to") can
	 * raise the auto-allow ceiling, but never past extreme, and never below
	 * whatever the mode already grants.
	 */
	public function test_site_auto_max_preference_raises_ceiling(): void {
		update_option( 'agent_builder_approval_auto_max_risk', Risk_Level::MEDIUM );

		// Supervised normally confirms MEDIUM; the site preference now allows it.
		$this->assertSame( 'allow', Risk_Level::enforcement( Risk_Level::MEDIUM, 'supervised' ) );
		// HIGH is still queued regardless of the preference.
		$this->assertSame( 'queue', Risk_Level::enforcement( Risk_Level::HIGH, 'supervised' ) );

		delete_option( 'agent_builder_approval_auto_max_risk' );
	}

	/**
	 * An invalid or extreme site preference is ignored, not treated as a
	 * blanket auto-allow.
	 */
	public function test_invalid_auto_max_preference_is_ignored(): void {
		update_option( 'agent_builder_approval_auto_max_risk', Risk_Level::EXTREME );
		$this->assertSame( 'confirm', Risk_Level::enforcement( Risk_Level::MEDIUM, 'supervised' ) );

		update_option( 'agent_builder_approval_auto_max_risk', 'not-a-real-risk-level' );
		$this->assertSame( 'confirm', Risk_Level::enforcement( Risk_Level::MEDIUM, 'supervised' ) );

		delete_option( 'agent_builder_approval_auto_max_risk' );
	}

	// ─── max() / weight() ────────────────────────────────────────────────────

	/**
	 * max() always returns the higher of the two risk levels, in either order.
	 */
	public function test_max_returns_higher_risk_level(): void {
		$this->assertSame( Risk_Level::HIGH, Risk_Level::max( Risk_Level::HIGH, Risk_Level::LOW ) );
		$this->assertSame( Risk_Level::HIGH, Risk_Level::max( Risk_Level::LOW, Risk_Level::HIGH ) );
		$this->assertSame( Risk_Level::MEDIUM, Risk_Level::max( Risk_Level::MEDIUM, Risk_Level::MEDIUM ) );
	}

	/**
	 * weight() orders every level strictly ascending, none/low/medium/high/extreme.
	 */
	public function test_weight_is_strictly_ascending(): void {
		$weights = array_map( array( Risk_Level::class, 'weight' ), Risk_Level::ALL );
		$sorted  = $weights;
		sort( $sorted );
		$this->assertSame( $sorted, $weights );
		$this->assertSame( array( 0, 1, 2, 3, 4 ), $weights );
	}

	// ─── mode_ceiling() ──────────────────────────────────────────────────────

	/**
	 * mode_ceiling() reports the documented ceiling for each mode, including
	 * an unrecognised mode falling back to the safest (none) ceiling.
	 */
	public function test_mode_ceiling_values(): void {
		$this->assertSame( Risk_Level::LOW, Risk_Level::mode_ceiling( 'autonomous' ) );
		$this->assertSame( Risk_Level::NONE, Risk_Level::mode_ceiling( 'supervised' ) );
		$this->assertSame( Risk_Level::NONE, Risk_Level::mode_ceiling( 'disabled' ) );
		$this->assertSame( Risk_Level::NONE, Risk_Level::mode_ceiling( 'no-such-mode' ) );
	}

	// ─── BASELINE_RISKS floor ────────────────────────────────────────────────

	/**
	 * Tools that install code, mutate the codebase, move money, reset
	 * credentials, or tear down a security control must never resolve to a
	 * risk that executes silently — this is exactly the bug class the #116
	 * risk-gate audit found and fixed via BASELINE_RISKS.
	 *
	 * @dataProvider high_risk_tools
	 * @param string $tool Tool slug.
	 */
	public function test_dangerous_tools_are_never_silently_allowed( string $tool ): void {
		$risk = Risk_Level::get_tool_default( $tool );

		$this->assertNotSame( Risk_Level::NONE, $risk, "{$tool} must not default to none" );
		$this->assertSame(
			'queue',
			Risk_Level::enforcement( $risk, 'autonomous' ),
			"{$tool} must be queued for approval even in autonomous mode"
		);
	}

	/**
	 * Tools whose baseline is HIGH (verified present under library/tools/).
	 *
	 * @return array<string, string[]>
	 */
	public static function high_risk_tools(): array {
		return array(
			'injects front-end JS'          => array( 'add_custom_js' ),
			'resets user credentials'       => array( 'force_password_reset' ),
			'issues a refund'               => array( 'wc_create_refund' ),
			'destroys a form and entries'   => array( 'delete_form' ),
			'generic option writer'         => array( 'db_update_option' ),
			'rewrites file-edit protection' => array( 'toggle_file_editing' ),
			'force-deletes a post'          => array( 'db_delete_post' ),
		);
	}

	/**
	 * Medium-baseline tools prompt for confirmation rather than running silently.
	 *
	 * @dataProvider medium_risk_tools
	 * @param string $tool Tool slug.
	 */
	public function test_medium_baseline_tools_require_confirmation( string $tool ): void {
		$risk = Risk_Level::get_tool_default( $tool );

		$this->assertSame( 'confirm', Risk_Level::enforcement( $risk, 'autonomous' ), "{$tool} must ask first" );
	}

	/**
	 * Tools whose baseline is MEDIUM (verified present under library/tools/).
	 *
	 * @return array<string, string[]>
	 */
	public static function medium_risk_tools(): array {
		return array(
			'injects front-end CSS'  => array( 'add_custom_css' ),
			'bulk-deletes revisions' => array( 'cleanup_post_revisions' ),
			'writes post content'    => array( 'create_post_content' ),
		);
	}

	/**
	 * The floor only ever raises risk — a registry value above it still wins.
	 *
	 * add_custom_css has a MEDIUM baseline. An admin who has escalated it to
	 * EXTREME in the registry must not see it pulled back down to MEDIUM.
	 */
	public function test_baseline_is_a_floor_not_a_ceiling(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'agent_builder_tools';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (name, description, category, source, enabled, risk_level, parameters, created_at, updated_at)
				 VALUES (%s, '', 'test', 'core', 1, %s, '{}', NOW(), NOW())
				 ON DUPLICATE KEY UPDATE risk_level = VALUES(risk_level)",
				'add_custom_css',
				Risk_Level::EXTREME
			)
		);
		Risk_Level::bust_cache();

		$this->assertSame( Risk_Level::EXTREME, Risk_Level::get_tool_default( 'add_custom_css' ) );
	}

	/**
	 * Read-only tools are untouched by the floor and still run without friction.
	 *
	 * Guards against a blanket "anything not marked readonly is risky" rule,
	 * which would bury ordinary lookups behind confirmation prompts.
	 */
	public function test_read_only_tools_still_run_silently(): void {
		foreach ( array( 'list_posts', 'get_post_content', 'get_site_overview' ) as $tool ) {
			$risk = Risk_Level::get_tool_default( $tool );
			$this->assertSame( 'allow', Risk_Level::enforcement( $risk, 'supervised' ), "{$tool} regressed" );
		}
	}

	/**
	 * An unknown tool carries no baseline and resolves to none.
	 */
	public function test_unknown_tool_resolves_to_none(): void {
		$this->assertSame( Risk_Level::NONE, Risk_Level::get_tool_default( 'no_such_tool_xyz' ) );
	}

	/**
	 * is_valid() only accepts the five declared constants.
	 */
	public function test_is_valid_rejects_unknown_strings(): void {
		foreach ( Risk_Level::ALL as $risk ) {
			$this->assertTrue( Risk_Level::is_valid( $risk ) );
		}
		$this->assertFalse( Risk_Level::is_valid( 'critical' ) );
		$this->assertFalse( Risk_Level::is_valid( '' ) );
	}

	/**
	 * get_baseline_risks() exposes the same map get_tool_default() floors
	 * against — used by the Safety Center admin screen.
	 */
	public function test_get_baseline_risks_matches_get_tool_default(): void {
		$baseline = Risk_Level::get_baseline_risks();
		$this->assertArrayHasKey( 'force_password_reset', $baseline );
		$this->assertSame( Risk_Level::HIGH, $baseline['force_password_reset'] );
		$this->assertSame(
			Risk_Level::get_tool_default( 'force_password_reset' ),
			Risk_Level::max( Risk_Level::NONE, $baseline['force_password_reset'] )
		);
	}
}
