<?php
/**
 * Unit Tests for GDPR exporters/erasers (M11 §8).
 *
 * Covers the newly-registered personal-data exporters and erasers for the
 * `agent_builder_notifications` rows, the `agent_builder_notify_optout` user
 * meta, and `agent_builder_runs` rows keyed by `user_id`.
 *
 * @package Agentic\Tests
 */

namespace Agentic\Tests;

use Agentic\GDPR;
use Agentic\Notifications;

/**
 * Test case for GDPR exporters/erasers.
 */
class Test_GDPR extends TestCase {

	/**
	 * Register the notification, opt-out and runs exporters.
	 */
	public function test_register_exporters_includes_new_entries(): void {
		$exporters = GDPR::register_exporters( array() );

		$names = array();
		foreach ( $exporters as $exporter ) {
			$names[] = end( $exporter['callback'] );
		}

		$this->assertContains( 'export_notifications', $names );
		$this->assertContains( 'export_notify_optout', $names );
		$this->assertContains( 'export_runs', $names );
	}

	/**
	 * Register the notification, opt-out and runs erasers.
	 */
	public function test_register_erasers_includes_new_entries(): void {
		$erasers = GDPR::register_erasers( array() );

		$names = array();
		foreach ( $erasers as $eraser ) {
			$names[] = end( $eraser['callback'] );
		}

		$this->assertContains( 'erase_notifications', $names );
		$this->assertContains( 'erase_notify_optout', $names );
		$this->assertContains( 'erase_runs', $names );
	}

	/**
	 * export_notifications() returns only the subject's rows, newest first.
	 */
	public function test_export_notifications_returns_user_rows(): void {
		$email  = 'gdpr-notif@example.com';
		$other  = 'gdpr-other@example.com';
		$user   = self::factory()->user->create( array( 'user_email' => $email ) );
		$user2  = self::factory()->user->create( array( 'user_email' => $other ) );

		Notifications::notify( $user, 'run_finished', 'Mine', 'Body A', array( 'run_id' => 'r-1' ) );
		Notifications::notify( $user2, 'run_finished', 'Theirs', 'Body B', array( 'run_id' => 'r-2' ) );

		$result = GDPR::export_notifications( $email );

		$this->assertCount( 1, $result['data'] );
		$item = $result['data'][0];
		$this->assertSame( 'agent_builder_notifications', $item['group_id'] );
		$this->assertTrue( $result['done'] );
	}

	/**
	 * export_notifications() returns an empty done payload for an unknown email.
	 */
	public function test_export_notifications_unknown_email_is_empty(): void {
		$result = GDPR::export_notifications( 'no-such-user@example.com' );

		$this->assertSame( array(), $result['data'] );
		$this->assertTrue( $result['done'] );
	}

	/**
	 * export_notify_optout() reports the opt-out meta when set.
	 */
	public function test_export_notify_optout_reports_meta(): void {
		$email = 'gdpr-optout@example.com';
		$user  = self::factory()->user->create( array( 'user_email' => $email ) );
		update_user_meta( $user, Notifications::OPTOUT_META, '1' );

		$result = GDPR::export_notify_optout( $email );

		$this->assertCount( 1, $result['data'] );
		$this->assertSame( 'agent_builder_email_prefs', $result['data'][0]['group_id'] );
	}

	/**
	 * export_notify_optout() returns an empty payload when no meta is set.
	 */
	public function test_export_notify_optout_without_meta_is_empty(): void {
		$email = 'gdpr-no-optout@example.com';
		self::factory()->user->create( array( 'user_email' => $email ) );

		$result = GDPR::export_notify_optout( $email );

		$this->assertSame( array(), $result['data'] );
		$this->assertTrue( $result['done'] );
	}

	/**
	 * export_runs() returns only the subject's runs.
	 */
	public function test_export_runs_returns_user_rows(): void {
		$email = 'gdpr-runs@example.com';
		$other = 'gdpr-runs-other@example.com';
		$user  = self::factory()->user->create( array( 'user_email' => $email ) );
		$user2 = self::factory()->user->create( array( 'user_email' => $other ) );

		$this->insert_run( $user, 'run-mine' );
		$this->insert_run( $user2, 'run-theirs' );

		$result = GDPR::export_runs( $email );

		$this->assertCount( 1, $result['data'] );
		$this->assertSame( 'agent_builder_runs', $result['data'][0]['group_id'] );
	}

	/**
	 * erase_notifications() removes the subject's rows and reports the count.
	 */
	public function test_erase_notifications_removes_rows(): void {
		$email = 'gdpr-erase-notif@example.com';
		$user  = self::factory()->user->create( array( 'user_email' => $email ) );

		Notifications::notify( $user, 'run_finished', 'A', '' );
		Notifications::notify( $user, 'run_finished', 'B', '' );

		$result = GDPR::erase_notifications( $email );

		$this->assertSame( 2, $result['items_removed'] );
		$this->assertSame( 0, Notifications::unread_count( $user ) );
	}

	/**
	 * erase_notify_optout() deletes the meta and reports it removed.
	 */
	public function test_erase_notify_optout_removes_meta(): void {
		$email = 'gdpr-erase-optout@example.com';
		$user  = self::factory()->user->create( array( 'user_email' => $email ) );
		update_user_meta( $user, Notifications::OPTOUT_META, '1' );

		$result = GDPR::erase_notify_optout( $email );

		$this->assertSame( 1, $result['items_removed'] );
		$this->assertSame( '', get_user_meta( $user, Notifications::OPTOUT_META, true ) );
	}

	/**
	 * erase_notify_optout() with no meta reports zero removed.
	 */
	public function test_erase_notify_optout_without_meta_removes_nothing(): void {
		$email = 'gdpr-erase-no-optout@example.com';
		self::factory()->user->create( array( 'user_email' => $email ) );

		$result = GDPR::erase_notify_optout( $email );

		$this->assertSame( 0, $result['items_removed'] );
	}

	/**
	 * erase_runs() removes the subject's runs and reports the count.
	 */
	public function test_erase_runs_removes_rows(): void {
		$email = 'gdpr-erase-runs@example.com';
		$user  = self::factory()->user->create( array( 'user_email' => $email ) );

		$this->insert_run( $user, 'run-a' );
		$this->insert_run( $user, 'run-b' );

		$result = GDPR::erase_runs( $email );

		$this->assertSame( 2, $result['items_removed'] );
	}

	/**
	 * An unknown email yields a no-op erasure (nothing removed, done).
	 */
	public function test_eraser_unknown_email_is_noop(): void {
		$this->assertSame( 0, GDPR::erase_notifications( 'ghost@example.com' )['items_removed'] );
		$this->assertSame( 0, GDPR::erase_runs( 'ghost@example.com' )['items_removed'] );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Insert a background-run row for a user directly against the runs table.
	 *
	 * @param int    $user_id User id.
	 * @param string $run_id  Unique run id.
	 */
	private function insert_run( int $user_id, string $run_id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Test fixture against the custom table.
		$wpdb->insert(
			$wpdb->prefix . 'agent_builder_runs',
			array(
				'run_id'         => $run_id,
				'root_agent'     => 'test-agent',
				'kind'           => 'task',
				'status'         => 'finished',
				'user_id'        => $user_id,
				'task_text'      => 'Do a thing',
				'result_summary' => 'Done',
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);
	}
}
