<?php
/**
 * Unit Tests for Agent_Profile.
 *
 * Agent_Profile merges an agent's manifest identity (name/icon/description)
 * with per-agent overrides stored in Agent_Settings under the profile_* keys
 * plus persona_notes. These tests exercise get()/save()/order(), the
 * identity_line() prompt fragment, and export_fields().
 *
 * @package Agentic\Tests
 */

namespace Agentic\Tests;

use Agentic\Agent_Profile;
use Agentic\Agent_Settings;

/**
 * Test case for Agent_Profile.
 */
class Test_Agent_Profile extends TestCase {

	/**
	 * Agent slug used throughout.
	 *
	 * @var string
	 */
	private string $slug = 'profile-test-agent';

	/**
	 * Reset static caches and remove any leftover agent before each test.
	 */
	public function setUp(): void {
		parent::setUp();
		Agent_Settings::bust_cache();
		Agent_Profile::bust();
		$this->delete_agent( $this->slug );
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		$this->delete_agent( $this->slug );
		Agent_Settings::bust_cache();
		Agent_Profile::bust();
		parent::tearDown();
	}

	/**
	 * With no overrides saved, get() falls back to the manifest identity and
	 * reports neutral flags.
	 */
	public function test_get_returns_manifest_defaults_when_no_profile_set(): void {
		$this->create_agent( $this->slug, 'Content Writer', 'Writes posts.', '✍️' );

		$profile = Agent_Profile::get( $this->slug );

		$this->assertSame( $this->slug, $profile['slug'] );
		$this->assertSame( 'Content Writer', $profile['display_name'] );
		$this->assertSame( '✍️', $profile['icon'] );
		$this->assertSame( 'Writes posts.', $profile['description'] );
		$this->assertSame( '', $profile['title'] );
		$this->assertSame( '', $profile['standing_description'] );
		$this->assertSame( 0, $profile['avatar_id'] );
		$this->assertSame( '', $profile['avatar_url'] );
		$this->assertFalse( $profile['pinned'] );
		$this->assertFalse( $profile['hidden'] );
		$this->assertSame( 0, $profile['order'] );
	}

	/**
	 * save() writes the known keys and get() reflects them, with the display
	 * name override winning over the manifest name.
	 */
	public function test_save_and_get_roundtrip(): void {
		$this->create_agent( $this->slug, 'Content Writer', 'Writes posts.', '✍️' );

		Agent_Profile::save(
			$this->slug,
			array(
				'profile_display_name' => 'Editor',
				'profile_title'        => 'Blog editor',
				'persona_notes'        => 'Always fact-check names.',
				'profile_pinned'       => true,
				'profile_hidden'       => '1',
				'profile_order'        => 4,
				'profile_avatar_emoji' => '🦉',
			)
		);

		$profile = Agent_Profile::get( $this->slug );

		$this->assertSame( 'Editor', $profile['display_name'] );
		$this->assertSame( 'Blog editor', $profile['title'] );
		$this->assertSame( 'Always fact-check names.', $profile['standing_description'] );
		$this->assertTrue( $profile['pinned'] );
		$this->assertTrue( $profile['hidden'] );
		$this->assertSame( 4, $profile['order'] );
		$this->assertSame( '🦉', $profile['avatar_emoji'] );
	}

	/**
	 * save() ignores unknown keys and sanitises booleans/ints; a non-image
	 * attachment id is cleared to 0.
	 */
	public function test_save_ignores_unknown_keys_and_sanitises(): void {
		Agent_Profile::save(
			$this->slug,
			array(
				'profile_pinned'    => 'yes',
				'profile_hidden'    => 'off',
				'profile_order'     => '7abc',
				'profile_avatar_id' => 999999,
				'not_a_profile_key' => 'nope',
			)
		);

		$all = Agent_Settings::get_all( $this->slug );
		$this->assertArrayNotHasKey( 'not_a_profile_key', $all );

		$profile = Agent_Profile::get( $this->slug );
		$this->assertTrue( $profile['pinned'] );
		$this->assertFalse( $profile['hidden'] );
		$this->assertSame( 7, $profile['order'] );
		$this->assertSame( 0, $profile['avatar_id'] );
	}

	/**
	 * save() keeps an attachment id that points at a real image attachment.
	 */
	public function test_save_keeps_valid_image_attachment_id(): void {
		$att_id = self::factory()->attachment->create_object(
			array(
				'post_mime_type' => 'image/png',
				'post_title'     => 'avatar',
			)
		);

		Agent_Profile::save( $this->slug, array( 'profile_avatar_id' => $att_id ) );

		$this->assertSame( $att_id, Agent_Profile::get( $this->slug )['avatar_id'] );
	}

	/**
	 * order() assigns 1-based positions in the given sequence.
	 */
	public function test_order_assigns_1_based_positions(): void {
		Agent_Profile::order( array( 'alpha', 'beta', 'gamma' ) );

		$this->assertSame( 1, Agent_Profile::get( 'alpha' )['order'] );
		$this->assertSame( 2, Agent_Profile::get( 'beta' )['order'] );
		$this->assertSame( 3, Agent_Profile::get( 'gamma' )['order'] );
	}

	/**
	 * identity_line() emits "You are …" only when a display name is set, and
	 * appends the title when one exists.
	 */
	public function test_identity_line(): void {
		$this->assertSame( '', Agent_Profile::identity_line( 'no-display-name' ) );

		Agent_Profile::save( 'plain', array( 'profile_display_name' => 'Plain' ) );
		$this->assertSame( "You are Plain.\n", Agent_Profile::identity_line( 'plain' ) );

		Agent_Profile::save(
			'titled',
			array(
				'profile_display_name' => 'Editor',
				'profile_title'        => 'Blog editor',
			)
		);
		$this->assertSame( "You are Editor, Blog editor.\n", Agent_Profile::identity_line( 'titled' ) );
	}

	/**
	 * export_fields() exposes the portable override keys but never the
	 * site-specific avatar attachment id.
	 */
	public function test_export_fields_excludes_avatar_id(): void {
		Agent_Profile::save(
			$this->slug,
			array(
				'profile_display_name' => 'Editor',
				'profile_title'        => 'Blog editor',
				'profile_pinned'       => true,
			)
		);

		$fields = Agent_Profile::export_fields( $this->slug );

		$this->assertArrayHasKey( 'profile_display_name', $fields );
		$this->assertArrayHasKey( 'profile_title', $fields );
		$this->assertArrayHasKey( 'profile_pinned', $fields );
		$this->assertArrayNotHasKey( 'profile_avatar_id', $fields );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Create a declarative manifest agent and refresh the registry so
	 * get_installed_agents() sees it.
	 *
	 * @param string $slug        Agent slug.
	 * @param string $name        Agent name.
	 * @param string $description Agent description.
	 * @param string $icon        Agent icon.
	 */
	private function create_agent( string $slug, string $name, string $description, string $icon ): void {
		$agent_dir = AGENT_BUILDER_AGENTS_DIR . '/' . $slug;
		wp_mkdir_p( $agent_dir );

		$manifest = array(
			'slug'              => $slug,
			'name'              => $name,
			'description'       => $description,
			'category'          => 'admin',
			'icon'              => $icon,
			'version'           => '1.0.0',
			'capabilities'      => array( 'read' ),
			'tools'             => array(),
			'suggested_prompts' => array(),
			'team'              => false,
		);

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture write.
		file_put_contents( $agent_dir . '/agent.json', wp_json_encode( $manifest, JSON_PRETTY_PRINT ) );

		\Agentic_Agent_Registry::get_instance()->get_installed_agents( true );
	}

	/**
	 * Delete a test agent directory.
	 *
	 * @param string $slug Agent slug.
	 */
	private function delete_agent( string $slug ): void {
		$agent_dir = AGENT_BUILDER_AGENTS_DIR . '/' . $slug;
		if ( is_dir( $agent_dir ) ) {
			$this->delete_directory( $agent_dir );
		}
		\Agentic\Abilities_Manifest::clear_cache( $slug );
		\Agentic_Agent_Registry::get_instance()->get_installed_agents( true );
	}
}
