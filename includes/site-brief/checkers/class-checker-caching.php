<?php
/**
 * Site Brief checker: page cache detection.
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
 * Card when no page cache is detected. Does not call manage_cache.
 */
class Checker_Caching extends Site_Brief_Checker {

	/**
	 * {@inheritdoc}
	 */
	public function get_id(): string {
		return 'caching';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_tools(): array {
		return array( 'check_caching_status' );
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
		return 'performance';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_severity(): int {
		return 30;
	}

	/**
	 * {@inheritdoc}
	 */
	public function run( Site_Brief_Runner $runner ): array {
		$result = $runner->observe_tool( 'check_caching_status', array() );
		if ( isset( $result['error'] ) ) {
			return array();
		}

		$page = $result['page_cache'] ?? array();
		if ( ! empty( $page['enabled'] ) ) {
			return array();
		}

		return array(
			$this->card(
				array(
					'title'            => __( 'No page cache detected', 'agent-builder' ),
					'evidence'         => __( 'A page cache plugin (or advanced-cache.php drop-in) was not found. Site Brief will not turn caching on.', 'agent-builder' ),
					'evidence_payload' => array( 'page_cache' => false ),
					'proposed_action'  => __( 'Install or enable a page cache from Plugins if you want one. manage_cache is not offered in this version.', 'agent-builder' ),
					'action_risk'      => 'none',
					'raw'              => array( 'page_cache' => $page ),
				)
			),
		);
	}
}
