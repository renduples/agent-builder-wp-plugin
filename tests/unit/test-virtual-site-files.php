<?php
/**
 * Unit tests for Virtual_Site_Files: robots.txt rules and llms.txt are
 * served from options, never written at the site root.
 *
 * @package Agentic\Tests
 */

namespace Agentic\Tests;

use Agentic\Tool_Loader;
use Agentic\Virtual_Site_Files;

/**
 * Test case for virtual robots.txt / llms.txt.
 */
class Test_Virtual_Site_Files extends TestCase {

	/**
	 * Physical files created by a test (removed in tearDown).
	 *
	 * @var string[]
	 */
	private array $created = array();

	/**
	 * REQUEST_URI before the test.
	 *
	 * @var string|null
	 */
	private ?string $request_uri = null;

	/**
	 * Run as an administrator on a public site.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : null;
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option( 'blog_public', 1 );
		delete_option( Virtual_Site_Files::ROBOTS_OPTION );
		delete_option( Virtual_Site_Files::LLMS_OPTION );
		delete_option( Virtual_Site_Files::LEGACY_NOTICE_OPTION );
		delete_option( Virtual_Site_Files::LEGACY_CHECKED_OPTION );
		foreach ( array( 'robots.txt', 'llms.txt' ) as $name ) {
			$this->assertFileDoesNotExist( Virtual_Site_Files::root_path( $name ), 'Test install must start without a physical ' . $name );
		}
	}

	/**
	 * Remove physical fixtures.
	 */
	public function tearDown(): void {
		foreach ( $this->created as $path ) {
			if ( is_file( $path ) ) {
				unlink( $path );
			}
		}
		if ( null === $this->request_uri ) {
			unset( $_SERVER['REQUEST_URI'] );
		} else {
			$_SERVER['REQUEST_URI'] = $this->request_uri;
		}
		parent::tearDown();
	}

	/**
	 * Write a physical fixture at the site root.
	 *
	 * @param string $name    File name.
	 * @param string $content Content.
	 * @return void
	 */
	private function physical( string $name, string $content ): void {
		$path = Virtual_Site_Files::root_path( $name );
		file_put_contents( $path, $content );
		$this->created[] = $path;
	}

	/**
	 * Execute a bundled tool by name.
	 *
	 * @param string $name Tool name.
	 * @param array  $args Arguments.
	 * @return array
	 */
	private function run_tool( string $name, array $args = array() ): array {
		$tool = Tool_Loader::get_instance()->get( $name );
		$this->assertNotNull( $tool, $name );
		return $tool->execute( $args );
	}

	/**
	 * The update tool stores the bots and the robots_txt filter serves them;
	 * no file appears at the site root.
	 */
	public function test_update_robots_txt_is_served_virtually(): void {
		$result = $this->run_tool( 'update_robots_txt', array( 'allow_bots' => array( 'GPTBot', 'ClaudeBot' ) ) );

		$this->assertTrue( $result['success'] );
		$this->assertTrue( $result['changed'] );
		$this->assertSame( array( 'GPTBot', 'ClaudeBot' ), Virtual_Site_Files::robots_allowed_bots() );
		$this->assertFileDoesNotExist( Virtual_Site_Files::root_path( 'robots.txt' ) );

		// What core's do_robots() prints, built in-process.
		$served = Virtual_Site_Files::robots_txt();
		$this->assertSame( 'virtual', $served['source'] );
		$this->assertStringContainsString( "User-agent: *\n", $served['content'] );
		$this->assertStringContainsString( Virtual_Site_Files::ROBOTS_HEADER . "\nUser-agent: GPTBot\nAllow: /", $served['content'] );
		$this->assertStringContainsString( "User-agent: ClaudeBot\nAllow: /", $served['content'] );

		// Running again changes nothing.
		$again = $this->run_tool( 'update_robots_txt', array( 'allow_bots' => array( 'GPTBot' ) ) );
		$this->assertFalse( $again['changed'] );

		// Not added on a site that discourages search engines.
		$this->assertSame( "User-agent: *\nDisallow: /\n", Virtual_Site_Files::filter_robots_txt( "User-agent: *\nDisallow: /\n", false ) );
	}

	/**
	 * Invalid user-agent tokens never reach robots.txt.
	 */
	public function test_robots_bot_names_are_sanitized(): void {
		Virtual_Site_Files::set_robots_allowed_bots( array( 'GPTBot', "Evil\nDisallow: /", '', array( 'x' ) ) );
		$this->assertSame( array( 'GPTBot' ), Virtual_Site_Files::robots_allowed_bots() );
	}

	/**
	 * With a physical robots.txt the tool writes nothing and returns the
	 * exact lines for the administrator.
	 */
	public function test_update_robots_txt_with_physical_file_returns_lines(): void {
		$original = "User-agent: *\nDisallow: /private/\nUser-agent: GPTBot\nDisallow: /\n";
		$this->physical( 'robots.txt', $original );

		$result = $this->run_tool( 'update_robots_txt', array( 'allow_bots' => array( 'GPTBot' ) ) );

		$this->assertFalse( $result['success'] );
		$this->assertTrue( $result['physical_file'] );
		$this->assertSame( Virtual_Site_Files::ROBOTS_HEADER . "\nUser-agent: GPTBot\nAllow: /\n", $result['lines_to_add'] );
		$this->assertSame( $original, file_get_contents( Virtual_Site_Files::root_path( 'robots.txt' ) ) );
		$this->assertSame( array(), Virtual_Site_Files::robots_allowed_bots() );

		$preview = $this->run_tool( 'preview_robots_txt_update', array( 'allow_bots' => array( 'GPTBot' ) ) );
		$this->assertSame( 'physical_file', $preview['source'] );
		$this->assertArrayHasKey( 'lines_to_add', $preview );
	}

	/**
	 * llms.txt is stored, routed by a rewrite rule, and never written.
	 */
	public function test_generate_llms_txt_is_served_virtually(): void {
		$this->set_permalink_structure( '/%postname%/' );
		self::factory()->post->create( array( 'post_title' => 'Hello llms' ) );

		$result = $this->run_tool( 'generate_llms_txt', array( 'include_posts' => 3 ) );

		$this->assertTrue( $result['saved'] );
		$this->assertArrayNotHasKey( 'physical_file', $result );
		$this->assertFileDoesNotExist( Virtual_Site_Files::root_path( 'llms.txt' ) );
		$this->assertStringStartsWith( '# ', Virtual_Site_Files::llms_txt_option() );
		$this->assertStringContainsString( 'Hello llms', Virtual_Site_Files::llms_txt_option() );
		$this->assertSame( 'virtual', Virtual_Site_Files::llms_txt()['source'] );

		$rules = get_option( 'rewrite_rules' );
		$this->assertArrayHasKey( Virtual_Site_Files::REWRITE_REGEX, $rules );
		$this->assertSame( 'index.php?' . Virtual_Site_Files::QUERY_VAR . '=1', $rules[ Virtual_Site_Files::REWRITE_REGEX ] );

		// Request matching: query var (pretty permalinks) and path fallback.
		$wp             = new \WP();
		$wp->query_vars = array( Virtual_Site_Files::QUERY_VAR => '1' );
		$this->assertTrue( Virtual_Site_Files::is_llms_request( $wp ) );
		$wp->query_vars = array();
		$_SERVER['REQUEST_URI'] = '/llms.txt';
		$this->assertTrue( Virtual_Site_Files::is_llms_request( $wp ) );
		$_SERVER['REQUEST_URI'] = '/llms.txt.bak';
		$this->assertFalse( Virtual_Site_Files::is_llms_request( $wp ) );

		// Agent-Ready / AI Radar checks see the virtual file.
		$tech = $this->run_tool( 'check_technical_readiness' );
		$this->assertTrue( $tech['checks']['llms_txt'] );
	}

	/**
	 * With a physical llms.txt the content is saved but the admin is told
	 * the file wins; the file is not touched.
	 */
	public function test_generate_llms_txt_with_physical_file(): void {
		$this->physical( 'llms.txt', "# Hand written\n" );

		$result = $this->run_tool( 'generate_llms_txt' );

		$this->assertSame( Virtual_Site_Files::root_path( 'llms.txt' ), $result['physical_file'] );
		$this->assertSame( "# Hand written\n", file_get_contents( Virtual_Site_Files::root_path( 'llms.txt' ) ) );
		$this->assertSame( 'physical_file', Virtual_Site_Files::llms_txt()['source'] );
	}

	/**
	 * Files written by earlier versions are kept, their content carried
	 * over, and a note is queued.
	 */
	public function test_legacy_root_files_are_kept_and_noted(): void {
		$robots = Virtual_Site_Files::ROBOTS_HEADER . "\nUser-agent: GPTBot\nAllow: /\n\nUser-agent: PerplexityBot\nAllow: /\n\nUser-agent: *\nDisallow:\n";
		$this->physical( 'robots.txt', $robots );
		$this->physical( 'llms.txt', "# Old site\n\n> Summary\n" );

		Virtual_Site_Files::maybe_check_legacy_root_files();

		$this->assertSame( $robots, file_get_contents( Virtual_Site_Files::root_path( 'robots.txt' ) ) );
		$this->assertFileExists( Virtual_Site_Files::root_path( 'llms.txt' ) );
		$this->assertSame( array( 'GPTBot', 'PerplexityBot' ), Virtual_Site_Files::robots_allowed_bots() );
		$this->assertSame( "# Old site\n\n> Summary\n", Virtual_Site_Files::llms_txt_option() );
		$state = get_option( Virtual_Site_Files::LEGACY_NOTICE_OPTION );
		$this->assertSame( array( 'robots.txt', 'llms.txt' ), $state['files'] );
		$this->assertFalse( $state['dismissed'] );

		// Only on Siteloom screens.
		set_current_screen( 'dashboard' );
		ob_start();
		Virtual_Site_Files::show_legacy_root_files_notice();
		$this->assertSame( '', trim( (string) ob_get_clean() ) );

		$_GET['page'] = 'agentic-settings';
		ob_start();
		Virtual_Site_Files::show_legacy_root_files_notice();
		$html = (string) ob_get_clean();
		unset( $_GET['page'] );
		$this->assertStringContainsString( 'agentic-root-files-notice', $html );
		$this->assertStringContainsString( 'is-dismissible', $html );
		$this->assertStringNotContainsString( '<script', $html );
	}

	/**
	 * A robots.txt the plugin never wrote is not reported.
	 */
	public function test_unrelated_robots_txt_is_not_reported(): void {
		$this->physical( 'robots.txt', "User-agent: *\nDisallow:\n" );
		Virtual_Site_Files::maybe_check_legacy_root_files();
		$this->assertFalse( get_option( Virtual_Site_Files::LEGACY_NOTICE_OPTION ) );
	}

	/**
	 * No plugin source writes robots.txt or llms.txt at the site root.
	 */
	public function test_no_source_writes_site_root_files(): void {
		$root     = rtrim( AGENT_BUILDER_DIR, '/' );
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			$path = $file->getPathname();
			if ( ! str_ends_with( $path, '.php' ) || preg_match( '#/(vendor|node_modules|tests|\.git)/#', $path ) ) {
				continue;
			}
			$src = (string) file_get_contents( $path );
			$this->assertDoesNotMatchRegularExpression( '/ABSPATH\s*\.\s*[\'"](robots|llms)\.txt/', $src, $path );
			$this->assertDoesNotMatchRegularExpression( '/rtrim\(\s*ABSPATH[^)]*\)\s*\.\s*[\'"]\/(robots|llms)\.txt/', $src, $path );
		}
	}
}
