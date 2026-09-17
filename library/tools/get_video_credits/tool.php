<?php
/**
 * Tool: get_video_credits
 *
 * Check the Agentic Video Generation credit balance for the connected account.
 * Credits are shared across all Agentic services (RAG, ImageGen, TTS, VideoGen).
 *
 * @package    Agent_Builder
 * @subpackage Tools
 * @since      3.0.0
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Check the Agentic Video Generation credit balance. Credits are shared with
 * all other Agentic services. 1 credit = $0.01 USD.
 * Veo 2 = 50 credits/second · Veo 3 = 85 credits/second · min 5 seconds billed.
 */
class Get_Video_Credits extends \Agentic\Tool_Base {

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'get_video_credits';
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return 'Check the current Agentic Video Generation credit balance. Credits are shared across all Agentic services (RAG, ImageGen, TTS, VideoGen). 1 credit = $0.01 USD. Generation costs: 50 credits/second (Veo 2) or 85 credits/second (Veo 3), minimum 5 seconds billed.';
	}

	/**
	 * Get the tool category.
	 *
	 * @return string
	 */
	public function get_category(): string {
		return 'media';
	}

	/**
	 * Get the parameter schema.
	 *
	 * @return array
	 */
	public function get_parameters(): array {
		return array(
			'type'       => 'object',
			'properties' => array(),
			'required'   => array(),
		);
	}

	/**
	 * Get annotations for the WP Abilities bridge.
	 *
	 * @return array{readonly: bool, destructive: bool, idempotent: bool}
	 */
	public function get_annotations(): array {
		return array(
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Validated arguments from the LLM.
	 * @return array Result data.
	 */
	public function execute( array $arguments ): array { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		$config = $this->get_videogen_config();
		if ( isset( $config['error'] ) ) {
			return $config;
		}

		$url      = add_query_arg( array( 'user_id' => rawurlencode( $config['user_id'] ) ), \Agentic\Service_Registry::url( 'agentic-videogen', '/credits' ) );
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 15,
				'headers' => array(
					'X-API-Key'        => $config['api_key'],
					'X-Plugin-Version' => AGENT_BUILDER_VERSION,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'error' => 'Could not reach the credit service: ' . $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 400 ) {
			$msg = isset( $data['detail'] ) ? (string) $data['detail'] : 'Credit service error (HTTP ' . $code . ')';
			return array( 'error' => $msg );
		}

		return is_array( $data ) ? $data : array( 'error' => 'Unexpected response from credit service.' );
	}

	/**
	 * Read and validate the videogen service configuration.
	 *
	 * @return array Config with api_key + user_id, or array with 'error' key.
	 */
	private function get_videogen_config(): array {
		$api_key = (string) get_option( 'agent_builder_rag_api_secret', '' );
		if ( empty( $api_key ) && defined( 'AGENTIC_RAG_API_KEY' ) ) {
			$api_key = AGENTIC_RAG_API_KEY;
		}
		if ( empty( $api_key ) ) {
			$api_key = (string) ( \Agentic\Provider_Registry::get( 'agentic' )['api_key'] ?? '' );
		}
		$user_id = $api_key;

		if ( empty( $api_key ) ) {
			return array( 'error' => 'Agentic Video Generation requires connecting an Agentic AI account — go to Agent Builder → Settings → Providers and connect Agentic AI.' );
		}

		return array(
			'api_key' => $api_key,
			'user_id' => $user_id,
		);
	}
}

return new Get_Video_Credits();
