<?php
/**
 * Unit Tests for the Anthropic Claude 5.x family rollout.
 *
 * Cover for #305: Anthropic shipped Sonnet 5.5 / Opus 5.5 / Fable 5.1, and
 * the newer models reject a *forced* `tool_choice` (`{"type":"any"}`) with a
 * 400. The provider catalogue now leads with the 5.x family (Sonnet 5.5 as
 * the fresh-install default), and the client stops forcing tool choice for
 * those models up front while learning any other model that rejects it at
 * runtime. Also guards that no `thinking` block is ever sent to Anthropic.
 *
 * @package Agentic\Tests
 */

namespace Agentic\Tests;

use Agentic\LLM_Client;
use Agentic\Model_Capabilities;
use Agentic\Provider_Registry;

/**
 * Test case for the Claude 5.x model catalogue and tool_choice behaviour.
 */
class Test_Anthropic_Claude_5 extends TestCase {

	/**
	 * A single OpenAI-shaped tool, reused across request-body tests.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $tool;

	/**
	 * Point the client at Anthropic with a stored API key, and clear any
	 * runtime-learned facts so each test starts from a clean slate.
	 */
	public function setUp(): void {
		parent::setUp();

		Provider_Registry::save_api_key( 'anthropic', 'test-key' );
		Provider_Registry::invalidate();
		delete_option( 'agent_builder_llm_provider' );
		delete_option( 'agent_builder_model' );
		delete_option( 'agent_builder_learned_tool_choice_unsupported' );
		MockWPFunctions::reset();

		$this->tool = array(
			array(
				'type'     => 'function',
				'function' => array(
					'name'        => 'get_weather',
					'description' => 'Get the weather.',
				),
			),
		);
	}

	/**
	 * Leave no Anthropic state behind for other tests.
	 */
	public function tearDown(): void {
		MockWPFunctions::reset();
		delete_option( 'agent_builder_llm_provider' );
		delete_option( 'agent_builder_model' );
		delete_option( 'agent_builder_learned_tool_choice_unsupported' );
		Provider_Registry::save_api_key( 'anthropic', '' );
		Provider_Registry::invalidate();

		parent::tearDown();
	}

	/**
	 * Build an Anthropic request body via the private format_request(), the
	 * same path chat()/stream_chat() use.
	 *
	 * @param string $model          Model id to send as.
	 * @param bool   $force_tool_use Force tool use flag.
	 * @return array<string, mixed>
	 */
	private function build_request( string $model, bool $force_tool_use ): array {
		update_option( 'agent_builder_llm_provider', 'anthropic' );
		update_option( 'agent_builder_model', $model );
		Provider_Registry::invalidate();

		$client = new LLM_Client();
		$format = new \ReflectionMethod( LLM_Client::class, 'format_request' );

		return $format->invoke(
			$client,
			array( array( 'role' => 'user', 'content' => 'hello' ) ),
			$this->tool,
			$force_tool_use
		);
	}

	/**
	 * Sonnet 5.5 must never be sent a forced tool_choice, and must never be
	 * sent a thinking block at all.
	 */
	public function test_sonnet_5_5_request_uses_auto_tool_choice_and_no_thinking(): void {
		$body = $this->build_request( 'claude-sonnet-5-5', true );

		$this->assertSame( 'auto', $body['tool_choice']['type'] ?? null, 'Sonnet 5.5 rejects a forced tool_choice; the client must send auto.' );
		$this->assertArrayNotHasKey( 'thinking', $body, 'No thinking block (enabled or disabled) may be sent to Anthropic.' );
	}

	/**
	 * The legacy 4.x family is untouched: a forced tool_choice is still
	 * allowed, so the client keeps sending "any" when asked to force.
	 */
	public function test_legacy_claude_4_6_still_forces_tool_choice(): void {
		$body = $this->build_request( 'claude-sonnet-4-6', true );

		$this->assertSame( 'any', $body['tool_choice']['type'] ?? null );
	}

	/**
	 * When an Anthropic model 400s on a forced tool_choice, the client must
	 * retry once with auto and remember the model so later calls stop forcing.
	 */
	public function test_forced_tool_choice_400_is_retried_with_auto(): void {
		update_option( 'agent_builder_llm_provider', 'anthropic' );
		// A 4.x model is not in the static reject list, so the first call still
		// forces "any" and exercises the runtime learn-then-retry path.
		update_option( 'agent_builder_model', 'claude-sonnet-4-6' );
		Provider_Registry::invalidate();

		$success = wp_json_encode(
			array(
				'content'     => array( array( 'type' => 'text', 'text' => 'hi' ) ),
				'stop_reason' => 'end_turn',
				'usage'       => array( 'input_tokens' => 1, 'output_tokens' => 1 ),
			)
		);
		$error = wp_json_encode(
			array(
				'type'  => 'error',
				'error' => array(
					'type'    => 'invalid_request_error',
					'message' => 'tool_choice: any is not supported for this model.',
				),
			)
		);

		$queue    = array(
			array( 'body' => $error, 'response' => array( 'code' => 400 ) ),
			array( 'body' => $success, 'response' => array( 'code' => 200 ) ),
		);
		$captured = array();
		$filter   = static function ( $preempt, $args, $url ) use ( &$queue, &$captured ) {
			$captured[] = isset( $args['body'] ) ? $args['body'] : '';
			return array_shift( $queue );
		};
		add_filter( 'pre_http_request', $filter, 10, 3 );

		$client = new LLM_Client();
		$result = $client->chat(
			array( array( 'role' => 'user', 'content' => 'weather?' ) ),
			$this->tool,
			true
		);

		remove_filter( 'pre_http_request', $filter, 10 );

		$this->assertIsArray( $result, 'The retry must succeed and return a normalized response.' );
		$this->assertCount( 2, $captured, 'Expected the original forced call plus one auto retry.' );

		$first  = json_decode( $captured[0], true );
		$second = json_decode( $captured[1], true );

		$this->assertSame( 'any', $first['tool_choice']['type'] ?? null, 'First call forces tool choice.' );
		$this->assertSame( 'auto', $second['tool_choice']['type'] ?? null, 'Retry falls back to auto.' );
		$this->assertSame( 'hi', $result['choices'][0]['message']['content'] ?? null );
		$this->assertFalse(
			Model_Capabilities::supports_forced_tool_choice( 'claude-sonnet-4-6', 'anthropic' ),
			'The rejection must be remembered so later calls skip forcing.'
		);
	}

	/**
	 * The hardcoded builtin catalogue seeds a fresh install with Sonnet 5.5 as
	 * the default, leads with the 5.x family, and retains the 4.x models.
	 */
	public function test_fresh_install_default_model_is_claude_sonnet_5_5(): void {
		$builtin   = new \ReflectionMethod( Provider_Registry::class, 'builtin_providers' );
		$providers = $builtin->invoke( null );

		$anthropic = null;
		foreach ( $providers as $p ) {
			if ( 'anthropic' === ( $p['slug'] ?? '' ) ) {
				$anthropic = $p;
				break;
			}
		}

		$this->assertNotNull( $anthropic, 'Anthropic must remain a builtin provider.' );
		$this->assertSame( 'claude-sonnet-5-5', $anthropic['default_model'] );

		$models = $anthropic['models'];
		$this->assertSame( 'claude-sonnet-5-5', $models[0], 'The 5.x family leads the catalogue.' );
		$this->assertContains( 'claude-opus-5-5', $models );
		$this->assertContains( 'claude-fable-5-1', $models );
		$this->assertContains( 'claude-haiku-4-5-20251001', $models );
		$this->assertContains( 'claude-opus-4-20250514', $models, 'The 4.x models stay selectable.' );
	}

	/**
	 * A site that already saved a model must keep it — the new default only
	 * seeds fresh installs and is never synced over a saved choice.
	 */
	public function test_existing_saved_model_is_preserved(): void {
		update_option( 'agent_builder_llm_provider', 'anthropic' );
		update_option( 'agent_builder_model', 'claude-opus-4-6' );
		Provider_Registry::invalidate();

		$client = new LLM_Client();

		$this->assertSame( 'claude-opus-4-6', $client->get_model() );
	}
}
