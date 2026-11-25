<?php
/**
 * Core AI Adapter — forward-compatible passthrough to a WordPress-native AI provider.
 *
 * WordPress 7 is expected to ship native AI built on the Abilities API. This
 * adapter is an inert scaffold: by default it does nothing and the plugin keeps
 * using its configured HTTP providers. When a future WordPress core AI runtime
 * (or an integration plugin) becomes available, it can opt in with two filters:
 *
 *   add_filter( 'agentic_core_ai_available', '__return_true' );
 *   add_filter( 'agentic_core_ai_chat', function ( $unused, $messages, $tools, $force ) {
 *       // Call the core AI runtime and return an OpenAI-shaped response array:
 *       // [ 'choices' => [ [ 'message' => [ 'role' => 'assistant', 'content' => '...' ] ] ],
 *       //   'usage'   => [ 'prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0 ] ]
 *       return $response;
 *   }, 10, 4 );
 *
 * Routing only activates for the dedicated provider slug, so existing providers
 * are never affected.
 *
 * @package    Agent_Builder
 * @subpackage Includes
 * @since      2.11.0
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dormant-by-default bridge to a WordPress-native AI provider.
 */
class Core_AI_Adapter {

	/**
	 * Provider slug that routes through the WordPress core AI runtime.
	 *
	 * @var string
	 */
	const PROVIDER_SLUG = 'wordpress-core';

	/**
	 * Whether a WordPress-native AI runtime is available to handle requests.
	 *
	 * False by default; a future core-AI integration opts in via the filter.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		return (bool) apply_filters( 'agentic_core_ai_available', false );
	}

	/**
	 * Whether requests for the given provider should route to core AI.
	 *
	 * @param string $provider Active provider slug.
	 * @return bool
	 */
	public static function handles( string $provider ): bool {
		return self::PROVIDER_SLUG === $provider && self::is_available();
	}

	/**
	 * Attempt to handle a chat request via the core AI runtime.
	 *
	 * Returns null when the adapter is dormant or does not handle the provider,
	 * so the caller falls through to its normal HTTP provider path with no
	 * change in behaviour.
	 *
	 * @param string     $provider       Active provider slug.
	 * @param array      $messages       Chat messages.
	 * @param array|null $tools          Tool definitions.
	 * @param bool       $force_tool_use Whether to force a tool call.
	 * @return array|\WP_Error|null Normalised response, error, or null to fall through.
	 */
	public static function maybe_handle( string $provider, array $messages, ?array $tools, bool $force_tool_use ) {
		if ( ! self::handles( $provider ) ) {
			return null;
		}

		$response = apply_filters( 'agentic_core_ai_chat', null, $messages, $tools, $force_tool_use );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( is_array( $response ) && isset( $response['choices'] ) ) {
			return $response;
		}

		return new \WP_Error(
			'core_ai_unavailable',
			__( 'The WordPress core AI provider is selected but no handler returned a response.', 'agentstructor' )
		);
	}
}
