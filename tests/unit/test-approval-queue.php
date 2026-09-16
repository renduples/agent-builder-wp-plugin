<?php
/**
 * Unit Tests for Approval_Queue.
 *
 * Covers the create → approve/reject/expire state transitions in
 * wp_agentic_approval_queue, and the params-aware find_approved() matching
 * that Tool_Executor relies on to consume a prior approval.
 *
 * @package Agentic\Tests
 */

namespace Agentic\Tests;

use Agentic\Approval_Queue;

/**
 * Test case for Approval_Queue.
 */
class Test_Approval_Queue extends TestCase {

	/**
	 * A new item lands as status = pending and is counted by get_pending_count().
	 */
	public function test_add_creates_a_pending_item(): void {
		$queue = new Approval_Queue();

		$id = $queue->add( 'test-agent', 'create_agent_files', array( 'slug' => 'new-agent' ), 'Needs a new agent', 7, 'high' );

		$this->assertIsInt( $id );
		$this->assertGreaterThan( 0, $id );
		$this->assertSame( 1, $queue->get_pending_count() );

		$pending = $queue->get_pending();
		$this->assertCount( 1, $pending );
		$this->assertSame( 'create_agent_files', $pending[0]['action'] );
		$this->assertSame( 'pending', $pending[0]['status'] );
		// params round-trips through wp_json_encode/json_decode.
		$this->assertSame( 'new-agent', $pending[0]['params']['slug'] );
	}

	/**
	 * approve() flips status to approved, stamps approved_by/approved_at,
	 * and removes the item from the pending count.
	 */
	public function test_approve_transitions_pending_to_approved(): void {
		$queue = new Approval_Queue();
		$id    = $queue->add( 'test-agent', 'force_password_reset', array( 'user_id' => 5 ), 'Locked out', 7, 'high' );

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$result = $queue->approve( $id );

		$this->assertTrue( $result );
		$this->assertSame( 0, $queue->get_pending_count() );

		$row = $this->get_queue_row( $id );
		$this->assertSame( 'approved', $row['status'] );
		$this->assertEquals( $admin_id, $row['approved_by'] );
		$this->assertNotNull( $row['approved_at'] );
	}

	/**
	 * reject() flips status to rejected and stamps approved_by/approved_at
	 * (the column doubles as "who acted on this", not just "who said yes").
	 */
	public function test_reject_transitions_pending_to_rejected(): void {
		$queue = new Approval_Queue();
		$id    = $queue->add( 'test-agent', 'wc_create_refund', array( 'order_id' => 42 ), 'Customer dispute', 7, 'high' );

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$result = $queue->reject( $id );

		$this->assertTrue( $result );
		$this->assertSame( 0, $queue->get_pending_count() );

		$row = $this->get_queue_row( $id );
		$this->assertSame( 'rejected', $row['status'] );
		$this->assertEquals( $admin_id, $row['approved_by'] );
	}

	/**
	 * cleanup_expired() marks a pending item whose expires_at has passed as
	 * 'expired' — it must not be silently deleted (the record is retained),
	 * and it must no longer count as pending.
	 */
	public function test_cleanup_expired_marks_stale_pending_as_expired(): void {
		global $wpdb;

		$queue = new Approval_Queue();
		$id    = $queue->add( 'test-agent', 'delete_form', array( 'form_id' => 9 ), 'Cleanup', 7, 'high' );

		// Backdate expires_at into the past, as if 7 days had elapsed.
		$wpdb->update(
			$wpdb->prefix . 'agentic_approval_queue',
			array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ),
			array( 'id' => $id )
		);

		$queue->cleanup_expired();

		$row = $this->get_queue_row( $id );
		$this->assertSame( 'expired', $row['status'] );
		$this->assertSame( 0, $queue->get_pending_count() );
	}

	/**
	 * cleanup_expired() does not touch a pending item whose expiry is still
	 * in the future.
	 */
	public function test_cleanup_expired_leaves_unexpired_pending_alone(): void {
		$queue = new Approval_Queue();
		$id    = $queue->add( 'test-agent', 'delete_form', array( 'form_id' => 9 ), 'Cleanup', 7, 'high' );

		$queue->cleanup_expired();

		$row = $this->get_queue_row( $id );
		$this->assertSame( 'pending', $row['status'] );
		$this->assertSame( 1, $queue->get_pending_count() );
	}

	/**
	 * log_executed() records an immediately-executed write with no approval
	 * gate — it must not appear in get_pending_count() or get_completed_count(),
	 * since nobody approved it (approved_by stays NULL).
	 */
	public function test_log_executed_is_not_counted_as_pending_or_completed(): void {
		$queue = new Approval_Queue();
		$id    = $queue->log_executed( 'test-agent', 'db_update_option', array( 'key' => 'x' ), 'low', 'autonomous', 'chat' );

		$this->assertIsInt( $id );
		$this->assertSame( 0, $queue->get_pending_count() );
		$this->assertSame( 0, $queue->get_completed_count() );

		$row = $this->get_queue_row( $id );
		$this->assertSame( 'executed', $row['status'] );
		$this->assertNull( $row['approved_by'] );
	}

	/**
	 * find_approved() only matches an approved item whose stored params are
	 * identical to the current call's arguments — a different call to the
	 * same tool/action must not consume an unrelated approval.
	 */
	public function test_find_approved_matches_only_identical_params(): void {
		$queue = new Approval_Queue();
		$id    = $queue->add( 'test-agent', 'manage_cli_settings', array( 'action' => 'enable', 'flag' => 'debug' ), 'Enable debug', 7, 'high' );
		$queue->approve( $id );

		$match = $queue->find_approved( 'test-agent', 'manage_cli_settings', array( 'action' => 'enable', 'flag' => 'debug' ) );
		$this->assertNotNull( $match );
		$this->assertSame( $id, (int) $match['id'] );

		$no_match = $queue->find_approved( 'test-agent', 'manage_cli_settings', array( 'action' => 'disable', 'flag' => 'debug' ) );
		$this->assertNull( $no_match );
	}

	/**
	 * find_approved() requires arguments to be passed — never matches by
	 * tool name alone, which would let an approval for one call authorize a
	 * completely different one.
	 */
	public function test_find_approved_never_matches_without_arguments(): void {
		$queue = new Approval_Queue();
		$id    = $queue->add( 'test-agent', 'manage_cli_settings', array( 'action' => 'enable' ), 'Enable', 7, 'high' );
		$queue->approve( $id );

		$this->assertNull( $queue->find_approved( 'test-agent', 'manage_cli_settings', null ) );
	}

	/**
	 * mark_executed() flips an approved item to executed and stamps executed_at.
	 */
	public function test_mark_executed_stamps_executed_at(): void {
		$queue = new Approval_Queue();
		$id    = $queue->add( 'test-agent', 'force_password_reset', array( 'user_id' => 1 ), 'Reset', 7, 'high' );
		$queue->approve( $id );

		$result = $queue->mark_executed( $id );

		$this->assertTrue( $result );
		$row = $this->get_queue_row( $id );
		$this->assertSame( 'executed', $row['status'] );
		$this->assertNotNull( $row['executed_at'] );
	}

	/**
	 * Fetch a raw approval_queue row by id.
	 *
	 * @param int $id Queue row id.
	 * @return array
	 */
	private function get_queue_row( int $id ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}agentic_approval_queue WHERE id = %d", $id ),
			ARRAY_A
		);
	}
}
