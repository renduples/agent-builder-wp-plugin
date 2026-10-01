<?php
/**
 * Notifications system.
 *
 * Inbox-backed notifications for run results, routine failures and approvals,
 * with an optional daily email digest for administrators and instant email for
 * run results and approvals.
 *
 * @package    Agent_Builder
 * @subpackage Includes
 * @since      4.1.0
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Inbox notifications + email delivery.
 *
 * Rows live in wp_agent_builder_notifications; the admin-bar inbox and (later)
 * the Tasks screen read them via unread_count()/list()/mark_read(). Email
 * delivery has two paths: an immediate email for run results and approvals
 * (instant mode) and a batched daily digest sent to every non-opted-out
 * administrator.
 */
class Notifications {

	/**
	 * Valid notification types.
	 */
	public const TYPES = array(
		'run_finished',
		'run_waiting',
		'run_error',
		'routine_failed',
		'approval_pending',
	);

	/**
	 * Types eligible for an immediate email when instant mode is on.
	 *
	 * `approval_pending` is included deliberately: an approval pause is exactly
	 * the moment the recipient must act, and in instant mode there is no daily
	 * digest to batch it into, so it has to email here or be silently dropped.
	 */
	private const INSTANT_TYPES = array( 'run_finished', 'run_waiting', 'run_error', 'approval_pending' );

	/**
	 * Notification email mode option key (off|instant|daily).
	 */
	private const EMAIL_OPTION = 'agent_builder_notify_email';

	/**
	 * Per-user instant-email cooldown option-key prefix.
	 *
	 * Stored as a plain wp_options row (claimed atomically via a duplicate-key
	 * INSERT — see claim_instant_email_cooldown()) rather than a transient,
	 * because a transient's read-then-set leaves a window in which two
	 * concurrent notify() calls for the same user can both observe the cooldown
	 * unset and both send.
	 */
	private const COOLDOWN_PREFIX = 'agentic_notification_email_cooldown_';

	/**
	 * Daily digest cron hook name.
	 */
	private const DIGEST_HOOK = 'agent_builder_notification_digest';

	/**
	 * Per-user opt-out user-meta key.
	 */
	public const OPTOUT_META = 'agent_builder_notify_optout';

	/**
	 * Nonce action for the profile-screen opt-out checkbox.
	 */
	private const OPTOUT_NONCE = 'agent_builder_notify_optout_nonce';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		// Schedule the digest if it is not already scheduled (safety net for
		// sites that upgraded without re-firing the activation hook).
		add_action( 'init', array( __CLASS__, 'maybe_schedule_digest' ) );

		// Per-user opt-out checkbox on the profile screen (own profile + others
		// an administrator edits), with a nonce on the save path.
		add_action( 'show_user_profile', array( __CLASS__, 'render_profile_optout' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'render_profile_optout' ) );
		add_action( 'personal_options_update', array( __CLASS__, 'save_profile_optout' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save_profile_optout' ) );

		// Run-lifecycle listeners: notify the run owner when a background run
		// finishes, errors, or pauses on them (the "comes back to you" promise).
		add_action( 'agent_builder_run_finished', array( __CLASS__, 'on_run_finished' ), 10, 1 );
		add_action( 'agent_builder_run_waiting', array( __CLASS__, 'on_run_waiting' ), 10, 1 );
	}

	/**
	 * Schedule the daily digest cron event if it is not already scheduled.
	 *
	 * @return void
	 */
	public static function maybe_schedule_digest(): void {
		// Never (re)schedule while safe mode is on: the digest is background
		// work, and safe mode is the escape hatch that disables all of it. A
		// separate admin_init cleanup clears the hook when safe mode flips on,
		// but that hook only fires on admin requests — this check must live
		// here too so a cron/REST/frontend hit can't silently re-schedule it.
		if ( Activator::is_safe_mode() ) {
			return;
		}

		if ( ! wp_next_scheduled( self::DIGEST_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::DIGEST_HOOK );
		}
	}

	/**
	 * Insert a notification row and (optionally) send an immediate email.
	 *
	 * @param int    $user_id Owning user id.
	 * @param string $type    One of self::TYPES.
	 * @param string $title   Short notification title.
	 * @param string $body    Plain-text notification body.
	 * @param array  $extra   Optional: link, run_id, agent_id, severity.
	 * @return int New row id.
	 */
	public static function notify( int $user_id, string $type, string $title, string $body, array $extra = array() ): int {
		global $wpdb;

		$table = $wpdb->prefix . 'agent_builder_notifications';

		$data = array(
			'user_id'    => $user_id,
			'type'       => $type,
			'severity'   => $extra['severity'] ?? 'info',
			'title'      => $title,
			'body'       => $body,
			'link'       => $extra['link'] ?? '',
			'run_id'     => $extra['run_id'] ?? null,
			'agent_id'   => $extra['agent_id'] ?? null,
			'created_at' => gmdate( 'Y-m-d H:i:s' ),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table insert.
		$wpdb->insert( $table, $data );

		$id = (int) $wpdb->insert_id;

		self::maybe_send_instant_email( $id, $user_id, $type, $title, $body, $extra );

		return $id;
	}

	/**
	 * Listener on `agent_builder_run_finished` — notify the run owner that a
	 * background run finished (success or cancel) or failed, unless the run is a
	 * chat run (which has no owner-facing "come back to you" expectation).
	 *
	 * The body is the first line of the run's result text, or its error — so the
	 * notification reads as a one-line summary, not a transcript dump.
	 *
	 * @param Agent_Run $run The finished run.
	 * @return void
	 */
	public static function on_run_finished( Agent_Run $run ): void {
		$user_id = (int) $run->get_user_id();
		if ( $user_id <= 0 || 'chat' === $run->get_kind() ) {
			return;
		}

		$data   = $run->to_array();
		$status = $run->get_status();

		if ( in_array( $status, array( 'failed', 'error', 'aborted' ), true ) ) {
			$error = (string) ( $data['error'] ?? '' );
			$body  = self::first_line( '' !== $error ? $error : (string) ( $data['result_summary']['text'] ?? '' ) );
			if ( '' === $body ) {
				$body = __( 'Your agent run failed.', 'agent-builder' );
			}

			self::notify(
				$user_id,
				'run_error',
				__( 'Agent run failed', 'agent-builder' ),
				$body,
				array(
					'link'     => self::tasks_link( $run ),
					'run_id'   => $run->get_run_id(),
					'agent_id' => $run->get_root_agent(),
					'severity' => 'error',
				)
			);
			return;
		}

		$body = self::first_line( (string) ( $data['result_summary']['text'] ?? '' ) );
		self::notify(
			$user_id,
			'run_finished',
			__( 'Agent run finished', 'agent-builder' ),
			$body,
			array(
				'link'     => self::tasks_link( $run ),
				'run_id'   => $run->get_run_id(),
				'agent_id' => $run->get_root_agent(),
			)
		);
	}

	/**
	 * Listener on `agent_builder_run_waiting` — notify the run owner that a
	 * background run has paused on an approval/proposal and needs their input.
	 *
	 * @param Agent_Run $run The run that just entered 'waiting'.
	 * @return void
	 */
	public static function on_run_waiting( Agent_Run $run ): void {
		$user_id = (int) $run->get_user_id();
		if ( $user_id <= 0 || 'chat' === $run->get_kind() ) {
			return;
		}

		self::notify(
			$user_id,
			'run_waiting',
			__( 'Agent run needs you', 'agent-builder' ),
			__( 'Your agent run is waiting for your approval.', 'agent-builder' ),
			array(
				'link'     => self::tasks_link( $run ),
				'run_id'   => $run->get_run_id(),
				'agent_id' => $run->get_root_agent(),
				'severity' => 'warning',
			)
		);
	}

	/**
	 * Deep link to the Tasks drawer for a specific run.
	 *
	 * @param Agent_Run $run Run to link to.
	 * @return string Admin URL with the run deep-link query arg.
	 */
	private static function tasks_link( Agent_Run $run ): string {
		return add_query_arg(
			array(
				'page' => 'agentic-tasks',
				'run'  => $run->get_run_id(),
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * First non-empty line of a (possibly multi-line) string.
	 *
	 * @param string $text Text to trim to its first line.
	 * @return string First line, or '' when the text is empty.
	 */
	private static function first_line( string $text ): string {
		$text = trim( $text );
		if ( '' === $text ) {
			return '';
		}

		$lines = preg_split( '/\r\n|\r|\n/', $text );
		return trim( (string) $lines[0] );
	}

	/**
	 * Count unread notifications for a user.
	 *
	 * @param int $user_id Owning user id.
	 * @return int Number of unread notifications.
	 */
	public static function unread_count( int $user_id ): int {
		global $wpdb;

		$table = $wpdb->prefix . 'agent_builder_notifications';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table count.
		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE user_id = %d AND read_at IS NULL',
				$table,
				$user_id
			)
		);

		return (int) $count;
	}

	/**
	 * List notifications for a user, newest first.
	 *
	 * @param int  $user_id     Owning user id.
	 * @param bool $unread_only Only return rows with read_at IS NULL.
	 * @param int  $per_page    Page size.
	 * @param int  $page        1-based page number.
	 * @return array Array of notification rows (associative).
	 */
	public static function list( int $user_id, bool $unread_only = false, int $per_page = 20, int $page = 1 ): array {
		global $wpdb;

		$table    = $wpdb->prefix . 'agent_builder_notifications';
		$per_page = max( 1, $per_page );
		$page     = max( 1, $page );
		$offset   = ( $page - 1 ) * $per_page;

		if ( $unread_only ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table read.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE user_id = %d AND read_at IS NULL ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d',
					$table,
					$user_id,
					$per_page,
					$offset
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table read.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE user_id = %d ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d',
					$table,
					$user_id,
					$per_page,
					$offset
				),
				ARRAY_A
			);
		}

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Mark notifications read for a user.
	 *
	 * @param int   $user_id Owning user id.
	 * @param array $ids     Row ids to mark; empty means "all unread".
	 * @return void
	 */
	public static function mark_read( int $user_id, array $ids = array() ): void {
		global $wpdb;

		$table = $wpdb->prefix . 'agent_builder_notifications';
		$now   = gmdate( 'Y-m-d H:i:s' );

		if ( empty( $ids ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table update.
			$wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET read_at = %s WHERE user_id = %d AND read_at IS NULL',
					$table,
					$now,
					$user_id
				)
			);
			return;
		}

		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
		if ( empty( $ids ) ) {
			return;
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$args         = array_merge( array( $table, $now, $user_id ), $ids );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table update.
		$wpdb->query(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Dynamic IN (%d…) count matches $ids; table %i + read_at %s + user_id %d via $args.
			$wpdb->prepare(
				"UPDATE %i SET read_at = %s WHERE user_id = %d AND id IN ({$placeholders}) AND read_at IS NULL", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $placeholders is only %d tokens; $args is table+timestamp+user+ids.
				...$args // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
			)
		);
	}

	/**
	 * Daily digest cron callback: email every non-opted-out administrator a
	 * summary of their un-emailed notifications.
	 *
	 * @return void
	 */
	public static function send_daily_digest(): void {
		// The digest runs only in "daily" mode. In "instant" mode each run email
		// is sent (and marked emailed) as it happens, so there is nothing left to
		// batch; "off" disables all notification email. Running the digest in
		// instant mode would re-email rows instant mode already sent.
		if ( 'daily' !== (string) get_option( self::EMAIL_OPTION, 'daily' ) ) {
			return;
		}

		$admins = get_users( array( 'role__in' => array( 'administrator' ) ) );

		foreach ( $admins as $admin ) {
			if ( self::is_opted_out( (int) $admin->ID ) ) {
				continue;
			}
			self::send_digest_for_user( (int) $admin->ID );
		}
	}

	/**
	 * Send the digest for a single administrator, then mark the rows emailed.
	 *
	 * @param int $user_id Administrator user id.
	 * @return void
	 */
	private static function send_digest_for_user( int $user_id ): void {
		global $wpdb;

		$table = $wpdb->prefix . 'agent_builder_notifications';

		// Validate the recipient *before* claiming any rows: a bad (missing or
		// malformed) address must not stamp rows as emailed and then bail,
		// silently dropping them from every future digest.
		$user = get_userdata( $user_id );
		if ( ! $user || ! is_email( $user->user_email ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table read.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE user_id = %d AND emailed_at IS NULL ORDER BY created_at ASC, id ASC LIMIT %d',
				$table,
				$user_id,
				50
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return;
		}

		// Claim these rows *before* composing or sending, so two overlapping
		// digest invocations for the same user (WP-Cron's double-fire, or a
		// system cron racing a request-triggered spawn_cron()) cannot both email
		// the same rows. The `emailed_at IS NULL` guard makes the claim atomic
		// per row: only rows still un-emailed are taken, and the affected-row
		// count below is how many this invocation actually owns.
		$ids          = array_map( 'absint', array_column( $rows, 'id' ) );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$claimed_at   = gmdate( 'Y-m-d H:i:s' );
		$claim_args   = array_merge( array( $table, $claimed_at ), $ids );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table update.
		$claimed = (int) $wpdb->query(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Dynamic IN (%d…) count matches $ids; table %i + emailed_at %s via $claim_args.
			$wpdb->prepare(
				"UPDATE %i SET emailed_at = %s WHERE id IN ({$placeholders}) AND emailed_at IS NULL", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $placeholders is only %d tokens; $claim_args is table+timestamp+ids.
				...$claim_args // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
			)
		);

		// A concurrent invocation already emailed every candidate row.
		if ( 0 === $claimed ) {
			return;
		}

		// Re-read only the rows this invocation actually claimed (a concurrent
		// run may have taken a subset in the meantime), so the email never
		// repeats a row another invocation already sent.
		$select_args = array_merge( array( $table ), $ids, array( $claimed_at ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table read.
		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Dynamic IN (%d…) count matches $ids; table %i + emailed_at %s via $select_args.
			$wpdb->prepare(
				"SELECT * FROM %i WHERE id IN ({$placeholders}) AND emailed_at = %s ORDER BY created_at ASC, id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $placeholders is only %d tokens; $select_args is table+timestamp+ids.
				...$select_args // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return;
		}

		$subject = sprintf(
			/* translators: %s: site name */
			__( '[%s] Daily activity digest', 'agent-builder' ),
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
		);

		$body  = '<p style="margin:0 0 12px;">' . esc_html__( "Here's what your agents did recently:", 'agent-builder' ) . '</p>';
		$body .= '<ul style="margin:0 0 16px;padding-left:18px;">';
		foreach ( $rows as $row ) {
			$body .= '<li style="margin:0 0 8px;"><strong>' . esc_html( (string) $row['title'] ) . '</strong>';
			if ( '' !== (string) $row['body'] ) {
				$body .= ' &mdash; ' . esc_html( (string) $row['body'] );
			}
			if ( '' !== (string) $row['link'] ) {
				$body .= ' <a href="' . esc_url( (string) $row['link'] ) . '">' . esc_html__( 'View', 'agent-builder' ) . '</a>';
			}
			$body .= '</li>';
		}
		$body .= '</ul>';

		$tasks_url = admin_url( 'admin.php?page=agentic-tasks' );
		$body     .= '<p style="margin:0 0 12px;"><a href="' . esc_url( $tasks_url ) . '">' . esc_html__( 'Open your Tasks screen', 'agent-builder' ) . '</a></p>';

		$sent = (bool) Email_Helper::send(
			$user->user_email,
			$subject,
			array(
				'heading' => __( 'Activity digest', 'agent-builder' ),
				'body'    => $body,
				'footer'  => self::email_footer(),
			)
		);

		if ( $sent ) {
			return;
		}

		// Release the claim so a transient send failure is retried by the next
		// digest rather than silently dropping these rows.
		$release_args = array_merge( array( $table ), $ids, array( $claimed_at ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table update.
		$wpdb->query(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Dynamic IN (%d…) count matches $ids; table %i + emailed_at %s via $release_args.
			$wpdb->prepare(
				"UPDATE %i SET emailed_at = NULL WHERE id IN ({$placeholders}) AND emailed_at = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $placeholders is only %d tokens; $release_args is table+ids+timestamp.
				...$release_args // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
			)
		);
	}

	/**
	 * Send an immediate email for a run-related notification when instant mode
	 * is on and the per-user cooldown has elapsed.
	 *
	 * @param int    $id      Notification row id.
	 * @param int    $user_id Owning user id.
	 * @param string $type    Notification type.
	 * @param string $title   Notification title.
	 * @param string $body    Notification body.
	 * @param array  $extra   Extra fields from notify().
	 * @return void
	 */
	private static function maybe_send_instant_email( int $id, int $user_id, string $type, string $title, string $body, array $extra ): void {
		if ( ! in_array( $type, self::INSTANT_TYPES, true ) ) {
			return;
		}

		$mode = (string) get_option( self::EMAIL_OPTION, 'daily' );
		if ( 'instant' !== $mode ) {
			return;
		}

		// Per-user opt-out must override the site-wide mode: an opted-out user
		// receives no instant email even when the site default is instant.
		if ( self::is_opted_out( $user_id ) ) {
			return;
		}

		$user = get_userdata( $user_id );
		if ( ! $user || ! is_email( $user->user_email ) ) {
			return;
		}

		// Claim the 5-minute cooldown atomically *before* composing or sending,
		// so two notify() calls for the same user racing in the same instant
		// cannot both observe an unset cooldown and both send an instant email.
		// Only the request whose claim wins proceeds (see
		// claim_instant_email_cooldown()).
		if ( ! self::claim_instant_email_cooldown( $user_id ) ) {
			return;
		}

		$subject = sprintf(
			/* translators: 1: site name, 2: notification title */
			__( '[%1$s] %2$s', 'agent-builder' ),
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			$title
		);

		$html = '<p style="margin:0 0 12px;">' . esc_html( $body ) . '</p>';
		if ( ! empty( $extra['link'] ) ) {
			$html .= Email_Helper::button( $extra['link'], __( 'View', 'agent-builder' ) );
		}

		$sent = (bool) Email_Helper::send(
			$user->user_email,
			$subject,
			array(
				'heading' => $title,
				'body'    => $html,
				'footer'  => self::email_footer(),
			)
		);

		if ( $sent ) {
			self::mark_emailed( $id );
		}
	}

	/**
	 * Atomically claim the per-user instant-email cooldown.
	 *
	 * The cooldown was previously a transient read-then-set: two notify() calls
	 * for the same user racing in the same instant could both read the
	 * transient unset before either armed it, so both sent an instant email.
	 * Claiming here — before the email is composed or sent — closes that window
	 * with a single duplicate-key INSERT (the same shape as
	 * Activator::acquire_upgrade_lock()): only the request whose INSERT lands
	 * owns the cooldown; everyone else's fails on wp_options' unique key on
	 * option_name and backs off.
	 *
	 * @param int $user_id Owning user id.
	 * @return bool True if this call won the cooldown and may send; false if a
	 *              send within the 5-minute window already owns it.
	 */
	private static function claim_instant_email_cooldown( int $user_id ): bool {
		global $wpdb;

		$key    = self::COOLDOWN_PREFIX . $user_id;
		$expiry = time() + 5 * MINUTE_IN_SECONDS;

		// Atomic acquire: a duplicate-key INSERT fails, which is the "someone
		// else already owns the cooldown" signal. Suppress the expected
		// duplicate-key error so it never reaches the log.
		$suppressed = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Raw write: this is a cooldown row, not an option read through the options API.
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				$key,
				(string) $expiry
			)
		);
		$wpdb->suppress_errors( $suppressed );

		if ( $inserted ) {
			return true;
		}

		// The row already exists. Read it directly, bypassing get_option() so
		// the alloptions cache can't serve a stale copy of a raw-written row.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Raw read: get_option() may serve a stale cached value for a raw-written row.
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
				$key
			)
		);

		if ( null === $existing ) {
			// The row vanished between our failed INSERT and this read. Retry the
			// INSERT once; if it still fails, someone else won it.
			$suppressed = $wpdb->suppress_errors( true );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Raw write.
			$inserted = $wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
					$key,
					(string) $expiry
				)
			);
			$wpdb->suppress_errors( $suppressed );

			return (bool) $inserted;
		}

		// Live cooldown — another send within the window already owns it.
		if ( time() < (int) $existing ) {
			return false;
		}

		// Stale cooldown: compare-and-swap takeover. The WHERE clause pins the
		// exact value just read, so the UPDATE only lands if nobody else took the
		// row over first (0 affected rows = lost the race).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Raw write.
		$taken = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				(string) $expiry,
				$key,
				$existing
			)
		);

		return 1 === (int) $taken;
	}

	/**
	 * Stamp a notification row as emailed.
	 *
	 * @param int $id Notification row id.
	 * @return void
	 */
	private static function mark_emailed( int $id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table update.
		$wpdb->update(
			$wpdb->prefix . 'agent_builder_notifications',
			array( 'emailed_at' => gmdate( 'Y-m-d H:i:s' ) ),
			array( 'id' => $id )
		);
	}

	/**
	 * Whether a user has opted out of notification emails.
	 *
	 * @param int $user_id User id.
	 * @return bool
	 */
	public static function is_opted_out( int $user_id ): bool {
		return (bool) get_user_meta( $user_id, self::OPTOUT_META, true );
	}

	/**
	 * Render the per-user opt-out checkbox on the profile screen.
	 *
	 * Shown on both the current user's own profile ("show_user_profile") and an
	 * administrator editing someone else's ("edit_user_profile").
	 *
	 * @param \WP_User $user User being edited.
	 * @return void
	 */
	public static function render_profile_optout( \WP_User $user ): void {
		wp_nonce_field( self::OPTOUT_NONCE, self::OPTOUT_NONCE . '_field' );

		$opted = self::is_opted_out( (int) $user->ID );
		?>
		<h2><?php esc_html_e( 'Agent activity email', 'agent-builder' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Email notifications', 'agent-builder' ); ?></th>
				<td>
					<label for="agent_builder_notify_optout">
						<input type="checkbox" name="agent_builder_notify_optout" id="agent_builder_notify_optout" value="1" <?php checked( $opted ); ?> />
						<?php esc_html_e( "Don't email me agent activity", 'agent-builder' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Turns off agent activity emails for this account, regardless of the site-wide setting.', 'agent-builder' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Save the per-user opt-out checkbox on profile update.
	 *
	 * @param int $user_id User being saved.
	 * @return void
	 */
	public static function save_profile_optout( int $user_id ): void {
		if ( ! isset( $_POST[ self::OPTOUT_NONCE . '_field' ] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::OPTOUT_NONCE . '_field' ] ) ), self::OPTOUT_NONCE ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		$optout = isset( $_POST['agent_builder_notify_optout'] ) ? '1' : '0';
		update_user_meta( $user_id, self::OPTOUT_META, $optout );
	}

	/**
	 * Standard email footer telling recipients how to turn notifications off.
	 *
	 * Links both the site-wide Settings → Security control (mode) and the
	 * per-user profile opt-out, so an administrator reading an email always has
	 * a one-click path to silence them (Renier's §11 decision).
	 *
	 * @return string
	 */
	private static function email_footer(): string {
		return sprintf(
			/* translators: 1: Settings → Security admin URL, 2: user profile admin URL */
			__( 'You are receiving these notifications as a site administrator. Manage the site-wide setting under Settings → Security: %1$s, or turn off email for your own account on your profile: %2$s', 'agent-builder' ),
			admin_url( 'admin.php?page=agentic-settings&tab=security' ),
			admin_url( 'profile.php' )
		);
	}
}
