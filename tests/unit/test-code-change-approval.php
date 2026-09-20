<?php
/**
 * Unit Tests for the `code_change` approval write path.
 *
 * REST_API::handle_approval() looks an approval row up by ID and runs whatever
 * `action`/`params` it carries, so anything able to write the approval queue
 * table controls both the path and the content that reach the `code_change`
 * handler. These tests treat that row as hostile and assert the handler cannot
 * be talked into writing an executable file anywhere — by extension, by double
 * extension, by traversal, or via a symlink planted inside uploads.
 *
 * @package Agentic\Tests
 */

namespace Agentic\Tests;

use Agentic\REST_API;
use ReflectionMethod;

/**
 * Test case for the code_change approval handler.
 */
class Test_Code_Change_Approval extends TestCase {

	/**
	 * Absolute paths created during a test, removed in tearDown().
	 *
	 * @var string[]
	 */
	private array $created_paths = array();

	/**
	 * Reflected handler under test.
	 *
	 * @var ReflectionMethod
	 */
	private ReflectionMethod $handler;

	/**
	 * REST_API instance (constructed without hooks).
	 *
	 * @var REST_API
	 */
	private REST_API $api;

	/**
	 * Setup.
	 */
	public function setUp(): void {
		parent::setUp();

		// newInstanceWithoutConstructor() so the test does not register the
		// plugin's REST routes a second time inside the test request.
		$this->api = ( new \ReflectionClass( REST_API::class ) )->newInstanceWithoutConstructor();

		// No setAccessible() call: since PHP 8.1 reflection can invoke a
		// private method directly, and the method is deprecated as of 8.5.
		$this->handler = new ReflectionMethod( REST_API::class, 'execute_code_change' );
	}

	/**
	 * Teardown: remove anything the test created, symlinks first.
	 */
	public function tearDown(): void {
		foreach ( array_reverse( $this->created_paths ) as $path ) {
			if ( is_link( $path ) ) {
				unlink( $path );
			} elseif ( file_exists( $path ) ) {
				unlink( $path );
			}
		}
		$this->created_paths = array();
		parent::tearDown();
	}

	/**
	 * Uploads base directory.
	 *
	 * @return string
	 */
	private function uploads_dir(): string {
		$dir = wp_upload_dir( null, false );
		return rtrim( $dir['basedir'], '/' );
	}

	/**
	 * Create a real file and register it for cleanup.
	 *
	 * @param string $path     Absolute path.
	 * @param string $contents File contents.
	 * @return string
	 */
	private function seed_file( string $path, string $contents ): string {
		wp_mkdir_p( dirname( $path ) );
		file_put_contents( $path, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$this->created_paths[] = $path;
		return $path;
	}

	/**
	 * Run the handler with a forged approval row.
	 *
	 * @param mixed  $path    Path exactly as the forged row would carry it.
	 * @param string $content File content.
	 * @return array<string,mixed>
	 */
	private function attempt( mixed $path, string $content = "<?php echo 'x';" ): array {
		return $this->handler->invoke(
			$this->api,
			array(
				'id'       => 4242,
				'agent_id' => 'forged',
			),
			array(
				'path'    => $path,
				'content' => $content,
			)
		);
	}

	/**
	 * Every executable-looking target must be refused, and nothing written.
	 *
	 * @dataProvider executable_target_provider
	 *
	 * @param string $path Relative path the forged approval asks to write.
	 */
	public function test_executable_targets_are_refused( string $path ): void {
		$result = $this->attempt( $path );

		$this->assertFalse( $result['success'], "Write should be refused for: {$path}" );
		$this->assertFalse( $result['ran'], "Write should not run for: {$path}" );
	}

	/**
	 * Paths that must never be writable through this handler.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function executable_target_provider(): array {
		return array(
			'plain php'            => array( 'uploads/agentic-cc-shell.php' ),
			'double extension'     => array( 'uploads/agentic-cc-shell.jpg.php' ),
			'uppercase extension'  => array( 'uploads/AGENTIC-CC-SHELL.PHP' ),
			'trailing dot'         => array( 'uploads/agentic-cc-shell.php.' ),
			'trailing space'       => array( 'uploads/agentic-cc-shell.php ' ),
			'ntfs data stream'     => array( 'uploads/agentic-cc-shell.php::$DATA' ),
			'phtml'                => array( 'uploads/agentic-cc-shell.phtml' ),
			'phar'                 => array( 'uploads/agentic-cc-shell.phar' ),
			'htaccess'             => array( 'uploads/.htaccess' ),
			'no extension'         => array( 'uploads/agentic-cc-shell' ),
			'traversal to plugins' => array( 'uploads/../plugins/agentic-cc-shell.php' ),
			'doubled traversal'    => array( 'uploads/....//plugins/agentic-cc-shell.php' ),
			'plugins directly'     => array( 'plugins/agentic-cc-shell.php' ),
			'themes directly'      => array( 'themes/agentic-cc-shell.php' ),
			'absolute path'        => array( '/tmp/agentic-cc-shell.php' ),
			'backslash traversal'  => array( 'uploads\\..\\plugins\\agentic-cc-shell.php' ),
			'null byte'            => array( "uploads/agentic-cc-shell.php\0.txt" ),
			// Same-origin script in a visitor's browser — not on the allowlist.
			'html'                 => array( 'uploads/agentic-cc-page.html' ),
			'svg'                  => array( 'uploads/agentic-cc-image.svg' ),
		);
	}

	/**
	 * A non-string path is refused rather than coerced.
	 */
	public function test_non_string_path_is_refused(): void {
		$result = $this->attempt( array( 'uploads/agentic-cc-notes.txt' ) );
		$this->assertFalse( $result['success'] );
		$this->assertFalse( $result['ran'] );
	}

	/**
	 * An existing PHP file inside uploads is never overwritten.
	 */
	public function test_existing_php_file_is_not_overwritten(): void {
		$target = $this->seed_file( $this->uploads_dir() . '/agentic-cc-existing.php', '<?php // original' );

		$result = $this->attempt( 'uploads/agentic-cc-existing.php', '<?php system( $_GET["c"] );' );

		$this->assertFalse( $result['success'] );
		$this->assertSame( '<?php // original', file_get_contents( $target ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/**
	 * An inert-looking name symlinked to a PHP file resolves and is refused.
	 */
	public function test_symlink_to_php_inside_uploads_is_refused(): void {
		$real = $this->seed_file( $this->uploads_dir() . '/agentic-cc-target.php', '<?php // original' );
		$link = $this->uploads_dir() . '/agentic-cc-decoy.txt';

		if ( ! @symlink( $real, $link ) ) {
			$this->markTestSkipped( 'Filesystem does not support symlinks.' );
		}
		$this->created_paths[] = $link;

		$result = $this->attempt( 'uploads/agentic-cc-decoy.txt', '<?php system( $_GET["c"] );' );

		$this->assertFalse( $result['success'] );
		$this->assertSame( '<?php // original', file_get_contents( $real ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/**
	 * A symlink whose real target sits outside uploads is refused even when
	 * both the link and the target have inert extensions.
	 */
	public function test_symlink_escaping_uploads_is_refused(): void {
		$outside = $this->seed_file( WP_CONTENT_DIR . '/agentic-cc-outside.txt', 'original' );
		$link    = $this->uploads_dir() . '/agentic-cc-escape.txt';

		if ( ! @symlink( $outside, $link ) ) {
			$this->markTestSkipped( 'Filesystem does not support symlinks.' );
		}
		$this->created_paths[] = $link;

		$result = $this->attempt( 'uploads/agentic-cc-escape.txt', 'overwritten' );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'original', file_get_contents( $outside ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/**
	 * A directory is never treated as a write target.
	 */
	public function test_directory_target_is_refused(): void {
		$result = $this->attempt( 'uploads', 'x' );
		$this->assertFalse( $result['success'] );
	}

	/**
	 * The handler still does its job for an inert file under uploads.
	 */
	public function test_inert_file_under_uploads_is_written(): void {
		$target = $this->seed_file( $this->uploads_dir() . '/agentic-cc-notes.txt', 'original' );

		$result = $this->attempt( 'uploads/agentic-cc-notes.txt', 'updated by approval' );

		$this->assertTrue( $result['success'], $result['message'] ?? '' );
		$this->assertTrue( $result['ran'] );
		$this->assertSame( 'updated by approval', file_get_contents( $target ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/**
	 * A nested inert file under uploads is written too.
	 */
	public function test_nested_inert_file_is_written(): void {
		$target = $this->seed_file( $this->uploads_dir() . '/agentic-cc/data.json', '{}' );

		$result = $this->attempt( 'uploads/agentic-cc/data.json', '{"ok":true}' );

		$this->assertTrue( $result['success'], $result['message'] ?? '' );
		$this->assertSame( '{"ok":true}', file_get_contents( $target ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/**
	 * Empty content is refused rather than truncating the target.
	 */
	public function test_empty_content_is_refused(): void {
		$target = $this->seed_file( $this->uploads_dir() . '/agentic-cc-keep.txt', 'original' );

		$result = $this->attempt( 'uploads/agentic-cc-keep.txt', '' );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'original', file_get_contents( $target ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}
}
