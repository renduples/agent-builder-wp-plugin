<?php
/**
 * Tool: get_image_pricing
 *
 * Retrieve current pricing rates for the Agentic Image Generation service.
 *
 * @package    Agent_Builder
 * @subpackage Tools
 * @since      3.3.36
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Retrieve current pricing rates for the Agentic Image Generation service.
 * Returns credits-per-image for each model and the USD cost per credit.
 */
class Get_Image_Pricing extends \Agentic\Tool_Base {

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'get_image_pricing';
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return 'Retrieve current pricing rates for the Agentic Image Generation service. Returns credits-per-image for imagen-4-fast, imagen-4, and imagen-4-ultra (generate/edit/upscale), and cost per credit in USD. Use this before estimating the cost of an image generation job.';
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

		if ( empty( $api_key ) ) {
			return array( 'error' => 'Agentic Image Generation requires connecting an Agentic AI account — go to Agent Builder → Settings → Providers and connect Agentic AI.' );
		}

		$response = wp_remote_get(
			\Agentic\Service_Registry::url( 'agentic-imagegen', '/pricing' ),
			array(
				'timeout' => 15,
				'headers' => array(
					'X-API-Key'        => $api_key,
					'X-Plugin-Version' => AGENT_BUILDER_VERSION,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'error' => 'Could not reach the pricing service: ' . $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 400 ) {
			$msg = isset( $data['detail'] ) ? (string) $data['detail'] : 'Pricing service error (HTTP ' . $code . ')';
			return array( 'error' => $msg );
		}

		return is_array( $data ) ? $data : array( 'error' => 'Unexpected response from pricing service.' );
	}
}

return new Get_Image_Pricing();
