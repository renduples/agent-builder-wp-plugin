<?php
/**
 * Site Brief checker: outdated active plugins.
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
 * Card for active plugins that have an update.
 */
class Checker_Plugin_Updates extends Site_Brief_Checker {

	/**
	 * {@inheritdoc}
	 */
	public function get_id(): string {
		return 'plugin_updates';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_tools(): array {
		return array( 'check_plugin_updates' );
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
		return 80;
	}

	/**
	 * {@inheritdoc}
	 */
	public function run( Site_Brief_Runner $runner ): array {
		$result = $runner->observe_tool( 'check_plugin_updates', array( 'outdated_only' => true ) );
		if ( isset( $result['error'] ) ) {
			return array();
		}

		$plugins = array();
		foreach ( (array) ( $result['plugins'] ?? array() ) as $row ) {
			if ( empty( $row['has_update'] ) ) {
				continue;
			}
			$plugins[] = array(
				'file'        => (string) ( $row['file'] ?? '' ),
				'name'        => (string) ( $row['name'] ?? '' ),
				'version'     => (string) ( $row['version'] ?? '' ),
				'new_version' => (string) ( $row['new_version'] ?? '' ),
			);
		}

		$count = count( $plugins );
		if ( 0 === $count ) {
			return array();
		}

		$lines = array();
		foreach ( array_slice( $plugins, 0, 8 ) as $plugin ) {
			$from    = $plugin['version'];
			$to      = $plugin['new_version'];
			$lines[] = $plugin['name'] . ( $from && $to ? ' (' . $from . ' → ' . $to . ')' : '' );
		}

		$title = ( 1 === $count )
			? sprintf(
				/* translators: %s: plugin name. */
				__( '%s has an update', 'agent-builder' ),
				$plugins[0]['name']
			)
			: sprintf(
				/* translators: %d: number of plugins. */
				_n( '%d plugin has updates', '%d plugins have updates', $count, 'agent-builder' ),
				$count
			);

		return array(
			$this->card(
				array(
					'title'            => $title,
					'evidence'         => implode( ', ', $lines ),
					'evidence_payload' => $plugins,
					'proposed_action'  => __( 'Open the Plugins screen to review updates. Agent Builder will not auto-update.', 'agent-builder' ),
					'action_risk'      => 'medium',
					'approve'          => $this->approve_url( admin_url( 'plugins.php' ) ),
					'raw'              => $plugins,
				)
			),
		);
	}
}
