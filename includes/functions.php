<?php
/**
 * Global helper functions for the Agent Builder plugin.
 *
 * This file intentionally declares NO namespace so all functions are available
 * in the global scope and callable from included admin files.
 *
 * @package Agent_Builder
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns the i18n strings array for the agenticChat JS object.
 *
 * Centralised here so all three wp_localize_script call sites (admin overlay,
 * frontend page, shortcode / modal) stay in sync automatically.
 *
 * Strings with placeholders use %s so callers can do sprintf() in JS via a
 * tiny helper, or the JS can do simple string replacement.
 *
 * @return array<string, string>
 */
function agent_builder_chat_i18n(): array {
	return array(
		'thinking'            => __( 'Agent is thinking...', 'agent-builder' ),
		'warmingUp'           => __( 'Warming up the AI model, please wait\u2026', 'agent-builder' ),
		'preparingAudio'      => __( 'Preparing audio\u2026', 'agent-builder' ),
		'copied'              => __( 'Copied!', 'agent-builder' ),
		'copy'                => __( 'Copy', 'agent-builder' ),
		'showDiff'            => __( '\u25b6 Show Diff', 'agent-builder' ),
		'hideDiff'            => __( '\u25bc Hide Diff', 'agent-builder' ),
		'proposalDefault'     => __( 'Agent wants to make a change.', 'agent-builder' ),
		'approvalDefault'     => __( 'An agent wants to perform a high-risk action.', 'agent-builder' ),
		'ttsOff'              => __( 'Read aloud: off \u2014 click to enable', 'agent-builder' ),
		'ttsOn'               => __( 'Read aloud: on \u2014 click to disable', 'agent-builder' ),
		'voiceHttpsRequired'  => __( 'Voice input requires Chrome, Edge, or Safari with HTTPS', 'agent-builder' ),
		/* translators: %s is the number of seconds before the next retry */
		'retryBusy'           => __( 'The AI model is busy. Retrying in %ss\u2026', 'agent-builder' ),
		'errorGeneric'        => __( 'Something went wrong. The issue has been reported.', 'agent-builder' ),
		'errorTimeout'        => __( 'The request timed out. The AI model may be overloaded \u2014 please try again.', 'agent-builder' ),
		'errorConnection'     => __( 'Sorry, there was an error connecting to the agent. Please try again.', 'agent-builder' ),
		/* translators: %s is the error message detail */
		'errorProposal'       => __( 'Error processing proposal: %s', 'agent-builder' ),
		/* translators: %s is the error message detail */
		'errorApproval'       => __( 'Error processing approval: %s', 'agent-builder' ),
		'errorTurnstile'      => __( 'Browser verification failed. Please refresh the page and try again.', 'agent-builder' ),
		'errorTtsLimit'       => __( 'Text-to-speech is currently unavailable. Please try again later.', 'agent-builder' ),
		/* translators: %s is the name of the agent that handed off the conversation */
		'handoffBanner'       => __( 'Handed off from %s. Context from the previous agent has been shared.', 'agent-builder' ),
		'reasoningSummary'    => __( 'Agent reasoning & steps', 'agent-builder' ),
		'reasoningThinking'   => __( 'Thinking:', 'agent-builder' ),
		'reasoningTools'      => __( 'Tools used:', 'agent-builder' ),
		/* translators: %s is the number of reasoning iterations */
		'reasoningIterations' => __( 'Iterations: %s', 'agent-builder' ),
	);
}

/**
 * Returns the effective provider, model, and vision model for a given agent slug,
 * respecting per-agent overrides from the agent_settings table.
 *
 * @param string $agent_slug Agent slug, or empty string for global defaults.
 * @return array{provider: string, model: string, vision_model: string, provider_label: string}
 */
function agent_builder_get_effective_provider_model( string $agent_slug = '' ): array {
	$provider_labels = array(
		'openai'    => 'OpenAI',
		'anthropic' => 'Anthropic',
		'xai'       => 'xAI',
		'google'    => 'Google',
		'mistral'   => 'Mistral',
		'kimi'      => 'Kimi',
		'deepseek'  => 'DeepSeek',
		'ollama'    => 'Ollama',
	);

	$provider     = get_option( 'agentic_llm_provider', 'agentic' );
	$model        = get_option( 'agentic_model', '' );
	$vision_model = get_option( 'agentic_vision_model', '' );

	if ( $agent_slug ) {
		$ov_provider     = \Agentic\Agent_Settings::get( $agent_slug, 'override_provider' );
		$ov_model        = \Agentic\Agent_Settings::get( $agent_slug, 'override_model' );
		$ov_vision_model = \Agentic\Agent_Settings::get( $agent_slug, 'override_vision_model' );
		if ( ! empty( $ov_provider ) ) {
			$provider = $ov_provider;
		}
		if ( ! empty( $ov_model ) ) {
			$model = $ov_model;
		}
		if ( ! empty( $ov_vision_model ) ) {
			$vision_model = $ov_vision_model;
		}
	}

	// Per-provider vision model from the registry overrides the global WP option.
	$provider_entry = \Agentic\Provider_Registry::get( $provider );
	if ( empty( $vision_model ) && ! empty( $provider_entry['vision_model'] ) ) {
		$vision_model = $provider_entry['vision_model'];
	}

	// Vision model falls back to the chat model when not separately configured.
	$vision_model = ! empty( $vision_model ) ? $vision_model : $model;

	return array(
		'provider'       => $provider,
		'model'          => $model,
		'vision_model'   => $vision_model,
		'provider_label' => $provider_labels[ $provider ] ?? ucfirst( $provider ),
	);
}

/**
 * Returns the effective chat feature flags for a given agent slug.
 *
 * Per-agent overrides (from the agent_settings table) take precedence when set ('1').
 * Otherwise falls back to the global Chat Settings.
 *
 * @param string $agent_slug Agent slug, or empty string for global defaults.
 * @return array{audio: string, tts: string, vision: string, costs: string}
 */
function agent_builder_get_effective_chat_features( string $agent_slug = '' ): array {
	$audio   = get_option( 'agentic_chat_audio', '1' );
	$rag_key = get_option( 'agentic_rag_api_secret', '' );
	$rag_key = ! empty( $rag_key ) ? $rag_key : ( \Agentic\Provider_Registry::get( 'agentic' )['api_key'] ?? '' );
	$tts     = ( '1' === get_option( 'agentic_chat_tts', '1' ) && ! empty( $rag_key ) ) ? '1' : '0';
	$vision  = get_option( 'agentic_chat_vision', '1' );
	$costs   = '0'; // This build has no license path — cost display stays off.

	if ( $agent_slug ) {
		$ov_audio  = \Agentic\Agent_Settings::get( $agent_slug, 'override_audio' );
		$ov_tts    = \Agentic\Agent_Settings::get( $agent_slug, 'override_tts' );
		$ov_vision = \Agentic\Agent_Settings::get( $agent_slug, 'override_vision' );
		if ( '' !== $ov_audio ) {
			$audio = $ov_audio;
		}
		if ( '' !== $ov_tts ) {
			// Per-agent TTS still requires API secret.
			$tts = ( '1' === $ov_tts && ! empty( $rag_key ) ) ? '1' : '0';
		}
		if ( '' !== $ov_vision ) {
			$vision = $ov_vision;
		}
	}

	return array(
		'audio'  => $audio,
		'tts'    => $tts,
		'vision' => $vision,
		'costs'  => $costs,
	);
}

/**
 * Returns an inline SVG icon (or img tag) for the given provider icon value.
 *
 * Accepts either a URL (returns an <img> element) or a built-in provider slug
 * (returns an inline SVG). Returns empty string if the slug is unknown.
 *
 * @param string $agentic_slug  Provider slug or full icon URL.
 * @param int    $size          Icon size in px.
 * @return string
 */
function agent_builder_setup_provider_icon( string $agentic_slug, int $size = 36 ): string {
	$s = (string) $size;

	// Full URL — render as an img element instead of looking up a built-in SVG.
	if ( str_starts_with( $agentic_slug, 'http://' ) || str_starts_with( $agentic_slug, 'https://' ) ) {
		return '<img src="' . esc_url( $agentic_slug ) . '" width="' . esc_attr( $s ) . '" height="' . esc_attr( $s ) . '" class="agentic-icon-img" alt="">';
	}

	$icons = array(
		'agentic'   => '<img src="' . esc_url( AGENT_BUILDER_URL . 'assets/icon.svg' ) . '" width="' . $s . '" height="' . $s . '" class="agentic-icon-img" alt="Agentic AI">',
		'llama'     => '<svg xmlns="http://www.w3.org/2000/svg" width="' . $s . '" height="' . $s . '" viewBox="0 0 24 24" fill="none" role="img" aria-label="Meta Llama"><rect width="24" height="24" rx="4" fill="#0064E0"/><path d="M5 18V9.5l4 5.5 3-4.5 3 4.5 4-5.5V18" stroke="#fff" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>',
		'cohere'    => '<svg xmlns="http://www.w3.org/2000/svg" width="' . $s . '" height="' . $s . '" viewBox="0 0 24 24" fill="none" role="img" aria-label="Cohere"><circle cx="12" cy="12" r="12" fill="#D18EE2"/><circle cx="12" cy="12" r="6" stroke="#fff" stroke-width="2" fill="none"/><circle cx="12" cy="5.5" r="2.5" fill="#fff"/></svg>',
		'xai'       => '<svg xmlns="http://www.w3.org/2000/svg" width="' . $s . '" height="' . $s . '" viewBox="0 0 24 24" fill="none" role="img" aria-label="xAI"><path d="M3 3l7.5 9L3 21h2.5l6.25-7.5L17.5 21H21l-7.75-9.5L20.5 3H18l-5.75 7L7 3H3z" fill="#000"/></svg>',
		'openai'    => '<svg xmlns="http://www.w3.org/2000/svg" width="' . $s . '" height="' . $s . '" viewBox="0 0 24 24" fill="none" role="img" aria-label="OpenAI"><path d="M22.28 9.84a5.83 5.83 0 0 0-.5-4.79 5.9 5.9 0 0 0-6.35-2.83A5.85 5.85 0 0 0 11.02 1a5.9 5.9 0 0 0-5.62 4.09 5.85 5.85 0 0 0-3.91 2.84 5.9 5.9 0 0 0 .73 6.92 5.83 5.83 0 0 0 .5 4.79 5.9 5.9 0 0 0 6.35 2.83A5.85 5.85 0 0 0 12.98 23a5.9 5.9 0 0 0 5.62-4.09 5.85 5.85 0 0 0 3.91-2.84 5.9 5.9 0 0 0-.73-6.92l.5.73z" fill="#000"/></svg>',
		'google'    => '<svg xmlns="http://www.w3.org/2000/svg" width="' . $s . '" height="' . $s . '" viewBox="0 0 24 24" role="img" aria-label="Google"><path d="M12 11v2.4h6.8c-.3 1.8-2 5.2-6.8 5.2-4.1 0-7.4-3.4-7.4-7.6S7.9 3.4 12 3.4c2.3 0 3.9.98 4.8 1.84l3.28-3.16C18.18.9 15.36 0 12 0 5.37 0 0 5.37 0 12s5.37 12 12 12c6.93 0 11.52-4.87 11.52-11.72 0-.79-.08-1.39-.19-1.99L12 11z" fill="#4285F4"/></svg>',
		'anthropic' => '<svg xmlns="http://www.w3.org/2000/svg" width="' . $s . '" height="' . $s . '" viewBox="0 0 24 24" fill="none" role="img" aria-label="Anthropic"><path d="M13.83 3h-3.66L4 21h3.66l1.22-3.33h6.24L16.34 21H20L13.83 3zm-3.94 11.67 2.11-5.77 2.1 5.77H9.9z" fill="#D97757"/></svg>',
		'mistral'   => '<svg xmlns="http://www.w3.org/2000/svg" width="' . $s . '" height="' . $s . '" viewBox="0 0 24 24" role="img" aria-label="Mistral"><rect x="0" y="0" width="8" height="8" fill="#000"/><rect x="8" y="0" width="8" height="8" fill="#f80"/><rect x="16" y="0" width="8" height="8" fill="#000"/><rect x="0" y="8" width="8" height="8" fill="#f80"/><rect x="8" y="8" width="8" height="8" fill="#000"/><rect x="16" y="8" width="8" height="8" fill="#f80"/><rect x="0" y="16" width="8" height="8" fill="#000"/><rect x="8" y="16" width="8" height="8" fill="#f80"/><rect x="16" y="16" width="8" height="8" fill="#000"/></svg>',
		'kimi'      => '<svg xmlns="http://www.w3.org/2000/svg" width="' . $s . '" height="' . $s . '" viewBox="0 0 24 24" fill="none" role="img" aria-label="Kimi"><rect width="24" height="24" rx="6" fill="#1783FF"/><path d="M7 7h2.2v4.1L13.5 7H16l-4.1 4.2L16.2 17H13.6l-3.3-4.1V17H7V7z" fill="#fff"/></svg>',
		'deepseek'  => '<svg xmlns="http://www.w3.org/2000/svg" width="' . $s . '" height="' . $s . '" viewBox="0 0 24 24" fill="none" role="img" aria-label="DeepSeek"><rect width="24" height="24" rx="6" fill="#4D6BFE"/><path d="M6.5 16.5c2.2-4.8 5-7.2 8.5-7.2 1.6 0 2.8.5 3.5 1.4.5.7.6 1.5.4 2.3-.4 1.4-1.6 2.4-3.2 2.4-1.2 0-2.2-.5-2.9-1.3-.5.9-1.3 1.5-2.4 1.5-1.4 0-2.4-1-2.4-2.4 0-2.2 2.1-3.6 5.1-3.6 1.1 0 2.1.2 3 .5" stroke="#fff" stroke-width="1.6" stroke-linecap="round" fill="none"/></svg>',
		'ollama'    => '<svg xmlns="http://www.w3.org/2000/svg" width="' . $s . '" height="' . $s . '" viewBox="0 0 24 24" fill="none" role="img" aria-label="Ollama"><circle cx="12" cy="12" r="10" fill="#000"/><text x="12" y="16" font-size="10" font-weight="bold" fill="#fff" font-family="monospace" dominant-baseline="auto" text-anchor="middle">ol</text></svg>',
	);
	return $icons[ $agentic_slug ] ?? '';
}
