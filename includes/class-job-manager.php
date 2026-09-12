<?php
/**
 * Job Manager
 *
 * Handles async job queue for long-running agent tasks.
 *
 * @package    Agent_Builder
 * @subpackage Includes
 * @author     Agent Builder Team <support@agentic-plugin.com>
 * @license    GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 * @link       https://agentic-plugin.com
 * @since      0.2.0
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Job Manager class
 */
class Job_Manager {

	/**
	 * Table name
	 */
	private const TABLE_NAME = 'agentic_jobs';

	/**
	 * Cache group
	 */
	private const CACHE_GROUP = 'agentic_jobs';

	/**
	 * Cache expiration (5 minutes)
	 */
	private const CACHE_EXPIRATION = 300;

	/**
	 * Job statuses
	 */
	public const STATUS_PENDING    = 'pending';
	public const STATUS_PROCESSING = 'processing';
	public const STATUS_COMPLETED  = 'completed';
	public const STATUS_FAILED     = 'failed';
	public const STATUS_CANCELLED  = 'cancelled';

	/**
	 * Health / stuck detection thresholds (P0 stabilization).
	 */
	public const STUCK_PROCESSING_THRESHOLD_MINUTES = 45;
	public const ABANDONED_PENDING_MAX_HOURS        = 6;

	/**
	 * Initialize
	 */
	public static function init(): void {
		add_action( 'agentic_process_job', array( __CLASS__, 'process_job' ) );
		add_action( 'agentic_cleanup_jobs', array( __CLASS__, 'cleanup_old_jobs' ) );
		add_action( 'agentic_job_health_check', array( __CLASS__, 'run_health_check' ) );

		// Schedule hourly cleanup if not already scheduled.
		if ( ! wp_next_scheduled( 'agentic_cleanup_jobs' ) ) {
			wp_schedule_event( time(), 'hourly', 'agentic_cleanup_jobs' );
		}

		// Schedule hourly job health/stuck detection (P0 stabilization).
		if ( ! wp_next_scheduled( 'agentic_job_health_check' ) ) {
			wp_schedule_event( time(), 'hourly', 'agentic_job_health_check' );
		}
	}

	/**
	 * Get table name with prefix
	 *
	 * @return string
	 */
	private static function get_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_NAME;
	}

	/**
	 * Create jobs table
	 *
	 * @return void
	 */
	public static function create_table(): void {
		global $wpdb;

		$table_name      = self::get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
			id varchar(36) NOT NULL,
			user_id bigint(20) UNSIGNED NOT NULL,
			agent_id varchar(100) DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			progress int(3) NOT NULL DEFAULT 0,
			message varchar(255) DEFAULT '',
			request_data longtext,
			response_data longtext,
			error_message text,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY (id),
			KEY idx_user_created (user_id, created_at),
			KEY idx_status (status),
			KEY idx_created (created_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Create a new job
	 *
	 * @param array $args Job arguments.
	 * @return string Job ID
	 */
	public static function create_job( array $args ): string {
		global $wpdb;

		if ( class_exists( __NAMESPACE__ . '\\Emergency_Stop' ) && Emergency_Stop::is_active() ) {
			return '';
		}

		$defaults = array(
			'user_id'      => get_current_user_id(),
			'agent_id'     => null,
			'request_data' => array(),
			'processor'    => null,
		);

		$args = wp_parse_args( $args, $defaults );

		$job_id = wp_generate_uuid4();
		$now    = gmdate( 'Y-m-d H:i:s' );

		// Store processor class in request_data.
		if ( $args['processor'] ) {
			$args['request_data']['_processor'] = $args['processor'];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table insert.
		$wpdb->insert(
			self::get_table_name(),
			array(
				'id'           => $job_id,
				'user_id'      => $args['user_id'],
				'agent_id'     => $args['agent_id'],
				'status'       => self::STATUS_PENDING,
				'progress'     => 0,
				'message'      => '',
				'request_data' => wp_json_encode( $args['request_data'] ),
				'created_at'   => $now,
				'updated_at'   => $now,
			),
			array( '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
		);

		// Schedule async processing.
		wp_schedule_single_event( time(), 'agentic_process_job', array( $job_id ) );

		// Invalidate list cache.
		self::invalidate_list_cache();

		return $job_id;
	}

	/**
	 * Get job by ID
	 *
	 * @param string $job_id Job ID.
	 * @return object|null
	 */
	public static function get_job( string $job_id ): ?object {
		$cache_key = 'job_' . $job_id;
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;

		$table = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table query with caching.
		$job = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %s", $job_id ) );

		if ( ! $job ) {
			return null;
		}

		// Decode JSON fields.
		$job->request_data = json_decode( $job->request_data, true );
		if ( $job->response_data ) {
			$job->response_data = json_decode( $job->response_data, true );
		}

		wp_cache_set( $cache_key, $job, self::CACHE_GROUP, self::CACHE_EXPIRATION );

		return $job;
	}

	/**
	 * Update job
	 *
	 * @param string $job_id Job ID.
	 * @param array  $data   Data to update.
	 * @return bool
	 */
	public static function update_job( string $job_id, array $data ): bool {
		global $wpdb;

		$data['updated_at'] = gmdate( 'Y-m-d H:i:s' );

		// Encode response_data if provided.
		if ( isset( $data['response_data'] ) && is_array( $data['response_data'] ) ) {
			$data['response_data'] = wp_json_encode( $data['response_data'] );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table update.
		$result = $wpdb->update(
			self::get_table_name(),
			$data,
			array( 'id' => $job_id ),
			null,
			array( '%s' )
		);

		if ( false !== $result ) {
			// Invalidate cache.
			wp_cache_delete( 'job_' . $job_id, self::CACHE_GROUP );
			self::invalidate_list_cache();
		}

		return false !== $result;
	}

	/**
	 * Process a job
	 *
	 * @param string $job_id Job ID.
	 * @return void
	 * @throws \Exception If job processor is invalid or missing.
	 */
	public static function process_job( string $job_id ): void {
		if ( class_exists( __NAMESPACE__ . '\\Emergency_Stop' ) && Emergency_Stop::is_active() ) {
			self::update_job(
				$job_id,
				array(
					'status'        => self::STATUS_CANCELLED,
					'message'       => 'Cancelled by emergency stop (Disable All Agents)',
					'error_message' => 'emergency_stop',
				)
			);
			return;
		}

		$job = self::get_job( $job_id );

		if ( ! $job ) {
			return;
		}

		// Check if already processing or completed.
		if ( in_array( $job->status, array( self::STATUS_PROCESSING, self::STATUS_COMPLETED, self::STATUS_CANCELLED ), true ) ) {
			return;
		}

		// Update to processing.
		self::update_job( $job_id, array( 'status' => self::STATUS_PROCESSING ) );

		try {
			// Get processor class from request data. Only a class that actually
			// implements Job_Processor_Interface may be instantiated here — this
			// value ultimately traces back to a REST request parameter, so a
			// bare class_exists() would let a caller name ANY loaded class
			// (WordPress core, any other active plugin) as the "processor".
			$processor_class = $job->request_data['_processor'] ?? null;

			if ( ! $processor_class || ! class_exists( $processor_class )
				|| ! in_array( __NAMESPACE__ . '\\Job_Processor_Interface', class_implements( $processor_class ), true ) ) {
				throw new \Exception( 'Invalid or missing job processor' );
			}

			// Create processor instance.
			$processor = new $processor_class();

			// Execute with progress callback.
			$result = $processor->execute(
				$job->request_data,
				function ( $progress, $message ) use ( $job_id ) {
					self::update_job(
						$job_id,
						array(
							'progress' => $progress,
							'message'  => $message,
						)
					);
				}
			);

			// Mark as completed.
			self::update_job(
				$job_id,
				array(
					'status'        => self::STATUS_COMPLETED,
					'progress'      => 100,
					'message'       => 'Completed',
					'response_data' => $result,
				)
			);

		} catch ( \Exception $e ) {
			// Mark as failed.
			self::update_job(
				$job_id,
				array(
					'status'        => self::STATUS_FAILED,
					'error_message' => $e->getMessage(),
					'message'       => 'Failed: ' . $e->getMessage(),
				)
			);
		}
	}

	/**
	 * Cancel a job
	 *
	 * @param string $job_id Job ID.
	 * @return bool
	 */
	public static function cancel_job( string $job_id ): bool {
		$job = self::get_job( $job_id );

		if ( ! $job || self::STATUS_PENDING !== $job->status ) {
			return false;
		}

		return self::update_job(
			$job_id,
			array(
				'status'  => self::STATUS_CANCELLED,
				'message' => 'Cancelled by user',
			)
		);
	}

	/**
	 * List jobs matching one or more statuses (lightweight fields for snapshots).
	 *
	 * @param string[] $statuses Status values.
	 * @param int      $limit    Max rows.
	 * @return array<int, array<string,mixed>>
	 */
	public static function list_by_statuses( array $statuses, int $limit = 200 ): array {
		$statuses = array_values(
			array_filter(
				array_map( 'sanitize_key', $statuses )
			)
		);
		if ( empty( $statuses ) ) {
			return array();
		}

		global $wpdb;
		$table        = self::get_table_name();
		$limit        = max( 1, min( 500, $limit ) );
		$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$args         = array_merge( array( $table ), $statuses, array( $limit ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Dynamic IN (%s…) count matches $statuses; table %i + limit %d via $args.
			$wpdb->prepare(
				"SELECT id, user_id, agent_id, status, progress, message, created_at, updated_at FROM %i WHERE status IN ({$placeholders}) ORDER BY updated_at DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $placeholders is only %s tokens; $args is table+statuses+limit.
				...$args // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Force-cancel all pending and processing jobs (emergency stop).
	 *
	 * @return array{pending:int,processing:int,ids:string[]}
	 */
	public static function emergency_cancel_all(): array {
		global $wpdb;
		$table = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom jobs table; %i quotes table name.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE status IN (%s, %s)',
				$table,
				self::STATUS_PENDING,
				self::STATUS_PROCESSING
			)
		);
		$ids = is_array( $ids ) ? $ids : array();

		$pending    = 0;
		$processing = 0;
		foreach ( $ids as $job_id ) {
			$job = self::get_job( (string) $job_id );
			if ( ! $job ) {
				continue;
			}
			if ( self::STATUS_PENDING === $job->status ) {
				++$pending;
			} elseif ( self::STATUS_PROCESSING === $job->status ) {
				++$processing;
			}
			self::update_job(
				(string) $job_id,
				array(
					'status'        => self::STATUS_CANCELLED,
					'message'       => 'Cancelled by emergency stop (Disable All Agents)',
					'error_message' => 'emergency_stop',
				)
			);
		}

		self::invalidate_list_cache();

		return array(
			'pending'    => $pending,
			'processing' => $processing,
			'ids'        => array_map( 'strval', $ids ),
		);
	}

	/**
	 * Get user's jobs
	 *
	 * @param int    $user_id User ID.
	 * @param string $status  Optional status filter.
	 * @param int    $limit   Limit.
	 * @return array
	 */
	public static function get_user_jobs( int $user_id, string $status = '', int $limit = 50 ): array {
		$cache_key = 'user_jobs_' . $user_id . '_' . $status . '_' . $limit;
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;

		$table = self::get_table_name();

		if ( $status ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table query with caching.
			$jobs = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE user_id = %d AND status = %s ORDER BY created_at DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$user_id,
					$status,
					$limit
				)
			);
		} else {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table query with caching.
			$jobs = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE user_id = %d ORDER BY created_at DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$user_id,
					$limit
				)
			);
		}

		// Decode JSON fields.
		foreach ( $jobs as $job ) {
			$job->request_data = json_decode( $job->request_data, true );
			if ( $job->response_data ) {
				$job->response_data = json_decode( $job->response_data, true );
			}
		}

		wp_cache_set( $cache_key, $jobs, self::CACHE_GROUP, self::CACHE_EXPIRATION );

		return $jobs;
	}

	/**
	 * Clean up old completed/failed jobs
	 *
	 * @return int Number of deleted jobs
	 */
	public static function cleanup_old_jobs(): int {
		global $wpdb;

		$agentic_table = self::get_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$deleted = $wpdb->query(
			"DELETE FROM {$agentic_table} WHERE status IN ('completed', 'failed', 'cancelled') AND updated_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		if ( $deleted > 0 ) {
			// Invalidate cache.
			self::invalidate_list_cache();
		}

		return (int) $deleted;
	}

	/**
	 * Run periodic health check for stuck / abandoned jobs (P0 stabilization).
	 *
	 * Detects:
	 * - Jobs stuck in 'processing' for too long (likely crashed or timed out during execution).
	 * - Very old 'pending' jobs that were never picked up (abandoned).
	 *
	 * Marks them failed with clear error so UIs (e.g. agent trainer) don't hang forever.
	 * This is lightweight recovery — no auto-retry (advanced retry/replay is Pro territory).
	 *
	 * @return array{stuck_marked: int, abandoned_marked: int}
	 */
	public static function run_health_check(): array {
		global $wpdb;

		$table   = self::get_table_name();
		$now     = gmdate( 'Y-m-d H:i:s' );
		$results = array(
			'stuck_marked'     => 0,
			'abandoned_marked' => 0,
		);

		// 1. Stuck processing jobs (updated_at too old while still processing).
		$stuck_threshold = gmdate( 'Y-m-d H:i:s', time() - ( self::STUCK_PROCESSING_THRESHOLD_MINUTES * 60 ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$stuck_jobs = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE status = %s AND updated_at < %s LIMIT 50',
				$table,
				self::STATUS_PROCESSING,
				$stuck_threshold
			)
		);

		foreach ( $stuck_jobs as $job ) {
			self::update_job(
				$job->id,
				array(
					'status'        => self::STATUS_FAILED,
					'error_message' => sprintf(
						'Automatically marked failed: stuck in processing for over %d minutes (possible crash, timeout, or uncaught error during execution). You may retry the operation.',
						self::STUCK_PROCESSING_THRESHOLD_MINUTES
					),
					'message'       => 'Failed (stuck job auto-detected)',
				)
			);
			++$results['stuck_marked'];
		}

		// 2. Abandoned old pending jobs (never started, e.g. scheduler missed or queue backed up).
		$abandoned_threshold = gmdate( 'Y-m-d H:i:s', time() - ( self::ABANDONED_PENDING_MAX_HOURS * 3600 ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$abandoned_jobs = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE status = %s AND created_at < %s LIMIT 50',
				$table,
				self::STATUS_PENDING,
				$abandoned_threshold
			)
		);

		foreach ( $abandoned_jobs as $job ) {
			self::update_job(
				$job->id,
				array(
					'status'        => self::STATUS_FAILED,
					'error_message' => sprintf(
						'Automatically marked failed: pending for over %d hours without starting (abandoned or scheduler issue). Retry the operation if needed.',
						self::ABANDONED_PENDING_MAX_HOURS
					),
					'message'       => 'Failed (abandoned pending job auto-detected)',
				)
			);
			++$results['abandoned_marked'];
		}

		if ( $results['stuck_marked'] + $results['abandoned_marked'] > 0 ) {
			self::invalidate_list_cache();
			// Record in audit for visibility (ties into Item 1 observability).
			if ( class_exists( 'Agentic\\Audit_Log' ) ) {
				( new \Agentic\Audit_Log() )->log(
					'system',
					'job_health_recovery',
					'job',
					$results,
					sprintf( 'Job health check recovered %d stuck + %d abandoned jobs', $results['stuck_marked'], $results['abandoned_marked'] )
				);
			}
		}

		return $results;
	}

	/**
	 * Get lightweight health snapshot for System Health surface / admin.
	 *
	 * @return array
	 */
	public static function get_health(): array {
		$stats = self::get_stats();

		global $wpdb;
		$table = self::get_table_name();

		$stuck_threshold = gmdate( 'Y-m-d H:i:s', time() - ( self::STUCK_PROCESSING_THRESHOLD_MINUTES * 60 ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$stuck_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE status = %s AND updated_at < %s',
				$table,
				self::STATUS_PROCESSING,
				$stuck_threshold
			)
		);

		$abandoned_threshold = gmdate( 'Y-m-d H:i:s', time() - ( self::ABANDONED_PENDING_MAX_HOURS * 3600 ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$abandoned_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE status = %s AND created_at < %s',
				$table,
				self::STATUS_PENDING,
				$abandoned_threshold
			)
		);

		return array(
			'stats'             => $stats,
			'stuck_processing'  => $stuck_count,
			'abandoned_pending' => $abandoned_count,
			'thresholds'        => array(
				'stuck_minutes'   => self::STUCK_PROCESSING_THRESHOLD_MINUTES,
				'abandoned_hours' => self::ABANDONED_PENDING_MAX_HOURS,
			),
		);
	}

	/**
	 * Get job statistics
	 *
	 * @param int $user_id Optional user ID to filter stats.
	 * @return array Job statistics
	 */
	public static function get_stats( int $user_id = 0 ): array {
		$cache_key = 'stats_' . $user_id;
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;

		$table = self::get_table_name();
		$where = $user_id ? $wpdb->prepare( 'WHERE user_id = %d', $user_id ) : '';

		$sql  = 'SELECT ';
		$sql .= 'COUNT(*) as total, ';
		$sql .= "SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending, ";
		$sql .= "SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing, ";
		$sql .= "SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed, ";
		$sql .= "SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed ";
		$sql .= 'FROM ' . $table . ' ' . $where;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table stats with caching.
		$stats = $wpdb->get_row( $sql, ARRAY_A );

		$result = $stats ? $stats : array(
			'total'      => 0,
			'pending'    => 0,
			'processing' => 0,
			'completed'  => 0,
			'failed'     => 0,
		);

		wp_cache_set( $cache_key, $result, self::CACHE_GROUP, self::CACHE_EXPIRATION );

		return $result;
	}

	/**
	 * Invalidate all list and stats cache
	 *
	 * @return void
	 */
	private static function invalidate_list_cache(): void {
		wp_cache_flush_group( self::CACHE_GROUP );
	}
}
