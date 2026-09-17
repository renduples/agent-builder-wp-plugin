<?php
/**
 * Tool: get_image_credits
 *
 * Check the Agentic Image Generation credit balance for the connected account.
 * Credits are shared between the Image Generation and Vector Store services.
 *
 * @package    Agent_Builder
 * @subpackage Tools
 * @since      2.2.7
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Check the Agentic Image Generation credit balance. Credits are shared with
 * the Vector Store / RAG service. 1 credit = $0.01 USD.
 */
class Get_Image_Credits extends \Agentic\Tool_Base {

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'get_image_credits';
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return 'Check the current Agentic Image Generation credit balance. Credits are shared with the Vector Store service. 1 credit = $0.01 USD. Generation costs: 3 credits (imagen-4-fast), 6 credits (imagen-4), 9 credits (imagen-4-ultra) per image.';
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
		$api_key = (string) get_option( 'agent_builder_rag_api_secret', '' );
		if ( empty( $api_key ) && defined( 'AGENTIC_RAG_API_KEY' ) ) {
			$api_key = AGENTIC_RAG_API_KEY;
		}
		if ( empty( $api_key ) ) {
			$api_key = (string) ( \Agentic\Provider_Registry::get( 'agentic' )['api_key'] ?? '' );
		}
		$user_id = $api_key;

		if ( empty( $api_key ) ) {
			return array( 'error' => 'Agentic Image Generation requires connecting an Agentic AI account — go to Agent Builder → Settings → Providers and connect Agentic AI.' );
		}

		$url      = add_query_arg( array( 'user_id' => rawurlencode( $user_id ) ), \Agentic\Service_Registry::url( 'agentic-imagegen', '/credits' ) );
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 15,
				'headers' => array(
					'X-API-Key'        => $api_key,
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
}

return new Get_Image_Credits();
