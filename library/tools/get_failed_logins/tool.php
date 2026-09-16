<?php
declare(strict_types=1);

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get Failed Logins Tool
 *
 * Checks for failed login attempts in the site's security log,
 * grouped by IP address to identify brute-force patterns.
 *
 * @package Agentic\Tools
 */
class Get_Failed_Logins extends \Agentic\Tool_Base {

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'get_failed_logins';
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return 'Check for failed login attempts in the site\'s security log. Returns individual events grouped by IP address to identify brute-force patterns.';
	}

	/**
	 * Get the tool category.
	 *
	 * @return string
	 */
	public function get_category(): string {
		return 'security';
	}

	/**
	 * Get the tool parameters.
	 *
	 * @return array
	 */
	public function get_parameters(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'hours' => array(
					'type'        => 'integer',
					'description' => 'Look back this many hours. Defaults to 24.',
				),
				'limit' => array(
					'type'        => 'integer',
					'description' => 'Max individual events to return (1–100). Defaults to 50.',
				),
			),
		);
	}

	/**
	 * Execute the failed logins check.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array
	 */
	public function execute( array $arguments ): array {
		// Discloses IP addresses tied to login attempts — the tool-generic
		// edit_posts floor a caller might otherwise only need (e.g. a
		// Contributor over WebMCP) is nowhere near enough; see
		// get_security_overview's identical guard, which already calls this
		// tool internally only after passing the same check.
		if ( ! current_user_can( 'manage_options' ) ) {
			return array( 'error' => 'You do not have permission to view the security log.' );
		}

		global $wpdb;

		$hours = max( 1, (int) ( $arguments['hours'] ?? 24 ) );
		$limit = min( max( (int) ( $arguments['limit'] ?? 50 ), 1 ), 100 );
		$since = gmdate( 'Y-m-d H:i:s', time() - ( $hours * HOUR_IN_SECONDS ) );
		$table = $wpdb->prefix . 'agentic_security_log';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table.
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		if ( $table_exists ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT event_type, ip_address, message, created_at FROM %i WHERE event_type = %s AND created_at >= %s ORDER BY created_at DESC LIMIT %d',
					$table,
					'failed_login',
					$since,
					$limit
				),
				ARRAY_A
			);

			$by_ip = array();
			foreach ( $rows as $row ) {
				$ip           = $row['ip_address'];
				$by_ip[ $ip ] = ( $by_ip[ $ip ] ?? 0 ) + 1;
			}
			arsort( $by_ip );

			$top_ips = array();
			foreach ( $by_ip as $ip => $count ) {
				$top_ips[] = array(
					'ip'       => $ip,
					'attempts' => $count,
				);
			}

			return array(
				'source' => 'agentic_security_log',
				'period' => "last {$hours} hours",
				'total'  => count( $rows ),
				'by_ip'  => $top_ips,
				'events' => array_slice(
					array_map(
						fn( $r ) => array(
							'ip'        => $r['ip_address'],
							'timestamp' => $r['created_at'],
							'data'      => $r['message'],
						),
						$rows
					),
					0,
					$limit
				),
			);
		}

		$lockouts = get_transient( 'agentic_failed_login_ips' );

		return array(
			'source'   => 'transient_fallback',
			'note'     => 'Security log table not found. Activate Agent Builder security logging in Settings for full data.',
			'total'    => is_array( $lockouts ) ? count( $lockouts ) : 0,
			'lockouts' => is_array( $lockouts ) ? $lockouts : array(),
		);
	}

	/**
	 * Get the tool annotations.
	 *
	 * @return array
	 */
	public function get_annotations(): array {
		return array(
			'readonly' => true,
		);
	}
}

return new Get_Failed_Logins();
