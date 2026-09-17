<?php
/**
 * Security Log
 *
 * Dedicated logging for security events (blocked messages, rate limits, PII warnings).
 *
 * @package    Agent_Builder
 * @subpackage Includes
 * @author     Agent Builder Team <support@agentic-plugin.com>
 * @license    GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 * @link       https://agentic-plugin.com
 * @since      0.2.0
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Security logging for chat security events
 */
class Security_Log {

	/**
	 * Singleton instance
	 *
	 * @var Security_Log|null
	 */
	private static ?Security_Log $instance = null;

	/**
	 * Table name constant
	 */
	private const TABLE_NAME = 'agent_builder_security_log';

	/**
	 * Get singleton instance
	 *
	 * @return Security_Log
	 */
	public static function get_instance(): Security_Log {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Get table name with prefix
	 *
	 * @return string
	 */
	private static function get_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_NAME;
	}

	/**
	 * Create security log table
	 */
	public static function create_table(): void {
		global $wpdb;

		$table_name      = self::get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			event_type varchar(50) NOT NULL,
			user_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
			ip_address varchar(64) NOT NULL,
			message text,
			pattern_matched varchar(255) DEFAULT NULL,
			pii_types varchar(255) DEFAULT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY (id),
			KEY idx_event_type (event_type),
			KEY idx_user_id (user_id),
			KEY idx_created (created_at),
			KEY idx_ip (ip_address)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Log a security event
	 *
	 * @param string $event_type      Event type (blocked, rate_limited, pii_warning).
	 * @param int    $user_id         User ID (0 for anonymous).
	 * @param string $ip_address      Client IP address.
	 * @param string $message         The message content (truncated for privacy).
	 * @param string $pattern_matched The pattern that triggered the event.
	 * @param array  $pii_types       PII types detected (for PII warnings).
	 * @return int|false Log entry ID or false on failure.
	 */
	public static function log(
		string $event_type,
		int $user_id,
		string $ip_address,
		string $message = '',
		string $pattern_matched = '',
		array $pii_types = array()
	): int|false {
		global $wpdb;

		// Truncate message for privacy/storage (store first 200 chars).
		$message_truncated = substr( $message, 0, 200 );

		// Anonymise IP if the GDPR setting is enabled.
		$stored_ip = get_option( 'agent_builder_ip_anonymize', true )
			? hash( 'sha256', $ip_address . wp_salt() )
			: $ip_address;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table insert.
		$result = $wpdb->insert(
			self::get_table_name(),
			array(
				'event_type'      => $event_type,
				'user_id'         => $user_id,
				'ip_address'      => $stored_ip,
				'message'         => $message_truncated,
				'pattern_matched' => substr( $pattern_matched, 0, 255 ),
				'pii_types'       => ! empty( $pii_types ) ? implode( ',', $pii_types ) : null,
				'created_at'      => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		return $result ? $wpdb->insert_id : false;
	}

	/**
	 * Log a system/administrative event (settings changes, agent lifecycle, etc.).
	 *
	 * Maps cleanly onto the security table without the IP/pattern fields.
	 *
	 * @param string $action      Action slug (e.g. 'settings_changed', 'agent_activated').
	 * @param string $target_type What was affected (e.g. 'security_settings', 'deployments').
	 * @param mixed  $details     Additional context (array or scalar).
	 * @return int|false Log entry ID or false on failure.
	 */
	public static function log_system(
		string $action,
		string $target_type = '',
		mixed $details = null
	): int|false {
		global $wpdb;

		$message = $target_type;
		if ( null !== $details ) {
			$encoded = is_array( $details ) || is_object( $details ) ? wp_json_encode( $details ) : (string) $details;
			if ( $encoded && '[]' !== $encoded && '{}' !== $encoded ) {
				$message = $target_type ? $target_type . ': ' . $encoded : $encoded;
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table insert.
		$result = $wpdb->insert(
			self::get_table_name(),
			array(
				'event_type'      => $action,
				'user_id'         => get_current_user_id(),
				'ip_address'      => '',
				'message'         => substr( $message, 0, 1000 ),
				'pattern_matched' => null,
				'pii_types'       => null,
				'created_at'      => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		return $result ? $wpdb->insert_id : false;
	}

	/**
	 * Get recent security events
	 *
	 * @param array $args Query arguments.
	 * @return array Security events.
	 */
	public static function get_events( array $args = array() ): array {
		global $wpdb;

		$defaults = array(
			'event_type' => '',
			'user_id'    => 0,
			'days'       => 0,
			'limit'      => 100,
			'offset'     => 0,
			'order'      => 'DESC',
		);

		$args = wp_parse_args( $args, $defaults );

		$where  = array();
		$params = array();

		if ( (int) $args['days'] > 0 ) {
			$where[]  = 'created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)';
			$params[] = (int) $args['days'];
		}

		if ( ! empty( $args['event_type'] ) ) {
			$where[]  = 'event_type = %s';
			$params[] = $args['event_type'];
		}

		if ( $args['user_id'] > 0 ) {
			$where[]  = 'user_id = %d';
			$params[] = $args['user_id'];
		}

		$where_clause = ! empty( $where ) ? 'WHERE ' . implode( ' AND ', $where ) : '';
		$order        = in_array( strtoupper( $args['order'] ), array( 'ASC', 'DESC' ), true ) ? $args['order'] : 'DESC';

		$params[] = (int) $args['limit'];
		$params[] = (int) $args['offset'];

		$table = self::get_table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from constant + prefix.
		$query = "SELECT * FROM {$table} {$where_clause} ORDER BY created_at {$order} LIMIT %d OFFSET %d";

		if ( empty( $params ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table query.
			return $wpdb->get_results( $query, ARRAY_A );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table query, safe parameters.
		return $wpdb->get_results( $wpdb->prepare( $query, $params ), ARRAY_A );
	}

	/**
	 * Get event count
	 *
	 * @param array $args Query arguments.
	 * @return int Total events.
	 */
	public static function get_count( array $args = array() ): int {
		global $wpdb;

		$where  = array();
		$params = array();

		if ( (int) ( $args['days'] ?? 0 ) > 0 ) {
			$where[]  = 'created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)';
			$params[] = (int) $args['days'];
		}

		if ( ! empty( $args['event_type'] ) ) {
			$where[]  = 'event_type = %s';
			$params[] = $args['event_type'];
		}

		if ( ! empty( $args['user_id'] ) ) {
			$where[]  = 'user_id = %d';
			$params[] = $args['user_id'];
		}

		$where_clause = ! empty( $where ) ? 'WHERE ' . implode( ' AND ', $where ) : '';
		$table        = self::get_table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from constant + prefix.
		$query = "SELECT COUNT(*) FROM {$table} {$where_clause}";

		if ( empty( $params ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table query.
			return (int) $wpdb->get_var( $query );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table query, safe parameters.
		return (int) $wpdb->get_var( $wpdb->prepare( $query, $params ) );
	}

	/**
	 * Get security statistics
	 *
	 * @param int $days Number of days to analyze.
	 * @return array Statistics.
	 */
	public static function get_stats( int $days = 7 ): array {
		global $wpdb;

		$table = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table stats.
		$stats = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) as total_events, SUM(CASE WHEN event_type = 'blocked' THEN 1 ELSE 0 END) as blocked_count, SUM(CASE WHEN event_type = 'rate_limited' THEN 1 ELSE 0 END) as rate_limited_count, SUM(CASE WHEN event_type = 'pii_warning' THEN 1 ELSE 0 END) as pii_warning_count, COUNT(DISTINCT ip_address) as unique_ips, COUNT(DISTINCT user_id) as unique_users FROM {$table} WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$days
			),
			ARRAY_A
		);

		return $stats ?? array(
			'total_events'       => 0,
			'blocked_count'      => 0,
			'rate_limited_count' => 0,
			'pii_warning_count'  => 0,
			'unique_ips'         => 0,
			'unique_users'       => 0,
		);
	}

	/**
	 * Clean up old log entries
	 *
	 * @param int $days Entries older than this will be deleted (default 30 days).
	 * @return int Number of deleted entries.
	 */
	public static function cleanup( int $days = 30 ): int {
		global $wpdb;

		$table = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table cleanup.
		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$days
			)
		);
	}

	/**
	 * Get top blocked patterns
	 *
	 * @param int $limit Number of patterns to return.
	 * @param int $days  Number of days to analyze.
	 * @return array Top patterns.
	 */
	public static function get_top_patterns( int $limit = 10, int $days = 7 ): array {
		global $wpdb;

		$table = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table query.
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pattern_matched, COUNT(*) as count FROM {$table} WHERE event_type = 'blocked' AND pattern_matched IS NOT NULL AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY) GROUP BY pattern_matched ORDER BY count DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$days,
				$limit
			),
			ARRAY_A
		);
	}

	/**
	 * Get top offending IPs
	 *
	 * @param int $limit Number of IPs to return.
	 * @param int $days  Number of days to analyze.
	 * @return array Top IPs.
	 */
	public static function get_top_ips( int $limit = 10, int $days = 7 ): array {
		global $wpdb;

		$table = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table query.
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ip_address, COUNT(*) as event_count FROM {$table} WHERE event_type IN ('blocked', 'rate_limited') AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY) GROUP BY ip_address ORDER BY event_count DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$days,
				$limit
			),
			ARRAY_A
		);
	}
}
