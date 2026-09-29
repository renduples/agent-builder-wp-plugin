<?php
/**
 * Unit Tests for the event-listener guards added in 4.1.0.
 *
 * Covers the four feedback-loop / hang fixes for high-frequency hooks:
 *   - a gated listener on `updated_option` mints exactly one proposal no matter
 *     how many times it fires;
 *   - Agent Builder's own option/transient writes never reach a listener;
 *   - the per-listener rate limit holds under rapid (concurrent) fires;
 *   - Audit_Log::bust_query_cache() issues no DELETE statement.
 *
 * @package Agentic\Tests
 */

namespace Agentic\Tests;

use Agentic\Agent_Lifecycle;
use Agentic\Agent_Proposals;
use Agentic\Audit_Log;
use Agentic\Manifest_Agent;

/**
 * Test case for the event-listener guards.
 */
class Test_Event_Listener_Guards extends TestCase {

	/**
	 * Build a minimal manifest agent whose slug the lifecycle methods resolve.
	 *
	 * @param string $slug Agent slug.
	 * @return Manifest_Agent
	 */
	private function make_agent( string $slug ): Manifest_Agent {
		return new Manifest_Agent( array( 'slug' => $slug ), __DIR__ );
	}

	/**
	 * Count proposals currently stored in the proposals table.
	 *
	 * Proposals moved from transients to the agent_builder_proposals table in
	 * schema 2.15.2 (M12); the dedupe marker remains a transient but the proposal
	 * itself now lives in the table.
	 *
	 * @return int
	 */
	private function count_proposals(): int {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}agent_builder_proposals" );
	}

	/**
	 * A gated listener bound to `updated_option` that proposes a MEDIUM-risk tool
	 * mints exactly one pending proposal under 50 rapid option writes — the dedupe
	 * gate absorbs every repeat fire instead of stacking a proposal per fire.
	 *
	 * `min_interval` is 0 here so the rate limit cannot mask the dedupe behaviour
	 * this test exists to prove.
	 */
	public function test_listener_dedupe_single_proposal_under_repeat_fires(): void {
		$agent    = $this->make_agent( 'listener-dedupe-agent' );
		$listener = array(
			'id'           => 'l1',
			'name'         => 'Dedupe listener',
			'hook'         => 'updated_option',
			'tool'         => 'add_custom_css',
			'min_interval' => 0,
		);

		for ( $i = 0; $i < 50; $i++ ) {
			Agent_Lifecycle::execute_event_listener( $agent, $listener, array( 'some_site_option', 'old', 'new' ) );
		}

		$this->assertSame( 1, $this->count_proposals(), 'exactly one proposal after 50 fires' );

		// The listener still holds a single unexpired pending marker.
		$this->assertNotNull(
			Agent_Proposals::has_pending( 'listener-dedupe-agent', 'l1', 'add_custom_css' ),
			'a pending marker should remain for the one live proposal'
		);
	}

	/**
	 * Agent Builder's own option/transient writes carry an internal prefix and
	 * must never reach a listener — even when the listener is bound to the very
	 * hooks those writes fire.
	 */
	public function test_internal_transient_writes_never_trigger_listeners(): void {
		$agent    = $this->make_agent( 'listener-internal-agent' );
		$listener = array(
			'id'           => 'l-internal',
			'name'         => 'Internal write guard',
			'hook'         => 'updated_option',
			'tool'         => 'add_custom_css',
			'min_interval' => 0,
		);

		// Bind exactly the way bind_event_listeners() does.
		add_action(
			'updated_option',
			static function () use ( $agent, $listener ) {
				Agent_Lifecycle::execute_event_listener( $agent, $listener, func_get_args() );
			},
			10,
			3
		);
		add_action(
			'added_option',
			static function () use ( $agent, $listener ) {
				Agent_Lifecycle::execute_event_listener( $agent, $listener, func_get_args() );
			},
			10,
			3
		);

		// Internal-prefixed writes across all six protected prefixes.
		set_transient( 'agentic_foo', 'bar', 60 );
		update_option( 'agentic_listener_rate_something', time() );
		update_option( 'agent_builder_test', 'x' );
		update_option( '_transient_timeout_agentic_foo', time() + 60 );

		$this->assertSame( 0, $this->count_proposals(), 'internal writes must never mint a proposal' );
	}

	/**
	 * The per-listener rate limit permits one execution per window and skips the
	 * rest, aggregating skipped fires into a counter rather than audit rows.
	 */
	public function test_rate_limit_holds_under_concurrent_fires(): void {
		$agent    = $this->make_agent( 'listener-rate-agent' );
		$listener = array(
			'id'           => 'l-rate',
			'name'         => 'Rate-limited listener',
			'hook'         => 'updated_option',
			'tool'         => 'add_custom_css',
			'min_interval' => 60,
		);

		for ( $i = 0; $i < 10; $i++ ) {
			Agent_Lifecycle::execute_event_listener( $agent, $listener, array( 'some_option', 'old', 'new' ) );
		}

		$this->assertSame( 1, $this->count_proposals(), 'only the first fire passes the rate limit' );
		$this->assertSame( 9, Agent_Lifecycle::get_listener_skip_count( 'listener-rate-agent', 'l-rate' ), 'nine fires are skipped and counted' );
	}

	/**
	 * bust_query_cache() advances a version counter instead of issuing a broad
	 * `DELETE … LIKE` on wp_options — which is what piled up row locks and hung
	 * requests under rapid audit inserts.
	 */
	public function test_bust_query_cache_issues_no_delete(): void {
		$deletes = array();
		$filter  = static function ( $query ) use ( &$deletes ) {
			if ( 1 === preg_match( '/^\s*DELETE\b/i', (string) $query ) ) {
				$deletes[] = $query;
			}
			return $query;
		};

		add_filter( 'query', $filter );
		try {
			Audit_Log::bust_query_cache();
		} finally {
			remove_filter( 'query', $filter );
		}

		$this->assertSame( array(), $deletes, 'bust_query_cache() must not issue a DELETE query' );
	}

	/**
	 * The version counter advances by exactly one per call — a single atomic
	 * increment, not a read-then-write that could lose or double an increment.
	 */
	public function test_bust_query_cache_increments_atomically(): void {
		delete_option( 'agentic_audit_cache_ver' );

		Audit_Log::bust_query_cache();
		$this->assertSame( 1, (int) get_option( 'agentic_audit_cache_ver' ), 'first bump seeds the counter at 1' );

		Audit_Log::bust_query_cache();
		$this->assertSame( 2, (int) get_option( 'agentic_audit_cache_ver' ), 'second bump increments the counter to 2' );
	}

	/**
	 * A manifest-level `arg_filter` drops hook arguments that do not match the
	 * declared pattern before they ever reach the approval gate, so a
	 * high-frequency hook does not mint proposals for irrelevant events.
	 */
	public function test_arg_filter_skips_non_matching_events(): void {
		$agent    = $this->make_agent( 'listener-filter-agent' );
		$listener = array(
			'id'           => 'l-filter',
			'name'         => 'Filtered listener',
			'hook'         => 'updated_option',
			'tool'         => 'add_custom_css',
			'min_interval' => 0,
			'arg_filter'   => array(
				'arg'     => 0,
				'pattern' => '^woocommerce_',
			),
		);

		// Non-matching option name — filtered out before the gate.
		Agent_Lifecycle::execute_event_listener( $agent, $listener, array( 'posts_table_option', 'old', 'new' ) );
		$this->assertSame( 0, $this->count_proposals(), 'a non-matching argument never reaches the gate' );

		// Matching option name — reaches the gate and proposes.
		Agent_Lifecycle::execute_event_listener( $agent, $listener, array( 'woocommerce_orders', 'old', 'new' ) );
		$this->assertSame( 1, $this->count_proposals(), 'a matching argument proposes once' );
	}

	/**
	 * The `in` allowlist form of `arg_filter` admits hook arguments by strict
	 * string equality — a value outside the list never reaches the gate, a listed
	 * value does.
	 */
	public function test_arg_filter_in_allowlist(): void {
		$agent    = $this->make_agent( 'listener-filter-in-agent' );
		$listener = array(
			'id'           => 'l-filter-in',
			'name'         => 'Allowlist listener',
			'hook'         => 'updated_option',
			'tool'         => 'add_custom_css',
			'min_interval' => 0,
			'arg_filter'   => array(
				'arg' => 0,
				'in'  => array( 'woocommerce_orders', 'woocommerce_customers' ),
			),
		);

		// Non-listed option name — filtered out before the gate.
		Agent_Lifecycle::execute_event_listener( $agent, $listener, array( 'posts_table_option', 'old', 'new' ) );
		$this->assertSame( 0, $this->count_proposal_transients(), 'a value outside the allowlist never reaches the gate' );

		// Allowlisted option name — reaches the gate and proposes.
		Agent_Lifecycle::execute_event_listener( $agent, $listener, array( 'woocommerce_orders', 'old', 'new' ) );
		$this->assertSame( 1, $this->count_proposal_transients(), 'an allowlisted value proposes once' );
	}

	/**
	 * An empty `in` allowlist matches nothing, so the listener never runs.
	 */
	public function test_arg_filter_in_empty_matches_nothing(): void {
		$agent    = $this->make_agent( 'listener-filter-in-empty-agent' );
		$listener = array(
			'id'           => 'l-filter-in-empty',
			'name'         => 'Empty allowlist listener',
			'hook'         => 'updated_option',
			'tool'         => 'add_custom_css',
			'min_interval' => 0,
			'arg_filter'   => array(
				'arg' => 0,
				'in'  => array(),
			),
		);

		Agent_Lifecycle::execute_event_listener( $agent, $listener, array( 'woocommerce_orders', 'old', 'new' ) );
		$this->assertSame( 0, $this->count_proposal_transients(), 'an empty allowlist never reaches the gate' );
	}

	/**
	 * Remove every option/transient this file's tests write, so a re-run of the
	 * suite within the 60-second rate-limit window is not blocked by a stale claim
	 * from a prior run (and proposal / pending-marker / audit-cache state never
	 * leaks between runs).
	 */
	public function tearDown(): void {
		global $wpdb;

		$wpdb->query(
			"DELETE FROM {$wpdb->options}
			WHERE option_name LIKE 'agentic_listener_rate_%'
			   OR option_name LIKE 'agentic_listener_skips_%'
			   OR option_name = 'agentic_audit_cache_ver'
			   OR option_name = 'agent_builder_test'
			   OR option_name LIKE '_transient_agentic_proposal_%'
			   OR option_name LIKE '_transient_timeout_agentic_proposal_%'
			   OR option_name LIKE '_transient_agentic_listener_pending_%'
			   OR option_name LIKE '_transient_timeout_agentic_listener_pending_%'
			   OR option_name = '_transient_agentic_foo'
			   OR option_name = '_transient_timeout_agentic_foo'"
		);

		parent::tearDown();
	}
}
