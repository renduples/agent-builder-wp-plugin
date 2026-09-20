<?php
/**
 * Site Brief checker: native and plugin forms with stale or zero submissions.
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
 * Diagnose-only form cards. There is no mail-server tool in v1.
 */
class Checker_Forms extends Site_Brief_Checker {

	/**
	 * {@inheritdoc}
	 */
	public function get_id(): string {
		return 'forms';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_tools(): array {
		return array( 'list_native_forms', 'get_form_stats', 'detect_form_plugins' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_agent(): string {
		return 'support-triage';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_severity(): int {
		return 40;
	}

	/**
	 * {@inheritdoc}
	 */
	public function run( Site_Brief_Runner $runner ): array {
		$native   = $runner->observe_tool( 'list_native_forms', array() );
		$detected = $runner->observe_tool( 'detect_form_plugins', array() );
		$cards    = array();

		$zero = array();
		foreach ( (array) ( $native['forms'] ?? array() ) as $form ) {
			$entries = (int) ( $form['entry_count'] ?? 0 );
			if ( $entries > 0 ) {
				continue;
			}
			$zero[] = array(
				'id'    => (int) ( $form['id'] ?? 0 ),
				'title' => (string) ( $form['title'] ?? '' ),
			);
		}

		if ( ! empty( $zero ) ) {
			$titles = array();
			foreach ( array_slice( $zero, 0, 5 ) as $form ) {
				if ( '' !== $form['title'] ) {
					$titles[] = $form['title'];
				}
			}
			$cards[] = $this->card(
				array(
					'subject'          => 'native',
					'title'            => sprintf(
						/* translators: %d: forms with zero entries. */
						_n( '%d native form has no submissions', '%d native forms have no submissions', count( $zero ), 'agent-builder' ),
						count( $zero )
					),
					'evidence'         => implode( ', ', $titles ),
					'evidence_payload' => $zero,
					'proposed_action'  => __( 'This is a diagnose card only. There is no mail-deliverability tool in this version.', 'agent-builder' ),
					'action_risk'      => 'none',
					'approve'          => array( 'type' => 'none' ),
					'raw'              => array( 'count' => count( $zero ) ),
				)
			);
		}

		$plugins = (array) ( $detected['detected'] ?? $detected['plugins'] ?? array() );
		$slugs   = array();
		foreach ( $plugins as $plugin ) {
			if ( is_array( $plugin ) && ! empty( $plugin['slug'] ) ) {
				$slugs[] = (string) $plugin['slug'];
			}
		}

		if ( ! empty( $slugs ) ) {
			$stats = $runner->observe_tool( 'get_form_stats', array() );
			if ( ! isset( $stats['error'] ) ) {
				$total = (int) ( $stats['total_entries'] ?? -1 );
				$week  = (int) ( $stats['entries_last_7_days'] ?? -1 );
				if ( 0 === $total || 0 === $week ) {
					$cards[] = $this->card(
						array(
							'subject'          => 'plugin',
							'title'            => __( 'Form submissions look stale or empty', 'agent-builder' ),
							'evidence'         => sprintf(
								/* translators: %s: form plugin slugs. */
								__( 'Detected: %s. Site Brief cannot test mail delivery in this version.', 'agent-builder' ),
								implode( ', ', $slugs )
							),
							'evidence_payload' => array(
								'plugins' => $slugs,
								'total'   => $total,
								'week'    => $week,
							),
							'proposed_action'  => __( 'Diagnose card only. No mail-server tool is in the tree.', 'agent-builder' ),
							'action_risk'      => 'none',
							'approve'          => array( 'type' => 'none' ),
							'raw'              => array(
								'plugins' => $slugs,
								'total'   => $total,
							),
						)
					);
				}
			}
		}

		return $cards;
	}
}
