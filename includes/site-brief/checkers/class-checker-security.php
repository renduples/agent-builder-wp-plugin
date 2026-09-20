<?php
/**
 * Site Brief checker: security overview flags.
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
 * Read-only card for flags get_security_overview already computes.
 */
class Checker_Security extends Site_Brief_Checker {

	/**
	 * {@inheritdoc}
	 */
	public function get_id(): string {
		return 'security';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_tools(): array {
		return array( 'get_security_overview' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_agent(): string {
		return 'site-health-sentinel';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_severity(): int {
		return 90;
	}

	/**
	 * {@inheritdoc}
	 */
	public function run( Site_Brief_Runner $runner ): array {
		$result = $runner->observe_tool( 'get_security_overview', array() );
		if ( isset( $result['error'] ) ) {
			return array();
		}

		$flags = array();
		foreach ( (array) ( $result['risk_flags'] ?? array() ) as $flag ) {
			$flag = is_string( $flag ) ? trim( $flag ) : '';
			if ( '' !== $flag ) {
				$flags[] = $flag;
			}
		}

		$failed = (int) ( $result['failed_logins_24h'] ?? 0 );
		if ( empty( $flags ) && $failed < 6 ) {
			return array();
		}

		if ( empty( $flags ) ) {
			$flags[] = sprintf(
				/* translators: %d: failed login count. */
				__( '%d failed logins in the last 24 hours.', 'agent-builder' ),
				$failed
			);
		}

		$level = (string) ( $result['risk_level'] ?? 'low' );

		return array(
			$this->card(
				array(
					'title'            => __( 'Security flags on this site', 'agent-builder' ),
					'evidence'         => implode( ' ', array_slice( $flags, 0, 3 ) ),
					'evidence_payload' => array(
						'flags'  => $flags,
						'failed' => $failed,
						'level'  => $level,
					),
					'proposed_action'  => __( 'Review failed logins and administrator accounts. No lock or reset is queued in this version.', 'agent-builder' ),
					'action_risk'      => 'none',
					'severity'         => 'high' === $level ? 95 : 90,
					'approve'          => array( 'type' => 'none' ),
					'raw'              => array(
						'failed_logins_24h' => $failed,
						'risk_level'        => $level,
					),
				)
			),
		);
	}
}
