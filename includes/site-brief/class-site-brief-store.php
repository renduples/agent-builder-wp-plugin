<?php
/**
 * Site Brief persistence.
 *
 * Stores the last scan, cards, and dismissals in a single option that is
 * never autoloaded.
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
 * Option-backed store for Site Brief results.
 */
class Site_Brief_Store {

	/**
	 * Option name. Autoload must stay off.
	 */
	public const OPTION = 'agent_builder_site_brief';

	/**
	 * Schema version stored inside the option payload.
	 */
	public const SCHEMA = 1;

	/**
	 * Soft cap on stored JSON so the option cannot grow without bound.
	 */
	private const MAX_BYTES = 51200;

	/**
	 * Default empty payload.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'version'       => self::SCHEMA,
			'last_run'      => '',
			'last_run_user' => 0,
			'status'        => 'idle',
			'cards'         => array(),
			'dismissed'     => array(),
			'assigned'      => array(),
			'store_stats'   => null,
			'skipped'       => array(),
		);
	}

	/**
	 * Read the stored brief. Never invokes tools.
	 *
	 * @return array<string, mixed>
	 */
	public static function get(): array {
		$stored = get_option( self::OPTION, null );
		if ( ! is_array( $stored ) ) {
			return self::defaults();
		}
		return array_merge( self::defaults(), $stored );
	}

	/**
	 * Persist the brief with autoload off.
	 *
	 * @param array<string, mixed> $data Payload.
	 * @return bool
	 */
	public static function save( array $data ): bool {
		$data            = array_merge( self::defaults(), $data );
		$data['version'] = self::SCHEMA;
		$data['cards']   = self::trim_cards( is_array( $data['cards'] ) ? $data['cards'] : array() );
		$encoded         = wp_json_encode( $data );
		if ( false === $encoded ) {
			return false;
		}
		if ( strlen( $encoded ) > self::MAX_BYTES ) {
			foreach ( $data['cards'] as &$card ) {
				if ( isset( $card['raw'] ) ) {
					$card['raw'] = array();
				}
			}
			unset( $card );
		}

		$existing = get_option( self::OPTION, false );
		if ( false === $existing ) {
			return add_option( self::OPTION, $data, '', 'no' );
		}

		$updated = update_option( self::OPTION, $data, false );
		self::ensure_not_autoloaded();
		return $updated;
	}

	/**
	 * Record a dismissal keyed by card id + evidence hash.
	 *
	 * The card reappears on a later scan only when the evidence hash differs.
	 *
	 * @param string $card_id       Card id (checker or checker:subject).
	 * @param string $evidence_hash SHA-256 of the card's evidence payload.
	 * @return void
	 */
	public static function dismiss( string $card_id, string $evidence_hash ): void {
		$data = self::get();
		if ( ! isset( $data['dismissed'] ) || ! is_array( $data['dismissed'] ) ) {
			$data['dismissed'] = array();
		}
		$data['dismissed'][ $card_id ] = array(
			'hash' => $evidence_hash,
			'at'   => gmdate( 'c' ),
		);
		self::save( $data );
	}

	/**
	 * Record an assignment keyed by card id.
	 *
	 * Survives a reload and a later scan for the same card id (unlike
	 * dismissals, assignments are not evidence-hash gated).
	 *
	 * @param string $card_id     Card id (checker or checker:subject).
	 * @param string $agent       Recommended agent slug.
	 * @param string $agent_label Human label for that agent.
	 * @return void
	 */
	public static function assign( string $card_id, string $agent, string $agent_label ): void {
		$data = self::get();
		if ( ! isset( $data['assigned'] ) || ! is_array( $data['assigned'] ) ) {
			$data['assigned'] = array();
		}
		$data['assigned'][ $card_id ] = array(
			'agent'       => sanitize_key( $agent ),
			'agent_label' => sanitize_text_field( $agent_label ),
			'at'          => gmdate( 'c' ),
		);
		self::save( $data );
	}

	/**
	 * Whether a card is currently dismissed for this exact evidence hash.
	 *
	 * @param string               $card_id   Card id.
	 * @param string               $hash      Current evidence hash.
	 * @param array<string, mixed> $dismissed Dismissed map from the store.
	 * @return bool
	 */
	public static function is_dismissed( string $card_id, string $hash, array $dismissed ): bool {
		if ( ! isset( $dismissed[ $card_id ] ) ) {
			return false;
		}
		$entry = $dismissed[ $card_id ];
		if ( is_string( $entry ) ) {
			return true;
		}
		if ( ! is_array( $entry ) || empty( $entry['hash'] ) ) {
			return false;
		}
		return hash_equals( (string) $entry['hash'], $hash );
	}

	/**
	 * Drop raw dumps so stored JSON stays small.
	 *
	 * @param array<int, array<string, mixed>> $cards Cards.
	 * @return array<int, array<string, mixed>>
	 */
	private static function trim_cards( array $cards ): array {
		$out = array();
		foreach ( $cards as $card ) {
			if ( ! is_array( $card ) ) {
				continue;
			}
			if ( isset( $card['raw'] ) && is_array( $card['raw'] ) && count( $card['raw'] ) > 12 ) {
				$card['raw'] = array_slice( $card['raw'], 0, 12 );
			}
			$out[] = $card;
		}
		return $out;
	}

	/**
	 * Force autoload off if a previous write left it on.
	 *
	 * @return void
	 */
	public static function ensure_not_autoloaded(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-shot autoload repair for a known option.
		$wpdb->update(
			$wpdb->options,
			array( 'autoload' => 'no' ),
			array( 'option_name' => self::OPTION ),
			array( '%s' ),
			array( '%s' )
		);
	}
}
