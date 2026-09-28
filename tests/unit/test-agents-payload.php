<?php
/**
 * Unit Tests for the Agents admin surface (Agents_Payload + REST actions).
 *
 * @package Agentic\Tests
 */

namespace Agentic\Tests;

use Agentic\Abilities_Manifest;
use Agentic\Admin_Pages\Agents_Payload;
use Agentic\Admin_Pages_REST;
use Agentic\Agent_Profile;
use Agentic\Agent_Settings;

/**
 * Test case for Agents_Payload and the agent REST actions.
 */
class Test_Agents_Payload extends TestCase {

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
		parent::tearDown();
	}

	/**
	 * build() returns the page shell and one merged row per installed agent.
	 */
	public function test_payload_build_returns_shape_and_rows(): void {
		$this->create_full_agent( 'shape-agent', 'Shape Agent', array( 'list_posts' ) );
		$this->track( 'shape-agent' );

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$payload = Agents_Payload::build();

		$this->assertSame( 'agents', $payload['page'] );
		$this->assertSame( 'Agents', $payload['title'] );
		$this->assertArrayHasKey( 'description', $payload );
		$this->assertArrayHasKey( 'is_advanced', $payload );
		$this->assertArrayHasKey( 'ui_mode', $payload );
		$this->assertTrue( $payload['is_admin'], 'Expected an administrator to be flagged is_admin.' );
		$this->assertNotEmpty( $payload['import_nonce'] );
		$this->assertStringContainsString( 'admin-post.php', $payload['import_url'] );

		$rows = array();
		foreach ( $payload['agents'] as $row ) {
			$rows[ $row['slug'] ] = $row;
		}

		$this->assertArrayHasKey( 'shape-agent', $rows, 'Expected the created agent in the payload.' );
		$row = $rows['shape-agent'];

		foreach ( array( 'slug', 'display_name', 'version', 'author', 'active', 'source', 'pinned', 'hidden', 'order' ) as $key ) {
			$this->assertArrayHasKey( $key, $row, "Expected a {$key} key on every agent row." );
		}
		$this->assertSame( '1.0.0', $row['version'] );
		$this->assertSame( 'user', $row['source'] );
		$this->assertFalse( $row['active'], 'A freshly-created agent starts inactive.' );
		$this->assertFalse( $row['pinned'] );
		$this->assertFalse( $row['hidden'] );
	}

	/**
	 * build() sorts pinned first, then by order, hidden last.
	 */
	public function test_payload_sorts_pinned_then_order_then_hidden(): void {
		$this->create_full_agent( 'sort-a', 'Sort A', array( 'list_posts' ) );
		$this->create_full_agent( 'sort-b', 'Sort B', array( 'list_posts' ) );
		$this->create_full_agent( 'sort-c', 'Sort C', array( 'list_posts' ) );
		$this->create_full_agent( 'sort-h', 'Sort H', array( 'list_posts' ) );
		foreach ( array( 'sort-a', 'sort-b', 'sort-c', 'sort-h' ) as $slug ) {
			$this->track( $slug );
		}

		Agent_Profile::save( 'sort-a', array( 'profile_order' => '3' ) );
		Agent_Profile::save( 'sort-b', array( 'profile_order' => '1', 'profile_pinned' => true ) );
		Agent_Profile::save( 'sort-c', array( 'profile_order' => '2' ) );
		// Hidden with the lowest order still lands last: hidden sorts after order.
		Agent_Profile::save( 'sort-h', array( 'profile_order' => '0', 'profile_hidden' => true ) );

		$payload = Agents_Payload::build();

		$mine = array_values(
			array_filter(
				$payload['agents'],
				static fn( array $row ): bool => in_array( $row['slug'], array( 'sort-a', 'sort-b', 'sort-c', 'sort-h' ), true )
			)
		);
		$slugs = array_map( static fn( array $row ): string => $row['slug'], $mine );

		$this->assertSame( array( 'sort-b', 'sort-c', 'sort-a', 'sort-h' ), $slugs );
	}

	/**
	 * agent_profile_save persists profile overrides and reads them back.
	 */
	public function test_agent_profile_save_action(): void {
		$this->create_full_agent( 'prof-agent', 'Prof Agent', array( 'list_posts' ) );
		$this->track( 'prof-agent' );

		$this->set_admin();
		$response = $this->dispatch(
			array(
				'action_name'         => 'agent_profile_save',
				'slug'                => 'prof-agent',
				'profile_display_name' => 'Prof Display',
				'profile_title'       => 'Writer',
				'profile_pinned'      => true,
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['ok'] );
		$this->assertSame( 'Prof Display', $data['agent']['display_name'] );
		$this->assertSame( 'Writer', $data['agent']['title'] );
		$this->assertTrue( $data['agent']['pinned'] );
	}

	/**
	 * agent_toggle activates then deactivates through the registry.
	 */
	public function test_agent_toggle_action(): void {
		$this->create_full_agent( 'toggle-agent', 'Toggle Agent', array( 'list_posts' ) );
		$this->track( 'toggle-agent' );

		$this->set_admin();
		$activate = $this->dispatch(
			array(
				'action_name' => 'agent_toggle',
				'slug'        => 'toggle-agent',
				'active'      => true,
			)
		);
		$this->assertSame( 200, $activate->get_status() );
		$this->assertTrue( $activate->get_data()['ok'] );
		$this->assertTrue( $activate->get_data()['active'] );
		$this->assertTrue( \Agentic_Agent_Registry::get_instance()->is_agent_active( 'toggle-agent' ) );

		$deactivate = $this->dispatch(
			array(
				'action_name' => 'agent_toggle',
				'slug'        => 'toggle-agent',
				'active'      => false,
			)
		);
		$this->assertSame( 200, $deactivate->get_status() );
		$this->assertFalse( $deactivate->get_data()['active'] );
		$this->assertFalse( \Agentic_Agent_Registry::get_instance()->is_agent_active( 'toggle-agent' ) );
	}

	/**
	 * agent_duplicate returns the new copy's slug.
	 */
	public function test_agent_duplicate_action(): void {
		$this->create_full_agent( 'dup-agent', 'Dup Agent', array( 'list_posts' ) );
		$this->track( 'dup-agent' );

		$this->set_admin();
		$response = $this->dispatch(
			array(
				'action_name' => 'agent_duplicate',
				'slug'        => 'dup-agent',
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['ok'] );
		$this->assertSame( 'dup-agent-copy', $data['slug'] );
		$this->track( $data['slug'] );
	}

	/**
	 * agent_reorder persists 1-based positions in the order given.
	 */
	public function test_agent_reorder_action(): void {
		$this->create_full_agent( 'reorder-a', 'Reorder A', array( 'list_posts' ) );
		$this->create_full_agent( 'reorder-b', 'Reorder B', array( 'list_posts' ) );
		$this->track( 'reorder-a' );
		$this->track( 'reorder-b' );

		$this->set_admin();
		$response = $this->dispatch(
			array(
				'action_name' => 'agent_reorder',
				'slugs'       => array( 'reorder-b', 'reorder-a' ),
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'reorder-b', 'reorder-a' ), $response->get_data()['slugs'] );
		$this->assertSame( 1, Agent_Profile::get( 'reorder-b' )['order'] );
		$this->assertSame( 2, Agent_Profile::get( 'reorder-a' )['order'] );
	}

	/**
	 * agent_export returns an admin-post download URL.
	 */
	public function test_agent_export_action(): void {
		$this->create_full_agent( 'exp-agent', 'Exp Agent', array( 'list_posts' ) );
		$this->track( 'exp-agent' );

		$this->set_admin();
		$response = $this->dispatch(
			array(
				'action_name' => 'agent_export',
				'slug'        => 'exp-agent',
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['ok'] );
		$this->assertStringContainsString( 'action=agentic_export_agent', $data['url'] );
		$this->assertStringContainsString( 'admin-post.php', $data['url'] );
	}

	/**
	 * agent_delete removes a user agent's directory (admin).
	 */
	public function test_agent_delete_action(): void {
		$this->create_full_agent( 'del-agent', 'Del Agent', array( 'list_posts' ) );
		$this->track( 'del-agent' );

		$this->set_admin();
		$response = $this->dispatch(
			array(
				'action_name' => 'agent_delete',
				'slug'        => 'del-agent',
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['ok'] );
		$this->assertFalse( is_dir( AGENT_BUILDER_AGENTS_DIR . '/del-agent' ), 'Expected the agent directory to be deleted.' );
	}

	/**
	 * agent_delete rejects a user holding only agent_builder_manage_agents.
	 */
	public function test_agent_delete_rejects_non_admin(): void {
		$this->create_full_agent( 'del-guard', 'Del Guard', array( 'list_posts' ) );
		$this->track( 'del-guard' );

		$this->set_agents_only_user();
		$response = $this->dispatch(
			array(
				'action_name' => 'agent_delete',
				'slug'        => 'del-guard',
			)
		);

		$this->assertTrue( $response->is_error() );
		$this->assertSame( 403, $response->get_status() );
		$this->assertTrue( is_dir( AGENT_BUILDER_AGENTS_DIR . '/del-guard' ), 'Expected the agent to survive the rejected delete.' );
	}

	/**
	 * import_agent() wp_die(403)s a user holding only agent_builder_manage_agents.
	 */
	public function test_agent_import_rejects_non_admin(): void {
		$this->set_agents_only_user();

		try {
			Admin_Pages_REST::import_agent();
			$this->fail( 'Expected import_agent() to wp_die() for a non-admin.' );
		} catch ( \WPDieException $e ) {
			$this->assertSame( 403, $e->getCode() );
		}
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
			'slug'         => $slug,
			'name'         => $name,
			'description'  => 'A test agent.',
			'category'     => 'admin',
			'icon'         => '🤖',
			'version'      => '1.0.0',
			'capabilities' => array( 'read' ),
			'tools'        => $tools,
			'team'         => false,
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
	 * Set the current user to an administrator.
	 *
	 * @return int User id.
	 */
	private function set_admin(): int {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		return $admin;
	}

	/**
	 * Set the current user to a non-admin granted only agent_builder_manage_agents.
	 *
	 * @return int User id.
	 */
	private function set_agents_only_user(): int {
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		get_user_by( 'id', $user )->add_cap( 'agent_builder_manage_agents' );
		wp_set_current_user( $user );
		return $user;
	}

	/**
	 * Dispatch a POST to the shared admin-page endpoint.
	 *
	 * @param array<string, mixed> $params Request params.
	 * @return \WP_REST_Response
	 */
	private function dispatch( array $params ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'POST', '/agentic/v1/admin-page' );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_get_server()->dispatch( $request );
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
