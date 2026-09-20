<?php
/**
 * Site Brief runner — allowlisted, read-only, time-budgeted scan.
 *
 * Calls Tool_Loader / Tool_Executor in observe mode. The allowlist is a
 * private constant: no filter can add a write tool to a scan.
 *
 * @package    Agent_Builder
 * @subpackage Site_Brief
 * @since      3.4.2
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic\Site_Brief;

use Agentic\Audit_Log;
use Agentic\Tool_Executor;
use Agentic\Tool_Loader;
use Agentic\Tools_Registry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deterministic PHP scan of this WordPress install.
 */
class Site_Brief_Runner {

	/**
	 * Exact tools a scan may invoke. Anything else is refused.
	 *
	 * @var string[]
	 */
	public const ALLOWLIST = array(
		'check_plugin_updates',
		'get_abandoned_plugins',
		'check_theme_status',
		'run_health_check',
		'get_php_errors',
		'get_security_overview',
		'verify_core_integrity',
		'get_cron_jobs',
		'check_caching_status',
		'list_comments',
		'list_native_forms',
		'get_form_stats',
		'detect_form_plugins',
		'find_oversized_images',
		'get_media_storage_report',
		'find_unused_media',
		'list_privileged_users',
		'wc_get_orders',
		'wc_get_stock_report',
		'wc_get_store_stats',
	);

	/**
	 * Prefixes that are never allowed, even if someone edits ALLOWLIST.
	 *
	 * @var string[]
	 */
	private const BANNED_PREFIXES = array(
		'db_',
		'create_',
		'github_',
		'generate_',
	);

	/**
	 * Named tools that are never allowed in a scan.
	 *
	 * @var string[]
	 */
	private const BANNED_TOOLS = array(
		'check_core_web_vitals',
		'manage_cache',
		'force_password_reset',
		'lock_user_account',
		'toggle_xml_rpc',
		'toggle_file_editing',
		'install_plugin_from_url',
		'wc_update_stock',
		'wc_create_refund',
		'wc_update_order_status',
		'wc_bulk_update_products',
		'wc_add_to_cart',
		'wc_update_cart_item',
	);

	/**
	 * PHP time budget for one /site-brief/run request, in seconds.
	 */
	public const TIME_BUDGET = 15.0;

	/**
	 * Transient that prevents overlapping scans.
	 */
	public const RUN_LOCK = 'agent_builder_site_brief_running';

	/**
	 * Checker id => class, in scan order (fast reads first).
	 *
	 * @var array<string, class-string<Site_Brief_Checker>>
	 */
	private const CHECKERS = array(
		'plugin_updates'    => Checker_Plugin_Updates::class,
		'comments_queue'    => Checker_Comments_Queue::class,
		'oversized_media'   => Checker_Oversized_Media::class,
		'unused_media'      => Checker_Unused_Media::class,
		'privileged_users'  => Checker_Privileged_Users::class,
		'site_health'       => Checker_Site_Health::class,
		'cron'              => Checker_Cron::class,
		'caching'           => Checker_Caching::class,
		'theme_updates'     => Checker_Theme_Updates::class,
		'php_errors'        => Checker_Php_Errors::class,
		'security'          => Checker_Security::class,
		'forms'             => Checker_Forms::class,
		'abandoned_plugins' => Checker_Abandoned_Plugins::class,
		'core_integrity'    => Checker_Core_Integrity::class,
		'wc_unpaid'         => Checker_Wc_Unpaid::class,
		'wc_low_stock'      => Checker_Wc_Low_Stock::class,
		'wc_stats'          => Checker_Wc_Stats::class,
	);

	/**
	 * Tool executor (observe mode).
	 *
	 * @var Tool_Executor
	 */
	private Tool_Executor $executor;

	/**
	 * Deadline as microtime.
	 *
	 * @var float
	 */
	private float $deadline;

	/**
	 * Constructor.
	 *
	 * @param Tool_Executor|null $executor Optional executor (tests).
	 */
	public function __construct( ?Tool_Executor $executor = null ) {
		$this->executor = $executor ?? new Tool_Executor( Tool_Loader::get_instance(), new Audit_Log(), null );
		$this->deadline = microtime( true ) + self::TIME_BUDGET;
	}

	/**
	 * Whether a tool slug is on the scan allowlist.
	 *
	 * @param string $tool_name Tool slug.
	 * @return bool
	 */
	public static function is_allowlisted( string $tool_name ): bool {
		return in_array( $tool_name, self::ALLOWLIST, true ) && ! self::is_banned( $tool_name );
	}

	/**
	 * Hard bans inside the runner.
	 *
	 * @param string $tool_name Tool slug.
	 * @return bool
	 */
	public static function is_banned( string $tool_name ): bool {
		if ( in_array( $tool_name, self::BANNED_TOOLS, true ) ) {
			return true;
		}
		foreach ( self::BANNED_PREFIXES as $prefix ) {
			if ( str_starts_with( $tool_name, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a tool can actually run on this install.
	 *
	 * Disabled (Tools Hub) or is_available() === false both skip the checker.
	 *
	 * @param string $tool_name Tool slug.
	 * @return bool
	 */
	public function tool_is_usable( string $tool_name ): bool {
		if ( ! self::is_allowlisted( $tool_name ) ) {
			return false;
		}
		if ( ! Tools_Registry::is_enabled( $tool_name ) ) {
			return false;
		}
		$tool = Tool_Loader::get_instance()->get( $tool_name );
		return $tool && $tool->is_available();
	}

	/**
	 * Observe-mode tool call. Refuses anything off the allowlist.
	 *
	 * @param string               $tool_name  Tool slug.
	 * @param array<string, mixed> $arguments  Tool arguments.
	 * @return array<string, mixed>
	 */
	public function observe_tool( string $tool_name, array $arguments = array() ): array {
		if ( ! self::is_allowlisted( $tool_name ) ) {
			return array(
				'error'   => 'not_allowlisted',
				'message' => sprintf( 'Site Brief refused off-list tool: %s', $tool_name ),
			);
		}
		$result = $this->executor->observe( $tool_name, $arguments, self::ALLOWLIST );
		return is_array( $result ) ? $result : array( 'error' => 'invalid_result' );
	}

	/**
	 * Run every applicable checker until the time budget expires.
	 *
	 * @return array<string, mixed> Persistable brief payload.
	 */
	public function run(): array {
		self::load_checkers();

		$previous  = Site_Brief_Store::get();
		$dismissed = is_array( $previous['dismissed'] ?? null ) ? $previous['dismissed'] : array();
		$cards     = array();
		$skipped   = array();
		$stats     = null;
		$status    = 'complete';

		foreach ( self::CHECKERS as $id => $class ) {
			if ( microtime( true ) >= $this->deadline ) {
				$status = 'partial';
				break;
			}
			if ( ! class_exists( $class ) ) {
				continue;
			}
			$checker = new $class();
			if ( ! $checker instanceof Site_Brief_Checker ) {
				continue;
			}
			if ( ! $checker->is_applicable() ) {
				continue;
			}

			$missing = false;
			foreach ( $checker->get_tools() as $tool_name ) {
				if ( ! $this->tool_is_usable( $tool_name ) ) {
					$missing = true;
					break;
				}
			}
			if ( $missing ) {
				$skipped[] = $id;
				continue;
			}

			$found = $checker->run( $this );
			if ( 'wc_stats' === $id && isset( $found['store_stats'] ) ) {
				$stats = $found['store_stats'];
				unset( $found['store_stats'] );
			}
			if ( ! is_array( $found ) ) {
				continue;
			}
			foreach ( $found as $card ) {
				if ( ! is_array( $card ) || empty( $card['id'] ) ) {
					continue;
				}
				if ( Site_Brief_Store::is_dismissed( (string) $card['id'], (string) ( $card['evidence_hash'] ?? '' ), $dismissed ) ) {
					continue;
				}
				$cards[] = $card;
			}
		}

		usort(
			$cards,
			static function ( array $a, array $b ): int {
				return (int) ( $b['severity'] ?? 0 ) <=> (int) ( $a['severity'] ?? 0 );
			}
		);

		return array(
			'version'       => Site_Brief_Store::SCHEMA,
			'last_run'      => gmdate( 'c' ),
			'last_run_user' => get_current_user_id(),
			'status'        => $status,
			'cards'         => array_values( $cards ),
			'dismissed'     => $dismissed,
			'store_stats'   => $stats,
			'skipped'       => $skipped,
		);
	}

	/**
	 * Load checker class files (they live one directory deeper than the autoloader).
	 *
	 * @return void
	 */
	public static function load_checkers(): void {
		static $loaded = false;
		if ( $loaded ) {
			return;
		}
		$dir   = __DIR__ . '/checkers';
		$files = glob( $dir . '/class-checker-*.php' );
		foreach ( ( false !== $files ? $files : array() ) as $file ) {
			require_once $file;
		}
		$loaded = true;
	}
}
