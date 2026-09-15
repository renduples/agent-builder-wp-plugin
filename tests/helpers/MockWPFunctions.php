<?php
/**
 * WordPress HTTP Mocking Helpers
 *
 * Tests run against a real, booted WordPress core test install
 * (WP_UnitTestCase), not an isolated brain/monkey sandbox — every core
 * function (get_option, wp_remote_get, current_user_can, ...) is already
 * defined and executing for real. That means the usual brain/monkey
 * `Mockery::mock( 'alias:wp_remote_post' )` pattern cannot be used here: PHP
 * fatals on redefining a function that already exists. Instead, this class
 * intercepts outbound HTTP the same way WordPress itself expects a test
 * suite to — via the `pre_http_request` filter, which every wp_remote_*()
 * call already checks before opening a socket.
 *
 * @package Agent_Builder
 * @subpackage Tests
 */

namespace Agentic\Tests;

/**
 * Intercepts wp_remote_*() calls so tests never make a real outbound request.
 */
class MockWPFunctions {

	/**
	 * Track filter callbacks added by mock_remote_response() so tests can
	 * remove them in tearDown() and not leak a canned response into the
	 * next test.
	 *
	 * @var callable[]
	 */
	private static array $active_filters = array();

	/**
	 * Short-circuit every wp_remote_*() call with a canned response, for the
	 * duration of the current test.
	 *
	 * @param array $response {
	 *     Optional. Shape of a WP_Http response array.
	 *     @type string $body     Response body.
	 *     @type array  $response {@type int $code HTTP status code.}
	 * }
	 * @return void
	 */
	public static function mock_remote_response( array $response = array() ): void {
		$response = array_replace(
			array(
				'body'     => '{}',
				'response' => array( 'code' => 200 ),
				'headers'  => array(),
			),
			$response
		);

		$callback = static function () use ( $response ) {
			return $response;
		};

		self::$active_filters[] = $callback;
		add_filter( 'pre_http_request', $callback, 10, 0 );
	}

	/**
	 * Remove every filter added by mock_remote_response(). Call from
	 * tearDown() in any test that uses the mock, so a canned response
	 * doesn't leak into the next test.
	 *
	 * @return void
	 */
	public static function reset(): void {
		foreach ( self::$active_filters as $callback ) {
			remove_filter( 'pre_http_request', $callback, 10 );
		}
		self::$active_filters = array();
	}
}
