<?php
/**
 * Unit tests for the audit-log chain anchor and gap links.
 *
 * Retention and privacy erasure remove audit rows; before the anchor existed
 * the first kept row then failed to verify ("tampering detected" about 30
 * days after install). These tests cover the fix and that real tampering is
 * still caught.
 *
 * @package Agentic\Tests
 */

namespace Agentic\Tests;

use Agentic\Audit_Log;
use Agentic\Audit_Log_Integrity;
use Agentic\GDPR;

/**
 * Test case for Audit_Log_Integrity anchors.
 */
class Test_Audit_Chain_Anchor extends TestCase {

	/**
	 * Start every test with no links stored.
	 */
	public function setUp(): void {
		parent::setUp();
		delete_option( Audit_Log_Integrity::ANCHOR_OPTION );
		delete_option( Audit_Log_Integrity::GAPS_OPTION );
		delete_option( Audit_Log_Integrity::REPAIR_FLAG_OPTION );
		delete_option( 'agent_builder_retention_audit_log' );
	}

	/**
	 * Insert a chained row with a chosen age, the way Audit_Log::log() does.
	 *
	 * @param int $days_ago Age of the row in days.
	 * @param int $user_id  User id stored on the row.
	 * @return int Row id.
	 */
	private function add_row( int $days_ago, int $user_id = 0 ): int {
		global $wpdb;
		static $n = 0;
		++$n;
		$data = array(
			'agent_id'      => 'test-agent',
			'action'        => 'tool_call',
			'target_type'   => 'list_posts',
			'target_id'     => (string) $n,
			'details'       => wp_json_encode( array( 'n' => $n ) ),
			'reasoning'     => '',
			'mode'          => 'supervised',
			'provider'      => '',
			'tokens_used'   => 0,
			'cost'          => 0.0,
			'user_id'       => $user_id,
			'created_at'    => gmdate( 'Y-m-d H:i:s', time() - ( $days_ago * DAY_IN_SECONDS ) ),
			'agent_author'  => '',
			'agent_version' => '',
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert( $wpdb->prefix . 'agent_builder_audit_log', $data );
		$id = (int) $wpdb->insert_id;
		Audit_Log_Integrity::record( $id, $data );
		return $id;
	}

	/**
	 * Delete rows directly, bypassing the plugin (as a DB-level edit would).
	 *
	 * @param int[] $ids Row ids.
	 */
	private function raw_delete( array $ids ): void {
		global $wpdb;
		foreach ( $ids as $id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->delete( $wpdb->prefix . 'agent_builder_audit_log', array( 'id' => $id ) );
		}
	}

	/**
	 * Edit a row's content directly.
	 *
	 * @param int $id Row id.
	 */
	private function raw_edit( int $id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( $wpdb->prefix . 'agent_builder_audit_log', array( 'action' => 'tampered' ), array( 'id' => $id ) );
	}

	/**
	 * Count audit rows.
	 *
	 * @return int
	 */
	private function row_count(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}agent_builder_audit_log" );
	}

	/**
	 * Retention prune via the cron path, then verify → valid.
	 */
	public function test_retention_prune_keeps_chain_valid(): void {
		$this->add_row( 45 );
		$this->add_row( 40 );
		$old  = $this->add_row( 35 );
		$keep = $this->add_row( 10 );
		$this->add_row( 1 );

		update_option( 'agent_builder_retention_audit_log', 30 );
		$deleted = ( new Audit_Log() )->cleanup_expired();

		$this->assertSame( 3, $deleted );
		$result = Audit_Log_Integrity::verify_chain();
		$this->assertTrue( $result['valid'], 'chain must verify after retention' );
		$this->assertSame( 2, $result['checked'] );
		$this->assertSame( $keep, $result['chain_start_id'] );
		$this->assertSame( $keep, $result['anchor_id'] );

		$anchor = Audit_Log_Integrity::get_anchor();
		$this->assertSame( $keep, $anchor['id'] );
		$this->assertSame( 'retention', $anchor['reason'] );
		$this->assertGreaterThan( $old, $anchor['id'] );

		// The scoped path still works from the anchored row and later.
		$this->assertTrue( Audit_Log_Integrity::verify_chain( $keep )['valid'] );
		$this->assertTrue( Audit_Log_Integrity::verify_chain( $keep + 1 )['valid'] );
	}

	/**
	 * Pruning twice keeps the chain valid (the anchor moves forward).
	 */
	public function test_prune_twice_keeps_chain_valid(): void {
		$this->add_row( 60 );
		$this->add_row( 50 );
		$this->add_row( 40 );
		$this->add_row( 20 );
		$last = $this->add_row( 0 );

		$this->assertSame( 2, Audit_Log_Integrity::prune_before( gmdate( 'Y-m-d H:i:s', time() - 45 * DAY_IN_SECONDS ) ) );
		$this->assertTrue( Audit_Log_Integrity::verify_chain()['valid'] );

		$this->assertSame( 2, Audit_Log_Integrity::prune_before( gmdate( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS ) ) );
		$result = Audit_Log_Integrity::verify_chain();
		$this->assertTrue( $result['valid'] );
		$this->assertSame( $last, $result['chain_start_id'] );

		// New rows keep chaining after the prunes.
		( new Audit_Log() )->log( 'test-agent', 'tool_call', 'list_posts', array( 'id' => 9 ) );
		$this->assertTrue( Audit_Log_Integrity::verify_chain()['valid'] );
	}

	/**
	 * GDPR::run_cleanup() uses the same anchored prune.
	 */
	public function test_gdpr_run_cleanup_keeps_chain_valid(): void {
		$this->add_row( 40 );
		$this->add_row( 35 );
		$keep = $this->add_row( 5 );

		update_option( 'agent_builder_retention_audit_log', 30 );
		GDPR::run_cleanup();

		$this->assertSame( 1, $this->row_count() );
		$result = Audit_Log_Integrity::verify_chain();
		$this->assertTrue( $result['valid'] );
		$this->assertSame( $keep, $result['chain_start_id'] );
	}

	/**
	 * Retention 0 keeps everything.
	 */
	public function test_retention_zero_keeps_everything(): void {
		$this->add_row( 400 );
		$this->add_row( 40 );
		$this->add_row( 1 );

		update_option( 'agent_builder_retention_audit_log', 0 );
		$this->assertSame( 0, ( new Audit_Log() )->cleanup_expired() );
		GDPR::run_cleanup();

		$this->assertSame( 3, $this->row_count() );
		$this->assertNull( Audit_Log_Integrity::get_anchor() );
		$this->assertTrue( Audit_Log_Integrity::verify_chain()['valid'] );
	}

	/**
	 * Pruning every row clears the links; new rows start a fresh chain.
	 */
	public function test_prune_everything_clears_anchor(): void {
		$this->add_row( 40 );
		$this->add_row( 35 );

		$this->assertSame( 2, ( new Audit_Log() )->cleanup( 30 ) );
		$this->assertNull( Audit_Log_Integrity::get_anchor() );

		$this->add_row( 0 );
		$this->assertTrue( Audit_Log_Integrity::verify_chain()['valid'] );
	}

	/**
	 * Editing a kept row after a prune is still detected.
	 */
	public function test_edit_kept_row_after_prune_is_detected(): void {
		$this->add_row( 40 );
		$first = $this->add_row( 10 );
		$mid   = $this->add_row( 5 );
		$this->add_row( 1 );
		( new Audit_Log() )->cleanup( 30 );

		$this->raw_edit( $mid );
		$result = Audit_Log_Integrity::verify_chain();
		$this->assertFalse( $result['valid'] );
		$this->assertSame( $mid, $result['broken_at_id'] );

		// Editing the anchored first row itself is detected too.
		$this->raw_edit( $first );
		$this->assertSame( $first, Audit_Log_Integrity::verify_chain()['broken_at_id'] );
	}

	/**
	 * Deleting a row in the middle (outside the plugin) is still detected.
	 */
	public function test_delete_middle_row_after_prune_is_detected(): void {
		$this->add_row( 40 );
		$this->add_row( 10 );
		$mid  = $this->add_row( 5 );
		$next = $this->add_row( 1 );
		( new Audit_Log() )->cleanup( 30 );

		$this->raw_delete( array( $mid ) );
		$result = Audit_Log_Integrity::verify_chain();
		$this->assertFalse( $result['valid'] );
		$this->assertSame( $next, $result['broken_at_id'] );
	}

	/**
	 * Deleting more head rows without the helper is detected: the new first
	 * row no longer matches the anchor id.
	 */
	public function test_delete_head_rows_without_helper_is_detected(): void {
		$this->add_row( 40 );
		$first  = $this->add_row( 10 );
		$second = $this->add_row( 5 );
		$this->add_row( 1 );
		( new Audit_Log() )->cleanup( 30 );
		$this->assertTrue( Audit_Log_Integrity::verify_chain()['valid'] );

		$this->raw_delete( array( $first ) );
		$result = Audit_Log_Integrity::verify_chain();
		$this->assertFalse( $result['valid'] );
		$this->assertSame( $second, $result['broken_at_id'] );
	}

	/**
	 * Without any anchor, a raw head delete is reported (the original bug's
	 * symptom — still correct for deletions made outside the plugin).
	 */
	public function test_raw_head_delete_without_anchor_is_detected(): void {
		$a = $this->add_row( 3 );
		$b = $this->add_row( 2 );
		$this->add_row( 1 );

		$this->raw_delete( array( $a ) );
		$this->assertSame( $b, Audit_Log_Integrity::verify_chain()['broken_at_id'] );
	}

	/**
	 * A fake anchor prev_hash is rejected.
	 */
	public function test_fake_anchor_prev_hash_is_detected(): void {
		$this->add_row( 40 );
		$first = $this->add_row( 10 );
		$this->add_row( 1 );
		( new Audit_Log() )->cleanup( 30 );

		$anchor              = get_option( Audit_Log_Integrity::ANCHOR_OPTION );
		$anchor['prev_hash'] = str_repeat( 'a', 64 );
		update_option( Audit_Log_Integrity::ANCHOR_OPTION, $anchor, false );

		$result = Audit_Log_Integrity::verify_chain();
		$this->assertFalse( $result['valid'] );
		$this->assertSame( $first, $result['broken_at_id'] );
	}

	/**
	 * An anchor moved to another row id (signature no longer matches) is ignored.
	 */
	public function test_moved_anchor_is_rejected(): void {
		$this->add_row( 40 );
		$first  = $this->add_row( 10 );
		$second = $this->add_row( 5 );
		( new Audit_Log() )->cleanup( 30 );

		$anchor = get_option( Audit_Log_Integrity::ANCHOR_OPTION );
		$this->raw_delete( array( $first ) );
		$anchor['id'] = $second;
		update_option( Audit_Log_Integrity::ANCHOR_OPTION, $anchor, false );

		$this->assertSame( $second, Audit_Log_Integrity::verify_chain()['broken_at_id'] );
	}

	/**
	 * The upgrade repair fixes a chain pruned before the anchor existed.
	 */
	public function test_repair_fixes_chain_pruned_before_the_fix(): void {
		$old   = $this->add_row( 40 );
		$first = $this->add_row( 10 );
		$this->add_row( 5 );
		$last = $this->add_row( 1 );

		// Old behavior: plain DELETE, no anchor.
		$this->raw_delete( array( $old ) );
		$before = Audit_Log_Integrity::verify_chain();
		$this->assertFalse( $before['valid'] );
		$this->assertSame( $first, $before['broken_at_id'] );

		Audit_Log_Integrity::maybe_repair_pruned_head();

		$after = Audit_Log_Integrity::verify_chain();
		$this->assertTrue( $after['valid'] );
		$this->assertSame( 3, $after['checked'] );
		$anchor = Audit_Log_Integrity::get_anchor();
		$this->assertSame( $first, $anchor['id'] );
		$this->assertSame( 'retention_before_anchor', $anchor['reason'] );
		$this->assertNotNull( $anchor['trusted_start_hash'] );
		$this->assertNotEmpty( get_option( Audit_Log_Integrity::REPAIR_FLAG_OPTION ) );

		// Later tampering is still detected after the repair.
		$this->raw_edit( $last );
		$this->assertSame( $last, Audit_Log_Integrity::verify_chain()['broken_at_id'] );
	}

	/**
	 * After a repair, retention keeps working and editing the trusted first
	 * row's stored hash is detected.
	 */
	public function test_repaired_chain_survives_later_prune_and_detects_hash_edit(): void {
		global $wpdb;

		$old   = $this->add_row( 50 );
		$first = $this->add_row( 40 );
		$this->add_row( 5 );
		$this->raw_delete( array( $old ) );
		$this->assertTrue( Audit_Log_Integrity::repair_pruned_head() );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( $wpdb->prefix . 'agent_builder_audit_log', array( 'integrity_hash' => str_repeat( 'b', 64 ) ), array( 'id' => $first ) );
		$this->assertSame( $first, Audit_Log_Integrity::verify_chain()['broken_at_id'] );
	}

	/**
	 * Retention after a repair moves the anchor on and stays valid.
	 */
	public function test_prune_after_repair_stays_valid(): void {
		$old = $this->add_row( 50 );
		$this->add_row( 40 );
		$this->add_row( 5 );
		$this->raw_delete( array( $old ) );
		$this->assertTrue( Audit_Log_Integrity::repair_pruned_head() );

		( new Audit_Log() )->cleanup( 30 );
		$this->assertTrue( Audit_Log_Integrity::verify_chain()['valid'] );
		$this->assertNull( Audit_Log_Integrity::get_anchor()['trusted_start_hash'] );
	}

	/**
	 * The repair does NOT run when later rows are also broken.
	 */
	public function test_repair_refuses_chain_broken_later(): void {
		$old = $this->add_row( 40 );
		$this->add_row( 10 );
		$mid = $this->add_row( 5 );
		$this->add_row( 1 );

		$this->raw_delete( array( $old ) );
		$this->raw_edit( $mid );

		Audit_Log_Integrity::maybe_repair_pruned_head();

		$this->assertNull( Audit_Log_Integrity::get_anchor() );
		$this->assertFalse( Audit_Log_Integrity::verify_chain()['valid'] );
		$this->assertFalse( Audit_Log_Integrity::repair_pruned_head() );
	}

	/**
	 * The repair does nothing on a valid chain, or one broken mid-table.
	 */
	public function test_repair_ignores_valid_or_mid_table_breaks(): void {
		$this->add_row( 3 );
		$mid = $this->add_row( 2 );
		$this->add_row( 1 );
		$this->assertFalse( Audit_Log_Integrity::repair_pruned_head() );

		$this->raw_delete( array( $mid ) );
		$this->assertFalse( Audit_Log_Integrity::repair_pruned_head() );
		$this->assertNull( Audit_Log_Integrity::get_anchor() );
	}

	/**
	 * GDPR erase removes the user's rows (anywhere in the table) and the
	 * chain still verifies; a later deletion outside the plugin is caught.
	 */
	public function test_gdpr_erase_removes_rows_and_keeps_chain_valid(): void {
		$user  = self::factory()->user->create( array( 'user_email' => 'erase-me@example.com' ) );
		$other = self::factory()->user->create( array( 'user_email' => 'keep-me@example.com' ) );

		$this->add_row( 9, $user );   // Head.
		$k1 = $this->add_row( 8, $other );
		$this->add_row( 7, $user );   // Middle, single.
		$k2 = $this->add_row( 6, $other );
		$this->add_row( 5, $user );   // Middle, run of two.
		$this->add_row( 4, $user );
		$k3 = $this->add_row( 3, $other );
		$this->add_row( 2, $other );
		$this->add_row( 1, $user );   // Tail.

		$result = GDPR::erase_audit_log( 'erase-me@example.com' );
		$this->assertSame( 5, $result['items_removed'] );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$left = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}agent_builder_audit_log WHERE user_id = %d", $user ) );
		$this->assertSame( 0, $left, 'personal rows must be gone' );

		$verify = Audit_Log_Integrity::verify_chain();
		$this->assertTrue( $verify['valid'], 'chain must verify after erasure' );
		$this->assertSame( 4, $verify['checked'] );
		$this->assertSame( $k1, Audit_Log_Integrity::get_anchor()['id'] );

		// Scoped walks across the gaps work.
		$this->assertTrue( Audit_Log_Integrity::verify_chain( $k2 )['valid'] );
		$this->assertTrue( Audit_Log_Integrity::verify_chain( $k3 )['valid'] );

		// New rows chain on after the erased tail.
		( new Audit_Log() )->log( 'test-agent', 'tool_call', 'list_posts', array( 'id' => 1 ) );
		$this->assertTrue( Audit_Log_Integrity::verify_chain()['valid'] );

		// Deleting the kept row before a gap, outside the plugin, is caught.
		$this->raw_delete( array( $k2 ) );
		$this->assertSame( $k3, Audit_Log_Integrity::verify_chain()['broken_at_id'] );
	}

	/**
	 * A second erasure next to an existing gap merges the gap correctly, and
	 * retention afterwards still verifies.
	 */
	public function test_gdpr_erase_then_more_erasure_and_prune(): void {
		$a = self::factory()->user->create( array( 'user_email' => 'a@example.com' ) );
		$b = self::factory()->user->create( array( 'user_email' => 'b@example.com' ) );

		$this->add_row( 50 );
		$this->add_row( 9, $b );
		$this->add_row( 8, $a );
		$k = $this->add_row( 7 );
		$this->add_row( 6, $b );
		$this->add_row( 5 );

		GDPR::erase_audit_log( 'a@example.com' );
		$this->assertTrue( Audit_Log_Integrity::verify_chain()['valid'] );

		GDPR::erase_audit_log( 'b@example.com' );
		$this->assertTrue( Audit_Log_Integrity::verify_chain()['valid'] );

		( new Audit_Log() )->cleanup( 30 );
		$result = Audit_Log_Integrity::verify_chain();
		$this->assertTrue( $result['valid'] );
		$this->assertSame( $k, $result['chain_start_id'] );
	}

	/**
	 * A forged gap link is ignored, so a deletion outside the plugin with a
	 * hand-written link is still reported.
	 */
	public function test_forged_gap_link_is_rejected(): void {
		$this->add_row( 3 );
		$mid  = $this->add_row( 2 );
		$next = $this->add_row( 1 );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$mid_hash = $wpdb->get_var( $wpdb->prepare( "SELECT integrity_hash FROM {$wpdb->prefix}agent_builder_audit_log WHERE id = %d", $mid ) );
		$this->raw_delete( array( $mid ) );
		update_option(
			Audit_Log_Integrity::GAPS_OPTION,
			array(
				array(
					'id'        => $next,
					'prev_id'   => $mid - 1,
					'prev_hash' => $mid_hash,
					'sig'       => str_repeat( 'c', 64 ),
				),
			),
			false
		);

		$this->assertSame( $next, Audit_Log_Integrity::verify_chain()['broken_at_id'] );
	}
}
