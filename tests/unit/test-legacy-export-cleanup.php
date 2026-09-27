<?php
/**
 * Unit tests for Activator::maybe_cleanup_legacy_agent_exports().
 *
 * Confirms the one-time migration removes agent-export zips left behind
 * by the pre-fix exporter at wp_upload_dir()['basedir'] . '/agentic-exports/'
 * (predictable filename, inside the public uploads tree), runs only once,
 * requires manage_options, and never touches non-zip files in that same
 * directory — it is also used by unrelated document-export tools.
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
	 * Snapshot state and resolve the legacy directory path.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->legacy_dir    = untrailingslashit( wp_upload_dir()['basedir'] ) . '/agentic-exports';
		$this->previous_flag = get_option( 'agent_builder_legacy_exports_cleaned', false );
		delete_option( 'agent_builder_legacy_exports_cleaned' );
		wp_mkdir_p( $this->legacy_dir );
	}

	/**
	 * Remove any files left in the legacy directory and restore state.
	 */
	public function tearDown(): void {
		foreach ( (array) glob( $this->legacy_dir . '/*' ) as $file ) {
			if ( is_file( $file ) ) {
				wp_delete_file( $file );
			}
		}
		if ( false === $this->previous_flag ) {
			delete_option( 'agent_builder_legacy_exports_cleaned' );
		} else {
			update_option( 'agent_builder_legacy_exports_cleaned', $this->previous_flag );
		}
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * An administrator's first admin_init removes a legacy zip and marks
	 * the cleanup as done.
	 */
	public function test_removes_legacy_zip_and_sets_flag(): void {
		$zip_path = $this->legacy_dir . '/content-writer.zip';
		file_put_contents( $zip_path, 'zip-bytes' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents -- Test fixture.
		$this->enter_admin_as_logged_in_user();

		Activator::maybe_cleanup_legacy_agent_exports();

		$this->assertFileDoesNotExist( $zip_path );
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
		$this->enter_admin_as_logged_in_user();

		Activator::maybe_cleanup_legacy_agent_exports();

		$this->assertFileExists( $zip_path );
	}

	/**
	 * A non-admin request never deletes anything and never sets the flag,
	 * so a later admin visit still gets to run the cleanup.
	 */
	public function test_requires_manage_options(): void {
		$zip_path = $this->legacy_dir . '/content-writer.zip';
		file_put_contents( $zip_path, 'zip-bytes' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents -- Test fixture.
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

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
		$this->enter_admin_as_logged_in_user();

		Activator::maybe_cleanup_legacy_agent_exports();

		$this->assertFileExists( $docx_path );
	}

	/**
	 * Create and switch to a logged-in administrator, matching the
	 * capability the migration is gated on.
	 */
	private function enter_admin_as_logged_in_user(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
	}
}
