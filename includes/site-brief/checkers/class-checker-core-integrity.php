<?php
/**
 * Site Brief checker: WordPress core checksum mismatches.
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
 * Card for core file mismatches. Never offers to rewrite core files.
 */
class Checker_Core_Integrity extends Site_Brief_Checker {

	/**
	 * {@inheritdoc}
	 */
	public function get_id(): string {
		return 'core_integrity';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_tools(): array {
		return array( 'verify_core_integrity' );
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
		return 'security';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_label(): string {
		return __( 'Core file integrity', 'agent-builder' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_severity(): int {
		return 100;
	}

	/**
	 * {@inheritdoc}
	 */
	public function run( Site_Brief_Runner $runner ): array {
		$result = $runner->observe_tool( 'verify_core_integrity', array() );
		if ( isset( $result['error'] ) ) {
			return array();
		}

		$modified = array_values( array_filter( array_map( 'strval', (array) ( $result['modified_files'] ?? array() ) ) ) );
		$missing  = array_values( array_filter( array_map( 'strval', (array) ( $result['missing_files'] ?? array() ) ) ) );
		$issues   = count( $modified ) + count( $missing );
		if ( $issues < 1 ) {
			return array();
		}

		$sample = array_slice( array_merge( $modified, $missing ), 0, 6 );

		return array(
			$this->card(
				array(
					'title'            => sprintf(
						/* translators: %d: mismatched core files. */
						_n( '%d WordPress core file does not match checksums', '%d WordPress core files do not match checksums', $issues, 'agent-builder' ),
						$issues
					),
					'evidence'         => implode( ', ', $sample ),
					'evidence_payload' => array(
						'modified' => count( $modified ),
						'missing'  => count( $missing ),
						'sample'   => $sample,
					),
					'proposed_action'  => __( 'Restore core files from a host backup or a fresh WordPress copy. Site Brief will never rewrite core files.', 'agent-builder' ),
					'action_risk'      => 'none',
					'approve'          => array( 'type' => 'none' ),
					'raw'              => array(
						'modified_count' => count( $modified ),
						'missing_count'  => count( $missing ),
					),
				)
			),
		);
	}
}
