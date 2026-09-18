<?php
/**
 * Model / provider capability matrix for LLM request shaping.
 *
 * Replaces ad-hoc model-name regexes with ordered rules that describe how
 * tools, reasoning, and JSON Schema dialects interact. Extensible via the
 * {@see 'agentic_model_capability_rules'} and {@see 'agentic_model_capabilities'}
 * filters so new models can be registered without core changes.
 *
 * @package    Agent_Builder
 * @subpackage Includes
 * @since      3.2.4
 */

declare(strict_types=1);

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolve capabilities for a model id + optional provider slug.
 */
final class Model_Capabilities {

	/**
	 * Tool JSON-Schema dialects.
	 */
	public const DIALECT_OPENAI    = 'openai';
	public const DIALECT_GOOGLE    = 'google';
	public const DIALECT_ANTHROPIC = 'anthropic';

	/**
	 * Chat API family for tool calling.
	 */
	public const API_CHAT_COMPLETIONS = 'chat_completions';
	public const API_RESPONSES        = 'responses';

	/**
	 * WordPress option storing runtime-learned "this model rejected tool
	 * schemas" facts — for local/unknown models (e.g. Ollama tags) not yet
	 * covered by a static rule, keyed by "{provider}:{model}".
	 */
	private const LEARNED_UNSUPPORTED_OPTION = 'agent_builder_learned_tools_unsupported';

	/**
	 * Capability defaults (safe baseline for classic GPT-4o / Claude / Gemini chat).
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			// Tools work on the default chat API for this provider.
			'supports_tools'                            => true,
			// Prefer /v1/chat/completions unless a rule says otherwise.
			'preferred_tools_api'                       => self::API_CHAT_COMPLETIONS,
			// If true and tools are present on Chat Completions, force reasoning_effort=none.
			'requires_reasoning_effort_none_with_tools' => false,
			// Schema dialect for tool parameters.
			'tool_schema_dialect'                       => self::DIALECT_OPENAI,
			// Keys to strip from tool parameter schemas (recursive).
			'schema_strip_keys'                         => array(
				'sanitize_callback',
				'validate_callback',
				'$schema',
				'$id',
			),
			// Whether additionalProperties is allowed in tool parameter schemas.
			'allows_additional_properties'              => true,
			// Human-readable notes (debug / admin).
			'notes'                                     => '',
		);
	}

	/**
	 * Ordered capability rules. First match wins.
	 *
	 * Each rule:
	 * - match: 'exact' | 'prefix' | 'regex' | 'provider' | 'default'
	 * - value: string|string[] depending on match type
	 * - provider: optional provider slug constraint
	 * - caps: partial capability overrides
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function rules(): array {
		$rules = array(
			// ── Exact known troublemakers ───────────────────────────────────
			array(
				'match' => 'exact',
				'value' => array( 'gpt-5.6-sol', 'gpt-5.6-sol-2025', 'gpt-5-sol' ),
				'caps'  => array(
					'requires_reasoning_effort_none_with_tools' => true,
					'preferred_tools_api' => self::API_CHAT_COMPLETIONS,
					'notes'               => 'OpenAI sol models reject tools unless reasoning_effort=none on Chat Completions.',
				),
			),

			// ── Prefix families (OpenAI reasoning / GPT-5) ──────────────────
			array(
				'match' => 'prefix',
				'value' => array( 'gpt-5', 'o1', 'o3', 'o4' ),
				'caps'  => array(
					'requires_reasoning_effort_none_with_tools' => true,
					'notes' => 'Reasoning-class OpenAI models: force reasoning_effort=none when using tools on Chat Completions.',
				),
			),

			// ── Regex catch-alls for future naming ──────────────────────────
			array(
				'match' => 'regex',
				'value' => '/(^|[-_])(sol|reasoning)([-_]|$)/i',
				'caps'  => array(
					'requires_reasoning_effort_none_with_tools' => true,
					'notes' => 'Name contains sol/reasoning — treat as tools-incompatible with default reasoning effort.',
				),
			),

			// ── DeepSeek (OpenAI-compatible; reasoner/v4-pro use thinking) ──
			array(
				'match' => 'provider',
				'value' => 'deepseek',
				'caps'  => array(
					'tool_schema_dialect' => self::DIALECT_OPENAI,
					// reasoner models may not support tools; do not force OpenAI-style none.
					'requires_reasoning_effort_none_with_tools' => false,
					'notes'               => 'DeepSeek Chat Completions API (OpenAI-compatible).',
				),
			),

			// ── Kimi / Moonshot (OpenAI-compatible; K3 always thinks) ───────
			array(
				'match' => 'provider',
				'value' => 'kimi',
				'caps'  => array(
					'tool_schema_dialect' => self::DIALECT_OPENAI,
					// Do not force reasoning_effort=none — K3 always uses thinking mode.
					'requires_reasoning_effort_none_with_tools' => false,
					'notes'               => 'Moonshot/Kimi Chat Completions API (OpenAI-compatible).',
				),
			),

			// ── Provider dialects ───────────────────────────────────────────
			array(
				'match' => 'provider',
				'value' => 'google',
				'caps'  => array(
					'tool_schema_dialect'          => self::DIALECT_GOOGLE,
					'allows_additional_properties' => false,
					'schema_strip_keys'            => array(
						'additionalProperties',
						'sanitize_callback',
						'validate_callback',
						'$schema',
						'$id',
						'examples',
						'example',
						'default',
						'title',
						'$ref',
						'$defs',
						'definitions',
						'oneOf',
						'anyOf',
						'allOf',
						'not',
						'const',
					),
					'notes'                        => 'Gemini functionDeclarations: no additionalProperties / PHP junk.',
				),
			),
			array(
				'match' => 'provider',
				'value' => 'anthropic',
				'caps'  => array(
					'tool_schema_dialect' => self::DIALECT_ANTHROPIC,
					'notes'               => 'Anthropic tool use input_schema dialect.',
				),
			),

			// ── Fallback ───────────────────────────────────────────────────
			array(
				'match' => 'default',
				'value' => '*',
				'caps'  => array(),
			),
		);

		/**
		 * Filter ordered model capability rules (first match wins).
		 *
		 * @param array $rules Rule list.
		 */
		return apply_filters( 'agentic_model_capability_rules', $rules );
	}

	/**
	 * Resolve full capabilities for a model + provider.
	 *
	 * @param string $model    Model id (e.g. gpt-5.6-sol, gemini-2.5-flash).
	 * @param string $provider Provider slug (openai, google, agentic, …).
	 * @return array<string, mixed>
	 */
	public static function for_model( string $model, string $provider = '' ): array {
		$model    = strtolower( trim( $model ) );
		$provider = sanitize_key( $provider );
		$caps     = self::defaults();

		// Apply all matching rules in order (later more specific can be first — first match for exact/prefix/regex, provider merges).
		// Strategy: merge every matching provider rule + first model-id rule (exact > prefix > regex).
		$model_rule_applied = false;

		foreach ( self::rules() as $rule ) {
			$type = $rule['match'] ?? '';
			$val  = $rule['value'] ?? '';
			$prov = isset( $rule['provider'] ) ? sanitize_key( (string) $rule['provider'] ) : '';

			if ( $prov && $provider && $prov !== $provider ) {
				continue;
			}

			$hit = false;
			switch ( $type ) {
				case 'exact':
					$ids = is_array( $val ) ? $val : array( $val );
					$ids = array_map( 'strtolower', $ids );
					$hit = in_array( $model, $ids, true );
					if ( $hit && $model_rule_applied ) {
						$hit = false;
					}
					if ( $hit ) {
						$model_rule_applied = true;
					}
					break;

				case 'prefix':
					if ( $model_rule_applied ) {
						break;
					}
					$prefixes = is_array( $val ) ? $val : array( $val );
					foreach ( $prefixes as $prefix ) {
						$prefix = strtolower( (string) $prefix );
						if ( '' !== $prefix && str_starts_with( $model, $prefix ) ) {
							$hit                = true;
							$model_rule_applied = true;
							break;
						}
					}
					break;

				case 'regex':
					if ( $model_rule_applied ) {
						break;
					}
					$pattern = is_array( $val ) ? ( $val[0] ?? '' ) : (string) $val;
					if ( $pattern && @preg_match( $pattern, $model ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
						$hit                = true;
						$model_rule_applied = true;
					}
					break;

				case 'provider':
					// Provider rules always merge when provider matches (or when rule value matches).
					$providers = is_array( $val ) ? $val : array( $val );
					$providers = array_map( 'sanitize_key', $providers );
					$hit       = $provider && in_array( $provider, $providers, true );
					break;

				case 'default':
					// Only fill gaps — already in defaults.
					$hit = false;
					break;
			}

			if ( $hit && ! empty( $rule['caps'] ) && is_array( $rule['caps'] ) ) {
				$caps = self::merge_caps( $caps, $rule['caps'] );
			}
		}

		// Infer dialect from provider when still default openai.
		if ( 'google' === $provider && self::DIALECT_OPENAI === $caps['tool_schema_dialect'] ) {
			$caps['tool_schema_dialect']          = self::DIALECT_GOOGLE;
			$caps['allows_additional_properties'] = false;
		}
		if ( 'anthropic' === $provider && self::DIALECT_OPENAI === $caps['tool_schema_dialect'] ) {
			$caps['tool_schema_dialect'] = self::DIALECT_ANTHROPIC;
		}

		/**
		 * Filter resolved capabilities for a model.
		 *
		 * @param array  $caps     Capabilities.
		 * @param string $model    Model id.
		 * @param string $provider Provider slug.
		 */
		return apply_filters( 'agentic_model_capabilities', $caps, $model, $provider );
	}

	/**
	 * Whether Chat Completions should force reasoning_effort=none when tools are present.
	 *
	 * @param string $model    Model id.
	 * @param string $provider Provider slug.
	 */
	public static function requires_reasoning_effort_none_with_tools( string $model, string $provider = '' ): bool {
		$caps = self::for_model( $model, $provider );
		return ! empty( $caps['requires_reasoning_effort_none_with_tools'] );
	}

	/**
	 * Whether this model/provider can accept tool/function-call definitions
	 * at all (e.g. tinyllama and some runtime-discovered Ollama tags cannot).
	 * Callers use this to strip tools before the request rather than sending
	 * a shape the model will reject.
	 *
	 * @param string $model    Model id.
	 * @param string $provider Provider slug.
	 */
	public static function supports_tools( string $model, string $provider = '' ): bool {
		if ( isset( self::learned_unsupported()[ self::learned_key( $model, $provider ) ] ) ) {
			return false;
		}
		$caps = self::for_model( $model, $provider );
		return ! isset( $caps['supports_tools'] ) || ! empty( $caps['supports_tools'] );
	}

	/**
	 * Whether a provider's error message indicates it rejected the request
	 * specifically because of the tool/function schema — the common failure
	 * mode for small local models (e.g. some Ollama tags) that were never
	 * built with function-calling support, not a real request/auth/quota
	 * error. Matched loosely against provider wording since each API phrases
	 * this differently and none of them return a dedicated error code for it.
	 *
	 * @param string $error_message Raw error text from the provider response.
	 */
	public static function is_tools_unsupported_error( string $error_message ): bool {
		if ( '' === $error_message ) {
			return false;
		}
		$needle = strtolower( $error_message );
		$patterns = array(
			'does not support tools',
			'does not support function',
			'does not support function calling',
			'tool use is not supported',
			'tools is not supported',
			'tools are not supported',
			'function calling is not supported',
			'functions are not supported',
			'model does not support',
			'no endpoints found that support tool use',
		);
		foreach ( $patterns as $pattern ) {
			if ( str_contains( $needle, $pattern ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Persist that a model/provider combination rejected tool schemas, so
	 * future requests strip tools up front instead of round-tripping a
	 * failed request every time.
	 *
	 * @param string $model    Model id.
	 * @param string $provider Provider slug.
	 */
	public static function mark_tools_unsupported( string $model, string $provider = '' ): void {
		$learned                                    = self::learned_unsupported();
		$learned[ self::learned_key( $model, $provider ) ] = true;
		update_option( self::LEARNED_UNSUPPORTED_OPTION, $learned, false );
	}

	/**
	 * @return array<string, bool>
	 */
	private static function learned_unsupported(): array {
		$learned = get_option( self::LEARNED_UNSUPPORTED_OPTION, array() );
		return is_array( $learned ) ? $learned : array();
	}

	/**
	 * @param string $model    Model id.
	 * @param string $provider Provider slug.
	 */
	private static function learned_key( string $model, string $provider ): string {
		return $provider . ':' . $model;
	}

	/**
	 * Tool schema dialect for this model/provider.
	 *
	 * @param string $model    Model id.
	 * @param string $provider Provider slug.
	 */
	public static function tool_schema_dialect( string $model, string $provider = '' ): string {
		$caps = self::for_model( $model, $provider );
		$d    = (string) ( $caps['tool_schema_dialect'] ?? self::DIALECT_OPENAI );
		return in_array( $d, array( self::DIALECT_OPENAI, self::DIALECT_GOOGLE, self::DIALECT_ANTHROPIC ), true )
			? $d
			: self::DIALECT_OPENAI;
	}

	/**
	 * Apply capability-driven adjustments to an already-built request body.
	 *
	 * @param array  $body     Request body.
	 * @param string $model    Model id.
	 * @param string $provider Provider slug.
	 * @return array
	 */
	public static function apply_to_request_body( array $body, string $model, string $provider = '' ): array {
		$caps      = self::for_model( $model, $provider );
		$tools     = $body['tools'] ?? null;
		$has_tools = ! empty( $tools );

		if ( $has_tools && ! empty( $caps['requires_reasoning_effort_none_with_tools'] ) ) {
			// Chat Completions path: disable reasoning effort so tools work.
			// Future: if preferred_tools_api === responses, route differently instead.
			if ( self::API_RESPONSES === ( $caps['preferred_tools_api'] ?? '' ) ) {
				/**
				 * Filter whether to use the Responses API for tools+reasoning models.
				 * Default false until Responses client path is fully implemented.
				 *
				 * @param bool   $use      Whether to switch API.
				 * @param string $model    Model.
				 * @param string $provider Provider.
				 * @param array  $caps     Capabilities.
				 */
				$use_responses = (bool) apply_filters( 'agentic_use_responses_api_for_tools', false, $model, $provider, $caps );
				if ( $use_responses ) {
					$body['_agentic_preferred_api'] = self::API_RESPONSES;
				} else {
					$body['reasoning_effort'] = 'none';
				}
			} else {
				$body['reasoning_effort'] = 'none';
			}
		}

		return $body;
	}

	/**
	 * Strip schema keys disallowed for this dialect (recursive).
	 *
	 * @param mixed  $node     Schema node.
	 * @param string $model    Model id.
	 * @param string $provider Provider slug.
	 * @return mixed
	 */
	public static function sanitize_schema_node( $node, string $model, string $provider = '' ) {
		if ( ! is_array( $node ) ) {
			return $node;
		}

		$caps  = self::for_model( $model, $provider );
		$strip = $caps['schema_strip_keys'] ?? array();
		if ( ! is_array( $strip ) ) {
			$strip = array();
		}
		if ( empty( $caps['allows_additional_properties'] ) ) {
			$strip[] = 'additionalProperties';
		}
		$strip = array_unique( array_merge( $strip, array( 'sanitize_callback', 'validate_callback' ) ) );

		foreach ( $strip as $key ) {
			unset( $node[ $key ] );
		}

		if ( isset( $node['properties'] ) ) {
			if ( $node['properties'] instanceof \stdClass ) {
				// empty object ok
			} elseif ( is_array( $node['properties'] ) ) {
				$clean = array();
				foreach ( $node['properties'] as $name => $prop ) {
					if ( ! is_string( $name ) || '' === $name ) {
						continue;
					}
					$clean[ $name ] = self::sanitize_schema_node( $prop, $model, $provider );
				}
				$node['properties'] = empty( $clean ) ? new \stdClass() : $clean;
			}
		}

		foreach ( $node as $k => $v ) {
			if ( 'properties' === $k ) {
				continue;
			}
			if ( is_array( $v ) ) {
				$node[ $k ] = self::sanitize_schema_node( $v, $model, $provider );
			}
		}

		return $node;
	}

	/**
	 * Merge capability arrays (list fields replaced, scalars overwritten).
	 *
	 * @param array $base Base caps.
	 * @param array $over Overrides.
	 * @return array
	 */
	private static function merge_caps( array $base, array $over ): array {
		foreach ( $over as $k => $v ) {
			if ( 'schema_strip_keys' === $k && is_array( $v ) && is_array( $base[ $k ] ?? null ) ) {
				$base[ $k ] = array_values( array_unique( array_merge( $base[ $k ], $v ) ) );
			} else {
				$base[ $k ] = $v;
			}
		}
		return $base;
	}
}
