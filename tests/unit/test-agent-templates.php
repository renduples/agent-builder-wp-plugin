<?php
/**
 * Unit Tests for Agent_Templates (duplicate / export / import).
 *
 * @package Agentic\Tests
 */

namespace Agentic\Tests;

use Agentic\Abilities_Manifest;
use Agentic\Admin_Pages_REST;
use Agentic\Agent_Library;
use Agentic\Agent_Profile;
use Agentic\Agent_Settings;
use Agentic\Agent_Templates;
use Agentic\Risk_Level;
use Agentic\Skills_Registry;

/**
 * Test case for Agent_Templates.
 */
class Test_Agent_Templates extends TestCase {

	/**
	 * Slugs created by these tests, cleaned up in tearDown().
	 *
	 * @var string[]
	 */
	private array $slugs = array();

	/**
	 * Reset caches before each test.
	 */
	public function setUp(): void {
		parent::setUp();
		Agent_Settings::bust_cache();
		Agent_Profile::bust();
		Skills_Registry::bust_cache();
		$this->slugs = array();
	}

	/**
	 * Remove every agent directory created during the test.
	 */
	public function tearDown(): void {
		foreach ( $this->slugs as $slug ) {
			$this->delete_agent( $slug );
		}
		Agent_Settings::bust_cache();
		Agent_Profile::bust();
		Skills_Registry::bust_cache();
		parent::tearDown();
	}

	/**
	 * duplicate() creates an active, integrity-signed copy with "(copy)"
	 * display name, copied settings and copied skill assignments.
	 */
	public function test_duplicate_creates_active_copy(): void {
		$this->create_full_agent( 'source-agent', 'Source', array( 'list_posts', 'create_post_content' ) );
		$this->track( 'source-agent' );

		Agent_Profile::save( 'source-agent', array( 'profile_title' => 'Writer', 'profile_pinned' => true ) );
		Skills_Registry::create(
			array(
				'name'        => 'source-skill',
				'description' => 'A source skill',
				'content'     => "# Source Skill\n",
				'agent_slug'  => array( 'source-agent' ),
				'enabled'     => true,
			)
		);

		$copy = Agent_Templates::duplicate( 'source-agent' );
		$this->track( $copy );

		$this->assertIsString( $copy );
		$this->assertSame( 'source-agent-copy', $copy );

		// Files exist and integrity holds.
		$copy_dir = AGENT_BUILDER_AGENTS_DIR . '/' . $copy;
		$this->assertFileExists( $copy_dir . '/agent.json' );
		$this->assertFileExists( $copy_dir . '/templates/system-prompt.txt' );
		$this->assertFileExists( $copy_dir . '/abilities.json' );
		$this->assertTrue( Abilities_Manifest::verify_integrity( $copy ) );

		// The copy is active.
		$this->assertContains( $copy, \Agentic_Agent_Registry::get_instance()->get_active_agents() );

		// Display name and copied settings.
		$profile = Agent_Profile::get( $copy );
		$this->assertSame( 'Source (copy)', $profile['display_name'] );
		$this->assertSame( 'Writer', $profile['title'] );
		$this->assertTrue( $profile['pinned'] );

		// A copy of the locally-assigned skill exists for the new slug.
		$skills = Skills_Registry::get_for_agent( $copy );
		$this->assertNotEmpty( $skills, 'Expected the copy to inherit the skill assignment' );
	}

	/**
	 * duplicate() avoids collisions by appending -copy-2, -copy-3, …
	 */
	public function test_duplicate_collides_to_copy_2(): void {
		$this->create_full_agent( 'multi-agent', 'Multi', array( 'list_posts' ) );
		$this->track( 'multi-agent' );

		$first  = Agent_Templates::duplicate( 'multi-agent' );
		$second = Agent_Templates::duplicate( 'multi-agent' );
		$this->track( $first );
		$this->track( $second );

		$this->assertSame( 'multi-agent-copy', $first );
		$this->assertSame( 'multi-agent-copy-2', $second );
	}

	/**
	 * export() writes a zip with the agent payload and never leaks provider
	 * settings/credentials.
	 */
	public function test_export_writes_zip_without_provider_secrets(): void {
		$this->create_full_agent( 'exporter', 'Exporter', array( 'list_posts', 'create_post_content' ) );
		$this->track( 'exporter' );

		Agent_Profile::save( 'exporter', array( 'profile_display_name' => 'Exporter Display' ) );
		Agent_Settings::update( 'exporter', 'provider_api_key', 'should-never-export-me' );
		Agent_Settings::update( 'exporter', 'provider_model', 'gpt-4' );
		Skills_Registry::create(
			array(
				'name'        => 'export-skill',
				'description' => 'Exported skill',
				'content'     => "# Exported\n",
				'agent_slug'  => array( 'exporter' ),
				'enabled'     => true,
			)
		);

		$path = Agent_Templates::export( 'exporter' );

		$this->assertIsString( $path );
		$this->assertFileExists( $path );

		$entries = $this->zip_entries( $path );
		$this->assertContains( 'agent.json', $entries );
		$this->assertContains( 'abilities.json', $entries );
		$this->assertContains( 'system-prompt.txt', $entries );
		$this->assertContains( 'profile.json', $entries );
		$this->assertContains( 'skills/export-skill.SKILL.md', $entries );

		// No provider credentials leak into any entry.
		$all = '';
		foreach ( $entries as $entry ) {
			$all .= $this->zip_read( $path, $entry );
		}
		$this->assertStringNotContainsString( 'should-never-export-me', $all );
		$this->assertStringNotContainsString( 'provider_api_key', $all );
	}

	/**
	 * import() writes an inactive agent, applies profile.json and imports
	 * skills.
	 */
	public function test_import_creates_inactive_agent(): void {
		$zip = $this->build_zip(
			array(
				'agent.json'         => wp_json_encode(
					array(
						'slug'              => 'imported-agent',
						'name'              => 'Imported',
						'description'       => 'An imported agent.',
						'category'          => 'admin',
						'icon'              => '📦',
						'version'           => '1.0.0',
						'capabilities'      => array( 'read' ),
						'tools'             => array( 'list_posts' ),
						'suggested_prompts' => array( 'Hello' ),
						'team'              => false,
					)
				),
				'abilities.json'     => wp_json_encode(
					array(
						'version'   => '1.0',
						'abilities' => array( 'list_posts' => array( 'risk' => 'none' ) ),
					)
				),
				'system-prompt.txt'  => 'You are an imported assistant.',
				'profile.json'       => wp_json_encode( array( 'profile_display_name' => 'Imported Display' ) ),
				'skills/im-skill.SKILL.md' => "# Imported skill\n",
			)
		);

		$slug = Agent_Templates::import( $this->upload_entry( $zip, 'imported-agent.zip' ) );
		$this->track( $slug );

		$this->assertSame( 'imported-agent', $slug );

		// Inactive until activated.
		$this->assertNotContains( $slug, \Agentic_Agent_Registry::get_instance()->get_active_agents() );

		// Files written.
		$dir = AGENT_BUILDER_AGENTS_DIR . '/' . $slug;
		$this->assertFileExists( $dir . '/agent.json' );
		$this->assertFileExists( $dir . '/templates/system-prompt.txt' );
		$this->assertStringContainsString( 'imported assistant', (string) file_get_contents( $dir . '/templates/system-prompt.txt' ) );

		// Profile applied.
		$this->assertSame( 'Imported Display', Agent_Profile::get( $slug )['display_name'] );

		// Skill imported and assigned.
		$this->assertNotEmpty( Skills_Registry::get_for_agent( $slug ) );
	}

	/**
	 * import() rejects an archive that declares a tool below its risk floor.
	 */
	public function test_import_rejects_risk_downgrade(): void {
		$zip = $this->build_zip(
			array(
				'agent.json'     => wp_json_encode(
					array(
						'slug'        => 'risky-agent',
						'name'        => 'Risky',
						'description' => 'A risky agent.',
						'category'    => 'admin',
						'icon'        => '⚠️',
						'version'     => '1.0.0',
						'capabilities' => array( 'read' ),
						'tools'       => array( 'add_custom_css' ),
						'team'        => false,
					)
				),
				'abilities.json' => wp_json_encode(
					array(
						'version'   => '1.0',
						'abilities' => array( 'add_custom_css' => array( 'risk' => 'none' ) ),
					)
				),
			)
		);

		$result = Agent_Templates::import( $this->upload_entry( $zip, 'risky-agent.zip' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'risk_downgrade', $result->get_error_code() );
	}

	/**
	 * import() resolves slug collisions by appending -2.
	 */
	public function test_import_resolves_slug_collision(): void {
		$this->create_full_agent( 'taken-agent', 'Taken', array( 'list_posts' ) );
		$this->track( 'taken-agent' );

		$zip = $this->build_zip(
			array(
				'agent.json' => wp_json_encode(
					array(
						'slug'        => 'taken-agent',
						'name'        => 'Taken',
						'description' => 'A colliding agent.',
						'category'    => 'admin',
						'icon'        => '🗂️',
						'version'     => '1.0.0',
						'capabilities' => array( 'read' ),
						'tools'       => array( 'list_posts' ),
						'team'        => false,
					)
				),
				'abilities.json' => wp_json_encode(
					array(
						'version'   => '1.0',
						'abilities' => array( 'list_posts' => array( 'risk' => 'none' ) ),
					)
				),
			)
		);

		$slug = Agent_Templates::import( $this->upload_entry( $zip, 'taken-agent.zip' ) );
		$this->track( $slug );

		$this->assertSame( 'taken-agent-2', $slug );
	}

	/**
	 * import() rejects a non-zip upload.
	 */
	public function test_import_rejects_non_zip(): void {
		$tmp = $this->temp_file( 'not-a-zip.txt', 'hello world' );

		$result = Agent_Templates::import( $this->upload_entry( $tmp, 'not-a-zip.txt' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'upload_error', $result->get_error_code() );
	}

	/**
	 * export() writes the zip to the system temp directory (wp_tempnam()),
	 * outside the web-servable tree entirely — not just wp_upload_dir(), but
	 * wp-content/ as a whole. This is what makes an exported agent's zip
	 * unreachable by a direct, unauthenticated HTTP request: the file never
	 * lives under a path the webserver serves.
	 */
	public function test_export_writes_outside_public_uploads_tree(): void {
		$this->create_full_agent( 'exporter-security', 'ExporterSecurity', array( 'list_posts' ) );
		$this->track( 'exporter-security' );

		$path = Agent_Templates::export( 'exporter-security' );

		$this->assertIsString( $path );
		$this->assertTrue( str_starts_with( $path, trailingslashit( get_temp_dir() ) ), 'Export must be written to the system temp directory' );
		$this->assertFalse( str_starts_with( $path, trailingslashit( wp_upload_dir()['basedir'] ) ), 'Export path must not be inside the public uploads tree' );
		$this->assertFalse( str_starts_with( $path, trailingslashit( WP_CONTENT_DIR ) ), 'Export path must not be inside wp-content (document root)' );

		wp_delete_file( $path );
	}

	/**
	 * export() must refuse to build an archive under the web root. wp_tempnam()
	 * normally returns a path under WP_TEMP_DIR / sys_get_temp_dir(), but
	 * get_temp_dir() can fall back to a web-servable directory (under
	 * wp-content/) when neither is writable — which would silently defeat the
	 * whole fix. The path-validation guard (is_under_web_root()) treats both
	 * ABSPATH and WP_CONTENT_DIR as unsafe, and only the real system temp dir
	 * as safe; an unresolvable path fails closed.
	 */
	public function test_export_guard_treats_web_root_paths_as_unsafe(): void {
		$method = new \ReflectionMethod( Agent_Templates::class, 'is_under_web_root' );

		// A path directly under the WP root but outside wp-content — still
		// directly web-servable, so it must be rejected.
		$in_root = ABSPATH . 'agentic-export-guard-probe.tmp';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture write.
		$this->assertNotFalse( file_put_contents( $in_root, 'x' ) );
		$this->assertTrue( $method->invoke( null, $in_root ), 'A path under ABSPATH must be treated as unsafe' );

		// A path under wp-content — the exact fallback location get_temp_dir()
		// can reach when its preferred temp dirs are not writable.
		$in_content = WP_CONTENT_DIR . '/agentic-export-guard-probe.tmp';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture write.
		$this->assertNotFalse( file_put_contents( $in_content, 'x' ) );
		$this->assertTrue( $method->invoke( null, $in_content ), 'A path under WP_CONTENT_DIR must be treated as unsafe' );

		// The real system temp dir is outside the web root — the one place an
		// exported archive may safely live.
		$in_temp = trailingslashit( get_temp_dir() ) . 'agentic-export-guard-probe.tmp';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture write.
		$this->assertNotFalse( file_put_contents( $in_temp, 'x' ) );
		$this->assertFalse( $method->invoke( null, $in_temp ), 'A path under the system temp dir must be treated as safe' );

		// An unresolvable path fails closed (treated as unsafe) rather than
		// assuming a path that cannot be checked is fine.
		$this->assertTrue(
			$method->invoke( null, trailingslashit( get_temp_dir() ) . 'agentic-export-guard-missing-' . wp_generate_password( 8, false ) . '.tmp' ),
			'An unresolvable path must fail closed'
		);

		wp_delete_file( $in_root );
		wp_delete_file( $in_content );
		wp_delete_file( $in_temp );
	}

	/**
	 * export() of a DB-backed manifest agent (no on-disk abilities.json)
	 * synthesizes a valid abilities.json from the agent's declared tools at
	 * their risk floors, so the export round-trips through import() instead of
	 * being rejected for a missing abilities manifest.
	 */
	public function test_export_db_backed_agent_round_trips(): void {
		Agent_Library::upsert(
			array(
				'slug'     => 'db-export-agent',
				'name'     => 'DB Export Agent',
				'manifest' => array(
					'slug'         => 'db-export-agent',
					'name'         => 'DB Export Agent',
					'description'  => 'A DB-backed agent with no directory.',
					'category'     => 'admin',
					'icon'         => '📚',
					'version'      => '1.0.0',
					'capabilities' => array( 'read' ),
					'tools'        => array( 'list_posts' ),
					'team'         => false,
				),
				'kind'     => 'manifest',
				'source'   => 'user',
				'enabled'  => true,
			)
		);
		\Agentic_Agent_Registry::get_instance()->get_installed_agents( true );

		$path = Agent_Templates::export( 'db-export-agent' );

		$this->assertIsString( $path );
		$this->assertFileExists( $path );

		$entries = $this->zip_entries( $path );
		$this->assertContains( 'abilities.json', $entries, 'Expected a synthesized abilities.json for a DB-backed agent' );

		$abilities = json_decode( $this->zip_read( $path, 'abilities.json' ), true );
		$this->assertIsArray( $abilities );
		$this->assertArrayHasKey( 'list_posts', $abilities['abilities'] );
		$this->assertSame( Risk_Level::get_tool_default( 'list_posts' ), $abilities['abilities']['list_posts']['risk'] );

		// Round-trip: the synthesized manifest must import as a valid agent
		// (slug resolves to -2 because the DB row still owns 'db-export-agent').
		$slug = Agent_Templates::import( $this->upload_entry( $path, 'db-export-agent.zip' ) );
		$this->track( $slug );

		$this->assertSame( 'db-export-agent-2', $slug );
	}

	/**
	 * export() writes to a wp_tempnam() file — a fresh temp path with a random
	 * suffix, never a stable slug-derived name — so even within the temp
	 * directory the exact filename cannot be constructed from the slug alone.
	 */
	public function test_export_uses_non_guessable_filename(): void {
		$this->create_full_agent( 'exporter-filename', 'ExporterFilename', array( 'list_posts' ) );
		$this->track( 'exporter-filename' );

		$path = Agent_Templates::export( 'exporter-filename' );

		$this->assertIsString( $path );
		$this->assertNotSame( 'exporter-filename.zip', basename( $path ) );
		$this->assertStringEndsWith( '.tmp', basename( $path ) );

		wp_delete_file( $path );
	}

	/**
	 * The export download is served by an authenticated admin-post handler
	 * (capability + nonce + streamed from a temp file), not an AJAX/JSON
	 * endpoint that would leave a file at a requestable path.
	 */
	public function test_export_download_is_an_admin_post_handler(): void {
		$this->assertNotFalse(
			has_action( 'admin_post_agentic_export_agent', array( Admin_Pages_REST::class, 'export_agent_download' ) ),
			'Expected the export download to be registered as an admin-post handler'
		);
	}

	/**
	 * import() rejects an archive with more entries than allowed, before any
	 * extraction happens — no import temp directory is left behind.
	 */
	public function test_import_rejects_archive_with_too_many_entries(): void {
		$files = array();
		for ( $i = 0; $i < 150; $i++ ) {
			$files[ 'junk-' . $i . '.txt' ] = 'x';
		}
		$zip = $this->build_zip( $files );

		$before = $this->import_temp_dirs();

		$result = Agent_Templates::import( $this->upload_entry( $zip, 'flood.zip' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'zip_too_many_entries', $result->get_error_code() );
		$this->assertSame( $before, $this->import_temp_dirs(), 'Expected no leftover import temp directory' );
	}

	/**
	 * import() rejects an archive whose declared uncompressed size exceeds
	 * the cap, before any extraction happens — no import temp directory is
	 * left behind.
	 */
	public function test_import_rejects_oversized_archive(): void {
		$zip = $this->build_zip(
			array(
				'agent.json'        => wp_json_encode(
					array(
						'slug'         => 'big-agent',
						'name'         => 'Big',
						'description' => 'An oversized archive.',
						'category'     => 'admin',
						'icon'         => '🤖',
						'version'      => '1.0.0',
						'capabilities' => array( 'read' ),
						'tools'        => array(),
						'team'         => false,
					)
				),
				'system-prompt.txt' => str_repeat( 'a', 6 * 1024 * 1024 ),
			)
		);

		$before = $this->import_temp_dirs();

		$result = Agent_Templates::import( $this->upload_entry( $zip, 'oversized.zip' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'zip_too_large', $result->get_error_code() );
		$this->assertSame( $before, $this->import_temp_dirs(), 'Expected no leftover import temp directory' );
	}

	/**
	 * import() rejects a zip whose central-directory metadata *declares* a small
	 * uncompressed size but whose DEFLATE stream actually expands beyond the cap
	 * — the forged-metadata zip-bomb bypass. The size bound must be enforced
	 * against the real decompressed bytes streamed out of the inflater, not the
	 * attacker-controlled per-entry size fields.
	 */
	public function test_import_rejects_zip_bomb_with_forged_declared_size(): void {
		$zip    = $this->build_zip( array( 'system-prompt.txt' => str_repeat( 'a', 6 * 1024 * 1024 ) ) );
		$forged = $this->forge_declared_sizes( $zip );

		$before = $this->import_temp_dirs();

		$result = Agent_Templates::import( $this->upload_entry( $forged, 'bomb.zip' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'zip_too_large', $result->get_error_code() );
		$this->assertSame( $before, $this->import_temp_dirs(), 'Expected no leftover import temp directory' );
	}

	/**
	 * A crafted archive with two entries sharing one name — the first small,
	 * the second expanding past the cap — is rejected. check_archive_bounds()
	 * must measure each entry by *index* (getStreamIndex()), not by name
	 * (getStream() returns only the first matching entry), or the large
	 * duplicate is silently skipped and the archive slips past the size bound.
	 */
	public function test_import_rejects_duplicate_name_zip_bomb(): void {
		$zip = $this->build_zip_with_duplicate_names(
			'system-prompt.txt',
			'small',
			str_repeat( 'a', 6 * 1024 * 1024 )
		);

		$before = $this->import_temp_dirs();

		$result = Agent_Templates::import( $this->upload_entry( $zip, 'duplicate.zip' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'zip_too_large', $result->get_error_code() );
		$this->assertSame( $before, $this->import_temp_dirs(), 'Expected no leftover import temp directory' );
	}

	/**
	 * slug_taken() (via unique_slug()) also sees DB-backed agents from
	 * agent_builder_agent_library, not just directory-based ones — an
	 * import colliding with a library-only slug gets the -2 suffix instead
	 * of silently shadowing the existing DB row.
	 */
	public function test_import_resolves_db_backed_slug_collision(): void {
		Agent_Library::upsert(
			array(
				'slug'     => 'lib-agent',
				'name'     => 'Library Agent',
				'manifest' => array(
					'slug'         => 'lib-agent',
					'name'         => 'Library Agent',
					'description'  => 'A DB-backed agent with no directory.',
					'category'     => 'admin',
					'icon'         => '📚',
					'version'      => '1.0.0',
					'capabilities' => array( 'read' ),
					'tools'        => array(),
					'team'         => false,
				),
				'kind'     => 'manifest',
				'source'   => 'user',
				'enabled'  => true,
			)
		);
		\Agentic_Agent_Registry::get_instance()->get_installed_agents( true );

		$zip = $this->build_zip(
			array(
				'agent.json'     => wp_json_encode(
					array(
						'slug'         => 'lib-agent',
						'name'         => 'Colliding',
						'description' => 'A colliding import.',
						'category'     => 'admin',
						'icon'         => '🗂️',
						'version'      => '1.0.0',
						'capabilities' => array( 'read' ),
						'tools'        => array( 'list_posts' ),
						'team'         => false,
					)
				),
				'abilities.json' => wp_json_encode(
					array(
						'version'   => '1.0',
						'abilities' => array( 'list_posts' => array( 'risk' => 'none' ) ),
					)
				),
			)
		);

		$slug = Agent_Templates::import( $this->upload_entry( $zip, 'lib-agent.zip' ) );
		$this->track( $slug );

		$this->assertSame( 'lib-agent-2', $slug );
	}

	/**
	 * A write_agent() failure partway through (here: the abilities.json
	 * write step) removes the just-created agent directory instead of
	 * leaving a malformed one behind that consumes the slug on retry.
	 *
	 * The failure is forced with a NAN risk value: wp_json_encode() inside
	 * Abilities_Manifest::write_manifest() returns false for it (json_encode()
	 * rejects NAN/INF outright, and WP's invalid-data fallback only rewrites
	 * strings, so the retry fails identically) — so the abilities-write step
	 * fails cleanly after write_agent() has already created the directory
	 * itself and written agent.json and the system prompt into it,
	 * deliberately not via a pre-existing directory at $agent_dir, which
	 * test_write_agent_does_not_touch_preexisting_directory() covers as its
	 * own, opposite case.
	 */
	public function test_write_agent_removes_partial_directory_on_abilities_failure(): void {
		$slug = 'partial-fail-agent';
		$this->track( $slug );

		$agent_dir = AGENT_BUILDER_AGENTS_DIR . '/' . $slug;

		$manifest = array(
			'slug'         => $slug,
			'name'         => 'Partial Fail',
			'description'  => 'A test agent.',
			'category'     => 'admin',
			'icon'         => '🤖',
			'version'      => '1.0.0',
			'capabilities' => array( 'read' ),
			'tools'        => array( 'list_posts' ),
			'team'         => false,
		);
		$abilities = array(
			'version'   => '1.0',
			'abilities' => array( 'list_posts' => array( 'risk' => NAN ) ),
		);

		$method = new \ReflectionMethod( Agent_Templates::class, 'write_agent' );
		$result = $method->invoke( null, $slug, $manifest, 'You are partial.', $abilities );

		$this->assertWPError( $result );
		$this->assertFalse( is_dir( $agent_dir ), 'Expected the partially-written agent directory to be removed on failure' );
	}

	/**
	 * write_agent() must not touch a directory that already exists for its
	 * slug — e.g. because a concurrent write raced ahead of it — instead of
	 * proceeding to write into (and potentially delete on a later failure)
	 * a directory it did not create. This is the concurrency bug the atomic
	 * mkdir() in write_agent() closes.
	 */
	public function test_write_agent_does_not_touch_preexisting_directory(): void {
		$slug = 'already-claimed-agent';
		$this->track( $slug );

		$agent_dir = AGENT_BUILDER_AGENTS_DIR . '/' . $slug;
		wp_mkdir_p( $agent_dir );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture standing in for another writer's already-committed content.
		file_put_contents( $agent_dir . '/agent.json', 'sentinel-from-another-write' );

		$manifest = array(
			'slug'         => $slug,
			'name'         => 'Already Claimed',
			'description'  => 'A test agent.',
			'category'     => 'admin',
			'icon'         => '🤖',
			'version'      => '1.0.0',
			'capabilities' => array( 'read' ),
			'tools'        => array( 'list_posts' ),
			'team'         => false,
		);
		$abilities = array(
			'version'   => '1.0',
			'abilities' => array( 'list_posts' => array( 'risk' => 'none' ) ),
		);

		$method = new \ReflectionMethod( Agent_Templates::class, 'write_agent' );
		$result = $method->invoke( null, $slug, $manifest, 'You are a duplicate write.', $abilities );

		$this->assertWPError( $result );
		$this->assertSame( 'slug_taken', $result->get_error_code() );
		$this->assertTrue( is_dir( $agent_dir ), 'Expected the pre-existing directory to be left alone' );
		$this->assertSame(
			'sentinel-from-another-write',
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test assertion reading a fixture file back.
			file_get_contents( $agent_dir . '/agent.json' ),
			"Expected the other writer's content to survive untouched"
		);
	}

	/**
	 * write_agent() reports a plain mkdir() failure as mkdir_failed, not as
	 * slug_taken: only a directory that already exists is a collision, while a
	 * failure for any other reason (permissions, a missing parent, …) must
	 * surface its own code so the real error is not masked by a false
	 * "already taken" report. The failure is forced with a read-only agents
	 * directory, so @mkdir() fails without $agent_dir ever existing.
	 */
	public function test_write_agent_reports_mkdir_failure_not_slug_taken(): void {
		$this->skip_when_root();

		$slug = 'mkdir-fail-agent';
		$this->track( $slug );

		$agent_dir  = AGENT_BUILDER_AGENTS_DIR . '/' . $slug;
		$agents_dir = AGENT_BUILDER_AGENTS_DIR;
		wp_mkdir_p( $agents_dir );

		$manifest = array(
			'slug'         => $slug,
			'name'         => 'Mkdir Fail',
			'description'  => 'A test agent.',
			'category'     => 'admin',
			'icon'         => '🤖',
			'version'      => '1.0.0',
			'capabilities' => array( 'read' ),
			'tools'        => array( 'list_posts' ),
			'team'         => false,
		);
		$abilities = array(
			'version'   => '1.0',
			'abilities' => array( 'list_posts' => array( 'risk' => 'none' ) ),
		);

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Simulate an unwritable parent so @mkdir() fails.
		chmod( $agents_dir, 0500 );

		try {
			$method = new \ReflectionMethod( Agent_Templates::class, 'write_agent' );
			$result = $method->invoke( null, $slug, $manifest, 'You are a failed write.', $abilities );
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Restore writability for subsequent tests.
			chmod( $agents_dir, 0755 );
		}

		$this->assertWPError( $result );
		$this->assertSame( 'mkdir_failed', $result->get_error_code() );
		$this->assertFalse( is_dir( $agent_dir ) );
	}

	/**
	 * import() refreshes the registry cache after a successful write, so a
	 * caller that immediately tries to activate the returned slug in the
	 * same request sees it — not a cache populated by unique_slug()'s
	 * pre-write collision check.
	 */
	public function test_import_then_activate_sees_fresh_registry_cache(): void {
		$zip = $this->build_zip(
			array(
				'agent.json'     => wp_json_encode(
					array(
						'slug'         => 'fresh-cache-agent',
						'name'         => 'Fresh Cache',
						'description' => 'An imported agent.',
						'category'     => 'admin',
						'icon'         => '🆕',
						'version'      => '1.0.0',
						'capabilities' => array( 'read' ),
						'tools'        => array( 'list_posts' ),
						'team'         => false,
					)
				),
				'abilities.json' => wp_json_encode(
					array(
						'version'   => '1.0',
						'abilities' => array( 'list_posts' => array( 'risk' => 'none' ) ),
					)
				),
			)
		);

		$slug = Agent_Templates::import( $this->upload_entry( $zip, 'fresh-cache-agent.zip' ) );
		$this->track( $slug );

		$this->assertSame( 'fresh-cache-agent', $slug );

		$activated = \Agentic_Agent_Registry::get_instance()->activate_agent( $slug );

		$this->assertNotWPError( $activated, 'Expected activation to see the freshly-imported agent, not a stale registry cache' );
	}

	/**
	 * import() rejects an archive with no abilities.json instead of silently
	 * returning a slug that Agentic_Agent_Registry::activate_agent() will
	 * later refuse to activate for lacking an abilities manifest.
	 */
	public function test_import_rejects_archive_missing_abilities(): void {
		$zip = $this->build_zip(
			array(
				'agent.json'        => wp_json_encode(
					array(
						'slug'         => 'no-abilities-agent',
						'name'         => 'No Abilities',
						'description' => 'Missing abilities.json.',
						'category'     => 'admin',
						'icon'         => '🚫',
						'version'      => '1.0.0',
						'capabilities' => array( 'read' ),
						'tools'        => array(),
						'team'         => false,
					)
				),
				'system-prompt.txt' => 'You lack abilities.',
			)
		);

		$result = Agent_Templates::import( $this->upload_entry( $zip, 'no-abilities.zip' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'missing_abilities', $result->get_error_code() );
	}

	/**
	 * import() rejects an abilities entry with no risk field instead of
	 * silently skipping it — same as an explicitly-invalid risk value.
	 */
	public function test_import_rejects_ability_entry_missing_risk(): void {
		$zip = $this->build_zip(
			array(
				'agent.json'     => wp_json_encode(
					array(
						'slug'         => 'no-risk-agent',
						'name'         => 'No Risk',
						'description' => 'Ability entry missing risk.',
						'category'     => 'admin',
						'icon'         => '❓',
						'version'      => '1.0.0',
						'capabilities' => array( 'read' ),
						'tools'        => array( 'list_posts' ),
						'team'         => false,
					)
				),
				'abilities.json' => wp_json_encode(
					array(
						'version'   => '1.0',
						'abilities' => array( 'list_posts' => array( 'reason' => 'no risk key here' ) ),
					)
				),
			)
		);

		$result = Agent_Templates::import( $this->upload_entry( $zip, 'no-risk.zip' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'risk_downgrade', $result->get_error_code() );
	}

	/**
	 * import() rejects an archive whose abilities.json does not declare a tool
	 * that agent.json lists — importing it would hand back a slug that can never
	 * be activated (activate_agent() cross-checks the same set).
	 */
	public function test_import_rejects_undeclared_tool(): void {
		$zip = $this->build_zip(
			array(
				'agent.json'     => wp_json_encode(
					array(
						'slug'         => 'undeclared-tool-agent',
						'name'         => 'Undeclared',
						'description' => 'Declares a tool its abilities omit.',
						'category'     => 'admin',
						'icon'         => '🕳️',
						'version'      => '1.0.0',
						'capabilities' => array( 'read' ),
						'tools'        => array( 'list_posts', 'create_post_content' ),
						'team'         => false,
					)
				),
				'abilities.json' => wp_json_encode(
					array(
						'version'   => '1.0',
						'abilities' => array( 'list_posts' => array( 'risk' => 'none' ) ),
					)
				),
			)
		);

		$result = Agent_Templates::import( $this->upload_entry( $zip, 'undeclared.zip' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'risk_downgrade', $result->get_error_code() );
	}

	/**
	 * duplicate() removes the copy's directory, settings and skill assignments
	 * when activation fails after write_agent() — no debris is left behind.
	 */
	public function test_duplicate_cleans_up_on_activation_failure(): void {
		// Source declares a tool its abilities.json omits, so write_agent()
		// succeeds but activate_agent() fails the manifest cross-check.
		$source_dir = AGENT_BUILDER_AGENTS_DIR . '/broken-source';
		wp_mkdir_p( $source_dir . '/templates' );

		$manifest = array(
			'slug'         => 'broken-source',
			'name'         => 'Broken',
			'description'  => 'A source that cannot be activated.',
			'category'     => 'admin',
			'icon'         => '⚠️',
			'version'      => '1.0.0',
			'capabilities' => array( 'read' ),
			'tools'        => array( 'list_posts', 'create_post_content' ),
			'suggested_prompts' => array(),
			'team'         => false,
		);
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture write.
		file_put_contents( $source_dir . '/agent.json', wp_json_encode( $manifest, JSON_PRETTY_PRINT ) );
		// abilities.json omits create_post_content.
		Abilities_Manifest::write_manifest(
			$source_dir,
			'broken-source',
			array(
				'version'   => '1.0',
				'abilities' => array( 'list_posts' => array( 'risk' => 'none' ) ),
			)
		);
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture write.
		file_put_contents( $source_dir . '/templates/system-prompt.txt', "You are Broken.\n" );
		\Agentic_Agent_Registry::get_instance()->get_installed_agents( true );

		$this->track( 'broken-source' );

		Agent_Settings::update( 'broken-source', 'some_key', 'some_value' );
		Skills_Registry::create(
			array(
				'name'        => 'broken-skill',
				'description' => 'A skill to copy',
				'content'     => "# Broken skill\n",
				'agent_slug'  => array( 'broken-source' ),
				'enabled'     => true,
			)
		);

		$result = Agent_Templates::duplicate( 'broken-source' );

		$this->assertWPError( $result );

		$copy = 'broken-source-copy';
		$this->assertFalse(
			is_dir( AGENT_BUILDER_AGENTS_DIR . '/' . $copy ),
			'Expected the failed duplicate directory to be removed'
		);
		$this->assertNotContains(
			$copy,
			\Agentic_Agent_Registry::get_instance()->get_active_agents(),
			'Expected the failed duplicate to not be active'
		);
		$this->assertSame(
			array(),
			Agent_Settings::get_all( $copy ),
			'Expected the failed duplicate settings to be removed'
		);

		// The copied skill assignment must not survive: the only skills under
		// the copy slug are the source's own (none reference the copy).
		$this->assertSame( array(), Skills_Registry::get_for_agent( $copy ) );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Create a full manifest agent: agent.json, abilities.json (+ signature)
	 * and templates/system-prompt.txt.
	 *
	 * @param string   $slug  Agent slug.
	 * @param string   $name  Agent name.
	 * @param string[] $tools Tool names to declare.
	 * @return string Agent directory.
	 */
	private function create_full_agent( string $slug, string $name, array $tools ): string {
		$agent_dir = AGENT_BUILDER_AGENTS_DIR . '/' . $slug;
		wp_mkdir_p( $agent_dir . '/templates' );

		$manifest = array(
			'slug'              => $slug,
			'name'              => $name,
			'description'       => 'A test agent.',
			'category'          => 'admin',
			'icon'              => '🤖',
			'version'           => '1.0.0',
			'capabilities'      => array( 'read' ),
			'tools'             => $tools,
			'suggested_prompts' => array(),
			'team'              => false,
		);

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture write.
		file_put_contents( $agent_dir . '/agent.json', wp_json_encode( $manifest, JSON_PRETTY_PRINT ) );

		$abilities = array( 'version' => '1.0', 'abilities' => array() );
		foreach ( $tools as $tool ) {
			$abilities['abilities'][ $tool ] = array( 'risk' => 'none' );
		}
		Abilities_Manifest::write_manifest( $agent_dir, $slug, $abilities );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture write.
		file_put_contents( $agent_dir . '/templates/system-prompt.txt', "You are {$name}.\n" );

		\Agentic_Agent_Registry::get_instance()->get_installed_agents( true );

		return $agent_dir;
	}

	/**
	 * Build a zip at a temporary path from an entry=>content map.
	 *
	 * @param array<string, string> $files Entry path => content.
	 * @return string Path to the zip.
	 */
	private function build_zip( array $files ): string {
		$path = $this->temp_path() . '.zip';
		$zip  = new \ZipArchive();
		$this->assertTrue( true === $zip->open( $path, \ZipArchive::CREATE ) );
		foreach ( $files as $entry => $content ) {
			$zip->addFromString( $entry, $content );
		}
		$zip->close();
		return $path;
	}

	/**
	 * Build a zip whose uncompressed-size fields are forged to zero while the
	 * actual compressed DEFLATE data (and its inflated output) is left intact.
	 * The result still opens and inflates to the original content — it just
	 * lies about how large that content is, which is exactly the forged
	 * central-directory metadata a zip-bomb uses to slip past a size check that
	 * trusts statIndex()['size'].
	 *
	 * @param string $zip_path Path to a single-entry zip.
	 * @return string Path to the forged zip.
	 */
	private function forge_declared_sizes( string $zip_path ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test fixture read.
		$raw = (string) file_get_contents( $zip_path );
		// Local file header (PK\x03\x04): uncompressed size at +22.
		$raw = $this->zero_zip_uncompressed_size( $raw, "PK\x03\x04", 22 );
		// Central directory header (PK\x01\x02): uncompressed size at +24.
		$raw = $this->zero_zip_uncompressed_size( $raw, "PK\x01\x02", 24 );

		$forged = $this->temp_path() . '-forged.zip';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture write.
		file_put_contents( $forged, $raw );
		return $forged;
	}

	/**
	 * Zero the four-byte uncompressed-size field at $offset bytes past the
	 * first occurrence of $signature in a raw zip blob.
	 *
	 * @param string $raw       Raw zip bytes.
	 * @param string $signature Four-byte header signature.
	 * @param int    $offset    Byte offset of the uncompressed-size field.
	 * @return string
	 */
	private function zero_zip_uncompressed_size( string $raw, string $signature, int $offset ): string {
		$pos = strpos( $raw, $signature );
		if ( false === $pos ) {
			return $raw;
		}
		for ( $i = 0; $i < 4; $i++ ) {
			$raw[ $pos + $offset + $i ] = "\x00";
		}
		return $raw;
	}

	/**
	 * Build a zip with two STORED entries sharing one name (the first small,
	 * the second large). ZipArchive::addFromString() replaces an existing
	 * entry by name instead of appending a duplicate, so this shape — the
	 * exact getStream() first-match bypass — must be assembled byte-for-byte.
	 *
	 * @param string $name  Shared entry name.
	 * @param string $small Small payload for the first entry.
	 * @param string $large Large payload for the second entry.
	 * @return string Path to the zip.
	 */
	private function build_zip_with_duplicate_names( string $name, string $small, string $large ): string {
		$path = $this->temp_path() . '.zip';

		$crc_small = crc32( $small );
		$len_small = strlen( $small );
		$crc_large = crc32( $large );
		$len_large = strlen( $large );

		$local_small = "PK\x03\x04" . pack( 'vvvvvVVVvv', 20, 0, 0, 0, 0, $crc_small, $len_small, $len_small, strlen( $name ), 0 ) . $name . $small;
		$local_large = "PK\x03\x04" . pack( 'vvvvvVVVvv', 20, 0, 0, 0, 0, $crc_large, $len_large, $len_large, strlen( $name ), 0 ) . $name . $large;

		$offset_small = 0;
		$offset_large = strlen( $local_small );

		$cd_small = "PK\x01\x02" . pack( 'vvvvvvVVVvvvvvVV', 20, 20, 0, 0, 0, 0, $crc_small, $len_small, $len_small, strlen( $name ), 0, 0, 0, 0, 0, $offset_small ) . $name;
		$cd_large = "PK\x01\x02" . pack( 'vvvvvvVVVvvvvvVV', 20, 20, 0, 0, 0, 0, $crc_large, $len_large, $len_large, strlen( $name ), 0, 0, 0, 0, 0, $offset_large ) . $name;

		$cd_size = strlen( $cd_small ) + strlen( $cd_large );
		$cd_off  = strlen( $local_small ) + strlen( $local_large );
		$eocd    = "PK\x05\x06" . pack( 'vvvvVVv', 0, 0, 2, 2, $cd_size, $cd_off, 0 );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture write.
		file_put_contents( $path, $local_small . $local_large . $cd_small . $cd_large . $eocd );
		return $path;
	}

	/**
	 * Wrap a filesystem path in the $_FILES-shaped array import() expects.
	 *
	 * @param string $path Path to the uploaded file.
	 * @param string $name Original filename.
	 * @return array<string, mixed>
	 */
	private function upload_entry( string $path, string $name ): array {
		return array(
			'name'     => $name,
			'type'     => 'application/zip',
			'tmp_name' => $path,
			'error'    => 0,
			'size'     => filesize( $path ),
		);
	}

	/**
	 * List the entries inside a zip archive.
	 *
	 * @param string $path Zip path.
	 * @return string[]
	 */
	private function zip_entries( string $path ): array {
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return array();
		}
		$names = array();
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$names[] = (string) $zip->getNameIndex( $i );
		}
		$zip->close();
		return $names;
	}

	/**
	 * Read a single entry from a zip archive.
	 *
	 * @param string $path  Zip path.
	 * @param string $entry Entry name.
	 * @return string
	 */
	private function zip_read( string $path, string $entry ): string {
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return '';
		}
		$content = (string) $zip->getFromName( $entry );
		$zip->close();
		return $content;
	}

	/**
	 * List currently-existing import temp directories, to confirm a rejected
	 * archive left none behind.
	 *
	 * @return string[]
	 */
	private function import_temp_dirs(): array {
		$dirs = glob( trailingslashit( get_temp_dir() ) . 'agentic-import-*', GLOB_ONLYDIR );
		return $dirs ? $dirs : array();
	}

	/**
	 * A unique temporary path that does not yet exist.
	 *
	 * @return string
	 */
	private function temp_path(): string {
		return trailingslashit( get_temp_dir() ) . 'agentic-test-' . wp_generate_password( 12, false );
	}

	/**
	 * Create a small temporary file.
	 *
	 * @param string $name    Filename.
	 * @param string $content Content.
	 * @return string Path to the file.
	 */
	private function temp_file( string $name, string $content ): string {
		$path = trailingslashit( get_temp_dir() ) . $name;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture write.
		file_put_contents( $path, $content );
		return $path;
	}

	/**
	 * Record a slug so tearDown() removes it.
	 *
	 * @param mixed $slug Agent slug.
	 */
	private function track( $slug ): void {
		if ( is_string( $slug ) && '' !== $slug ) {
			$this->slugs[] = $slug;
		}
	}

	/**
	 * Delete an agent directory and refresh the registry.
	 *
	 * @param string $slug Agent slug.
	 */
	private function delete_agent( string $slug ): void {
		$registry = \Agentic_Agent_Registry::get_instance();
		// Deactivate first, while the agent still exists on disk: leaving a
		// ghost entry in the agent_builder_active_agents option makes a
		// later test that reuses the slug see "already_active" wrongly.
		if ( $registry->is_agent_active( $slug ) ) {
			$registry->deactivate_agent( $slug );
		}

		$dir = AGENT_BUILDER_AGENTS_DIR . '/' . $slug;
		if ( is_dir( $dir ) ) {
			$this->delete_directory( $dir );
		}
		Abilities_Manifest::clear_cache( $slug );
		$registry->get_installed_agents( true );
	}
}
