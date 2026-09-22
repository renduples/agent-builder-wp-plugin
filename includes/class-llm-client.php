<?php
/**
 * LLM Client for multiple AI providers.
 *
 * Supports OpenAI, Anthropic, OpenRouter, and other providers.
 *
 * @package    Agent_Builder
 * @subpackage Includes
 * @author     Agent Builder Team <support@agentic-plugin.com>
 * @license    GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 * @link       https://agentic-plugin.com
 * @since      0.1.0
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * LLM client supporting OpenAI, Anthropic, xAI, Google, Mistral, Meta Llama, Cohere, Agentic AI, Ollama,
 * and any custom OpenAI-compatible provider registered in Provider_Registry.
 *
 * Handles chat completions across multiple AI providers.
 */
class LLM_Client {

	/**
	 * LLM provider
	 *
	 * @var string
	 */
	private string $provider;

	/**
	 * API key
	 *
	 * @var string
	 */
	private string $api_key;

	/**
	 * Model to use
	 *
	 * @var string
	 */
	private string $model;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->provider = get_option( 'agent_builder_llm_provider', 'agentic' );

		// Resolve API key and default model from DB-backed provider registry.
		$p             = Provider_Registry::get( $this->provider );
		$this->model   = get_option( 'agent_builder_model', $p['default_model'] ?? 'gemini-2.5-flash' );
		$this->api_key = ( ! $p || 'none' === ( $p['auth_type'] ?? 'bearer' ) )
			? ''
			: ( $p['api_key'] ?? '' );

		// Auto-correct model if it doesn't match the provider (e.g. after switching providers).
		$this->validate_model();
	}

	/**
	 * Get current provider
	 *
	 * @return string
	 */
	public function get_provider(): string {
		return $this->provider;
	}

	/**
	 * Get current model
	 *
	 * @return string
	 */
	public function get_model(): string {
		return $this->model;
	}

	/**
	 * Override the model for the current request
	 *
	 * @param string $model Model identifier.
	 * @return void
	 */
	public function set_model( string $model ): void {
		$this->model = $model;
	}

	/**
	 * Override the provider (and load its stored API key automatically).
	 *
	 * @param string $provider Provider slug (must be registered in Provider_Registry).
	 * @return void
	 */
	public function set_provider( string $provider ): void {
		$this->provider = $provider;
		$p              = Provider_Registry::get( $provider );
		$this->api_key  = ( $p && 'none' !== ( $p['auth_type'] ?? 'bearer' ) ) ? ( $p['api_key'] ?? '' ) : '';
	}

	/**
	 * Override the API key for the current request.
	 *
	 * @param string $api_key API key.
	 * @return void
	 */
	public function set_api_key( string $api_key ): void {
		$this->api_key = $api_key;
	}

	/**
	 * Ensure the current model is valid for the current provider.
	 *
	 * When the provider changes (globally or via per-agent override) without
	 * an explicit model change, the old model (e.g. "gemini-2.5-flash" for
	 * Anthropic) would produce an API error. This method detects the mismatch
	 * and falls back to the provider's default model.
	 *
	 * @return void
	 */
	public function validate_model(): void {
		$p = Provider_Registry::get( $this->provider );
		if ( ! $p || empty( $p['default_model'] ) ) {
			return;
		}

		// Map of req_format → known model prefixes for that format.
		$format_prefixes = array(
			'anthropic' => array( 'claude' ),
			'google'    => array( 'gemini', 'gemma' ),
		);

		$format   = $p['req_format'] ?? 'openai';
		$prefixes = $format_prefixes[ $format ] ?? array();

		// For openai-format providers, check if the model clearly belongs to a different provider family.
		if ( empty( $prefixes ) ) {
			// OpenAI-format providers are diverse (OpenAI, xAI, Mistral, Cohere, Ollama, custom).
			// Only auto-correct if the model clearly belongs to Anthropic or Google format.
			$foreign_prefixes = array_merge( ...array_values( $format_prefixes ) );
			$model_lower      = strtolower( $this->model );
			foreach ( $foreign_prefixes as $fp ) {
				if ( str_starts_with( $model_lower, $fp ) ) {
					$this->model = $p['default_model'];
					return;
				}
			}
			return;
		}

		// For Anthropic/Google format providers, verify the model matches at least one known prefix.
		$model_lower = strtolower( $this->model );
		foreach ( $prefixes as $prefix ) {
			if ( str_starts_with( $model_lower, $prefix ) ) {
				return; // Model is compatible.
			}
		}

		// Model doesn't match — use provider's default.
		$this->model = $p['default_model'];
	}

	/**
	 * Return the currently preferred AI Client Adapter (WP 7.0+ native or our legacy bridge).
	 * This is the integration point for the new pluggable AI substrate layer.
	 *
	 * @return AI_Client_Adapter|null
	 */
	public function get_ai_adapter(): ?AI_Client_Adapter {
		// The registry is populated ultra-early in the main plugin file.
		if ( class_exists( '\\Agentic\\AI_Client_Registry' ) ) {
			return AI_Client_Registry::get_preferred();
		}
		return null;
	}

	/**
	 * Check if the client is configured
	 *
	 * @return bool
	 */
	public function is_configured(): bool {
		if ( class_exists( __NAMESPACE__ . '\\Emergency_Stop' ) && Emergency_Stop::is_active() ) {
			return false;
		}
		$p = Provider_Registry::get( $this->provider );
		if ( ! $p ) {
			return false;
		}
		// URL-based provider (Ollama) — configured when a non-empty endpoint is stored in the providers table.
		if ( 'none' === $p['auth_type'] ) {
			return ! empty( $p['endpoint'] );
		}
		return ! empty( $this->api_key );
	}

	/**
	 * Send a chat completion request
	 *
	 * @param array $messages       Conversation messages.
	 * @param array $tools          Available tools.
	 * @param bool  $force_tool_use Force tool use mode.
	 * @return array|\WP_Error Response or error.
	 */
	public function chat( array $messages, array $tools = array(), bool $force_tool_use = false ): array|\WP_Error {
		// Forward-compatible: route to a WordPress-native AI runtime when one is
		// available and selected. Dormant by default (returns null → falls through).
		$core_ai = Core_AI_Adapter::maybe_handle( $this->provider, $messages, $tools, $force_tool_use );
		if ( null !== $core_ai ) {
			return $core_ai;
		}

		if ( ! $this->is_configured() ) {
			return new \WP_Error( 'not_configured', 'LLM API key not configured.' );
		}

		// The hosted relay meters by license key and rejects requests without one
		// ("missing license key"). Say so here, with the fix, instead of letting
		// the relay's error surface in chat (#135).
		if ( 'agentic' === $this->provider && '' === (string) get_option( 'agent_builder_license_key', '' ) ) {
			return new \WP_Error(
				'agentic_license_missing',
				__( 'The hosted Agentic AI connection has no license key, so requests would be rejected. Reconnect from Agent Builder → Quick Start, or add your own provider key under Settings → Providers.', 'agent-builder' )
			);
		}

		// Models that cannot tool-call (tinyllama, runtime-discovered Ollama tags, …):
		// strip tools before the request so agents still get a text reply (D8).
		if ( ! empty( $tools ) && ! Model_Capabilities::supports_tools( (string) $this->model, (string) $this->provider ) ) {
			$tools          = array();
			$force_tool_use = false;
		}

		// Get endpoint and headers for the provider.
		$endpoint = $this->get_endpoint();
		$headers  = $this->get_headers();

		// Strip any internal metadata keys (e.g. _ability_name) before sending to the LLM,
		// and normalise empty `properties` arrays to objects so json_encode emits `{}`
		// instead of `[]` (required by xAI, Anthropic, and other strict APIs).
		$clean_tools = array_map(
			static function ( array $tool ): array {
				$tool = array_filter(
					$tool,
					static fn( $key ) => ! str_starts_with( $key, '_' ),
					ARRAY_FILTER_USE_KEY
				);
				// Fix empty properties: [] → stdClass so JSON serialises as {}.
				if ( isset( $tool['function']['parameters']['properties'] )
					&& is_array( $tool['function']['parameters']['properties'] )
					&& empty( $tool['function']['parameters']['properties'] )
				) {
					$tool['function']['parameters']['properties'] = new \stdClass();
				}
				return $tool;
			},
			(array) $tools
		);

		// Remember whether tools were sent so a provider rejection can retry once
		// without tools (unknown local models not yet in the capability rules).
		$sent_tools = ! empty( $clean_tools );

		$body = $this->format_request( $messages, $clean_tools, $force_tool_use );

		if ( is_wp_error( $endpoint ) ) {
			return $endpoint;
		}

		// Agentic AI runs on constrained hardware — use a longer timeout.
		// Override with: add_filter( 'agentic_llm_request_timeout', fn() => 60, 10, 2 ).
		$default_timeout = ( 'agentic' === $this->provider ) ? 300 : 120;
		$timeout         = (int) apply_filters( 'agentic_llm_request_timeout', $default_timeout, $this->provider );

		$request_args = array(
			'headers' => $headers,
			'body'    => wp_json_encode( $body ),
			'timeout' => $timeout,
		);

		// One retry on transient network errors or 5xx responses (not 4xx which are permanent).
		$response = wp_remote_post( $endpoint, $request_args );
		if ( is_wp_error( $response ) || in_array( (int) wp_remote_retrieve_response_code( $response ), array( 500, 502 ), true ) ) {
			sleep( 1 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rand_rand -- Short deliberate backoff before retry.
			$response = wp_remote_post( $endpoint, $request_args );
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );

		// Handle errors BEFORE normalization so provider error payloads aren't lost.
		if ( 200 !== $status ) {
			$raw_body      = wp_remote_retrieve_body( $response );
			$error_message = '';

			// phpcs:disable Squiz.PHP.CommentedOutCode.Found -- JSON error format examples for documentation, not commented-out code.
			// Try to extract error message from various provider formats.
			// Google: {"error":{"code":500,"message":"...","status":"INTERNAL"}}
			// OpenAI: {"error":{"message":"...","type":"...","code":"..."}}
			// Anthropic: {"type":"error","error":{"type":"...","message":"..."}}
			// Ollama/generic: {"error":"..."} or {"message":"..."}.
			// phpcs:enable Squiz.PHP.CommentedOutCode.Foundsage":"..."}}
			// Ollama/generic: {"error":"..."} or {"message":"..."}.
			// phpcs:enable Squiz.PHP.CommentedOutCode.Found
			if ( isset( $data['error']['message'] ) ) {
				$error_message = $data['error']['message'];
			} elseif ( isset( $data['error'] ) && is_string( $data['error'] ) ) {
				$error_message = $data['error'];
			} elseif ( isset( $data['message'] ) ) {
				$error_message = $data['message'];
			}

			// Add status code context for common HTTP errors.
			if ( 401 === $status ) {
				$error_message = 'Invalid API key. Please check your API key in Settings > Agentic.';
			} elseif ( 402 === $status || 429 === $status ) {
				// Pass through the server's own error message; use a generic fallback only if absent.
				$user_msg = ! empty( $error_message ) ? $error_message : (
					402 === $status
						? __( 'The AI service requires payment or additional credits to continue.', 'agent-builder' )
						: __( 'Rate limit reached. Please wait a moment and try again.', 'agent-builder' )
				);
				return new \WP_Error(
					402 === $status ? 'insufficient_credits' : 'quota_exceeded',
					$user_msg,
					array(
						'status'      => $status,
						'user_facing' => true,
					)
				);
			} elseif ( 503 === $status ) {
				$retry_after   = (int) ( $data['retry_after'] ?? 10 );
				$error_message = ! empty( $error_message ) ? $error_message : __( 'The AI model is currently busy. Please try again shortly.', 'agent-builder' );
				return new \WP_Error(
					'model_busy',
					$error_message,
					array(
						'status'      => 503,
						'retry_after' => $retry_after,
						'retriable'   => true,
					)
				);
			}

			// Fallback: always include provider, model, and HTTP status so the error is actionable.
			if ( empty( $error_message ) ) {
				$snippet       = mb_substr( trim( $raw_body ), 0, 200 );
				$error_message = sprintf(
					'%s/%s returned HTTP %d: %s',
					$this->provider,
					$this->model,
					$status,
					! empty( $snippet ) ? $snippet : '(empty response body)'
				);
			}

			// Provider rejected tool schemas (common for small Ollama models): retry once without tools.
			if ( $sent_tools && Model_Capabilities::is_tools_unsupported_error( (string) $error_message ) ) {
				Model_Capabilities::mark_tools_unsupported( (string) $this->model, (string) $this->provider );
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log(
					sprintf(
						'Agentic LLM [%s/%s] does not support tools — retrying without tool schemas.',
						$this->provider,
						$this->model
					)
				);
				return $this->chat( $messages, array(), false );
			}

			// Log the full response and request body for debugging.
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf( 'Agentic LLM error [%s/%s] HTTP %d: %s', $this->provider, $this->model, $status, $raw_body ) );
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf( 'Agentic LLM request body [%s/%s]: %s', $this->provider, $this->model, wp_json_encode( $body ) ) );

			return new \WP_Error(
				'api_error',
				$error_message,
				array(
					'status'   => $status,
					'provider' => $this->provider,
					'model'    => $this->model,
					'body'     => $data,
				)
			);
		}

		// Normalize provider-specific response formats to OpenAI-compatible structure.
		$agentic_resp_format = Provider_Registry::get( $this->provider )['resp_format'] ?? 'openai';
		if ( 'cohere' === $agentic_resp_format ) {
			$data = $this->normalize_cohere_response( $data );
		} elseif ( 'agentic_ollama' === $agentic_resp_format ) {
			$data = $this->normalize_agentic_response( $data );
		} elseif ( 'google' === $agentic_resp_format ) {
			$data = $this->normalize_google_response( $data );
		} elseif ( 'anthropic' === $agentic_resp_format ) {
			$data = $this->normalize_anthropic_response( $data );
		}

		return $data;
	}

	/**
	 * Stream a chat completion, invoking $on_token for each text token as it arrives.
	 *
	 * Uses WordPress's http_api_curl action to intercept the cURL handle and set
	 * CURLOPT_WRITEFUNCTION so SSE chunks are processed in real time rather than
	 * buffered until the request completes.
	 *
	 * Returns a normalized response with the same shape as chat() so the caller can
	 * use it interchangeably in the tool loop.
	 *
	 * @param array    $messages       Conversation messages.
	 * @param callable $on_token       Invoked per text token: fn(string $token): void.
	 * @param array    $tools          Available tools.
	 * @param bool     $force_tool_use Force tool use on this call.
	 * @return array|\WP_Error Normalized response or error.
	 */
	public function stream_chat(
		array $messages,
		callable $on_token,
		array $tools = array(),
		bool $force_tool_use = false
	): array|\WP_Error {
		// Forward-compatible: when a WordPress-native AI runtime handles this
		// request, it has no token stream of its own, so emit the full assistant
		// text once through the callback for consumer parity with chat(). Dormant
		// by default (maybe_handle() returns null → falls through).
		$core_ai = Core_AI_Adapter::maybe_handle( $this->provider, $messages, $tools, $force_tool_use );
		if ( null !== $core_ai ) {
			if ( ! is_wp_error( $core_ai ) ) {
				$core_text = (string) ( $core_ai['choices'][0]['message']['content'] ?? '' );
				if ( '' !== $core_text ) {
					$on_token( $core_text );
				}
			}
			return $core_ai;
		}

		if ( ! $this->is_configured() ) {
			return new \WP_Error( 'not_configured', 'LLM API key not configured.' );
		}

		// The hosted relay meters by license key and rejects requests without one
		// ("missing license key"). Say so here, with the fix, instead of letting
		// the relay's error surface in chat (#135).
		if ( 'agentic' === $this->provider && '' === (string) get_option( 'agent_builder_license_key', '' ) ) {
			return new \WP_Error(
				'agentic_license_missing',
				__( 'The hosted Agentic AI connection has no license key, so requests would be rejected. Reconnect from Agent Builder → Quick Start, or add your own provider key under Settings → Providers.', 'agent-builder' )
			);
		}

		// Same tool stripping as chat() for models without tool support (D8).
		if ( ! empty( $tools ) && ! Model_Capabilities::supports_tools( (string) $this->model, (string) $this->provider ) ) {
			$tools          = array();
			$force_tool_use = false;
		}

		$p           = Provider_Registry::get( $this->provider );
		$resp_format = $p['resp_format'] ?? 'openai';

		// Clean tools — same transform as chat().
		$clean_tools = array_map(
			static function ( array $tool ): array {
				$tool = array_filter(
					$tool,
					static fn( $key ) => ! str_starts_with( $key, '_' ),
					ARRAY_FILTER_USE_KEY
				);
				if ( isset( $tool['function']['parameters']['properties'] )
					&& is_array( $tool['function']['parameters']['properties'] )
					&& empty( $tool['function']['parameters']['properties'] )
				) {
					$tool['function']['parameters']['properties'] = new \stdClass();
				}
				return $tool;
			},
			$tools
		);

		$endpoint = $this->get_endpoint();
		if ( is_wp_error( $endpoint ) ) {
			return $endpoint;
		}

		$headers = $this->get_headers();
		$body    = $this->format_request( $messages, $clean_tools, $force_tool_use );

		// Enable server-side streaming for each provider format.
		if ( 'google' === $resp_format ) {
			// Google uses a different URL suffix and SSE alt param.
			$endpoint = str_replace( ':generateContent', ':streamGenerateContent', $endpoint );
			if ( ! str_contains( $endpoint, 'alt=sse' ) ) {
				$endpoint .= ( str_contains( $endpoint, '?' ) ? '&' : '?' ) . 'alt=sse';
			}
		} elseif ( 'agentic_ollama' === $resp_format ) {
			// Native Ollama: flip the stream flag that format_request() sets to false.
			$body['stream'] = true;
		} else {
			// OpenAI-compatible and Anthropic both accept stream: true in the body.
			$body['stream'] = true;
		}

		$default_timeout = ( 'agentic' === $this->provider ) ? 300 : 120;
		$timeout         = (int) apply_filters( 'agentic_llm_request_timeout', $default_timeout, $this->provider );

		// Streaming state — captured by both the filter closure and the WRITEFUNCTION.
		$stream_buf     = '';
		$acc_text       = '';
		$tool_calls_raw = array();
		$finish_reason  = 'stop';
		$usage          = array(
			'prompt_tokens'     => 0,
			'completion_tokens' => 0,
			'total_tokens'      => 0,
		);

		$curl_hook = function ( $handle ) use (
			&$stream_buf,
			$on_token,
			&$acc_text,
			&$tool_calls_raw,
			&$finish_reason,
			&$usage,
			$resp_format
		) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Required for SSE streaming; wp_remote_get() does not support streaming callbacks.
			curl_setopt(
				$handle,
				CURLOPT_WRITEFUNCTION,
				function (
					$ch,
					$data
				) use (
					&$stream_buf,
					$on_token,
					&$acc_text,
					&$tool_calls_raw,
					&$finish_reason,
					&$usage,
					$resp_format
				) {
					$stream_buf .= $data;

					// Consume complete SSE frames (separated by double newline).
					// phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- Intentional assignment for stream frame parsing (idiomatic and safe here).
					while ( ( $pos = strpos( $stream_buf, "\n\n" ) ) !== false ) {
						$frame      = substr( $stream_buf, 0, $pos );
						$stream_buf = substr( $stream_buf, $pos + 2 );

						foreach ( explode( "\n", $frame ) as $raw_line ) {
							$raw_line = ltrim( $raw_line, "\r" );
							if ( ! str_starts_with( $raw_line, 'data:' ) ) {
								continue;
							}
							$json_str = ltrim( substr( $raw_line, 5 ) );
							if ( '[DONE]' === $json_str ) {
								continue;
							}
							$chunk = json_decode( $json_str, true );
							if ( ! is_array( $chunk ) ) {
								continue;
							}

							if ( 'anthropic' === $resp_format ) {
								$this->parse_anthropic_stream_chunk( $chunk, $on_token, $acc_text, $tool_calls_raw, $finish_reason, $usage );
							} elseif ( 'google' === $resp_format ) {
								$this->parse_google_stream_chunk( $chunk, $on_token, $acc_text, $tool_calls_raw, $finish_reason, $usage );
							} elseif ( 'agentic_ollama' === $resp_format ) {
								$token = $chunk['message']['content'] ?? '';
								if ( '' !== $token ) {
									$acc_text .= $token;
									$on_token( $token );
								}
								// Capture tool calls if Ollama ever streams them (P0 Item 3 improvement).
								if ( ! empty( $chunk['message']['tool_calls'] ) ) {
									$tool_calls_raw = array_merge( $tool_calls_raw ?: array(), $chunk['message']['tool_calls'] );
									$finish_reason  = 'tool_calls';
								}
								if ( ! empty( $chunk['done'] ) ) {
									$usage['prompt_tokens']     = $chunk['prompt_eval_count'] ?? 0;
									$usage['completion_tokens'] = $chunk['eval_count'] ?? 0;
									// Map Ollama done_reason so truncation is visible (parity with
									// OpenAI/Anthropic/Google stream parsers). Never override tool_calls.
									if ( 'tool_calls' !== $finish_reason ) {
										$done_reason = (string) ( $chunk['done_reason'] ?? '' );
										if ( in_array( $done_reason, array( 'length', 'max_tokens' ), true ) ) {
											$finish_reason = 'length';
										} elseif ( '' !== $done_reason && 'stop' !== $finish_reason ) {
											$finish_reason = ( 'stop' === $done_reason ) ? 'stop' : $finish_reason;
										}
									}
								}
							} else {
								$this->parse_openai_stream_chunk( $chunk, $on_token, $acc_text, $tool_calls_raw, $finish_reason, $usage );
							}
						}
					}

					return strlen( $data );
				}
			);
		};

		add_action( 'http_api_curl', $curl_hook );

		$response = wp_remote_post(
			$endpoint,
			array(
				'headers' => $headers,
				'body'    => wp_json_encode( $body ),
				'timeout' => $timeout,
			)
		);

		remove_action( 'http_api_curl', $curl_hook );

		// Drain any remaining SSE data in $stream_buf not terminated by \n\n.
		// The agentic/Google proxy sometimes closes the connection without a trailing
		// double-newline, leaving the final frame unparsed and $acc_text empty.
		$raw_error_body = $stream_buf; // preserve for non-200 error reporting below.
		if ( '' !== $stream_buf ) {
			foreach ( explode( "\n", $stream_buf ) as $raw_line ) {
				$raw_line = ltrim( $raw_line, "\r" );
				if ( ! str_starts_with( $raw_line, 'data:' ) ) {
					continue;
				}
				$json_str = ltrim( substr( $raw_line, 5 ) );
				if ( '[DONE]' === $json_str ) {
					continue;
				}
				$chunk = json_decode( $json_str, true );
				if ( ! is_array( $chunk ) ) {
					continue;
				}
				if ( 'anthropic' === $resp_format ) {
					$this->parse_anthropic_stream_chunk( $chunk, $on_token, $acc_text, $tool_calls_raw, $finish_reason, $usage );
				} elseif ( 'google' === $resp_format ) {
					$this->parse_google_stream_chunk( $chunk, $on_token, $acc_text, $tool_calls_raw, $finish_reason, $usage );
				} elseif ( 'agentic_ollama' === $resp_format ) {
					$token = $chunk['message']['content'] ?? '';
					if ( '' !== $token ) {
						$acc_text .= $token;
						$on_token( $token );
					}
					// Capture tool calls if Ollama ever streams them (P0 Item 3 improvement).
					if ( ! empty( $chunk['message']['tool_calls'] ) ) {
						$tool_calls_raw = array_merge( $tool_calls_raw ?: array(), $chunk['message']['tool_calls'] );
						$finish_reason  = 'tool_calls';
					}
					if ( ! empty( $chunk['done'] ) && 'tool_calls' !== $finish_reason ) {
						$done_reason = (string) ( $chunk['done_reason'] ?? '' );
						if ( in_array( $done_reason, array( 'length', 'max_tokens' ), true ) ) {
							$finish_reason = 'length';
						} elseif ( 'stop' === $done_reason ) {
							$finish_reason = 'stop';
						}
					}
				} else {
					$this->parse_openai_stream_chunk( $chunk, $on_token, $acc_text, $tool_calls_raw, $finish_reason, $usage );
				}
			}
			$stream_buf = '';
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status ) {
			$body         = wp_remote_retrieve_body( $response );
			$raw          = $body ? $body : $raw_error_body;
			$err          = json_decode( $raw, true );
			$msg          = $err['error']['message'] ?? ( $err['error'] ?? (string) $raw );
			$fallback_msg = $msg ? $msg : 'Streaming request failed.';
			return new \WP_Error( 'api_error', $fallback_msg, array( 'status' => $status ) );
		}

		if ( 0 === $usage['total_tokens'] ) {
			$usage['total_tokens'] = $usage['prompt_tokens'] + $usage['completion_tokens'];
		}

		// Build normalized response identical in shape to chat().
		$message = array(
			'role'    => 'assistant',
			'content' => $acc_text,
		);
		$tc_list = array();
		foreach ( $tool_calls_raw as $tc ) {
			$tc_id     = ! empty( $tc['id'] ) ? $tc['id'] : ( 'call_s_' . count( $tc_list ) );
			$tool_call = array(
				'id'       => $tc_id,
				'type'     => 'function',
				'function' => array(
					'name'      => $tc['name'],
					'arguments' => $tc['arguments_str'],
				),
			);
			if ( ! empty( $tc['thought_signature'] ) ) {
				$tool_call['thought_signature'] = $tc['thought_signature'];
			}
			$tc_list[] = $tool_call;
		}
		if ( ! empty( $tc_list ) ) {
			$message['tool_calls'] = $tc_list;
			$finish_reason         = 'tool_calls';
		}

		return array(
			'choices' => array(
				array(
					'message'       => $message,
					'finish_reason' => $finish_reason,
				),
			),
			'usage'   => $usage,
		);
	}

	/**
	 * Parse an Anthropic SSE chunk into streaming accumulators.
	 *
	 * @param array    $chunk          Decoded JSON chunk.
	 * @param callable $on_token       Text-token callback.
	 * @param string   $acc_text       Accumulated text (by reference).
	 * @param array    $tool_calls_raw Accumulated tool call state (by reference).
	 * @param string   $finish_reason  Finish reason (by reference).
	 * @param array    $usage          Token usage (by reference).
	 * @return void
	 */
	private function parse_anthropic_stream_chunk(
		array $chunk,
		callable $on_token,
		string &$acc_text,
		array &$tool_calls_raw,
		string &$finish_reason,
		array &$usage
	): void {
		$type = $chunk['type'] ?? '';

		if ( 'content_block_delta' === $type ) {
			$delta = $chunk['delta'] ?? array();
			if ( 'text_delta' === ( $delta['type'] ?? '' ) ) {
				$token = $delta['text'] ?? '';
				if ( '' !== $token ) {
					$acc_text .= $token;
					$on_token( $token );
				}
			} elseif ( 'input_json_delta' === ( $delta['type'] ?? '' ) ) {
				$idx = $chunk['index'] ?? 0;
				if ( ! isset( $tool_calls_raw[ $idx ] ) ) {
					$tool_calls_raw[ $idx ] = array(
						'id'            => 'toolu_' . $idx,
						'name'          => '',
						'arguments_str' => '',
					);
				}
				$tool_calls_raw[ $idx ]['arguments_str'] .= ( $delta['partial_json'] ?? '' );
			}
		} elseif ( 'content_block_start' === $type ) {
			$block = $chunk['content_block'] ?? array();
			if ( 'tool_use' === ( $block['type'] ?? '' ) ) {
				$idx                    = $chunk['index'] ?? count( $tool_calls_raw );
				$tool_calls_raw[ $idx ] = array(
					'id'            => $block['id'] ?? ( 'toolu_' . $idx ),
					'name'          => $block['name'] ?? '',
					'arguments_str' => '',
				);
			}
		} elseif ( 'message_start' === $type ) {
			$usage['prompt_tokens'] = $chunk['message']['usage']['input_tokens'] ?? 0;
		} elseif ( 'message_delta' === $type ) {
			$usage['completion_tokens'] = $chunk['usage']['output_tokens'] ?? 0;
			// Mirror normalize_anthropic_response()'s stop_reason mapping so
			// streamed and non-streamed calls report truncation the same way.
			$stop_reason = $chunk['delta']['stop_reason'] ?? '';
			if ( 'tool_use' === $stop_reason ) {
				$finish_reason = 'tool_calls';
			} elseif ( 'max_tokens' === $stop_reason ) {
				$finish_reason = 'length';
			}
		}
	}

	/**
	 * Parse a Google (Gemini) SSE chunk into streaming accumulators.
	 *
	 * @param array    $chunk          Decoded JSON chunk.
	 * @param callable $on_token       Text-token callback.
	 * @param string   $acc_text       Accumulated text (by reference).
	 * @param array    $tool_calls_raw Accumulated tool call state (by reference).
	 * @param string   $finish_reason  Finish reason (by reference).
	 * @param array    $usage          Token usage (by reference).
	 * @return void
	 */
	private function parse_google_stream_chunk(
		array $chunk,
		callable $on_token,
		string &$acc_text,
		array &$tool_calls_raw,
		string &$finish_reason,
		array &$usage
	): void {
		foreach ( $chunk['candidates'][0]['content']['parts'] ?? array() as $part ) {
			if ( isset( $part['text'] ) ) {
				$token = $part['text'];
				if ( '' !== $token ) {
					$acc_text .= $token;
					$on_token( $token );
				}
			} elseif ( isset( $part['functionCall'] ) ) {
				$idx                    = count( $tool_calls_raw );
				$tool_calls_raw[ $idx ] = array(
					'id'            => 'call_g_' . $idx,
					'name'          => $part['functionCall']['name'] ?? '',
					'arguments_str' => wp_json_encode( ! empty( $part['functionCall']['args'] ) ? $part['functionCall']['args'] : new \stdClass() ),
				);
				// See normalize_google_response() for why this must round-trip verbatim.
				if ( ! empty( $part['thoughtSignature'] ) ) {
					$tool_calls_raw[ $idx ]['thought_signature'] = $part['thoughtSignature'];
				}
				$finish_reason = 'tool_calls';
			}
		}

		// finishReason only appears on the terminal chunk for a candidate — mirror
		// normalize_google_response()'s mapping so streamed and non-streamed calls
		// report truncation (MAX_TOKENS) the same way. Never override an
		// already-detected 'tool_calls' — Gemini can report STOP on the same
		// chunk that carries the functionCall, which is a normal turn-end, not
		// evidence the model finished without needing the tool.
		$chunk_finish = $chunk['candidates'][0]['finishReason'] ?? '';
		if ( '' !== $chunk_finish && 'tool_calls' !== $finish_reason ) {
			$finish_map    = array(
				'STOP'       => 'stop',
				'MAX_TOKENS' => 'length',
				'SAFETY'     => 'content_filter',
			);
			$finish_reason = $finish_map[ $chunk_finish ] ?? $finish_reason;
		}

		if ( isset( $chunk['usageMetadata'] ) ) {
			$usage['prompt_tokens']     = $chunk['usageMetadata']['promptTokenCount'] ?? 0;
			$usage['completion_tokens'] = $chunk['usageMetadata']['candidatesTokenCount'] ?? 0;
		}
	}

	/**
	 * Parse an OpenAI-compatible SSE chunk into streaming accumulators.
	 *
	 * @param array    $chunk          Decoded JSON chunk.
	 * @param callable $on_token       Text-token callback.
	 * @param string   $acc_text       Accumulated text (by reference).
	 * @param array    $tool_calls_raw Accumulated tool call state (by reference).
	 * @param string   $finish_reason  Finish reason (by reference).
	 * @param array    $usage          Token usage (by reference).
	 * @return void
	 */
	private function parse_openai_stream_chunk(
		array $chunk,
		callable $on_token,
		string &$acc_text,
		array &$tool_calls_raw,
		string &$finish_reason,
		array &$usage
	): void {
		$delta = $chunk['choices'][0]['delta'] ?? array();
		$token = $delta['content'] ?? '';
		if ( '' !== $token ) {
			$acc_text .= $token;
			$on_token( $token );
		}

		foreach ( $delta['tool_calls'] ?? array() as $tc_delta ) {
			$idx = $tc_delta['index'] ?? 0;
			if ( ! isset( $tool_calls_raw[ $idx ] ) ) {
				$tool_calls_raw[ $idx ] = array(
					'id'            => '',
					'name'          => '',
					'arguments_str' => '',
				);
			}
			if ( ! empty( $tc_delta['id'] ) ) {
				$tool_calls_raw[ $idx ]['id'] = $tc_delta['id'];
			}
			if ( ! empty( $tc_delta['function']['name'] ) ) {
				$tool_calls_raw[ $idx ]['name'] .= $tc_delta['function']['name'];
			}
			$tool_calls_raw[ $idx ]['arguments_str'] .= $tc_delta['function']['arguments'] ?? '';
		}

		$chunk_finish = $chunk['choices'][0]['finish_reason'] ?? null;
		if ( $chunk_finish ) {
			$finish_reason = $chunk_finish;
		}
		if ( isset( $chunk['usage'] ) ) {
			$usage['prompt_tokens']     = $chunk['usage']['prompt_tokens'] ?? $usage['prompt_tokens'];
			$usage['completion_tokens'] = $chunk['usage']['completion_tokens'] ?? $usage['completion_tokens'];
			$usage['total_tokens']      = $chunk['usage']['total_tokens'] ?? 0;
		}
	}

	/**
	 * Get usage statistics from response
	 *
	 * @param array $response API response.
	 * @return array Usage statistics.
	 */
	public function get_usage( array $response ): array {
		return $response['usage'] ?? array(
			'prompt_tokens'     => 0,
			'completion_tokens' => 0,
			'total_tokens'      => 0,
		);
	}

	/**
	 * Get API endpoint for a specific provider (public version for testing)
	 *
	 * @param string $provider Provider name.
	 * @return string Endpoint URL.
	 */
	public function get_endpoint_for_provider( string $provider ): string {
		$p = Provider_Registry::get( $provider );
		if ( ! $p ) {
			return '';
		}
		$model = get_option( 'agent_builder_model', '' );
		$key   = $p['api_key'] ?? '';
		return Provider_Registry::resolve_endpoint( $p['endpoint'], $model, $key );
	}

	/**
	 * Get headers for a specific provider (public version for testing)
	 *
	 * @param string $provider Provider name.
	 * @param string $api_key  API key to use.
	 * @return array Headers.
	 */
	public function get_headers_for_provider( string $provider, string $api_key ): array {
		$headers   = array(
			'Content-Type'     => 'application/json',
			'X-Plugin-Version' => AGENT_BUILDER_VERSION,
		);
		$p         = Provider_Registry::get( $provider );
		$auth_type = $p['auth_type'] ?? 'bearer';

		switch ( $auth_type ) {
			case 'anthropic':
				$headers['x-api-key']         = $api_key;
				$headers['anthropic-version'] = '2023-06-01';
				break;
			case 'url_key':
			case 'none':
				// No auth header.
				break;
			default: // Bearer auth.
				$headers['Authorization'] = 'Bearer ' . $api_key;
		}

		return $headers;
	}

	/**
	 * Max output tokens to send in generationConfig for Google-dialect requests
	 * (native Google/Gemini and the hosted "agentic" provider, which also uses
	 * req_format=google).
	 *
	 * Without an explicit cap, the hosted proxy reserves credits against the
	 * model's full output ceiling (e.g. 65,536 tokens for gemini-2.5-flash)
	 * rather than realistic usage, causing funded accounts to see spurious
	 * 402s. The default is well above observed real-world usage (~244 tokens
	 * average) but far below the ceiling that inflates the reservation.
	 *
	 * @return int
	 */
	private function google_max_output_tokens(): int {
		/**
		 * Filter the maxOutputTokens sent in generationConfig for Google-dialect
		 * chat requests.
		 *
		 * @param int $max_output_tokens Default max output tokens.
		 */
		return (int) apply_filters( 'agentic_google_max_output_tokens', 8192 );
	}

	/**
	 * Format request body for a specific provider (public version for testing).
	 *
	 * @param string $provider       Provider name.
	 * @param array  $messages       Conversation messages.
	 * @param string $model_override Optional model override.
	 * @return array Formatted request body.
	 */
	public function format_request_for_provider( string $provider, array $messages, string $model_override = '' ): array {
		$p          = Provider_Registry::get( $provider );
		$req_format = $p['req_format'] ?? 'openai';
		$body       = array();

		switch ( $req_format ) {
			case 'anthropic':
				// Anthropic uses different format.
				$system = '';
				$msgs   = array();
				foreach ( $messages as $msg ) {
					if ( 'system' === $msg['role'] ) {
						$system = $msg['content'];
					} else {
						$msgs[] = $msg;
					}
				}
				$default_model      = $p['default_model'] ?? 'claude-3-5-sonnet-20241022';
				$body['model']      = ! empty( $model_override ) ? $model_override : get_option( 'agent_builder_model', $default_model );
				$body['messages']   = $msgs;
				$body['max_tokens'] = 4096;
				if ( ! empty( $system ) ) {
					$body['system'] = $system;
				}
				break;

			case 'google':
				$contents    = array();
				$system_text = '';
				foreach ( $messages as $msg ) {
					if ( 'system' === $msg['role'] ) {
						$system_text = is_string( $msg['content'] ) ? $msg['content'] : '';
						continue;
					}
					$contents[] = array(
						'role'  => 'user' === $msg['role'] ? 'user' : 'model',
						'parts' => array( array( 'text' => is_string( $msg['content'] ) ? $msg['content'] : '' ) ),
					);
				}
				$body['contents'] = $this->merge_consecutive_google_roles( $contents );
				if ( ! empty( $system_text ) ) {
					$body['systemInstruction'] = array( 'parts' => array( array( 'text' => $system_text ) ) );
				}
				$body['generationConfig'] = array( 'maxOutputTokens' => $this->google_max_output_tokens() );
				break;

			default:
				// OpenAI-compatible format (openai, xai, mistral, llama, cohere, agentic, ollama, custom).
				$default_model       = $p['default_model'] ?? 'gpt-4o';
				$body['model']       = ! empty( $model_override ) ? $model_override : get_option( 'agent_builder_model', $default_model );
				$body['messages']    = $messages;
				$body['max_tokens']  = 4096;
				$body['temperature'] = 0.7;
		}

		return $body;
	}

	/**
	 * Get API endpoint for the current provider
	 *
	 * @return string|\WP_Error Endpoint URL or error.
	 */
	private function get_endpoint(): string|\WP_Error {
		$p = Provider_Registry::get( $this->provider );
		if ( ! $p ) {
			return new \WP_Error( 'invalid_provider', 'Invalid LLM provider.' );
		}
		return Provider_Registry::resolve_endpoint( $p['endpoint'], $this->model, $this->api_key );
	}

	/**
	 * Get headers for the current provider
	 *
	 * @return array Headers.
	 */
	private function get_headers(): array {
		$p         = Provider_Registry::get( $this->provider );
		$auth_type = $p['auth_type'] ?? 'bearer';
		$headers   = array(
			'Content-Type'     => 'application/json',
			'X-Plugin-Version' => AGENT_BUILDER_VERSION,
		);

		switch ( $auth_type ) {
			case 'anthropic':
				$headers['x-api-key']         = $this->api_key;
				$headers['anthropic-version'] = '2023-06-01';
				break;
			case 'url_key': // Google — key embedded in URL.
			case 'none':    // Ollama — local, unauthenticated.
				// No auth header.
				break;
			default: // bearer — OpenAI, xAI, Mistral, Llama, Cohere, Agentic AI, and custom providers.
				$headers['Authorization'] = 'Bearer ' . $this->api_key;
		}

		return $headers;
	}

	/**
	 * Format request body for the current provider
	 *
	 * @param array $messages       Conversation messages.
	 * @param array $tools          Available tools.
	 * @param bool  $force_tool_use Force tool use mode.
	 * @return array Formatted request body.
	 */
	private function format_request( array $messages, array $tools, bool $force_tool_use = false ): array {
		$p          = Provider_Registry::get( $this->provider );
		$req_format = $p['req_format'] ?? 'openai';
		$body       = array();

		switch ( $req_format ) {
			case 'anthropic':
				// Anthropic Messages API format with tool calling support.
				$system = '';
				$msgs   = array();
				foreach ( $messages as $msg ) {
					if ( 'system' === $msg['role'] ) {
						$system = is_string( $msg['content'] ) ? $msg['content'] : '';
						continue;
					}

					// Handle tool-call result messages from the controller loop.
					if ( 'tool' === ( $msg['role'] ?? '' ) ) {
						$msgs[] = array(
							'role'    => 'user',
							'content' => array(
								array(
									'type'        => 'tool_result',
									'tool_use_id' => $msg['tool_call_id'] ?? '',
									'content'     => $msg['content'] ?? '',
								),
							),
						);
						continue;
					}

					// Handle assistant messages that contain tool calls.
					if ( 'assistant' === ( $msg['role'] ?? '' ) && ! empty( $msg['tool_calls'] ) ) {
						$blocks = array();
						if ( ! empty( $msg['content'] ) ) {
							$blocks[] = array(
								'type' => 'text',
								'text' => $msg['content'],
							);
						}
						foreach ( $msg['tool_calls'] as $tc ) {
							$blocks[] = array(
								'type'  => 'tool_use',
								'id'    => $tc['id'] ?? ( 'toolu_' . count( $blocks ) ),
								'name'  => $tc['function']['name'] ?? '',
								'input' => json_decode( $tc['function']['arguments'] ?? '{}', true ) ?? new \stdClass(),
							);
						}
						$msgs[] = array(
							'role'    => 'assistant',
							'content' => $blocks,
						);
						continue;
					}

					// Convert multimodal content for Anthropic's format.
					$converted = $this->convert_content_for_anthropic( $msg['content'] );
					$msgs[]    = array(
						'role'    => $msg['role'],
						'content' => $converted,
					);
				}
				$body['model']      = $this->model;
				$body['messages']   = $msgs;
				$body['max_tokens'] = 4096;
				if ( ! empty( $system ) ) {
					$body['system'] = $system;
				}

				// Tool declarations — convert OpenAI tool format to Anthropic format.
				if ( ! empty( $tools ) ) {
					$anthropic_tools = array();
					foreach ( $tools as $tool ) {
						$fn = $tool['function'] ?? array();
						if ( empty( $fn['name'] ) ) {
							continue;
						}
						$tool_def = array(
							'name'        => $fn['name'],
							'description' => $fn['description'] ?? '',
						);
						if ( ! empty( $fn['parameters'] ) ) {
							$tool_def['input_schema'] = $this->normalize_tool_schema( $fn['parameters'] );
						} else {
							$tool_def['input_schema'] = array(
								'type'       => 'object',
								'properties' => new \stdClass(),
							);
						}
						$anthropic_tools[] = $tool_def;
					}
					if ( ! empty( $anthropic_tools ) ) {
						$body['tools']       = $anthropic_tools;
						$body['tool_choice'] = array( 'type' => $force_tool_use ? 'any' : 'auto' );
					}
				}
				break;

			case 'google':
				$contents    = array();
				$system_text = '';
				foreach ( $messages as $msg ) {
					if ( 'system' === $msg['role'] ) {
						$system_text = is_string( $msg['content'] ) ? $msg['content'] : '';
						continue;
					}

					// Handle tool-call result messages from the controller loop.
					if ( 'tool' === ( $msg['role'] ?? '' ) ) {
						$decoded    = json_decode( $msg['content'] ?? '{}', true );
						$contents[] = array(
							'role'  => 'user',
							'parts' => array(
								array(
									'functionResponse' => array(
										'name'     => $msg['name'] ?? $msg['tool_call_id'] ?? 'unknown',
										'response' => is_array( $decoded ) ? $decoded : array( 'result' => $msg['content'] ?? '' ),
									),
								),
							),
						);
						continue;
					}

					// Handle assistant messages that contain tool calls.
					if ( 'assistant' === ( $msg['role'] ?? '' ) && ! empty( $msg['tool_calls'] ) ) {
						$fc_parts = array();
						foreach ( $msg['tool_calls'] as $tc ) {
							$args_json    = $tc['function']['arguments'] ?? '{}';
							$decoded_args = json_decode( $args_json, false );
							$fc_part      = array(
								'functionCall' => array(
									'name' => $tc['function']['name'] ?? '',
									'args' => $decoded_args ? $decoded_args : new \stdClass(),
								),
							);
							// Gemini 3+ requires the thought_signature captured off this exact
							// functionCall part to be replayed verbatim, or the request 400s.
							// Absent for pre-3.x models — nothing to replay, nothing sent.
							if ( ! empty( $tc['thought_signature'] ) ) {
								$fc_part['thoughtSignature'] = $tc['thought_signature'];
							}
							$fc_parts[] = $fc_part;
						}
						if ( ! empty( $msg['content'] ) ) {
							array_unshift( $fc_parts, array( 'text' => $msg['content'] ) );
						}
						$contents[] = array(
							'role'  => 'model',
							'parts' => $fc_parts,
						);
						continue;
					}

					$parts      = $this->convert_content_for_google( $msg['content'] );
					$contents[] = array(
						'role'  => 'user' === $msg['role'] ? 'user' : 'model',
						'parts' => $parts,
					);
				}
				$body['contents'] = $this->merge_consecutive_google_roles( $contents );
				if ( ! empty( $system_text ) ) {
					$body['systemInstruction'] = array( 'parts' => array( array( 'text' => $system_text ) ) );
				}
				// Function declarations — convert OpenAI tool format to Google format.
				if ( ! empty( $tools ) ) {
					$fn_declarations = array();
					foreach ( $tools as $tool ) {
						$fn = $tool['function'] ?? array();
						if ( empty( $fn['name'] ) ) {
							continue;
						}
						$decl = array(
							'name'        => $fn['name'],
							'description' => $fn['description'] ?? '',
						);
						if ( ! empty( $fn['parameters'] ) && is_array( $fn['parameters'] ) ) {
							// Gemini rejects additionalProperties / PHP junk (sanitize_callback, etc.).
							$decl['parameters'] = $this->normalize_tool_schema_for_google( $fn['parameters'] );
						}
						$fn_declarations[] = $decl;
					}
					if ( ! empty( $fn_declarations ) ) {
						$body['tools']      = array( array( 'functionDeclarations' => $fn_declarations ) );
						$body['toolConfig'] = array(
							'functionCallingConfig' => array(
								'mode' => $force_tool_use ? 'ANY' : 'AUTO',
							),
						);
					}
				}
				$body['generationConfig'] = array( 'maxOutputTokens' => $this->google_max_output_tokens() );
				break;

			case 'agentic':
				// Native Ollama-style format (used for smaller/local models via Agentic hosting or direct Ollama).
				// We now enable tool calling with a simplified schema for better reliability on weak models (P0 Item 3).
				$body['model']    = $this->model;
				$body['messages'] = $messages;
				$body['stream']   = false;

				if ( ! empty( $tools ) ) {
					// Use simplified schemas for weaker models (already object-typed).
					$simplified_tools    = $this->simplify_tools_for_weak_models(
						$this->sanitize_tools_for_openai( $tools )
					);
					$body['tools']       = $simplified_tools;
					$body['tool_choice'] = $force_tool_use ? 'required' : 'auto';
				}
				break;

			default:
				// OpenAI-compatible: xAI, Mistral, Llama, Cohere, Ollama, and custom providers.
				$body['model']    = $this->model;
				$body['messages'] = $messages;
				if ( ! empty( $tools ) ) {
					// Sanitize schemas — WP abilities / MCP often ship illegal top-level oneOf/enum.
					$body['tools']       = $this->sanitize_tools_for_openai( $tools );
					$body['tool_choice'] = $force_tool_use ? 'required' : 'auto';
				}
		}

		// Hosted Agentic provider: attach billing identity so the proxy can meter
		// usage by license key. Gated on the provider (not the request format) so a
		// user's own Google/Gemini key never leaks these fields to Google's API.
		if ( 'agentic' === $this->provider ) {
			$body['user_id']  = Provider_Registry::get_license_key();
			$body['site_url'] = home_url();
		}

		// Capability matrix: reasoning_effort, future Responses API, etc.
		// Extensible via agentic_model_capability_rules / agentic_model_capabilities.
		return Model_Capabilities::apply_to_request_body(
			$body,
			(string) ( $body['model'] ?? $this->model ),
			(string) $this->provider
		);
	}

	/**
	 * Normalize a native Ollama /api/chat response to OpenAI-compatible structure.
	 *
	 * Ollama returns: {message: {role, content, tool_calls?}, done: true}
	 * We now properly support tool_calls for better reliability on smaller models (P0 Item 3).
	 *
	 * @param array|null $data Raw Ollama response.
	 * @return array OpenAI-compatible response.
	 */
	private function normalize_agentic_response( ?array $data ): array {
		if ( ! is_array( $data ) || isset( $data['choices'] ) ) {
			return $data ?? array();
		}

		$message = array(
			'role'    => $data['message']['role'] ?? 'assistant',
			'content' => $data['message']['content'] ?? '',
		);

		// Support tool calls returned by Ollama (when available).
		if ( ! empty( $data['message']['tool_calls'] ) ) {
			$openai_tool_calls = array();
			foreach ( $data['message']['tool_calls'] as $tc ) {
				$openai_tool_calls[] = array(
					'id'       => $tc['id'] ?? 'call_' . count( $openai_tool_calls ),
					'type'     => 'function',
					'function' => array(
						'name'      => $tc['function']['name'] ?? '',
						'arguments' => is_string( $tc['function']['arguments'] ?? '' )
							? $tc['function']['arguments']
							: wp_json_encode( $tc['function']['arguments'] ?? new \stdClass() ),
					),
				);
			}
			$message['tool_calls'] = $openai_tool_calls;
		}

		return array(
			'choices' => array(
				array(
					'message'       => $message,
					'finish_reason' => ! empty( $message['tool_calls'] ) ? 'tool_calls' : 'stop',
				),
			),
		);
	}

	/**
	 * Normalize Anthropic Messages API response to OpenAI-compatible format.
	 *
	 * Anthropic Messages format:
	 *   { id, type: "message", role, content: [{type: "text", text}, {type: "tool_use", id, name, input}],
	 *     stop_reason, usage: { input_tokens, output_tokens } }
	 *
	 * Normalized to:
	 *   { choices: [{ message: { role, content, tool_calls? }, finish_reason }],
	 *     usage: { prompt_tokens, completion_tokens, total_tokens } }
	 *
	 * @param array|null $data Raw Anthropic response.
	 * @return array OpenAI-compatible response.
	 */
	private function normalize_anthropic_response( ?array $data ): array {
		if ( ! is_array( $data ) || isset( $data['choices'] ) ) {
			return $data ?? array();
		}

		// Handle error responses.
		if ( 'error' === ( $data['type'] ?? '' ) ) {
			return $data;
		}

		$content_blocks = $data['content'] ?? array();
		$text_parts     = array();
		$tool_calls     = array();

		foreach ( $content_blocks as $block ) {
			if ( 'text' === ( $block['type'] ?? '' ) ) {
				$text_parts[] = $block['text'] ?? '';
			} elseif ( 'tool_use' === ( $block['type'] ?? '' ) ) {
				$tool_calls[] = array(
					'id'       => $block['id'] ?? ( 'call_a_' . count( $tool_calls ) ),
					'type'     => 'function',
					'function' => array(
						'name'      => $block['name'] ?? '',
						'arguments' => wp_json_encode( $block['input'] ?? new \stdClass() ),
					),
				);
			}
		}

		$message = array(
			'role'    => $data['role'] ?? 'assistant',
			'content' => implode( "\n", $text_parts ),
		);

		if ( ! empty( $tool_calls ) ) {
			$message['tool_calls'] = $tool_calls;
		}

		// Map stop_reason to OpenAI finish_reason.
		$stop_reason   = $data['stop_reason'] ?? 'end_turn';
		$finish_reason = match ( $stop_reason ) {
			'tool_use'   => 'tool_calls',
			'max_tokens' => 'length',
			default      => 'stop',
		};

		$input_tokens  = $data['usage']['input_tokens'] ?? 0;
		$output_tokens = $data['usage']['output_tokens'] ?? 0;

		return array(
			'choices' => array(
				array(
					'message'       => $message,
					'finish_reason' => $finish_reason,
				),
			),
			'usage'   => array(
				'prompt_tokens'     => $input_tokens,
				'completion_tokens' => $output_tokens,
				'total_tokens'      => $input_tokens + $output_tokens,
			),
		);
	}

	/**
	 * Normalize Google Gemini API response to OpenAI-compatible format.
	 *
	 * @param array|null $data Raw Google Gemini response.
	 * @return array OpenAI-compatible response.
	 */
	private function normalize_google_response( ?array $data ): array {
		if ( ! is_array( $data ) || isset( $data['choices'] ) ) {
			return $data ?? array();
		}

		$candidate = $data['candidates'][0] ?? array();
		$parts     = $candidate['content']['parts'] ?? array();

		// Separate text parts from functionCall parts.
		$text_parts = array();
		$tool_calls = array();
		$tc_index   = 0;
		foreach ( $parts as $part ) {
			if ( isset( $part['text'] ) ) {
				$text_parts[] = $part['text'];
			} elseif ( isset( $part['functionCall'] ) ) {
				$fc        = $part['functionCall'];
				$tool_call = array(
					'id'       => 'call_g_' . $tc_index,
					'type'     => 'function',
					'function' => array(
						'name'      => $fc['name'] ?? '',
						'arguments' => wp_json_encode( ! empty( $fc['args'] ) ? $fc['args'] : new \stdClass() ),
					),
				);
				// Gemini 3+ attaches an opaque thought_signature to the part carrying the
				// functionCall; it must be replayed verbatim on the next turn or Google
				// returns a 400. Carried as extra metadata — absent for pre-3.x models.
				if ( ! empty( $part['thoughtSignature'] ) ) {
					$tool_call['thought_signature'] = $part['thoughtSignature'];
				}
				$tool_calls[] = $tool_call;
				++$tc_index;
			}
		}
		$text = implode( '', $text_parts );

		$finish_map = array(
			'STOP'       => 'stop',
			'MAX_TOKENS' => 'length',
			'SAFETY'     => 'content_filter',
		);
		$finish     = $finish_map[ $candidate['finishReason'] ?? '' ] ?? 'stop';

		// If the model returned function calls, set finish_reason to 'tool_calls'
		// so the Agent_Controller knows to enter the tool execution loop.
		if ( ! empty( $tool_calls ) ) {
			$finish = 'tool_calls';
		}

		$usage = $data['usageMetadata'] ?? array();

		$message = array(
			'role'    => 'assistant',
			'content' => $text,
		);
		if ( ! empty( $tool_calls ) ) {
			$message['tool_calls'] = $tool_calls;
		}

		return array(
			'choices' => array(
				array(
					'message'       => $message,
					'finish_reason' => $finish,
				),
			),
			'usage'   => array(
				'prompt_tokens'     => $usage['promptTokenCount'] ?? 0,
				'completion_tokens' => $usage['candidatesTokenCount'] ?? 0,
				'total_tokens'      => $usage['totalTokenCount'] ?? 0,
			),
		);
	}

	/**
	 * Normalize a Cohere v2 chat response to OpenAI-compatible structure.
	 *
	 * Cohere returns: {message: {role, content: [{type, text}], tool_calls?}}
	 * OpenAI returns: {choices: [{message: {role, content, tool_calls?}}]}
	 *
	 * @param array $data Raw Cohere response.
	 * @return array OpenAI-compatible response.
	 */
	private function normalize_cohere_response( array $data ): array {
		// Already normalized (or response from error handler).
		if ( isset( $data['choices'] ) ) {
			return $data;
		}

		$content = '';
		if ( isset( $data['message']['content'] ) ) {
			if ( is_array( $data['message']['content'] ) ) {
				foreach ( $data['message']['content'] as $part ) {
					if ( 'text' === ( $part['type'] ?? '' ) ) {
						$content .= $part['text'] ?? '';
					}
				}
			} else {
				$content = (string) $data['message']['content'];
			}
		}

		$message = array(
			'role'    => $data['message']['role'] ?? 'assistant',
			'content' => $content,
		);

		// Cohere v2 tool_calls format matches OpenAI's — pass through as-is.
		if ( ! empty( $data['message']['tool_calls'] ) ) {
			$message['tool_calls'] = $data['message']['tool_calls'];
		}

		$normalized = array(
			'choices' => array(
				array(
					'message'       => $message,
					'finish_reason' => $data['finish_reason'] ?? null,
				),
			),
		);

		if ( isset( $data['usage'] ) ) {
			$in                  = $data['usage']['billed_units']['input_tokens']
				?? $data['usage']['tokens']['input_tokens']
				?? 0;
			$out                 = $data['usage']['billed_units']['output_tokens']
				?? $data['usage']['tokens']['output_tokens']
				?? 0;
			$normalized['usage'] = array(
				'prompt_tokens'     => $in,
				'completion_tokens' => $out,
				'total_tokens'      => $in + $out,
			);
		}

		return $normalized;
	}

	/**
	 * Convert multimodal content to Anthropic's format.
	 *
	 * Supports both data URLs (data:image/...;base64,...) and HTTP URLs.
	 * Anthropic requires inline base64, so HTTP URLs are fetched and encoded.
	 *
	 * @param string|array $content Message content (string or multimodal array).
	 * @return string|array Converted content.
	 */
	private function convert_content_for_anthropic( $content ) {
		if ( is_string( $content ) ) {
			return $content;
		}

		if ( ! is_array( $content ) ) {
			return '';
		}

		$parts = array();
		foreach ( $content as $part ) {
			if ( 'text' === ( $part['type'] ?? '' ) ) {
				$parts[] = array(
					'type' => 'text',
					'text' => $part['text'] ?? '',
				);
			} elseif ( 'image_url' === ( $part['type'] ?? '' ) ) {
				$url        = $part['image_url']['url'] ?? '';
				$media_type = '';
				$b64_data   = '';

				// Try data URL first.
				if ( preg_match( '/^data:(image\/[a-z]+);base64,(.+)$/s', $url, $matches ) ) {
					$media_type = $matches[1];
					$b64_data   = $matches[2];
				} elseif ( str_starts_with( $url, 'http' ) ) {
					// Fetch the image and base64-encode it.
					$response = wp_remote_get( $url, array( 'timeout' => 15 ) );
					if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
						$media_type = wp_remote_retrieve_header( $response, 'content-type' );
						$b64_data   = base64_encode( wp_remote_retrieve_body( $response ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
					}
				}

				if ( $media_type && $b64_data ) {
					$parts[] = array(
						'type'   => 'image',
						'source' => array(
							'type'       => 'base64',
							'media_type' => $media_type,
							'data'       => $b64_data,
						),
					);
				}
			}
		}

		return $parts;
	}

	/**
	 * Convert multimodal content to Google's format.
	 *
	 * Supports both data URLs and HTTP URLs. Google requires inline_data,
	 * so HTTP URLs are fetched and encoded.
	 *
	 * @param string|array $content Message content (string or multimodal array).
	 * @return array Google-format parts.
	 */
	private function convert_content_for_google( $content ) {
		if ( is_string( $content ) ) {
			return array( array( 'text' => $content ) );
		}

		if ( ! is_array( $content ) ) {
			return array( array( 'text' => '' ) );
		}

		$parts = array();
		foreach ( $content as $part ) {
			if ( 'text' === ( $part['type'] ?? '' ) ) {
				$parts[] = array( 'text' => $part['text'] ?? '' );
			} elseif ( 'image_url' === ( $part['type'] ?? '' ) ) {
				$url       = $part['image_url']['url'] ?? '';
				$mime_type = '';
				$b64_data  = '';

				if ( preg_match( '/^data:(image\/[a-z]+);base64,(.+)$/s', $url, $matches ) ) {
					$mime_type = $matches[1];
					$b64_data  = $matches[2];
				} elseif ( str_starts_with( $url, 'http' ) ) {
					$response = wp_remote_get( $url, array( 'timeout' => 15 ) );
					if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
						$mime_type = wp_remote_retrieve_header( $response, 'content-type' );
						$b64_data  = base64_encode( wp_remote_retrieve_body( $response ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
					}
				}

				if ( $mime_type && $b64_data ) {
					$parts[] = array(
						'inline_data' => array(
							'mime_type' => $mime_type,
							'data'      => $b64_data,
						),
					);
				}
			}
		}

		return $parts;
	}

	/**
	 * Merge consecutive messages with the same role for Google API compatibility.
	 *
	 * @param array $contents Array of message content parts.
	 * @return array Merged content array.
	 */
	private function merge_consecutive_google_roles( array $contents ): array {
		$merged   = array();
		$prev_idx = -1;
		foreach ( $contents as $entry ) {
			if ( $prev_idx >= 0 && $merged[ $prev_idx ]['role'] === $entry['role'] ) {
				$merged[ $prev_idx ]['parts'] = array_merge( $merged[ $prev_idx ]['parts'], $entry['parts'] );
			} else {
				$merged[] = $entry;
				++$prev_idx;
			}
		}
		return $merged;
	}

	/**
	 * Sanitize an OpenAI tools array so every function.parameters is a strict object schema.
	 *
	 * @param array $tools Tool definitions.
	 * @return array
	 */
	private function sanitize_tools_for_openai( array $tools ): array {
		$out = array();
		foreach ( $tools as $tool ) {
			if ( ! is_array( $tool ) ) {
				continue;
			}
			$fn = $tool['function'] ?? null;
			if ( ! is_array( $fn ) || empty( $fn['name'] ) ) {
				continue;
			}
			$params = $fn['parameters'] ?? array();
			if ( ! is_array( $params ) ) {
				$params = array();
			}
			$fn['parameters'] = $this->normalize_tool_schema( $params );
			$tool['function'] = $fn;
			// Ensure OpenAI wrapper shape.
			if ( empty( $tool['type'] ) ) {
				$tool['type'] = 'function';
			}
			$out[] = $tool;
		}
		return $out;
	}

	/**
	 * Schema for Google functionDeclarations — stricter than OpenAI.
	 *
	 * Structural normalize first, then dialect strip via Model_Capabilities
	 * (additionalProperties, sanitize_callback, etc.). Rules are filterable.
	 *
	 * @param array $params Raw parameters.
	 * @return array
	 */
	private function normalize_tool_schema_for_google( array $params ): array {
		$schema = $this->normalize_tool_schema( $params, true );
		// Use google dialect even when hosted provider is "agentic" (req_format=google).
		$cleaned = Model_Capabilities::sanitize_schema_node(
			$schema,
			(string) $this->model,
			'google'
		);
		return is_array( $cleaned ) ? $cleaned : array(
			'type'       => 'object',
			'properties' => new \stdClass(),
		);
	}

	/**
	 * Normalize a tool parameter schema into valid JSON Schema for function calling.
	 *
	 * Handles:
	 *  1. Flat property maps (no wrapping type/properties) — wraps them.
	 *  2. Per-property required => true — moves to top-level required array.
	 *  3. Top-level oneOf/anyOf/allOf/enum/const/not — stripped (OpenAI rejects these).
	 *  4. Missing type — forced to object.
	 *  5. PHP/WP junk (sanitize_callback) stripped from properties.
	 *
	 * @param array $params     Raw parameters from get_parameters() or ability input_schema.
	 * @param bool  $for_google When true, never emit additionalProperties (Gemini rejects it).
	 * @return array Valid JSON Schema object.
	 */
	private function normalize_tool_schema( array $params, bool $for_google = false ): array {
		// OpenAI: top-level must be type:object without oneOf/anyOf/allOf/enum/const/not.
		$forbidden_top = array( 'oneOf', 'anyOf', 'allOf', 'enum', 'const', 'not', '$ref', '$defs', 'definitions', 'if', 'then', 'else' );

		// If top-level is a non-object composition (e.g. only oneOf), wrap as a free-form object.
		$top_type        = $params['type'] ?? null;
		$has_composition = false;
		foreach ( array( 'oneOf', 'anyOf', 'allOf' ) as $k ) {
			if ( ! empty( $params[ $k ] ) ) {
				$has_composition = true;
				break;
			}
		}

		if ( $has_composition && ( null === $top_type || 'object' !== $top_type ) ) {
			// Flatten: empty properties so the call can still proceed.
			// OpenAI allows additionalProperties; Gemini does not — omit for Google.
			$out = array(
				'type'       => 'object',
				'properties' => new \stdClass(),
			);
			if ( ! $for_google ) {
				$out['additionalProperties'] = true;
			}
			return $out;
		}

		// Already properly wrapped.
		if ( isset( $params['type'] ) && 'object' === $params['type'] && isset( $params['properties'] ) ) {
			$props = $params['properties'];
		} elseif ( isset( $params['properties'] ) ) {
			$props = $params['properties'];
		} elseif ( $has_composition ) {
			$props = array();
		} else {
			// Flat property map — wrap it (but not if keys look like schema keywords only).
			$schema_keys = array( 'type', 'required', 'additionalProperties', 'description', 'title', '$schema' );
			$looks_flat  = false;
			foreach ( $params as $k => $_ ) {
				if ( ! in_array( $k, $schema_keys, true ) && ! in_array( $k, $forbidden_top, true ) ) {
					$looks_flat = true;
					break;
				}
			}
			$props = $looks_flat ? $params : ( $params['properties'] ?? array() );
		}

		// Convert stdClass to empty array.
		if ( $props instanceof \stdClass ) {
			return array(
				'type'       => 'object',
				'properties' => new \stdClass(),
			);
		}
		if ( ! is_array( $props ) ) {
			$props = array();
		}

		$required    = array();
		$clean_props = array();

		// Prefer explicit top-level required array when present.
		if ( ! empty( $params['required'] ) && is_array( $params['required'] ) ) {
			$required = array_values(
				array_filter(
					$params['required'],
					static function ( $r ) {
						return is_string( $r ) && '' !== $r;
					}
				)
			);
		}

		foreach ( $props as $name => $def ) {
			if ( ! is_string( $name ) || '' === $name ) {
				continue;
			}
			// Skip schema keywords if a flat map mixed them in.
			if ( in_array( $name, $forbidden_top, true ) || in_array( $name, array( 'type', 'required', 'additionalProperties', '$schema' ), true ) ) {
				continue;
			}
			if ( ! is_array( $def ) ) {
				$def = array(
					'type'        => 'string',
					'description' => (string) $def,
				);
			}
			if ( ! empty( $def['required'] ) && true === $def['required'] ) {
				$required[] = $name;
			}
			// PHP/WP REST junk never belongs in LLM tool schemas.
			unset( $def['required'], $def['sanitize_callback'], $def['validate_callback'] );
			// Property-level enum/const is allowed by OpenAI; leave them.
			// Strip nested top-level-style composition if a property is itself a bare oneOf without type.
			if ( empty( $def['type'] ) && ( isset( $def['oneOf'] ) || isset( $def['anyOf'] ) || isset( $def['allOf'] ) ) ) {
				$def['type'] = 'string';
				unset( $def['oneOf'], $def['anyOf'], $def['allOf'] );
			}
			if ( empty( $def['type'] ) ) {
				$def['type'] = 'string';
			}
			// Drop property-level additionalProperties for Google; keep for OpenAI only.
			if ( $for_google ) {
				unset( $def['additionalProperties'] );
			}
			$clean_props[ $name ] = $def;
		}

		$schema = array(
			'type'       => 'object',
			'properties' => empty( $clean_props ) ? new \stdClass() : $clean_props,
		);

		if ( ! empty( $required ) ) {
			// Only keep required keys that still exist as properties.
			$required = array_values(
				array_filter(
					array_unique( $required ),
					static function ( $r ) use ( $clean_props ) {
						return isset( $clean_props[ $r ] );
					}
				)
			);
			if ( $required ) {
				$schema['required'] = $required;
			}
		}

		// OpenAI: optional additionalProperties. Gemini: never send it.
		if ( ! $for_google ) {
			if ( array_key_exists( 'additionalProperties', $params ) ) {
				$schema['additionalProperties'] = $params['additionalProperties'];
			} elseif ( $has_composition && empty( $clean_props ) ) {
				$schema['additionalProperties'] = true;
			}
		}

		return $schema;
	}

	/**
	 * Simplify tool schemas for weaker / smaller models (P0 Item 3).
	 *
	 * Small models (Ollama, Gemma, Phi, etc.) perform much better when tool
	 * schemas are kept very simple: only name, description, and flat properties
	 * with basic types. We remove complex nesting, examples, and excessive
	 * constraints.
	 *
	 * @param array $tools Original tool definitions.
	 * @return array Simplified tool definitions.
	 */
	private function simplify_tools_for_weak_models( array $tools ): array {
		$simplified = array();

		foreach ( $tools as $tool ) {
			$fn = $tool['function'] ?? array();
			if ( empty( $fn['name'] ) ) {
				continue;
			}

			$simple_fn = array(
				'name'        => $fn['name'],
				'description' => $fn['description'] ?? '',
			);

			$params = $fn['parameters'] ?? array();
			$props  = $params['properties'] ?? array();

			$simple_props = array();
			foreach ( $props as $prop_name => $prop_def ) {
				// Keep only the most basic fields small models understand well.
				$simple_prop = array(
					'type'        => $prop_def['type'] ?? 'string',
					'description' => $prop_def['description'] ?? '',
				);

				// For enums, keep a small number of values.
				if ( ! empty( $prop_def['enum'] ) && is_array( $prop_def['enum'] ) ) {
					$simple_prop['enum'] = array_slice( $prop_def['enum'], 0, 8 );
				}

				$simple_props[ $prop_name ] = $simple_prop;
			}

			if ( ! empty( $simple_props ) ) {
				$simple_fn['parameters'] = array(
					'type'       => 'object',
					'properties' => $simple_props,
				);

				if ( ! empty( $params['required'] ) ) {
					$simple_fn['parameters']['required'] = $params['required'];
				}
			}

			$simplified[] = array( 'function' => $simple_fn );
		}

		return $simplified;
	}
}
