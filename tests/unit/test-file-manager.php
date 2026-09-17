<?php
/**
 * Unit Tests for File_Manager.
 *
 * Covers the extension denylist and allowed-root restrictions added for the
 * WordPress.org Plugin Developer FAQ / Guideline 8 review pass: no tool may
 * ever write an executable file (.php and friends, or .htaccess/.htpasswd),
 * and the plugin's own directory (AGENT_BUILDER_DIR) is writable only at the
 * single, fixed `library/agents/*\/abilities.json` pattern that
 * configure_approval_gate and enable_webmcp_defaults actually use.
 *
 * @package Agentic\Tests
 */

namespace Agentic\Tests;

use Agentic\File_Manager;

/**
 * Test case for File_Manager.
 */
class Test_File_Manager extends TestCase {

	/**
	 * Absolute paths created during a test, removed in tearDown() regardless
	 * of whether the write under test was expected to succeed or fail.
	 *
	 * @var string[]
	 */
	private array $created_paths = array();

	/**
	 * Teardown: remove any file this test actually created.
	 */
	public function tearDown(): void {
		foreach ( $this->created_paths as $path ) {
			if ( file_exists( $path ) ) {
				unlink( $path );
			}
		}
		$this->created_paths = array();
		parent::tearDown();
	}

	// ─── Extension denylist ──────────────────────────────────────────────────

	/**
	 * A .php write under the uploads directory — the classic uploads-dir RCE
	 * shape — is rejected even though uploads is an otherwise-allowed root.
	 */
	public function test_put_contents_rejects_php_under_uploads(): void {
		$upload_dir = wp_upload_dir( null, false );
		$path       = trailingslashit( $upload_dir['basedir'] ) . 'agentic-test-shell.php';
		$this->created_paths[] = $path;

		$this->assertFalse( File_Manager::put_contents( $path, '<?php echo "pwned"; ?>' ) );
		$this->assertFileDoesNotExist( $path );
	}

	/**
	 * .htaccess is denied too — a permissive .htaccess re-enabling PHP
	 * execution (or overriding an existing deny rule) is the other half of
	 * the classic uploads-dir bypass; blocking only .php is not enough.
	 */
	public function test_put_contents_rejects_htaccess_under_uploads(): void {
		$upload_dir = wp_upload_dir( null, false );
		$path       = trailingslashit( $upload_dir['basedir'] ) . '.htaccess';
		$this->created_paths[] = $path;

		$this->assertFalse( File_Manager::put_contents( $path, 'Options +ExecCGI' ) );
		$this->assertFileDoesNotExist( $path );
	}

	/**
	 * A double extension ending in .php (e.g. "shell.jpg.php") is still
	 * denied — it executes as PHP on a stock Apache/Nginx config exactly
	 * like a plain "shell.php" would.
	 */
	public function test_put_contents_rejects_double_extension_ending_in_php(): void {
		$upload_dir = wp_upload_dir( null, false );
		$path       = trailingslashit( $upload_dir['basedir'] ) . 'agentic-test-shell.jpg.php';
		$this->created_paths[] = $path;

		$this->assertFalse( File_Manager::put_contents( $path, '<?php echo "pwned"; ?>' ) );
		$this->assertFileDoesNotExist( $path );
	}

	/**
	 * NTFS silently strips trailing dots/spaces at file-creation time, so a
	 * check for exactly "shell.php" can be evaded by asking to write
	 * "shell.php." (or with trailing spaces) on a Windows host, where the
	 * file that actually lands on disk is plain "shell.php".
	 */
	public function test_put_contents_rejects_windows_trailing_dot_bypass(): void {
		$upload_dir = wp_upload_dir( null, false );
		$path       = trailingslashit( $upload_dir['basedir'] ) . 'agentic-test-shell.php.';
		$this->created_paths[] = $path;
		$this->created_paths[] = rtrim( $path, '.' );

		$this->assertFalse( File_Manager::put_contents( $path, '<?php echo "pwned"; ?>' ) );
	}

	/**
	 * An NTFS Alternate Data Stream reference doesn't end in a denylisted
	 * extension by simple string matching, so any filename containing "::"
	 * is rejected outright rather than trying to parse the stream name.
	 */
	public function test_put_contents_rejects_ntfs_alternate_data_stream(): void {
		$upload_dir = wp_upload_dir( null, false );
		$path       = trailingslashit( $upload_dir['basedir'] ) . 'agentic-test-shell.php::$DATA';
		$this->created_paths[] = $path;

		$this->assertFalse( File_Manager::put_contents( $path, '<?php echo "pwned"; ?>' ) );
	}

	/**
	 * A safe extension (.json/.txt/.md) under an allowed root still writes
	 * normally — the denylist must not become an accidental allowlist.
	 */
	public function test_put_contents_still_accepts_safe_extensions_under_uploads(): void {
		$upload_dir = wp_upload_dir( null, false );
		$path       = trailingslashit( $upload_dir['basedir'] ) . 'agentic-test-note.txt';
		$this->created_paths[] = $path;

		$this->assertTrue( File_Manager::put_contents( $path, 'hello' ) );
		$this->assertFileExists( $path );
		$this->assertSame( 'hello', file_get_contents( $path ) );
	}

	/**
	 * copy() enforces the same denylist on the destination, not just
	 * put_contents() — a source file's own extension is irrelevant.
	 */
	public function test_copy_rejects_disallowed_destination_extension(): void {
		$upload_dir = wp_upload_dir( null, false );
		$src        = trailingslashit( $upload_dir['basedir'] ) . 'agentic-test-src.txt';
		$dest       = trailingslashit( $upload_dir['basedir'] ) . 'agentic-test-dest.php';
		$this->created_paths[] = $src;
		$this->created_paths[] = $dest;

		file_put_contents( $src, 'source content' );

		$this->assertFalse( File_Manager::copy( $src, $dest ) );
		$this->assertFileDoesNotExist( $dest );
	}

	// ─── AGENT_BUILDER_DIR narrow exception ─────────────────────────────────

	/**
	 * The one thing still writable under the plugin's own directory: a
	 * bundled agent's abilities.json, matching what configure_approval_gate
	 * and enable_webmcp_defaults actually write. Checked via is_allowed_path()
	 * directly against a non-existent test slug — realpath()'s "parent
	 * doesn't exist yet" fallback still resolves the string prefix check
	 * correctly, so this never touches the real library/agents/ tree.
	 */
	public function test_agent_builder_dir_allows_abilities_json_under_library_agents(): void {
		$path = AGENT_BUILDER_DIR . 'library/agents/_agentic_test_slug/abilities.json';

		$this->assertTrue( File_Manager::is_allowed_path( $path ) );
	}

	/**
	 * Nothing else under library/agents/ is writable — same directory, a
	 * disallowed filename.
	 */
	public function test_agent_builder_dir_rejects_agent_php_under_library_agents(): void {
		$path = AGENT_BUILDER_DIR . 'library/agents/_agentic_test_slug/agent.php';

		$this->assertFalse( File_Manager::is_allowed_path( $path ) );
	}

	/**
	 * Nothing outside library/agents/ in the plugin directory is writable at
	 * all, even a same-named abilities.json.
	 */
	public function test_agent_builder_dir_rejects_abilities_json_outside_library_agents(): void {
		$path = AGENT_BUILDER_DIR . 'includes/abilities.json';

		$this->assertFalse( File_Manager::is_allowed_path( $path ) );
	}

	/**
	 * robots.txt and llms.txt at the site web root are the one named-file
	 * exception outside every other root — required so
	 * Tool_Helpers::restore_backup() can restore either one.
	 */
	public function test_site_root_allows_only_robots_and_llms_txt(): void {
		$this->assertTrue( File_Manager::is_allowed_path( rtrim( ABSPATH, '/' ) . '/robots.txt' ) );
		$this->assertTrue( File_Manager::is_allowed_path( rtrim( ABSPATH, '/' ) . '/llms.txt' ) );
		$this->assertFalse( File_Manager::is_allowed_path( rtrim( ABSPATH, '/' ) . '/shell.php' ) );
		$this->assertFalse( File_Manager::is_allowed_path( rtrim( ABSPATH, '/' ) . '/wp-content/robots.txt' ) );
	}

	// ─── Other allowed roots ─────────────────────────────────────────────────

	/**
	 * AGENT_BUILDER_BACKUPS_DIR was missing from the allowed roots entirely before
	 * this pass — Tool_Helpers::backup_file() writes there, so it must be
	 * allowed (still subject to the same extension denylist as everywhere
	 * else).
	 */
	public function test_agentic_backups_dir_is_an_allowed_root(): void {
		$this->assertTrue(
			File_Manager::is_allowed_path( AGENT_BUILDER_BACKUPS_DIR . '/20260101-000000_agentic-agents__test-agent__abilities.json' )
		);
	}

	/**
	 * A path with no relation to any allowed root is rejected regardless of
	 * extension.
	 */
	public function test_rejects_path_outside_every_allowed_root(): void {
		$this->assertFalse( File_Manager::is_allowed_path( sys_get_temp_dir() . '/agentic-test-outside.json' ) );
	}

	// ─── ensure_protected_dir() ──────────────────────────────────────────────

	/**
	 * A freshly created directory gets an execution-denying index.php,
	 * .htaccess, and web.config — defense-in-depth for a misconfigured
	 * webserver, on top of the write-time extension denylist.
	 */
	public function test_ensure_protected_dir_seeds_execution_deny_files(): void {
		$upload_dir = wp_upload_dir( null, false );
		$dir        = trailingslashit( $upload_dir['basedir'] ) . 'agentic-test-protected-dir';

		$this->assertTrue( File_Manager::ensure_protected_dir( $dir ) );
		$this->assertFileExists( $dir . '/index.php' );
		$this->assertFileExists( $dir . '/.htaccess' );
		$this->assertFileExists( $dir . '/web.config' );
		$this->assertStringContainsString( 'Require all denied', file_get_contents( $dir . '/.htaccess' ) );
		$this->assertStringContainsString( '<requestFiltering>', file_get_contents( $dir . '/web.config' ) );

		$this->delete_directory( $dir );
	}

	/**
	 * A site owner's own customisation of any of these three files is never
	 * overwritten by a later call.
	 */
	public function test_ensure_protected_dir_does_not_overwrite_existing_files(): void {
		$upload_dir = wp_upload_dir( null, false );
		$dir        = trailingslashit( $upload_dir['basedir'] ) . 'agentic-test-protected-dir-custom';

		wp_mkdir_p( $dir );
		file_put_contents( $dir . '/.htaccess', '# custom rule' );

		File_Manager::ensure_protected_dir( $dir );

		$this->assertSame( '# custom rule', file_get_contents( $dir . '/.htaccess' ) );

		$this->delete_directory( $dir );
	}
}
