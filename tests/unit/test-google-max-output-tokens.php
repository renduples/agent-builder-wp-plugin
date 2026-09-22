<?php
/**
 * Unit Tests for #158: the google-dialect request body must cap output
 * tokens instead of letting the hosted proxy reserve credits against the
 * model's full output ceiling (e.g. 65,536 tokens for gemini-2.5-flash,
 * ~24.6 credits/call worst-case) for every hosted "agentic" call.
 *
 * @package Agentic\Tests
 */

namespace Agentic\Tests;

use Agentic\LLM_Client;
use Agentic\Provider_Registry;

/**
 * Test case for the generationConfig.maxOutputTokens fix.
 */
class Test_Google_Max_Output_Tokens extends TestCase {

	/**
	 * Reset provider config between tests.
	 */
	public function setUp(): void {
		parent::setUp();

		Provider_Registry::save_api_key( 'agentic', 'relay-key-abc123' );
		Provider_Registry::invalidate();
		update_option( 'agent_builder_license_key', 'AGNT-TEST-KEY' );
		update_option( 'agent_builder_llm_provider', 'agentic' );
		update_option( 'agent_builder_model', 'gemini-2.5-flash' );
	}

	/**
	 * Leave no state behind for other tests.
	 */
	public function tearDown(): void {
		delete_option( 'agent_builder_license_key' );
		Provider_Registry::save_api_key( 'agentic', '' );
		Provider_Registry::invalidate();
		delete_option( 'agent_builder_llm_provider' );
		delete_option( 'agent_builder_model' );
		remove_all_filters( 'agentic_google_max_output_tokens' );

		parent::tearDown();
	}

	/**
	 * Invoke the private format_request() the same way chat()/stream_chat() do.
	 *
	 * @param array $messages Conversation messages.
	 * @param array $tools    Tool declarations.
	 * @return array
	 */
	private function build_request( array $messages, array $tools = array() ): array {
		$client = new LLM_Client();
		$format = new \ReflectionMethod( LLM_Client::class, 'format_request' );

		return $format->invoke( $client, $messages, $tools, false );
	}

	/**
	 * The hosted agentic provider runs req_format=google — the body sent to
	 * the relay must include a sane, non-ceiling maxOutputTokens so the proxy
	 * stops over-reserving credits.
	 */
	public function test_google_body_includes_sane_max_output_tokens(): void {
		$body = $this->build_request( array( array( 'role' => 'user', 'content' => 'hello' ) ) );

		$this->assertArrayHasKey( 'generationConfig', $body );
		$this->assertArrayHasKey( 'maxOutputTokens', $body['generationConfig'] );

		$max = $body['generationConfig']['maxOutputTokens'];

		$this->assertGreaterThan( 0, $max );
		// Comfortably above observed real-world usage (~244 tokens avg)...
		$this->assertGreaterThanOrEqual( 1024, $max );
		// ...but nowhere near the 65,536 ceiling that inflates the reservation.
		$this->assertLessThan( 65536, $max );
	}

	/**
	 * Same-shaped request built by format_request_for_provider() (used by the
	 * settings "Test Connection" endpoints) must carry the same cap.
	 */
	public function test_test_connection_body_also_caps_output_tokens(): void {
		$client = new LLM_Client();
		$body   = $client->format_request_for_provider(
			'agentic',
			array( array( 'role' => 'user', 'content' => 'ping' ) )
		);

		$this->assertSame(
			8192,
			$body['generationConfig']['maxOutputTokens'] ?? null,
			'Test-connection requests reserve credits too and must carry the same cap.'
		);
	}

	/**
	 * The cap is overridable via the documented filter.
	 */
	public function test_max_output_tokens_is_filterable(): void {
		add_filter(
			'agentic_google_max_output_tokens',
			function () {
				return 2048;
			}
		);

		$body = $this->build_request( array( array( 'role' => 'user', 'content' => 'hello' ) ) );

		$this->assertSame( 2048, $body['generationConfig']['maxOutputTokens'] );
	}

	/**
	 * Adding generationConfig must not disturb function-declaration tool
	 * calling on the google dialect.
	 */
	public function test_tool_calling_is_unaffected(): void {
		$tools = array(
			array(
				'type'     => 'function',
				'function' => array(
					'name'        => 'get_weather',
					'description' => 'Get the weather.',
					'parameters'  => array(
						'type'       => 'object',
						'properties' => array(
							'city' => array( 'type' => 'string' ),
						),
					),
				),
			),
		);

		$body = $this->build_request( array( array( 'role' => 'user', 'content' => 'weather?' ) ), $tools );

		$this->assertArrayHasKey( 'generationConfig', $body );
		$this->assertSame( 'get_weather', $body['tools'][0]['functionDeclarations'][0]['name'] ?? null );
		$this->assertSame( 'AUTO', $body['toolConfig']['functionCallingConfig']['mode'] ?? null );
	}

	/**
	 * Adding generationConfig must not disturb Gemini-3 thoughtSignature
	 * replay on assistant messages carrying prior tool calls.
	 */
	public function test_thought_signature_replay_is_unaffected(): void {
		$messages = array(
			array( 'role' => 'user', 'content' => 'weather?' ),
			array(
				'role'       => 'assistant',
				'content'    => '',
				'tool_calls' => array(
					array(
						'id'                 => 'call_1',
						'function'           => array(
							'name'      => 'get_weather',
							'arguments' => '{"city":"Cape Town"}',
						),
						'thought_signature' => 'opaque-signature-abc',
					),
				),
			),
		);

		$body = $this->build_request( $messages );

		$this->assertArrayHasKey( 'generationConfig', $body );

		$model_turn = null;
		foreach ( $body['contents'] as $entry ) {
			if ( 'model' === ( $entry['role'] ?? '' ) ) {
				$model_turn = $entry;
			}
		}

		$this->assertNotNull( $model_turn, 'Expected the assistant tool-call turn to be present.' );
		$fc_part = null;
		foreach ( $model_turn['parts'] as $part ) {
			if ( isset( $part['functionCall'] ) ) {
				$fc_part = $part;
			}
		}

		$this->assertNotNull( $fc_part );
		$this->assertSame( 'opaque-signature-abc', $fc_part['thoughtSignature'] ?? null );
	}
}
