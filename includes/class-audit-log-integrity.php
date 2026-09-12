<?php
/**
 * Audit Log Integrity — hash-chains wp_agentic_audit_log so that editing or
 * deleting a historical row is detectable, not just editing one in place.
 *
 * Mirrors Abilities_Manifest's HMAC-signing precedent (same key source, via
 * Abilities_Manifest::get_integrity_key()) but a per-row signature alone
 * only catches an edited row — it says nothing about a row that's simply
 * gone. Chaining each row's hash into the next closes that gap: deleting or
 * altering any row breaks the chain from that point forward, which
 * verify_chain() below can detect without needing every row's original
 * content to already be known ahead of time.
 *
 * This is detection, not prevention. Anyone with direct database access can
 * still delete rows outright — no application-layer code can stop that.
 * What this buys is the same thing Abilities_Manifest::verify_integrity()
 * buys for manifests: a site owner (or an auditor with read access) can
 * prove after the fact whether the log has been tampered with, instead of
 * trusting it blindly.
 *
 * @package    Agent_Builder
 * @subpackage Includes
 * @since      3.3.96
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Computes, records, and verifies the audit log's hash chain.
 */
class Audit_Log_Integrity {

	/**
	 * Build the canonical, deterministic byte string a row's hash is computed
	 * over. Every field that matters for "what actually happened" is
	 * included; volatile/derived values (e.g. cache state) are not.
	 *
	 * Field order is fixed explicitly (not just ksort on the caller's array)
	 * so the canonical form can't silently shift if a future edit reorders
	 * the $data array passed into log().
	 *
	 * @param int   $id   The row's own id — included so reordering/relabeling
	 *                    rows can't be used to hide a deletion.
	 * @param array $data Row data — either the associative array passed to
	 *                    $wpdb->insert() in Audit_Log::log() (typed PHP
	 *                    values), or a row fetched back with $wpdb->get_results()
	 *                    (everything as strings). Every field is explicitly
	 *                    cast below so both sources canonicalize identically —
	 *                    without that, verify_chain() would never match what
	 *                    record() computed at insert time, since MySQL always
	 *                    returns column values as strings regardless of their
	 *                    declared type.
	 * @return string
	 */
	private static function canonical_row( int $id, array $data ): string {
		$fields = array(
			'id'            => $id,
			'agent_id'      => (string) ( $data['agent_id'] ?? '' ),
			'action'        => (string) ( $data['action'] ?? '' ),
			'target_type'   => (string) ( $data['target_type'] ?? '' ),
			'target_id'     => (string) ( $data['target_id'] ?? '' ),
			'details'       => (string) ( $data['details'] ?? '' ),
			'reasoning'     => (string) ( $data['reasoning'] ?? '' ),
			'mode'          => (string) ( $data['mode'] ?? '' ),
			'provider'      => (string) ( $data['provider'] ?? '' ),
			'tokens_used'   => (int) ( $data['tokens_used'] ?? 0 ),
			'cost'          => sprintf( '%.6f', (float) ( $data['cost'] ?? 0.0 ) ),
			'user_id'       => (int) ( $data['user_id'] ?? 0 ),
			'created_at'    => (string) ( $data['created_at'] ?? '' ),
			'agent_author'  => (string) ( $data['agent_author'] ?? '' ),
			'agent_version' => (string) ( $data['agent_version'] ?? '' ),
		);

		return (string) wp_json_encode( $fields );
	}

	/**
	 * Compute the hash for one row, chained onto whatever came before it.
	 *
	 * @param int         $id             Row id.
	 * @param array       $data           Row data (see canonical_row()).
	 * @param string|null $previous_hash  The previous row's integrity_hash,
	 *                                    or null for the first row in the chain.
	 * @return string Hex-encoded HMAC-SHA256.
	 */
	public static function compute_hash( int $id, array $data, ?string $previous_hash ): string {
		$payload = self::canonical_row( $id, $data ) . '|' . ( $previous_hash ?? '' );
		return hash_hmac( 'sha256', $payload, Abilities_Manifest::get_integrity_key() );
	}

	/**
	 * The most recent row's integrity_hash, i.e. the tip of the chain.
	 *
	 * A NULL result means either the table is empty, or the most recent row
	 * predates this feature (no integrity_hash was ever computed for it) —
	 * either way, the next row correctly starts a new chain from empty.
	 *
	 * @return string|null
	 */
	private static function get_chain_tip(): ?string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Single-row lookup, no caching benefit.
		$hash = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT integrity_hash FROM %i ORDER BY id DESC LIMIT 1',
				$wpdb->prefix . 'agentic_audit_log'
			)
		);
		return ( null === $hash || '' === $hash ) ? null : (string) $hash;
	}

	/**
	 * Compute and persist the integrity_hash for a row that was just
	 * inserted by Audit_Log::log(). Call this immediately after the insert,
	 * with the exact same $data array that was written — the chain tip read
	 * here is a separate query from the insert, so under concurrent writes
	 * two requests could theoretically read the same tip and both chain off
	 * it. That's a benign race (verify_chain() would report a fork at worst,
	 * not silently accept a tampered row), not a security gap, and matches
	 * the audit log's existing lack of insert-level locking generally.
	 *
	 * @param int   $id   The newly inserted row's id.
	 * @param array $data The data array passed to $wpdb->insert().
	 * @return void
	 */
	public static function record( int $id, array $data ): void {
		global $wpdb;

		$previous_hash = self::get_chain_tip();
		$hash          = self::compute_hash( $id, $data, $previous_hash );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Single-row update immediately after insert.
		$wpdb->update(
			$wpdb->prefix . 'agentic_audit_log',
			array( 'integrity_hash' => $hash ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Walk the chain and report the first break, if any.
	 *
	 * Rows written before this feature shipped have a NULL integrity_hash —
	 * they're skipped for hashing purposes but still counted, and the chain
	 * simply restarts (previous_hash = null) at the first row that has one.
	 * That means tampering with a *pre-feature* row can't be detected; only
	 * tampering with rows written after this shipped can be. That's an
	 * inherent limit of turning on tamper-evidence after the fact, not a bug
	 * — flagged explicitly in the return value via `chain_start_id`.
	 *
	 * @param int|null $from_id Only verify rows with id >= this. Null = verify everything.
	 * @return array{valid: bool, checked: int, broken_at_id: int|null, chain_start_id: int|null}
	 */
	public static function verify_chain( ?int $from_id = null ): array {
		global $wpdb;

		$where  = array();
		$params = array( $wpdb->prefix . 'agentic_audit_log' );
		if ( null !== $from_id ) {
			$where[]  = 'id >= %d';
			$params[] = $from_id;
		}
		$where_sql = $where ? ( 'WHERE ' . implode( ' AND ', $where ) ) : '';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name via %i, $where_sql built from fixed strings only.
		$query = "SELECT id, agent_id, action, target_type, target_id, details, reasoning, mode, provider,
			tokens_used, cost, user_id, created_at, agent_author, agent_version, integrity_hash
			FROM %i {$where_sql} ORDER BY id ASC";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Read-only integrity walk, not a hot path. $query is prepared right here via %i/%d placeholders; the ignore two lines up (on the $query assignment) doesn't reach this separate statement.
		$rows = $wpdb->get_results( $wpdb->prepare( $query, $params ), ARRAY_A );

		$previous_hash  = null;
		$checked        = 0;
		$chain_start_id = null;

		foreach ( $rows as $row ) {
			$id = (int) $row['id'];

			if ( null === $row['integrity_hash'] || '' === $row['integrity_hash'] ) {
				// Pre-feature row (or a still-in-flight insert) — not chained, skip.
				$previous_hash = null;
				continue;
			}

			if ( null === $chain_start_id ) {
				$chain_start_id = $id;
			}

			$data     = $row;
			unset( $data['integrity_hash'] );
			$expected = self::compute_hash( $id, $data, $previous_hash );
			++$checked;

			if ( ! hash_equals( $expected, $row['integrity_hash'] ) ) {
				return array(
					'valid'          => false,
					'checked'        => $checked,
					'broken_at_id'   => $id,
					'chain_start_id' => $chain_start_id,
				);
			}

			$previous_hash = $row['integrity_hash'];
		}

		return array(
			'valid'          => true,
			'checked'        => $checked,
			'broken_at_id'   => null,
			'chain_start_id' => $chain_start_id,
		);
	}
}
