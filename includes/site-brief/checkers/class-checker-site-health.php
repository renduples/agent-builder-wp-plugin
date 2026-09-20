<?php
/**
 * Site Brief checker: Site Health / run_health_check issues.
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
 * Cards for real health issues returned by run_health_check.
 */
class Checker_Site_Health extends Site_Brief_Checker {

	/**
	 * {@inheritdoc}
	 */
	public function get_id(): string {
		return 'site_health';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_tools(): array {
		return array( 'run_health_check' );
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
		return 70;
	}

	/**
	 * {@inheritdoc}
	 */
	public function run( Site_Brief_Runner $runner ): array {
		$result = $runner->observe_tool( 'run_health_check', array() );
		if ( isset( $result['error'] ) ) {
			return array();
		}

		$issues = array();
		foreach ( (array) ( $result['issues'] ?? array() ) as $issue ) {
			$issue = is_string( $issue ) ? trim( $issue ) : '';
			if ( '' !== $issue ) {
				$issues[] = $issue;
			}
		}

		if ( empty( $issues ) ) {
			return array();
		}

		$cards = array();
		foreach ( array_slice( $issues, 0, 5 ) as $index => $issue ) {
			$cards[] = $this->card(
				array(
					'subject'          => (string) $index,
					'title'            => $issue,
					'evidence'         => sprintf(
						/* translators: 1: health score, 2: grade. */
						__( 'Site health score %1$d (%2$s).', 'agent-builder' ),
						(int) ( $result['score'] ?? 0 ),
						(string) ( $result['grade'] ?? '' )
					),
					'evidence_payload' => array(
						'issue' => $issue,
						'score' => (int) ( $result['score'] ?? 0 ),
					),
					'proposed_action'  => __( 'Open Site Health for details. The scan does not apply a fix.', 'agent-builder' ),
					'action_risk'      => 'none',
					'approve'          => $this->approve_url( admin_url( 'site-health.php' ) ),
					'raw'              => array( 'issue' => $issue ),
				)
			);
		}

		return $cards;
	}
}
