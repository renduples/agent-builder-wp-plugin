<?php
/**
 * Audit Log Integrity — hash-chains wp_agent_builder_audit_log so that editing or
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
	 * Option holding the signed anchor for the first kept row after the head
	 * of the log was removed: { id, prev_id: 0, prev_hash, trusted_start_hash,
	 * set_at, reason, sig }.
	 */
	const ANCHOR_OPTION = 'agent_builder_audit_chain_anchor';

	/**
	 * Option holding signed gap links for rows whose predecessor was removed
	 * from the middle of the log (privacy erasure).
	 */
	const GAPS_OPTION = 'agent_builder_audit_chain_gaps';

	/**
	 * One-time flag for repair_pruned_head().
	 */
	const REPAIR_FLAG_OPTION = 'agent_builder_audit_chain_repair_done';

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
	 * The most recent row's integrity_hash *before* a given row id, i.e. the
	 * tip of the chain that row should link onto.
	 *
	 * A NULL result means either no earlier row exists, or the most recent
	 * earlier row predates this feature (no integrity_hash was ever computed
	 * for it) — either way, the next row correctly starts a new chain from
	 * empty.
	 *
	 * Excludes $before_id itself: record() calls this *after* the row it is
	 * hashing has already been inserted (with integrity_hash still NULL), so
	 * without this exclusion "ORDER BY id DESC LIMIT 1" would pick up that
	 * same just-inserted row — the highest id in the table at that moment —
	 * and always read back a NULL hash. Every row would then chain onto
	 * nothing, and verify_chain() (which walks strictly by id, unaware of
	 * insert order) would report a break as soon as two real rows existed.
	 *
	 * @param int $before_id Only consider rows with id strictly less than this.
	 * @return string|null
	 */
	private static function get_chain_tip( int $before_id ): ?string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Single-row lookup, no caching benefit.
		$hash = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT integrity_hash FROM %i WHERE id < %d ORDER BY id DESC LIMIT 1',
				$wpdb->prefix . 'agent_builder_audit_log',
				$before_id
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

		// Serialize the read-tip + write-hash pair. Without this, two
		// near-simultaneous record() calls (concurrent cron / agent activity,
		// or several log writes in one turn) can both read the same chain tip
		// before either writes, so the later row chains onto a stale tip and
		// verify_chain() reports a false tamper break. A MySQL named lock makes
		// the pair atomic across the separate DB connections of concurrent PHP
		// requests. Best-effort: if the lock can't be taken we still record the
		// row (a possible false break is better than a dropped audit entry).
		$got_lock = self::acquire_lock();

		try {
			$previous_hash = self::get_chain_tip( $id );
			$hash          = self::compute_hash( $id, $data, $previous_hash );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Single-row update immediately after insert.
			$wpdb->update(
				$wpdb->prefix . 'agent_builder_audit_log',
				array( 'integrity_hash' => $hash ),
				array( 'id' => $id ),
				array( '%s' ),
				array( '%d' )
			);
		} finally {
			self::release_lock( $got_lock );
		}
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
	 * Rows the plugin itself removed (retention, privacy erasure) are bridged
	 * by signed chain links — see prune_before() and delete_rows(). A row is
	 * accepted when its hash verifies against its real predecessor's hash, or
	 * against the prev_hash of a link recorded for exactly this row *and*
	 * this predecessor (id 0 = "first row in the table", i.e. the anchor).
	 * Anything else — an edited row, a row deleted outside the plugin, head
	 * rows deleted without updating the anchor, a forged anchor — still fails.
	 *
	 * @param int|null $from_id Only verify rows with id >= this. Null = verify everything.
	 * @return array{valid: bool, checked: int, broken_at_id: int|null, chain_start_id: int|null, anchor_id: int|null}
	 */
	public static function verify_chain( ?int $from_id = null ): array {
		// A scoped walk (id >= $from_id) must seed from the row immediately
		// before $from_id, not "nothing" — otherwise the first row in range
		// would be compared against "no previous row" even though it was
		// originally chained onto a real predecessor, and every scoped call
		// would report a false break.
		$prev_id   = 0;
		$prev_hash = null;
		if ( null !== $from_id ) {
			$before = self::get_row_before( $from_id );
			if ( null !== $before ) {
				$prev_id   = $before['id'];
				$prev_hash = $before['hash'];
			}
		}

		return self::walk( self::fetch_rows( $from_id ), $prev_id, $prev_hash );
	}

	/**
	 * Verify a list of rows (ascending id) against the chain.
	 *
	 * @param array       $rows      Rows from fetch_rows().
	 * @param int         $prev_id   Id of the row right before the first one (0 = none).
	 * @param string|null $prev_hash That row's integrity_hash.
	 * @return array{valid: bool, checked: int, broken_at_id: int|null, chain_start_id: int|null, anchor_id: int|null}
	 */
	private static function walk( array $rows, int $prev_id, ?string $prev_hash ): array {
		$anchor         = self::get_anchor();
		$gaps           = self::get_gaps();
		$checked        = 0;
		$chain_start_id = null;
		$anchor_id      = null;

		foreach ( $rows as $row ) {
			$id     = (int) $row['id'];
			$stored = $row['integrity_hash'];

			if ( null === $stored || '' === $stored ) {
				// Pre-feature row (or a still-in-flight insert) — not chained, skip.
				$prev_id   = $id;
				$prev_hash = null;
				continue;
			}

			if ( null === $chain_start_id ) {
				$chain_start_id = $id;
			}
			++$checked;

			$data = $row;
			unset( $data['integrity_hash'] );

			$ok = false;

			// Repair anchor (see repair_pruned_head()): the first row's own
			// content can't be re-verified because the hash it was chained onto
			// is gone, so its stored hash is accepted as the trusted start.
			if ( 0 === $prev_id && null !== $anchor && $anchor['id'] === $id ) {
				$anchor_id = $id;
				if ( null !== $anchor['trusted_start_hash'] && hash_equals( $anchor['trusted_start_hash'], (string) $stored ) ) {
					$ok = true;
				}
			}

			$candidates = array( $prev_hash );
			if ( 0 === $prev_id && null !== $anchor && $anchor['id'] === $id ) {
				$candidates[] = $anchor['prev_hash'];
			}
			if ( isset( $gaps[ $id ] ) && $gaps[ $id ]['prev_id'] === $prev_id ) {
				$candidates[] = $gaps[ $id ]['prev_hash'];
			}

			foreach ( $candidates as $candidate ) {
				if ( $ok ) {
					break;
				}
				$ok = hash_equals( self::compute_hash( $id, $data, $candidate ), (string) $stored );
			}

			if ( ! $ok ) {
				return array(
					'valid'          => false,
					'checked'        => $checked,
					'broken_at_id'   => $id,
					'chain_start_id' => $chain_start_id,
					'anchor_id'      => $anchor_id,
				);
			}

			$prev_id   = $id;
			$prev_hash = (string) $stored;
		}

		return array(
			'valid'          => true,
			'checked'        => $checked,
			'broken_at_id'   => null,
			'chain_start_id' => $chain_start_id,
			'anchor_id'      => $anchor_id,
		);
	}

	/**
	 * Fetch rows to verify, ascending by id.
	 *
	 * @param int|null $from_id Only rows with id >= this. Null = all rows.
	 * @param int|null $after_id Only rows with id > this. Null = no bound.
	 * @return array<int, array<string, mixed>>
	 */
	private static function fetch_rows( ?int $from_id, ?int $after_id = null ): array {
		global $wpdb;

		$where  = array();
		$params = array( self::table() );
		if ( null !== $from_id ) {
			$where[]  = 'id >= %d';
			$params[] = $from_id;
		}
		if ( null !== $after_id ) {
			$where[]  = 'id > %d';
			$params[] = $after_id;
		}
		$where_sql = $where ? ( 'WHERE ' . implode( ' AND ', $where ) ) : '';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name via %i, $where_sql built from fixed strings only.
		$query = "SELECT id, agent_id, action, target_type, target_id, details, reasoning, mode, provider,
			tokens_used, cost, user_id, created_at, agent_author, agent_version, integrity_hash
			FROM %i {$where_sql} ORDER BY id ASC";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Read-only integrity walk, not a hot path. $query is prepared right here via %i/%d placeholders.
		$rows = $wpdb->get_results( $wpdb->prepare( $query, $params ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * The row immediately before a given id (id + integrity_hash), if any.
	 *
	 * @param int $before_id Row id.
	 * @return array{id: int, hash: string|null}|null
	 */
	private static function get_row_before( int $before_id ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Single-row lookup.
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT id, integrity_hash FROM %i WHERE id < %d ORDER BY id DESC LIMIT 1', self::table(), $before_id ),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return null;
		}
		$hash = $row['integrity_hash'];
		return array(
			'id'   => (int) $row['id'],
			'hash' => ( null === $hash || '' === $hash ) ? null : (string) $hash,
		);
	}

	// -------------------------------------------------------------------------
	// Chain anchor and gap links — removals made by the plugin itself.
	// -------------------------------------------------------------------------

	/**
	 * Delete every audit row created before $cutoff, keeping the chain
	 * verifiable. Every retention path (Audit_Log::cleanup(), GDPR::
	 * run_cleanup()) goes through here.
	 *
	 * Rows are removed from the head only: everything up to and including the
	 * newest row older than $cutoff (by id). Before deleting, the hash the new
	 * first row was chained onto is read; after deleting, it is saved as the
	 * signed anchor for that row so verify_chain() can still check it.
	 *
	 * @param string $cutoff UTC datetime ('Y-m-d H:i:s'); rows older than this go.
	 * @param string $reason Short reason stored with the anchor.
	 * @return int Rows deleted.
	 */
	public static function prune_before( string $cutoff, string $reason = 'retention' ): int {
		global $wpdb;

		$got_lock = self::acquire_lock();
		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table lookup.
			$last_deleted = (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT MAX(id) FROM %i WHERE created_at < %s', self::table(), $cutoff )
			);
			if ( $last_deleted < 1 ) {
				return 0;
			}
			return self::delete_head_through( $last_deleted, $reason );
		} finally {
			self::release_lock( $got_lock );
		}
	}

	/**
	 * Delete rows id <= $last_deleted and anchor the new first row.
	 * Caller holds the lock.
	 *
	 * @param int    $last_deleted Highest id to delete.
	 * @param string $reason       Reason stored with the anchor.
	 * @return int Rows deleted.
	 */
	private static function delete_head_through( int $last_deleted, string $reason ): int {
		global $wpdb;
		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table lookup.
		$first_kept = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MIN(id) FROM %i WHERE id > %d', $table, $last_deleted ) );

		$prev_hash = null;
		if ( $first_kept > 0 ) {
			$gaps = self::get_gaps();
			if ( isset( $gaps[ $first_kept ] ) ) {
				// Its real predecessor was already erased; keep that link's hash.
				$prev_hash = $gaps[ $first_kept ]['prev_hash'];
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Single-row lookup.
				$hash      = $wpdb->get_var( $wpdb->prepare( 'SELECT integrity_hash FROM %i WHERE id = %d', $table, $last_deleted ) );
				$prev_hash = ( null === $hash || '' === $hash ) ? null : (string) $hash;
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Retention delete on the plugin's own table.
		$deleted = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id <= %d', $table, $last_deleted ) );
		if ( false === $deleted ) {
			return 0;
		}

		if ( $first_kept > 0 ) {
			self::save_anchor( self::make_link( $first_kept, 0, $prev_hash, $reason ) );
			$gaps = array_filter(
				self::get_gaps(),
				static function ( $gap_id ) use ( $first_kept ) {
					return $gap_id > $first_kept;
				},
				ARRAY_FILTER_USE_KEY
			);
			self::save_gaps( $gaps );
		} else {
			self::clear_links();
		}

		return (int) $deleted;
	}

	/**
	 * Delete specific audit rows (anywhere in the table) and keep the chain
	 * verifiable. Used by the privacy eraser, which removes one user's rows.
	 *
	 * The removed rows' content is gone for good. For each row that is kept
	 * and whose predecessor is removed, a signed "gap link" records which
	 * kept row now precedes it and the hash it was really chained onto, so
	 * verify_chain() can step over the gap. A gap link only applies when the
	 * kept row before the gap is exactly the one recorded, so deleting more
	 * rows next to a gap outside the plugin is still detected. A gap that
	 * reaches the top of the table becomes the anchor instead.
	 *
	 * @param int[]  $ids    Row ids to delete.
	 * @param string $reason Short reason stored with the links.
	 * @return int Rows deleted.
	 */
	public static function delete_rows( array $ids, string $reason = 'privacy_erasure' ): int {
		global $wpdb;
		$table = self::table();

		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ), static fn( $id ) => $id > 0 ) ) );
		if ( ! $ids ) {
			return 0;
		}
		sort( $ids );
		$deleting = array_flip( $ids );

		$got_lock = self::acquire_lock();
		try {
			$gaps = self::get_gaps();

			// For each deleted row, find the next row. If that row is kept, it
			// needs a link: the hash it was chained onto (this row's hash, or
			// an existing link's hash if this row was already a gap's edge).
			$needs_link = array();
			foreach ( array_chunk( $ids, 500 ) as $chunk ) {
				$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders is a fixed list of %d.
				$sql = "SELECT d.id, d.integrity_hash, (SELECT MIN(n.id) FROM %i n WHERE n.id > d.id) AS next_id FROM %i d WHERE d.id IN ({$placeholders})";
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared here with %i/%d placeholders.
				$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( array( $table, $table ), $chunk ) ), ARRAY_A );
				foreach ( (array) $rows as $row ) {
					$next = (int) $row['next_id'];
					if ( $next < 1 || isset( $deleting[ $next ] ) ) {
						continue;
					}
					if ( isset( $gaps[ $next ] ) ) {
						$needs_link[ $next ] = $gaps[ $next ]['prev_hash'];
					} else {
						$hash                = $row['integrity_hash'];
						$needs_link[ $next ] = ( null === $hash || '' === $hash ) ? null : (string) $hash;
					}
				}
			}

			$deleted = 0;
			foreach ( array_chunk( $ids, 500 ) as $chunk ) {
				$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $placeholders is a fixed list of %d.
				$result   = $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE id IN ({$placeholders})", array_merge( array( $table ), $chunk ) ) );
				$deleted += (int) $result;
			}

			if ( ! self::table_has_rows() ) {
				self::clear_links();
				return $deleted;
			}

			$gaps = array_diff_key( $gaps, $deleting );
			foreach ( $needs_link as $kept_id => $prev_hash ) {
				$before = self::get_row_before( (int) $kept_id );
				if ( null === $before ) {
					self::save_anchor( self::make_link( (int) $kept_id, 0, $prev_hash, $reason ) );
					unset( $gaps[ $kept_id ] );
				} else {
					$gaps[ $kept_id ] = self::make_link( (int) $kept_id, $before['id'], $prev_hash, $reason );
				}
			}
			self::save_gaps( $gaps );

			return $deleted;
		} finally {
			self::release_lock( $got_lock );
		}
	}

	/**
	 * One-time repair for sites that pruned the head of the log before the
	 * anchor existed (a false "tampering detected" about 30 days after
	 * install). Runs from Activator::maybe_upgrade(), guarded by an option.
	 *
	 * @return void
	 */
	public static function maybe_repair_pruned_head(): void {
		if ( get_option( self::REPAIR_FLAG_OPTION ) ) {
			return;
		}
		if ( ! self::table_ready() ) {
			return; // Tables not created/upgraded yet; try again next time.
		}
		update_option( self::REPAIR_FLAG_OPTION, gmdate( 'c' ), false );
		self::repair_pruned_head();
	}

	/**
	 * Repair the chain only when the sole problem is head pruning.
	 *
	 * Conditions, all required:
	 *  - verify_chain() fails at the first chained row (checked = 1);
	 *  - that row is the lowest id in the table (nothing before it);
	 *  - every later row verifies when walked from that row's stored hash.
	 *
	 * Then an anchor with trusted_start_hash = that row's stored hash is saved.
	 * That one row's own content can't be re-verified (the hash it was chained
	 * onto was deleted before anchors existed), but any later edit to its
	 * stored hash, or to any later row, is still detected. If later rows also
	 * fail, nothing is repaired: that is real tampering or corruption.
	 *
	 * @return bool True when an anchor was saved.
	 */
	public static function repair_pruned_head(): bool {
		$result = self::verify_chain();
		if ( ! empty( $result['valid'] ) || 1 !== (int) $result['checked'] ) {
			return false;
		}
		$first_id = (int) $result['broken_at_id'];
		if ( $first_id < 1 || $first_id !== (int) $result['chain_start_id'] ) {
			return false;
		}
		if ( null !== self::get_row_before( $first_id ) ) {
			return false;
		}

		$rows = self::fetch_rows( $first_id );
		if ( ! $rows || (int) $rows[0]['id'] !== $first_id ) {
			return false;
		}
		$start_hash = (string) $rows[0]['integrity_hash'];
		$later      = self::walk( array_slice( $rows, 1 ), $first_id, $start_hash );
		if ( empty( $later['valid'] ) ) {
			return false;
		}

		self::save_anchor( self::make_link( $first_id, 0, null, 'retention_before_anchor', $start_hash ) );

		return ! empty( self::verify_chain()['valid'] );
	}

	/**
	 * The current anchor, if one exists and its signature checks out.
	 *
	 * @return array{id: int, prev_id: int, prev_hash: string|null, trusted_start_hash: string|null, set_at: string, reason: string}|null
	 */
	public static function get_anchor(): ?array {
		$anchor = self::normalize_link( get_option( self::ANCHOR_OPTION, null ) );
		return ( null !== $anchor && 0 === $anchor['prev_id'] ) ? $anchor : null;
	}

	/**
	 * Gap links keyed by the kept row id, signature-checked.
	 *
	 * @return array<int, array{id: int, prev_id: int, prev_hash: string|null, trusted_start_hash: string|null, set_at: string, reason: string}>
	 */
	private static function get_gaps(): array {
		$raw  = get_option( self::GAPS_OPTION, array() );
		$gaps = array();
		foreach ( is_array( $raw ) ? $raw : array() as $link ) {
			$link = self::normalize_link( $link );
			if ( null !== $link && $link['prev_id'] > 0 ) {
				$gaps[ $link['id'] ] = $link;
			}
		}
		return $gaps;
	}

	/**
	 * Build a signed link record.
	 *
	 * @param int         $id                 The kept row this link is for.
	 * @param int         $prev_id            Kept row before it (0 = none: anchor).
	 * @param string|null $prev_hash          Hash the row was chained onto.
	 * @param string      $reason             Why rows were removed.
	 * @param string|null $trusted_start_hash Repair anchors only.
	 * @return array
	 */
	private static function make_link( int $id, int $prev_id, ?string $prev_hash, string $reason, ?string $trusted_start_hash = null ): array {
		$link        = array(
			'id'                 => $id,
			'prev_id'            => $prev_id,
			'prev_hash'          => ( '' === $prev_hash ) ? null : $prev_hash,
			'trusted_start_hash' => ( '' === $trusted_start_hash ) ? null : $trusted_start_hash,
			'set_at'             => gmdate( 'Y-m-d H:i:s' ),
			'reason'             => $reason,
		);
		$link['sig'] = self::sign_link( $link );
		return $link;
	}

	/**
	 * HMAC over the fields verify_chain() relies on, with the same key as the
	 * row hashes, so someone with database access alone can't forge or edit
	 * an anchor or gap link.
	 *
	 * @param array $link Link fields.
	 * @return string
	 */
	private static function sign_link( array $link ): string {
		$payload = implode(
			'|',
			array(
				'audit-chain-link',
				(int) $link['id'],
				(int) $link['prev_id'],
				(string) ( $link['prev_hash'] ?? '' ),
				(string) ( $link['trusted_start_hash'] ?? '' ),
			)
		);
		return hash_hmac( 'sha256', $payload, Abilities_Manifest::get_integrity_key() );
	}

	/**
	 * Validate a stored link; null when malformed or the signature fails.
	 *
	 * @param mixed $link Stored value.
	 * @return array|null
	 */
	private static function normalize_link( $link ): ?array {
		if ( ! is_array( $link ) || empty( $link['id'] ) || ! isset( $link['sig'] ) ) {
			return null;
		}
		$clean = array(
			'id'                 => (int) $link['id'],
			'prev_id'            => (int) ( $link['prev_id'] ?? 0 ),
			'prev_hash'          => isset( $link['prev_hash'] ) && '' !== $link['prev_hash'] ? (string) $link['prev_hash'] : null,
			'trusted_start_hash' => isset( $link['trusted_start_hash'] ) && '' !== $link['trusted_start_hash'] ? (string) $link['trusted_start_hash'] : null,
			'set_at'             => (string) ( $link['set_at'] ?? '' ),
			'reason'             => (string) ( $link['reason'] ?? '' ),
		);
		if ( ! hash_equals( self::sign_link( $clean ), (string) $link['sig'] ) ) {
			return null;
		}
		return $clean;
	}

	/**
	 * Save the anchor.
	 *
	 * @param array $link Signed link with prev_id 0.
	 * @return void
	 */
	private static function save_anchor( array $link ): void {
		update_option( self::ANCHOR_OPTION, $link, false );
	}

	/**
	 * Save gap links (re-keyed list).
	 *
	 * @param array $gaps Links keyed by id.
	 * @return void
	 */
	private static function save_gaps( array $gaps ): void {
		if ( ! $gaps ) {
			delete_option( self::GAPS_OPTION );
			return;
		}
		ksort( $gaps );
		$stored = array();
		foreach ( $gaps as $link ) {
			$link['sig'] = self::sign_link( $link );
			$stored[]    = $link;
		}
		update_option( self::GAPS_OPTION, $stored, false );
	}

	/**
	 * Remove the anchor and gap links (the table is empty).
	 *
	 * @return void
	 */
	private static function clear_links(): void {
		delete_option( self::ANCHOR_OPTION );
		delete_option( self::GAPS_OPTION );
	}

	/**
	 * Whether the audit table has any rows.
	 *
	 * @return bool
	 */
	private static function table_has_rows(): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Existence check.
		return null !== $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i LIMIT 1', self::table() ) );
	}

	/**
	 * Whether the audit table and its integrity_hash column exist.
	 *
	 * @return bool
	 */
	private static function table_ready(): bool {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check.
		return null !== $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'integrity_hash' ) );
	}

	/**
	 * The audit log table name.
	 *
	 * @return string
	 */
	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'agent_builder_audit_log';
	}

	/**
	 * Take the advisory lock shared by record() and the delete helpers.
	 *
	 * @return bool Whether the lock was taken.
	 */
	private static function acquire_lock(): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Advisory lock, not a data query.
		return 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::lock_name(), 5 ) );
	}

	/**
	 * Release the advisory lock if it was taken.
	 *
	 * @param bool $got_lock Result of acquire_lock().
	 * @return void
	 */
	private static function release_lock( bool $got_lock ): void {
		global $wpdb;
		if ( $got_lock ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Releasing the advisory lock.
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::lock_name() ) );
		}
	}

	/**
	 * Advisory lock name, unique per database + table prefix.
	 *
	 * @return string
	 */
	private static function lock_name(): string {
		global $wpdb;
		return 'ab_audit_' . substr( md5( DB_NAME . '|' . $wpdb->prefix ), 0, 20 );
	}
}
