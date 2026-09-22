<?php
/**
 * Site Brief checker: abandoned active plugins.
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
 * Advise-only card for abandoned plugins. Never uninstalls.
 */
class Checker_Abandoned_Plugins extends Site_Brief_Checker {

	/**
	 * {@inheritdoc}
	 */
	public function get_id(): string {
		return 'abandoned_plugins';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_tools(): array {
		return array( 'get_abandoned_plugins' );
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
	public function get_category(): string {
		return 'maintenance';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_severity(): int {
		return 65;
	}

	/**
	 * {@inheritdoc}
	 */
	public function run( Site_Brief_Runner $runner ): array {
		$result = $runner->observe_tool( 'get_abandoned_plugins', array() );
		if ( isset( $result['error'] ) ) {
			return array();
		}

		$abandoned = array();
		foreach ( (array) ( $result['abandoned'] ?? array() ) as $row ) {
			$abandoned[] = array(
				'name'   => (string) ( $row['name'] ?? $row['slug'] ?? '' ),
				'slug'   => (string) ( $row['slug'] ?? '' ),
				'months' => (int) ( $row['months_since_update'] ?? 0 ),
			);
		}

		$count = count( $abandoned );
		if ( $count < 1 ) {
			return array();
		}

		$names = array();
		foreach ( array_slice( $abandoned, 0, 6 ) as $plugin ) {
			$names[] = $plugin['name'] . ( $plugin['months'] ? ' (' . $plugin['months'] . ' mo)' : '' );
		}

		return array(
			$this->card(
				array(
					'title'            => sprintf(
						/* translators: %d: abandoned plugin count. */
						_n( '%d abandoned plugin', '%d abandoned plugins', $count, 'agent-builder' ),
						$count
					),
					'evidence'         => implode( ', ', $names ),
					'evidence_payload' => $abandoned,
					'proposed_action'  => __( 'Review on the Plugins screen. Site Brief will not uninstall anything.', 'agent-builder' ),
					'action_risk'      => 'none',
					'raw'              => array_slice( $abandoned, 0, 8 ),
				)
			),
		);
	}
}
