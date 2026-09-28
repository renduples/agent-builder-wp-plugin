<?php
/**
 * Plugin activation handler.
 *
 * Everything that runs on register_activation_hook.
 *
 * Activation itself stays light: just the idempotent dbDelta table creation,
 * default options, and a pre-flight check. All heavy data seeding (bundled
 * agents, ~276 tools, ~33 skills, demo/OKF knowledge) is deferred out of the
 * activation request and runs chunked (one step per request) and lock-guarded
 * on admin_init — see maybe_run_deferred_seed(). Every step is wrapped so a
 * DB hiccup or hosting resource limit degrades the plugin, never the site.
 *
 * Define AGENT_BUILDER_SAFE_MODE as true in wp-config.php to disable all of
 * this plugin's background work (deferred seeding + cron) without
 * deactivating it — a one-line escape hatch for a struggling host, or an
 * admin locked out of wp-admin who still has file access.
 *
 * @package Agent_Builder
 * @since   2.3.0
 */

declare(strict_types=1);

namespace Agentic;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Activator
 *
 * @since 2.3.0
 */
final class Activator {

	/**
	 * Structured log collected throughout activation.
	 * Written to the audit log and to agent_builder_last_activation_log at the end.
	 *
	 * @var array<int, array{step: string, status: string, details: mixed}>
	 */
	private static array $activation_log = array();

	/**
	 * Append one step to the in-memory activation log.
	 * Errors are also sent to error_log() immediately for PHP debug log visibility.
	 *
	 * @param string $step    Short identifier for the step, e.g. 'create_table'.
	 * @param string $status  'ok', 'skipped', 'warning', or 'error'.
	 * @param mixed  $details Any serialisable value (string, array, etc.).
	 */
	private static function record( string $step, string $status, mixed $details = null ): void {
		self::$activation_log[] = array(
			'step'    => $step,
			'status'  => $status,
			'details' => $details,
			'time'    => gmdate( 'Y-m-d H:i:s' ),
		);

		if ( in_array( $status, array( 'error', 'warning' ), true ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional activation debug output.
			error_log( '[Agent Builder activation] ' . $step . ' [' . $status . ']: ' . ( is_string( $details ) ? $details : wp_json_encode( $details ) ) );
		}
	}

	/**
	 * Persist the site's schema version under the current option name, for
	 * display (see class-dashboard-rest.php) and as a baseline for any
	 * future migration this plugin needs once it has real installs.
	 *
	 * @param string $version Schema version to record.
	 * @return void
	 */
	private static function set_db_schema_version( string $version ): void {
		update_option( 'agent_builder_db_schema_version', $version );
	}

	/**
	 * List of heavy data-seeding steps run deferred, one per admin_init hit,
	 * behind the agent_builder_needs_seed flag. Order matters: agents must
	 * exist before tools/skills are synced against them.
	 *
	 * @var string[]
	 */
	private const SEED_STEPS = array(
		'import_agents_dir',
		'activate_bundled_agents',
		'seed_bundled_agents',
		'seed_tools',
		'seed_skills',
		'seed_okf_examples',
	);

	/**
	 * Number of consecutive failed legacy-export cleanup attempts before the
	 * one-time sweep gives up and asks for manual intervention instead of
	 * re-running (and re-failing) on every request forever — the stuck file is
	 * a permissions/ownership problem a human must fix.
	 */
	private const LEGACY_EXPORT_CLEANUP_MAX_FAILURES = 10;

	/**
	 * Option name and TTL (seconds) for the atomic schema-upgrade lock. See
	 * acquire_upgrade_lock()/release_upgrade_lock().
	 *
	 * The TTL is how long a lock is held before it is treated as abandoned and
	 * eligible for compare-and-swap takeover. It is deliberately conservative:
	 * dbDelta() can run an ALTER against a large, populated table that takes
	 * minutes, and a short TTL would let a second request mistake a slow-but-
	 * alive migration for a dead owner and run DDL against the same tables
	 * concurrently. Ten minutes is far longer than any real migration, so an
	 * expired lock almost always means the owning process actually died.
	 */
	private const UPGRADE_LOCK_KEY = 'agent_builder_upgrade_lock';
	private const UPGRADE_LOCK_TTL = 600;

	/**
	 * Whether the site owner has thrown the safe-mode breaker.
	 *
	 * Defining AGENT_BUILDER_SAFE_MODE as true in wp-config.php disables all
	 * of this plugin's background work — deferred seeding and cron — without
	 * deactivating it. Meant as a one-line escape hatch (added via File
	 * Manager/SSH) for a struggling host or an admin locked out of wp-admin.
	 *
	 * @return bool
	 */
	public static function is_safe_mode(): bool {
		return defined( 'AGENT_BUILDER_SAFE_MODE' ) && AGENT_BUILDER_SAFE_MODE;
	}

	/**
	 * Run a single activation/seeding step, catching every \Throwable so a
	 * DB hiccup or an unexpected exception in one step can never fatal the
	 * request or leave the site unable to load. Failures are logged and flip
	 * the agent_builder_activation_degraded flag (surfaced as an admin
	 * notice); they never propagate.
	 *
	 * @param string   $step Step identifier for the log.
	 * @param callable $callback Zero-arg callable performing the step.
	 * @return bool True on success, false if the step threw or errored.
	 */
	private static function guarded_step( string $step, callable $callback ): bool {
		try {
			$callback();
			return true;
		} catch ( \Throwable $e ) {
			self::record( $step, 'error', 'Uncaught: ' . $e->getMessage() );
			update_option( 'agent_builder_activation_degraded', true );
			return false;
		}
	}

	/**
	 * Light pre-flight check run before any heavy work: a trivial $wpdb
	 * write/read round-trip, plus PHP/MySQL minimums. If this fails we skip
	 * straight to a safe/degraded activation instead of bursting dbDelta and
	 * seed queries into an environment that is already struggling.
	 *
	 * @return bool True when the environment looks healthy enough for heavy work.
	 */
	private static function preflight_check(): bool {
		global $wpdb;

		if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
			self::record( 'preflight_check', 'error', 'PHP ' . PHP_VERSION . ' is below the required 8.1' );
			return false;
		}

		$db_version = method_exists( $wpdb, 'db_version' ) ? $wpdb->db_version() : '';
		if ( $db_version && version_compare( $db_version, '5.6', '<' ) ) {
			self::record( 'preflight_check', 'error', 'Database version ' . $db_version . ' is below the required 5.6' );
			return false;
		}

		// Trivial write/read round-trip through wp_options, proving the DB
		// connection has headroom before we run 10+ dbDelta calls into it.
		$probe_key   = 'agent_builder_preflight_probe';
		$probe_value = (string) wp_generate_password( 12, false, false );
		$written     = add_option( $probe_key, $probe_value, '', 'no' );
		$read_back   = get_option( $probe_key );
		delete_option( $probe_key );

		if ( ! $written || $read_back !== $probe_value ) {
			self::record( 'preflight_check', 'error', 'trivial $wpdb write/read round-trip failed' );
			return false;
		}

		self::record(
			'preflight_check',
			'ok',
			array(
				'php_version' => PHP_VERSION,
				'db_version'  => $db_version,
			)
		);
		return true;
	}

	/**
	 * Merge whatever has been recorded on self::$activation_log this request
	 * into the persisted agent_builder_last_activation_log option, then
	 * reset the in-memory collector. Used by the deferred/chunked seeder
	 * (which runs across many separate admin_init requests) so its steps
	 * accumulate into the same history the activation request wrote,
	 * instead of clobbering it or growing unbounded.
	 *
	 * @param string $schema_version Current DB_SCHEMA_VERSION.
	 * @return void
	 */
	private static function flush_deferred_log( string $schema_version ): void {
		if ( empty( self::$activation_log ) ) {
			return;
		}

		$existing = get_option( 'agent_builder_last_activation_log', array() );
		if ( ! is_array( $existing ) || ! isset( $existing['steps'] ) || ! is_array( $existing['steps'] ) ) {
			$existing = array(
				'schema_version' => $schema_version,
				'activated_at'   => gmdate( 'Y-m-d H:i:s' ),
				'steps'          => array(),
			);
		}

		$existing['steps'] = array_merge( $existing['steps'], self::$activation_log );
		// Cap history so this option can never grow unbounded across many
		// deferred admin_init hits.
		if ( count( $existing['steps'] ) > 200 ) {
			$existing['steps'] = array_slice( $existing['steps'], -200 );
		}

		update_option( 'agent_builder_last_activation_log', $existing );
		self::$activation_log = array();
	}

	/**
	 * Sync stored schema version after a plugin upgrade.
	 *
	 * register_activation_hook does not fire on WP.org auto-updates, so
	 * agent_builder_db_schema_version would otherwise stay on the previous
	 * value and the dashboard Schema tile would never catch up.
	 *
	 * Runs on every request (hooked on plugins_loaded) so the first cron,
	 * REST or frontend hit after an auto-update brings the schema current —
	 * not just the first admin visit. No-op (no DB writes) when the stored
	 * option already equals AGENT_BUILDER_DB_VERSION. When behind, re-runs
	 * create_tables() (dbDelta, idempotent, no data loss) then writes the
	 * current constant. An atomic lock (see acquire_upgrade_lock()) keeps
	 * near-simultaneous requests (a cron run and a REST hit landing
	 * together) from racing into create_tables(); on failure the lock row is
	 * left in place so a broken site backs off for the lock's TTL instead
	 * of re-running the full dbDelta pass on every request. A failure is
	 * logged and leaves the stored version behind so it retries later,
	 * instead of silently marking a failed upgrade complete.
	 *
	 * Also flips agent_builder_needs_seed so the deferred/chunked seeder
	 * fills in any bundled tools/skills/agents added since the site's last
	 * seed — existing data is never touched, only gaps are filled.
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		// Version-independent: runs regardless of whether the stored schema
		// version already equals AGENT_BUILDER_DB_VERSION. See
		// maybe_add_awaiting_tool_call_id_column() for why this can't ride
		// along with the version-gated path below.
		self::maybe_add_awaiting_tool_call_id_column();

		$stored = (string) get_option( 'agent_builder_db_schema_version', '' );
		if ( AGENT_BUILDER_DB_VERSION === $stored ) {
			return;
		}

		// Atomic lock: a cron run and a REST request landing together during the
		// upgrade window must not both race into create_tables()/dbDelta().
		// Released only on success; left in place on failure so the retry backs
		// off — the TTL is both the backoff window and the safety net if the
		// process dies mid-upgrade.
		$lock_value = self::acquire_upgrade_lock();
		if ( null === $lock_value ) {
			return; // Another request is already running (or recently failed).
		}

		self::$activation_log = array();
		self::guarded_step( 'maybe_upgrade_create_tables', array( __CLASS__, 'create_tables' ) );
		$tables_ok = 'ok' === self::last_log_status( 'create_tables' );
		if ( ! $tables_ok ) {
			update_option( 'agent_builder_activation_degraded', true );
		}
		self::flush_deferred_log( AGENT_BUILDER_DB_VERSION );

		if ( ! $tables_ok ) {
			return; // Leave the lock as a 60s backoff and the version behind for retry.
		}

		self::set_db_schema_version( AGENT_BUILDER_DB_VERSION );

		if ( ! self::is_safe_mode() ) {
			update_option( 'agent_builder_needs_seed', true );
		}

		self::release_upgrade_lock( $lock_value );
	}

	/**
	 * Atomically acquire the schema-upgrade lock.
	 *
	 * The lock is a single wp_options row named agent_builder_upgrade_lock whose
	 * value is a JSON blob carrying this request's unique owner token plus its
	 * expiry time. Acquisition is atomic, unlike a transient's get-then-set
	 * (which has a read-then-write window): the INSERT relies on wp_options'
	 * unique key on option_name, so a duplicate-key failure IS the "someone else
	 * already holds it" signal. A stale lock — one whose expiry has passed,
	 * meaning its owner is no longer renewing it and is presumed dead — is taken
	 * over with a compare-and-swap UPDATE that includes the old value in its
	 * WHERE clause, so two requests racing to take over the same expired lock
	 * cannot both win. The TTL is deliberately long (UPGRADE_LOCK_TTL, 10
	 * minutes): a dbDelta() ALTER against a large table can legitimately run
	 * for minutes, and a shorter expiry would let a second request mistake a
	 * slow-but-alive migration for a dead one and start concurrent DDL.
	 *
	 * @return string|null The exact option_value this request wrote (to be passed
	 *                     back to release_upgrade_lock()), or null if another
	 *                     request currently holds a live lock.
	 */
	private static function acquire_upgrade_lock(): ?string {
		global $wpdb;

		$new_value = wp_json_encode(
			array(
				'token'   => wp_generate_password( 12, false ),
				'expires' => time() + self::UPGRADE_LOCK_TTL,
			)
		);

		// Atomic acquire: a duplicate-key INSERT fails, which is the atomic
		// "someone else holds it" signal. Suppress the expected duplicate-key
		// error so it never reaches the log.
		$suppressed = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Raw write: this is a lock row, not an option read through the options API.
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				self::UPGRADE_LOCK_KEY,
				$new_value
			)
		);
		$wpdb->suppress_errors( $suppressed );

		if ( $inserted ) {
			return $new_value;
		}

		// The row already exists. Read it directly, bypassing get_option() so
		// the alloptions cache can't serve a stale copy of a raw-written row.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Raw read: get_option() may serve a stale cached value for a raw-written lock row.
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
				self::UPGRADE_LOCK_KEY
			)
		);

		if ( null === $existing ) {
			// The owner released the row between our failed INSERT and this read.
			// Retry the INSERT once; if it still fails, someone else won it.
			$suppressed = $wpdb->suppress_errors( true );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Raw write: this is a lock row, not an option read through the options API.
			$inserted = $wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
					self::UPGRADE_LOCK_KEY,
					$new_value
				)
			);
			$wpdb->suppress_errors( $suppressed );

			return $inserted ? $new_value : null;
		}

		$decoded = json_decode( $existing, true );
		$expires = is_array( $decoded ) && isset( $decoded['expires'] ) ? (int) $decoded['expires'] : 0;

		if ( time() < $expires ) {
			return null; // Live lock — another request owns it.
		}

		// Stale lock: compare-and-swap. The WHERE clause pins the exact value we
		// just read, so the UPDATE only lands if nobody else took the row over
		// first (0 affected rows = lost the race).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Raw write: this is a lock row, not an option read through the options API.
		$taken = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$new_value,
				self::UPGRADE_LOCK_KEY,
				$existing
			)
		);

		return ( 1 === (int) $taken ) ? $new_value : null;
	}

	/**
	 * Release the schema-upgrade lock — but only if the row still holds this
	 * request's exact value. If this request's TTL expired mid-migration and a
	 * newer request took the lock over, the value no longer matches, so this
	 * DELETE is a no-op and the newer request's lock is left intact. A request
	 * can never release a lock it does not own.
	 *
	 * @param string $lock_value Exact option_value returned by acquire_upgrade_lock().
	 * @return void
	 */
	private static function release_upgrade_lock( string $lock_value ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Raw delete: this is a lock row, not an option deleted through the options API.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				self::UPGRADE_LOCK_KEY,
				$lock_value
			)
		);
	}

	/**
	 * One-time, version-independent migration for the awaiting_tool_call_id
	 * column added to the runs table by the M10b resume-path fix.
	 *
	 * Deliberately decoupled from AGENT_BUILDER_DB_VERSION: the next value in
	 * the programme's schema plan (2.15.1) is reserved for M11's own schema
	 * work, so this column can't be folded into a version bump here — a site
	 * that got 2.15.1 from this fix (with only this column added) would then
	 * wrongly skip M11's real migration, since maybe_upgrade()'s
	 * `AGENT_BUILDER_DB_VERSION === $stored` check would already match.
	 *
	 * Tracked by its own option so it runs at most once per site, checks the
	 * column directly (never assumes anything about the stored schema
	 * version), and is a safe no-op if the table doesn't exist yet (a fresh
	 * activation's create_tables() already includes this column).
	 *
	 * @return void
	 */
	private static function maybe_add_awaiting_tool_call_id_column(): void {
		if ( get_option( 'agent_builder_awaiting_tool_call_id_migrated' ) ) {
			return;
		}

		try {
			global $wpdb;
			$table = $wpdb->prefix . 'agent_builder_runs';

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table is an internal prefix + literal name, not user input.
			if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
				return; // Table not created yet — nothing to migrate; leave unmigrated so this retries later.
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is an internal prefix + literal name, not user input.
			$column_exists = (bool) $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'awaiting_tool_call_id' ) );

			if ( ! $column_exists ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- schema migration; $table is trusted, no user input.
				$altered = $wpdb->query( "ALTER TABLE {$table} ADD COLUMN awaiting_tool_call_id varchar(64) DEFAULT NULL AFTER awaiting_id" );

				if ( false === $altered ) {
					// ALTER reports failure via a false return + $wpdb->last_error,
					// never a thrown exception — leave unmigrated so this retries
					// on the next admin_init instead of permanently suppressing it.
					return;
				}

				// Re-verify rather than trust a truthy query result: confirm the
				// column is actually there before marking this migration done.
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is an internal prefix + literal name, not user input.
				$column_exists = (bool) $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'awaiting_tool_call_id' ) );

				if ( ! $column_exists ) {
					return; // Still missing — retry on the next admin_init.
				}
			}

			update_option( 'agent_builder_awaiting_tool_call_id_migrated', true );
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional migration debug output.
			error_log( '[Agent Builder] maybe_add_awaiting_tool_call_id_column failed: ' . $e->getMessage() );
			// Leave unmigrated — retried on the next admin_init.
		}
	}

	/**
	 * Retry entry point for the "finished setup in a reduced state" admin
	 * notice. Clears the degraded flag and re-arms deferred seeding so the
	 * next admin_init picks up wherever it left off (seed_progress is left
	 * intact — steps already completed are not repeated).
	 *
	 * @return void
	 */
	public static function retry_activation(): void {
		delete_option( 'agent_builder_activation_degraded' );
		if ( ! self::is_safe_mode() ) {
			update_option( 'agent_builder_needs_seed', true );
		}
	}

	/**
	 * Handle the "Retry now" link on the reduced-state admin notice.
	 * Hooked on admin_init (before headers are sent) so it can redirect back
	 * to the clean URL once done.
	 *
	 * @return void
	 */
	public static function maybe_handle_retry_request(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! isset( $_GET['agentic_retry_activation_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['agentic_retry_activation_nonce'] ) ), 'agentic_retry_activation' ) ) {
			return;
		}

		self::retry_activation();

		wp_safe_redirect( remove_query_arg( array( 'agentic_retry_activation', 'agentic_retry_activation_nonce' ) ) );
		exit;
	}

	/**
	 * Cron safety net for safe mode: if AGENT_BUILDER_SAFE_MODE is switched on
	 * after cron events were already scheduled (e.g. the site owner throws
	 * the breaker on a struggling host), actively unschedule them instead of
	 * relying only on future schedule_cron_events() calls being skipped.
	 *
	 * @return void
	 */
	public static function maybe_disable_cron_for_safe_mode(): void {
		if ( ! self::is_safe_mode() ) {
			return;
		}

		if ( wp_next_scheduled( 'agent_builder_cleanup_audit_log' ) ) {
			wp_clear_scheduled_hook( 'agent_builder_cleanup_audit_log' );
		}
		if ( wp_next_scheduled( 'agent_builder_costs_check_alerts' ) ) {
			wp_clear_scheduled_hook( 'agent_builder_costs_check_alerts' );
		}
	}

	/**
	 * One-time cleanup of legacy agent-export zips left behind by earlier
	 * revisions of the exporter, before it was moved to build archives in a
	 * temp file outside the web root and stream them through an authenticated
	 * admin-post handler (nothing new writes either of these locations now).
	 *
	 * Two legacy locations are cleared:
	 *   - wp-content/agentic-exports/ — the intermediate revision's export
	 *     working directory (the now-removed AGENT_BUILDER_EXPORTS_DIR). No
	 *     other code ever wrote here, so the whole directory tree is removed.
	 *   - wp_upload_dir()['basedir'] . '/agentic-exports/<slug>.zip' — the
	 *     original pre-fix exporter's predictable-filename output, inside the
	 *     public uploads tree. This directory is shared with unrelated document
	 *     tools (create_docx, create_pdf, create_spreadsheet, merge_pdfs,
	 *     html_to_docx), none of which write a .zip there, so only its *.zip
	 *     files are touched.
	 *
	 * This is a security cleanup, not a schema migration, so it runs from an
	 * early, always-fires hook (init — see agent-builder.php) rather than
	 * waiting for an admin to visit wp-admin: an unremediated legacy zip is
	 * exploitable every second it sits there, including on cron/REST/frontend
	 * requests where admin_init never fires. It is a filesystem delete, not a
	 * privileged UI action, so it is deliberately NOT gated on
	 * current_user_can() — it must also run for anonymous visitors. A short
	 * transient lock prevents concurrent/overlapping sweeps.
	 *
	 * Only marks itself done when every legacy file/directory is actually gone:
	 * if any delete fails (permissions, in-use), the flag is left unset so the
	 * next request retries instead of silently leaving a still-downloadable
	 * file exposed forever.
	 *
	 * @return void
	 */
	public static function maybe_cleanup_legacy_agent_exports(): void {
		if ( get_option( 'agent_builder_legacy_exports_cleaned' ) || get_option( 'agent_builder_legacy_exports_gave_up' ) ) {
			return;
		}

		$lock_key = 'agent_builder_legacy_exports_lock';
		if ( get_transient( $lock_key ) ) {
			return; // Another request is already running this one-time sweep.
		}
		set_transient( $lock_key, 1, 30 );

		try {
			$all_removed = true;

			// wp-content/agentic-exports/ — the intermediate revision's export
			// directory, now fully legacy. Remove the entire tree, not just the
			// zips inside it (nothing else ever wrote here).
			$exports_dir = untrailingslashit( WP_CONTENT_DIR ) . '/agentic-exports';
			if ( is_dir( $exports_dir ) && ! File_Manager::rmdir( $exports_dir, true ) ) {
				$all_removed = false;
			}

			// uploads/agentic-exports/<slug>.zip — the original pre-fix output.
			// Only the <slug>.zip files the pre-fix exporter actually named are
			// ours here; the directory is shared with unrelated document tools
			// (and manual backups/future tools may drop a .zip of their own), so
			// it is never removed wholesale and only slug-shaped zips are touched.
			$legacy_dir = untrailingslashit( wp_upload_dir()['basedir'] ) . '/agentic-exports';
			if ( is_dir( $legacy_dir ) ) {
				$zips = glob( $legacy_dir . '/*.zip' );
				if ( false === $zips ) {
					// The directory exists but could not be scanned (unreadable).
					// Treat that as "not done" rather than "no zips": if the
					// process can't see into it, it cannot have deleted its
					// contents, and the flag must stay unset so a later request
					// retries.
					$all_removed = false;
				} else {
					foreach ( $zips as $zip ) {
						if ( ! self::is_legacy_export_name( basename( $zip ) ) ) {
							continue; // Not ours — belongs to another tool/backup.
						}
						if ( ! wp_delete_file( $zip ) ) {
							$all_removed = false;
						}
					}
				}
			}

			// Only mark the migration done once every legacy file/directory is
			// actually gone — a single failed delete leaves the flag unset so the
			// next request retries rather than abandoning a still-exposed file.
			// But retrying forever is itself harmful: a permanently-stuck file
			// (bad permissions/owner) would otherwise make every request re-run
			// this sweep indefinitely. Count consecutive failures and give up
			// after a short run, surfacing the problem to an admin instead.
			if ( $all_removed ) {
				update_option( 'agent_builder_legacy_exports_cleaned', true );
				delete_option( 'agent_builder_legacy_exports_fail_count' );
				delete_option( 'agent_builder_legacy_exports_gave_up' );
			} else {
				self::record_legacy_export_cleanup_failure();
			}
		} finally {
			delete_transient( $lock_key );
		}
	}

	/**
	 * Whether a filename is a legacy agent-export name: <slug>.zip, where a
	 * slug is the lowercase [a-z0-9_-] string the pre-fix exporter used
	 * (sanitize_key / sanitize_title output). This narrows the sweep away from
	 * every .zip in the shared uploads/agentic-exports/ directory — which
	 * unrelated document tools and manual backups may also use — to only files
	 * the exporter itself named.
	 *
	 * @param string $basename File basename, e.g. 'content-writer.zip'.
	 * @return bool
	 */
	private static function is_legacy_export_name( string $basename ): bool {
		return 1 === preg_match( '/^[a-z0-9_-]+\.zip$/', $basename );
	}

	/**
	 * Count one failed legacy-export cleanup attempt and, once the failure
	 * count crosses LEGACY_EXPORT_CLEANUP_MAX_FAILURES, give up: set the
	 * give-up flag (which stops the sweep re-running on every request) and log
	 * an error so a human intervenes. The admin notice surfaced by
	 * Admin_Notice_Manager::show_legacy_exports_stuck_notice() reads the same
	 * flag.
	 *
	 * @return void
	 */
	private static function record_legacy_export_cleanup_failure(): void {
		$failures = (int) get_option( 'agent_builder_legacy_exports_fail_count', 0 ) + 1;
		update_option( 'agent_builder_legacy_exports_fail_count', $failures );

		if ( $failures >= self::LEGACY_EXPORT_CLEANUP_MAX_FAILURES ) {
			update_option( 'agent_builder_legacy_exports_gave_up', true );
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operator-facing signal for a stuck, manual-intervention cleanup; the admin notice surfaces the same state in wp-admin.
			error_log( '[Agent Builder] Legacy agent-export cleanup gave up after ' . $failures . ' failed attempts — remove the remaining files under wp-content/uploads/agentic-exports/ and wp-content/agentic-exports/ manually.' );
		}
	}

	/**
	 * Handle the "Retry now" link on the stuck-legacy-exports admin notice.
	 * Hooked on admin_init (before headers are sent) so it can clear the
	 * give-up state, re-run the sweep, and redirect back to a clean URL.
	 *
	 * @return void
	 */
	public static function maybe_handle_legacy_exports_retry_request(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! isset( $_GET['agentic_retry_legacy_exports_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['agentic_retry_legacy_exports_nonce'] ) ), 'agentic_retry_legacy_exports' ) ) {
			return;
		}

		delete_option( 'agent_builder_legacy_exports_gave_up' );
		delete_option( 'agent_builder_legacy_exports_fail_count' );

		self::maybe_cleanup_legacy_agent_exports();

		wp_safe_redirect( remove_query_arg( array( 'agentic_retry_legacy_exports', 'agentic_retry_legacy_exports_nonce' ) ) );
		exit;
	}

	/**
	 * Run all activation tasks.
	 *
	 * Wrapped in an outer catch-all so activation can never fatal or leave
	 * WordPress unable to load, no matter what goes wrong inside — every
	 * individual step already guards itself via guarded_step(), this is the
	 * last-resort net around the whole method.
	 *
	 * @param string $schema_version Current DB_SCHEMA_VERSION from Plugin class.
	 * @return void
	 */
	public static function activate( string $schema_version ): void {
		try {
			self::do_activate( $schema_version );
		} catch ( \Throwable $e ) {
			self::record( 'activate_fatal_guard', 'error', 'Uncaught: ' . $e->getMessage() );
			update_option( 'agent_builder_activation_degraded', true );
			update_option( 'agent_builder_needs_seed', false );
			try {
				update_option(
					'agent_builder_last_activation_log',
					array(
						'schema_version' => $schema_version,
						'activated_at'   => gmdate( 'Y-m-d H:i:s' ),
						'steps'          => self::$activation_log,
					)
				);
			} catch ( \Throwable $ignored ) {
				unset( $ignored ); // Never let logging itself take the site down.
			}
		}
	}

	/**
	 * Actual activation body. Kept light and fast: create the (idempotent)
	 * tables, set defaults, and — only when a pre-flight check passes and
	 * safe mode is off — arm the deferred/chunked seeder. The heavy data
	 * seeding itself (bundled agents, ~276 tools, ~33 skills, demo/OKF
	 * knowledge) never runs in this request; see maybe_run_deferred_seed().
	 *
	 * @param string $schema_version Current DB_SCHEMA_VERSION from Plugin class.
	 * @return void
	 */
	private static function do_activate( string $schema_version ): void {
		// Reset collector so repeated activations don't accumulate across requests.
		self::$activation_log = array();

		self::record(
			'activate_start',
			'ok',
			array(
				'schema_version' => $schema_version,
				'wp_version'     => get_bloginfo( 'version' ),
				'php_version'    => PHP_VERSION,
			)
		);

		self::guarded_step( 'set_flags', array( __CLASS__, 'set_flags' ) );
		self::guarded_step( 'create_tables', array( __CLASS__, 'create_tables' ) ); // Security-log table is created here — safe to log after this point.
		if ( 'ok' !== self::last_log_status( 'create_tables' ) ) {
			// create_tables() never throws on a dbDelta/$wpdb error (it just
			// records a 'warning' with the per-table errors) — but the site
			// still hit a DB error during setup, so this must still degrade
			// and notify, per requirement 1.
			update_option( 'agent_builder_activation_degraded', true );
		} else {
			// A fresh install's create_tables() already includes
			// awaiting_tool_call_id in its dbDelta SQL, so the standalone
			// migration in maybe_upgrade() would just be a no-op query on
			// every admin_init until it flips this flag — set it now instead.
			update_option( 'agent_builder_awaiting_tool_call_id_migrated', true );
		}
		self::guarded_step( 'set_default_options', array( __CLASS__, 'set_default_options' ) );

		$preflight_ok = self::guarded_step( 'preflight_check', array( __CLASS__, 'preflight_check' ) )
			&& 'ok' === self::last_log_status( 'preflight_check' );

		if ( self::is_safe_mode() ) {
			update_option( 'agent_builder_needs_seed', false );
			self::record( 'defer_seed', 'skipped', 'AGENT_BUILDER_SAFE_MODE defined — heavy seeding stays off' );
		} elseif ( ! $preflight_ok ) {
			update_option( 'agent_builder_needs_seed', false );
			update_option( 'agent_builder_activation_degraded', true );
			self::record( 'defer_seed', 'skipped', 'pre-flight check failed — activating in a reduced/safe state' );
		} else {
			delete_option( 'agent_builder_seed_progress' );
			update_option( 'agent_builder_needs_seed', true );
			self::record( 'defer_seed', 'ok', 'heavy seeding deferred to admin_init, chunked one step per request and lock-guarded' );
		}

		// Cron safety (requirement 5): never schedule anything when safe mode
		// is on, and never schedule on top of an environment that just
		// failed its pre-flight check.
		if ( self::is_safe_mode() ) {
			self::record( 'schedule_cron_events', 'skipped', 'AGENT_BUILDER_SAFE_MODE defined' );
		} elseif ( ! $preflight_ok ) {
			self::record( 'schedule_cron_events', 'skipped', 'pre-flight check failed' );
		} else {
			self::guarded_step( 'schedule_cron_events', array( __CLASS__, 'schedule_cron_events' ) );
		}

		self::guarded_step(
			'finalize',
			static function () use ( $schema_version ): void {
				flush_rewrite_rules();
				self::set_db_schema_version( $schema_version );
			}
		);

		// Persist the full activation log to an option (readable even if tables failed).
		update_option(
			'agent_builder_last_activation_log',
			array(
				'schema_version' => $schema_version,
				'activated_at'   => gmdate( 'Y-m-d H:i:s' ),
				'steps'          => self::$activation_log,
			)
		);

		// Security log is now available — record the activation event. Guarded:
		// this is a nice-to-have record, never a reason to fail activation.
		self::guarded_step(
			'security_log',
			static function () use ( $schema_version ): void {
				\Agentic\Security_Log::log_system(
					'plugin_activated',
					'agent-builder',
					array(
						'version'        => AGENT_BUILDER_VERSION,
						'schema_version' => $schema_version,
						'wp_version'     => get_bloginfo( 'version' ),
						'php_version'    => PHP_VERSION,
					)
				);
			}
		);
	}

	/**
	 * Read back the status of the most recent self::$activation_log entry
	 * for a given step, so callers can branch on a guarded_step()'s actual
	 * outcome (including a non-exception 'warning', e.g. create_tables()
	 * completing but a $wpdb error occurring on one table) rather than just
	 * whether it threw.
	 *
	 * @param string $step Step identifier as passed to record()/guarded_step().
	 * @return string|null 'ok', 'warning', 'error', 'skipped', or null if not logged.
	 */
	private static function last_log_status( string $step ): ?string {
		foreach ( array_reverse( self::$activation_log ) as $entry ) {
			if ( $step === $entry['step'] ) {
				return $entry['status'];
			}
		}
		return null;
	}

	/**
	 * Deferred, chunked, lock-guarded heavy data seeding.
	 *
	 * Hooked on admin_init. No-ops immediately unless agent_builder_needs_seed
	 * is set, safe mode is off, and no other request is already seeding
	 * (a short transient lock prevents concurrent/overlapping runs). Runs at
	 * most one heavy step (see SEED_STEPS) per request, so a site owner can
	 * keep using wp-admin normally while the rest trickles in over the next
	 * few page loads. Progress is tracked in an option so it resumes across
	 * requests and correctly stops once every step is done.
	 *
	 * @return void
	 */
	public static function maybe_run_deferred_seed(): void {
		if ( self::is_safe_mode() ) {
			return;
		}

		if ( ! get_option( 'agent_builder_needs_seed' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return; // Only run while an admin is actually browsing wp-admin.
		}

		$lock_key = 'agent_builder_seed_lock';
		if ( get_transient( $lock_key ) ) {
			return; // Another request is already seeding this chunk.
		}
		set_transient( $lock_key, 1, 30 );

		self::$activation_log = array();
		$schema_version       = defined( 'AGENT_BUILDER_DB_VERSION' ) ? AGENT_BUILDER_DB_VERSION : '';

		try {
			$finished = self::run_seed_chunk( $schema_version );
			if ( $finished ) {
				delete_option( 'agent_builder_needs_seed' );
				delete_option( 'agent_builder_seed_progress' );

				// Every step eventually succeeded (possibly after a transient
				// failure self-healed on a later automatic attempt) — the
				// "reduced state" notice no longer applies to seeding.
				$had_error = false;
				foreach ( self::$activation_log as $entry ) {
					if ( 'error' === $entry['status'] ) {
						$had_error = true;
						break;
					}
				}
				if ( ! $had_error ) {
					delete_option( 'agent_builder_activation_degraded' );
				}
			}
		} catch ( \Throwable $e ) {
			self::record( 'deferred_seed_chunk', 'error', 'Uncaught: ' . $e->getMessage() );
			update_option( 'agent_builder_activation_degraded', true );
		} finally {
			self::flush_deferred_log( $schema_version );
			delete_transient( $lock_key );
		}
	}

	/**
	 * Run the next not-yet-done step of SEED_STEPS, then return.
	 *
	 * @param string $schema_version Current DB_SCHEMA_VERSION.
	 * @return bool True once every step has been completed.
	 */
	private static function run_seed_chunk( string $schema_version ): bool {
		$progress = get_option( 'agent_builder_seed_progress', array() );
		if ( ! is_array( $progress ) ) {
			$progress = array();
		}

		foreach ( self::SEED_STEPS as $step ) {
			if ( ! empty( $progress[ $step ] ) ) {
				continue;
			}

			$ok = self::guarded_step(
				$step,
				function () use ( $step, $schema_version ): void {
					switch ( $step ) {
						case 'import_agents_dir':
							self::import_agents_dir();
							break;
						case 'activate_bundled_agents':
							self::activate_bundled_agents();
							break;
						case 'seed_bundled_agents':
							self::seed_bundled_agents();
							break;
						case 'seed_tools':
							self::seed_tools( $schema_version );
							break;
						case 'seed_skills':
							self::seed_skills( $schema_version );
							break;
						case 'seed_okf_examples':
							// Demo Knowledge Wiki concepts (example: true — hidden from agents).
							if ( class_exists( __NAMESPACE__ . '\\Okf_Store' ) ) {
								$okf_seeded = Okf_Store::seed_examples();
								self::record( 'seed_okf_examples', 'ok', array( 'wrote' => $okf_seeded ) );
							}
							break;
					}
				}
			);

			if ( ! $ok ) {
				// Leave this step un-advanced so it is retried (rate-limited
				// by the 30s lock) on a later admin_init instead of being
				// silently skipped forever.
				return false;
			}

			$progress[ $step ] = true;
			update_option( 'agent_builder_seed_progress', $progress );

			// One heavy step per request keeps every hit light.
			return count( $progress ) >= count( self::SEED_STEPS );
		}

		return true; // Nothing left to do.
	}

	/**
	 * Set one-time activation flags.
	 *
	 * @return void
	 */
	private static function set_flags(): void {
		// Welcome admin notice.
		add_option( 'agent_builder_show_welcome_notice', true );

		self::record(
			'set_flags',
			'ok',
			array(
				'agent_builder_show_welcome_notice' => 'added',
			)
		);
	}

	/**
	 * Set default plugin options (only if they don't already exist).
	 *
	 * @return void
	 */
	private static function set_default_options(): void {
		$results = array();

		// Read the default model from the provider table (single source of truth).
		$agentic_provider = \Agentic\Provider_Registry::get( 'agentic' );
		$default_model    = $agentic_provider['default_model'] ?? 'gemini-2.5-flash';

		$defaults = array(
			'agent_builder_agent_mode'              => 'supervised',
			'agent_builder_audit_enabled'           => true,
			'agent_builder_llm_provider'            => 'agentic',
			'agent_builder_model'                   => $default_model,
			// Default chat chrome for new installs (admin + frontend shortcode).
			'agent_builder_chat_theme'              => 'light',
			// GDPR: default retention to 30 days so data is not kept indefinitely on fresh installs.
			'agent_builder_chat_tts'                => '1',
			'agent_builder_chat_whitelabel'         => '1',
			'agent_builder_show_whatsapp_cta'       => '0',
			'agent_builder_allow_platform_sync'     => '0',
			'agent_builder_retention_conversations' => 30,
			'agent_builder_retention_audit_log'     => 30,
			\Agentic\Usage_Limits::OPTION_KEY       => \Agentic\Usage_Limits::get_install_defaults(),
			// Gutenberg sidebar: enabled by default so new installs have it working out of the box.
			'agent_builder_editor_sidebar_settings' => array(
				'enabled'         => '1',
				'agent_slug'      => 'content-writer',
				'agent_slugs'     => array( 'content-writer', 'seo-optimizer', 'wordpress-assistant' ),
				'post_types'      => array( 'post', 'page' ),
				'inject_context'  => '1',
				'agent_mode'      => 'autonomous',
				'toolbar_enabled' => '1',
			),
		);

		// Options that are large or only read on specific admin/editor screens
		// are not autoloaded on every request (performance).
		$no_autoload = array(
			'agent_builder_editor_sidebar_settings',
			'agent_builder_retention_conversations',
			'agent_builder_retention_audit_log',
			\Agentic\Usage_Limits::OPTION_KEY,
		);

		foreach ( $defaults as $key => $value ) {
			$autoload        = in_array( $key, $no_autoload, true ) ? 'no' : 'yes';
			$results[ $key ] = add_option( $key, $value, '', $autoload ) ? 'added' : 'already_exists';
		}

		self::record( 'set_default_options', 'ok', $results );
	}

	/**
	 * Activate all bundled library agents.
	 *
	 * Scans the library directory and ensures every bundled agent
	 * is present in the agent_builder_active_agents option.
	 *
	 * @return void
	 */
	private static function activate_bundled_agents(): void {
		$library_dirs  = apply_filters( 'agentic_library_dirs', array( AGENT_BUILDER_DIR . 'library/agents' ) );
		$bundled_slugs = array();

		foreach ( $library_dirs as $library_dir ) {
			if ( ! is_dir( $library_dir ) ) {
				self::record( 'activate_bundled_agents', 'warning', 'library directory not found: ' . $library_dir );
				continue;
			}

			$folders = scandir( $library_dir );

			if ( ! is_array( $folders ) ) {
				self::record( 'activate_bundled_agents', 'error', 'scandir() failed on library directory: ' . $library_dir );
				continue;
			}

			foreach ( $folders as $folder ) {
				if ( '.' === $folder || '..' === $folder || 'README.md' === $folder ) {
					continue;
				}

				$agent_path = $library_dir . '/' . $folder;

				// Must be a directory with an agent.php file.
				if ( is_dir( $agent_path ) && ( file_exists( $agent_path . '/agent.php' ) || file_exists( $agent_path . '/agent.json' ) ) ) {
					$bundled_slugs[] = $folder;
				}
			}
		}

		$bundled_slugs = array_unique( $bundled_slugs );

		if ( empty( $bundled_slugs ) ) {
			self::record( 'activate_bundled_agents', 'warning', 'no agent directories found in library' );
			return;
		}

		$active_agents = get_option( 'agent_builder_active_agents', array() );
		$merged        = array_unique( array_merge( $active_agents, $bundled_slugs ) );
		$newly_added   = array_diff( $bundled_slugs, $active_agents );

		update_option( 'agent_builder_active_agents', array_values( $merged ) );

		// Generate abilities.json integrity hashes for bundled agents.
		include_once AGENT_BUILDER_DIR . 'includes/class-abilities-manifest.php';
		$hash_results = array();
		foreach ( $bundled_slugs as $slug ) {
			$saved                 = Abilities_Manifest::save_integrity_hash( $slug );
			$hash_results[ $slug ] = $saved ? 'hash_saved' : 'hash_failed';
			if ( ! $saved ) {
				self::record( 'integrity_hash', 'warning', "save_integrity_hash() returned false for agent '$slug'" );
			}
		}

		self::record(
			'activate_bundled_agents',
			'ok',
			array(
				'found'        => $bundled_slugs,
				'newly_added'  => array_values( $newly_added ),
				'total_active' => count( $merged ),
				'hashes'       => $hash_results,
			)
		);
	}

	/**
	 * Import on-disk agents from the writable agents directory into the library
	 * table, so a custom agent a site owner drops directly into that directory
	 * (bypassing the plugin's own upload UI) still shows up after activation.
	 *
	 * This reads the writable directory as DATA only — it never includes or
	 * executes agent.php from there (WP.org Guideline 8). Declarative
	 * agent.json agents import directly; legacy agent.php agents are
	 * synthesised into a manifest ONLY when every declared tool resolves to a
	 * registered library tool, otherwise they are recorded for admin-assisted
	 * migration and never silently dropped.
	 *
	 * Idempotency is tracked in an option ledger (not a marker file) so it holds
	 * even when the directory is not writable during CLI activation, and so an
	 * agent the user later deletes from the library is not re-imported.
	 *
	 * @param string|null $dir Directory to import from. Defaults to AGENT_BUILDER_AGENTS_DIR.
	 * @return void
	 */
	private static function import_agents_dir( ?string $dir = null ): void {
		if ( ! class_exists( '\Agentic\Agent_Library' ) ) {
			return;
		}

		if ( null === $dir ) {
			$dir = defined( 'AGENT_BUILDER_AGENTS_DIR' ) ? AGENT_BUILDER_AGENTS_DIR : WP_CONTENT_DIR . '/agentic-agents';
		}

		if ( ! is_dir( $dir ) ) {
			return;
		}

		$folders = scandir( $dir );
		if ( ! is_array( $folders ) ) {
			return;
		}

		$imported_ledger = get_option( 'agent_builder_agents_dir_imported', array() );
		if ( ! is_array( $imported_ledger ) ) {
			$imported_ledger = array();
		}

		$needs_migration = get_option( 'agent_builder_agents_needing_migration', array() );
		if ( ! is_array( $needs_migration ) ) {
			$needs_migration = array();
		}

		$imported = array();
		$flagged  = array();

		foreach ( $folders as $folder ) {
			// Skip dotfiles/dot-dirs and non-directories.
			if ( '' === $folder || '.' === $folder[0] ) {
				continue;
			}

			$path = trailingslashit( $dir ) . $folder;
			if ( ! is_dir( $path ) ) {
				continue;
			}

			// Already imported once — never re-import (owner may have since deleted it).
			if ( in_array( $folder, $imported_ledger, true ) ) {
				continue;
			}

			// A row already owns this slug — respect it, mark handled.
			if ( \Agentic\Agent_Library::get_by_slug( $folder ) ) {
				$imported_ledger[] = $folder;
				continue;
			}

			$has_json = file_exists( $path . '/agent.json' );
			$has_php  = file_exists( $path . '/agent.php' );

			if ( ! $has_json && ! $has_php ) {
				continue; // Not an agent directory (e.g. a shared library).
			}

			$source = file_exists( $path . '/.requires-license' ) ? 'purchased' : 'user';

			// Declarative agent: import the manifest as-is.
			if ( $has_json ) {
				$manifest = \Agentic\Agent_Manifest_Validator::from_file( $path . '/agent.json' );
				if ( is_array( $manifest ) ) {
					self::import_manifest_row( $folder, $manifest, $source );
					$imported_ledger[] = $folder;
					$imported[]        = $folder;
				}
				continue;
			}

			// Legacy PHP agent: convert to a manifest ONLY if it is "thin" — every
			// declared tool resolves to a registered library tool. The PHP file is
			// never executed; tool names come from abilities.json (data).
			$header = self::read_agent_php_header( $path . '/agent.php' );
			$tools  = self::read_abilities_tool_names( $path . '/abilities.json' );

			if ( null !== $tools && '' !== ( $header['name'] ?? '' ) && self::all_tools_resolve( $tools ) ) {
				$manifest = self::synthesise_manifest( $folder, $header, $tools, $path );
				self::import_manifest_row( $folder, $manifest, $source );
				$imported_ledger[] = $folder;
				$imported[]        = $folder;
				continue;
			}

			// Heavy / undetermined agent: do NOT execute, do NOT drop. Record for
			// admin-assisted migration. Not added to the ledger, so a later
			// resolution (e.g. converted agent.json) is still picked up.
			$reason                     = ( null === $tools ) ? 'no_abilities_manifest' : 'unresolved_tools';
			$needs_migration[ $folder ] = array(
				'slug'   => $folder,
				'name'   => $header['name'] ?? $folder,
				'reason' => $reason,
				'tools'  => $tools ?? array(),
			);
			$flagged[]                  = $folder;
		}

		update_option( 'agent_builder_agents_dir_imported', array_values( array_unique( $imported_ledger ) ) );
		update_option( 'agent_builder_agents_needing_migration', $needs_migration );

		self::record(
			'import_agents_dir',
			'ok',
			array(
				'dir'             => $dir,
				'imported'        => $imported,
				'needs_migration' => $flagged,
			)
		);
	}

	/**
	 * Upsert a manifest agent discovered in the writable directory into the table.
	 *
	 * @param string               $slug     Directory slug.
	 * @param array<string, mixed> $manifest Validated manifest.
	 * @param string               $source   'user' or 'purchased'.
	 * @return void
	 */
	private static function import_manifest_row( string $slug, array $manifest, string $source ): void {
		\Agentic\Agent_Library::upsert(
			array(
				'slug'     => $slug,
				'name'     => (string) ( $manifest['name'] ?? $slug ),
				'manifest' => $manifest,
				'kind'     => 'manifest',
				'source'   => $source,
				'origin'   => 'agents_dir_import',
				'version'  => (string) ( $manifest['version'] ?? '1.0.0' ),
				'author'   => (string) ( $manifest['author'] ?? '' ),
			)
		);
	}

	/**
	 * Read an agent.php plugin-style header as DATA (never executes the file).
	 *
	 * @param string $file Absolute path to agent.php.
	 * @return array<string, string>
	 */
	private static function read_agent_php_header( string $file ): array {
		$headers = array(
			'name'         => 'Agent Name',
			'version'      => 'Version',
			'description'  => 'Description',
			'author'       => 'Author',
			'author_uri'   => 'Author URI',
			'category'     => 'Category',
			'capabilities' => 'Capabilities',
			'icon'         => 'Icon',
		);
		$data    = get_file_data( $file, $headers );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Read declared tool names from an abilities.json as DATA.
	 *
	 * @param string $file Absolute path to abilities.json.
	 * @return string[]|null List of tool names, or null when no manifest exists.
	 */
	private static function read_abilities_tool_names( string $file ): ?array {
		if ( ! file_exists( $file ) ) {
			return null;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read as data during activation; WP_Filesystem unavailable.
		$json = file_get_contents( $file );
		if ( false === $json ) {
			return null;
		}
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			return null;
		}
		$tools = array();
		if ( isset( $data['abilities'] ) && is_array( $data['abilities'] ) ) {
			$tools = array_keys( $data['abilities'] );
		}
		if ( ! empty( $data['wp_abilities'] ) && is_array( $data['wp_abilities'] ) ) {
			foreach ( $data['wp_abilities'] as $entry ) {
				if ( ! empty( $entry['name'] ) ) {
					$tools[] = (string) $entry['name'];
				}
			}
		}
		return array_values( array_unique( array_map( 'strval', $tools ) ) );
	}

	/**
	 * Whether every tool name resolves to a registered library tool.
	 *
	 * @param string[] $tools Tool names.
	 * @return bool True when the list is non-empty and all tools resolve.
	 */
	private static function all_tools_resolve( array $tools ): bool {
		if ( empty( $tools ) || ! class_exists( '\Agentic\Tools_Registry' ) ) {
			return false;
		}
		foreach ( $tools as $tool ) {
			if ( null === \Agentic\Tools_Registry::get( (string) $tool ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Build a manifest for a thin legacy agent from its header, tools, and
	 * (optionally) its on-disk system prompt template — all read as data.
	 *
	 * @param string                $slug   Directory slug.
	 * @param array<string, string> $header Parsed agent.php header.
	 * @param string[]              $tools  Resolved tool names.
	 * @param string                $path   Absolute agent directory path.
	 * @return array<string, mixed> Validated manifest.
	 */
	private static function synthesise_manifest( string $slug, array $header, array $tools, string $path ): array {
		$caps = array();
		if ( ! empty( $header['capabilities'] ) ) {
			$caps = array_filter( array_map( 'trim', explode( ',', $header['capabilities'] ) ) );
		}

		$raw = array(
			'slug'         => $slug,
			'name'         => $header['name'] ?? $slug,
			'description'  => $header['description'] ?? '',
			'version'      => $header['version'] ?? '1.0.0',
			'author'       => $header['author'] ?? '',
			'author_uri'   => $header['author_uri'] ?? '',
			'category'     => $header['category'] ?? 'admin',
			'icon'         => $header['icon'] ?? '🤖',
			'capabilities' => array_values( $caps ),
			'tools'        => $tools,
		);

		$prompt_file = $path . '/templates/system-prompt.txt';
		if ( file_exists( $prompt_file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local template read as data during activation.
			$prompt = file_get_contents( $prompt_file );
			if ( is_string( $prompt ) && '' !== trim( $prompt ) ) {
				$raw['system_prompt'] = $prompt;
			}
		}

		$manifest = \Agentic\Agent_Manifest_Validator::validate( $raw );
		return is_wp_error( $manifest ) ? $raw : $manifest;
	}

	/**
	 * Mirror every bundled agent shipped in the plugin into the library table.
	 *
	 * Runs on activation/upgrade. Bundled agents live as reviewed files under
	 * library/agents/{slug} (agent.json for declarative agents, agent.php for
	 * reviewed PHP ones). Their rows are the single-source-derived mirror:
	 * declarative agents seed kind=manifest; PHP agents seed kind=php with a
	 * path, and the registry keeps running those from the reviewed file. Because
	 * the file also wins the registry's directory scan, these rows serve
	 * uniformity and queryability, not runtime dispatch.
	 *
	 * A PHP agent's row is DERIVED at seed time (header + abilities.json), so the
	 * reviewed class stays the one authored source and cannot drift.
	 *
	 * Version rule (§8.5): a newer bundled version overwrites, but only a
	 * source=bundled row the admin has not edited since we last seeded it (its
	 * stored hash still matches our recorded seed hash). An edited row is left
	 * intact and flagged "update available" instead of being clobbered. Rows
	 * owned by purchased/user sources are never touched.
	 *
	 * @param string|null $dirs Library dirs to scan. Defaults to the filtered set.
	 * @return void
	 */
	private static function seed_bundled_agents( ?array $dirs = null ): void {
		if ( ! class_exists( '\Agentic\Agent_Library' ) ) {
			return;
		}

		$library_dirs = $dirs ?? apply_filters( 'agentic_library_dirs', array( AGENT_BUILDER_DIR . 'library/agents' ) );

		$seed_hashes = get_option( 'agent_builder_bundled_seed_hashes', array() );
		$seed_hashes = is_array( $seed_hashes ) ? $seed_hashes : array();

		$updates = get_option( 'agent_builder_bundled_updates_available', array() );
		$updates = is_array( $updates ) ? $updates : array();

		$seeded  = array();
		$skipped = array();
		$flagged = array();

		foreach ( $library_dirs as $library_dir ) {
			if ( ! is_dir( $library_dir ) ) {
				continue;
			}

			$origin  = self::origin_for_library_dir( $library_dir );
			$folders = scandir( $library_dir );
			if ( ! is_array( $folders ) ) {
				continue;
			}

			foreach ( $folders as $folder ) {
				if ( '' === $folder || '.' === $folder[0] || 'README.md' === $folder ) {
					continue;
				}

				$path = trailingslashit( $library_dir ) . $folder;
				if ( ! is_dir( $path ) ) {
					continue;
				}

				$has_json = file_exists( $path . '/agent.json' );
				$has_php  = file_exists( $path . '/agent.php' );

				if ( $has_json ) {
					$manifest = \Agentic\Agent_Manifest_Validator::from_file( $path . '/agent.json' );
					if ( ! is_array( $manifest ) ) {
						continue;
					}
					$kind     = 'manifest';
					$row_path = '';
				} elseif ( $has_php ) {
					$header = self::read_agent_php_header( $path . '/agent.php' );
					if ( '' === ( $header['name'] ?? '' ) ) {
						continue;
					}
					$tools    = self::read_abilities_tool_names( $path . '/abilities.json' ) ?? array();
					$manifest = self::synthesise_manifest( $folder, $header, $tools, $path );
					$kind     = 'php';
					// Portable, informational reference only — the registry always
					// loads PHP agents from the reviewed library directory scan, not
					// from this column, so a relative slug path suffices.
					$row_path = $folder . '/agent.php';
				} else {
					continue;
				}

				$version  = (string) ( $manifest['version'] ?? '1.0.0' );
				$new_hash = hash( 'sha256', (string) wp_json_encode( $manifest ) );
				$row      = \Agentic\Agent_Library::get_by_slug( $folder );

				if ( ! $row ) {
					self::upsert_bundled_row( $folder, $manifest, $kind, $row_path, $origin, $version );
					$seed_hashes[ $folder ] = $new_hash;
					unset( $updates[ $folder ] );
					$seeded[] = $folder;
					continue;
				}

				if ( 'bundled' !== ( $row['source'] ?? '' ) ) {
					$skipped[] = $folder; // purchased/user owns this slug.
					continue;
				}

				if ( ! version_compare( $version, (string) ( $row['version'] ?? '0' ), '>' ) ) {
					$skipped[] = $folder; // same or older.
					continue;
				}

				$prev_seed = (string) ( $seed_hashes[ $folder ] ?? '' );
				if ( '' === $prev_seed || ( $row['hash'] ?? '' ) === $prev_seed ) {
					// Unmodified since our last seed — clean upgrade.
					self::upsert_bundled_row( $folder, $manifest, $kind, $row_path, $origin, $version );
					$seed_hashes[ $folder ] = $new_hash;
					unset( $updates[ $folder ] );
					$seeded[] = $folder;
				} else {
					// Admin edited the bundled row — never clobber; surface a notice.
					$updates[ $folder ] = array(
						'slug' => $folder,
						'from' => (string) ( $row['version'] ?? '' ),
						'to'   => $version,
					);
					$flagged[]          = $folder;
				}
			}
		}

		update_option( 'agent_builder_bundled_seed_hashes', $seed_hashes );
		update_option( 'agent_builder_bundled_updates_available', $updates );

		self::record(
			'seed_bundled_agents',
			'ok',
			array(
				'seeded'  => $seeded,
				'skipped' => $skipped,
				'flagged' => $flagged,
			)
		);
	}

	/**
	 * Upsert a bundled-agent row with a consistent field bag.
	 *
	 * @param string               $slug     Agent slug.
	 * @param array<string, mixed> $manifest Derived/validated manifest.
	 * @param string               $kind     'manifest' or 'php'.
	 * @param string               $path     Relative-safe path for php rows, '' otherwise.
	 * @param string               $origin   Owning plugin origin.
	 * @param string               $version  Agent version.
	 * @return void
	 */
	private static function upsert_bundled_row( string $slug, array $manifest, string $kind, string $path, string $origin, string $version ): void {
		\Agentic\Agent_Library::upsert(
			array(
				'slug'        => $slug,
				'name'        => (string) ( $manifest['name'] ?? $slug ),
				'description' => (string) ( $manifest['description'] ?? '' ),
				'manifest'    => $manifest,
				'kind'        => $kind,
				'path'        => $path,
				'source'      => 'bundled',
				'origin'      => $origin,
				'version'     => $version,
				'author'      => (string) ( $manifest['author'] ?? '' ),
			)
		);
	}

	/**
	 * Derive the origin tag for a library directory so plugin deactivation can
	 * later disable only its own rows.
	 *
	 * @param string $dir Absolute library directory path.
	 * @return string
	 */
	private static function origin_for_library_dir( string $dir ): string {
		if ( false !== strpos( $dir, 'agent-builder-pro' ) ) {
			return 'agent-builder-pro';
		}
		if ( defined( 'AGENT_BUILDER_DIR' ) && 0 === strpos( $dir, AGENT_BUILDER_DIR ) ) {
			return 'agent-builder';
		}
		return 'agent-builder';
	}

	/**
	 * Create all custom database tables.
	 *
	 * @return void
	 */
	private static function create_tables(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		// Audit log table.
		$sql_audit = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}agent_builder_audit_log (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            agent_id varchar(64) NOT NULL,
            action varchar(128) NOT NULL,
            target_type varchar(64),
            target_id varchar(128),
            details longtext,
            reasoning text,
            mode varchar(32) DEFAULT '',
            provider varchar(64) DEFAULT '',
            tokens_used int unsigned DEFAULT 0,
            cost decimal(10,6) DEFAULT 0,
            user_id bigint(20) unsigned,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            agent_author varchar(191) DEFAULT '',
            agent_version varchar(32) DEFAULT '',
            integrity_hash char(64) DEFAULT NULL,
            run_id varchar(36) DEFAULT NULL,
            PRIMARY KEY (id),
            KEY agent_id (agent_id),
            KEY action (action),
            KEY created_at (created_at),
            KEY user_created (user_id, created_at),
            KEY idx_agent_created (agent_id, created_at),
            KEY run_id (run_id)
        ) $charset_collate;";

		// Approval queue table.
		$sql_queue = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}agent_builder_approval_queue (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            agent_id varchar(64) NOT NULL,
            action varchar(128) NOT NULL,
            params longtext NOT NULL,
            reasoning text,
            risk_level varchar(32) DEFAULT 'none',
            status varchar(32) DEFAULT 'pending',
            approved_by bigint(20) unsigned,
            approved_at datetime,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            expires_at datetime,
            executed_at datetime DEFAULT NULL,
            mode varchar(32) DEFAULT '',
            invocation varchar(32) DEFAULT '',
            run_id varchar(36) DEFAULT NULL,
            user_id bigint(20) unsigned,
            PRIMARY KEY (id),
            KEY status (status),
            KEY created_at (created_at),
            KEY idx_status_created (status, created_at),
            KEY idx_expires (expires_at),
            KEY run_id (run_id)
        ) $charset_collate;";

		// Memory table.
		$sql_memory = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}agent_builder_memory (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            memory_type varchar(50) NOT NULL,
            entity_id varchar(100) NOT NULL,
            memory_key varchar(255) NOT NULL,
            memory_value longtext NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            expires_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY memory_type_entity (memory_type, entity_id),
            KEY memory_type_created (memory_type, created_at),
            KEY memory_key (memory_key),
            KEY idx_expires (expires_at)
        ) $charset_collate;";

		// Tools registry table.
		$sql_tools = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}agent_builder_tools (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(128) NOT NULL,
            description text NOT NULL,
            category varchar(64) NOT NULL DEFAULT 'WordPress',
            source varchar(64) NOT NULL DEFAULT 'core',
            enabled tinyint(1) NOT NULL DEFAULT 1,
            risk_level varchar(32) NOT NULL DEFAULT 'none',
            parameters longtext,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY name (name),
            KEY category (category),
            KEY source (source),
            KEY enabled (enabled)
        ) $charset_collate;";

		include_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_results = array();
		$table_errors  = array();

		// Helper to run dbDelta and capture any error.
		//
		// dbDelta() cannot parse "CREATE TABLE IF NOT EXISTS" — its regex takes
		// the first word after "CREATE TABLE " as the table name, so it reads
		// "IF" as the table name and silently operates on a bogus table called
		// "IF" instead of the real one. Every $sql_* string below is written
		// with "IF NOT EXISTS" (dbDelta already no-ops safely on a table that
		// already exists with matching columns, so it was never needed), which
		// means every schema change to an already-existing table — a widened
		// column, a new column, a new index — has been silently applying to
		// nothing on any site that installed before that change shipped. Strip
		// it here rather than rewrite 10 SQL strings, so this is fixed for
		// every table at once.
		$run_delta = function ( string $name, string $sql ) use ( $wpdb, &$table_results, &$table_errors ): void {
			$sql                    = preg_replace( '/CREATE TABLE IF NOT EXISTS/i', 'CREATE TABLE', $sql, 1 );
			$wpdb->last_error       = '';
			$delta                  = dbDelta( $sql );
			$table_results[ $name ] = $delta;
			if ( $wpdb->last_error ) {
				$table_errors[ $name ] = $wpdb->last_error;
			}
		};

		$run_delta( 'agent_builder_audit_log', $sql_audit );
		$run_delta( 'agent_builder_approval_queue', $sql_queue );
		$run_delta( 'agent_builder_memory', $sql_memory );
		$run_delta( 'agent_builder_tools', $sql_tools );

		// Conversations table — stores each chat turn for efficient session browsing.
		$sql_conversations = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}agent_builder_conversations (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            session_id varchar(36) NOT NULL,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            agent_id varchar(64) NOT NULL DEFAULT '',
            role varchar(16) NOT NULL DEFAULT 'user',
            content longtext NOT NULL,
            tools_used text,
            feedback tinyint(1) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY session_user (session_id, user_id),
            KEY user_agent (user_id, agent_id),
            KEY created_at (created_at)
        ) $charset_collate;";
		$run_delta( 'agent_builder_conversations', $sql_conversations );

		// Agent settings table.
		$sql_agent_settings = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}agent_builder_agent_settings (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            agent_slug varchar(128) NOT NULL,
            meta_key varchar(128) NOT NULL,
            meta_value longtext,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY agent_key (agent_slug, meta_key),
            KEY agent_slug (agent_slug),
            KEY meta_key (meta_key)
        ) $charset_collate;";
		$run_delta( 'agent_builder_agent_settings', $sql_agent_settings );

		// Providers table — stores LLM provider configuration and Agentic service endpoints.
		$sql_providers = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}agent_builder_providers (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            slug varchar(100) NOT NULL,
            name varchar(255) NOT NULL DEFAULT '',
            endpoint text,
            default_model varchar(255) NOT NULL DEFAULT '',
            vision_model varchar(255) NOT NULL DEFAULT '',
            auth_type varchar(50) NOT NULL DEFAULT 'bearer',
            req_format varchar(50) NOT NULL DEFAULT 'openai',
            resp_format varchar(50) NOT NULL DEFAULT 'openai',
            requires_key tinyint(1) NOT NULL DEFAULT 1,
            api_key text,
            key_url varchar(2048) NOT NULL DEFAULT '',
            icon text,
            models longtext,
            model_pricing longtext,
            is_builtin tinyint(1) NOT NULL DEFAULT 0,
            sort_order int(11) NOT NULL DEFAULT 99,
            provider_type varchar(32) NOT NULL DEFAULT 'llm',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug),
            KEY sort_order (sort_order),
            KEY provider_type (provider_type)
        ) $charset_collate;";
		$run_delta( 'agent_builder_providers', $sql_providers );

		// Skills table. agent_slug holds a JSON array of agent slugs (e.g.
		// '["content-writer","seo-optimizer"]'), or '' to mean every agent —
		// see Skills_Registry::normalize_agent_slugs()/decode_agent_slugs().
		// Widened from varchar(128) in 3.3.90 to fit multiple slugs.
		$sql_skills = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}agent_builder_skills (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            slug varchar(255) NOT NULL,
            description text,
            content longtext,
            agent_slug varchar(1024) NOT NULL DEFAULT '',
            source varchar(64) NOT NULL DEFAULT 'local',
            source_id varchar(255) NOT NULL DEFAULT '',
            version varchar(32) NOT NULL DEFAULT '1.0.0',
            author varchar(255) NOT NULL DEFAULT '',
            source_hash varchar(64) NOT NULL DEFAULT '',
            enabled tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug),
            KEY agent_slug (agent_slug),
            KEY source (source),
            KEY enabled (enabled)
        ) $charset_collate;";
		$run_delta( 'agent_builder_skills', $sql_skills );

		// Orchestration runs table — one row per general run context: an
		// autonomous task, routine, event, delegation, prompt test, or (Pro)
		// workflow. Tracks delegation depth, fan-out, accumulated tokens/cost,
		// iteration/tool progress, and a small JSON scratchpad + transcript
		// shared across delegated agents / resumed after a pause.
		$sql_runs = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}agent_builder_runs (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            run_id varchar(36) NOT NULL,
            root_agent varchar(64) NOT NULL DEFAULT '',
            kind varchar(24) NOT NULL DEFAULT 'task',
            status varchar(16) NOT NULL DEFAULT 'running',
            user_id bigint(20) unsigned,
            task_text text,
            parent_run_id varchar(36) DEFAULT NULL,
            job_id varchar(36) DEFAULT NULL,
            session_id varchar(64) DEFAULT NULL,
            invocation varchar(32) DEFAULT NULL,
            source_ref varchar(191) DEFAULT NULL,
            delegations int unsigned NOT NULL DEFAULT 0,
            max_depth smallint unsigned NOT NULL DEFAULT 0,
            iterations int unsigned NOT NULL DEFAULT 0,
            tokens_used int unsigned NOT NULL DEFAULT 0,
            cost decimal(10,6) NOT NULL DEFAULT 0,
            tools_used text,
            result_summary longtext,
            error text,
            awaiting_type varchar(16) DEFAULT NULL,
            awaiting_id varchar(36) DEFAULT NULL,
            awaiting_tool_call_id varchar(64) DEFAULT NULL,
            cancel_requested tinyint(1) NOT NULL DEFAULT 0,
            state longtext,
            started_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            finished_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY run_id (run_id),
            KEY root_agent (root_agent),
            KEY status (status),
            KEY started_at (started_at),
            KEY user_status (user_id, status),
            KEY parent_run_id (parent_run_id),
            KEY source_ref (source_ref),
            KEY kind_started (kind, started_at)
        ) $charset_collate;";
		$run_delta( 'agent_builder_runs', $sql_runs );

		// Agent library — one row per agent, whatever its origin. Declarative
		// agents (kind=manifest) are interpreted from the manifest column by
		// Manifest_Agent; reviewed PHP agents (kind=php) keep running from their
		// file and the row is a version/record anchor. Dropped on uninstall when
		// the admin opted into full data deletion (see uninstall.php); otherwise
		// left in place so a reinstall finds existing purchased/user rows.
		$sql_agent_library = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}agent_builder_agent_library (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            slug varchar(128) NOT NULL,
            name varchar(255) NOT NULL,
            description text,
            manifest longtext NOT NULL,
            kind varchar(16) NOT NULL DEFAULT 'manifest',
            path varchar(255) NOT NULL DEFAULT '',
            source varchar(32) NOT NULL DEFAULT 'user',
            origin varchar(64) NOT NULL DEFAULT '',
            source_id varchar(255) NOT NULL DEFAULT '',
            version varchar(32) NOT NULL DEFAULT '1.0.0',
            author varchar(255) NOT NULL DEFAULT '',
            hash char(64) NOT NULL DEFAULT '',
            enabled tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug),
            KEY source (source),
            KEY origin (origin),
            KEY enabled (enabled)
        ) $charset_collate;";
		$run_delta( 'agent_builder_agent_library', $sql_agent_library );

		// Ensure Job_Manager, Security_Log, and Deployments are available (activation fires early).
		include_once AGENT_BUILDER_DIR . 'includes/class-job-manager.php';
		include_once AGENT_BUILDER_DIR . 'includes/class-security-log.php';
		include_once AGENT_BUILDER_DIR . 'includes/class-deployments.php';

		// Create jobs table.
		$wpdb->last_error = '';
		Job_Manager::create_table();
		if ( $wpdb->last_error ) {
			$table_errors['agent_builder_jobs'] = $wpdb->last_error;
		} else {
			$table_results['agent_builder_jobs'] = 'ok';
		}

		// Create security log table.
		$wpdb->last_error = '';
		Security_Log::create_table();
		if ( $wpdb->last_error ) {
			$table_errors['agent_builder_security_log'] = $wpdb->last_error;
		} else {
			$table_results['agent_builder_security_log'] = 'ok';
		}

		// Create deployments table.
		$wpdb->last_error = '';
		Deployments::create_table();
		if ( $wpdb->last_error ) {
			$table_errors['agent_builder_deployments'] = $wpdb->last_error;
		} else {
			$table_results['agent_builder_deployments'] = 'ok';
		}

		$status = empty( $table_errors ) ? 'ok' : 'warning';
		self::record(
			'create_tables',
			$status,
			array(
				'tables' => $table_results,
				'errors' => $table_errors,
			)
		);
	}

	/**
	 * Seed core tools and sync agent-contributed tools into the registry.
	 *
	 * @param string $schema_version Current DB_SCHEMA_VERSION.
	 * @return void
	 */
	public static function seed_tools( string $schema_version ): void {
		global $wpdb;

		// Skip if already seeded for this version.
		$seeded_version = get_option( 'agent_builder_tools_seeded_version', '' );
		if ( $seeded_version === $schema_version ) {
			self::record(
				'seed_tools',
				'skipped',
				array(
					'reason'         => 'already_seeded',
					'schema_version' => $schema_version,
				)
			);
			return;
		}

		// Safety: skip if the tools table doesn't exist yet.
		$table = $wpdb->prefix . 'agent_builder_tools';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			self::record( 'seed_tools', 'error', 'tools table does not exist — skipping seed' );
			return;
		}

		// Ensure dependencies are loaded (activation fires before init).
		include_once AGENT_BUILDER_DIR . 'includes/class-risk-level.php';
		include_once AGENT_BUILDER_DIR . 'includes/class-tool-base.php';
		include_once AGENT_BUILDER_DIR . 'includes/class-tool-loader.php';
		include_once AGENT_BUILDER_DIR . 'includes/class-tools-registry.php';

		$category_map = array(
			'db_update_option' => 'database',
			'db_create_post'   => 'database',
			'db_update_post'   => 'database',
			'db_delete_post'   => 'database',
			'agents_available' => 'agents',
		);

		$seeded = Tools_Registry::seed_core_tools( $category_map );

		update_option( 'agent_builder_tools_seeded_version', $schema_version );

		// Sync agent-contributed tools.
		Tool_Loader::get_instance()->sync_to_registry();

		self::record(
			'seed_tools',
			'ok',
			array(
				'seeded'         => $seeded,
				'schema_version' => $schema_version,
			)
		);
	}

	/**
	 * Seed bundled core skills into the database.
	 *
	 * Reads SKILL.md files from library/skills/ and inserts any that are not
	 * already present. Skipped on repeat activations for the same schema version.
	 *
	 * @param string $schema_version Current DB_SCHEMA_VERSION.
	 * @return void
	 */
	public static function seed_skills( string $schema_version ): void {
		global $wpdb;

		// Gated on both the DB schema version and the plugin version: bundled
		// SKILL.md content can change in a content-only release with no
		// schema bump, and seed_core_skills() needs to run then too so
		// unedited core skills pick up the improved wording.
		$seeded_version = get_option( 'agent_builder_skills_seeded_version', '' );
		$seeded_plugin  = get_option( 'agent_builder_skills_seeded_plugin_version', '' );
		$plugin_version = defined( 'AGENT_BUILDER_VERSION' ) ? AGENT_BUILDER_VERSION : '';

		if ( $seeded_version === $schema_version && $seeded_plugin === $plugin_version ) {
			self::record(
				'seed_skills',
				'skipped',
				array(
					'reason'         => 'already_seeded',
					'schema_version' => $schema_version,
					'plugin_version' => $plugin_version,
				)
			);
			return;
		}

		$table = $wpdb->prefix . 'agent_builder_skills';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			self::record( 'seed_skills', 'error', 'skills table does not exist — skipping seed' );
			return;
		}

		include_once AGENT_BUILDER_DIR . 'includes/class-skills-registry.php';

		$result = Skills_Registry::seed_core_skills();

		update_option( 'agent_builder_skills_seeded_version', $schema_version );
		update_option( 'agent_builder_skills_seeded_plugin_version', $plugin_version );

		self::record(
			'seed_skills',
			'ok',
			array(
				'seeded'         => $result['seeded'],
				'refreshed'      => $result['refreshed'],
				'schema_version' => $schema_version,
				'plugin_version' => $plugin_version,
			)
		);
	}

	/**
	 * Schedule recurring cron events.
	 *
	 * @return void
	 */
	private static function schedule_cron_events(): void {
		$results = array();

		if ( ! wp_next_scheduled( 'agent_builder_cleanup_audit_log' ) ) {
			wp_schedule_event( time(), 'daily', 'agent_builder_cleanup_audit_log' );
			$results['agent_builder_cleanup_audit_log'] = 'scheduled';
		} else {
			$results['agent_builder_cleanup_audit_log'] = 'already_scheduled';
		}

		if ( ! wp_next_scheduled( 'agent_builder_costs_check_alerts' ) ) {
			wp_schedule_event( time(), 'daily', 'agent_builder_costs_check_alerts' );
			$results['agent_builder_costs_check_alerts'] = 'scheduled';
		} else {
			$results['agent_builder_costs_check_alerts'] = 'already_scheduled';
		}

		self::record( 'schedule_cron_events', 'ok', $results );
	}
}
