<?php
/**
 * Unit tests for the per-agent MCP endpoint (Agentic_MCP_Server).
 *
 * Locks the WordPress.org review remedy for "remote administration through
 * the plugin":
 *  - MCP is off by default for every agent, and only an administrator can
 *    turn it on.
 *  - The endpoint refuses unauthenticated callers, callers without the
 *    staff floor, and callers without the per-tool capability.
 *  - No plugin code path creates a WordPress Application Password.
 *
 * @package Agentic\Tests
 */

namespace Agentic\Tests;

use Agentic\Abilities_Manifest;

/**
 * Test case for the MCP endpoint.
 */
class Test_MCP_Server extends TestCase {

	/**
	 * Fixture agent slug.
	 */
	const SLUG = 'mcp-test-agent';

	/**
	 * Read-only tool the fixture agent declares.
	 */
	const READ_TOOL = 'list_posts';

	/**
	 * Write tool the fixture agent declares.
	 */
	const WRITE_TOOL = 'create_post_content';

	/**
	 * Start every test with MCP off and no fixture agent.
	 */
	public function setUp(): void {
		parent::setUp();
		delete_option( \Agentic_MCP_Server::ENABLED_AGENTS_OPTION );
		delete_option( \Agentic_MCP_Server::LAST_CONNECTED_OPTION );
	}

	/**
	 * Remove the fixture agent and reset state.
	 */
	public function tearDown(): void {
		$registry = \Agentic_Agent_Registry::get_instance();
		if ( $registry->is_agent_active( self::SLUG ) ) {
			$registry->deactivate_agent( self::SLUG );
		}
		$dir = \Agentic\File_Manager::agents_dir() . '/' . self::SLUG;
		if ( is_dir( $dir ) ) {
			$this->remove_dir( $dir );
		}
		Abilities_Manifest::clear_cache( self::SLUG );
		$registry->get_installed_agents( true );

		delete_option( \Agentic_MCP_Server::ENABLED_AGENTS_OPTION );
		delete_option( \Agentic_MCP_Server::LAST_CONNECTED_OPTION );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	// ── (c) Off by default ─────────────────────────────────────────────────

	/**
	 * A freshly installed agent's MCP endpoint is off.
	 */
	public function test_mcp_is_off_by_default(): void {
		$this->create_agent();

		$this->assertFalse( \Agentic_MCP_Server::is_mcp_enabled( self::SLUG ) );
		$this->assertSame( array(), get_option( \Agentic_MCP_Server::ENABLED_AGENTS_OPTION, array() ) );

		$readiness = \Agentic_MCP_Server::mcp_readiness( self::SLUG );
		$this->assertFalse( $readiness['ready'] );
	}

	/**
	 * An authenticated administrator still gets a 403 from an agent whose MCP
	 * endpoint has not been turned on, and no tools are revealed.
	 */
	public function test_endpoint_refuses_agent_with_mcp_off(): void {
		$this->create_agent();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$resp = $this->mcp( 'tools/list' );

		$this->assertSame( 403, $resp->get_status() );
		$this->assertSame( 'agentic_mcp_disabled', $resp->get_data()['code'] ?? '' );
	}

	/**
	 * Only a site administrator (manage_options) may turn MCP on.
	 */
	public function test_only_administrator_can_enable_mcp(): void {
		$this->create_agent();

		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		get_userdata( $editor )->add_cap( 'agent_builder_manage_settings' );
		wp_set_current_user( $editor );

		$resp = $this->toggle( true );
		$this->assertContains( $resp->get_status(), array( 401, 403 ) );
		$this->assertFalse( \Agentic_MCP_Server::is_mcp_enabled( self::SLUG ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$resp = $this->toggle( true );
		$this->assertSame( 200, $resp->get_status() );
		$this->assertTrue( \Agentic_MCP_Server::is_mcp_enabled( self::SLUG ) );
	}

	// ── (b) Authentication and capability ─────────────────────────────────

	/**
	 * Anonymous requests are refused with a 401, even when MCP is on.
	 */
	public function test_endpoint_refuses_unauthenticated_request(): void {
		$this->create_agent();
		\Agentic_MCP_Server::set_mcp_enabled( self::SLUG, true );
		wp_set_current_user( 0 );

		foreach ( array( 'initialize', 'tools/list', 'tools/call' ) as $method ) {
			$resp = $this->mcp( $method, array( 'name' => self::WRITE_TOOL ) );
			$this->assertSame( 401, $resp->get_status(), "$method must require authentication" );
		}
		$this->assertSame( array(), get_option( \Agentic_MCP_Server::LAST_CONNECTED_OPTION, array() ) );
	}

	/**
	 * A logged-in user below the staff floor (subscriber) is refused.
	 */
	public function test_endpoint_refuses_subscriber(): void {
		$this->create_agent();
		\Agentic_MCP_Server::set_mcp_enabled( self::SLUG, true );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$resp = $this->mcp( 'tools/list' );
		$this->assertSame( 403, $resp->get_status() );
	}

	/**
	 * Write tools need manage_options; read-only tools need edit_posts.
	 */
	public function test_required_capability_per_tool(): void {
		$this->assertSame( 'manage_options', \Agentic_MCP_Server::required_capability_for_tool( self::WRITE_TOOL ) );
		$this->assertSame( 'edit_posts', \Agentic_MCP_Server::required_capability_for_tool( self::READ_TOOL ) );
		$this->assertSame( 'manage_options', \Agentic_MCP_Server::required_capability_for_tool( 'no_such_tool' ) );
	}

	/**
	 * An editor (passes the staff floor) cannot call a write tool, and the
	 * tool never runs — no post is created.
	 */
	public function test_editor_cannot_call_write_tool(): void {
		$this->create_agent();
		\Agentic_MCP_Server::set_mcp_enabled( self::SLUG, true );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$before = (int) wp_count_posts( 'post' )->draft + (int) wp_count_posts( 'post' )->publish;

		$resp = $this->mcp(
			'tools/call',
			array(
				'name'      => $this->mcp_tool_name( self::WRITE_TOOL ),
				'arguments' => array(
					'title'   => 'MCP capability test',
					'content' => 'Should never be created.',
				),
			)
		);

		$this->assertSame( 200, $resp->get_status() );
		$body = $resp->get_data();
		$this->assertArrayHasKey( 'error', $body, 'An editor must not be able to call a write tool over MCP.' );
		$this->assertSame( 'Insufficient permissions for this tool.', $body['error']['message'] );

		$after = (int) wp_count_posts( 'post' )->draft + (int) wp_count_posts( 'post' )->publish;
		$this->assertSame( $before, $after );
	}

	/**
	 * An administrator's write call reaches Tool_Executor (no capability
	 * error) — the normal risk/approval gate then decides what happens.
	 */
	public function test_administrator_write_call_goes_through_tool_executor(): void {
		$this->create_agent();
		\Agentic_MCP_Server::set_mcp_enabled( self::SLUG, true );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$resp = $this->mcp(
			'tools/call',
			array(
				'name'      => $this->mcp_tool_name( self::WRITE_TOOL ),
				'arguments' => array(
					'title'   => 'MCP admin test',
					'content' => 'Body.',
				),
			)
		);

		$body = $resp->get_data();
		$this->assertArrayNotHasKey( 'error', $body );
		$this->assertArrayHasKey( 'result', $body );
	}

	// ── (a) No Application Passwords ──────────────────────────────────────

	/**
	 * The old "create / revoke credential" routes no longer exist.
	 */
	public function test_credential_routes_are_gone(): void {
		$routes = rest_get_server()->get_routes();
		$this->assertArrayNotHasKey( '/agentic/v1/admin-settings/mcp-create-credential', $routes );
		$this->assertArrayNotHasKey( '/agentic/v1/admin-settings/mcp-revoke-credential', $routes );
	}

	/**
	 * Driving every MCP-related flow (settings bootstrap, enabling MCP,
	 * testing, listing and calling tools) never creates an Application
	 * Password for anyone.
	 */
	public function test_mcp_flows_create_no_application_password(): void {
		$this->create_agent();
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$req = new \WP_REST_Request( 'GET', '/agentic/v1/admin-settings' );
		$this->assertSame( 200, rest_get_server()->dispatch( $req )->get_status() );

		$this->toggle( true );

		$req = new \WP_REST_Request( 'POST', '/agentic/v1/admin-settings/mcp-test' );
		$req->set_param( 'slug', self::SLUG );
		rest_get_server()->dispatch( $req );

		$this->mcp( 'initialize' );
		$this->mcp( 'tools/list' );
		$this->mcp( 'tools/call', array( 'name' => $this->mcp_tool_name( self::READ_TOOL ) ) );

		foreach ( get_users( array( 'fields' => 'ID' ) ) as $user_id ) {
			$this->assertSame(
				array(),
				\WP_Application_Passwords::get_user_application_passwords( (int) $user_id ),
				"User $user_id must not have an Application Password created by the plugin."
			);
		}
	}

	/**
	 * No shipped plugin source calls core's Application Password creation
	 * API at all.
	 */
	public function test_no_plugin_source_creates_application_passwords(): void {
		$root  = dirname( __DIR__, 2 );
		$hits  = array();
		$iter  = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );
		$skip  = '#/(vendor|node_modules|tests|\.git)/#';
		foreach ( $iter as $file ) {
			$path = $file->getPathname();
			if ( preg_match( $skip, $path ) || ! preg_match( '/\.(php|js)$/', $path ) ) {
				continue;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source scan.
			$src = (string) file_get_contents( $path );
			if ( false !== stripos( $src, 'create_new_application_password' ) ) {
				$hits[] = substr( $path, strlen( $root ) + 1 );
			}
		}

		$this->assertSame( array(), $hits, 'Plugin code must never create Application Passwords.' );
	}

	// ── Helpers ───────────────────────────────────────────────────────────

	/**
	 * Install and activate a fixture agent declaring one read and one write tool.
	 */
	private function create_agent(): void {
		$dir = \Agentic\File_Manager::agents_dir() . '/' . self::SLUG;
		wp_mkdir_p( $dir . '/templates' );

		$tools = array( self::READ_TOOL, self::WRITE_TOOL );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture write.
		file_put_contents(
			$dir . '/agent.json',
			wp_json_encode(
				array(
					'slug'              => self::SLUG,
					'name'              => 'MCP Test Agent',
					'description'       => 'A test agent.',
					'category'          => 'admin',
					'icon'              => '🤖',
					'version'           => '1.0.0',
					'capabilities'      => array( 'read' ),
					'tools'             => $tools,
					'suggested_prompts' => array(),
					'team'              => false,
				)
			)
		);

		$abilities = array(
			'version'   => '1.0',
			'abilities' => array(),
		);
		foreach ( $tools as $tool ) {
			$abilities['abilities'][ $tool ] = array( 'risk' => 'none' );
		}
		Abilities_Manifest::write_manifest( $dir, self::SLUG, $abilities );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture write.
		file_put_contents( $dir . '/templates/system-prompt.txt', "You are a test agent.\n" );

		$registry = \Agentic_Agent_Registry::get_instance();
		$registry->get_installed_agents( true );
		$registry->activate_agent( self::SLUG );
		$this->assertNotNull( $registry->get_agent_instance( self::SLUG ), 'Fixture agent should be active.' );
	}

	/**
	 * The MCP tool name for one of this plugin's tools on this site: an
	 * ability name (agent-builder__tool-name) when core's Abilities API is
	 * present, else the raw tool name.
	 *
	 * @param string $tool Tool name.
	 * @return string
	 */
	private function mcp_tool_name( string $tool ): string {
		if ( function_exists( 'wp_get_ability' ) ) {
			return 'agent-builder__' . str_replace( '_', '-', $tool );
		}
		return $tool;
	}

	/**
	 * Dispatch a JSON-RPC call to the fixture agent's MCP endpoint.
	 *
	 * @param string $method JSON-RPC method.
	 * @param array  $params JSON-RPC params.
	 * @return \WP_REST_Response
	 */
	private function mcp( string $method, array $params = array() ): \WP_REST_Response {
		$req = new \WP_REST_Request( 'POST', '/agentic/' . self::SLUG . '/mcp' );
		$req->set_header( 'Content-Type', 'application/json' );
		$req->set_body(
			(string) wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => $method,
					'params'  => (object) $params,
				)
			)
		);
		return rest_get_server()->dispatch( $req );
	}

	/**
	 * Toggle MCP for the fixture agent via the settings route.
	 *
	 * @param bool $enabled Enable or disable.
	 * @return \WP_REST_Response
	 */
	private function toggle( bool $enabled ): \WP_REST_Response {
		$req = new \WP_REST_Request( 'POST', '/agentic/v1/admin-settings/mcp-toggle-agent' );
		$req->set_param( 'slug', self::SLUG );
		$req->set_param( 'enabled', $enabled );
		return rest_get_server()->dispatch( $req );
	}

	/**
	 * Recursively delete a directory.
	 *
	 * @param string $dir Directory.
	 */
	private function remove_dir( string $dir ): void {
		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test cleanup.
			$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test cleanup.
		rmdir( $dir );
	}
}
