<?php
/**
 * Unit tests for the Gemini functionResponse.response fix: the field must be a
 * JSON object (Struct), never a bare list — a list-returning tool result would
 * otherwise 400 with "Proto field is not repeating, cannot start list".
 *
 * @package Agentic\Tests
 */

namespace Agentic\Tests;

use Agentic\LLM_Client;
use Agentic\Provider_Registry;

/**
 * Test case for functionResponse.response normalization (google dialect).
 */
class Test_Google_Function_Response extends TestCase {

	/**
	 * Configure the hosted agentic (google-dialect) provider.
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

		parent::tearDown();
	}

	/**
	 * Build a request from a conversation ending in a tool result and return the
	 * functionResponse part the google builder produced for it.
	 *
	 * @param string $content Raw tool-result content (the controller sends JSON).
	 * @return array<string, mixed>
	 */
	private function tool_response_part( string $content ): array {
		$client   = new LLM_Client();
		$format   = new \ReflectionMethod( LLM_Client::class, 'format_request' );
		$messages = array(
			array( 'role' => 'user', 'content' => 'do it' ),
			array(
				'role'         => 'tool',
				'name'         => 'list_privileged_users',
				'tool_call_id' => 'call_1',
				'content'      => $content,
			),
		);

		$body = $format->invoke( $client, $messages, array(), false );

		foreach ( (array) ( $body['contents'] ?? array() ) as $entry ) {
			foreach ( (array) ( $entry['parts'] ?? array() ) as $part ) {
				if ( isset( $part['functionResponse'] ) ) {
					return $part['functionResponse'];
				}
			}
		}

		return array();
	}

	/**
	 * A list-returning tool result must be wrapped in an object under 'result',
	 * not sent as a bare JSON list (which Vertex rejects with a 400).
	 */
	public function test_list_result_is_wrapped_in_object(): void {
		$fr = $this->tool_response_part( '[{"id":1},{"id":2}]' );

		$this->assertArrayHasKey( 'response', $fr );
		$resp = $fr['response'];
		$this->assertNotSame(
			$resp,
			array_values( $resp ),
			'functionResponse.response must be an object (Struct), never a bare list.'
		);
		$this->assertSame( array( array( 'id' => 1 ), array( 'id' => 2 ) ), $resp['result'] );
	}

	/**
	 * An object-returning tool result passes through unchanged (already a Struct).
	 */
	public function test_object_result_passes_through(): void {
		$fr = $this->tool_response_part( '{"status":"ok","count":3}' );

		$this->assertSame( array( 'status' => 'ok', 'count' => 3 ), $fr['response'] );
	}

	/**
	 * A non-JSON/scalar tool result is wrapped under 'result'.
	 */
	public function test_scalar_result_is_wrapped(): void {
		$fr = $this->tool_response_part( 'plain text result' );

		$this->assertSame( array( 'result' => 'plain text result' ), $fr['response'] );
	}
}
