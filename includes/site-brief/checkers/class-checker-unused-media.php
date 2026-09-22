<?php
/**
 * Site Brief checker: unused media count (dismiss-only).
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
 * Informational unused-media card. v1 never queues a delete.
 */
class Checker_Unused_Media extends Site_Brief_Checker {

	/**
	 * {@inheritdoc}
	 */
	public function get_id(): string {
		return 'unused_media';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_tools(): array {
		return array( 'find_unused_media' );
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
		return 'media';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_severity(): int {
		return 20;
	}

	/**
	 * {@inheritdoc}
	 */
	public function run( Site_Brief_Runner $runner ): array {
		$result = $runner->observe_tool(
			'find_unused_media',
			array( 'limit' => 50 )
		);
		if ( isset( $result['error'] ) ) {
			return array();
		}

		$count = (int) ( $result['total_found'] ?? 0 );
		if ( $count < 1 ) {
			return array();
		}

		return array(
			$this->card(
				array(
					'title'            => sprintf(
						/* translators: %d: unused attachments. */
						_n( '%d unused media item', '%d unused media items', $count, 'agent-builder' ),
						$count
					),
					'evidence'         => __( 'Counted from post content and featured images only. Nothing is deleted in this version.', 'agent-builder' ),
					'evidence_payload' => array( 'total' => $count ),
					'proposed_action'  => __( 'Dismiss if this is expected. Site Brief will not delete media.', 'agent-builder' ),
					'action_risk'      => 'none',
					'approve'          => array( 'type' => 'none' ),
					'raw'              => array( 'total' => $count ),
				)
			),
		);
	}
}
