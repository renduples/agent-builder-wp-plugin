<?php
/**
 * Unit Tests for the event-listener manifest sanitisation added in 4.1.0.
 *
 * Covers `min_interval` (rate-limit window) and `arg_filter` (positional-hook
 * argument + PCRE filter) coercion, and that a listener naming neither a prompt
 * nor a tool is dropped.
 *
 * @package Agentic\Tests
 */

namespace Agentic\Tests;

use Agentic\Agent_Manifest_Validator;

/**
 * Test case for the event-listener manifest keys.
 */
class Test_Agent_Manifest_Validator extends TestCase {

	/**
	 * Build a minimal valid manifest whose event_listeners is the focus.
	 *
	 * @param array $listeners Raw event_listeners input.
	 * @return array<string, mixed>|WP_Error Sanitized manifest.
	 */
	private function validate( array $listeners ) {
		return Agent_Manifest_Validator::validate(
			array(
				'slug'            => 'listener-manifest-agent',
				'name'            => 'Listener Manifest Agent',
				'description'     => 'Test agent.',
				'category'        => 'developer',
				'event_listeners' => $listeners,
			)
		);
	}

	/**
	 * `min_interval` defaults to 60, honours 0 (disabled), coerces negatives to
	 * 60, and caps at one day.
	 */
	public function test_min_interval_coercion(): void {
		$manifest = $this->validate(
			array(
				array( 'id' => 'defaulted', 'hook' => 'updated_option', 'tool' => 'add_custom_css' ),
				array( 'id' => 'disabled', 'hook' => 'updated_option', 'tool' => 'add_custom_css', 'min_interval' => 0 ),
				array( 'id' => 'negative', 'hook' => 'updated_option', 'tool' => 'add_custom_css', 'min_interval' => -5 ),
				array( 'id' => 'capped', 'hook' => 'updated_option', 'tool' => 'add_custom_css', 'min_interval' => 999999 ),
			)
		);

		$this->assertIsArray( $manifest );
		$listeners = array_column( $manifest['event_listeners'], 'min_interval', 'id' );

		$this->assertSame( 60, $listeners['defaulted'] );
		$this->assertSame( 0, $listeners['disabled'] );
		$this->assertSame( 60, $listeners['negative'] );
		$this->assertSame( 86400, $listeners['capped'] );
	}

	/**
	 * `arg_filter` survives when valid and is dropped when its pattern does not
	 * compile; `arg` is clamped to the accepted-args range.
	 */
	public function test_arg_filter_sanitisation(): void {
		$manifest = $this->validate(
			array(
				array(
					'id'         => 'filtered',
					'hook'       => 'updated_option',
					'tool'       => 'add_custom_css',
					'arg_filter' => array( 'arg' => 0, 'pattern' => '^woocommerce_' ),
				),
				array(
					'id'         => 'bad-pattern',
					'hook'       => 'updated_option',
					'tool'       => 'add_custom_css',
					'arg_filter' => array( 'arg' => 0, 'pattern' => '(' ),
				),
			)
		);

		$this->assertIsArray( $manifest );
		$listeners = $manifest['event_listeners'];

		$this->assertSame(
			array( 'arg' => 0, 'pattern' => '^woocommerce_' ),
			$listeners[0]['arg_filter']
		);

		$this->assertArrayNotHasKey( 'arg_filter', $listeners[1], 'an invalid PCRE pattern is dropped, not kept' );
	}

	/**
	 * The `in` allowlist form survives sanitisation as an exact-match list, and an
	 * empty `in` is preserved (it matches nothing at runtime).
	 */
	public function test_arg_filter_in_allowlist_validation(): void {
		$manifest = $this->validate(
			array(
				array(
					'id'         => 'allowlisted',
					'hook'       => 'updated_option',
					'tool'       => 'add_custom_css',
					'arg_filter' => array( 'arg' => 0, 'in' => array( 'publish', 'pending' ) ),
				),
				array(
					'id'         => 'empty',
					'hook'       => 'updated_option',
					'tool'       => 'add_custom_css',
					'arg_filter' => array( 'arg' => 0, 'in' => array() ),
				),
			)
		);

		$this->assertIsArray( $manifest );
		$listeners = $manifest['event_listeners'];

		$this->assertSame(
			array( 'arg' => 0, 'in' => array( 'publish', 'pending' ) ),
			$listeners[0]['arg_filter']
		);
		$this->assertSame(
			array( 'arg' => 0, 'in' => array() ),
			$listeners[1]['arg_filter']
		);
	}

	/**
	 * A malformed `arg_filter` — neither form, both forms, a non-string allowlist
	 * item, or a non-list `in` — is rejected (dropped, not kept).
	 */
	public function test_arg_filter_rejects_malformed_filters(): void {
		$manifest = $this->validate(
			array(
				array(
					'id'         => 'neither',
					'hook'       => 'updated_option',
					'tool'       => 'add_custom_css',
					'arg_filter' => array( 'arg' => 0 ),
				),
				array(
					'id'         => 'both',
					'hook'       => 'updated_option',
					'tool'       => 'add_custom_css',
					'arg_filter' => array( 'arg' => 0, 'pattern' => '^x', 'in' => array( 'x' ) ),
				),
				array(
					'id'         => 'non-string-item',
					'hook'       => 'updated_option',
					'tool'       => 'add_custom_css',
					'arg_filter' => array( 'arg' => 0, 'in' => array( 'ok', 42 ) ),
				),
				array(
					'id'         => 'non-list-in',
					'hook'       => 'updated_option',
					'tool'       => 'add_custom_css',
					'arg_filter' => array( 'arg' => 0, 'in' => 'publish' ),
				),
			)
		);

		$this->assertIsArray( $manifest );
		foreach ( $manifest['event_listeners'] as $listener ) {
			$this->assertArrayNotHasKey(
				'arg_filter',
				$listener,
				sprintf( 'listener %s keeps no malformed arg_filter', $listener['id'] )
			);
		}
	}

	/**
	 * A listener naming neither `prompt` nor `tool` has nothing to run and is
	 * discarded from the manifest entirely.
	 */
	public function test_listener_without_action_is_dropped(): void {
		$manifest = $this->validate(
			array(
				array( 'id' => 'noop', 'hook' => 'updated_option' ),
				array( 'id' => 'runnable', 'hook' => 'updated_option', 'tool' => 'add_custom_css' ),
			)
		);

		$this->assertIsArray( $manifest );
		$ids = array_column( $manifest['event_listeners'], 'id' );
		$this->assertSame( array( 'runnable' ), $ids );
	}
}
