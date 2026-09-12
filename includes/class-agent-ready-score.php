<?php
/**
 * Agent-Ready Score — local, read-only diagnostic of how ready this site is
 * for AI agents to discover and safely act on it.
 *
 * Every check in this class runs entirely in-process against this site's own
 * files, database, and active-plugin state. None of the eight checks makes an
 * outbound HTTP request — see readme.txt's "Agent-Ready Score (Local Only)"
 * External Services entry, which depends on that being true. Three checks
 * (llms_txt_present, robots_ai_directives, schema_org_present) deliberately
 * stay lighter than Agent Builder Pro's "AI Radar" agent — see
 * docs/agent-ready-score-brief.md — schema_org_present in particular is a
 * proxy signal (active-plugin detection) rather than real HTML/JSON-LD
 * parsing, because real parsing would require fetching the rendered page
 * over HTTP, which this class must never do.
 *
 * commerce_readiness (see docs/commerce-readiness-brief.md) is scored, not
 * informational — a deliberate departure from sitepassport.org's own
 * necessarily-informational Commerce category. sitepassport.org is an
 * external scanner guessing at compound signals through an auth wall; this
 * class runs inside WordPress with direct wp_get_abilities()/
 * WC()->payment_gateways() access, so there's no ambiguity left to hedge —
 * the same reasoning that already makes capability_exposure/safety_trust
 * scorable only from in here, not from outside.
 *
 * @package    Agent_Builder
 * @subpackage Includes
 * @since      3.3.90
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Computes and stores the Agent-Ready Score.
 */
class Agent_Ready_Score {

	/**
	 * Option storing the last computed score.
	 */
	public const OPTION = 'agentic_score_latest';

	/**
	 * Cron hook for the weekly re-scan.
	 */
	public const CRON_HOOK = 'agentic_rescan_score';

	/**
	 * Weight given to each check's category, used to compute the overall score.
	 */
	private const WEIGHTS = array(
		'high'   => 3,
		'medium' => 2,
		'low'    => 1,
	);

	/**
	 * Register the weekly re-scan cron.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( self::CRON_HOOK, array( self::class, 'rescan' ) );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'weekly', self::CRON_HOOK );
		}
	}

	/**
	 * Get the last computed score, computing it once if none exists yet.
	 *
	 * @return array Score payload: {overall, grade, categories, checked_at}.
	 */
	public static function get_latest(): array {
		$stored = get_option( self::OPTION, array() );
		if ( is_array( $stored ) && ! empty( $stored ) && isset( $stored['overall'] ) ) {
			return $stored;
		}
		return self::compute();
	}

	/**
	 * Force a fresh computation, bypassing the cached option.
	 *
	 * Distinct name from compute() so callers that want to explicitly force a
	 * fresh run (the REST rescan action, the self-expose MCP tool's
	 * force_rescan argument, the weekly cron) read their intent clearly, even
	 * though the body is identical today.
	 *
	 * @return array Score payload.
	 */
	public static function rescan(): array {
		return self::compute();
	}

	/**
	 * Run all eight checks, compute the weighted overall score, and persist it.
	 *
	 * @return array Score payload: {overall, grade, categories, checked_at}.
	 */
	public static function compute(): array {
		$categories = array(
			'mcp_server_reachable'     => self::check_mcp_server_reachable(),
			'webmcp_tools_registered'  => self::check_webmcp_tools_registered(),
			'approval_gate_configured' => self::check_approval_gate_configured(),
			'llms_txt_present'         => self::check_llms_txt_present(),
			'robots_ai_directives'     => self::check_robots_ai_directives(),
			'schema_org_present'       => self::check_schema_org_present(),
			'well_known_manifest'      => self::check_well_known_manifest(),
			'commerce_readiness'       => self::check_commerce_readiness(),
		);

		$data = array(
			'overall'    => self::weighted_overall( $categories ),
			'categories' => $categories,
			'checked_at' => gmdate( 'Y-m-d H:i:s' ),
		);
		$data['grade'] = self::grade_for( $data['overall'] );

		update_option( self::OPTION, $data, false );

		return $data;
	}

	/**
	 * Weighted average across all categories (high=3, medium=2, low=1).
	 *
	 * @param array $categories Check results keyed by check id.
	 * @return int Overall score, 0-100.
	 */
	private static function weighted_overall( array $categories ): int {
		$weighted_sum = 0;
		$weight_total = 0;
		foreach ( $categories as $check ) {
			$weight        = self::WEIGHTS[ $check['weight'] ] ?? 1;
			$weighted_sum += $check['score'] * $weight;
			$weight_total += $weight;
		}
		return $weight_total > 0 ? (int) round( $weighted_sum / $weight_total ) : 0;
	}

	/**
	 * Map an overall score to a letter grade.
	 *
	 * @param int $overall Overall score, 0-100.
	 * @return string One of A/B/C/D/F.
	 */
	private static function grade_for( int $overall ): string {
		return match ( true ) {
			$overall >= 90 => 'A',
			$overall >= 75 => 'B',
			$overall >= 60 => 'C',
			$overall >= 40 => 'D',
			default        => 'F',
		};
	}

	/**
	 * mcp_server_reachable — Capability exposure, high weight, free-fixable.
	 *
	 * Reuses Agentic_Relay_Connect::mcp_readiness(), already built for the
	 * Settings > MCP tab's own "ready/not ready" status per agent.
	 *
	 * MCP is opt-in per agent (off by default — see
	 * Agentic_Relay_Connect::ENABLED_AGENTS_OPTION); an agent nobody has
	 * turned it on for isn't broken, it's the deliberate secure default, so
	 * it's excluded from this score entirely rather than counted as a
	 * failure — otherwise a fresh, correctly-locked-down site would always
	 * score low here, nudging admins to blanket-enable MCP just to raise a
	 * number. Only agents someone actually opted in are checked, and only
	 * a real problem (no manifest, signature mismatch) counts against the
	 * score.
	 *
	 * @return array Check result.
	 */
	private static function check_mcp_server_reachable(): array {
		$all_slugs = class_exists( '\\Agentic_Agent_Registry' )
			? array_keys( \Agentic_Agent_Registry::get_instance()->get_all_instances() )
			: array();

		if ( empty( $all_slugs ) ) {
			return self::result( 0, 'Capability exposure', 'high', true, 'No agents are active yet.' );
		}

		if ( ! class_exists( '\\Agentic_Relay_Connect' ) ) {
			return self::result( 0, 'Capability exposure', 'high', true, 'MCP relay unavailable.' );
		}

		$slugs = array_values( array_filter( $all_slugs, array( '\\Agentic_Relay_Connect', 'is_mcp_enabled' ) ) );

		if ( empty( $slugs ) ) {
			return self::result( 100, 'Capability exposure', 'high', true, 'MCP is off for every active agent — nothing to fix. Enable it for an agent in Settings > MCP if you want external AI apps like Claude Desktop or Cursor to reach it.' );
		}

		$ready_count  = 0;
		$worst_reason = '';
		foreach ( $slugs as $slug ) {
			$readiness = \Agentic_Relay_Connect::mcp_readiness( $slug );
			if ( ! empty( $readiness['ready'] ) ) {
				++$ready_count;
			} elseif ( '' === $worst_reason ) {
				$worst_reason = sprintf( '%s: %s', $slug, $readiness['reason'] ?? 'Not ready.' );
			}
		}

		$score  = (int) round( 100 * $ready_count / count( $slugs ) );
		$detail = 100 === $score
			? sprintf( 'All %d agent(s) with MCP enabled expose a working server.', count( $slugs ) )
			: $worst_reason;

		return self::result( $score, 'Capability exposure', 'high', true, $detail );
	}

	/**
	 * webmcp_tools_registered — Capability exposure, high weight, free-fixable.
	 *
	 * Binary, not a "more is better" scale: a genuinely public-safe tool
	 * catalog is small by design (see enable_webmcp_defaults' own docblock —
	 * risk tier alone does not make a tool safe for an anonymous visitor,
	 * so this deliberately doesn't reward exposing many tools over exposing
	 * the right, hand-curated few).
	 *
	 * @return array Check result.
	 */
	private static function check_webmcp_tools_registered(): array {
		if ( ! Webmcp_Bridge::is_enabled() ) {
			return self::result( 0, 'Capability exposure', 'high', true, 'The WebMCP Bridge is turned off.' );
		}

		$count = count( Abilities_Manifest::get_webmcp_exposed( 'frontend' ) );
		$score = $count > 0 ? 100 : 0;

		$detail = 0 === $count
			? 'No tools are exposed to the frontend yet.'
			: sprintf( '%d tool(s) exposed to the frontend via WebMCP.', $count );

		return self::result( $score, 'Capability exposure', 'high', true, $detail );
	}

	/**
	 * approval_gate_configured — Safety & trust, high weight, free-fixable.
	 *
	 * Fail-closed, no partial credit: any exposed ability with a risk above
	 * medium, or a missing approval-queue table, scores 0 outright.
	 *
	 * @return array Check result.
	 */
	private static function check_approval_gate_configured(): array {
		global $wpdb;

		$table  = $wpdb->prefix . 'agentic_approval_queue';
		$exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( ! $exists ) {
			return self::result( 0, 'Safety & trust', 'high', true, 'The approval queue table is missing.' );
		}

		foreach ( Abilities_Manifest::get_webmcp_exposed() as $exposure ) {
			if ( ! in_array( $exposure['risk'], array( Risk_Level::NONE, Risk_Level::LOW, Risk_Level::MEDIUM ), true ) ) {
				return self::result(
					0,
					'Safety & trust',
					'high',
					true,
					sprintf( '%s (%s) is exposed to WebMCP at an unsafe risk tier.', $exposure['tool_name'], $exposure['agent_slug'] )
				);
			}
		}

		return self::result( 100, 'Safety & trust', 'high', true, 'Every exposed tool has a safe, declared risk tier.' );
	}

	/**
	 * llms_txt_present — Discoverability, medium weight, Pro-fixable only.
	 *
	 * @return array Check result.
	 */
	private static function check_llms_txt_present(): array {
		$path = rtrim( ABSPATH, '/' ) . '/llms.txt';
		if ( ! file_exists( $path ) ) {
			return self::result( 0, 'Discoverability', 'medium', false, 'No llms.txt file found.' );
		}

		$contents    = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local filesystem read, not an HTTP request.
		$well_formed = strlen( $contents ) > 20 && str_starts_with( ltrim( $contents ), '#' );

		return $well_formed
			? self::result( 100, 'Discoverability', 'medium', false, 'llms.txt exists and looks well-formed.' )
			: self::result( 50, 'Discoverability', 'medium', false, 'llms.txt exists but does not look well-formed.' );
	}

	/**
	 * robots_ai_directives — Bot access control, medium weight, Pro-fixable only.
	 *
	 * Reuses Tool_Helpers::get_ai_bots()/parse_robots_bots() — the same bot
	 * list and parsing logic AI Radar's check_robots_txt tool uses — but never
	 * Tool_Helpers::read_robots_txt() itself, since that helper falls back to
	 * a real wp_remote_get() request when no physical robots.txt exists. This
	 * check sources the WordPress-generated ("virtual") case in-process instead,
	 * via the same do_robots action core's own /robots.txt handler fires.
	 *
	 * @return array Check result.
	 */
	private static function check_robots_ai_directives(): array {
		if ( ! class_exists( '\\Agentic\\Tool_Helpers' ) ) {
			return self::result( 0, 'Bot access control', 'medium', false, 'Robots.txt helper unavailable.' );
		}

		$content = self::local_robots_txt_content();
		$parsed  = Tool_Helpers::parse_robots_bots( $content );
		$bots    = Tool_Helpers::get_ai_bots();

		if ( $parsed['blanket_block'] ) {
			return self::result( 0, 'Bot access control', 'medium', false, 'A blanket rule blocks all crawlers, including AI bots.' );
		}

		$addressed = 0;
		foreach ( array_keys( $bots ) as $bot ) {
			if ( isset( $parsed['explicit_bots'][ strtolower( $bot ) ] ) ) {
				++$addressed;
			}
		}

		$score = (int) round( 100 * $addressed / count( $bots ) );
		$detail = 0 === $addressed
			? 'robots.txt has no explicit rules for AI crawlers — silence scores lower than an explicit choice.'
			: sprintf( '%d of %d known AI crawlers have an explicit rule in robots.txt.', $addressed, count( $bots ) );

		return self::result( $score, 'Bot access control', 'medium', false, $detail );
	}

	/**
	 * Get robots.txt content without ever making an HTTP request.
	 *
	 * Mirrors Tool_Helpers::read_robots_txt()'s physical-file branch exactly;
	 * for the virtual (WordPress-generated) case, reimplements core's own
	 * do_robots() algorithm (wp-includes/functions.php) directly — same
	 * `robots_txt` filter, same output — rather than firing the `do_robots`
	 * action and capturing it via output buffering. do_robots() also calls
	 * header() unconditionally, which is harmless during a normal REST/admin
	 * request (headers aren't sent yet at that point) but emits a PHP warning
	 * when this runs somewhere headers are already sent (e.g. `wp eval`) —
	 * reimplementing the content logic avoids that entirely, with identical
	 * output for the case that matters here.
	 *
	 * @return string Robots.txt content.
	 */
	private static function local_robots_txt_content(): string {
		$physical_path = rtrim( ABSPATH, '/' ) . '/robots.txt';
		if ( file_exists( $physical_path ) ) {
			return (string) file_get_contents( $physical_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local filesystem read.
		}

		$public   = (bool) get_option( 'blog_public' );
		$site_url = wp_parse_url( site_url() );
		$path     = ! empty( $site_url['path'] ) ? $site_url['path'] : '';
		$output   = "User-agent: *\n";
		$output  .= "Disallow: {$path}/wp-admin/\n";
		$output  .= "Allow: {$path}/wp-admin/admin-ajax.php\n";

		return (string) apply_filters( 'robots_txt', $output, $public );
	}

	/**
	 * schema_org_present — Content, medium weight, Pro-fixable only.
	 *
	 * Deliberately a proxy signal, not real JSON-LD parsing — parsing the
	 * rendered homepage would require an HTTP fetch (see AI Radar's
	 * check_schema_markup tool, which does exactly that), which this class
	 * must never do. Detects known schema-emitting SEO plugins instead.
	 *
	 * @return array Check result.
	 */
	private static function check_schema_org_present(): array {
		$seo_plugin_active = class_exists( 'WPSEO_Options' )
			|| class_exists( 'RankMath' )
			|| defined( 'AIOSEO_VERSION' );
		$woocommerce_active = class_exists( 'WooCommerce' );

		if ( $seo_plugin_active ) {
			return self::result(
				100,
				'Content',
				'medium',
				false,
				'An active SEO plugin commonly emits Organization/WebSite schema — not verified against rendered output.'
			);
		}

		if ( $woocommerce_active ) {
			return self::result(
				50,
				'Content',
				'medium',
				false,
				'WooCommerce is active (Product schema likely) but no SEO plugin was found for Organization/WebSite schema.'
			);
		}

		return self::result( 0, 'Content', 'medium', false, 'No schema-emitting SEO plugin or WooCommerce detected.' );
	}

	/**
	 * well_known_manifest — Discoverability, low weight, free-fixable, no Pro overlap.
	 *
	 * @return array Check result.
	 */
	private static function check_well_known_manifest(): array {
		return Webmcp_Bridge::is_enabled()
			? self::result( 100, 'Discoverability', 'low', true, '/.well-known/webmcp.json is being served.' )
			: self::result( 0, 'Discoverability', 'low', true, 'The WebMCP Bridge is off, so no manifest is served.' );
	}

	/**
	 * commerce_readiness — Commerce, medium weight, not free-fixable (the
	 * fix — activate Storefront Assistant and turn on the WebMCP Bridge —
	 * is two existing manual toggles, not yet wired to a one-click apply
	 * tool the way well_known_manifest's is).
	 *
	 * Direct first-party introspection, no REST round-trip: unlike
	 * sitepassport.org's external scanner (which has to guess at compound
	 * signals through an auth wall — see docs/commerce-readiness-brief.md's
	 * "two real gotchas"), this class runs inside WordPress and can call
	 * wp_get_abilities() and WC()->payment_gateways() directly.
	 *
	 * The top score tier requires a live, WebMCP-exposed, write-capable
	 * commerce tool (find_webmcp_commerce_tool(), below) — WooCommerce's own
	 * native Abilities API presence alone (checked second, as a secondary
	 * informational signal) is NOT enough. That API is ungated framework
	 * plumbing; only this plugin's own WebMCP Bridge is what this check's
	 * own promise — "an agent can call this safely through Agent Builder's
	 * own risk gate" — actually depends on.
	 *
	 * A site with no commerce platform scores 100 ("not applicable" rather
	 * than "failing") — matching sitepassport.org's index-level "always
	 * present, informational" choice would penalize the majority of sites
	 * this check doesn't apply to at all.
	 *
	 * @return array Check result.
	 */
	private static function check_commerce_readiness(): array {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return self::result( 100, 'Commerce', 'medium', false, 'No commerce platform detected on this site.' );
		}

		$gateways = array();
		if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
			$gateways = array_keys( WC()->payment_gateways()->get_available_payment_gateways() );
		}

		if ( empty( $gateways ) ) {
			return self::result(
				30,
				'Commerce',
				'medium',
				false,
				'WooCommerce is active but no payment gateway is configured yet — nothing for an agent to safely transact with.'
			);
		}

		// The real bar this check's own docblock names — "an agent can call
		// safely through Agent Builder's own risk gate" — is whether a
		// commerce tool is actually WebMCP-exposed right now (see
		// Storefront Assistant), not just whether WooCommerce's native
		// Abilities API happens to be present. Checked first and wins
		// outright: this plugin's own gated pathway doesn't depend on
		// WooCommerce's Abilities API existing at all.
		$live_tool = self::find_webmcp_commerce_tool();

		// WooCommerce's own native abilities — a separate, informational
		// signal. Presence here means the framework-level plumbing exists,
		// not that anything is safely gated for an agent to call; an
		// external scanner (sitepassport.org) can only ever see this one.
		$commerce_abilities = array();
		if ( function_exists( 'wp_get_abilities' ) ) {
			// Plugin Check's wp_function_not_compatible_with_requires_wp check does not
			// recognise this function_exists() guard and still flags the call below as
			// incompatible with "Requires at least: 6.4" — false positive, documented as
			// a justified exception in SUBMISSION-NOTES.md (this branch never executes
			// on WP < 6.9).
			foreach ( wp_get_abilities() as $ability ) {
				$name = method_exists( $ability, 'get_name' ) ? $ability->get_name() : '';
				if ( false !== stripos( $name, 'woocommerce' ) || false !== stripos( $name, 'commerce' ) ) {
					$commerce_abilities[] = $name;
				}
			}
		}

		if ( $live_tool ) {
			return self::result(
				100,
				'Commerce',
				'medium',
				false,
				sprintf(
					'%d payment gateway(s) configured (%s). An agent can safely transact right now via "%s" (%s agent), gated through Agent Builder\'s own approval pipeline.',
					count( $gateways ),
					implode( ', ', $gateways ),
					$live_tool['tool_name'],
					$live_tool['agent_slug']
				)
			);
		}

		$detail = sprintf(
			'%d payment gateway(s) configured (%s), but no commerce tool is exposed to agents through Agent Builder\'s own WebMCP Bridge yet — activate Storefront Assistant and turn on the WebMCP Bridge to close this.%s',
			count( $gateways ),
			implode( ', ', $gateways ),
			empty( $commerce_abilities )
				? ' WooCommerce has not registered any commerce-scoped WordPress Ability either.'
				: sprintf( ' WooCommerce has registered %d commerce-scoped WordPress Ability/Abilities, but that\'s a separate, ungated framework signal — not something Agent Builder itself confirms before use.', count( $commerce_abilities ) )
		);

		return self::result( empty( $commerce_abilities ) ? 50 : 65, 'Commerce', 'medium', false, $detail );
	}

	/**
	 * Find any tool that is both webmcp_expose:true for some agent and
	 * actually reachable (the WebMCP Bridge master switch is on), and whose
	 * own category is 'ecommerce' — the live signal that an agent can
	 * safely transact right now, not just that a tool file exists somewhere.
	 * Deliberately not hardcoded to Storefront Assistant's slug: any agent a
	 * site owner has wired up this way counts.
	 *
	 * @return array{agent_slug:string,tool_name:string,webmcp_context:string,risk:string}|null
	 */
	private static function find_webmcp_commerce_tool(): ?array {
		// Webmcp_Bridge, Abilities_Manifest, and Tool_Loader are this
		// plugin's own classes — already loaded in the same request as this
		// one, same as check_well_known_manifest()'s direct call just above.
		// A class_exists() string guard here would be a real bug, not
		// defensiveness: 'Webmcp_Bridge' is a bare, unqualified name and
		// class_exists() never resolves it against the current namespace
		// (Agentic) the way a bare code reference does — it would always
		// check the global namespace and always return false.
		if ( ! Webmcp_Bridge::is_enabled() ) {
			return null;
		}

		// Specifically a write-capable ecommerce tool (e.g. wc_add_to_cart) —
		// a merely readonly one (wc_browse_products, wc_view_cart) lets an
		// agent look but not act, which doesn't satisfy "can safely
		// transact." Storefront Assistant exposes both kinds together; this
		// only credits the site once the write half is actually reachable.
		foreach ( Abilities_Manifest::get_webmcp_exposed() as $exposure ) {
			$tool = Tool_Loader::get_instance()->get( $exposure['tool_name'] );
			if ( ! $tool || 'ecommerce' !== $tool->get_category() ) {
				continue;
			}
			if ( empty( $tool->get_annotations()['readonly'] ) ) {
				return $exposure;
			}
		}

		return null;
	}

	/**
	 * Build a single check's result array.
	 *
	 * @param int    $score    0-100.
	 * @param string $category Display category.
	 * @param string $weight   'high'|'medium'|'low'.
	 * @param bool   $fixable  Whether a free one-click fix exists for this check.
	 * @param string $detail   Human-readable explanation.
	 * @return array{score:int,status:string,detail:string,category:string,weight:string,fixable:bool}
	 */
	private static function result( int $score, string $category, string $weight, bool $fixable, string $detail ): array {
		return array(
			'score'    => $score,
			'status'   => $score >= 90 ? 'pass' : ( $score > 0 ? 'partial' : 'fail' ),
			'detail'   => $detail,
			'category' => $category,
			'weight'   => $weight,
			'fixable'  => $fixable,
		);
	}
}
