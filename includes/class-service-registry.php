<?php
/**
 * Service Registry
 *
 * Thin wrapper around Provider_Registry for the service-type entries
 * (non-LLM endpoints: Agentic API, Chat, RAG, TTS, Image Gen, Video Gen).
 *
 * Service rows live in the same `agent_builder_providers` table as LLM providers,
 * distinguished by provider_type = 'service'. This means:
 *   - One table, one place to manage all endpoints.
 *   - Admins can override any base URL from Settings → Endpoints.
 *   - The default URL is always restored by reset() without any code change.
 *
 * Public API is intentionally identical to the old option-based design so
 * callers throughout the plugin do not need to change.
 *
 * @package    Agent_Builder
 * @subpackage Includes
 * @since      2.9.110
 */

declare(strict_types=1);

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Proxy for Agentic service endpoint base URLs.
 */
class Service_Registry {

	/**
	 * Descriptions for each service slug — presentation only, not stored in DB.
	 *
	 * @var array<string, string>
	 */
	private const DESCRIPTIONS = array(
		'agentic-api'      => 'Core API for registration, agent updates, and model pricing.',
		'agentic-chat'     => 'LLM inference gateway. Also used for health checks and model listing.',
		'agentic-tts'      => 'Text-to-speech synthesis service.',
		'agentic-imagegen' => 'Image generation, editing, and upscaling service.',
		'agentic-videogen' => 'Video generation, trimming, stitching, and captioning service.',
	);

	/**
	 * Default base URLs — used by reset() and to compute is_custom.
	 * Must stay in sync with builtin_providers() in Provider_Registry.
	 *
	 * @var array<string, string>
	 */
	private const DEFAULT_URLS = array(
		'agentic-api'      => 'https://agentic-plugin.com',
		'agentic-chat'     => 'https://chat.agentic-plugin.com:11435',
		'agentic-tts'      => 'https://tts.agentic-plugin.com',
		'agentic-imagegen' => 'https://imagegen.agentic-plugin.com',
		'agentic-videogen' => 'https://videogen.agentic-plugin.com',
	);

	// ── Public API ────────────────────────────────────────────────────────────

	/**
	 * Return the base URL for a service, optionally appending a path.
	 *
	 * Falls back to the built-in DEFAULT_URLS when the database row is missing,
	 * mis-typed, or has an empty endpoint (e.g. a fresh install whose provider
	 * table did not seed the service rows). Without this, callers would hand an
	 * empty string to wp_remote_* and fail with "A valid URL was not provided."
	 *
	 * @param string $slug Service slug (e.g. 'agentic-imagegen').
	 * @param string $path Optional path to append, with or without leading slash.
	 * @return string Resolved URL, or empty string when slug is unknown.
	 */
	public static function url( string $slug, string $path = '' ): string {
		$base = Provider_Registry::get_service_url( $slug );
		if ( '' === $base ) {
			$base = self::DEFAULT_URLS[ $slug ] ?? '';
		}
		if ( '' === $base ) {
			return '';
		}
		$base = rtrim( $base, '/' );
		return '' === $path ? $base : $base . '/' . ltrim( $path, '/' );
	}

	/**
	 * Return all services with their current and default URLs.
	 *
	 * @return array<string, array{slug: string, name: string, description: string, url: string, default_url: string, is_custom: bool}>
	 */
	public static function get_all(): array {
		$rows   = Provider_Registry::get_services();
		$result = array();

		foreach ( $rows as $svc ) {
			$slug            = $svc['slug'];
			$current         = $svc['endpoint'];
			$default         = self::DEFAULT_URLS[ $slug ] ?? $current;
			$result[ $slug ] = array(
				'slug'        => $slug,
				'name'        => $svc['name'],
				'description' => self::DESCRIPTIONS[ $slug ] ?? '',
				'url'         => $current,
				'default_url' => $default,
				'is_custom'   => $current !== $default,
			);
		}

		return $result;
	}

	/**
	 * Save a custom base URL for a service.
	 *
	 * Passing an empty string restores the default.
	 *
	 * @param string $slug Service slug.
	 * @param string $url  New base URL (empty string = reset to default).
	 * @return bool False when the slug is unknown.
	 */
	public static function update( string $slug, string $url ): bool {
		$url = esc_url_raw( trim( $url ) );

		if ( '' === $url ) {
			self::reset( $slug );
			return null !== Provider_Registry::get_service( $slug );
		}

		return Provider_Registry::save_service_endpoint( $slug, $url );
	}

	/**
	 * Reset a service URL to its built-in default.
	 *
	 * @param string $slug Service slug.
	 * @return void
	 */
	public static function reset( string $slug ): void {
		$default = self::DEFAULT_URLS[ $slug ] ?? '';
		if ( '' !== $default ) {
			Provider_Registry::save_service_endpoint( $slug, $default );
		}
	}

	/**
	 * Invalidate the underlying provider cache.
	 *
	 * @return void
	 */
	public static function invalidate(): void {
		Provider_Registry::invalidate();
	}

	/**
	 * Return all known service slugs.
	 *
	 * @return string[]
	 */
	public static function get_slugs(): array {
		return array_column( Provider_Registry::get_services(), 'slug' );
	}
}
