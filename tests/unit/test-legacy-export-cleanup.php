<?php
/**
 * Unit tests for Activator::maybe_cleanup_legacy_agent_exports().
 *
 * Confirms the one-time security sweep removes legacy agent-export output left
 * behind by earlier revisions of the exporter: the whole wp-content/agentic-exports/
 * directory tree (the intermediate revision's working dir), and the *.zip files at
 * wp_upload_dir()['basedir'] . '/agentic-exports/' (the original pre-fix exporter's
 * predictable-filename output). It runs from an always-fires hook with no
 * authenticated user, runs only once, does not mark itself done while legacy
 * output remains, and never touches non-zip files in the shared uploads
 * directory — which unrelated document-export tools also use.
 *
 * @package Agentic\Tests
 */

namespace Agentic\Tests;

use Agentic\Activator;

/**
 * Test case for the legacy agent-export cleanup migration.
 */
class Test_Legacy_Export_Cleanup extends TestCase {

	/**
	 * Absolute path to the legacy wp-content/agentic-exports directory.
	 *
	 * @var string
	 */
	private string $exports_dir;

	/**
	 * Absolute path to the legacy uploads/agentic-exports directory.
	 *
	 * @var string
	 */
	private string $legacy_dir;

	/**
	 * Previous stored cleanup-flag option, restored in tearDown.
	 *
	 * @var mixed
	 */
	private $previous_flag;

	/**
	 * Previous stored failure-count option, restored in tearDown.
	 *
	 * @var mixed
	 */
	private $previous_fail_count;

	/**
	 * Previous stored give-up flag option, restored in tearDown.
	 *
	 * @var mixed
	 */
	private $previous_gave_up;

	/**
	 * Snapshot state and resolve the legacy directory paths.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->exports_dir          = untrailingslashit( WP_CONTENT_DIR ) . '/agentic-exports';
		$this->legacy_dir           = untrailingslashit( wp_upload_dir()['basedir'] ) . '/agentic-exports';
		$this->previous_flag        = get_option( 'agent_builder_legacy_exports_cleaned', false );
		$this->previous_fail_count  = get_option( 'agent_builder_legacy_exports_fail_count', false );
		$this->previous_gave_up     = get_option( 'agent_builder_legacy_exports_gave_up', false );
		delete_option( 'agent_builder_legacy_exports_cleaned' );
		delete_option( 'agent_builder_legacy_exports_fail_count' );
		delete_option( 'agent_builder_legacy_exports_gave_up' );
		wp_mkdir_p( $this->legacy_dir );
	}

	/**
	 * Remove any files left in the legacy directories and restore state.
	 */
	public function tearDown(): void {
		@chmod( $this->legacy_dir, 0755 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Restore writability.
		foreach ( (array) glob( $this->legacy_dir . '/*' ) as $path ) {
			if ( is_file( $path ) ) {
				wp_delete_file( $path );
			} elseif ( is_dir( $path ) ) {
				\Agentic\File_Manager::rmdir( $path, true );
			}
		}
		@chmod( $this->exports_dir, 0755 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Restore writability.
		\Agentic\File_Manager::rmdir( $this->exports_dir, true );
		if ( false === $this->previous_flag ) {
			delete_option( 'agent_builder_legacy_exports_cleaned' );
		} else {
			update_option( 'agent_builder_legacy_exports_cleaned', $this->previous_flag );
		}
		if ( false === $this->previous_fail_count ) {
			delete_option( 'agent_builder_legacy_exports_fail_count' );
		} else {
			update_option( 'agent_builder_legacy_exports_fail_count', $this->previous_fail_count );
		}
		if ( false === $this->previous_gave_up ) {
			delete_option( 'agent_builder_legacy_exports_gave_up' );
		} else {
			update_option( 'agent_builder_legacy_exports_gave_up', $this->previous_gave_up );
		}
		delete_transient( 'agent_builder_legacy_exports_lock' );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * The sweep is hooked on init (which always fires, before auth), not on
	 * admin_init (which never fires for unauthenticated, cron, or REST
	 * requests and would leave the exposure standing until an admin visits).
	 */
	public function test_hooked_on_always_fires_init(): void {
		$this->assertNotFalse(
			has_action( 'init', array( Activator::class, 'maybe_cleanup_legacy_agent_exports' ) ),
			'Expected the legacy cleanup to be hooked on init (always fires), not admin_init'
		);
		$this->assertFalse(
			has_action( 'admin_init', array( Activator::class, 'maybe_cleanup_legacy_agent_exports' ) ),
			'Expected the legacy cleanup to no longer be hooked on admin_init'
		);
	}

	/**
	 * The first run removes a legacy zip and marks the cleanup as done —
	 * without any authenticated user, since the files are an unauthenticated
	 * disclosure exposure and must be cleared even if nobody logs in.
	 */
	public function test_removes_legacy_zip_and_sets_flag_without_auth(): void {
		$zip_path = $this->legacy_dir . '/content-writer.zip';
		file_put_contents( $zip_path, 'zip-bytes' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents -- Test fixture.
		wp_set_current_user( 0 );

		Activator::maybe_cleanup_legacy_agent_exports();

		$this->assertFileDoesNotExist( $zip_path );
		$this->assertTrue( (bool) get_option( 'agent_builder_legacy_exports_cleaned' ) );
	}

	/**
	 * The whole wp-content/agentic-exports/ directory tree (the intermediate
	 * revision's export working dir, now fully legacy) is removed — not just
	 * the .zip files sitting directly inside it.
	 */
	public function test_removes_whole_wp_content_exports_directory(): void {
		wp_mkdir_p( $this->exports_dir . '/subdir' );
		file_put_contents( $this->exports_dir . '/orphan.zip', 'zip-bytes' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		file_put_contents( $this->exports_dir . '/subdir/leftover.tmp', 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		wp_set_current_user( 0 );

		Activator::maybe_cleanup_legacy_agent_exports();

		$this->assertDirectoryDoesNotExist( $this->exports_dir );
		$this->assertTrue( (bool) get_option( 'agent_builder_legacy_exports_cleaned' ) );
	}

	/**
	 * Once the flag is set, a later zip placed at the legacy path is left
	 * alone — the migration is one-time only, not a standing sweep.
	 */
	public function test_runs_only_once(): void {
		update_option( 'agent_builder_legacy_exports_cleaned', true );
		$zip_path = $this->legacy_dir . '/late-arrival.zip';
		file_put_contents( $zip_path, 'zip-bytes' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents -- Test fixture.

		Activator::maybe_cleanup_legacy_agent_exports();

		$this->assertFileExists( $zip_path );
	}

	/**
	 * The sweep must not mark itself done while a legacy zip is still on disk:
	 * if a delete fails the flag stays unset so a later request retries, and a
	 * retry after the failure is cleared completes the job.
	 */
	public function test_does_not_mark_done_when_a_delete_fails(): void {
		$this->skip_when_root();

		$zip_path = $this->legacy_dir . '/content-writer.zip';
		file_put_contents( $zip_path, 'zip-bytes' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents -- Test fixture.
		wp_set_current_user( 0 );
		// Read-only directory makes unlink() fail (this process is unprivileged),
		// simulating a real delete failure rather than a mocked one.
		chmod( $this->legacy_dir, 0500 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Simulate delete failure.

		Activator::maybe_cleanup_legacy_agent_exports();

		$this->assertFileExists( $zip_path, 'Delete failed, so the zip must remain' );
		$this->assertFalse( get_option( 'agent_builder_legacy_exports_cleaned' ), 'Migration must not mark itself done while a legacy zip remains' );

		// Restore writability and confirm a retry then finishes and flags done.
		chmod( $this->legacy_dir, 0755 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Restore writability.
		Activator::maybe_cleanup_legacy_agent_exports();
		$this->assertFileDoesNotExist( $zip_path );
		$this->assertTrue( (bool) get_option( 'agent_builder_legacy_exports_cleaned' ) );
	}

	/**
	 * An unreadable entry inside the legacy wp-content/agentic-exports/ tree
	 * makes the recursive delete throw (RecursiveDirectoryIterator throws on a
	 * directory it cannot open). File_Manager::rmdir() must swallow that and
	 * return false instead — so this one-time security sweep degrades to "leave
	 * the flag unset, retry next request" rather than fatalling every request
	 * (the sweep runs on init, for every visitor, until the flag is set).
	 */
	public function test_unreadable_legacy_entry_does_not_fatal(): void {
		$this->skip_when_root();

		wp_mkdir_p( $this->exports_dir . '/locked' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture write.
		file_put_contents( $this->exports_dir . '/locked/secret.zip', 'zip-bytes' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Simulate an unreadable entry.
		chmod( $this->exports_dir . '/locked', 0000 );
		wp_set_current_user( 0 );

		try {
			Activator::maybe_cleanup_legacy_agent_exports();
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Restore so tearDown can remove the tree.
			chmod( $this->exports_dir . '/locked', 0755 );
		}

		$this->assertFalse(
			get_option( 'agent_builder_legacy_exports_cleaned' ),
			'An unreadable legacy entry must leave the flag unset (retry later), not crash the request'
		);
	}

	/**
	 * A concurrent request holding the lock skips the sweep and leaves the
	 * flag unset so it is retried once the lock clears.
	 */
	public function test_respects_concurrent_lock(): void {
		$zip_path = $this->legacy_dir . '/content-writer.zip';
		file_put_contents( $zip_path, 'zip-bytes' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents -- Test fixture.
		set_transient( 'agent_builder_legacy_exports_lock', 1, 30 );

		Activator::maybe_cleanup_legacy_agent_exports();

		$this->assertFileExists( $zip_path );
		$this->assertFalse( get_option( 'agent_builder_legacy_exports_cleaned' ) );
	}

	/**
	 * Non-zip files in the same directory belong to unrelated document
	 * tools (create_docx, create_pdf, create_spreadsheet, merge_pdfs,
	 * html_to_docx) and must survive the cleanup untouched.
	 */
	public function test_does_not_touch_non_zip_files(): void {
		$docx_path = $this->legacy_dir . '/report.docx';
		file_put_contents( $docx_path, 'docx-bytes' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents -- Test fixture.
		wp_set_current_user( 0 );

		Activator::maybe_cleanup_legacy_agent_exports();

		$this->assertFileExists( $docx_path );
	}

	/**
	 * A .zip in the shared uploads/agentic-exports/ directory that is not
	 * slug-shaped (e.g. a manual backup or a future unrelated tool's output) is
	 * left alone: the sweep deletes only the <slug>.zip files the pre-fix
	 * exporter actually named, not every .zip it happens to find there.
	 */
	public function test_does_not_touch_non_slug_zip_files(): void {
		$backup_path = $this->legacy_dir . '/My Manual Backup.zip';
		file_put_contents( $backup_path, 'zip-bytes' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents -- Test fixture.
		wp_set_current_user( 0 );

		Activator::maybe_cleanup_legacy_agent_exports();

		$this->assertFileExists( $backup_path );
		$this->assertTrue( (bool) get_option( 'agent_builder_legacy_exports_cleaned' ), 'A non-legacy zip must not block completion' );
	}

	/**
	 * A slug-shaped .zip in the shared uploads/agentic-exports/ directory that
	 * does not match any installed agent and was written after the streaming
	 * exports release (e.g. a manual client backup) survives: the sweep no
	 * longer deletes every slug-shaped .zip it finds there.
	 */
	public function test_does_not_touch_unrelated_slug_zip(): void {
		$backup_path = $this->legacy_dir . '/client-backup.zip';
		file_put_contents( $backup_path, 'zip-bytes' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents -- Test fixture.
		wp_set_current_user( 0 );

		Activator::maybe_cleanup_legacy_agent_exports();

		$this->assertFileExists( $backup_path, 'An unrelated client backup must survive the legacy sweep' );
		$this->assertTrue( (bool) get_option( 'agent_builder_legacy_exports_cleaned' ), 'A non-legacy zip must not block completion' );
	}

	/**
	 * A slug-shaped .zip whose mtime predates the streaming-exports release is
	 * still removed even when its slug no longer matches an installed agent —
	 * the pre-fix exporter named output after agents that have since been
	 * deleted, and those must still be swept.
	 */
	public function test_removes_old_uninstalled_slug_zip(): void {
		$old_path = $this->legacy_dir . '/deleted-agent.zip';
		file_put_contents( $old_path, 'zip-bytes' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents -- Test fixture.
		// Backdate the file to before the streaming-exports release (epoch
		// 1790467200, 2026-09-27 UTC — mirrors Activator::LEGACY_EXPORT_STREAMING_CUTOFF).
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- Test fixture.
		touch( $old_path, 1790467200 - 3600 );
		wp_set_current_user( 0 );

		Activator::maybe_cleanup_legacy_agent_exports();

		$this->assertFileDoesNotExist( $old_path );
		$this->assertTrue( (bool) get_option( 'agent_builder_legacy_exports_cleaned' ) );
	}

	/**
	 * A permanently-stuck cleanup (a legacy "zip" that can never be deleted)
	 * stops retrying automatically after a short run of failed attempts: the
	 * failure counter crosses LEGACY_EXPORT_CLEANUP_MAX_FAILURES, the give-up
	 * flag is set, and further requests short-circuit instead of re-running the
	 * sweep forever. The stuck entry is simulated with a non-empty directory
	 * named <slug>.zip — for an installed agent slug (content-writer), so the
	 * sweep still recognises it as legacy — which wp_delete_file() cannot remove
	 * even as root.
	 */
	public function test_gives_up_after_repeated_failures(): void {
		$stuck = $this->legacy_dir . '/content-writer.zip';
		wp_mkdir_p( $stuck . '/inner' );
		file_put_contents( $stuck . '/inner/keep.txt', 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents -- Test fixture making the directory non-empty.
		wp_set_current_user( 0 );

		$max = 10; // Mirrors Activator::LEGACY_EXPORT_CLEANUP_MAX_FAILURES.
		for ( $i = 0; $i < $max; $i++ ) {
			Activator::maybe_cleanup_legacy_agent_exports();
			$this->assertFalse( get_option( 'agent_builder_legacy_exports_cleaned' ), 'Must not mark done while the stuck file remains' );
		}

		$this->assertTrue( (bool) get_option( 'agent_builder_legacy_exports_gave_up' ), 'Expected the sweep to give up after repeated failures' );
		$this->assertSame( $max, (int) get_option( 'agent_builder_legacy_exports_fail_count' ) );

		// Once it has given up, further requests short-circuit and no longer
		// re-run the sweep or advance the counter.
		Activator::maybe_cleanup_legacy_agent_exports();
		$this->assertSame( $max, (int) get_option( 'agent_builder_legacy_exports_fail_count' ) );
		$this->assertTrue( is_dir( $stuck ), 'The stuck entry is left for a human to remove' );

		$this->delete_directory( $stuck );
	}
}
