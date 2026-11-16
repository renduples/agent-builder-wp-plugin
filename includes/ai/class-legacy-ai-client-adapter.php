<?php
/**
 * Legacy AI Client Adapter
 *
 * Wraps the existing robust LLM_Client + Provider_Registry stack.
 * This is the "bridge" implementation that gives full modern AI agent
 * capabilities (including weak-model reliability, reasoning capture,
 * multi-provider support, Ollama, etc.) on every supported WordPress
 * version from 6.4 upward.
 *
 * Always available. Used automatically when the native WP 7.0+ AI Client
 * is not present or not preferred.
 *
 * @package    Agent_Builder
 * @subpackage AI
 * @since      2.11.0
 */

declare( strict_types=1 );

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Legacy_AI_Client_Adapter implements AI_Client_Adapter {

	/**
	 * @var LLM_Client|null
	 */
	private $llm_client = null;

	public function get_slug(): string {
		return 'legacy';
	}

	public function get_name(): string {
		return 'Siteloom Legacy (full bridge on WP 6.4+)';
	}

	public function is_available(): bool {
		// Our own stack is always available once the plugin is active.
		return true;
	}

	/**
	 * @param array $args
	 * @return array|WP_Error
	 */
	public function generate( array $args ) {
		if ( ! $this->llm_client ) {
			$this->llm_client = new LLM_Client();
		}

		// WP 7.0+ Connectors preference (bridge + seamless native story).
		// If a central connector credential exists for the current provider, the
		// legacy path can still benefit (real value on upgraded sites today).
		if ( WP_AI_Detection::is_wp7_or_later() ) {
			$connector_creds = Provider_Registry::get_connector_credentials( $this->llm_client->get_provider() );
			if ( $connector_creds && ! empty( $connector_creds['api_key'] ) ) {
				$this->llm_client->set_api_key( $connector_creds['api_key'] );
			}
		}

		// Map the common args our controller already builds into the shape
		// the current LLM_Client::chat / stream_chat expect.
		// We keep the mapping minimal here; the real smarts stay in LLM_Client
		// and Agent_Controller (history, tool defs, weak-model guidance, etc.).

		$messages = array();
		if ( ! empty( $args['system'] ) || ! empty( $args['system_instruction'] ) ) {
			$messages[] = array(
				'role'    => 'system',
				'content' => $args['system_instruction'] ?? $args['system'],
			);
		}
		if ( ! empty( $args['history'] ) && is_array( $args['history'] ) ) {
			$messages = array_merge( $messages, $args['history'] );
		}
		if ( ! empty( $args['text'] ) ) {
			$messages[] = array(
				'role'    => 'user',
				'content' => $args['text'],
			);
		}

		// Delegate to the existing, heavily battle-tested path.
		// LLM_Client::chat returns the normalized assistant message array.
		$response = $this->llm_client->chat( $messages );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Already in a shape our code understands — just tag the adapter.
		if ( is_array( $response ) ) {
			$response['_adapter'] = 'legacy';
		}

		return $response;
	}

	public function get_capabilities(): array {
		// Our legacy stack has excellent practical coverage thanks to prior P0 work.
		return array(
			'text_generation'        => true,
			'json_schema'            => true, // via our simplification + retry + few-shot
			'multimodal'             => false, // vision handled via separate vision_model path today
			'streaming'              => true,
			'token_usage'            => true,
			'weak_model_reliability' => true, // one of our strongest differentiators
		);
	}
}
