<?php
/**
 * Unit tests for protected (non-public) backup storage, the legacy-backup
 * migration, the private-storage Site Health test and the delete-all
 * uninstall folder cleanup.
 *
 * @package Agentic\Tests
 */

namespace Agentic\Tests;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.WP.AlternativeFunctions

use Agentic\File_Manager;
use Agentic\Site_Health;
use Agentic\Tool_Helpers;
use Agentic\Uninstall_Cleanup;

/**
 * Test case for protected backups.
 */
class Test_Protected_Backups extends TestCase {

	/**
	 * Start every test from an empty backups/knowledge tree.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->wipe_dir( AGENT_BUILDER_BACKUPS_DIR );
		$this->wipe_dir( AGENT_BUILDER_KNOWLEDGE_DIR );
		Tool_Helpers::migrate_legacy_backups( true );
	}

	/**
	 * Leave no backup files behind for other test classes.
	 */
	public function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		$this->wipe_dir( AGENT_BUILDER_BACKUPS_DIR );
		$this->wipe_dir( AGENT_BUILDER_KNOWLEDGE_DIR );
		parent::tearDown();
	}

	/**
	 * Recursively delete a directory if it exists.
	 *
	 * @param string $dir Directory.
	 */
	private function wipe_dir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \RecursiveDirectoryIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $it as $item ) {
			$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}
		rmdir( $dir );
	}

	// ─── Table backups ──────────────────────────────────────────────────────

	/**
	 * A table backup is an exit-guarded .php file with a 24-hex random part,
	 * and two backups never share a name.
	 */
	public function test_table_backup_is_guarded_and_unguessable(): void {
		$file = Tool_Helpers::backup_table( 'agent_builder_agent_settings' );
		$this->assertNotNull( $file );
		$this->assertMatchesRegularExpression( '/^\d{8}-\d{6}_[a-f0-9]{24}_agent_builder_agent_settings\.php$/', $file );

		$path = AGENT_BUILDER_BACKUPS_DIR . '/db/' . $file;
		$raw  = file_get_contents( $path );
		$this->assertStringStartsWith( "<?php exit; ?>\n", $raw );
		$this->assertIsArray( json_decode( (string) File_Manager::get_guarded_contents( $path ), true ) );

		$this->assertFileExists( AGENT_BUILDER_BACKUPS_DIR . '/db/index.php' );
		$this->assertStringContainsString( 'Require all denied', (string) file_get_contents( AGENT_BUILDER_BACKUPS_DIR . '/.htaccess' ) );

		$this->assertNotSame( Tool_Helpers::new_backup_name( 'posts' ), Tool_Helpers::new_backup_name( 'posts' ) );
		$this->assertEmpty( glob( AGENT_BUILDER_BACKUPS_DIR . '/db/*.json' ) );
	}

	/**
	 * Restore reads the guarded file back and puts the rows back.
	 */
	public function test_table_backup_restores(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'agent_builder_agent_settings';

		\Agentic\Agent_Settings::update( 'agentic-test-backup', 'colour', 'blue' );
		$file = Tool_Helpers::backup_table( 'agent_builder_agent_settings' );
		$this->assertNotNull( $file );

		$listed = wp_list_pluck( Tool_Helpers::get_table_backups(), 'file' );
		$this->assertContains( $file, $listed );

		$wpdb->update( $table, array( 'meta_value' => 'red' ), array( 'agent_slug' => 'agentic-test-backup' ) );

		$result = Tool_Helpers::restore_table_backup( $file );
		$this->assertTrue( $result['success'], $result['message'] );
		$this->assertSame(
			'blue',
			$wpdb->get_var( $wpdb->prepare( 'SELECT meta_value FROM %i WHERE agent_slug = %s AND meta_key = %s', $table, 'agentic-test-backup', 'colour' ) )
		);

		$wpdb->delete( $table, array( 'agent_slug' => 'agentic-test-backup' ) );
	}

	/**
	 * Retention keeps the newest DB_BACKUP_KEEP copies of one table only,
	 * and a table whose name ends the same way is not counted with it.
	 */
	public function test_table_backup_retention_matches_exact_table(): void {
		$dir = AGENT_BUILDER_BACKUPS_DIR . '/db';
		File_Manager::ensure_protected_dir( $dir, true );

		File_Manager::put_guarded_contents( $dir . '/' . Tool_Helpers::new_backup_name( 'agent_builder_agent_settings', '20200101-000000' ), '{}' );
		for ( $i = 1; $i <= 4; $i++ ) {
			File_Manager::put_guarded_contents( $dir . '/' . Tool_Helpers::new_backup_name( 'settings', '2020010' . $i . '-000000' ), '{}' );
		}
		// Age every seeded file so the 60s throttle does not skip the new backup.
		foreach ( glob( $dir . '/*.php' ) as $f ) {
			touch( $f, time() - 3600 );
		}

		// The real table "settings" doesn't exist, so drive pruning through a real one.
		Tool_Helpers::backup_table( 'agent_builder_agent_settings' );

		$settings = array_filter( glob( $dir . '/*.php' ), fn( $f ) => (bool) preg_match( '/_[a-f0-9]{24}_settings\.php$/', $f ) );
		$own      = array_filter( glob( $dir . '/*.php' ), fn( $f ) => (bool) preg_match( '/_agent_builder_agent_settings\.php$/', $f ) );
		$this->assertCount( 4, $settings, 'another table\'s backups are never pruned' );
		$this->assertCount( 2, $own );
	}

	// ─── File backups ───────────────────────────────────────────────────────

	/**
	 * A file backup is guarded, carries no predictable name, lists with its
	 * original path, and restores the original content.
	 */
	public function test_file_backup_is_guarded_and_restores(): void {
		$uploads  = wp_upload_dir( null, false )['basedir'];
		$original = $uploads . '/agentic-test-restore.txt';
		file_put_contents( $original, 'original content' );

		$backup = Tool_Helpers::backup_file( $original );
		$this->assertNotNull( $backup );
		$this->assertMatchesRegularExpression( Tool_Helpers::BACKUP_NAME_PATTERN, basename( $backup ) );
		$this->assertStringStartsWith( "<?php exit; ?>\n", (string) file_get_contents( $backup ) );
		$this->assertStringNotContainsString( 'original content', (string) file_get_contents( $backup, false, null, 0, 15 ) );

		$listed = Tool_Helpers::get_backups();
		$this->assertCount( 1, $listed );
		$this->assertSame( basename( $backup ), $listed[0]['file'] );
		$this->assertSame( wp_normalize_path( $original ), wp_normalize_path( $listed[0]['original'] ) );
		$this->assertSame( strlen( 'original content' ), $listed[0]['size'] );

		file_put_contents( $original, 'changed by an agent' );
		$result = Tool_Helpers::restore_backup( basename( $backup ) );
		$this->assertTrue( $result['success'], $result['message'] );
		$this->assertSame( 'original content', file_get_contents( $original ) );

		unlink( $original );
	}

	/**
	 * Guarded writes are refused outside the private storage directories
	 * and for anything but a plain *.php name.
	 */
	public function test_guarded_write_is_confined_to_private_storage(): void {
		wp_mkdir_p( AGENT_BUILDER_BACKUPS_DIR );
		$uploads = wp_upload_dir( null, false )['basedir'];

		$this->assertFalse( File_Manager::put_guarded_contents( $uploads . '/agentic-test.php', 'x' ) );
		$this->assertFalse( File_Manager::put_guarded_contents( AGENT_BUILDER_BACKUPS_DIR . '/index.php', 'x' ) );
		$this->assertFalse( File_Manager::put_guarded_contents( AGENT_BUILDER_BACKUPS_DIR . '/a.json', 'x' ) );
		$this->assertTrue( File_Manager::put_guarded_contents( AGENT_BUILDER_BACKUPS_DIR . '/agentic-test.php', 'x' ) );
		$this->assertSame( 'x', File_Manager::get_guarded_contents( AGENT_BUILDER_BACKUPS_DIR . '/agentic-test.php' ) );
	}

	// ─── Legacy migration ───────────────────────────────────────────────────

	/**
	 * Old plain-text backups are converted (keeping their timestamp) and the
	 * plain files deleted; the converted copies still restore.
	 */
	public function test_legacy_backups_are_converted(): void {
		$db = AGENT_BUILDER_BACKUPS_DIR . '/db';
		wp_mkdir_p( $db );
		file_put_contents( AGENT_BUILDER_BACKUPS_DIR . '/.htaccess', "# old\n" );
		file_put_contents( $db . '/20250101-101010_posts.json', '{"table":"wp_posts","type":"full","row_count":0,"rows":[]}' );
		file_put_contents( AGENT_BUILDER_BACKUPS_DIR . '/20250101-101010_uploads__agentic-legacy.txt', 'legacy' );

		$this->assertSame( 2, Tool_Helpers::count_unprotected_backups() );
		$this->assertSame( 2, Tool_Helpers::migrate_legacy_backups( true ) );
		$this->assertSame( 0, Tool_Helpers::count_unprotected_backups() );

		$this->assertEmpty( glob( $db . '/*.json' ) );
		$this->assertFileDoesNotExist( AGENT_BUILDER_BACKUPS_DIR . '/20250101-101010_uploads__agentic-legacy.txt' );
		$this->assertSame( "# old\n", file_get_contents( AGENT_BUILDER_BACKUPS_DIR . '/.htaccess' ), 'a customised .htaccess is never overwritten' );

		$tables = Tool_Helpers::get_table_backups();
		$this->assertCount( 1, $tables );
		$this->assertStringStartsWith( '20250101-101010_', $tables[0]['file'] );
		$this->assertSame( 'posts', $tables[0]['table_short'] );

		$files = Tool_Helpers::get_backups();
		$this->assertCount( 1, $files );
		$this->assertSame( 'uploads/agentic-legacy.txt', $files[0]['original_rel'] );

		$this->assertSame( Tool_Helpers::BACKUP_FORMAT_VERSION, (int) get_option( Tool_Helpers::BACKUP_FORMAT_OPTION ) );
	}

	/**
	 * The plugin's own older execution-only .htaccess is upgraded to deny-all.
	 */
	public function test_old_plugin_htaccess_is_upgraded_to_deny_all(): void {
		$dir = AGENT_BUILDER_KNOWLEDGE_DIR;
		File_Manager::ensure_protected_dir( $dir );
		$this->assertStringNotContainsString( 'Agent Builder private storage', (string) file_get_contents( $dir . '/.htaccess' ) );

		File_Manager::ensure_protected_dir( $dir, true );
		$htaccess = (string) file_get_contents( $dir . '/.htaccess' );
		$this->assertStringContainsString( 'Agent Builder private storage', $htaccess );
		$this->assertStringContainsString( 'allowUnlisted="false"', (string) file_get_contents( $dir . '/web.config' ) );
	}

	// ─── Site Health ────────────────────────────────────────────────────────

	/**
	 * Simulate a web server for the loopback probe.
	 *
	 * @param string $mode 'nginx' (static served, PHP executed), 'php-as-text', 'denied' or 'error'.
	 */
	private function fake_server( string $mode ): void {
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( $mode ) {
				if ( 'error' === $mode ) {
					return new \WP_Error( 'http_request_failed', 'loopback failed' );
				}
				if ( 'denied' === $mode ) {
					return array(
						'headers'  => array(),
						'body'     => 'Forbidden',
						'response' => array( 'code' => 403, 'message' => 'Forbidden' ),
						'cookies'  => array(),
					);
				}
				$path = WP_CONTENT_DIR . substr( (string) wp_parse_url( $url, PHP_URL_PATH ), strlen( (string) wp_parse_url( content_url(), PHP_URL_PATH ) ) );
				$body = is_readable( $path ) ? (string) file_get_contents( $path ) : '';
				if ( str_ends_with( $path, '.php' ) && 'nginx' === $mode ) {
					$body = ''; // Executed: exit; before the payload.
				}
				return array(
					'headers'  => array(),
					'body'     => $body,
					'response' => array( 'code' => 200, 'message' => 'OK' ),
					'cookies'  => array(),
				);
			},
			10,
			3
		);
	}

	/**
	 * Nginx that serves static files: knowledge readable → recommended, not critical.
	 */
	public function test_site_health_recommended_when_static_files_are_served(): void {
		wp_mkdir_p( AGENT_BUILDER_BACKUPS_DIR );
		wp_mkdir_p( AGENT_BUILDER_KNOWLEDGE_DIR );
		$this->fake_server( 'nginx' );

		$result = Site_Health::test_private_storage();
		$this->assertSame( 'recommended', $result['status'] );
		$this->assertSame( 'agent_builder_private_storage', $result['test'] );
		$this->assertStringContainsString( 'deny all', $result['actions'] );
		$this->assertEmpty( glob( AGENT_BUILDER_BACKUPS_DIR . '/agentic-probe-*' ), 'probe files are cleaned up' );
	}

	/**
	 * A server that hands out .php source would leak backups → critical.
	 */
	public function test_site_health_critical_when_guarded_payload_is_readable(): void {
		wp_mkdir_p( AGENT_BUILDER_BACKUPS_DIR );
		$this->fake_server( 'php-as-text' );

		$this->assertSame( 'critical', Site_Health::test_private_storage()['status'] );
	}

	/**
	 * Denied requests → good; a failing loopback → skipped (good), never an error.
	 */
	public function test_site_health_good_when_denied_or_skipped(): void {
		wp_mkdir_p( AGENT_BUILDER_BACKUPS_DIR );
		wp_mkdir_p( AGENT_BUILDER_KNOWLEDGE_DIR );

		$this->fake_server( 'denied' );
		$this->assertSame( 'good', Site_Health::test_private_storage()['status'] );

		remove_all_filters( 'pre_http_request' );
		$this->fake_server( 'error' );
		$result = Site_Health::test_private_storage();
		$this->assertSame( 'good', $result['status'] );
		$this->assertStringContainsString( 'skipped', $result['description'] );
	}

	/**
	 * The test is registered with Site Health.
	 */
	public function test_site_health_registers_storage_test(): void {
		$tests = Site_Health::add_tests( array( 'direct' => array() ) );
		$this->assertArrayHasKey( 'agent_builder_private_storage', $tests['direct'] );
	}

	// ─── Uninstall ──────────────────────────────────────────────────────────

	/**
	 * Delete-all uninstall removes both data folders recursively, and nothing
	 * outside wp-content/agentic-*.
	 */
	public function test_uninstall_cleanup_removes_data_folders(): void {
		Tool_Helpers::backup_table( 'agent_builder_agent_settings' );
		wp_mkdir_p( AGENT_BUILDER_KNOWLEDGE_DIR . '/okf/site' );
		file_put_contents( AGENT_BUILDER_KNOWLEDGE_DIR . '/okf/site/index.md', '# x' );

		$outside = WP_CONTENT_DIR . '/uploads/agentic-keep-me';
		wp_mkdir_p( $outside );

		$removed = Uninstall_Cleanup::remove_data_directories( array_merge( Uninstall_Cleanup::data_directories(), array( $outside ) ) );

		$this->assertCount( 2, $removed );
		$this->assertDirectoryDoesNotExist( AGENT_BUILDER_BACKUPS_DIR );
		$this->assertDirectoryDoesNotExist( AGENT_BUILDER_KNOWLEDGE_DIR );
		$this->assertDirectoryExists( $outside, 'only wp-content/agentic-* folders are ever removed' );

		rmdir( $outside );
	}

	/**
	 * uninstall.php calls the cleanup only past the keep-data early return.
	 */
	public function test_uninstall_php_wires_cleanup_after_keep_data_return(): void {
		$src  = (string) file_get_contents( AGENT_BUILDER_DIR . 'uninstall.php' );
		$keep = strpos( $src, "if ( '1' !== \$agentic_delete_data )" );
		$call = strpos( $src, 'Uninstall_Cleanup::remove_data_directories()' );
		$this->assertNotFalse( $keep );
		$this->assertNotFalse( $call );
		$this->assertGreaterThan( $keep, $call );
	}
}
