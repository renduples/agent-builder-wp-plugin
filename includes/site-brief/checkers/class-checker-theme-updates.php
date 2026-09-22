<?php
/**
 * Site Brief checker: active theme update.
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
 * Card when the active theme has an update.
 */
class Checker_Theme_Updates extends Site_Brief_Checker {

	/**
	 * {@inheritdoc}
	 */
	public function get_id(): string {
		return 'theme_updates';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_tools(): array {
		return array( 'check_theme_status' );
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
		return 75;
	}

	/**
	 * {@inheritdoc}
	 */
	public function run( Site_Brief_Runner $runner ): array {
		$result = $runner->observe_tool(
			'check_theme_status',
			array( 'filter' => 'active' )
		);
		if ( isset( $result['error'] ) ) {
			return array();
		}

		$active = null;
		foreach ( (array) ( $result['themes'] ?? array() ) as $theme ) {
			if ( isset( $theme['status'] ) && 'active' === $theme['status'] ) {
				$active = $theme;
				break;
			}
		}

		if ( ! is_array( $active ) || empty( $active['update_available'] ) ) {
			return array();
		}

		$name    = (string) ( $active['name'] ?? $active['slug'] ?? '' );
		$current = (string) ( $active['version'] ?? '' );
		$new     = is_string( $active['update_available'] ) ? $active['update_available'] : '';

		$evidence = $name;
		if ( '' !== $current && '' !== $new && true !== $active['update_available'] ) {
			$evidence = $name . ' (' . $current . ' → ' . $new . ')';
		} elseif ( '' !== $current ) {
			$evidence = $name . ' (' . $current . ')';
		}

		return array(
			$this->card(
				array(
					'title'            => sprintf(
						/* translators: %s: theme name. */
						__( 'Active theme %s has an update', 'agent-builder' ),
						$name
					),
					'evidence'         => $evidence,
					'evidence_payload' => array(
						'slug'    => (string) ( $active['slug'] ?? '' ),
						'version' => $current,
						'new'     => $new,
					),
					'proposed_action'  => __( 'Open the Themes screen to review the update. Agent Builder will not auto-update.', 'agent-builder' ),
					'action_risk'      => 'medium',
					'raw'              => array(
						'slug'    => (string) ( $active['slug'] ?? '' ),
						'version' => $current,
					),
				)
			),
		);
	}
}
