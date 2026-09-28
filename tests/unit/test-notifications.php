<?php
/**
 * Unit Tests for Notifications.
 *
 * Covers notify()/unread_count()/list()/mark_read(), the daily digest's
 * opt-out behaviour, and the instant-email cooldown.
 *
 * @package Agentic\Tests
 */

namespace Agentic\Tests;

use Agentic\Notifications;

/**
 * Test case for Notifications.
 */
class Test_Notifications extends TestCase {

	/**
	 * Previous email-mode option value, restored in tearDown.
	 *
	 * @var mixed
	 */
	private $previous_email_mode;

	/**
	 * Snapshot the email-mode option so a test that flips it to "instant" can't
	 * leak instant mode (and its armed cooldown) into a later test.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->previous_email_mode = get_option( 'agent_builder_notify_email', false );
	}

	/**
	 * Restore the email-mode option and clear any instant-email cooldown
	 * transients armed during the test.
	 */
	public function tearDown(): void {
		if ( false === $this->previous_email_mode ) {
			delete_option( 'agent_builder_notify_email' );
		} else {
			update_option( 'agent_builder_notify_email', $this->previous_email_mode );
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test cleanup of cooldown transients; underscores escaped for LIKE.
		$wpdb->query(
			"DELETE FROM {$wpdb->options}
			WHERE option_name LIKE '\_transient\_agentic\_notification\_email\_cooldown\_%'
			   OR option_name LIKE '\_transient\_timeout\_agentic\_notification\_email\_cooldown\_%'"
		);

		parent::tearDown();
	}

	/**
	 * Create an administrator user with a deterministic email.
	 *
	 * @param string $email Email address.
	 * @return int User id.
	 */
	private function make_admin( string $email ): int {
		return self::factory()->user->create(
			array(
				'role'       => 'administrator',
				'user_email' => $email,
			)
		);
	}

	/**
	 * Collect every recipient email from the mock mailer's sent queue.
	 *
	 * @return array List of recipient addresses.
	 */
	private function sent_recipients(): array {
		$mailer = tests_retrieve_phpmailer_instance();
		if ( ! $mailer || empty( $mailer->mock_sent ) ) {
			return array();
		}

		$recipients = array();
		foreach ( $mailer->mock_sent as $sent ) {
			foreach ( (array) $sent['to'] as $to ) {
				if ( is_array( $to ) ) {
					$recipients[] = (string) reset( $to );
				} else {
					$recipients[] = (string) $to;
				}
			}
		}
		return $recipients;
	}

	/**
	 * notify() inserts a row and returns its id.
	 */
	public function test_notify_inserts_row_and_returns_id(): void {
		$id = Notifications::notify( 7, 'run_finished', 'Run finished', 'Your run finished.', array( 'run_id' => 'abc-123' ) );

		$this->assertGreaterThan( 0, $id );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Test assertion against the custom table.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $wpdb->prefix . 'agent_builder_notifications', $id ), ARRAY_A );

		$this->assertSame( '7', $row['user_id'] );
		$this->assertSame( 'run_finished', $row['type'] );
		$this->assertSame( 'Run finished', $row['title'] );
		$this->assertSame( 'abc-123', $row['run_id'] );
		$this->assertNull( $row['read_at'] );
	}

	/**
	 * unread_count() only counts rows whose read_at is NULL.
	 */
	public function test_unread_count_counts_unread_only(): void {
		$id1 = Notifications::notify( 7, 'run_finished', 'One', '' );
		Notifications::notify( 7, 'run_error', 'Two', '' );

		$this->assertSame( 2, Notifications::unread_count( 7 ) );

		Notifications::mark_read( 7, array( $id1 ) );

		$this->assertSame( 1, Notifications::unread_count( 7 ) );
	}

	/**
	 * unread_count() is per-user.
	 */
	public function test_unread_count_is_scoped_to_user(): void {
		Notifications::notify( 7, 'run_finished', 'One', '' );
		Notifications::notify( 8, 'run_finished', 'Two', '' );

		$this->assertSame( 1, Notifications::unread_count( 7 ) );
		$this->assertSame( 1, Notifications::unread_count( 8 ) );
	}

	/**
	 * list() returns newest first and honours pagination.
	 */
	public function test_list_returns_newest_first_and_paginates(): void {
		$ids = array();
		foreach ( array( 'A', 'B', 'C' ) as $title ) {
			$ids[] = Notifications::notify( 7, 'run_finished', $title, '' );
		}

		$page1 = Notifications::list( 7, false, 2, 1 );
		$this->assertCount( 2, $page1 );
		// Newest first: 'C' then 'B'.
		$this->assertSame( 'C', $page1[0]['title'] );
		$this->assertSame( 'B', $page1[1]['title'] );

		$page2 = Notifications::list( 7, false, 2, 2 );
		$this->assertCount( 1, $page2 );
		$this->assertSame( 'A', $page2[0]['title'] );
	}

	/**
	 * list( unread_only ) excludes rows already read.
	 */
	public function test_list_unread_only(): void {
		$id = Notifications::notify( 7, 'run_finished', 'Unread', '' );
		Notifications::notify( 7, 'run_finished', 'Will read', '' );

		Notifications::mark_read( 7, array( $id ) );

		$unread = Notifications::list( 7, true );
		$this->assertCount( 1, $unread );
		$this->assertSame( 'Will read', $unread[0]['title'] );
	}

	/**
	 * mark_read() with an empty id list marks every unread row read.
	 */
	public function test_mark_read_empty_marks_all(): void {
		Notifications::notify( 7, 'run_finished', 'One', '' );
		Notifications::notify( 7, 'run_finished', 'Two', '' );

		Notifications::mark_read( 7 );

		$this->assertSame( 0, Notifications::unread_count( 7 ) );
	}

	/**
	 * mark_read() with ids only touches those ids for that user.
	 */
	public function test_mark_read_respects_user_scope(): void {
		$mine  = Notifications::notify( 7, 'run_finished', 'Mine', '' );
		$other = Notifications::notify( 8, 'run_finished', 'Other', '' );

		// Ask to mark "other"'s id read on behalf of user 7 — must be a no-op.
		Notifications::mark_read( 7, array( $other ) );

		$this->assertSame( 1, Notifications::unread_count( 7 ) );
		$this->assertSame( 1, Notifications::unread_count( 8 ) );

		Notifications::mark_read( 7, array( $mine ) );
		$this->assertSame( 0, Notifications::unread_count( 7 ) );
	}

	/**
	 * The daily digest emails only non-opted-out administrators.
	 */
	public function test_daily_digest_sends_only_to_non_opted_out_admins(): void {
		reset_phpmailer_instance();

		$admin_ok     = $this->make_admin( 'ok-admin@example.com' );
		$admin_optout = $this->make_admin( 'optout-admin@example.com' );
		$editor       = self::factory()->user->create(
			array(
				'role'       => 'editor',
				'user_email' => 'editor@example.com',
			)
		);

		update_user_meta( $admin_optout, 'agent_builder_notify_optout', '1' );

		Notifications::notify( $admin_ok, 'run_finished', 'Digest A', 'Body A' );
		Notifications::notify( $admin_optout, 'run_finished', 'Digest B', 'Body B' );
		Notifications::notify( $editor, 'run_finished', 'Digest C', 'Body C' );

		Notifications::send_daily_digest();

		$recipients = $this->sent_recipients();

		$this->assertContains( 'ok-admin@example.com', $recipients );
		$this->assertNotContains( 'optout-admin@example.com', $recipients );
		$this->assertNotContains( 'editor@example.com', $recipients );
	}

	/**
	 * The daily digest does not re-email notifications already emailed.
	 */
	public function test_daily_digest_does_not_resend_emailed(): void {
		reset_phpmailer_instance();

		$admin = $this->make_admin( 'digest-admin@example.com' );
		Notifications::notify( $admin, 'run_finished', 'First', '' );

		Notifications::send_daily_digest();
		Notifications::send_daily_digest();

		$mailer = tests_retrieve_phpmailer_instance();
		$this->assertCount( 1, $mailer->mock_sent );
	}

	/**
	 * Instant mode emails the run owner once per cooldown window.
	 */
	public function test_instant_mode_respects_cooldown(): void {
		update_option( 'agent_builder_notify_email', 'instant' );
		reset_phpmailer_instance();

		$user_id = $this->make_admin( 'instant-admin@example.com' );

		Notifications::notify( $user_id, 'run_finished', 'Run 1', 'First run finished.' );
		Notifications::notify( $user_id, 'run_finished', 'Run 2', 'Second run finished.' );

		$mailer = tests_retrieve_phpmailer_instance();
		$this->assertCount( 1, $mailer->mock_sent );
		$this->assertContains( 'instant-admin@example.com', $this->sent_recipients() );
	}

	/**
	 * Non-run types never trigger an instant email.
	 */
	public function test_instant_mode_ignores_non_run_types(): void {
		update_option( 'agent_builder_notify_email', 'instant' );
		reset_phpmailer_instance();

		$user_id = $this->make_admin( 'approval-admin@example.com' );

		Notifications::notify( $user_id, 'approval_pending', 'Approval needed', 'Something needs you.' );

		$mailer = tests_retrieve_phpmailer_instance();
		$this->assertCount( 0, $mailer->mock_sent );
	}
}
