<?php
/**
 * Site Health test for Agent Builder background tasks.
 *
 * Registers three direct tests on the WordPress Site Health screen:
 * `agent_builder_background_runs`, `agent_builder_private_storage`
 * (automatic backups, knowledge files and user agents must not be
 * downloadable) and `agent_builder_storage_location` (data still waiting in
 * the pre-4.5 wp-content folders). The test's database queries only run when
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
	 * Site Health slug of the storage-location (migration) test.
	 */
	private const LOCATION_TEST_SLUG = 'agent_builder_storage_location';

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

		$tests['direct'][ self::LOCATION_TEST_SLUG ] = array(
			'label' => __( 'Agent Builder: data is stored in the uploads folder', 'agent-builder' ),
			'test'  => array( __CLASS__, 'test_storage_location' ),
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
	 * Writes a throwaway probe file into the backups, knowledge and agents
	 * folders of the plugin's uploads data root, requests it over HTTP from the site
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
			'backups'   => File_Manager::backups_dir(),
			'knowledge' => File_Manager::knowledge_dir(),
			'agents'    => File_Manager::agents_dir(),
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
				'description' => '<p>' . __( 'Automatic backups, knowledge files and agent files in the Agent Builder uploads folder cannot be downloaded from the web.', 'agent-builder' ) . '</p>',
				'actions'     => '',
				'test'        => self::STORAGE_TEST_SLUG,
			);
		}

		$folders = array();
		foreach ( $readable as $key ) {
			$folders[] = '<code>' . esc_html( self::display_path( $dirs[ $key ] ) ) . '/</code>';
		}

		if ( $critical ) {
			$label       = __( 'Agent Builder backups can be downloaded from the web', 'agent-builder' );
			$description = '<p>' . __( 'Copies of site data that Agent Builder saves before an agent changes something can be read by anyone who requests them. Block web access to the folders below, then delete backups you no longer need on the Approvals → Backups screen.', 'agent-builder' ) . '</p>';
		} else {
			$label       = __( 'Agent Builder folders are readable from the web', 'agent-builder' );
			$description = '<p>' . sprintf(
				/* translators: %s: comma-separated folder names. */
				__( 'Your web server hands out files from %s. Automatic backups stay protected because they are stored in a format the server will not serve, but knowledge files (your agents\' reference notes) and agent files can be read by anyone who knows their address.', 'agent-builder' ),
				implode( ', ', $folders )
			) . '</p>';
		}

		$snippet = self::nginx_snippet();
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
	 * The nginx rule that denies web access to the plugin's private folders.
	 *
	 * Built from the real uploads URL path, so it is correct for a custom
	 * uploads location too.
	 *
	 * @return string
	 */
	public static function nginx_snippet(): string {
		$path = (string) wp_parse_url( File_Manager::data_url(), PHP_URL_PATH );
		$path = '/' . trim( $path, '/' );

		return 'location ~* ^' . preg_quote( $path, '#' ) . '/(' . implode( '|', File_Manager::PRIVATE_SUBDIRS ) . ")/ {\n\tdeny all;\n}";
	}

	/**
	 * A data path shown to the administrator, relative to ABSPATH when possible.
	 *
	 * @param string $path Absolute path.
	 * @return string
	 */
	private static function display_path( string $path ): string {
		$path = wp_normalize_path( $path );
		$root = wp_normalize_path( trailingslashit( ABSPATH ) );
		return str_starts_with( $path, $root ) ? substr( $path, strlen( $root ) ) : $path;
	}

	/**
	 * Run the storage-location Site Health test.
	 *
	 * Reports any pre-4.5 wp-content/agentic-* folder that still exists
	 * because the one-time move into the uploads data root
	 * (Storage_Migration) could not complete — usually file permissions. The
	 * plugin keeps reading those folders until they are gone, so nothing is
	 * lost, but it never writes to them.
	 *
	 * @return array{label:string,status:string,badge:array{label:string,color:string},description:string,actions:string,test:string}
	 */
	public static function test_storage_location(): array {
		$badge = array(
			'label' => __( 'Agent Builder', 'agent-builder' ),
			'color' => 'blue',
		);
		$left  = Storage_Migration::remaining_legacy_dirs();
		$copy  = Storage_Migration::uses_copy();

		if ( empty( $left ) ) {
			$description = '<p>' . sprintf(
				/* translators: %s: folder path. */
				__( 'Agent Builder keeps its files (your agents, knowledge files and automatic backups) in %s.', 'agent-builder' ),
				'<code>' . esc_html( self::display_path( File_Manager::data_dir() ) ) . '/</code>'
			) . '</p>';

			$shared = Storage_Migration::shared_legacy_dirs();
			if ( ! empty( $shared ) ) {
				$folders = array();
				foreach ( $shared as $dir ) {
					$folders[] = '<code>' . esc_html( self::display_path( $dir ) ) . '/</code>';
				}
				$description .= '<p>' . sprintf(
					/* translators: %s: old folder paths. */
					__( 'This site has copied its data out of the older folders shared by every site of the network: %s. Agent Builder no longer uses them on this site and never deletes them, because other sites copy from them the first time they load. Once every site of the network has been visited, you can delete these folders yourself.', 'agent-builder' ),
					implode( ', ', $folders )
				) . '</p>';
			}

			return array(
				'label'       => __( 'Agent Builder data is stored in the uploads folder', 'agent-builder' ),
				'status'      => 'good',
				'badge'       => $badge,
				'description' => $description,
				'actions'     => '',
				'test'        => self::LOCATION_TEST_SLUG,
			);
		}

		$folders = array();
		foreach ( $left as $dir ) {
			$folders[] = '<code>' . esc_html( self::display_path( $dir ) ) . '/</code>';
		}

		return array(
			'label'       => __( 'Some Agent Builder files could not be moved to the uploads folder', 'agent-builder' ),
			'status'      => 'recommended',
			'badge'       => array(
				'label' => __( 'Agent Builder', 'agent-builder' ),
				'color' => 'orange',
			),
			'description' => '<p>' . sprintf(
				/* translators: 1: old folder paths, 2: new folder path. */
				__( 'Agent Builder now stores its files in %2$s. These older folders still hold files that could not be moved automatically, usually because of file permissions: %1$s. Agent Builder still reads them, so nothing is lost, but it no longer writes there.', 'agent-builder' ),
				implode( ', ', $folders ),
				'<code>' . esc_html( self::display_path( File_Manager::data_dir() ) ) . '/</code>'
			) . '</p>',
			'actions'     => '<p>' . ( $copy
				? __( 'Make the uploads folder writable by the web server (or copy the old folders\' contents into the matching agents/, knowledge/ or backups/ subfolder of this site yourself, keeping any file that already exists there). Agent Builder retries the copy every hour. The old folders are shared by every site of the network and are never deleted automatically.', 'agent-builder' )
				: __( 'Make the old folders writable by the web server (or move their contents into the matching agents/, knowledge/ or backups/ subfolder yourself, keeping any file that already exists there). Agent Builder retries the move every hour and removes each old folder once it is empty.', 'agent-builder' )
			) . '</p>',
			'test'        => self::LOCATION_TEST_SLUG,
		);
	}

	/**
	 * Write a probe into $dir, fetch it with a loopback request, delete it.
	 *
	 * @param string $dir     Absolute directory inside the uploads data root.
	 * @param bool   $guarded Probe the exit-guarded .php backup format instead of a plain .txt file.
	 * @return bool|null True if the probe content came back over HTTP, false if not, null if the check could not run.
	 */
	public static function probe_storage_dir( string $dir, bool $guarded ): ?bool {
		$data_dir = wp_normalize_path( untrailingslashit( File_Manager::data_dir() ) );
		$dir      = wp_normalize_path( untrailingslashit( $dir ) );
		if ( ! str_starts_with( $dir, $data_dir . '/' ) ) {
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
			File_Manager::data_url() . '/' . substr( $dir, strlen( $data_dir ) + 1 ) . '/' . $name,
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
