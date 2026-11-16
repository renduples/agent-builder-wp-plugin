<?php
/**
 * Unit tests for where the plugin stores data (WordPress.org review):
 * nothing is written inside the plugin directory, user data lives in the
 * uploads data root, the pre-4.5 wp-content/agentic-* folders are migrated
 * once, and the plugin never defines DISALLOW_FILE_EDIT.
 *
 * @package Agentic\Tests
 */

namespace Agentic\Tests;

use Agentic\Abilities_Manifest;
use Agentic\Admin_Pages_REST;
use Agentic\File_Manager;
use Agentic\Storage_Migration;
use Agentic\Tool_Loader;

/**
 * Test case for the storage location rules.
 */
class Test_Storage_Location extends TestCase {

	/**
	 * Bundled agent used for the read-only checks.
	 */
	private const BUNDLED = 'wordpress-assistant';

	/**
	 * Options saved before each test.
	 *
	 * @var array<string, mixed>
	 */
	private array $saved = array();

	/**
	 * Run as an administrator; remember the options these tests change.
	 */
	public function setUp(): void {
		parent::setUp();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		foreach ( array( Abilities_Manifest::OVERRIDES_OPTION, Abilities_Manifest::SIGNATURES_OPTION, 'agent_builder_active_agents', \Agentic\Webmcp_Bridge::OPTION_ENABLED ) as $option ) {
			$this->saved[ $option ] = get_option( $option, null );
		}
		Abilities_Manifest::clear_cache();
		// The test bootstrap already ran the migration once (no legacy
		// folders yet), which recorded it as done.
		delete_option( Storage_Migration::MIGRATED_OPTION );
		delete_transient( 'agent_builder_storage_migration_retry' );
	}

	/**
	 * Restore options and remove legacy fixtures.
	 */
	public function tearDown(): void {
		foreach ( $this->saved as $option => $value ) {
			null === $value ? delete_option( $option ) : update_option( $option, $value );
		}
		foreach ( array_keys( File_Manager::LEGACY_DIRS ) as $kind ) {
			$this->delete_directory( File_Manager::legacy_dir( $kind ) );
		}
		delete_option( Storage_Migration::MIGRATED_OPTION );
		delete_transient( 'agent_builder_storage_migration_retry' );
		Abilities_Manifest::clear_cache();
		parent::tearDown();
	}

	/**
	 * Hash of every file in the plugin directory (path => md5), skipping
	 * dependency folders and symlinks.
	 *
	 * @return array<string, string>
	 */
	private function plugin_dir_snapshot(): array {
		$root     = rtrim( AGENT_BUILDER_DIR, '/' );
		$snapshot = array();
		$skip     = array( 'vendor', 'node_modules', '.git' );

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveCallbackFilterIterator(
				new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ),
				static function ( \SplFileInfo $file ) use ( $root, $skip ): bool {
					if ( $file->isLink() ) {
						return false;
					}
					return ! ( $file->isDir() && dirname( $file->getPathname() ) === $root && in_array( $file->getFilename(), $skip, true ) );
				}
			)
		);

		foreach ( $iterator as $file ) {
			if ( $file->isFile() ) {
				$snapshot[ substr( $file->getPathname(), strlen( $root ) ) ] = md5_file( $file->getPathname() );
			}
		}

		ksort( $snapshot );
		return $snapshot;
	}

	/**
	 * A tool from the catalog.
	 *
	 * @param string $name Tool name.
	 * @return \Agentic\Tool_Base
	 */
	private function tool( string $name ): \Agentic\Tool_Base {
		$loader = Tool_Loader::get_instance();
		$loader->load();
		$tool = $loader->get( $name );
		$this->assertNotNull( $tool, $name );
		return $tool;
	}

	// ─── (a) Nothing writes inside the plugin directory ──────────────────────

	/**
	 * The WebMCP expose toggle, enable_webmcp_defaults, configure_approval_gate,
	 * save_integrity_hash() and generate_agent_documentation on a bundled
	 * agent leave every file in the plugin directory unchanged.
	 */
	public function test_bundled_agent_operations_never_write_the_plugin_dir(): void {
		$before   = $this->plugin_dir_snapshot();
		$manifest = Abilities_Manifest::load( self::BUNDLED );
		$this->assertIsArray( $manifest );
		$tool_name = (string) array_key_first( $manifest['abilities'] );

		// Expose toggle (advanced mode) — un-expose is always allowed.
		$request = new \WP_REST_Request( 'POST', '/agentic/v1/admin-page' );
		$request->set_param( 'action_name', 'toggle_webmcp_expose' );
		$request->set_param( 'agent_slug', self::BUNDLED );
		$request->set_param( 'tool_name', $tool_name );
		$request->set_param( 'expose', false );
		$response = Admin_Pages_REST::post_action( $request );
		$this->assertInstanceOf( \WP_REST_Response::class, $response );

		// The effective manifest carries the change …
		$this->assertFalse( Abilities_Manifest::load( self::BUNDLED )['abilities'][ $tool_name ]['webmcp_expose'] );
		// … stored as a database override.
		$overrides = get_option( Abilities_Manifest::OVERRIDES_OPTION );
		$this->assertFalse( $overrides[ self::BUNDLED ]['overrides']['abilities'][ $tool_name ]['webmcp_expose'] );

		$this->tool( 'enable_webmcp_defaults' )->execute( array() );
		$this->tool( 'configure_approval_gate' )->execute( array() );

		$this->assertTrue( Abilities_Manifest::save_integrity_hash( self::BUNDLED ) );
		$this->assertTrue( Abilities_Manifest::verify_integrity( self::BUNDLED ) );
		$this->assertArrayHasKey( self::BUNDLED, get_option( Abilities_Manifest::SIGNATURES_OPTION ) );

		$doc = $this->tool( 'generate_agent_documentation' )->execute( array( 'agent_slug' => self::BUNDLED ) );
		if ( empty( $doc['error'] ) ) {
			$this->assertTrue( $doc['written'] );
			$this->assertStringStartsWith( File_Manager::data_dir() . '/docs/', (string) $doc['file'] );
		}

		$this->assertSame( $before, $this->plugin_dir_snapshot(), 'No file in the plugin directory may be created or changed.' );
	}

	/**
	 * Overrides only change WebMCP fields: a stored risk change is ignored,
	 * and an override for a tool the manifest does not declare adds nothing.
	 */
	public function test_overrides_cannot_change_risk_or_add_tools(): void {
		$path    = Abilities_Manifest::resolve_path( self::BUNDLED );
		$shipped = json_decode( (string) file_get_contents( $path ), true );
		$tool    = (string) array_key_first( $shipped['abilities'] );

		update_option(
			Abilities_Manifest::OVERRIDES_OPTION,
			array(
				self::BUNDLED => array(
					'path'      => md5( wp_normalize_path( $path ) ),
					'overrides' => array(
						'abilities' => array(
							$tool          => array(
								'risk'          => 'none',
								'webmcp_expose' => true,
							),
							'delete_post_x' => array( 'webmcp_expose' => true ),
						),
					),
				),
			)
		);
		Abilities_Manifest::clear_cache( self::BUNDLED );

		$effective = Abilities_Manifest::load( self::BUNDLED );
		$this->assertTrue( $effective['abilities'][ $tool ]['webmcp_expose'] );
		$this->assertSame( $shipped['abilities'][ $tool ]['risk'] ?? null, $effective['abilities'][ $tool ]['risk'] ?? null );
		$this->assertArrayNotHasKey( 'delete_post_x', $effective['abilities'] );
	}

	// ─── (b) User agents are created under the uploads data root ─────────────

	/**
	 * create_agent_files writes the agent into the uploads data root, signed.
	 */
	public function test_create_agent_files_writes_to_uploads_data_root(): void {
		$slug   = 'storage-test-agent';
		$result = $this->tool( 'create_agent_files' )->execute(
			array(
				'slug'          => $slug,
				'name'          => 'Storage Test Agent',
				'description'   => 'Checks where agents are written.',
				'system_prompt' => 'You help.',
				'tools'         => array( array( 'name' => 'get_site_overview' ) ),
			)
		);

		$dir = File_Manager::agents_dir() . '/' . $slug;
		$this->assertTrue( $result['success'] ?? false, wp_json_encode( $result ) );
		$this->assertSame( $dir, $result['directory'] );
		$this->assertStringStartsWith( untrailingslashit( wp_upload_dir( null, false )['basedir'] ) . '/', $dir );
		$this->assertFileExists( $dir . '/agent.json' );
		$this->assertFileExists( $dir . '/abilities.json' );
		$this->assertFileExists( $dir . '/abilities-signature' );
		$this->assertFileExists( File_Manager::agents_dir() . '/.htaccess' );
		$this->assertSame( $dir . '/abilities.json', Abilities_Manifest::resolve_path( $slug ) );
		$this->assertTrue( Abilities_Manifest::verify_integrity( $slug ) );
		$this->assertDirectoryDoesNotExist( File_Manager::legacy_dir( 'agents' ) . '/' . $slug );

		// A WebMCP toggle on a user agent still rewrites its own file and re-signs.
		$manifest = Abilities_Manifest::load( $slug );
		$manifest['abilities']['get_site_overview']['webmcp_expose'] = false;
		$this->assertTrue( Abilities_Manifest::persist( $slug, $manifest ) );
		$this->assertStringContainsString( '"webmcp_expose": false', (string) file_get_contents( $dir . '/abilities.json' ) );
		$this->assertTrue( Abilities_Manifest::verify_integrity( $slug ) );

		\Agentic_Agent_Registry::get_instance()->deactivate_agent( $slug );
		$this->delete_directory( $dir );
	}

	// ─── (c) Migration ───────────────────────────────────────────────────────

	/**
	 * Seed the three legacy folders.
	 *
	 * @return void
	 */
	private function seed_legacy(): void {
		$agents = File_Manager::legacy_dir( 'agents' ) . '/legacy-mover';
		wp_mkdir_p( $agents . '/templates' );
		file_put_contents( File_Manager::legacy_dir( 'agents' ) . '/index.php', "<?php\n// Silence is golden.\n" );
		file_put_contents( $agents . '/agent.json', wp_json_encode( array( 'slug' => 'legacy-mover', 'name' => 'Legacy Mover' ) ) );
		file_put_contents( $agents . '/templates/system-prompt.txt', 'Hi.' );
		$abilities = wp_json_encode( array( 'version' => '1.0', 'abilities' => array( 'get_site_overview' => array( 'risk' => 'none' ) ) ) );
		file_put_contents( $agents . '/abilities.json', $abilities );
		file_put_contents( $agents . '/abilities-signature', hash_hmac( 'sha256', $abilities, Abilities_Manifest::get_integrity_key() ) );

		wp_mkdir_p( File_Manager::legacy_dir( 'knowledge' ) . '/okf/site' );
		file_put_contents( File_Manager::legacy_dir( 'knowledge' ) . '/legacy-mover-knowledge.txt', 'Facts.' );
		file_put_contents( File_Manager::legacy_dir( 'knowledge' ) . '/okf/site/index.md', '# Wiki' );

		wp_mkdir_p( File_Manager::legacy_dir( 'backups' ) . '/db' );
		file_put_contents( File_Manager::legacy_dir( 'backups' ) . '/20260101-000000_0123456789abcdef01234567_robots-txt.php', File_Manager::GUARD_HEADER . '{"original_rel":"robots.txt","created":"2026-01-01 00:00:00"}' . "\nold" );
		file_put_contents( File_Manager::legacy_dir( 'backups' ) . '/db/20260101-000000_0123456789abcdef01234567_options.php', File_Manager::GUARD_HEADER . '{"table":"x","rows":[]}' );
	}

	/**
	 * Legacy data moves into the data root, the old folders are removed, the
	 * moved agent still verifies, and running again changes nothing.
	 */
	public function test_migration_moves_data_and_is_idempotent(): void {
		$this->seed_legacy();

		Storage_Migration::maybe_run( true );

		foreach ( array_keys( File_Manager::LEGACY_DIRS ) as $kind ) {
			$this->assertDirectoryDoesNotExist( File_Manager::legacy_dir( $kind ), $kind );
		}
		$this->assertFileExists( File_Manager::agents_dir() . '/legacy-mover/agent.json' );
		$this->assertFileExists( File_Manager::agents_dir() . '/legacy-mover/templates/system-prompt.txt' );
		$this->assertFileExists( File_Manager::knowledge_dir() . '/legacy-mover-knowledge.txt' );
		$this->assertFileExists( File_Manager::knowledge_dir() . '/okf/site/index.md' );
		$this->assertFileExists( File_Manager::backups_dir() . '/db/20260101-000000_0123456789abcdef01234567_options.php' );
		$this->assertFileExists( File_Manager::agents_dir() . '/.htaccess' );
		$this->assertSame( Storage_Migration::VERSION, (int) get_option( Storage_Migration::MIGRATED_OPTION ) );

		Abilities_Manifest::clear_cache();
		$this->assertSame( File_Manager::agents_dir() . '/legacy-mover/abilities.json', Abilities_Manifest::resolve_path( 'legacy-mover' ) );
		$this->assertTrue( Abilities_Manifest::verify_integrity( 'legacy-mover' ) );
		$this->assertCount( 1, \Agentic\Tool_Helpers::get_backups() );

		// Second run: nothing to do, nothing lost.
		$this->assertNull( Storage_Migration::maybe_run() );
		$second = Storage_Migration::maybe_run( true );
		$this->assertSame( 0, $second['agents']['moved'] + $second['knowledge']['moved'] + $second['backups']['moved'] );
		$this->assertFileExists( File_Manager::agents_dir() . '/legacy-mover/agent.json' );

		$this->delete_directory( File_Manager::agents_dir() . '/legacy-mover' );
		wp_delete_file( File_Manager::knowledge_dir() . '/legacy-mover-knowledge.txt' );
		$this->delete_directory( File_Manager::knowledge_dir() . '/okf' );
		$this->delete_directory( File_Manager::backups_dir() );
	}

	/**
	 * A name already taken at the destination with different content is left
	 * in the old folder, which stays readable as a fallback.
	 */
	public function test_migration_never_overwrites_and_keeps_fallback(): void {
		$this->seed_legacy();
		File_Manager::ensure_data_dir( 'knowledge' );
		file_put_contents( File_Manager::knowledge_dir() . '/legacy-mover-knowledge.txt', 'Newer facts.' );
		file_put_contents( File_Manager::legacy_dir( 'knowledge' ) . '/only-old.txt', 'Old only.' );

		Storage_Migration::run();

		$this->assertSame( 'Newer facts.', file_get_contents( File_Manager::knowledge_dir() . '/legacy-mover-knowledge.txt' ) );
		$this->assertSame( 'Facts.', file_get_contents( File_Manager::legacy_dir( 'knowledge' ) . '/legacy-mover-knowledge.txt' ) );
		$this->assertFileExists( File_Manager::knowledge_dir() . '/only-old.txt' );
		$this->assertNotSame( Storage_Migration::VERSION, (int) get_option( Storage_Migration::MIGRATED_OPTION, 0 ) );
		$this->assertArrayHasKey( 'knowledge', Storage_Migration::remaining_legacy_dirs() );
		$this->assertSame( 'recommended', \Agentic\Site_Health::test_storage_location()['status'] );
		$this->assertContains( File_Manager::legacy_dir( 'knowledge' ), File_Manager::read_dirs( 'knowledge' ) );

		wp_delete_file( File_Manager::knowledge_dir() . '/legacy-mover-knowledge.txt' );
		wp_delete_file( File_Manager::knowledge_dir() . '/only-old.txt' );
		$this->delete_directory( File_Manager::knowledge_dir() . '/okf' );
		$this->delete_directory( File_Manager::agents_dir() . '/legacy-mover' );
		$this->delete_directory( File_Manager::backups_dir() );
	}

	/**
	 * With no legacy folders at all the migration completes immediately.
	 */
	public function test_migration_without_legacy_dirs_completes(): void {
		Storage_Migration::maybe_run( true );
		$this->assertSame( Storage_Migration::VERSION, (int) get_option( Storage_Migration::MIGRATED_OPTION ) );
		$this->assertSame( 'good', \Agentic\Site_Health::test_storage_location()['status'] );
	}

	// ─── (d) No global constants ─────────────────────────────────────────────

	/**
	 * No plugin PHP file defines DISALLOW_FILE_EDIT, and the old toggle tool
	 * only recommends the wp-config.php line.
	 */
	public function test_plugin_never_defines_disallow_file_edit(): void {
		$root  = rtrim( AGENT_BUILDER_DIR, '/' );
		$files = array_merge(
			array( $root . '/agent-builder.php', $root . '/uninstall.php' ),
			(array) glob( $root . '/includes/*.php' ),
			(array) glob( $root . '/includes/*/*.php' ),
			(array) glob( $root . '/library/tools/*/tool.php' ),
			(array) glob( $root . '/admin/*.php' )
		);
		foreach ( $files as $file ) {
			$this->assertDoesNotMatchRegularExpression( '/(?<![\'"])\bdefine\s*\(\s*[\'"]DISALLOW_FILE_EDIT/', (string) file_get_contents( $file ), $file );
		}

		update_option( 'agent_builder_disallow_file_edit', 1 );
		$result = $this->tool( 'toggle_file_editing' )->execute( array( 'allow_editing' => false ) );
		$this->assertFalse( $result['changed'] );
		$this->assertSame( "define( 'DISALLOW_FILE_EDIT', true );", $result['wp_config_line'] );

		// The upgrade routine removes the leftover option.
		\Agentic\Activator::maybe_upgrade();
		$this->assertFalse( get_option( 'agent_builder_disallow_file_edit', false ) );
	}
}
