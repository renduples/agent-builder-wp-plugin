<?php
/**
 * Notifications system.
 *
 * Inbox-backed notifications for run results, routine failures and approvals,
 * with an optional daily email digest for administrators and instant email for
 * run results.
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
 * delivery has two paths: an immediate email for run results (instant mode) and
 * a batched daily digest sent to every non-opted-out administrator.
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
	 */
	private const RUN_TYPES = array( 'run_finished', 'run_waiting', 'run_error' );

	/**
	 * Notification email mode option key (off|instant|daily).
	 */
	private const EMAIL_OPTION = 'agent_builder_notify_email';

	/**
	 * Per-user instant-email cooldown transient prefix.
	 */
	private const COOLDOWN_PREFIX = 'agentic_notification_email_cooldown_';

	/**
	 * Daily digest cron hook name.
	 */
	private const DIGEST_HOOK = 'agent_builder_notification_digest';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		// Schedule the digest if it is not already scheduled (safety net for
		// sites that upgraded without re-firing the activation hook).
		add_action( 'init', array( __CLASS__, 'maybe_schedule_digest' ) );
	}

	/**
	 * Schedule the daily digest cron event if it is not already scheduled.
	 *
	 * @return void
	 */
	public static function maybe_schedule_digest(): void {
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
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table update.
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table update.
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
		// "off" disables all notification email, including the digest.
		if ( 'off' === (string) get_option( self::EMAIL_OPTION, 'daily' ) ) {
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

		$user = get_userdata( $user_id );
		if ( ! $user || ! is_email( $user->user_email ) ) {
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

		if ( ! $sent ) {
			return;
		}

		$ids          = array_map( 'absint', array_column( $rows, 'id' ) );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$args         = array_merge( array( $table, gmdate( 'Y-m-d H:i:s' ) ), $ids );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table update.
		$wpdb->query(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Dynamic IN (%d…) count matches $ids; table %i + emailed_at %s via $args.
			$wpdb->prepare(
				"UPDATE %i SET emailed_at = %s WHERE id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $placeholders is only %d tokens; $args is table+timestamp+ids.
				...$args // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
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
		if ( ! in_array( $type, self::RUN_TYPES, true ) ) {
			return;
		}

		$mode = (string) get_option( self::EMAIL_OPTION, 'daily' );
		if ( 'instant' !== $mode ) {
			return;
		}

		$user = get_userdata( $user_id );
		if ( ! $user || ! is_email( $user->user_email ) ) {
			return;
		}

		$cooldown = self::COOLDOWN_PREFIX . $user_id;
		if ( get_transient( $cooldown ) ) {
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

		// Match the existing 5-minute-cooldown shape: always arm the cooldown
		// after an attempt so a burst of notifications cannot flood the inbox.
		set_transient( $cooldown, 1, 5 * MINUTE_IN_SECONDS );

		if ( $sent ) {
			self::mark_emailed( $id );
		}
	}

	/**
	 * Stamp a notification row as emailed.
	 *
	 * @param int $id Notification row id.
	 * @return void
	 */
	private static function mark_emailed( int $id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table update.
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
	private static function is_opted_out( int $user_id ): bool {
		return (bool) get_user_meta( $user_id, 'agent_builder_notify_optout', true );
	}

	/**
	 * Standard email footer telling recipients how to turn notifications off.
	 *
	 * @return string
	 */
	private static function email_footer(): string {
		return sprintf(
			/* translators: %s: Settings → Security admin URL */
			__( 'You are receiving these notifications as a site administrator. To manage or turn them off, go to Settings → Security: %s', 'agent-builder' ),
			admin_url( 'admin.php?page=agentic-settings&tab=security' )
		);
	}
}
