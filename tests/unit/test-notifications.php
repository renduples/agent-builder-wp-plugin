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

use Agentic\Agent_Run;
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test cleanup of cooldown rows (transient leftovers + the atomic-claim option row); underscores escaped for LIKE.
		$wpdb->query(
			"DELETE FROM {$wpdb->options}
			WHERE option_name LIKE '\_transient\_agentic\_notification\_email\_cooldown\_%'
			   OR option_name LIKE '\_transient\_timeout\_agentic\_notification\_email\_cooldown\_%'
			   OR option_name LIKE 'agentic\_notification\_email\_cooldown\_%'"
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
	 * Invoke a private static Notifications method (mirrors the existing
	 * test-schema-upgrade seam: the cooldown claim stays private and is
	 * exercised through the same reflection path).
	 *
	 * @param string $method Method name.
	 * @param array  $args   Positional arguments.
	 * @return mixed
	 */
	private static function invoke_private( string $method, array $args = array() ) {
		$ref = new \ReflectionMethod( Notifications::class, $method );
		return $ref->invokeArgs( null, $args );
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
	 * The per-user opt-out checkbox renders on the profile screen.
	 */
	public function test_profile_optout_render_outputs_checkbox(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user    = get_userdata( $user_id );

		ob_start();
		Notifications::render_profile_optout( $user );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'agent_builder_notify_optout', $html );
	}

	/**
	 * Saving the profile opt-out with a valid nonce writes the meta.
	 */
	public function test_profile_optout_save_with_nonce_writes_meta(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$_POST['agent_builder_notify_optout_nonce_field'] = wp_create_nonce( 'agent_builder_notify_optout_nonce' );
		$_POST['agent_builder_notify_optout']             = '1';

		Notifications::save_profile_optout( $user_id );

		$this->assertSame( '1', get_user_meta( $user_id, 'agent_builder_notify_optout', true ) );

		unset( $_POST['agent_builder_notify_optout_nonce_field'], $_POST['agent_builder_notify_optout'] );
	}

	/**
	 * Saving without the nonce field is a no-op (the meta is left untouched).
	 */
	public function test_profile_optout_save_without_nonce_is_noop(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		Notifications::save_profile_optout( $user_id );

		$this->assertSame( '', get_user_meta( $user_id, 'agent_builder_notify_optout', true ) );
	}

	/**
	 * A user without edit_user capability cannot set someone else's opt-out.
	 */
	public function test_profile_optout_save_requires_capability(): void {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$other  = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $editor );

		$_POST['agent_builder_notify_optout_nonce_field'] = wp_create_nonce( 'agent_builder_notify_optout_nonce' );
		$_POST['agent_builder_notify_optout']             = '1';

		Notifications::save_profile_optout( $other );

		$this->assertSame( '', get_user_meta( $other, 'agent_builder_notify_optout', true ) );

		unset( $_POST['agent_builder_notify_optout_nonce_field'], $_POST['agent_builder_notify_optout'] );
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
	 * Two notify() calls for the same user racing in the same instant produce
	 * exactly one instant email: the per-user cooldown is claimed atomically
	 * before the email is composed, so the loser of the claim backs off instead
	 * of sending a duplicate.
	 *
	 * The race is simulated by intercepting the outer call's cooldown claim
	 * INSERT through the `query` filter and running a second notify() for the
	 * same user inside it — the concurrent call wins the claim (and sends),
	 * leaving the outer call's own INSERT to fail on the unique option_name key.
	 *
	 * The filter matches on the claim's option value (a leading quote followed
	 * by the cooldown key) rather than the bare prefix, so it only ever fires on
	 * the atomic-claim INSERT and never on a legacy `_transient_*` option name.
	 */
	public function test_concurrent_notifications_send_a_single_instant_email(): void {
		update_option( 'agent_builder_notify_email', 'instant' );
		reset_phpmailer_instance();

		$user_id = $this->make_admin( 'race-instant@example.com' );

		$armed = false;
		$racer = static function ( $query ) use ( &$armed, $user_id ) {
			if ( ! $armed && false !== stripos( (string) $query, "'agentic_notification_email_cooldown_" ) ) {
				$armed = true;
				// The concurrent process sends its own notification, claiming the
				// cooldown and sending the single email, before the outer claim
				// lands. ($armed stays true, so this callback cannot re-enter.)
				Notifications::notify( $user_id, 'run_finished', 'Concurrent', 'Concurrent run finished.' );
			}
			return $query;
		};
		add_filter( 'query', $racer );

		try {
			Notifications::notify( $user_id, 'run_finished', 'Outer', 'Outer run finished.' );
		} finally {
			remove_filter( 'query', $racer );
		}

		$this->assertTrue( $armed, 'the race must actually intercept the cooldown claim INSERT for this test to prove anything' );

		$mailer = tests_retrieve_phpmailer_instance();
		$this->assertCount( 1, $mailer->mock_sent, 'two concurrent notify() calls must produce exactly one instant email' );
		$this->assertContains( 'race-instant@example.com', $this->sent_recipients() );
	}

	/**
	 * A cooldown whose 5-minute window has elapsed is taken over by the next
	 * send via the compare-and-swap path, and the stale expiry is overwritten
	 * with a fresh one — so a cooldown never strands the user permanently.
	 */
	public function test_expired_instant_email_cooldown_is_taken_over(): void {
		$user_id = $this->make_admin( 'expired-instant@example.com' );

		global $wpdb;
		$key   = 'agentic_notification_email_cooldown_' . $user_id;
		$stale = (string) ( time() - 10 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Test seeds a stale cooldown row directly, mirroring the production INSERT.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				$key,
				$stale
			)
		);

		$claimed = self::invoke_private( 'claim_instant_email_cooldown', array( $user_id ) );
		$this->assertTrue( $claimed, 'an expired cooldown must be taken over' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Test assertion against the raw cooldown row.
		$stored = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key ) );
		$this->assertNotSame( $stale, $stored, 'the takeover must overwrite the stale expiry' );
		$this->assertGreaterThan( time(), (int) $stored, 'the new expiry must be in the future' );
	}

	/**
	 * An `approval_pending` notification under instant mode emails immediately.
	 *
	 * Regression for the M11 gate follow-up: approval pauses were excluded from
	 * the instant-email type set, so under instant mode an approval-needed
	 * notification was created but never emailed — no instant email (wrong type),
	 * and no digest (digest is a no-op in instant mode).
	 */
	public function test_instant_mode_emails_approval_pending(): void {
		update_option( 'agent_builder_notify_email', 'instant' );
		reset_phpmailer_instance();

		$user_id = $this->make_admin( 'approval-instant@example.com' );

		Notifications::notify( $user_id, 'approval_pending', 'Approval needed', 'Something needs you.' );

		$mailer = tests_retrieve_phpmailer_instance();
		$this->assertCount( 1, $mailer->mock_sent );
		$this->assertContains( 'approval-instant@example.com', $this->sent_recipients() );
	}

	/**
	 * Types outside the instant-email set never trigger an immediate email.
	 * `routine_failed` is the one type that is digest-eligible but not
	 * instant-eligible, so it is the right probe for the instant-type gate.
	 */
	public function test_instant_mode_ignores_routine_failed(): void {
		update_option( 'agent_builder_notify_email', 'instant' );
		reset_phpmailer_instance();

		$user_id = $this->make_admin( 'routine-admin@example.com' );

		Notifications::notify( $user_id, 'routine_failed', 'Routine failed', 'Something broke.' );

		$mailer = tests_retrieve_phpmailer_instance();
		$this->assertCount( 0, $mailer->mock_sent );
	}

	/**
	 * The daily digest is a no-op when the mode is "instant": instant mode emails
	 * (and marks emailed) run/approval notifications as they happen, so the digest
	 * must not batch anything — running it would re-email rows instant mode sent.
	 */
	public function test_digest_does_not_run_in_instant_mode(): void {
		update_option( 'agent_builder_notify_email', 'instant' );
		reset_phpmailer_instance();

		$admin = $this->make_admin( 'instant-digest@example.com' );

		// routine_failed is digest-eligible but never instant-eligible, so the only
		// thing that could email this row is the (disabled) daily digest.
		Notifications::notify( $admin, 'routine_failed', 'Routine failed', 'Something broke.' );

		Notifications::send_daily_digest();

		$mailer = tests_retrieve_phpmailer_instance();
		$this->assertCount( 0, $mailer->mock_sent );
	}

	/**
	 * Instant mode honours the per-user opt-out: an opted-out user receives no
	 * instant email even when the site-wide mode is instant.
	 */
	public function test_instant_mode_honours_optout(): void {
		update_option( 'agent_builder_notify_email', 'instant' );
		reset_phpmailer_instance();

		$user_id = $this->make_admin( 'optout-instant@example.com' );
		update_user_meta( $user_id, 'agent_builder_notify_optout', '1' );

		Notifications::notify( $user_id, 'run_finished', 'Run finished', 'Your run finished.' );

		$mailer = tests_retrieve_phpmailer_instance();
		$this->assertCount( 0, $mailer->mock_sent );
	}

	/**
	 * The digest validates the recipient before claiming rows: a bad (malformed)
	 * address returns early, so the rows stay un-emailed and are retried later
	 * rather than silently dropped.
	 */
	public function test_digest_skips_bad_recipient_without_claiming_rows(): void {
		reset_phpmailer_instance();

		$bad = self::factory()->user->create(
			array(
				'role'       => 'administrator',
				'user_email' => 'not-an-email',
			)
		);
		$id = Notifications::notify( $bad, 'run_finished', 'Digest', 'Body' );

		Notifications::send_daily_digest();

		$mailer = tests_retrieve_phpmailer_instance();
		$this->assertCount( 0, $mailer->mock_sent );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Test assertion against the custom table.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT emailed_at FROM %i WHERE id = %d', $wpdb->prefix . 'agent_builder_notifications', $id ), ARRAY_A );
		$this->assertNull( $row['emailed_at'], 'A bad recipient must not stamp rows emailed.' );
	}

	/**
	 * A finished background run notifies its owner with a run_finished row whose
	 * body is the first line of the result summary (the "comes back to you"
	 * promise for finished runs).
	 */
	public function test_on_run_finished_creates_run_finished_notification(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$run = Agent_Run::begin(
			'wordpress-assistant',
			array(
				'kind'      => 'task',
				'user_id'   => $admin_id,
				'task_text' => 'Summarise the posts.',
			)
		);
		$run->finish( 'completed', array( 'text' => "First line of result.\nSecond line." ) );

		$rows = Notifications::list( $admin_id );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'run_finished', $rows[0]['type'] );
		$this->assertSame( 'First line of result.', $rows[0]['body'] );
		$this->assertSame( $run->get_run_id(), $rows[0]['run_id'] );
	}

	/**
	 * A failed background run notifies its owner with a run_error row whose body
	 * is the first line of the error.
	 */
	public function test_on_run_finished_creates_run_error_notification_on_failure(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$run = Agent_Run::begin(
			'wordpress-assistant',
			array(
				'kind'      => 'task',
				'user_id'   => $admin_id,
				'task_text' => 'Publish a post.',
			)
		);
		$run->finish( 'failed', array( 'error' => "Run failed: the provider errored.\nRetry later." ) );

		$rows = Notifications::list( $admin_id );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'run_error', $rows[0]['type'] );
		$this->assertSame( 'Run failed: the provider errored.', $rows[0]['body'] );
		$this->assertSame( 'error', $rows[0]['severity'] );
	}

	/**
	 * A run that pauses on an approval notifies its owner with a run_waiting row.
	 */
	public function test_on_run_waiting_creates_run_waiting_notification(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$run = Agent_Run::begin(
			'wordpress-assistant',
			array(
				'kind'      => 'task',
				'user_id'   => $admin_id,
				'task_text' => 'Publish a post.',
			)
		);
		$run->mark_waiting( 'approval', '42', array(), 'call_abc' );

		$rows = Notifications::list( $admin_id );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'run_waiting', $rows[0]['type'] );
		$this->assertSame( 'warning', $rows[0]['severity'] );
		$this->assertSame( $run->get_run_id(), $rows[0]['run_id'] );
	}

	/**
	 * Chat runs have no owner-facing "come back to you" expectation, so neither
	 * finishing nor waiting on one produces a notification.
	 */
	public function test_chat_runs_do_not_notify(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$run = Agent_Run::begin(
			'wordpress-assistant',
			array(
				'kind'      => 'chat',
				'user_id'   => $admin_id,
				'task_text' => 'Hello.',
			)
		);
		$run->finish( 'completed', array( 'text' => 'Hi there.' ) );

		$this->assertCount( 0, Notifications::list( $admin_id ) );
	}
}
