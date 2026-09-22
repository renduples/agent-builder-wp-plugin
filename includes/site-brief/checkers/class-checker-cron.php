<?php
/**
 * Site Brief checker: overdue WP-Cron events.
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
 * Card when WP-Cron has missed events.
 */
class Checker_Cron extends Site_Brief_Checker {

	/**
	 * {@inheritdoc}
	 */
	public function get_id(): string {
		return 'cron';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_tools(): array {
		return array( 'get_cron_jobs' );
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
	public function get_category(): string {
		return 'maintenance';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_severity(): int {
		return 35;
	}

	/**
	 * {@inheritdoc}
	 */
	public function run( Site_Brief_Runner $runner ): array {
		$result = $runner->observe_tool( 'get_cron_jobs', array() );
		if ( isset( $result['error'] ) ) {
			return array();
		}

		$overdue = (int) ( $result['overdue'] ?? 0 );
		if ( $overdue < 1 ) {
			return array();
		}

		return array(
			$this->card(
				array(
					'title'            => sprintf(
						/* translators: %d: overdue cron events. */
						_n( '%d WP-Cron event is overdue', '%d WP-Cron events are overdue', $overdue, 'agent-builder' ),
						$overdue
					),
					'evidence'         => __( 'Scheduled events are running late. Site Brief does not trigger cron in this version.', 'agent-builder' ),
					'evidence_payload' => array( 'overdue' => $overdue ),
					'proposed_action'  => __( 'Check DISABLE_WP_CRON and your host’s real cron. No write is queued.', 'agent-builder' ),
					'action_risk'      => 'none',
					'approve'          => array( 'type' => 'none' ),
					'raw'              => array( 'overdue' => $overdue ),
				)
			),
		);
	}
}
