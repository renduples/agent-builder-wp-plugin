<?php
/**
 * Regression tests for the silent empty-reply bug (Assistant Trainer).
 *
 * The Assistant Trainer never answered on the hosted Gemini provider: its
 * tool list carried array parameters with no `items`, Vertex rejected the
 * whole request, the relay reported that as an SSE error frame on an HTTP
 * 200 stream, stream_chat() ignored the frame, and the controller accepted
 * the resulting empty text as a successful answer — an empty "end" event
 * with error:false and a blank chat bubble.
 *
 * Each link of that chain is covered here.
 *
 * @package Agentic\Tests
 */

namespace Agentic\Tests;

use Agentic\Agent_Controller;
use Agentic\LLM_Client;
use Agentic\Manifest_Agent;
use Agentic\Provider_Registry;
use Agentic\Tool_Loader;

/**
 * Test case for empty-reply handling.
 */
class Test_Empty_Reply extends TestCase {

	/**
	 * Configure the hosted agentic provider (req/resp format google).
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
		remove_all_filters( 'pre_http_request' );
		delete_option( 'agent_builder_license_key' );
		delete_option( 'agent_builder_allow_anonymous_chat' );
		Provider_Registry::save_api_key( 'agentic', '' );
		Provider_Registry::invalidate();
		delete_option( 'agent_builder_llm_provider' );
		delete_option( 'agent_builder_model' );

		parent::tearDown();
	}

	/**
	 * Collect the paths of array-typed schema nodes that lack `items`.
	 *
	 * @param mixed  $node Schema node.
	 * @param string $path Path so far.
	 * @return string[]
	 */
	private static function arrays_without_items( $node, string $path ): array {
		if ( ! is_array( $node ) ) {
			return array();
		}
		$bad  = array();
		$type = $node['type'] ?? null;
		if ( is_string( $type ) && 'array' === strtolower( $type ) && empty( $node['items'] ) ) {
			$bad[] = $path;
		}
		foreach ( $node as $key => $child ) {
			$bad = array_merge( $bad, self::arrays_without_items( $child, $path . '.' . $key ) );
		}
		return $bad;
	}

	/**
	 * Stub the next HTTP request with a fixed SSE body on HTTP 200.
	 *
	 * @param string $sse Raw SSE body.
	 */
	private function stub_stream( string $sse ): void {
		add_filter(
			'pre_http_request',
			static function () use ( $sse ) {
				return array(
					'headers'  => array( 'content-type' => 'text/event-stream' ),
					'body'     => $sse,
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
				);
			}
		);
	}

	/**
	 * No bundled tool may declare an array parameter without `items` —
	 * Gemini rejects the entire request for the agent carrying it.
	 */
	public function test_bundled_tools_declare_items_for_every_array(): void {
		$bad = array();
		foreach ( Tool_Loader::get_instance()->get_all_definitions() as $def ) {
			$bad = array_merge( $bad, self::arrays_without_items( $def['function']['parameters'], $def['function']['name'] ) );
		}

		$this->assertSame( array(), $bad, 'Array parameters without items: ' . implode( ', ', $bad ) );
	}

	/**
	 * A third-party tool with a loose array schema is repaired before it is
	 * sent, so one bad tool cannot silence an agent.
	 */
	public function test_request_body_gives_loose_arrays_items(): void {
		$tools = array(
			array(
				'type'     => 'function',
				'function' => array(
					'name'        => 'loose_tool',
					'description' => 'Flat legacy schema with bare arrays.',
					'parameters'  => array(
						'names'  => array(
							'type'     => 'array',
							'required' => true,
						),
						'nested' => array(
							'type'       => 'object',
							'properties' => array(
								'tags' => array( 'type' => 'array' ),
							),
						),
					),
				),
			),
		);

		$client = new LLM_Client();
		$format = new \ReflectionMethod( LLM_Client::class, 'format_request' );
		$body   = $format->invoke( $client, array( array( 'role' => 'user', 'content' => 'hi' ) ), $tools, false );

		$this->assertSame( array(), self::arrays_without_items( $body, 'body' ) );
	}

	/**
	 * An SSE error frame on an HTTP 200 stream is an error, not an empty reply.
	 * This is the exact frame the hosted relay sent for the Trainer.
	 */
	public function test_stream_error_frame_returns_wp_error(): void {
		$this->stub_stream( 'data: {"error": {"code": 500, "message": "Vertex 400: function_declarations[7].parameters.properties[parameters].items: missing field."}}' . "\n\n" );

		$client = new LLM_Client();
		$result = $client->stream_chat(
			array( array( 'role' => 'user', 'content' => 'Hello, what can you do?' ) ),
			static function () {}
		);

		$this->assertWPError( $result );
		$this->assertStringContainsString( 'items: missing field', $result->get_error_message() );
	}

	/**
	 * Anthropic's error event shape is caught too.
	 */
	public function test_anthropic_stream_error_event_returns_wp_error(): void {
		update_option( 'agent_builder_llm_provider', 'anthropic' );
		update_option( 'agent_builder_model', 'claude-sonnet-5-5' );
		Provider_Registry::save_api_key( 'anthropic', 'sk-ant-test' );
		Provider_Registry::invalidate();

		$this->stub_stream( "event: error\ndata: {\"type\": \"error\", \"error\": {\"type\": \"overloaded_error\", \"message\": \"Overloaded\"}}\n\n" );

		$client = new LLM_Client();
		$result = $client->stream_chat( array( array( 'role' => 'user', 'content' => 'hi' ) ), static function () {} );

		Provider_Registry::save_api_key( 'anthropic', '' );

		$this->assertWPError( $result );
		$this->assertSame( 'Overloaded', $result->get_error_message() );
	}

	/**
	 * A normal stream body that bypassed the cURL write hook is still parsed.
	 */
	public function test_stream_body_without_curl_hook_is_parsed(): void {
		$this->stub_stream( 'data: {"candidates": [{"content": {"parts": [{"text": "Hi there"}]}, "finishReason": "STOP"}]}' . "\n\n" );

		$tokens = '';
		$client = new LLM_Client();
		$result = $client->stream_chat(
			array( array( 'role' => 'user', 'content' => 'hi' ) ),
			static function ( string $t ) use ( &$tokens ) {
				$tokens .= $t;
			}
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'Hi there', $result['choices'][0]['message']['content'] );
		$this->assertSame( 'Hi there', $tokens );
	}

	/**
	 * The controller never reports an empty model reply as a successful answer,
	 * in streaming or non-streaming mode.
	 */
	public function test_controller_turns_empty_reply_into_error(): void {
		$slug = 'test-empty-reply';
		\Agentic_Agent_Registry::get_instance()->register(
			new Manifest_Agent(
				array(
					'slug' => $slug,
					'name' => 'Empty Reply Agent',
				),
				''
			)
		);
		update_option( 'agent_builder_allow_anonymous_chat', true );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		foreach ( array( false, true ) as $streaming ) {
			$controller = new Agent_Controller( new Fake_LLM_Client( array( Fake_LLM_Client::text_response( '' ) ) ) );
			if ( $streaming ) {
				$controller->enable_streaming( static function () {} );
			}
			$result = $controller->chat( 'Hello, what can you do?', array(), 0, 'session-empty', $slug );

			$label = $streaming ? 'streaming' : 'non-streaming';
			$this->assertTrue( ! empty( $result['error'] ), "An empty reply must be an error ($label)." );
			$this->assertNotSame( '', trim( (string) $result['response'] ), "The error must carry a message ($label)." );
		}
	}
}
