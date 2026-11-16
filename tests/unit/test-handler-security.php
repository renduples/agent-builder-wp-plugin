<?php
/**
 * Nonce + capability regression tests for admin AJAX handlers.
 *
 * Every data-changing wp_ajax_* handler must refuse a request that has no
 * valid nonce, and must refuse a logged-in user who lacks the capability even
 * when that user presents a valid nonce.
 *
 * @package Agentic\Tests
 */

namespace Agentic\Tests;

use Agentic\Admin_Ajax;
use Agentic\Okf_Admin;

/**
 * Test case for AJAX handler nonce / capability enforcement.
 */
class Test_Handler_Security extends \WP_Ajax_UnitTestCase {

	/**
	 * Handlers under test: action => [ callable, nonce action, nonce field ].
	 *
	 * @return array<string, array{0: callable, 1: string, 2: string}>
	 */
	private function handlers(): array {
		return array(
			'agentic_toggle_tool'                => array( array( Admin_Ajax::class, 'toggle_tool' ), 'agentic_toggle_tool', '_ajax_nonce' ),
			'agentic_remove_provider'            => array( array( Admin_Ajax::class, 'remove_provider' ), 'agentic_settings_nonce', 'nonce' ),
			'agentic_save_user_scheduled_task'   => array( array( Admin_Ajax::class, 'save_user_scheduled_task' ), 'agentic_user_scheduled_tasks', '_ajax_nonce' ),
			'agentic_dismiss_setup_notice'       => array( array( Admin_Ajax::class, 'dismiss_setup_notice' ), 'agentic_dismiss_setup_notice', 'nonce' ),
			'agentic_okf_save'                   => array( array( Okf_Admin::class, 'ajax_save' ), 'agentic_okf', '_ajax_nonce' ),
			'agentic_okf_delete'                 => array( array( Okf_Admin::class, 'ajax_delete' ), 'agentic_okf', '_ajax_nonce' ),
		);
	}

	/**
	 * Register the handlers (the dispatcher only does so inside real AJAX requests).
	 */
	public function set_up(): void {
		// Core's admin_init update checks (wp_version_check etc.) must not hit
		// the network from a unit test; answer every HTTP request with an
		// empty 200 so they return quietly.
		add_filter( 'pre_http_request', array( $this, 'block_http' ), 10, 0 );
		parent::set_up();
		foreach ( $this->handlers() as $action => $spec ) {
			add_action( 'wp_ajax_' . $action, $spec[0] );
		}
	}

	/**
	 * Short-circuit outbound HTTP.
	 *
	 * @return array
	 */
	public function block_http(): array {
		return array(
			'headers'  => array(),
			'body'     => '',
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
		);
	}

	/**
	 * Data provider: one row per handler.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function handler_provider(): array {
		return array(
			'toggle_tool'              => array( 'agentic_toggle_tool' ),
			'remove_provider'          => array( 'agentic_remove_provider' ),
			'save_user_scheduled_task' => array( 'agentic_save_user_scheduled_task' ),
			'dismiss_setup_notice'     => array( 'agentic_dismiss_setup_notice' ),
			'okf_save'                 => array( 'agentic_okf_save' ),
			'okf_delete'               => array( 'agentic_okf_delete' ),
		);
	}

	/**
	 * An administrator without a nonce is stopped by check_ajax_referer (-1).
	 *
	 * @dataProvider handler_provider
	 *
	 * @param string $action AJAX action.
	 */
	public function test_missing_nonce_is_rejected( string $action ): void {
		$this->_setRole( 'administrator' );
		$_POST = array( 'action' => $action );

		try {
			$this->_handleAjax( $action );
			$this->fail( 'Handler ran without a nonce: ' . $action );
		} catch ( \WPAjaxDieStopException $e ) {
			$this->assertSame( '-1', $e->getMessage() );
		}
	}

	/**
	 * A subscriber with a valid nonce is refused by the capability check.
	 *
	 * @dataProvider handler_provider
	 *
	 * @param string $action AJAX action.
	 */
	public function test_subscriber_with_nonce_is_rejected( string $action ): void {
		$this->_setRole( 'subscriber' );
		$spec  = $this->handlers()[ $action ];
		$_POST = array(
			'action'  => $action,
			$spec[2]  => wp_create_nonce( $spec[1] ),
			'tool'    => 'read_file',
			'enabled' => '0',
			'id'      => 'x',
		);

		try {
			$this->_handleAjax( $action );
		} catch ( \WPAjaxDieContinueException $e ) {
			unset( $e );
		} catch ( \WPAjaxDieStopException $e ) {
			unset( $e );
		}

		$response = json_decode( $this->_last_response, true );
		$this->assertIsArray( $response, 'Expected a JSON error response for ' . $action );
		$this->assertFalse( $response['success'], 'Subscriber was allowed through: ' . $action );
	}
}
