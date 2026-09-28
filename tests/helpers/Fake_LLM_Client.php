<?php
/**
 * Fake_LLM_Client — a scripted LLM_Client test double.
 *
 * Extends the concrete LLM_Client so it satisfies Agent_Controller's
 * `?LLM_Client $llm` injection seam, but never touches the network. Each call
 * to chat() pops the next response from a FIFO queue, letting a test script a
 * full multi-iteration run (tool call → tool result → final text) in advance.
 *
 * The static tool_call_response() / text_response() factories build responses
 * in the same OpenAI shape LLM_Client::chat() returns, with a caller-supplied
 * `usage` block so tests can assert that tokens/cost accumulate across turns.
 *
 * @package Agentic\Tests
 */

namespace Agentic\Tests;

use Agentic\LLM_Client;

/**
 * Scripted, offline stand-in for LLM_Client.
 */
class Fake_LLM_Client extends LLM_Client {

	/**
	 * Remaining scripted responses, in call order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $queue = array();

	/**
	 * Number of chat() invocations so far.
	 *
	 * @var int
	 */
	public int $chat_calls = 0;

	/**
	 * Messages payload from each chat() invocation, in call order.
	 *
	 * @var array<int, array<int, array<string, mixed>>>
	 */
	public array $messages_seen = array();

	/**
	 * Constructor.
	 *
	 * @param array<int, array<string, mixed>> $responses Optional responses to seed the queue with.
	 */
	public function __construct( array $responses = array() ) {
		parent::__construct();
		foreach ( $responses as $response ) {
			$this->enqueue( $response );
		}
	}

	/**
	 * Queue a single scripted response for the next chat() call.
	 *
	 * @param array<string, mixed> $response OpenAI-shaped response.
	 * @return void
	 */
	public function enqueue( array $response ): void {
		$this->queue[] = $response;
	}

	/**
	 * Always report as configured, so no Provider_Registry state is required.
	 *
	 * @return bool
	 */
	public function is_configured(): bool {
		return true;
	}

	/**
	 * Return the next scripted response instead of calling a provider.
	 *
	 * @param array      $messages       Conversation messages (recorded for inspection).
	 * @param array      $tools          Available tools (unused).
	 * @param bool       $force_tool_use Force-tool-use flag (unused).
	 * @return array|\WP_Error The next queued response, or WP_Error when exhausted.
	 */
	public function chat( array $messages, array $tools = array(), bool $force_tool_use = false ): array|\WP_Error {
		++$this->chat_calls;
		$this->messages_seen[] = $messages;

		if ( empty( $this->queue ) ) {
			return new \WP_Error( 'fake_exhausted', 'Fake_LLM_Client has no scripted responses left.' );
		}

		return array_shift( $this->queue );
	}

	/**
	 * Build a scripted tool-call response.
	 *
	 * @param string               $tool_name Tool the model asks to call.
	 * @param array<string, mixed> $arguments Tool arguments (JSON-encoded into the call).
	 * @param array<string, int>   $usage     Optional usage block (defaults sum to a nonzero total).
	 * @return array<string, mixed>
	 */
	public static function tool_call_response( string $tool_name, array $arguments, array $usage = array() ): array {
		return array(
			'choices' => array(
				array(
					'message'       => array(
						'role'       => 'assistant',
						'content'    => '',
						'tool_calls' => array(
							array(
								'id'       => 'call_' . uniqid(),
								'type'     => 'function',
								'function' => array(
									'name'      => $tool_name,
									'arguments' => wp_json_encode( $arguments ),
								),
							),
						),
					),
					'finish_reason' => 'tool_calls',
				),
			),
			'usage'   => array_merge(
				array(
					'prompt_tokens'     => 10,
					'completion_tokens' => 5,
					'total_tokens'      => 15,
				),
				$usage
			),
		);
	}

	/**
	 * Build a scripted final-text response (no tool calls).
	 *
	 * @param string             $content Final assistant text.
	 * @param array<string, int> $usage   Optional usage block (defaults sum to a nonzero total).
	 * @return array<string, mixed>
	 */
	public static function text_response( string $content, array $usage = array() ): array {
		return array(
			'choices' => array(
				array(
					'message'       => array(
						'role'    => 'assistant',
						'content' => $content,
					),
					'finish_reason' => 'stop',
				),
			),
			'usage'   => array_merge(
				array(
					'prompt_tokens'     => 20,
					'completion_tokens' => 8,
					'total_tokens'      => 28,
				),
				$usage
			),
		);
	}
}
