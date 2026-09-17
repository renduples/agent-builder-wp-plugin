<?php
/**
 * Unit Tests for Audit_Log_Integrity.
 *
 * Audit_Log::log() calls Audit_Log_Integrity::record() automatically on
 * every insert (see includes/class-audit-log.php), so these tests write
 * through the real Audit_Log API rather than hand-building canonical rows —
 * that exercises the exact code path production traffic uses.
 *
 * @package Agentic\Tests
 */

namespace Agentic\Tests;

use Agentic\Audit_Log;
use Agentic\Audit_Log_Integrity;

/**
 * Test case for Audit_Log_Integrity.
 */
class Test_Audit_Log_Integrity extends TestCase {

	/**
	 * A freshly written chain of rows verifies as valid end to end.
	 */
	public function test_verify_chain_reports_valid_for_untampered_rows(): void {
		$audit = new Audit_Log();
		$audit->log( 'test-agent', 'tool_call', 'list_posts', array( 'id' => 1 ) );
		$audit->log( 'test-agent', 'tool_call', 'get_post_content', array( 'id' => 2 ) );
		$audit->log( 'test-agent', 'tool_call', 'db_update_option', array( 'id' => 3 ) );

		$result = Audit_Log_Integrity::verify_chain();

		$this->assertTrue( $result['valid'] );
		$this->assertNull( $result['broken_at_id'] );
		$this->assertGreaterThanOrEqual( 3, $result['checked'] );
		$this->assertNotNull( $result['chain_start_id'] );
	}

	/**
	 * Editing a historical row's content in place (bypassing the application
	 * layer, as direct DB access would) must be detected: verify_chain()
	 * reports the tampered row's id as broken_at_id, and every row after it
	 * fails to verify too since each hash is chained onto the previous one.
	 */
	public function test_verify_chain_detects_a_tampered_row(): void {
		global $wpdb;

		$audit = new Audit_Log();
		$id1   = $audit->log( 'test-agent', 'tool_call', 'list_posts', array( 'id' => 1 ) );
		$id2   = $audit->log( 'test-agent', 'tool_call', 'get_post_content', array( 'id' => 2 ) );
		$id3   = $audit->log( 'test-agent', 'tool_call', 'db_update_option', array( 'id' => 3 ) );

		$this->assertNotFalse( $id1 );
		$this->assertNotFalse( $id2 );
		$this->assertNotFalse( $id3 );

		$sanity = Audit_Log_Integrity::verify_chain();
		$this->assertTrue( $sanity['valid'], 'precondition: chain must be valid before tampering' );

		// Directly mutate row 2's action, the way a rogue DB-level edit would —
		// not through Audit_Log::log(), so integrity_hash is left stale.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->update(
			$wpdb->prefix . 'agent_builder_audit_log',
			array( 'action' => 'tampered_action' ),
			array( 'id' => $id2 )
		);

		$result = Audit_Log_Integrity::verify_chain();

		$this->assertFalse( $result['valid'] );
		$this->assertSame( (int) $id2, $result['broken_at_id'] );
	}

	/**
	 * Deleting a row outright also breaks the chain from that point forward —
	 * this is the gap a per-row-only signature (no chaining) would miss.
	 */
	public function test_verify_chain_detects_a_deleted_row(): void {
		global $wpdb;

		$audit = new Audit_Log();
		$id1   = $audit->log( 'test-agent', 'tool_call', 'list_posts', array( 'id' => 1 ) );
		$id2   = $audit->log( 'test-agent', 'tool_call', 'get_post_content', array( 'id' => 2 ) );
		$id3   = $audit->log( 'test-agent', 'tool_call', 'db_update_option', array( 'id' => 3 ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->delete( $wpdb->prefix . 'agent_builder_audit_log', array( 'id' => $id2 ) );

		$result = Audit_Log_Integrity::verify_chain();

		$this->assertFalse( $result['valid'] );
		// The row after the deleted one is now the first mismatch, since it
		// was chained onto id2's hash which no longer precedes it.
		$this->assertSame( (int) $id3, $result['broken_at_id'] );
	}

	/**
	 * verify_chain( $from_id ) scopes the walk to id >= $from_id, so a caller
	 * can check only recent history without re-verifying the entire table.
	 */
	public function test_verify_chain_can_be_scoped_by_from_id(): void {
		$audit = new Audit_Log();
		$audit->log( 'test-agent', 'tool_call', 'list_posts', array( 'id' => 1 ) );
		$id2 = $audit->log( 'test-agent', 'tool_call', 'get_post_content', array( 'id' => 2 ) );
		$audit->log( 'test-agent', 'tool_call', 'db_update_option', array( 'id' => 3 ) );

		$result = Audit_Log_Integrity::verify_chain( (int) $id2 );

		$this->assertTrue( $result['valid'] );
		$this->assertSame( 2, $result['checked'] );
	}

	/**
	 * compute_hash() is deterministic for identical inputs and changes when
	 * the previous hash in the chain changes — the core property the whole
	 * chain relies on.
	 */
	public function test_compute_hash_is_deterministic_and_chain_sensitive(): void {
		$data = array(
			'agent_id'  => 'test-agent',
			'action'    => 'tool_call',
			'created_at' => '2026-01-01 00:00:00',
		);

		$hash_a = Audit_Log_Integrity::compute_hash( 1, $data, null );
		$hash_b = Audit_Log_Integrity::compute_hash( 1, $data, null );
		$this->assertSame( $hash_a, $hash_b );

		$hash_with_prev = Audit_Log_Integrity::compute_hash( 1, $data, 'some-previous-hash' );
		$this->assertNotSame( $hash_a, $hash_with_prev );
	}
}
