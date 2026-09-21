<?php
/**
 * Site Brief checker contract.
 *
 * Each checker is a small class under includes/site-brief/checkers/ that
 * turns allowlisted read-tool output into zero or more ranked cards.
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
 * Base class for one Site Brief checker.
 */
abstract class Site_Brief_Checker {

	/**
	 * Stable checker id (matches the brief table).
	 *
	 * @return string
	 */
	abstract public function get_id(): string;

	/**
	 * Read tools this checker needs. All must be on the runner allowlist
	 * and available, otherwise the runner skips the checker with no card.
	 *
	 * @return string[]
	 */
	abstract public function get_tools(): array;

	/**
	 * Bundled agent whose job already claims this work.
	 *
	 * @return string
	 */
	abstract public function get_agent(): string;

	/**
	 * Ranking weight, higher first. Override per checker.
	 *
	 * @return int
	 */
	public function get_severity(): int {
		return 50;
	}

	/**
	 * Whether this checker applies on this install (e.g. WooCommerce active).
	 *
	 * @return bool
	 */
	public function is_applicable(): bool {
		return true;
	}

	/**
	 * Run the checker and return zero or more cards.
	 *
	 * @param Site_Brief_Runner $runner Runner (observe-mode tool calls).
	 * @return array<int, array<string, mixed>>
	 */
	abstract public function run( Site_Brief_Runner $runner ): array;

	/**
	 * Human label for a bundled agent slug.
	 *
	 * @param string $slug Agent slug.
	 * @return string
	 */
	protected function agent_label( string $slug ): string {
		$labels = array(
			'site-health-sentinel' => __( 'Site Health Sentinel', 'agent-builder' ),
			'wordpress-assistant'  => __( 'WordPress Assistant', 'agent-builder' ),
			'support-triage'       => __( 'Support Triage', 'agent-builder' ),
			'storefront-assistant' => __( 'Storefront Assistant', 'agent-builder' ),
		);
		return $labels[ $slug ] ?? $slug;
	}

	/**
	 * SHA-256 of a stable evidence payload. Dismissals key off this hash.
	 *
	 * @param mixed $payload JSON-encodable evidence (no PII).
	 * @return string
	 */
	protected function evidence_hash( mixed $payload ): string {
		$encoded = wp_json_encode( $payload );
		return hash( 'sha256', false === $encoded ? '' : $encoded );
	}

	/**
	 * Build a card array in the shape the store and REST panel expect.
	 *
	 * @param array<string, mixed> $spec Card fields.
	 * @return array<string, mixed>
	 */
	protected function card( array $spec ): array {
		$checker_id = $this->get_id();
		$subject    = isset( $spec['subject'] ) ? (string) $spec['subject'] : '';
		$card_id    = '' === $subject ? $checker_id : $checker_id . '.' . $subject;
		$agent      = $this->get_agent();
		$payload    = $spec['evidence_payload'] ?? ( $spec['evidence'] ?? '' );

		$raw = $spec['raw'] ?? array();
		if ( is_array( $raw ) && count( $raw ) > 20 ) {
			$raw = array_slice( $raw, 0, 20 );
		}

		return array(
			'id'              => sanitize_text_field( $card_id ),
			'checker_id'      => $checker_id,
			'title'           => (string) ( $spec['title'] ?? '' ),
			'evidence'        => (string) ( $spec['evidence'] ?? '' ),
			'agent'           => $agent,
			'agent_label'     => $this->agent_label( $agent ),
			'proposed_action' => (string) ( $spec['proposed_action'] ?? '' ),
			'action_risk'     => (string) ( $spec['action_risk'] ?? 'none' ),
			'scan_risk'       => 'none',
			'severity'        => (int) ( $spec['severity'] ?? $this->get_severity() ),
			'evidence_hash'   => $this->evidence_hash( $payload ),
			'dismissable'     => array_key_exists( 'dismissable', $spec ) ? (bool) $spec['dismissable'] : true,
			'tool_slugs'      => array_values( array_map( 'strval', (array) ( $spec['tool_slugs'] ?? $this->get_tools() ) ) ),
			'raw'             => is_array( $raw ) ? $raw : array(),
		);
	}
}
