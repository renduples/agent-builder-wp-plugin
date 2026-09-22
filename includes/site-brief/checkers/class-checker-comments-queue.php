<?php
/**
 * Site Brief checker: held and spam comments above threshold.
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
 * Cards for a held or spam comment backlog.
 */
class Checker_Comments_Queue extends Site_Brief_Checker {

	/**
	 * Default backlog size that produces a card.
	 */
	public const THRESHOLD = 5;

	/**
	 * {@inheritdoc}
	 */
	public function get_id(): string {
		return 'comments_queue';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_tools(): array {
		return array( 'list_comments' );
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
	public function get_category(): string {
		return 'maintenance';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_severity(): int {
		return 60;
	}

	/**
	 * {@inheritdoc}
	 */
	public function run( Site_Brief_Runner $runner ): array {
		$held = $runner->observe_tool(
			'list_comments',
			array(
				'status' => 'hold',
				'limit'  => 1,
			)
		);
		$spam = $runner->observe_tool(
			'list_comments',
			array(
				'status' => 'spam',
				'limit'  => 1,
			)
		);

		$held_total = isset( $held['error'] ) ? 0 : (int) ( $held['total'] ?? 0 );
		$spam_total = isset( $spam['error'] ) ? 0 : (int) ( $spam['total'] ?? 0 );

		$cards = array();

		if ( $held_total >= self::THRESHOLD ) {
			$cards[] = $this->card(
				array(
					'subject'          => 'hold',
					'title'            => sprintf(
						/* translators: %d: number of comments. */
						_n( '%d comment is awaiting moderation', '%d comments are awaiting moderation', $held_total, 'agent-builder' ),
						$held_total
					),
					'evidence'         => sprintf(
						/* translators: %d: number of held comments. */
						__( '%d held comments in the moderation queue.', 'agent-builder' ),
						$held_total
					),
					'evidence_payload' => array(
						'status' => 'hold',
						'total'  => $held_total,
					),
					'proposed_action'  => __( 'Open the Comments screen to review held comments.', 'agent-builder' ),
					'action_risk'      => 'medium',
					'raw'              => array( 'total' => $held_total ),
				)
			);
		}

		if ( $spam_total >= self::THRESHOLD ) {
			$cards[] = $this->card(
				array(
					'subject'          => 'spam',
					'title'            => sprintf(
						/* translators: %d: number of spam comments. */
						_n( '%d spam comment is queued', '%d spam comments are queued', $spam_total, 'agent-builder' ),
						$spam_total
					),
					'evidence'         => sprintf(
						/* translators: %d: spam count. */
						__( '%d comments marked as spam.', 'agent-builder' ),
						$spam_total
					),
					'evidence_payload' => array(
						'status' => 'spam',
						'total'  => $spam_total,
					),
					'proposed_action'  => __( 'Queue cleanup_spam_comments for approval. The scan will not delete anything.', 'agent-builder' ),
					'action_risk'      => 'medium',
					'raw'              => array( 'total' => $spam_total ),
				)
			);
		}

		return $cards;
	}
}
