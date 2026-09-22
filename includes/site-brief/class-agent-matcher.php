<?php
/**
 * Site Brief best-fit agent matcher.
 *
 * Replaces a checker's hardcoded agent slug with automatic discovery: match
 * a finding's required tools against every candidate agent (installed,
 * bundled, and — opt-in — the marketplace catalog) and pick the best fit.
 * An installed capable agent always wins over an upsell.
 *
 * @package    Agent_Builder
 * @subpackage Site_Brief
 * @since      3.5.0
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic\Site_Brief;

use Agentic\Service_Registry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves the best-fit agent for a checker's required tools.
 */
class Agent_Matcher {

	/**
	 * Option holding the resolved checker_id => agent map (Part B cache).
	 */
	public const MAP_OPTION = 'agent_builder_sb_agent_map';

	/**
	 * How long a resolved map entry stays valid before being recomputed.
	 */
	public const MAP_TTL = DAY_IN_SECONDS;

	/**
	 * Transient holding the fetched marketplace catalog.
	 */
	public const CATALOG_TRANSIENT = 'agent_builder_sb_agent_catalog';

	/**
	 * Option holding the last successfully fetched catalog, used as a
	 * graceful-offline fallback when a refresh fails.
	 */
	public const CATALOG_LAST_GOOD_OPTION = 'agent_builder_sb_agent_catalog_last_good';

	/**
	 * Default fallback agent when nothing fully covers a checker's tools.
	 */
	private const DEFAULT_FALLBACK_AGENT = 'wordpress-assistant';

	/**
	 * Per-request memoised candidate pool.
	 *
	 * @var array<int, array<string, mixed>>|null
	 */
	private static ?array $candidates_cache = null;

	/**
	 * Per-request memoised marketplace catalog.
	 *
	 * @var array<int, array<string, mixed>>|null
	 */
	private static ?array $catalog_cache = null;

	/**
	 * Register cache-invalidation hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'agent_builder_agent_activated', array( self::class, 'invalidate_map' ) );
		add_action( 'agent_builder_agent_deactivated', array( self::class, 'invalidate_map' ) );
		add_action( 'agent_builder_agent_deleted', array( self::class, 'invalidate_map' ) );
	}

	/**
	 * Resolve (and cache) the best-fit agent for one checker.
	 *
	 * @param Site_Brief_Checker $checker Checker instance.
	 * @return array<string, mixed> {slug, label, installed, source, upsell_url, resolved_at}
	 */
	public static function resolve_for_checker( Site_Brief_Checker $checker ): array {
		$id  = $checker->get_id();
		$map = self::get_map();

		if ( isset( $map[ $id ] ) && is_array( $map[ $id ] ) && self::is_fresh( $map[ $id ] ) ) {
			return $map[ $id ];
		}

		$resolved                = self::resolve( $checker->get_tools(), $checker->get_category(), $checker->get_agent() );
		$resolved['resolved_at'] = time();
		$map[ $id ]              = $resolved;
		update_option( self::MAP_OPTION, $map, false );

		return $resolved;
	}

	/**
	 * Resolve the best-fit agent for an arbitrary set of required tools.
	 *
	 * Eligibility requires full coverage of every required tool — a partial
	 * match cannot fully service the finding. Free/premium is never a ranking
	 * factor; the most focused agent that can do the whole job wins.
	 *
	 * @param string[] $required_tools Tool slugs the finding needs.
	 * @param string   $category       Optional checker category for tiebreaks.
	 * @param string   $fallback_agent Hint used when nothing fully covers the tools.
	 * @return array<string, mixed> {slug, label, installed, source, upsell_url}
	 */
	public static function resolve( array $required_tools, string $category = '', string $fallback_agent = '' ): array {
		$required = array_values( array_unique( array_map( 'strval', $required_tools ) ) );

		$eligible = array();
		foreach ( self::candidates() as $candidate ) {
			if ( self::covers( $candidate['tools'], $required ) ) {
				$eligible[] = $candidate;
			}
		}

		if ( empty( $eligible ) ) {
			return self::fallback_result( $fallback_agent );
		}

		usort(
			$eligible,
			static function ( array $a, array $b ) use ( $category ): int {
				return self::compare( $a, $b, $category );
			}
		);

		$best = $eligible[0];

		return array(
			'slug'       => $best['slug'],
			'label'      => $best['label'],
			'installed'  => $best['installed'],
			'source'     => $best['source'],
			'upsell_url' => $best['installed'] ? null : self::upsell_url( $best['slug'] ),
		);
	}

	/**
	 * Whether an agent's declared tools cover every required tool.
	 *
	 * @param string[] $agent_tools Tools the candidate agent declares.
	 * @param string[] $required    Tools the finding requires.
	 * @return bool
	 */
	private static function covers( array $agent_tools, array $required ): bool {
		foreach ( $required as $tool ) {
			if ( ! in_array( $tool, $agent_tools, true ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Ranking comparator among fully-covering candidates.
	 *
	 * Order: installed before uninstalled, fewest total tools (most focused),
	 * category match, higher marketplace rating, higher downloads, then a
	 * stable slug tiebreak.
	 *
	 * @param array<string, mixed> $a        First candidate.
	 * @param array<string, mixed> $b        Second candidate.
	 * @param string                $category Checker category, or ''.
	 * @return int
	 */
	private static function compare( array $a, array $b, string $category ): int {
		if ( $a['installed'] !== $b['installed'] ) {
			return $a['installed'] ? -1 : 1;
		}

		$tool_count_a = count( $a['tools'] );
		$tool_count_b = count( $b['tools'] );
		if ( $tool_count_a !== $tool_count_b ) {
			return $tool_count_a <=> $tool_count_b;
		}

		if ( '' !== $category ) {
			$match_a = ( $a['category'] === $category ) ? 0 : 1;
			$match_b = ( $b['category'] === $category ) ? 0 : 1;
			if ( $match_a !== $match_b ) {
				return $match_a <=> $match_b;
			}
		}

		if ( $a['rating'] !== $b['rating'] ) {
			return $b['rating'] <=> $a['rating'];
		}

		if ( $a['downloads'] !== $b['downloads'] ) {
			return $b['downloads'] <=> $a['downloads'];
		}

		return strcmp( $a['slug'], $b['slug'] );
	}

	/**
	 * Fallback when no candidate fully covers the required tools.
	 *
	 * @param string $fallback_agent Checker's get_agent() hint.
	 * @return array<string, mixed>
	 */
	private static function fallback_result( string $fallback_agent ): array {
		$slug      = '' !== $fallback_agent ? $fallback_agent : self::DEFAULT_FALLBACK_AGENT;
		$installed = self::is_installed( $slug );

		return array(
			'slug'       => $slug,
			'label'      => self::label_for_slug( $slug ),
			'installed'  => $installed,
			'source'     => $installed ? 'installed' : 'fallback',
			'upsell_url' => $installed ? null : self::upsell_url( $slug ),
		);
	}

	/**
	 * Full candidate pool: installed/bundled agents first, then any
	 * marketplace-only slug not already installed.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function candidates(): array {
		if ( null !== self::$candidates_cache ) {
			return self::$candidates_cache;
		}

		$pool = array();
		foreach ( self::installed_candidates() as $slug => $candidate ) {
			$pool[ $slug ] = $candidate;
		}
		foreach ( self::marketplace_candidates() as $slug => $candidate ) {
			if ( isset( $pool[ $slug ] ) ) {
				continue;
			}
			$pool[ $slug ] = $candidate;
		}

		self::$candidates_cache = array_values( $pool );
		return self::$candidates_cache;
	}

	/**
	 * Installed + bundled agents, with their declared tool lists.
	 *
	 * @return array<string, array<string, mixed>> Keyed by slug.
	 */
	private static function installed_candidates(): array {
		$out = array();
		if ( ! class_exists( '\Agentic_Agent_Registry' ) ) {
			return $out;
		}

		$agents = \Agentic_Agent_Registry::get_instance()->get_installed_agents();
		foreach ( $agents as $slug => $agent ) {
			if ( ! is_array( $agent ) ) {
				continue;
			}
			$tools = self::tools_for_installed_agent( $agent );
			if ( null === $tools ) {
				continue;
			}
			$out[ $slug ] = array(
				'slug'      => (string) $slug,
				'label'     => (string) ( $agent['name'] ?? self::deslug( (string) $slug ) ),
				'installed' => true,
				'source'    => ! empty( $agent['bundled'] ) ? 'bundled' : 'installed',
				'category'  => (string) ( $agent['category'] ?? '' ),
				'tools'     => $tools,
				'rating'    => 0.0,
				'downloads' => 0,
			);
		}
		return $out;
	}

	/**
	 * Declared tool list for an installed agent record, or null when it
	 * cannot be determined (so it is excluded from matching rather than
	 * guessed at).
	 *
	 * @param array<string, mixed> $agent Agent record from the registry.
	 * @return string[]|null
	 */
	private static function tools_for_installed_agent( array $agent ): ?array {
		if ( is_array( $agent['db_manifest'] ?? null ) ) {
			return array_values( array_map( 'strval', (array) ( $agent['db_manifest']['tools'] ?? array() ) ) );
		}

		$directory = (string) ( $agent['directory'] ?? '' );
		if ( '' === $directory ) {
			return null;
		}

		$manifest_file = rtrim( $directory, '/' ) . '/agent.json';
		if ( ! file_exists( $manifest_file ) ) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local agent.json, not a remote URL.
		$decoded = json_decode( (string) file_get_contents( $manifest_file ), true );
		if ( ! is_array( $decoded ) ) {
			return null;
		}

		return array_values( array_map( 'strval', (array) ( $decoded['tools'] ?? array() ) ) );
	}

	/**
	 * Marketplace catalog agents, keyed by slug.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function marketplace_candidates(): array {
		$out = array();
		foreach ( self::fetch_catalog() as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$slug = (string) ( $entry['slug'] ?? '' );
			if ( '' === $slug ) {
				continue;
			}
			$out[ $slug ] = array(
				'slug'      => $slug,
				'label'     => (string) ( $entry['name'] ?? self::deslug( $slug ) ),
				'installed' => false,
				'source'    => 'marketplace',
				'category'  => self::first_category( $entry['categories'] ?? array() ),
				'tools'     => array_values( array_map( 'strval', (array) ( $entry['tools'] ?? array() ) ) ),
				'rating'    => (float) ( $entry['rating'] ?? 0 ),
				'downloads' => (int) ( $entry['downloads'] ?? 0 ),
				'url'       => (string) ( $entry['url'] ?? '' ),
			);
		}
		return $out;
	}

	/**
	 * First category slug/name from a catalog entry's categories field.
	 *
	 * @param mixed $categories Raw categories value from the catalog.
	 * @return string
	 */
	private static function first_category( $categories ): string {
		if ( ! is_array( $categories ) || empty( $categories ) ) {
			return '';
		}
		$first = reset( $categories );
		if ( is_array( $first ) ) {
			return (string) ( $first['slug'] ?? $first['name'] ?? '' );
		}
		return (string) $first;
	}

	/**
	 * Fetch (and cache) the marketplace agent catalog.
	 *
	 * Opt-in gated behind the existing "Refresh model catalog from Agentic"
	 * platform-sync setting — the same Guideline 7 gate the curated
	 * model-pricing catalog already uses. Nothing personally identifying is
	 * sent: it is a plain GET, matching happens entirely locally. A failed or
	 * disabled fetch degrades gracefully to installed/bundled agents only.
	 *
	 * @param bool $explicit True to bypass the opt-in gate (an admin-initiated refresh).
	 * @return array<int, array<string, mixed>>
	 */
	public static function fetch_catalog( bool $explicit = false ): array {
		if ( null !== self::$catalog_cache ) {
			return self::$catalog_cache;
		}

		$cached = get_transient( self::CATALOG_TRANSIENT );
		if ( is_array( $cached ) ) {
			self::$catalog_cache = $cached;
			return $cached;
		}

		if ( ! $explicit && '1' !== (string) get_option( 'agent_builder_allow_platform_sync', '0' ) ) {
			self::$catalog_cache = array();
			return self::$catalog_cache;
		}

		$agents = self::fetch_catalog_remote();
		if ( empty( $agents ) ) {
			$last_good           = get_option( self::CATALOG_LAST_GOOD_OPTION, array() );
			self::$catalog_cache = is_array( $last_good ) ? $last_good : array();
			return self::$catalog_cache;
		}

		set_transient( self::CATALOG_TRANSIENT, $agents, self::MAP_TTL );
		update_option( self::CATALOG_LAST_GOOD_OPTION, $agents, false );
		// A freshly fetched catalog can change which agent best-fits a
		// checker, so any previously cached resolution is now stale.
		self::invalidate_map();

		self::$catalog_cache = $agents;
		return $agents;
	}

	/**
	 * Perform the actual remote GET against the marketplace catalog endpoint.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function fetch_catalog_remote(): array {
		if ( ! class_exists( Service_Registry::class ) ) {
			return array();
		}

		$resp = wp_remote_get(
			Service_Registry::url( 'agentic-api', '/wp-json/agentic-marketplace/v1/agents' ),
			array( 'timeout' => 10 )
		);
		if ( is_wp_error( $resp ) || 200 !== wp_remote_retrieve_response_code( $resp ) ) {
			return array();
		}

		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( ! is_array( $body ) ) {
			return array();
		}

		return array_values( (array) ( $body['agents'] ?? $body ) );
	}

	/**
	 * Human label for a slug, from the candidate pool when known, else a
	 * de-slugified fallback. No hardcoded agent-name map.
	 *
	 * @param string $slug Agent slug.
	 * @return string
	 */
	private static function label_for_slug( string $slug ): string {
		foreach ( self::candidates() as $candidate ) {
			if ( $candidate['slug'] === $slug ) {
				return $candidate['label'];
			}
		}
		return self::deslug( $slug );
	}

	/**
	 * De-slugify a slug into a display label (e.g. "woocommerce-assistant" -> "Woocommerce Assistant").
	 *
	 * @param string $slug Agent slug.
	 * @return string
	 */
	public static function deslug( string $slug ): string {
		return ucwords( str_replace( array( '-', '_' ), ' ', $slug ) );
	}

	/**
	 * Marketplace URL for a slug, preferring the catalog's own listing URL.
	 *
	 * @param string $slug Agent slug.
	 * @return string
	 */
	public static function upsell_url( string $slug ): string {
		foreach ( self::fetch_catalog() as $entry ) {
			if ( is_array( $entry ) && (string) ( $entry['slug'] ?? '' ) === $slug && ! empty( $entry['url'] ) ) {
				return (string) $entry['url'];
			}
		}

		$url = 'https://agentic-plugin.com/marketplace/' . rawurlencode( $slug ) . '/';
		/** This filter is documented in includes/site-brief/class-site-brief-controller.php */
		return (string) apply_filters( 'agentic_site_brief_agent_upsell_url', $url, $slug );
	}

	/**
	 * Whether a slug is installed on this site (bundled counts as installed).
	 *
	 * @param string $slug Agent slug.
	 * @return bool
	 */
	private static function is_installed( string $slug ): bool {
		if ( '' === $slug || ! class_exists( '\Agentic_Agent_Registry' ) ) {
			return false;
		}
		return \Agentic_Agent_Registry::get_instance()->is_agent_installed( $slug );
	}

	/**
	 * Whether a resolved map entry is still within its TTL.
	 *
	 * @param array<string, mixed> $entry Cached resolution.
	 * @return bool
	 */
	private static function is_fresh( array $entry ): bool {
		$resolved_at = (int) ( $entry['resolved_at'] ?? 0 );
		return $resolved_at > 0 && ( time() - $resolved_at ) < self::MAP_TTL;
	}

	/**
	 * The resolved checker_id => agent map.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_map(): array {
		$map = get_option( self::MAP_OPTION, array() );
		return is_array( $map ) ? $map : array();
	}

	/**
	 * Drop the resolved map (a fresh scan recomputes every checker).
	 *
	 * @return void
	 */
	public static function invalidate_map(): void {
		delete_option( self::MAP_OPTION );
	}

	/**
	 * Reset per-request memoisation. Test-only.
	 *
	 * @return void
	 */
	public static function reset_request_cache(): void {
		self::$candidates_cache = null;
		self::$catalog_cache    = null;
	}
}
