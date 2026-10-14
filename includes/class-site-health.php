<?php
/**
 * Site Health test for Agent Builder background tasks.
 *
 * Registers two direct tests on the WordPress Site Health screen:
 * `agent_builder_background_runs` and `agent_builder_private_storage`
 * (automatic backups and knowledge files must not be downloadable). The test's database queries only run when
 * WordPress actually builds the Site Health screen (or the WP-CLI / REST Site
 * Health endpoints), never on an ordinary page load — the `site_status_tests`
 * filter is only applied from `WP_Site_Health::get_tests()`.
 *
 * @package    Agent_Builder
 * @subpackage Includes
 * @since      4.1.0
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Site Health checks for the plugin's background-task machinery.
 */
class Site_Health {

	/**
	 * Site Health test slug.
	 */
	private const TEST_SLUG = 'agent_builder_background_runs';

	/**
	 * Site Health slug of the private-storage exposure test.
	 */
	private const STORAGE_TEST_SLUG = 'agent_builder_private_storage';

	/**
	 * Option holding the timestamp of the last completed hourly health-check tick.
	 */
	private const OPTION_LAST_TICK = 'agent_builder_cron_last_tick';

	/**
	 * WP-Cron is considered overdue after this many seconds without a tick.
	 *
	 * The only thing that stamps `agent_builder_cron_last_tick` is the hourly
	 * `agent_builder_job_health_check` event (Job_Manager::run_health_check()),
	 * so a healthy site's tick is 0–59 minutes old at any given moment. The
	 * threshold must comfortably exceed that period (2× the hourly schedule),
	 * or a healthy site would false-report `recommended` for most of every hour.
	 */
	private const CRON_OVERDUE_SECONDS = 2 * HOUR_IN_SECONDS;

	/**
	 * A run is stuck after this many seconds in a running state.
	 */
	private const RUN_STUCK_SECONDS = 45 * MINUTE_IN_SECONDS;

	/**
	 * An approval is stale after this many seconds waiting.
	 */
	private const APPROVAL_WAIT_SECONDS = 24 * HOUR_IN_SECONDS;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'site_status_tests', array( __CLASS__, 'add_tests' ) );
	}

	/**
	 * Register the Agent Builder tests with WordPress Site Health.
	 *
	 * @param array $tests Existing direct/async test definitions.
	 * @return array Tests with the Agent Builder direct tests appended.
	 */
	public static function add_tests( array $tests ): array {
		$tests['direct'][ self::TEST_SLUG ] = array(
			'label' => __( 'Agent Builder: background tasks', 'agent-builder' ),
			'test'  => array( __CLASS__, 'test_background_runs' ),
		);

		$tests['direct'][ self::STORAGE_TEST_SLUG ] = array(
			'label' => __( 'Agent Builder: private files are not public', 'agent-builder' ),
			'test'  => array( __CLASS__, 'test_private_storage' ),
		);

		return $tests;
	}

	/**
	 * Run the background-tasks Site Health test.
	 *
	 * @return array{label:string,status:string,badge:array{label:string,color:string},description:string,actions:string,test:string}
	 */
	public static function test_background_runs(): array {
		$cron_stale      = self::cron_is_stale();
		$stuck_runs      = self::count_stuck_runs();
		$stale_approvals = self::count_stale_approvals();

		if ( ! $cron_stale && 0 === $stuck_runs && 0 === $stale_approvals ) {
			return array(
				'label'       => __( 'Agent Builder: background tasks', 'agent-builder' ),
				'status'      => 'good',
				'badge'       => array(
					'label' => __( 'Background tasks', 'agent-builder' ),
					'color' => 'green',
				),
				'description' => '<p>' . __( 'Agent Builder background tasks are running on schedule.', 'agent-builder' ) . '</p>',
				'actions'     => '',
				'test'        => self::TEST_SLUG,
			);
		}

		return array(
			'label'       => __( 'Agent Builder: background tasks', 'agent-builder' ),
			'status'      => 'recommended',
			'badge'       => array(
				'label' => __( 'Background tasks', 'agent-builder' ),
				'color' => 'orange',
			),
			'description' => self::build_description( $cron_stale, $stuck_runs, $stale_approvals ),
			'actions'     => self::build_actions( $cron_stale, $stuck_runs, $stale_approvals ),
			'test'        => self::TEST_SLUG,
		);
	}

	/**
	 * Run the private-storage Site Health test.
	 *
	 * Writes a throwaway probe file into wp-content/agentic-backups and
	 * wp-content/agentic-knowledge, requests it over HTTP from the site
	 * itself (a loopback request — nothing leaves the server) and deletes it
	 * again. Backups get two probes: a plain .txt (is the folder readable at
	 * all?) and an exit-guarded .php in the real backup format (could a
	 * backup's content ever be read?). If the loopback request fails the
	 * test is skipped rather than reported as a problem.
	 *
	 * @return array{label:string,status:string,badge:array{label:string,color:string},description:string,actions:string,test:string}
	 */
	public static function test_private_storage(): array {
		$readable = array();
		$checked  = false;
		$critical = false;

		$dirs = array(
			'backups'   => defined( 'AGENT_BUILDER_BACKUPS_DIR' ) ? AGENT_BUILDER_BACKUPS_DIR : '',
			'knowledge' => defined( 'AGENT_BUILDER_KNOWLEDGE_DIR' ) ? AGENT_BUILDER_KNOWLEDGE_DIR : '',
		);

		foreach ( $dirs as $key => $dir ) {
			if ( '' === $dir || ! is_dir( $dir ) ) {
				continue; // Nothing stored there yet.
			}

			$plain = self::probe_storage_dir( $dir, false );
			if ( null !== $plain ) {
				$checked = true;
				if ( $plain ) {
					$readable[] = $key;
				}
			}

			if ( 'backups' === $key && true === self::probe_storage_dir( $dir, true ) ) {
				$critical = true;
			}
		}

		// Leftover plain-text backups are only a real leak if the folder is readable.
		if ( in_array( 'backups', $readable, true ) && Tool_Helpers::count_unprotected_backups() > 0 ) {
			$critical = true;
		}

		$label = __( 'Agent Builder: private files are not public', 'agent-builder' );
		$badge = array(
			'label' => __( 'Security', 'agent-builder' ),
			'color' => 'blue',
		);

		if ( ! $checked && ! $critical ) {
			return array(
				'label'       => $label,
				'status'      => 'good',
				'badge'       => $badge,
				'description' => '<p>' . __( 'Agent Builder could not request its own folders over the web (the loopback request failed, or nothing is stored yet), so this check was skipped. Automatic backups are stored in a format the web server will not hand out either way.', 'agent-builder' ) . '</p>',
				'actions'     => '',
				'test'        => self::STORAGE_TEST_SLUG,
			);
		}

		if ( ! $critical && empty( $readable ) ) {
			return array(
				'label'       => $label,
				'status'      => 'good',
				'badge'       => $badge,
				'description' => '<p>' . __( 'Automatic backups and knowledge files in wp-content cannot be downloaded from the web.', 'agent-builder' ) . '</p>',
				'actions'     => '',
				'test'        => self::STORAGE_TEST_SLUG,
			);
		}

		$folders = array();
		foreach ( $readable as $key ) {
			$folders[] = '<code>wp-content/agentic-' . esc_html( $key ) . '/</code>';
		}

		if ( $critical ) {
			$label       = __( 'Agent Builder backups can be downloaded from the web', 'agent-builder' );
			$description = '<p>' . __( 'Copies of site data that Agent Builder saves before an agent changes something can be read by anyone who requests them. Block web access to the folders below, then delete backups you no longer need on the Approvals → Backups screen.', 'agent-builder' ) . '</p>';
		} else {
			$label       = __( 'Agent Builder folders are readable from the web', 'agent-builder' );
			$description = '<p>' . sprintf(
				/* translators: %s: comma-separated folder names. */
				__( 'Your web server hands out files from %s. Automatic backups stay protected because they are stored in a format the server will not serve, but knowledge files (your agents\' reference notes) can be read by anyone who knows their address.', 'agent-builder' ),
				implode( ', ', $folders )
			) . '</p>';
		}

		$snippet = "location ~* ^/wp-content/agentic-(backups|knowledge)/ {\n\tdeny all;\n}";
		$actions = '<p>' . __( 'Apache and IIS follow the .htaccess and web.config files Agent Builder places in these folders. On nginx, add this rule to the site\'s server block and reload nginx:', 'agent-builder' ) . '</p><pre><code>' . esc_html( $snippet ) . '</code></pre>';

		return array(
			'label'       => $label,
			'status'      => $critical ? 'critical' : 'recommended',
			'badge'       => array(
				'label' => __( 'Security', 'agent-builder' ),
				'color' => $critical ? 'red' : 'orange',
			),
			'description' => $description,
			'actions'     => $actions,
			'test'        => self::STORAGE_TEST_SLUG,
		);
	}

	/**
	 * Write a probe into $dir, fetch it with a loopback request, delete it.
	 *
	 * @param string $dir     Absolute directory under WP_CONTENT_DIR.
	 * @param bool   $guarded Probe the exit-guarded .php backup format instead of a plain .txt file.
	 * @return bool|null True if the probe content came back over HTTP, false if not, null if the check could not run.
	 */
	public static function probe_storage_dir( string $dir, bool $guarded ): ?bool {
		$content_dir = wp_normalize_path( untrailingslashit( WP_CONTENT_DIR ) );
		$dir         = wp_normalize_path( untrailingslashit( $dir ) );
		if ( ! str_starts_with( $dir, $content_dir . '/' ) ) {
			return null;
		}

		$token  = bin2hex( random_bytes( 12 ) );
		$marker = 'agent-builder-probe-' . $token;
		$name   = 'agentic-probe-' . $token . ( $guarded ? '.php' : '.txt' );
		$path   = $dir . '/' . $name;

		$written = $guarded
			? File_Manager::put_guarded_contents( $path, $marker )
			: File_Manager::put_contents( $path, $marker );
		if ( ! $written ) {
			return null;
		}

		$response = wp_remote_get(
			content_url( substr( $dir, strlen( $content_dir ) + 1 ) . '/' . $name ),
			array(
				'timeout'     => 5,
				'redirection' => 3,
				/** This filter is documented in wp-includes/class-wp-http-streams.php */
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core filter.
			)
		);

		wp_delete_file( $path );

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 0 === $code || $code >= 500 ) {
			return null;
		}

		return 200 === $code && str_contains( (string) wp_remote_retrieve_body( $response ), $marker );
	}

	/**
	 * Whether the last background-tasks cron tick is missing or overdue.
	 *
	 * @return bool True when cron is stale (missing tick, or older than the threshold).
	 */
	public static function cron_is_stale(): bool {
		$last_tick = (int) get_option( self::OPTION_LAST_TICK, 0 );

		if ( 0 === $last_tick ) {
			return true;
		}

		return ( time() - $last_tick ) > self::CRON_OVERDUE_SECONDS;
	}

	/**
	 * Count Agent_Run rows still in a running state longer than the stuck threshold.
	 *
	 * A COUNT(*) with the age predicate in SQL is cheaper and does not silently
	 * undercount when more runs are running than Agent_Run::query()'s 200-row
	 * page cap (that method clamps `per_page` to 200, so counting over a paged
	 * result would miss stuck runs beyond the first page).
	 *
	 * @return int Number of stuck runs.
	 */
	private static function count_stuck_runs(): int {
		if ( ! class_exists( Agent_Run::class ) ) {
			return 0;
		}

		global $wpdb;
		$table    = $wpdb->prefix . 'agent_builder_runs';
		$stuck_at = gmdate( 'Y-m-d H:i:s', time() - self::RUN_STUCK_SECONDS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregate count of stuck runs; runs only on the Site Health screen/REST/CLI.
		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE status = %s AND updated_at < %s',
				$table,
				'running',
				$stuck_at
			)
		);

		return (int) $count;
	}

	/**
	 * Count approval items that have been waiting longer than the stale threshold.
	 *
	 * @return int Number of stale pending approvals.
	 */
	private static function count_stale_approvals(): int {
		if ( ! class_exists( Approval_Queue::class ) ) {
			return 0;
		}

		$stale = 0;
		$queue = new Approval_Queue();

		foreach ( $queue->get_pending() as $item ) {
			if ( self::datetime_age_seconds( (string) ( $item['created_at'] ?? '' ) ) > self::APPROVAL_WAIT_SECONDS ) {
				++$stale;
			}
		}

		return $stale;
	}

	/**
	 * Age of a GMT MySQL datetime in seconds.
	 *
	 * All timestamps written by this plugin (current_time( 'mysql', true ),
	 * gmdate()) are stored in GMT, so parse with an explicit UTC suffix to avoid
	 * PHP default-timezone skew. Empty or unparseable values are treated as
	 * fresh (age 0) rather than stale, so malformed data never false-positives.
	 *
	 * @param string $datetime GMT MySQL datetime (Y-m-d H:i:s).
	 * @return int Age in seconds (never negative).
	 */
	private static function datetime_age_seconds( string $datetime ): int {
		if ( '' === $datetime ) {
			return 0;
		}

		$timestamp = strtotime( $datetime . ' UTC' );
		if ( false === $timestamp ) {
			return 0;
		}

		return max( 0, time() - $timestamp );
	}

	/**
	 * Build the failing-test description listing each problem.
	 *
	 * @param bool $cron_stale      Whether cron is overdue.
	 * @param int  $stuck_runs      Number of stuck runs.
	 * @param int  $stale_approvals Number of stale approvals.
	 * @return string HTML description.
	 */
	private static function build_description( bool $cron_stale, int $stuck_runs, int $stale_approvals ): string {
		$problems = array();

		if ( $cron_stale ) {
			$problems[] = __( 'Scheduled background tasks are overdue (no recent cron tick).', 'agent-builder' );
		}

		if ( $stuck_runs > 0 ) {
			$problems[] = sprintf(
				/* translators: %d: number of stuck runs. */
				_n( '%d agent run is stuck in a running state.', '%d agent runs are stuck in a running state.', $stuck_runs, 'agent-builder' ),
				$stuck_runs
			);
		}

		if ( $stale_approvals > 0 ) {
			$problems[] = sprintf(
				/* translators: %d: number of stale approvals. */
				_n( '%d approval has been waiting over 24 hours.', '%d approvals have been waiting over 24 hours.', $stale_approvals, 'agent-builder' ),
				$stale_approvals
			);
		}

		return '<p>' . __( 'Some Agent Builder background tasks need attention:', 'agent-builder' ) . '</p><ul><li>' . implode( '</li><li>', $problems ) . '</li></ul>';
	}

	/**
	 * Build the failing-test action links.
	 *
	 * @param bool $cron_stale      Whether cron is overdue.
	 * @param int  $stuck_runs      Number of stuck runs.
	 * @param int  $stale_approvals Number of stale approvals.
	 * @return string HTML actions.
	 */
	private static function build_actions( bool $cron_stale, int $stuck_runs, int $stale_approvals ): string {
		$actions = '';

		if ( $cron_stale ) {
			$doc_link = sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( __( 'https://developer.wordpress.org/plugins/cron/', 'agent-builder' ) ),
				__( 'WordPress cron documentation', 'agent-builder' )
			);

			if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
				$actions .= sprintf(
					'<p>%s</p>',
					sprintf(
						/* translators: %s: link to the WordPress cron documentation. */
						__( '<code>DISABLE_WP_CRON</code> is set but no recent cron tick was recorded — make sure a real system cron entry is hitting wp-cron.php. See %s.', 'agent-builder' ),
						$doc_link
					)
				);
			} else {
				$actions .= sprintf(
					'<p>%s</p>',
					sprintf(
						/* translators: %s: link to the WordPress cron documentation. */
						__( 'On a low-traffic site, set <code>DISABLE_WP_CRON</code> and add a real system cron entry so background tasks run reliably. See %s.', 'agent-builder' ),
						$doc_link
					)
				);
			}
		}

		if ( $stuck_runs > 0 ) {
			$actions .= sprintf(
				'<p><a href="%1$s">%2$s</a></p>',
				esc_url( admin_url( 'admin.php?page=agentic-tasks' ) ),
				__( 'Review runs', 'agent-builder' )
			);
		}

		if ( $stale_approvals > 0 ) {
			$actions .= sprintf(
				'<p><a href="%1$s">%2$s</a></p>',
				esc_url( admin_url( 'admin.php?page=agentic-approvals' ) ),
				__( 'Review approvals', 'agent-builder' )
			);
		}

		return $actions;
	}
}
