<?php
/**
 * Unit Tests for Agent_Proposals.
 *
 * Covers the create → get/approve/reject state transitions now backed by the
 * wp_agent_builder_proposals table (schema 2.15.2), the filterable pending()
 * listing, the two expiry windows (1h chat / 7d run), and expiry-on-read.
 *
 * @package Agentic\Tests
 */

namespace Agentic\Tests;

use Agentic\Agent_Proposals;
use Agentic\Approval_Queue;
use Agentic\Risk_Level;

/**
 * Test case for Agent_Proposals.
 */
class Test_Agent_Proposals extends TestCase {

	/**
	 * create() persists a pending proposal that get() reads back with its
	 * params round-tripped through JSON storage.
	 */
	public function test_create_and_get_round_trips_pending_proposal(): void {
		$proposal = Agent_Proposals::create(
			'list_posts',
			array( 'post_type' => 'post', 'numberposts' => 5 ),
			'wordpress-assistant',
			'List some posts',
			"--- a\n+++ b\n"
		);

		$this->assertNotEmpty( $proposal['id'] );

		$read = Agent_Proposals::get( $proposal['id'] );
		$this->assertIsArray( $read );
		$this->assertSame( 'list_posts', $read['tool'] );
		$this->assertSame( 'wordpress-assistant', $read['agent_id'] );
		$this->assertSame( 'List some posts', $read['description'] );
		$this->assertSame( 'pending', $read['status'] );
		// params round-trips through wp_json_encode/json_decode.
		$this->assertSame( array( 'post_type' => 'post', 'numberposts' => 5 ), $read['params'] );
	}

	/**
	 * get() returns null for an unknown id.
	 */
	public function test_get_returns_null_for_unknown_proposal(): void {
		$this->assertNull( Agent_Proposals::get( 'does-not-exist' ) );
	}

	/**
	 * A chat-originated proposal (no run_id) expires in one hour.
	 */
	public function test_chat_proposal_expires_in_one_hour(): void {
		$proposal = Agent_Proposals::create( 'list_posts', array(), 'wordpress-assistant', 'Chat proposal' );

		$expires = strtotime( (string) $proposal['expires_at'] );
		$expected = time() + 3600;

		$this->assertGreaterThanOrEqual( $expected - 5, $expires );
		$this->assertLessThanOrEqual( $expected + 5, $expires );
	}

	/**
	 * A run-backed proposal (run_id set) expires in seven days.
	 */
	public function test_run_proposal_expires_in_seven_days(): void {
		$proposal = Agent_Proposals::create( 'list_posts', array(), 'wordpress-assistant', 'Run proposal', '', 'run-123' );

		$expires = strtotime( (string) $proposal['expires_at'] );
		$expected = time() + ( 7 * 24 * 3600 );

		$this->assertGreaterThanOrEqual( $expected - 5, $expires );
		$this->assertLessThanOrEqual( $expected + 5, $expires );
		$this->assertSame( 'run-123', $proposal['run_id'] );
	}

	/**
	 * reject() flips status to rejected and stamps decision/decided_at, and a
	 * second reject() is refused as already-processed.
	 */
	public function test_reject_transitions_pending_to_rejected(): void {
		$proposal = Agent_Proposals::create( 'list_posts', array(), 'wordpress-assistant', 'Reject me' );

		$result = Agent_Proposals::reject( $proposal['id'] );
		$this->assertTrue( $result['success'] );

		$row = $this->get_proposal_row( $proposal['id'] );
		$this->assertSame( 'rejected', $row['status'] );
		$this->assertSame( 'rejected', $row['decision'] );
		$this->assertNotNull( $row['decided_at'] );

		$again = Agent_Proposals::reject( $proposal['id'] );
		$this->assertArrayHasKey( 'error', $again );
	}

	/**
	 * reject() on a missing proposal returns the not-found error.
	 */
	public function test_reject_missing_proposal_returns_error(): void {
		$result = Agent_Proposals::reject( 'does-not-exist' );
		$this->assertArrayHasKey( 'error', $result );
	}

	/**
	 * approve() flips status to approved and stamps the decision before it
	 * returns the tool execution result.
	 */
	public function test_approve_transitions_pending_to_approved(): void {
		$proposal = Agent_Proposals::create( 'list_posts', array(), 'wordpress-assistant', 'Approve me' );

		$result = Agent_Proposals::approve( $proposal['id'] );
		$this->assertIsArray( $result );

		$row = $this->get_proposal_row( $proposal['id'] );
		$this->assertSame( 'approved', $row['status'] );
		$this->assertSame( 'approved', $row['decision'] );
		$this->assertNotNull( $row['decided_at'] );
	}

	/**
	 * approve() routes execution through Tool_Executor::execute_approved(): the
	 * tool actually runs, agent_builder_tool_executed fires exactly once, and a
	 * non-readonly tool is written to the operations ledger with its computed
	 * (high) risk — not the hardcoded 'medium' the pre-M12 path used.
	 */
	public function test_approve_routes_through_execute_approved_and_logs_actual_risk(): void {
		$proposal = Agent_Proposals::create(
			'db_update_option',
			array( 'name' => 'agent_builder_test_approve_opt', 'value' => 'approved-via-proposal' ),
			'wordpress-assistant',
			'Approve the write'
		);

		$calls    = 0;
		$listener = static function () use ( &$calls ) {
			++$calls;
		};
		add_action( 'agent_builder_tool_executed', $listener, 10, 4 );

		$result = Agent_Proposals::approve( $proposal['id'] );

		remove_action( 'agent_builder_tool_executed', $listener, 10 );

		$this->assertSame( 'approved-via-proposal', get_option( 'agent_builder_test_approve_opt' ) );
		$this->assertTrue( $result['updated'] ?? false );
		$this->assertSame( 1, $calls, 'execute_approved() must raise the executed hook once' );

		$queue  = new Approval_Queue();
		$recent = $queue->get_recent( array( 'agent_id' => 'wordpress-assistant' ) );
		$rows   = array_values(
			array_filter(
				$recent,
				static fn( $r ) => 'db_update_option' === $r['action']
			)
		);
		$this->assertCount( 1, $rows, 'exactly one operations-ledger entry, not a double log' );
		$this->assertSame( Risk_Level::HIGH, $rows[0]['risk_level'] );
	}

	/**
	 * pending() returns only non-expired pending proposals and excludes
	 * decided (approved/rejected) and lapsed rows.
	 */
	public function test_pending_returns_only_non_expired_pending(): void {
		$live   = Agent_Proposals::create( 'list_posts', array(), 'wordpress-assistant', 'Live' );
		$expired = Agent_Proposals::create( 'list_posts', array(), 'wordpress-assistant', 'Expired' );
		$decided = Agent_Proposals::create( 'list_posts', array(), 'wordpress-assistant', 'Decided' );

		// Backdate one into the past and decide another.
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'agent_builder_proposals',
			array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ),
			array( 'id' => $expired['id'] )
		);
		Agent_Proposals::reject( $decided['id'] );

		$pending = Agent_Proposals::pending();
		$ids     = wp_list_pluck( $pending, 'id' );

		$this->assertContains( $live['id'], $ids );
		$this->assertNotContains( $expired['id'], $ids );
		$this->assertNotContains( $decided['id'], $ids );
	}

	/**
	 * pending() filters by agent_id, run_id, and created_by.
	 */
	public function test_pending_filters_by_agent_run_and_created_by(): void {
		Agent_Proposals::create( 'list_posts', array(), 'agent-a', 'A1', '', 'run-1', 7 );
		Agent_Proposals::create( 'list_posts', array(), 'agent-b', 'B1', '', 'run-2', 8 );
		Agent_Proposals::create( 'list_posts', array(), 'agent-a', 'A2', '', 'run-3', 9 );

		$by_agent = Agent_Proposals::pending( array( 'agent_id' => 'agent-a' ) );
		$this->assertCount( 2, $by_agent );

		$by_run = Agent_Proposals::pending( array( 'run_id' => 'run-2' ) );
		$this->assertCount( 1, $by_run );
		$this->assertSame( 'agent-b', $by_run[0]['agent_id'] );

		$by_created = Agent_Proposals::pending( array( 'created_by' => 9 ) );
		$this->assertCount( 1, $by_created );
		$this->assertSame( 'agent-a', $by_created[0]['agent_id'] );
		$this->assertSame( 9, $by_created[0]['created_by'] );
	}

	/**
	 * pending() lists newest first.
	 */
	public function test_pending_orders_newest_first(): void {
		$older = Agent_Proposals::create( 'list_posts', array(), 'wordpress-assistant', 'Older' );
		$newer = Agent_Proposals::create( 'list_posts', array(), 'wordpress-assistant', 'Newer' );

		// Backdate the first so the ordering is deterministic.
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'agent_builder_proposals',
			array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ),
			array( 'id' => $older['id'] )
		);

		$pending = Agent_Proposals::pending();
		$this->assertSame( $newer['id'], $pending[0]['id'] );
		$this->assertSame( $older['id'], $pending[1]['id'] );
	}

	/**
	 * Expiry-on-read: a still-pending proposal whose expires_at has lapsed is
	 * treated as gone by get(), without waiting for the cleanup cron.
	 */
	public function test_get_returns_null_for_expired_pending_proposal(): void {
		$proposal = Agent_Proposals::create( 'list_posts', array(), 'wordpress-assistant', 'About to expire' );

		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'agent_builder_proposals',
			array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ),
			array( 'id' => $proposal['id'] )
		);

		$this->assertNull( Agent_Proposals::get( $proposal['id'] ) );
	}

	/**
	 * cleanup_expired() marks a stale pending proposal 'expired' (retaining
	 * the row) and returns the number of rows it flipped.
	 */
	public function test_cleanup_expired_marks_stale_pending_as_expired(): void {
		$proposal = Agent_Proposals::create( 'list_posts', array(), 'wordpress-assistant', 'Stale' );

		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'agent_builder_proposals',
			array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ),
			array( 'id' => $proposal['id'] )
		);

		$flipped = Agent_Proposals::cleanup_expired();

		$this->assertSame( 1, $flipped );
		$row = $this->get_proposal_row( $proposal['id'] );
		$this->assertSame( 'expired', $row['status'] );
	}

	/**
	 * cleanup_expired() leaves an unexpired pending proposal alone.
	 */
	public function test_cleanup_expired_leaves_unexpired_pending_alone(): void {
		$proposal = Agent_Proposals::create( 'list_posts', array(), 'wordpress-assistant', 'Fresh' );

		$flipped = Agent_Proposals::cleanup_expired();

		$this->assertSame( 0, $flipped );
		$row = $this->get_proposal_row( $proposal['id'] );
		$this->assertSame( 'pending', $row['status'] );
	}

	/**
	 * cleanup_expired() compares expires_at against UTC regardless of the MySQL
	 * session timezone. A chat proposal expires in one hour, so with the session
	 * timezone set ahead of UTC a fresh proposal would satisfy
	 * `expires_at < NOW()` and be expired early; against UTC_TIMESTAMP() it must
	 * survive the cleanup pass untouched.
	 */
	public function test_cleanup_expired_uses_utc_when_session_timezone_is_ahead(): void {
		global $wpdb;

		$original_tz = $wpdb->get_var( 'SELECT @@session.time_zone' );
		$wpdb->query( "SET time_zone = '+08:00'" );

		try {
			$proposal = Agent_Proposals::create( 'list_posts', array(), 'wordpress-assistant', 'Fresh across timezones' );

			$flipped = Agent_Proposals::cleanup_expired();

			$this->assertSame( 0, $flipped );
			$row = $this->get_proposal_row( $proposal['id'] );
			$this->assertSame( 'pending', $row['status'] );
		} finally {
			$wpdb->query( $wpdb->prepare( 'SET time_zone = %s', $original_tz ) );
		}
	}

	/**
	 * mark_decided() claims the pending row atomically: the first claim wins
	 * (1 affected row), a second claim for an already-decided row loses (0 rows).
	 */
	public function test_mark_decided_claims_pending_row_atomically(): void {
		$proposal = Agent_Proposals::create( 'list_posts', array(), 'wordpress-assistant', 'Claim me' );

		$mark = new \ReflectionMethod( Agent_Proposals::class, 'mark_decided' );

		$this->assertTrue( $mark->invoke( null, $proposal['id'], 'approved' ), 'The first claim must win the pending row.' );
		$this->assertFalse( $mark->invoke( null, $proposal['id'], 'approved' ), 'A second claim must lose the already-decided row.' );

		$row = $this->get_proposal_row( $proposal['id'] );
		$this->assertSame( 'approved', $row['status'] );
		$this->assertSame( 'approved', $row['decision'] );
	}

	/**
	 * A losing concurrent approve() — whose get() read 'pending' but whose
	 * mark_decided() UPDATE lands after a competing request already claimed the
	 * row — must be refused and must NOT execute the tool. Simulated with the
	 * 'query' filter: when approve()'s conditional UPDATE is about to run, a
	 * competing request claims the row first, so the UPDATE affects 0 rows.
	 */
	public function test_approve_refuses_and_skips_execution_when_claim_lost(): void {
		$proposal = Agent_Proposals::create( 'list_posts', array(), 'wordpress-assistant', 'Race', '', 'run-race', 1 );

		$claimed = false;
		$race    = static function ( string $query ) use ( &$claimed, $proposal ): string {
			if ( ! $claimed && false !== stripos( $query, 'agent_builder_proposals' ) && false !== stripos( $query, "SET status = 'approved'" ) ) {
				$claimed = true;
				global $wpdb;
				// The competing request claims the pending row first.
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$wpdb->prefix}agent_builder_proposals SET status = 'approved', decision = 'approved', decided_by = 1, decided_at = %s WHERE id = %s AND status = 'pending'",
						gmdate( 'Y-m-d H:i:s' ),
						$proposal['id']
					)
				);
			}
			return $query;
		};

		add_filter( 'query', $race );

		try {
			$result = Agent_Proposals::approve( $proposal['id'] );
		} finally {
			remove_filter( 'query', $race );
		}

		$this->assertArrayHasKey( 'error', $result );
		$this->assertSame( 'Proposal already processed.', $result['error'] );

		// The losing request must not have executed the tool: no 'proposal_approved'
		// audit row was written for this proposal.
		global $wpdb;
		$approved = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}agent_builder_audit_log WHERE action = %s", 'proposal_approved' )
		);
		$this->assertSame( 0, $approved );
	}

	/**
	 * The identical atomic-claim guard applies to reject(): a losing reject()
	 * returns the already-processed error and writes no 'proposal_rejected'
	 * audit row.
	 */
	public function test_reject_refuses_when_claim_lost(): void {
		$proposal = Agent_Proposals::create( 'list_posts', array(), 'wordpress-assistant', 'Race reject', '', 'run-reject', 1 );

		$claimed = false;
		$race    = static function ( string $query ) use ( &$claimed, $proposal ): string {
			if ( ! $claimed && false !== stripos( $query, 'agent_builder_proposals' ) && false !== stripos( $query, "SET status = 'rejected'" ) ) {
				$claimed = true;
				global $wpdb;
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$wpdb->prefix}agent_builder_proposals SET status = 'rejected', decision = 'rejected', decided_by = 1, decided_at = %s WHERE id = %s AND status = 'pending'",
						gmdate( 'Y-m-d H:i:s' ),
						$proposal['id']
					)
				);
			}
			return $query;
		};

		add_filter( 'query', $race );

		try {
			$result = Agent_Proposals::reject( $proposal['id'] );
		} finally {
			remove_filter( 'query', $race );
		}

		$this->assertArrayHasKey( 'error', $result );
		$this->assertSame( 'Proposal already processed.', $result['error'] );

		global $wpdb;
		$rejected = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}agent_builder_audit_log WHERE action = %s", 'proposal_rejected' )
		);
		$this->assertSame( 0, $rejected );
	}

	/**
	 * A listener-gated proposal persists its listener_id, and approve() clears the
	 * dedupe marker immediately (rather than waiting out the marker's one-hour
	 * TTL), so the same event can mint a fresh proposal again.
	 */
	public function test_approve_clears_listener_dedupe_marker_immediately(): void {
		$agent_id    = 'approve-clears-marker-agent';
		$listener_id = 'l-approve';
		$tool        = 'list_posts';

		$proposal = Agent_Proposals::create( $tool, array(), $agent_id, 'Approve marker', '', '', 0, $listener_id );

		// listener_id round-trips through the row: persisted by create()'s insert,
		// read back by get()/normalize_row().
		$this->assertSame( $listener_id, Agent_Proposals::get( $proposal['id'] )['listener_id'] );

		// A live marker exists while the proposal is still pending.
		$this->assertSame( $proposal['id'], Agent_Proposals::has_pending( $agent_id, $listener_id, $tool ) );

		Agent_Proposals::approve( $proposal['id'] );

		// The marker is gone the moment the proposal is decided — no TTL wait.
		$this->assertNull( Agent_Proposals::has_pending( $agent_id, $listener_id, $tool ) );
		$this->assertFalse( get_transient( Agent_Proposals::pending_key( $agent_id, $listener_id, $tool ) ) );
	}

	/**
	 * reject() likewise clears the listener dedupe marker immediately.
	 */
	public function test_reject_clears_listener_dedupe_marker_immediately(): void {
		$agent_id    = 'reject-clears-marker-agent';
		$listener_id = 'l-reject';
		$tool        = 'list_posts';

		$proposal = Agent_Proposals::create( $tool, array(), $agent_id, 'Reject marker', '', '', 0, $listener_id );

		$this->assertSame( $listener_id, Agent_Proposals::get( $proposal['id'] )['listener_id'] );
		$this->assertSame( $proposal['id'], Agent_Proposals::has_pending( $agent_id, $listener_id, $tool ) );

		Agent_Proposals::reject( $proposal['id'] );

		$this->assertNull( Agent_Proposals::has_pending( $agent_id, $listener_id, $tool ) );
		$this->assertFalse( get_transient( Agent_Proposals::pending_key( $agent_id, $listener_id, $tool ) ) );
	}

	/**
	 * Fetch a raw proposals table row by id.
	 *
	 * @param string $proposal_id Proposal UUID.
	 * @return array
	 */
	private function get_proposal_row( string $proposal_id ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}agent_builder_proposals WHERE id = %s", $proposal_id ),
			ARRAY_A
		);
	}
}
