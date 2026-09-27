<?php
/**
 * Unit Tests for Agent_Templates (duplicate / export / import).
 *
 * @package Agentic\Tests
 */

namespace Agentic\Tests;

use Agentic\Abilities_Manifest;
use Agentic\Agent_Profile;
use Agentic\Agent_Settings;
use Agentic\Agent_Templates;
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
		$dir = AGENT_BUILDER_AGENTS_DIR . '/' . $slug;
		if ( is_dir( $dir ) ) {
			$this->delete_directory( $dir );
		}
		Abilities_Manifest::clear_cache( $slug );
		\Agentic_Agent_Registry::get_instance()->get_installed_agents( true );
	}
}
