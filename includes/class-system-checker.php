<?php
/**
 * System Requirements Checker
 *
 * Tests server configuration to ensure Agent Builder can run successfully.
 * Checks PHP settings, WordPress config, server timeouts, and LLM API connectivity.
 *
 * @package    Agent_Builder
 * @subpackage Includes
 * @author     Agent Builder Team <support@agentic-plugin.com>
 * @license    GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 * @link       https://agentic-plugin.com
 * @since      0.1.0
 *
 * php version 8.1
 */

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * System Checker Class
 */
class System_Checker {

	/**
	 * Register REST API routes
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			'agentic/v1',
			'/system-check',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'run_system_check' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);

		register_rest_route(
			'agentic/v1',
			'/timeout-test',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'test_timeout' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
	}

	/**
	 * Run all system checks
	 *
	 * @return \WP_REST_Response
	 */
	public static function run_system_check(): \WP_REST_Response {
		$checks = array();

		// PHP Version.
		$php_version = phpversion();
		$checks[]    = array(
			'name'     => 'PHP Version',
			'status'   => version_compare( $php_version, '8.0', '>=' ) ? 'pass' : 'fail',
			'value'    => $php_version,
			'required' => '8.0+',
			'fix'      => 'Update PHP via your hosting control panel or contact your hosting provider.',
		);

		// Max Execution Time.
		$max_exec = ini_get( 'max_execution_time' );
		$checks[] = array(
			'name'     => 'Max Execution Time',
			'status'   => ( 0 === (int) $max_exec || $max_exec >= 120 ) ? 'pass' : 'fail',
			'value'    => ( 0 === (int) $max_exec ) ? 'Unlimited' : $max_exec . 's',
			'required' => '120s+',
			'fix'      => 'Add to wp-config.php: @ini_set(\'max_execution_time\', 120);',
		);

		// Memory Limit.
		$memory       = ini_get( 'memory_limit' );
		$memory_bytes = wp_convert_hr_to_bytes( $memory );
		$checks[]     = array(
			'name'     => 'Memory Limit',
			'status'   => $memory_bytes >= 256 * 1024 * 1024 ? 'pass' : 'warning',
			'value'    => $memory,
			'required' => '256M+',
			'fix'      => 'Add to wp-config.php: define(\'WP_MEMORY_LIMIT\', \'256M\');',
		);

		// WordPress Version.
		$wp_version = get_bloginfo( 'version' );
		$checks[]   = array(
			'name'     => 'WordPress Version',
			'status'   => version_compare( $wp_version, '6.0', '>=' ) ? 'pass' : 'fail',
			'value'    => $wp_version,
			'required' => '6.0+',
			'fix'      => 'Update WordPress core from Dashboard → Updates.',
		);

		// Permalinks.
		$permalink_structure = get_option( 'permalink_structure' );
		$checks[]            = array(
			'name'     => 'Permalinks',
			'status'   => ! empty( $permalink_structure ) ? 'pass' : 'fail',
			'value'    => ! empty( $permalink_structure ) ? 'Custom' : 'Default',
			'required' => 'Custom (not default)',
			'fix'      => 'Go to Settings → Permalinks, choose any non-default option and save.',
		);

		// LLM API Key — at least one key-requiring provider must have a stored key.
				$has_api_key = ! empty(
					array_filter(
						\Agentic\Provider_Registry::get_all(),
						static fn( array $p ) => $p['requires_key'] && ! empty( $p['api_key'] )
					)
				);
				$checks[]    = array(
					'name'     => 'LLM API Key',
					'status'   => $has_api_key ? 'pass' : 'warning',
					'value'    => $has_api_key ? 'Configured' : 'Not set',
					'required' => 'Required for Agent Builder',
					'fix'      => 'Enter your API key in Settings → Providers.',
				);

				// REST API.
				$rest_enabled = rest_url() !== false;
				$checks[]     = array(
					'name'     => 'REST API',
					'status'   => $rest_enabled ? 'pass' : 'fail',
					'value'    => $rest_enabled ? 'Enabled' : 'Disabled',
					'required' => 'Enabled',
					'fix'      => 'Check if REST API is blocked by security plugin or hosting settings.',
				);

				// Overall status.
				$overall = ! in_array( 'fail', array_column( $checks, 'status' ), true );

				// Save results.
				update_option(
					'agent_builder_last_system_check',
					array(
						'timestamp' => time(),
						'results'   => $checks,
						'overall'   => $overall,
					)
				);

		return new \WP_REST_Response(
			array(
				'checks'  => $checks,
				'overall' => $overall,
			),
			200
		);
	}

	/**
	 * Test server timeout capability
	 *
	 * Sleeps for 90 seconds to verify server can handle long requests.
	 *
	 * @return \WP_REST_Response
	 */
	public static function test_timeout(): \WP_REST_Response {
		// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Required for timeout testing.
		set_time_limit( 120 );
		$start = time();
		sleep( 90 ); // Sleep for 90 seconds.
		$duration = time() - $start;

		return new \WP_REST_Response(
			array(
				'success'  => true,
				'duration' => $duration,
				'message'  => 'Server can handle long requests (90+ seconds)',
			),
			200
		);
	}

	/**
	 * Get last system check results
	 *
	 * @return array|null
	 */
	public static function get_last_check(): ?array {
		return get_option( 'agent_builder_last_system_check', null );
	}

	/**
	 * Check if system requirements are met
	 *
	 * @return bool
	 */
	public static function requirements_met(): bool {
		$last_check = self::get_last_check();

		if ( ! $last_check ) {
			return false;
		}

		return $last_check['overall'] ?? false;
	}
}
