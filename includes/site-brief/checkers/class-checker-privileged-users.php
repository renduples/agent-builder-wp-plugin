<?php
/**
 * Site Brief checker: administrator count.
 *
 * @package    Agent_Builder
 * @subpackage Site_Brief
 * @since      3.4.2
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic\Site_Brief;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Informational card when more administrators exist than expected.
 */
class Checker_Privileged_Users extends Site_Brief_Checker {

	/**
	 * Expected administrator count. Above this is informational only.
	 */
	public const EXPECTED_MAX = 2;

	/**
	 * {@inheritdoc}
	 */
	public function get_id(): string {
		return 'privileged_users';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_tools(): array {
		return array( 'list_privileged_users' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_agent(): string {
		return 'wordpress-assistant';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_severity(): int {
		return 15;
	}

	/**
	 * {@inheritdoc}
	 */
	public function run( Site_Brief_Runner $runner ): array {
		$result = $runner->observe_tool(
			'list_privileged_users',
			array( 'role' => 'administrator' )
		);
		if ( isset( $result['error'] ) ) {
			return array();
		}

		$total = (int) ( $result['total'] ?? 0 );
		if ( $total <= self::EXPECTED_MAX ) {
			return array();
		}

		$names = array();
		foreach ( array_slice( (array) ( $result['users'] ?? array() ), 0, 8 ) as $user ) {
			$login = (string) ( $user['user_login'] ?? '' );
			if ( '' !== $login ) {
				$names[] = $login;
			}
		}

		return array(
			$this->card(
				array(
					'title'            => sprintf(
						/* translators: %d: administrator count. */
						__( '%d administrator accounts', 'agent-builder' ),
						$total
					),
					'evidence'         => sprintf(
						/* translators: %s: comma-separated logins. */
						__( 'Accounts: %s. Review for unexpected administrators.', 'agent-builder' ),
						implode( ', ', $names )
					),
					'evidence_payload' => array(
						'total'  => $total,
						'logins' => $names,
					),
					'proposed_action'  => __( 'Open Users to review roles. Site Brief will not change accounts.', 'agent-builder' ),
					'action_risk'      => 'none',
					'raw'              => array( 'total' => $total ),
				)
			),
		);
	}
}
