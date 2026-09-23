<?php
/**
 * Site Brief checker: new PHP fatals/errors in debug.log.
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
 * Read-only card for recent fatal errors.
 */
class Checker_Php_Errors extends Site_Brief_Checker {

	/**
	 * {@inheritdoc}
	 */
	public function get_id(): string {
		return 'php_errors';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_tools(): array {
		return array( 'get_php_errors' );
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
	public function get_label(): string {
		return __( 'PHP errors', 'agent-builder' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_severity(): int {
		return 85;
	}

	/**
	 * {@inheritdoc}
	 */
	public function run( Site_Brief_Runner $runner ): array {
		$result = $runner->observe_tool(
			'get_php_errors',
			array(
				'level' => 'fatal',
				'limit' => 10,
			)
		);
		if ( isset( $result['error'] ) ) {
			return array();
		}
		if ( isset( $result['status'] ) && 'no_log_file' === $result['status'] ) {
			return array();
		}

		$total = (int) ( $result['total_matching'] ?? 0 );
		if ( $total < 1 ) {
			return array();
		}

		$entries = array_values( array_filter( array_map( 'strval', (array) ( $result['entries'] ?? array() ) ) ) );
		$sample  = array_slice( $entries, -3 );

		return array(
			$this->card(
				array(
					'title'            => sprintf(
						/* translators: %d: fatal count. */
						_n( '%d PHP fatal in debug.log', '%d PHP fatals in debug.log', $total, 'agent-builder' ),
						$total
					),
					'evidence'         => implode( ' | ', $sample ),
					'evidence_payload' => array(
						'total'   => $total,
						'sample'  => $sample,
						'size_kb' => $result['log_size_kb'] ?? 0,
					),
					'proposed_action'  => __( 'Review debug.log on the host. Site Brief does not edit PHP files.', 'agent-builder' ),
					'action_risk'      => 'none',
					'approve'          => array( 'type' => 'none' ),
					'raw'              => array( 'total' => $total ),
				)
			),
		);
	}
}
