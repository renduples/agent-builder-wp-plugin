<?php
/**
 * WP AI Client Adapter (native WordPress 7.0+ path)
 *
 * Thin, faithful wrapper around the official `WP_Optional_API::ai_client_prompt()` builder.
 * Maps the beautiful WP_Error + GenerativeAiResult + tokenUsage conventions
 * into the shape our Agent_Controller / LLM_Client already expect.
 *
 * Only instantiated / registered when WP_AI_Detection::has_ai_client() is true.
 * Gives users the "configure once in Settings → Connectors" experience while
 * keeping every AgentStructor safety, observability, multi-agent, and approval
 * feature intact.
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

class WP_AI_Client_Adapter implements AI_Client_Adapter {

	public function get_slug(): string {
		return 'wp-ai-client';
	}

	public function get_name(): string {
		return 'WordPress 7.0+ AI Client (native)';
	}

	public function is_available(): bool {
		return WP_AI_Detection::has_ai_client();
	}

	/**
	 * @param array $args
	 * @return array|WP_Error
	 */
	public function generate( array $args ) {
		if ( ! WP_Optional_API::has( 'wp_ai_client_prompt' ) ) {
			return new \WP_Error( 'ai_client_unavailable', 'WP AI Client not available on this WordPress version.' );
		}

		try {
			$builder = WP_Optional_API::ai_client_prompt();

			// Core text
			if ( ! empty( $args['text'] ) ) {
				$builder = $builder->with_text( (string) $args['text'] );
			}

			// System instruction
			if ( ! empty( $args['system_instruction'] ) || ! empty( $args['system'] ) ) {
				$sys     = $args['system_instruction'] ?? $args['system'];
				$builder = $builder->using_system_instruction( (string) $sys );
			}

			// History (array of role/content messages)
			if ( ! empty( $args['history'] ) && is_array( $args['history'] ) ) {
				$builder = $builder->with_history( $args['history'] );
			}

			// Temperature / max tokens / model prefs
			if ( isset( $args['temperature'] ) ) {
				$builder = $builder->using_temperature( (float) $args['temperature'] );
			}
			if ( isset( $args['max_tokens'] ) ) {
				$builder = $builder->using_max_tokens( (int) $args['max_tokens'] );
			}
			if ( ! empty( $args['model'] ) ) {
				$builder = $builder->using_model_preference( (string) $args['model'] );
			}

			// Structured / JSON mode
			if ( ! empty( $args['json_schema'] ) && is_array( $args['json_schema'] ) ) {
				$builder = $builder->as_json_response( $args['json_schema'] );
			}

			// Multimodal / files (future-proof)
			if ( ! empty( $args['files'] ) && is_array( $args['files'] ) ) {
				foreach ( $args['files'] as $file ) {
					$builder = $builder->with_file( $file );
				}
			}

			// Execute — prefer the rich result object when available
			if ( method_exists( $builder, 'generate_text_result' ) ) {
				$result = $builder->generate_text_result();
			} else {
				$text   = $builder->generate_text();
				$result = (object) array(
					'text'              => $text,
					'token_usage'       => null,
					'provider_metadata' => array(),
					'model_metadata'    => array(),
				);
			}

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			// Normalize to what our existing code expects
			$normalized = array(
				'success'           => true,
				'text'              => $result->text ?? (string) $result,
				'tokens_used'       => $result->token_usage->total_tokens ?? $result->tokenUsage->totalTokens ?? null,
				'model'             => $result->model_metadata['model'] ?? $result->model ?? null,
				'provider_metadata' => $result->provider_metadata ?? $result->providerMetadata ?? array(),
				'raw'               => $result, // full object for advanced consumers
			);

			return $normalized;

		} catch ( \Throwable $e ) {
			return new \WP_Error(
				'ai_client_exception',
				'WP AI Client error: ' . $e->getMessage(),
				array( 'exception' => $e )
			);
		}
	}

	public function get_capabilities(): array {
		// WP AI Client is the modern standard — assume rich support when present.
		return array(
			'text_generation'   => true,
			'json_schema'       => true,
			'multimodal'        => true,   // images, etc. via with_file / modalities
			'streaming'         => true,   // when the builder supports it
			'token_usage'       => true,
			'provider_metadata' => true,
		);
	}
}
