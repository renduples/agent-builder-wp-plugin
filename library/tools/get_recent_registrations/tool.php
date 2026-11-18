<?php
declare(strict_types=1);

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get Recent Registrations Tool
 *
 * Lists recently registered users to detect bot registrations
 * or unauthorised signups.
 *
 * @package Agentic\Tools
 */
class Get_Recent_Registrations extends \Agentic\Tool_Base {

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'get_recent_registrations';
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return 'List recently registered users. Useful for detecting bot registrations or unauthorised signups.';
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
				'days'  => array(
					'type'        => 'integer',
					'description' => 'Look back this many days. Defaults to 7.',
				),
				'limit' => array(
					'type'        => 'integer',
					'description' => 'Max results (1–50). Defaults to 20.',
				),
			),
		);
	}

	/**
	 * Execute the recent registrations check.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array
	 */
	public function execute( array $arguments ): array {
		// Discloses other users' email addresses and roles — see
		// list_privileged_users' identical guard for why edit_posts alone
		// is not enough here.
		if ( ! current_user_can( 'list_users' ) ) {
			return array( 'error' => 'You do not have permission to view user accounts.' );
		}

		$days  = max( 1, (int) ( $arguments['days'] ?? 7 ) );
		$limit = min( max( (int) ( $arguments['limit'] ?? 20 ), 1 ), 50 );
		$since = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		$users = get_users(
			array(
				'date_query' => array(
					array(
						'after'  => $since,
						'column' => 'user_registered',
					),
				),
				'orderby'    => 'registered',
				'order'      => 'DESC',
				'number'     => $limit,
			)
		);

		return array(
			'period' => "last {$days} days",
			'total'  => count( $users ),
			'users'  => array_map(
				fn( $u ) => array(
					'id'         => (int) $u->ID,
					'login'      => $u->user_login,
					'email'      => $u->user_email,
					'roles'      => $u->roles,
					'registered' => $u->user_registered,
				),
				$users
			),
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
			'personal_data' => true,
		);
	}
}

return new Get_Recent_Registrations();
