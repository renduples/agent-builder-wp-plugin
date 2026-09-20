<?php
/**
 * Provider Registry
 *
 * Single source of truth for LLM provider configuration.
 * Providers are stored in the `wp_agent_builder_providers` database table.
 * Built-in providers are seeded on first use and can be edited but not deleted.
 * Custom providers can be added, edited, and deleted freely.
 *
 * Endpoint URL placeholders:
 *   %MODEL%      — replaced with the model string (rawurlencode'd)
 *   %KEY%        — replaced with the API key (rawurlencode'd); used by Google
 *   %OLLAMA_URL% — replaced with the agent_builder_ollama_url option value
 *
 * auth_type values:
 *   bearer    — Authorization: Bearer {key}  (OpenAI, xAI, Mistral, Llama, Cohere)
 *   anthropic — x-api-key: {key} + anthropic-version header
 *   url_key   — key embedded in URL via %KEY% placeholder (Google)
 *   none      — no auth header (Ollama)
 *   bearer    — also used by Agentic AI (key stored in table, set via registration)
 *
 * req_format values:
 *   openai    — OpenAI-compatible request structure (default)
 *   anthropic — Anthropic messages format
 *   google    — Google Gemini contents/parts format
 *   agentic   — Native Ollama /api/chat format (no tool calls)
 *
 * resp_format values:
 *   openai        — standard response; no normalization needed
 *   cohere        — normalize_cohere_response() applied
 *   agentic_ollama — normalize_agentic_response() applied
 *
 * @package    Agent_Builder
 * @subpackage Includes
 * @since      1.9.8
 */

declare(strict_types=1);

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registry for LLM provider configuration.
 */
class Provider_Registry {

	/**
	 * DB table name suffix (without $wpdb->prefix).
	 */
	private const TABLE = 'agent_builder_providers';

	/**
	 * On WP 7.0+, attempt to retrieve credentials from the new central
	 * Connectors API instead of (or before) our local storage.
	 * This delivers the "configure once in Settings → Connectors" promise.
	 *
	 * @param string $provider_slug e.g. 'openai', 'anthropic'
	 * @return array|null ['api_key' => string] or null
	 */
	public static function get_connector_credentials( string $provider_slug ): ?array {
		if ( ! WP_AI_Detection::has_connectors() || ! WP_Optional_API::has( 'wp_get_connectors' ) ) {
			return null;
		}

		$connectors = WP_Optional_API::get_connectors();
		if ( empty( $connectors ) || ! is_array( $connectors ) ) {
			return null;
		}

		// Common mapping from our provider slugs to connector IDs used by core/official plugins.
		$map = array(
			'openai'    => 'openai',
			'anthropic' => 'anthropic',
			'google'    => 'google',
			'xai'       => 'xai',
		);

		$connector_id = $map[ $provider_slug ] ?? $provider_slug;

		foreach ( $connectors as $conn ) {
			if ( ( $conn['id'] ?? '' ) === $connector_id || ( $conn['slug'] ?? '' ) === $connector_id ) {
				// Core connectors usually store the key under a specific setting.
				$key = $conn['api_key'] ?? $conn['key'] ?? get_option( $conn['setting_name'] ?? '' );
				if ( ! empty( $key ) ) {
					return array( 'api_key' => $key );
				}
			}
		}

		return null;
	}

	/**
	 * In-memory cache for the current request.
	 *
	 * @var array<int, array<string, mixed>>|null
	 */
	private static ?array $cache = null;

	// ── Encryption helpers ───────────────────────────────────────────────────

	/**
	 * Derive a 256-bit encryption key from WordPress auth salts.
	 *
	 * @return string Raw 32-byte key.
	 */
	private static function encryption_key(): string {
		// Use AUTH_KEY + SECURE_AUTH_KEY (defined in wp-config.php) so the
		// ciphertext is tied to this specific WordPress installation.
		if ( defined( 'AUTH_KEY' ) && defined( 'SECURE_AUTH_KEY' ) ) {
			return hash( 'sha256', AUTH_KEY . SECURE_AUTH_KEY, true );
		}

		// Rare installs without salt constants: derive from wp_salt(), which
		// generates and persists database-backed salts automatically.
		return hash( 'sha256', wp_salt( 'auth' ) . wp_salt( 'secure_auth' ), true );
	}

	/**
	 * Encrypt a plaintext value using AES-256-CBC.
	 *
	 * Returns the original string unchanged when openssl is unavailable or
	 * the value is empty.
	 *
	 * @param string $plaintext Value to encrypt.
	 * @return string Base-64 encoded "iv:ciphertext" string, or empty string.
	 */
	public static function encrypt( string $plaintext ): string {
		if ( '' === $plaintext || ! function_exists( 'openssl_encrypt' ) ) {
			return $plaintext;
		}

		$key = self::encryption_key();
		$iv  = openssl_random_pseudo_bytes( 16 );
		if ( false === $iv ) {
			return $plaintext; // Fallback to plaintext if IV generation fails.
		}

		$cipher = openssl_encrypt( $plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		if ( false === $cipher ) {
			return $plaintext;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding ciphertext, not obfuscating code.
		return 'enc:' . base64_encode( $iv . $cipher );
	}

	/**
	 * Decrypt a value previously encrypted with self::encrypt().
	 *
	 * Handles both encrypted (prefixed with "enc:") and legacy plaintext
	 * values, so the migration is transparent.
	 *
	 * @param string $stored Stored value from the database.
	 * @return string Original plaintext, or empty string.
	 */
	public static function decrypt( string $stored ): string {
		if ( '' === $stored ) {
			return '';
		}

		// Legacy: not encrypted — return as-is.
		if ( ! str_starts_with( $stored, 'enc:' ) ) {
			return $stored;
		}

		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return ''; // Cannot decrypt without openssl.
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding ciphertext.
		$raw = base64_decode( substr( $stored, 4 ), true );
		if ( false === $raw || strlen( $raw ) < 17 ) {
			return ''; // Corrupt data.
		}

		$iv     = substr( $raw, 0, 16 );
		$cipher = substr( $raw, 16 );
		$key    = self::encryption_key();

		$plaintext = openssl_decrypt( $cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		return ( false === $plaintext ) ? '' : $plaintext;
	}

	// ── Public API ────────────────────────────────────────────────────────────

	/**
	 * Return all LLM providers, sorted by sort_order ASC.
	 *
	 * Service-type entries (RAG, TTS, imagegen, etc.) are excluded.
	 * Use get_services() to retrieve those.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_all(): array {
		self::load();
		$list = array_values(
			array_filter(
				self::$cache ?? array(),
				static fn( array $p ): bool => 'llm' === ( $p['provider_type'] ?? 'llm' )
			)
		);
		// Stable A–Z by display name (built-ins + custom).
		usort(
			$list,
			static function ( array $a, array $b ): int {
				return strcasecmp( (string) ( $a['name'] ?? '' ), (string) ( $b['name'] ?? '' ) );
			}
		);
		return $list;
	}

	/**
	 * Return all service-type entries (non-LLM endpoints), sorted by sort_order ASC.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_services(): array {
		self::load();
		return array_values(
			array_filter(
				self::$cache ?? array(),
				static fn( array $p ): bool => 'service' === ( $p['provider_type'] ?? 'llm' )
			)
		);
	}

	/**
	 * Return a single service provider by slug, or null when not found or not a service.
	 *
	 * @param string $slug Service slug (e.g. 'agentic-imagegen').
	 * @return array<string, mixed>|null
	 */
	public static function get_service( string $slug ): ?array {
		$provider = self::get( $slug );
		if ( null === $provider || 'service' !== ( $provider['provider_type'] ?? 'llm' ) ) {
			return null;
		}
		return $provider;
	}

	/**
	 * Return the base URL for a service endpoint, optionally appending a path.
	 *
	 * @param string $slug Service slug (e.g. 'agentic-imagegen').
	 * @param string $path Optional path to append, with or without leading slash.
	 * @return string Resolved URL, or empty string when slug is unknown or not a service.
	 */
	public static function get_service_url( string $slug, string $path = '' ): string {
		$provider = self::get( $slug );
		if ( null === $provider || 'service' !== ( $provider['provider_type'] ?? 'llm' ) ) {
			return '';
		}
		$base = rtrim( $provider['endpoint'], '/' );
		if ( '' !== $path ) {
			return $base . '/' . ltrim( $path, '/' );
		}
		return $base;
	}

	/**
	 * Save (update) only the endpoint URL for a service provider.
	 *
	 * @param string $slug     Service slug.
	 * @param string $endpoint New base URL.
	 * @return bool True on success, false when slug is not a known service.
	 */
	public static function save_service_endpoint( string $slug, string $endpoint ): bool {
		self::load();
		if ( null === self::get_service( $slug ) ) {
			return false;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . self::TABLE,
			array( 'endpoint' => sanitize_text_field( $endpoint ) ),
			array( 'slug' => $slug ),
			array( '%s' ),
			array( '%s' )
		);

		self::invalidate();
		return true;
	}

	/**
	 * Return a single provider by slug, or null when not found.
	 *
	 * @param string $slug Provider slug (e.g. 'openai').
	 * @return array<string, mixed>|null
	 */
	public static function get( string $slug ): ?array {
		self::load();
		foreach ( self::$cache as $provider ) {
			if ( $provider['slug'] === $slug ) {
				return $provider;
			}
		}
		return null;
	}

	/**
	 * Return all provider slugs (for allowlist validation).
	 *
	 * @return string[]
	 */
	public static function get_slugs(): array {
		return array_column( self::get_all(), 'slug' );
	}

	/**
	 * Return providers that are considered "active" — either they don't require
	 * an API key, or a key has been configured for them.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_active(): array {
		return array_values(
			array_filter(
				self::get_all(),
				static function ( array $p ): bool {
						return ! $p['requires_key'] || ! empty( $p['api_key'] );
				}
			)
		);
	}

	/**
	 * Return input/output pricing rates for a given provider + model.
	 *
	 * @param string $provider_slug Provider slug.
	 * @param string $model         Model identifier (empty = use provider default).
	 * @return array{in: float, out: float} Per-million-token rates in USD.
	 */
	public static function get_model_pricing( string $provider_slug, string $model = '' ): array {
		$fallback = array(
			'in'  => 0.0,
			'out' => 0.0,
		);

		$providers = self::get_all();
		$provider  = null;
		foreach ( $providers as $p ) {
			if ( $p['slug'] === $provider_slug ) {
				$provider = $p;
				break;
			}
		}

		if ( ! $provider || empty( $provider['model_pricing'] ) ) {
			return $fallback;
		}

		$pricing = (array) $provider['model_pricing'];

		// Try exact model match first.
		if ( $model && isset( $pricing[ $model ] ) ) {
			return array(
				'in'  => (float) ( $pricing[ $model ]['in'] ?? 0.0 ),
				'out' => (float) ( $pricing[ $model ]['out'] ?? 0.0 ),
			);
		}

		// Fall back to the first available entry.
		$first = reset( $pricing );
		if ( $first ) {
			return array(
				'in'  => (float) ( $first['in'] ?? 0.0 ),
				'out' => (float) ( $first['out'] ?? 0.0 ),
			);
		}

		return $fallback;
	}

	/**
	 * Save model pricing for a provider.
	 *
	 * @param string $provider_slug Provider slug.
	 * @param array  $pricing       Model → {in, out} map.
	 */
	public static function save_model_pricing( string $provider_slug, array $pricing ): void {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;

		// Verify provider exists.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE slug = %s", $provider_slug ) );
		if ( ! $id ) {
			return;
		}

		$clean = array();
		foreach ( $pricing as $model => $rates ) {
			$model = sanitize_text_field( $model );
			if ( ! $model ) {
				continue;
			}
			$clean[ $model ] = array(
				'in'  => max( 0.0, (float) ( $rates['in'] ?? 0 ) ),
				'out' => max( 0.0, (float) ( $rates['out'] ?? 0 ) ),
			);
		}

		// Only update the model_pricing column — never touch other provider fields.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( $table, array( 'model_pricing' => wp_json_encode( $clean ) ), array( 'id' => $id ) );
		self::$cache = null; // Bust cache.
	}

	/**
	 * The billing identity sent to the hosted Agentic relay.
	 *
	 * The relay meters usage by this value — the site's license key — and
	 * rejects a request without one ("This site is not activated for Agentic
	 * hosted AI"). It is a distinct credential from the `agentic` provider's
	 * API key: signup returns both `api_key` (authenticates the request) and
	 * `license_key` (the metering identity), and they are different values.
	 * `Admin_Ajax::persist_hosted_signup()` stores the license key in the
	 * `agent_builder_license_key` option (see #135); this reads it back.
	 *
	 * The provider row's API key is NOT a valid metering identity and must not
	 * be used as a fallback — doing so meters under the wrong id and the relay
	 * either rejects it or serves unmetered. A site with an API key but no
	 * license key is treated as not-usable so the admin is funnelled back to
	 * Quick Start to reconnect (which then stores the license key).
	 *
	 * @return string The license key, or '' when the site has no hosted license.
	 */
	public static function get_license_key(): string {
		return (string) get_option( 'agent_builder_license_key', '' );
	}

	/**
	 * Whether at least one provider is fully usable for chat right now.
	 *
	 * Stricter than get_active(): the hosted "agentic" provider also needs a
	 * billing identity (see get_license_key()) so the proxy can meter usage.
	 * Without one the proxy rejects the request, so it is not counted here and
	 * the admin funnels the user back to setup.
	 *
	 * @return bool
	 */
	public static function has_usable_provider(): bool {
		if ( class_exists( __NAMESPACE__ . '\\Emergency_Stop' ) && Emergency_Stop::is_active() ) {
			return false;
		}
		$ollama_url = get_option( 'agent_builder_ollama_url', '' );
		foreach ( self::get_all() as $p ) {
			$slug = $p['slug'] ?? '';
			if ( 'agentic' === $slug ) {
				if ( ! empty( $p['api_key'] ) && '' !== self::get_license_key() ) {
					return true;
				}
				continue;
			}
			if ( 'none' === ( $p['auth_type'] ?? '' ) ) {
				if ( ! empty( $ollama_url ) ) {
					return true;
				}
				continue;
			}
			if ( ! $p['requires_key'] || ! empty( $p['api_key'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether the hosted "agentic" provider has an API key but no license key.
	 *
	 * That is the state a site is left in when it signed up before 3.4.1: the
	 * signup handler stored the API key and discarded the license key (#135).
	 * has_usable_provider() correctly reports false for it, so the admin is
	 * funnelled back to Quick Start; this tells that screen why, so it can say
	 * that signing up again with the same email repairs the connection (the
	 * endpoint returns the existing key together with its license).
	 *
	 * @return bool
	 */
	public static function hosted_license_missing(): bool {
		$p = self::get( 'agentic' );
		if ( null === $p || empty( $p['api_key'] ) ) {
			return false;
		}
		return '' === (string) get_option( 'agent_builder_license_key', '' );
	}

	/**
	 * Return slugs of providers that do NOT require an API key.
	 *
	 * @return string[]
	 */
	public static function get_no_key_slugs(): array {
		return array_column(
			array_filter( self::get_all(), static fn( array $p ) => ! $p['requires_key'] ),
			'slug'
		);
	}

	/**
	 * Return an associative slug → name map for all providers.
	 *
	 * @return array<string, string>
	 */
	public static function get_slug_name_map(): array {
		$map = array();
		foreach ( self::get_all() as $p ) {
			$map[ $p['slug'] ] = $p['name'];
		}
		return $map;
	}

	/**
	 * Whether a slug matches a known provider.
	 *
	 * @param string $slug Provider slug.
	 * @return bool
	 */
	public static function is_valid( string $slug ): bool {
		return null !== self::get( $slug );
	}

	/**
	 * Resolve a provider's endpoint URL by substituting any placeholders.
	 *
	 * @param string $endpoint Endpoint pattern (may contain %MODEL%, %KEY%, %OLLAMA_URL%).
	 * @param string $model    Model string.
	 * @param string $api_key  API key.
	 * @return string Resolved endpoint URL.
	 */
	public static function resolve_endpoint( string $endpoint, string $model = '', string $api_key = '' ): string {
		if ( str_contains( $endpoint, '%OLLAMA_URL%' ) ) {
			$base     = rtrim( get_option( 'agent_builder_ollama_url', 'http://localhost:11434' ), '/' );
			$endpoint = str_replace( '%OLLAMA_URL%', $base, $endpoint );
		}
		if ( str_contains( $endpoint, '%MODEL%' ) ) {
			$endpoint = str_replace( '%MODEL%', rawurlencode( $model ), $endpoint );
		}
		if ( str_contains( $endpoint, '%KEY%' ) ) {
			$endpoint = str_replace( '%KEY%', rawurlencode( $api_key ), $endpoint );
		}
		return $endpoint;
	}

	/**
	 * Add or update a provider.
	 *
	 * Built-in providers can be edited; their is_builtin flag cannot be changed.
	 * Slug is the unique key — passing an existing slug performs an update.
	 * Include 'api_key' in the array to update the stored key; omit to leave unchanged.
	 *
	 * @param array<string, mixed> $provider Provider data. Must include 'slug'.
	 * @return bool True on success, false when slug is missing.
	 */
	public static function upsert( array $provider ): bool {
		self::load();

		$slug = $provider['slug'] ?? '';
		if ( empty( $slug ) ) {
			return false;
		}

		$existing = self::get( $slug );
		$input    = $provider; // Caller's original partial input, before defaults are merged in.

		if ( null !== $existing ) {
			// Any field not explicitly supplied falls back to the existing row.
			// Partial updates (e.g. the "refresh models" action or the daily
			// live-model-refresh cron, both of which only pass slug + models)
			// must not blank out fields — endpoint, default_model, etc. —
			// they never intended to touch.
			$provider = array_merge( $existing, $provider );
			// Preserve is_builtin — never caller-controlled.
			$provider['is_builtin'] = $existing['is_builtin'];
			// api_key: an explicitly empty value means "leave unchanged" (the
			// classic Settings form always submits a blank/masked key input).
			if ( ! array_key_exists( 'api_key', $input ) || '' === $input['api_key'] ) {
				$provider['api_key'] = $existing['api_key'];
			}
			// model_pricing: an explicitly empty value means "leave unchanged".
			if ( ! array_key_exists( 'model_pricing', $input ) || empty( $input['model_pricing'] ) ) {
				$provider['model_pricing'] = $existing['model_pricing'];
			}
		} else {
			$provider['is_builtin'] = false;
			if ( ! array_key_exists( 'api_key', $provider ) ) {
				$provider['api_key'] = '';
			}
		}

		$normalized = self::normalize( $provider );
		self::write_row( $normalized );
		self::invalidate();
		return true;
	}

	/**
	 * Save (update) only the API key for a provider.
	 *
	 * @param string $slug Provider slug.
	 * @param string $key  New API key (pass empty string to clear).
	 * @return bool True on success, false when provider not found.
	 */
	public static function save_api_key( string $slug, string $key ): bool {
		self::load();
		if ( null === self::get( $slug ) ) {
			return false;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . self::TABLE,
			array( 'api_key' => self::encrypt( sanitize_text_field( $key ) ) ),
			array( 'slug' => $slug ),
			array( '%s' ),
			array( '%s' )
		);

		self::invalidate();
		return true;
	}

	/**
	 * Restore a previously stored encrypted API key blob (emergency stop recovery).
	 *
	 * Does not re-encrypt — writes the ciphertext as-is.
	 *
	 * @param string $slug      Provider slug.
	 * @param string $encrypted Encrypted value previously read from the providers table.
	 * @return bool
	 */
	public static function restore_encrypted_api_key( string $slug, string $encrypted ): bool {
		self::load();
		if ( null === self::get( $slug ) ) {
			return false;
		}
		if ( '' === $encrypted ) {
			return self::save_api_key( $slug, '' );
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . self::TABLE,
			array( 'api_key' => $encrypted ),
			array( 'slug' => $slug ),
			array( '%s' ),
			array( '%s' )
		);

		self::invalidate();
		return true;
	}

	/**
	 * Delete a custom (non-builtin) provider by slug.
	 *
	 * @param string $slug Provider slug.
	 * @return true|\WP_Error True on success; WP_Error when protected or not found.
	 */
	public static function delete( string $slug ): bool|\WP_Error {
		self::load();

		$existing = self::get( $slug );

		if ( null === $existing ) {
			return new \WP_Error( 'not_found', 'Provider not found.' );
		}
		if ( ! empty( $existing['is_builtin'] ) ) {
			return new \WP_Error( 'protected', 'Cannot delete a built-in provider.' );
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			$wpdb->prefix . self::TABLE,
			array( 'slug' => $slug ),
			array( '%s' )
		);

		self::invalidate();
		return true;
	}

	/**
	 * Invalidate the in-memory cache (useful after option updates in the same request).
	 *
	 * @return void
	 */
	public static function invalidate(): void {
		self::$cache = null;
	}

	// ── Private helpers ───────────────────────────────────────────────────────

	/**
	 * Load providers from the DB table into the in-memory cache.
	 *
	 * Seeds built-in providers first if the table is empty.
	 *
	 * @return void
	 */
	private static function load(): void {
		if ( null !== self::$cache ) {
			return;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Custom plugin table.
			'SELECT * FROM ' . $wpdb->prefix . self::TABLE . ' ORDER BY sort_order ASC, id ASC',
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			// Fresh install or table was just created — seed built-ins.
			foreach ( self::builtin_providers() as $p ) {
				self::write_row( $p );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
				'SELECT * FROM ' . $wpdb->prefix . self::TABLE . ' ORDER BY sort_order ASC, id ASC', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
				ARRAY_A
			);
		}

		self::$cache = array_values( array_map( array( self::class, 'row_to_array' ), (array) $rows ) );

		// Seed any built-in rows that don't exist yet (e.g. service entries added by an update).
		$cached_slugs = array_column( self::$cache, 'slug' );
		$needs_reload = false;
		foreach ( self::builtin_providers() as $builtin ) {
			if ( ! in_array( $builtin['slug'], $cached_slugs, true ) ) {
				self::write_row( $builtin );
				$needs_reload = true;
			}
		}
		if ( $needs_reload ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$rows        = $wpdb->get_results( 'SELECT * FROM ' . $wpdb->prefix . self::TABLE . ' ORDER BY sort_order ASC, id ASC', ARRAY_A );
			self::$cache = array_values( array_map( array( self::class, 'row_to_array' ), (array) $rows ) );
		}

		// Always sync non-user-configurable built-in fields from PHP definitions so that
		// deployments which change formats take effect on next admin load.
		// Note: 'endpoint', 'vision_model', and 'models' are normally excluded —
		// they are user-configurable via ↺ Refresh. Exceptions below force-heal
		// known broken defaults (e.g. Google resp_format=openai, retired -exp models).
		// For service entries 'endpoint' IS synced on first seed; after that admins may override it.
		$builtins    = array_column( self::builtin_providers(), null, 'slug' );
		$sync_fields = array( 'auth_type', 'req_format', 'resp_format', 'requires_key', 'key_url', 'provider_type' );

		foreach ( self::$cache as &$p ) {
			if ( ! isset( $builtins[ $p['slug'] ] ) ) {
				continue; // Custom provider — never overwrite.
			}
			$builtin = $builtins[ $p['slug'] ];
			$changed = false;
			foreach ( $sync_fields as $field ) {
				if ( isset( $builtin[ $field ] ) && $p[ $field ] !== $builtin[ $field ] ) {
					$p[ $field ] = $builtin[ $field ];
					$changed     = true;
				}
			}

			// Heal stale OpenAI / Google model catalogs that break chat after API retirements.
			if ( self::provider_models_need_refresh( $p ) ) {
				if ( isset( $builtin['models'] ) ) {
					$p['models'] = $builtin['models'];
					$changed     = true;
				}
				if ( isset( $builtin['default_model'] ) ) {
					$p['default_model'] = $builtin['default_model'];
					$changed            = true;
				}
				if ( isset( $builtin['model_pricing'] ) ) {
					$p['model_pricing'] = $builtin['model_pricing'];
					$changed            = true;
				}
			}

			if ( $changed ) {
				self::write_row( $p );
			}
		}
		unset( $p );
	}

	/**
	 * Whether a built-in provider's model list looks retired / misconfigured.
	 *
	 * @param array<string, mixed> $p Provider row.
	 */
	private static function provider_models_need_refresh( array $p ): bool {
		$slug    = (string) ( $p['slug'] ?? '' );
		$default = (string) ( $p['default_model'] ?? '' );
		$models  = $p['models'] ?? array();
		if ( ! is_array( $models ) ) {
			$models = array();
		}
		$joined = strtolower( $default . ' ' . implode( ' ', array_map( 'strval', $models ) ) );

		if ( 'google' === $slug ) {
			// Experimental or 1.5 aliases commonly 404; also heal wrong resp_format (handled separately).
			if ( str_contains( $joined, '-exp' ) || str_contains( $joined, 'gemini-1.5' ) || str_contains( $joined, 'gemini-2.5-pro-exp' ) ) {
				return true;
			}
			if ( empty( $models ) || ! in_array( 'gemini-2.5-flash', $models, true ) ) {
				return true;
			}
		}

		if ( 'openai' === $slug ) {
			// Legacy-only catalogs (e.g. gpt-3.5-turbo as sole default without 4.1 family).
			if ( empty( $models ) || ! in_array( 'gpt-4.1-mini', $models, true ) ) {
				return true;
			}
			if ( in_array( $default, array( 'gpt-3.5-turbo', 'gpt-4-turbo' ), true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Register the daily live-model-refresh cron so provider model lists stay
	 * current from each provider's own API instead of depending on hardcoded
	 * lists that need a plugin update every time a vendor ships a new model.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'agent_builder_refresh_provider_models', array( self::class, 'cron_refresh_all_live_models' ) );

		if ( is_admin() && ! wp_next_scheduled( 'agent_builder_refresh_provider_models' ) ) {
			wp_schedule_event( time(), 'daily', 'agent_builder_refresh_provider_models' );
		}
	}

	/**
	 * Whether the admin opted in to daily model-catalog refresh from
	 * agentic-plugin.com (Guideline 7). Default off.
	 *
	 * @return bool
	 */
	public static function platform_sync_allowed(): bool {
		return '1' === (string) get_option( 'agent_builder_allow_platform_sync', '0' );
	}

	/**
	 * Cron callback: refresh the live model list for every built-in provider
	 * that has an API key (or, for key-less providers like Ollama, is reachable).
	 * The hardcoded `models` arrays in builtin_providers() are only a bootstrap
	 * seed for brand-new installs — this keeps already-configured installs
	 * current without waiting on a plugin release.
	 *
	 * @return void
	 */
	public static function cron_refresh_all_live_models(): void {
		$allow_catalog = self::platform_sync_allowed();
		foreach ( self::get_all() as $p ) {
			if ( ! ( $p['is_builtin'] ?? false ) ) {
				continue; // Custom providers manage their own model lists.
			}
			$slug = (string) ( $p['slug'] ?? '' );
			if ( 'agentic' === $slug && empty( $p['api_key'] ) ) {
				continue; // The hosted gateway requires its own key to authenticate.
			}
			// Guideline 7: BYOK providers must not phone agentic-plugin.com
			// unless the admin opted in. Ollama is local; the Agentic gateway
			// is a configured SaaS (key present).
			if ( ! $allow_catalog && 'ollama' !== $slug && 'agentic' !== $slug ) {
				continue;
			}
			$models = self::fetch_live_models_from_api( $slug );
			if ( ! empty( $models ) ) {
				self::upsert(
					array(
						'slug'   => $slug,
						'models' => $models,
					)
				);
			}
		}
	}

	/**
	 * Fetch the live model list for a provider.
	 *
	 * Shared by the admin "Refresh" action (class-admin-ajax.php) and the
	 * daily cron above. Third-party (BYOK) providers are resolved from our
	 * own curated catalog on agentic-plugin.com — the same source that
	 * already powers "Get Latest Pricing" — instead of every WordPress site
	 * independently learning each vendor's raw models API and filtering out
	 * what doesn't apply (embeddings, audio, deprecated aliases, etc.).
	 * Ollama has no such thing to query (self-hosted); the "agentic" hosted
	 * gateway is already our own API, so it's asked directly for real-time
	 * accuracy.
	 *
	 * @param string $slug     Provider slug.
	 * @param bool   $explicit True for an admin-initiated "Refresh" click —
	 *                         bypasses the Guideline 7 opt-in gate on the
	 *                         curated catalog, the same way "Get Latest
	 *                         Pricing" already does. False (default) for the
	 *                         daily cron, which stays gated.
	 * @return array<int, string> Model IDs, or an empty array on failure.
	 */
	public static function fetch_live_models_from_api( string $slug, bool $explicit = false ): array {
		$provider = self::get( $slug );
		if ( ! $provider ) {
			return array();
		}

		if ( 'ollama' === $slug ) {
			$ollama_url = rtrim( get_option( 'agent_builder_ollama_url', 'http://localhost:11434' ), '/' );
			$resp       = wp_remote_get( $ollama_url . '/api/tags', array( 'timeout' => 10 ) );
			if ( is_wp_error( $resp ) || 200 !== wp_remote_retrieve_response_code( $resp ) ) {
				return array();
			}
			$data = json_decode( wp_remote_retrieve_body( $resp ), true );
			return array_values( array_filter( wp_list_pluck( $data['models'] ?? array(), 'name' ) ) );
		}

		if ( 'agentic' === $slug ) {
			$resp = wp_remote_get(
				Service_Registry::url( 'agentic-chat', '/v1beta/models' ),
				array(
					'headers' => array(
						'Authorization'    => 'Bearer ' . ( $provider['api_key'] ?? '' ),
						'X-Plugin-Version' => AGENT_BUILDER_VERSION,
					),
					'timeout' => 15,
				)
			);
			if ( is_wp_error( $resp ) || 200 !== wp_remote_retrieve_response_code( $resp ) ) {
				return array();
			}
			$data   = json_decode( wp_remote_retrieve_body( $resp ), true );
			$models = array();
			foreach ( $data['models'] ?? array() as $m ) {
				$name = $m['name'] ?? '';
				if ( ! empty( $name ) ) {
					$models[] = str_starts_with( $name, 'models/' ) ? substr( $name, 7 ) : $name;
				}
			}
			return array_values( array_filter( $models ) );
		}

		// Every other built-in LLM provider (openai, anthropic, google, xai,
		// mistral, llama, kimi, deepseek, cohere, ...): our own curated catalog.
		$catalog = self::fetch_curated_catalog( $explicit );
		return array_values( array_keys( (array) ( $catalog[ $slug ] ?? array() ) ) );
	}

	/**
	 * Fetch (and briefly cache) the curated provider → model → pricing catalog
	 * from agentic-plugin.com. Single source of truth for both "Get Latest
	 * Pricing" (class-admin-ajax.php::update_model_pricing()) and live
	 * model-list refresh above, so a vendor's API changes only need updating
	 * in one place instead of in every WordPress install.
	 *
	 * @return array<string, array<string, array{in: float, out: float}>>
	 */
	private static function fetch_curated_catalog( bool $explicit = false ): array {
		$cached = get_transient( 'agentic_curated_model_catalog' );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		// Guideline 7 only restricts *automatic* phone-home (the daily cron).
		// An explicit, user-initiated click — "Refresh Models" here, or "Get
		// Latest Pricing"'s own separate direct GET — isn't what that
		// guideline targets, so it bypasses the opt-in gate.
		if ( ! $explicit && ! self::platform_sync_allowed() ) {
			return array();
		}

		$resp = wp_remote_get(
			Service_Registry::url( 'agentic-api', '/wp-json/agentic/v1/model-pricing' ),
			array( 'timeout' => 15 )
		);
		if ( is_wp_error( $resp ) || 200 !== wp_remote_retrieve_response_code( $resp ) ) {
			return array();
		}

		$body    = json_decode( wp_remote_retrieve_body( $resp ), true );
		$catalog = (array) ( $body['providers'] ?? array() );
		set_transient( 'agentic_curated_model_catalog', $catalog, HOUR_IN_SECONDS );
		return $catalog;
	}

	/**
	 * Convert a raw DB row array into a normalized provider array.
	 *
	 * @param array<string, mixed> $row Raw DB row.
	 * @return array<string, mixed> Normalized provider array.
	 */
	private static function row_to_array( array $row ): array {
		$models_raw = $row['models'] ?? '[]';
		$models     = json_decode( $models_raw, true );
		if ( ! is_array( $models ) ) {
			$models = array();
		}

		return array(
			'id'            => (int) ( $row['id'] ?? 0 ),
			'slug'          => (string) ( $row['slug'] ?? '' ),
			'name'          => (string) ( $row['name'] ?? '' ),
			'endpoint'      => (string) ( $row['endpoint'] ?? '' ),
			'default_model' => (string) ( $row['default_model'] ?? '' ),
			'auth_type'     => (string) ( $row['auth_type'] ?? 'bearer' ),
			'req_format'    => (string) ( $row['req_format'] ?? 'openai' ),
			'resp_format'   => (string) ( $row['resp_format'] ?? 'openai' ),
			'requires_key'  => (bool) (int) ( $row['requires_key'] ?? 1 ),
			'api_key'       => self::decrypt( (string) ( $row['api_key'] ?? '' ) ),
			'key_url'       => (string) ( $row['key_url'] ?? '' ),
			'icon'          => (string) ( $row['icon'] ?? '' ),
			'models'        => array_values( $models ),
			'model_pricing' => (array) json_decode( (string) ( $row['model_pricing'] ?? '{}' ), true ),
			'vision_model'  => (string) ( $row['vision_model'] ?? '' ),
			'is_builtin'    => (bool) (int) ( $row['is_builtin'] ?? 0 ),
			'sort_order'    => (int) ( $row['sort_order'] ?? 99 ),
			'provider_type' => (string) ( $row['provider_type'] ?? 'llm' ),
		);
	}

	/**
	 * Write a single provider to the DB table (insert or update by slug).
	 *
	 * @param array<string, mixed> $p Normalized provider array.
	 * @return void
	 */
	private static function write_row( array $p ): void {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;

		$data = array(
			'slug'          => $p['slug'],
			'name'          => $p['name'],
			'endpoint'      => $p['endpoint'],
			'default_model' => $p['default_model'],
			'auth_type'     => $p['auth_type'],
			'req_format'    => $p['req_format'],
			'resp_format'   => $p['resp_format'],
			'requires_key'  => $p['requires_key'] ? 1 : 0,
			'api_key'       => self::encrypt( (string) ( $p['api_key'] ?? '' ) ),
			'key_url'       => $p['key_url'],
			'icon'          => $p['icon'],
			'models'        => wp_json_encode( $p['models'] ?? array() ),
			'model_pricing' => wp_json_encode( $p['model_pricing'] ?? array() ),
			'vision_model'  => $p['vision_model'] ?? '',
			'is_builtin'    => $p['is_builtin'] ? 1 : 0,
			'sort_order'    => (int) $p['sort_order'],
			'provider_type' => $p['provider_type'] ?? 'llm',
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$existing_id = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$wpdb->prepare( 'SELECT id FROM ' . $table . ' WHERE slug = %s', $p['slug'] ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		);

		if ( $existing_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update( $table, $data, array( 'id' => $existing_id ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert( $table, $data );
		}
	}

	/**
	 * Sanitize and normalise a single provider array.
	 *
	 * @param array<string, mixed> $p Raw provider data.
	 * @return array<string, mixed> Normalised provider data.
	 */
	private static function normalize( array $p ): array {
		$auth   = sanitize_key( $p['auth_type'] ?? 'bearer' );
		$format = sanitize_key( $p['req_format'] ?? 'openai' );
		$resp   = sanitize_key( $p['resp_format'] ?? 'openai' );

		// Derive requires_key from auth_type when not explicitly set.
		$requires_key = isset( $p['requires_key'] )
			? (bool) $p['requires_key']
			: 'none' !== $auth;

		$raw_type = $p['provider_type'] ?? 'llm';

		return array(
			'slug'          => sanitize_key( $p['slug'] ?? '' ),
			'name'          => sanitize_text_field( $p['name'] ?? '' ),
			'endpoint'      => sanitize_text_field( $p['endpoint'] ?? '' ),
			'default_model' => sanitize_text_field( $p['default_model'] ?? '' ),
			'auth_type'     => $auth,
			'req_format'    => $format,
			'resp_format'   => $resp,
			'requires_key'  => $requires_key,
			'api_key'       => sanitize_text_field( $p['api_key'] ?? '' ),
			'key_url'       => sanitize_text_field( $p['key_url'] ?? '' ),
			'icon'          => sanitize_text_field( $p['icon'] ?? '' ),
			'models'        => array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $p['models'] ?? array() ) ) ) ),
			'model_pricing' => (array) ( $p['model_pricing'] ?? array() ),
			'vision_model'  => sanitize_text_field( $p['vision_model'] ?? '' ),
			'is_builtin'    => (bool) ( $p['is_builtin'] ?? false ),
			'sort_order'    => (int) ( $p['sort_order'] ?? 99 ),
			'provider_type' => in_array( $raw_type, array( 'llm', 'service' ), true ) ? $raw_type : 'llm',
		);
	}

	/**
	 * Canonical built-in provider definitions.
	 *
	 * Used to seed the DB on first activation and to sync non-user-configurable
	 * fields (endpoint, models, formats) on every admin load.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function builtin_providers(): array {
		return array_map(
			array( self::class, 'normalize' ),
			array(
				array(
					'slug'          => 'agentic',
					'name'          => 'Agentic AI',
					'endpoint'      => 'https://chat.agentic-plugin.com:11435/v1beta/models/%MODEL%:generateContent',
					'default_model' => 'gemini-2.5-flash',
					'auth_type'     => 'bearer',
					'req_format'    => 'google',
					'resp_format'   => 'google',
					'requires_key'  => true,
					'key_url'       => '',
					'icon'          => 'agentic',
					// Gemini 3.x models added alongside 2.5 (not replacing) ahead of the Oct 20, 2026
					// Vertex AI deprecation; 2.5 keeps working under ELA pricing through Jan 28, 2027.
					'models'        => array( 'gemini-2.5-flash', 'gemini-2.5-flash-lite', 'gemini-2.5-pro', 'gemma-4-26b', 'gemini-3.1-flash-lite', 'gemini-3.5-flash-lite', 'gemini-3.5-flash', 'gemini-3.6-flash' ),
					'model_pricing' => array(), // Populated at runtime via "Get Latest Pricing" from wp_marketplace_pricing.
					'is_builtin'    => true,
					'sort_order'    => 0,
				),
				array(
					'slug'          => 'xai',
					'name'          => 'xAI (Grok)',
					// phpcs:ignore PluginCheck.CodeAnalysis.AIProvider.DirectIntegration -- Multi-provider agent tool-calling is this plugin's core feature; the WP AI Client SDK explicitly excludes agent/tool orchestration from its scope (php-ai-client REQUIREMENTS.md: "no agents"), and no official connector exists for this provider yet.
					'endpoint'      => 'https://api.x.ai/v1/chat/completions',
					'default_model' => 'grok-3',
					'auth_type'     => 'bearer',
					'req_format'    => 'openai',
					'resp_format'   => 'openai',
					'requires_key'  => true,
					'key_url'       => 'https://console.x.ai',
					'icon'          => 'xai',
					'models'        => array( 'grok-3', 'grok-3-mini', 'grok-2-1212', 'grok-2-vision-1212', 'grok-beta' ),
					'model_pricing' => array(
						'grok-3'             => array(
							'in'  => 3.00,
							'out' => 15.00,
						),
						'grok-3-mini'        => array(
							'in'  => 0.30,
							'out' => 0.50,
						),
						'grok-2-1212'        => array(
							'in'  => 2.00,
							'out' => 10.00,
						),
						'grok-2-vision-1212' => array(
							'in'  => 2.00,
							'out' => 10.00,
						),
						'grok-beta'          => array(
							'in'  => 5.00,
							'out' => 15.00,
						),
					),
					'is_builtin'    => true,
					'sort_order'    => 1,
				),
				array(
					'slug'          => 'openai',
					'name'          => 'OpenAI',
					// phpcs:ignore PluginCheck.CodeAnalysis.AIProvider.DirectIntegration -- Multi-provider agent tool-calling is this plugin's core feature; the WP AI Client SDK explicitly excludes agent/tool orchestration from its scope (php-ai-client REQUIREMENTS.md: "no agents"), and no official connector exists for this provider yet.
					'endpoint'      => 'https://api.openai.com/v1/chat/completions',
					'default_model' => 'gpt-4.1-mini',
					'auth_type'     => 'bearer',
					'req_format'    => 'openai',
					'resp_format'   => 'openai',
					'requires_key'  => true,
					'key_url'       => 'https://platform.openai.com/api-keys',
					'icon'          => 'openai',
					// Prefer currently documented API chat models (ChatGPT product retirements do not always match the API).
					'models'        => array( 'gpt-4.1', 'gpt-4.1-mini', 'gpt-4.1-nano', 'gpt-4o', 'gpt-4o-mini', 'o4-mini', 'o3', 'o3-mini', 'o1', 'o1-mini' ),
					'model_pricing' => array(
						'gpt-4.1'      => array(
							'in'  => 2.00,
							'out' => 8.00,
						),
						'gpt-4.1-mini' => array(
							'in'  => 0.40,
							'out' => 1.60,
						),
						'gpt-4.1-nano' => array(
							'in'  => 0.10,
							'out' => 0.40,
						),
						'gpt-4o'       => array(
							'in'  => 2.50,
							'out' => 10.00,
						),
						'gpt-4o-mini'  => array(
							'in'  => 0.15,
							'out' => 0.60,
						),
						'o4-mini'      => array(
							'in'  => 1.10,
							'out' => 4.40,
						),
						'o3'           => array(
							'in'  => 10.00,
							'out' => 40.00,
						),
						'o3-mini'      => array(
							'in'  => 1.10,
							'out' => 4.40,
						),
						'o1'           => array(
							'in'  => 15.00,
							'out' => 60.00,
						),
						'o1-mini'      => array(
							'in'  => 3.00,
							'out' => 12.00,
						),
					),
					'is_builtin'    => true,
					'sort_order'    => 2,
				),
				array(
					'slug'          => 'anthropic',
					'name'          => 'Anthropic (Claude)',
					// phpcs:ignore PluginCheck.CodeAnalysis.AIProvider.DirectIntegration -- Multi-provider agent tool-calling is this plugin's core feature; the WP AI Client SDK explicitly excludes agent/tool orchestration from its scope (php-ai-client REQUIREMENTS.md: "no agents"), and no official connector exists for this provider yet.
					'endpoint'      => 'https://api.anthropic.com/v1/messages',
					'default_model' => 'claude-sonnet-4-6',
					'auth_type'     => 'anthropic',
					'req_format'    => 'anthropic',
					'resp_format'   => 'anthropic',
					'requires_key'  => true,
					'key_url'       => 'https://platform.claude.com/settings/keys',
					'icon'          => 'anthropic',
					'models'        => array( 'claude-sonnet-4-6', 'claude-opus-4-6', 'claude-haiku-4-5-20251001', 'claude-sonnet-4-5-20250929', 'claude-opus-4-20250514' ),
					'model_pricing' => array(
						'claude-sonnet-4-6'          => array(
							'in'  => 3.00,
							'out' => 15.00,
						),
						'claude-opus-4-6'            => array(
							'in'  => 15.00,
							'out' => 75.00,
						),
						'claude-haiku-4-5-20251001'  => array(
							'in'  => 0.80,
							'out' => 4.00,
						),
						'claude-sonnet-4-5-20250929' => array(
							'in'  => 3.00,
							'out' => 15.00,
						),
						'claude-opus-4-20250514'     => array(
							'in'  => 15.00,
							'out' => 75.00,
						),
					),
					'is_builtin'    => true,
					'sort_order'    => 3,
				),
				array(
					'slug'          => 'google',
					'name'          => 'Google (Gemini)',
					// phpcs:ignore PluginCheck.CodeAnalysis.AIProvider.DirectIntegration -- Multi-provider agent tool-calling is this plugin's core feature; the WP AI Client SDK explicitly excludes agent/tool orchestration from its scope (php-ai-client REQUIREMENTS.md: "no agents"), and no official connector exists for this provider yet.
					'endpoint'      => 'https://generativelanguage.googleapis.com/v1beta/models/%MODEL%:generateContent?key=%KEY%',
					'default_model' => 'gemini-2.5-flash',
					'auth_type'     => 'url_key',
					'req_format'    => 'google',
					// Must be google — Gemini generateContent responses are not OpenAI-shaped.
					'resp_format'   => 'google',
					'requires_key'  => true,
					'key_url'       => 'https://aistudio.google.com/apikey',
					'icon'          => 'google',
					// Stable Gemini API model IDs (avoid -exp / 1.5 aliases that 404 after shutdowns).
					// Gemini 3.x added alongside 2.5 ahead of the Oct 20, 2026 Vertex AI deprecation
					// (2.5 keeps working under ELA pricing through Jan 28, 2027 — see
					// GEMINI-3-MIGRATION-BRIEF.md). default_model deliberately left on 2.5-flash.
					'models'        => array( 'gemini-2.5-pro', 'gemini-2.5-flash', 'gemini-2.5-flash-lite', 'gemini-2.0-flash', 'gemini-3.1-flash-lite', 'gemini-3.5-flash-lite', 'gemini-3.5-flash', 'gemini-3.6-flash' ),
					'model_pricing' => array(
						'gemini-2.5-pro'        => array(
							'in'  => 1.25,
							'out' => 10.00,
						),
						'gemini-2.5-flash'      => array(
							'in'  => 0.30,
							'out' => 2.50,
						),
						'gemini-2.5-flash-lite' => array(
							'in'  => 0.10,
							'out' => 0.40,
						),
						'gemini-2.0-flash'      => array(
							'in'  => 0.10,
							'out' => 0.40,
						),
						// PLACEHOLDER — mirrored from the 2.5 tier they replace, pending real
						// Vertex AI Model Garden pricing. Do not treat as accurate billing data.
						'gemini-3.1-flash-lite' => array(
							'in'  => 0.10,
							'out' => 0.40,
						),
						'gemini-3.5-flash-lite' => array(
							'in'  => 0.10,
							'out' => 0.40,
						),
						'gemini-3.5-flash'      => array(
							'in'  => 0.30,
							'out' => 2.50,
						),
						'gemini-3.6-flash'      => array(
							'in'  => 0.30,
							'out' => 2.50,
						),
					),
					'is_builtin'    => true,
					'sort_order'    => 4,
				),
				array(
					'slug'          => 'mistral',
					'name'          => 'Mistral AI',
					// phpcs:ignore PluginCheck.CodeAnalysis.AIProvider.DirectIntegration -- Multi-provider agent tool-calling is this plugin's core feature; the WP AI Client SDK explicitly excludes agent/tool orchestration from its scope (php-ai-client REQUIREMENTS.md: "no agents"), and no official connector exists for this provider yet.
					'endpoint'      => 'https://api.mistral.ai/v1/chat/completions',
					'default_model' => 'mistral-large-latest',
					'auth_type'     => 'bearer',
					'req_format'    => 'openai',
					'resp_format'   => 'openai',
					'requires_key'  => true,
					'key_url'       => 'https://console.mistral.ai/api-keys',
					'icon'          => 'mistral',
					'models'        => array( 'mistral-large-latest', 'mistral-medium-latest', 'mistral-small-latest', 'codestral-latest', 'open-mixtral-8x22b' ),
					'model_pricing' => array(
						'mistral-large-latest'  => array(
							'in'  => 2.00,
							'out' => 6.00,
						),
						'mistral-medium-latest' => array(
							'in'  => 2.00,
							'out' => 6.00,
						),
						'mistral-small-latest'  => array(
							'in'  => 0.20,
							'out' => 0.60,
						),
						'codestral-latest'      => array(
							'in'  => 0.30,
							'out' => 0.90,
						),
						'open-mixtral-8x22b'    => array(
							'in'  => 2.00,
							'out' => 6.00,
						),
					),
					'is_builtin'    => true,
					'sort_order'    => 5,
				),
				array(
					'slug'          => 'llama',
					'name'          => 'Meta Llama',
					'endpoint'      => 'https://api.llama.com/v1/chat/completions',
					'default_model' => 'Llama-3.3-70B-Instruct',
					'auth_type'     => 'bearer',
					'req_format'    => 'openai',
					'resp_format'   => 'openai',
					'requires_key'  => true,
					'key_url'       => 'https://llama.meta.com/docs/getting_models/api',
					'icon'          => 'llama',
					'models'        => array( 'Llama-3.3-70B-Instruct', 'Llama-3.2-90B-Vision-Instruct', 'Llama-3.1-405B-Instruct', 'Llama-3.1-70B-Instruct' ),
					'model_pricing' => array(
						'Llama-3.3-70B-Instruct'        => array(
							'in'  => 0.20,
							'out' => 0.20,
						),
						'Llama-3.2-90B-Vision-Instruct' => array(
							'in'  => 0.27,
							'out' => 0.27,
						),
						'Llama-3.1-405B-Instruct'       => array(
							'in'  => 0.80,
							'out' => 0.80,
						),
						'Llama-3.1-70B-Instruct'        => array(
							'in'  => 0.20,
							'out' => 0.20,
						),
					),
					'is_builtin'    => true,
					'sort_order'    => 6,
				),
				array(
					'slug'          => 'cohere',
					'name'          => 'Cohere',
					// phpcs:ignore PluginCheck.CodeAnalysis.AIProvider.DirectIntegration -- Multi-provider agent tool-calling is this plugin's core feature; the WP AI Client SDK explicitly excludes agent/tool orchestration from its scope (php-ai-client REQUIREMENTS.md: "no agents"), and no official connector exists for this provider yet.
					'endpoint'      => 'https://api.cohere.com/v2/chat',
					'default_model' => 'command-r-plus-08-2024',
					'auth_type'     => 'bearer',
					'req_format'    => 'openai',
					'resp_format'   => 'cohere',
					'requires_key'  => true,
					'key_url'       => 'https://dashboard.cohere.com/api-keys',
					'icon'          => 'cohere',
					'models'        => array( 'command-r-plus-08-2024', 'command-r-plus', 'command-r-08-2024', 'command-r', 'command' ),
					'model_pricing' => array(
						'command-r-plus-08-2024' => array(
							'in'  => 2.50,
							'out' => 10.00,
						),
						'command-r-plus'         => array(
							'in'  => 2.50,
							'out' => 10.00,
						),
						'command-r-08-2024'      => array(
							'in'  => 0.15,
							'out' => 0.60,
						),
						'command-r'              => array(
							'in'  => 0.15,
							'out' => 0.60,
						),
						'command'                => array(
							'in'  => 1.00,
							'out' => 2.00,
						),
					),
					'is_builtin'    => true,
					'sort_order'    => 7,
				),
				array(
					'slug'          => 'deepseek',
					'name'          => 'DeepSeek',
					// phpcs:ignore PluginCheck.CodeAnalysis.AIProvider.DirectIntegration -- Multi-provider agent tool-calling is this plugin's core feature; the WP AI Client SDK explicitly excludes agent/tool orchestration from its scope (php-ai-client REQUIREMENTS.md: "no agents"), and no official connector exists for this provider yet.
					'endpoint'      => 'https://api.deepseek.com/chat/completions',
					'default_model' => 'deepseek-v4-flash',
					'auth_type'     => 'bearer',
					'req_format'    => 'openai',
					'resp_format'   => 'openai',
					'requires_key'  => true,
					'key_url'       => 'https://platform.deepseek.com/api_keys',
					'icon'          => 'deepseek',
					// v4 is current; chat/reasoner remain as transitional aliases (deprecated mid-2026).
					'models'        => array( 'deepseek-v4-flash', 'deepseek-v4-pro', 'deepseek-chat', 'deepseek-reasoner' ),
					'model_pricing' => array(
						'deepseek-v4-flash' => array(
							'in'  => 0.14,
							'out' => 0.28,
						),
						'deepseek-v4-pro'   => array(
							'in'  => 0.55,
							'out' => 2.19,
						),
						'deepseek-chat'     => array(
							'in'  => 0.14,
							'out' => 0.28,
						),
						'deepseek-reasoner' => array(
							'in'  => 0.55,
							'out' => 2.19,
						),
					),
					'is_builtin'    => true,
					'sort_order'    => 8,
				),
				array(
					'slug'          => 'kimi',
					'name'          => 'Kimi (Moonshot AI)',
					// Global OpenAI-compatible endpoint (China: https://api.moonshot.cn/v1/chat/completions).
					'endpoint'      => 'https://api.moonshot.ai/v1/chat/completions',
					'default_model' => 'kimi-k2.5',
					'auth_type'     => 'bearer',
					'req_format'    => 'openai',
					'resp_format'   => 'openai',
					'requires_key'  => true,
					'key_url'       => 'https://platform.kimi.ai/console/api-keys',
					'icon'          => 'kimi',
					// Stable public model IDs — K3 always uses thinking mode; K2.x for general chat.
					'models'        => array( 'kimi-k3', 'kimi-k2.6', 'kimi-k2.5', 'kimi-k2.5-turbo', 'moonshot-v1-128k', 'moonshot-v1-32k', 'moonshot-v1-8k' ),
					'model_pricing' => array(
						'kimi-k3'          => array(
							'in'  => 3.00,
							'out' => 15.00,
						),
						'kimi-k2.6'        => array(
							'in'  => 0.60,
							'out' => 2.50,
						),
						'kimi-k2.5'        => array(
							'in'  => 0.60,
							'out' => 2.50,
						),
						'kimi-k2.5-turbo'  => array(
							'in'  => 0.30,
							'out' => 1.20,
						),
						'moonshot-v1-128k' => array(
							'in'  => 2.00,
							'out' => 5.00,
						),
						'moonshot-v1-32k'  => array(
							'in'  => 1.00,
							'out' => 3.00,
						),
						'moonshot-v1-8k'   => array(
							'in'  => 0.20,
							'out' => 2.00,
						),
					),
					'vision_model'  => 'kimi-k2.6',
					'is_builtin'    => true,
					'sort_order'    => 9,
				),
				array(
					'slug'          => 'ollama',
					'name'          => 'Ollama (Local)',
					'endpoint'      => 'http://localhost:11434/v1/chat/completions',
					'default_model' => 'llama3.2',
					'auth_type'     => 'none',
					'req_format'    => 'openai',
					'resp_format'   => 'openai',
					'requires_key'  => false,
					'key_url'       => '',
					'icon'          => 'ollama',
					'models'        => array(), // Dynamic per installation — use ↺ Refresh to populate.
					'model_pricing' => array(), // Local — no charge.
					'vision_model'  => '',
					'is_builtin'    => true,
					'sort_order'    => 10,
				),

				// ── Agentic service endpoints (provider_type = 'service') ───────────────
				// These are not LLM providers. They store only the base URL so it can be
				// overridden by administrators from Settings → Endpoints without code changes.

				array(
					'slug'          => 'agentic-api',
					'name'          => 'Agentic API',
					'endpoint'      => 'https://agentic-plugin.com',
					'default_model' => '',
					'auth_type'     => 'none',
					'req_format'    => 'openai',
					'resp_format'   => 'openai',
					'requires_key'  => false,
					'key_url'       => '',
					'icon'          => 'agentic',
					'models'        => array(),
					'model_pricing' => array(),
					'vision_model'  => '',
					'is_builtin'    => true,
					'sort_order'    => 10,
					'provider_type' => 'service',
				),
				array(
					'slug'          => 'agentic-chat',
					'name'          => 'Agentic Chat API',
					'endpoint'      => 'https://chat.agentic-plugin.com:11435',
					'default_model' => '',
					'auth_type'     => 'none',
					'req_format'    => 'openai',
					'resp_format'   => 'openai',
					'requires_key'  => false,
					'key_url'       => '',
					'icon'          => 'agentic',
					'models'        => array(),
					'model_pricing' => array(),
					'vision_model'  => '',
					'is_builtin'    => true,
					'sort_order'    => 11,
					'provider_type' => 'service',
				),
				array(
					'slug'          => 'agentic-tts',
					'name'          => 'Agentic TTS',
					'endpoint'      => 'https://tts.agentic-plugin.com',
					'default_model' => '',
					'auth_type'     => 'none',
					'req_format'    => 'openai',
					'resp_format'   => 'openai',
					'requires_key'  => false,
					'key_url'       => '',
					'icon'          => 'agentic',
					'models'        => array(),
					'model_pricing' => array(),
					'vision_model'  => '',
					'is_builtin'    => true,
					'sort_order'    => 13,
					'provider_type' => 'service',
				),
				array(
					'slug'          => 'agentic-imagegen',
					'name'          => 'Agentic Image Gen',
					'endpoint'      => 'https://imagegen.agentic-plugin.com',
					'default_model' => '',
					'auth_type'     => 'none',
					'req_format'    => 'openai',
					'resp_format'   => 'openai',
					'requires_key'  => false,
					'key_url'       => '',
					'icon'          => 'agentic',
					'models'        => array(),
					'model_pricing' => array(),
					'vision_model'  => '',
					'is_builtin'    => true,
					'sort_order'    => 14,
					'provider_type' => 'service',
				),
				array(
					'slug'          => 'agentic-videogen',
					'name'          => 'Agentic Video Gen',
					'endpoint'      => 'https://videogen.agentic-plugin.com',
					'default_model' => '',
					'auth_type'     => 'none',
					'req_format'    => 'openai',
					'resp_format'   => 'openai',
					'requires_key'  => false,
					'key_url'       => '',
					'icon'          => 'agentic',
					'models'        => array(),
					'model_pricing' => array(),
					'vision_model'  => '',
					'is_builtin'    => true,
					'sort_order'    => 15,
					'provider_type' => 'service',
				),
			)
		);
	}
}
