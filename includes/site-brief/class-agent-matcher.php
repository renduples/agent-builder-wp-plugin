<?php
/**
 * Site Brief best-fit agent matcher.
 *
 * Replaces a checker's hardcoded agent slug with automatic discovery: match
 * a finding's required tools against every agent installed on this site
 * (bundled or added by the owner) and pick the best fit. Only installed
 * agents are ever recommended.
 *
 * @package    Agent_Builder
 * @subpackage Site_Brief
 * @since      3.5.0
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic\Site_Brief;

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
	 * @return array<string, mixed> {slug, label, installed, source, resolved_at}
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
	 * match cannot fully service the finding. The most focused installed agent
	 * that can do the whole job wins.
	 *
	 * @param string[] $required_tools Tool slugs the finding needs.
	 * @param string   $category       Optional checker category for tiebreaks.
	 * @param string   $fallback_agent Hint used when nothing fully covers the tools.
	 * @return array<string, mixed> {slug, label, installed, source}
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
			'slug'      => $best['slug'],
			'label'     => $best['label'],
			'installed' => true,
			'source'    => $best['source'],
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
	 * Order: fewest total tools (most focused), category match, then a stable
	 * slug tiebreak.
	 *
	 * @param array<string, mixed> $a        First candidate.
	 * @param array<string, mixed> $b        Second candidate.
	 * @param string                $category Checker category, or ''.
	 * @return int
	 */
	private static function compare( array $a, array $b, string $category ): int {
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

		return strcmp( $a['slug'], $b['slug'] );
	}

	/**
	 * Fallback when no candidate fully covers the required tools.
	 *
	 * Uses the checker's hint when that agent is installed, otherwise the
	 * bundled WordPress Assistant — never an agent the site does not have.
	 *
	 * @param string $fallback_agent Checker's get_agent() hint.
	 * @return array<string, mixed>
	 */
	private static function fallback_result( string $fallback_agent ): array {
		$slug = ( '' !== $fallback_agent && self::is_installed( $fallback_agent ) )
			? $fallback_agent
			: self::DEFAULT_FALLBACK_AGENT;

		return array(
			'slug'      => $slug,
			'label'     => self::label_for_slug( $slug ),
			'installed' => true,
			'source'    => 'fallback',
		);
	}

	/**
	 * Candidate pool: the agents installed on this site (bundled or added by
	 * the owner).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function candidates(): array {
		if ( null !== self::$candidates_cache ) {
			return self::$candidates_cache;
		}

		self::$candidates_cache = array_values( self::installed_candidates() );
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
	}
}
