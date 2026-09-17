<?php
/**
 * Abilities Manifest — loads and validates abilities.json from agent directories
 *
 * Each agent ships with an abilities.json declaring which tools it uses and
 * at what risk level. This class parses those manifests, validates them, and
 * provides the effective risk level for any tool+agent combination.
 *
 * @package    Agent_Builder
 * @subpackage Includes
 * @since      2.4.0
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Parses and enforces abilities.json manifests.
 */
class Abilities_Manifest {

	/**
	 * Cached manifests keyed by agent slug.
	 *
	 * @var array<string, array|null>
	 */
	private static array $cache = array();

	/**
	 * Name of the integrity signature file stored alongside abilities.json.
	 */
	private const SIGNATURE_FILE = 'abilities-signature';

	/**
	 * Resolve the filesystem path to abilities.json for a given agent.
	 *
	 * Searches wp-content/agentic-agents/ first, then the bundled library
	 * directories — the same precedence Agent_Registry uses when it picks which
	 * agent.php to load.
	 *
	 * The two must agree. Agent_Updates::do_update() unpacks a purchased or
	 * uploaded agent into agents_dir, where it takes load priority over any
	 * same-slug library agent. When this method searched library/ first, such an
	 * agent ran its own agent.php and system prompt while being handed the tool
	 * list and risk levels from a *different* bundled manifest. An agent whose
	 * prompt still said "you never make changes to the site" would be offered
	 * tools that change the site.
	 *
	 * Resolving from the agent's own directory also means a site owner who
	 * edits their installed agent's abilities.json sees the edit take effect,
	 * rather than being silently overridden by the bundled copy. It grants no
	 * new privilege: whoever can write agents_dir can already edit agent.php,
	 * and the risk floor plus the extreme-risk block still apply.
	 *
	 * @param string $agent_slug Agent identifier.
	 * @return string|null Absolute path or null if not found.
	 */
	public static function resolve_path( string $agent_slug ): ?string {
		$library_dirs = apply_filters( 'agentic_library_dirs', array( AGENT_BUILDER_DIR . 'library/agents' ) );

		$paths = array( AGENT_BUILDER_AGENTS_DIR . '/' . $agent_slug . '/abilities.json' );
		foreach ( $library_dirs as $dir ) {
			$paths[] = trailingslashit( $dir ) . $agent_slug . '/abilities.json';
		}

		foreach ( $paths as $path ) {
			if ( file_exists( $path ) ) {
				return $path;
			}
		}

		return null;
	}

	/**
	 * Load the abilities.json for a given agent.
	 *
	 * Searches first in the library/ directory, then wp-content/agentic-agents/.
	 *
	 * @param string $agent_slug Agent identifier.
	 * @return array|null Parsed manifest or null if not found.
	 */
	public static function load( string $agent_slug ): ?array {
		if ( array_key_exists( $agent_slug, self::$cache ) ) {
			return self::$cache[ $agent_slug ];
		}

		$path = self::resolve_path( $agent_slug );
		if ( $path ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local plugin file; WP_Filesystem unavailable at manifest load time.
			$json = file_get_contents( $path );
			$data = json_decode( $json, true );

			if ( JSON_ERROR_NONE === json_last_error() && self::is_valid_manifest( $data ) ) {
				self::$cache[ $agent_slug ] = $data;
				return $data;
			}
		}

		self::$cache[ $agent_slug ] = null;
		return null;
	}

	/**
	 * Get the declared risk level for a tool within an agent's manifest.
	 *
	 * Returns null if the agent has no manifest or the tool isn't declared.
	 *
	 * A tool with multiple actions of meaningfully different risk (e.g. a
	 * `manage_x` tool whose `list`/`get` action is a pure read but whose
	 * `create`/`update`/`delete` actions write) can declare a per-action
	 * `risk_by_action` map alongside its flat `risk`; `$call_action` looks
	 * that up first and falls back to the flat value for any action not
	 * listed. This only ever narrows which of the tool's OWN actions the
	 * flat risk applies to — it cannot introduce a risk below the tool's
	 * own intrinsic default, since get_effective_risk() still maxes this
	 * against that floor.
	 *
	 * @param string $agent_slug  Agent identifier.
	 * @param string $tool_name   Tool name (slug).
	 * @param string $call_action Optional `action` argument of the specific call, for tools with risk_by_action.
	 * @return string|null Risk level string or null.
	 */
	public static function get_declared_risk( string $agent_slug, string $tool_name, string $call_action = '' ): ?string {
		$manifest = self::load( $agent_slug );
		if ( ! $manifest ) {
			return null;
		}

		// Check tools (standalone tools).
		if ( isset( $manifest['abilities'][ $tool_name ] ) ) {
			$ability = $manifest['abilities'][ $tool_name ];
			if ( '' !== $call_action && isset( $ability['risk_by_action'][ $call_action ] ) ) {
				$risk = $ability['risk_by_action'][ $call_action ];
				return Risk_Level::is_valid( $risk ) ? $risk : null;
			}
			if ( isset( $ability['risk'] ) ) {
				$risk = $ability['risk'];
				return Risk_Level::is_valid( $risk ) ? $risk : null;
			}
		}

		// Check wp_abilities (wp-extended abilities used as tools).
		if ( ! empty( $manifest['wp_abilities'] ) && is_array( $manifest['wp_abilities'] ) ) {
			foreach ( $manifest['wp_abilities'] as $entry ) {
				if ( ( $entry['name'] ?? '' ) === $tool_name && isset( $entry['risk'] ) ) {
					$risk = $entry['risk'];
					return Risk_Level::is_valid( $risk ) ? $risk : null;
				}
			}
		}

		return null;
	}

	/**
	 * Get the effective risk level for a tool call by a specific agent.
	 *
	 * Effective risk = max( tool_default_risk, manifest_risk, admin_override ).
	 * An agent or admin can only escalate risk, never downgrade — this holds
	 * per call too: passing $call_action only lets a tool's manifest risk
	 * apply more narrowly (via risk_by_action, see get_declared_risk()), it
	 * never lowers the result below the tool's own intrinsic default.
	 *
	 * @param string    $agent_slug  Agent identifier.
	 * @param string    $tool_name   Tool name.
	 * @param Tool_Base $tool        Tool instance (for default risk).
	 * @param string    $call_action Optional `action` argument of the specific call, for tools with risk_by_action.
	 * @return string Effective risk level.
	 */
	public static function get_effective_risk( string $agent_slug, string $tool_name, ?Tool_Base $tool = null, string $call_action = '' ): string {
		// 1. Tool default risk — prefer registry, fall back to instance method.
		$tool_default = Risk_Level::get_tool_default( $tool_name );
		if ( $tool ) {
			$tool_default = Risk_Level::max( $tool_default, $tool->get_risk_level() );
		}

		// 2. Manifest declaration (can only escalate).
		$manifest_risk = self::get_declared_risk( $agent_slug, $tool_name, $call_action );
		$effective     = $manifest_risk
			? Risk_Level::max( $tool_default, $manifest_risk )
			: $tool_default;

		// 3. Admin override (stored in options, can only escalate).
		$overrides = get_option( 'agent_builder_risk_overrides', array() );
		$key       = $agent_slug . ':' . $tool_name;
		if ( isset( $overrides[ $key ] ) && Risk_Level::is_valid( $overrides[ $key ] ) ) {
			$effective = Risk_Level::max( $effective, $overrides[ $key ] );
		}

		return $effective;
	}

	/**
	 * Check if a tool is declared in the agent's manifest.
	 *
	 * @param string $agent_slug Agent identifier.
	 * @param string $tool_name  Tool name.
	 * @return bool
	 */
	public static function is_declared( string $agent_slug, string $tool_name ): bool {
		$manifest = self::load( $agent_slug );
		if ( ! $manifest ) {
			return false;
		}

		if ( isset( $manifest['abilities'][ $tool_name ] ) ) {
			return true;
		}

		if ( ! empty( $manifest['wp_abilities'] ) && is_array( $manifest['wp_abilities'] ) ) {
			foreach ( $manifest['wp_abilities'] as $entry ) {
				$entry_name = (string) ( $entry['name'] ?? '' );
				if ( $entry_name === $tool_name ) {
					return true;
				}
				// Ability names use slashes (woocommerce/product-create); tools use __.
				$as_fn = str_replace( array( '/', '-' ), array( '__', '_' ), $entry_name );
				if ( $as_fn === $tool_name ) {
					return true;
				}
			}
		}

		// Declarative agents list tools in agent.json; treat those as declared even if
		// abilities.json is incomplete (prevents fail-closed blocking after upgrades).
		if ( self::is_listed_in_agent_json( $agent_slug, $tool_name ) ) {
			return true;
		}

		// Framework global tools always allowed when a manifest exists.
		if ( in_array( $tool_name, array( 'report_issue', 'request_human_help' ), true ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Whether a tool is listed in the agent's agent.json tools array.
	 *
	 * @param string $agent_slug Agent slug.
	 * @param string $tool_name  Tool name.
	 */
	private static function is_listed_in_agent_json( string $agent_slug, string $tool_name ): bool {
		$paths = array();
		if ( defined( 'AGENT_BUILDER_AGENTS_DIR' ) ) {
			$paths[] = trailingslashit( AGENT_BUILDER_AGENTS_DIR ) . $agent_slug . '/agent.json';
		}
		if ( defined( 'AGENT_BUILDER_DIR' ) ) {
			$paths[] = trailingslashit( AGENT_BUILDER_DIR ) . 'library/agents/' . $agent_slug . '/agent.json';
		}
		foreach ( $paths as $path ) {
			if ( ! is_readable( $path ) ) {
				continue;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$raw = file_get_contents( $path );
			if ( false === $raw ) {
				continue;
			}
			$data = json_decode( $raw, true );
			if ( ! is_array( $data ) || empty( $data['tools'] ) || ! is_array( $data['tools'] ) ) {
				continue;
			}
			if ( in_array( $tool_name, $data['tools'], true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Get all declared tool names from a manifest.
	 *
	 * @param string $agent_slug Agent identifier.
	 * @return string[] Tool names.
	 */
	public static function get_declared_tools( string $agent_slug ): array {
		$manifest = self::load( $agent_slug );
		if ( ! $manifest ) {
			return array();
		}

		$tools = array();

		if ( isset( $manifest['abilities'] ) && is_array( $manifest['abilities'] ) ) {
			$tools = array_keys( $manifest['abilities'] );
		}

		if ( ! empty( $manifest['wp_abilities'] ) && is_array( $manifest['wp_abilities'] ) ) {
			foreach ( $manifest['wp_abilities'] as $entry ) {
				if ( ! empty( $entry['name'] ) ) {
					$tools[] = $entry['name'];
				}
			}
		}

		return $tools;
	}

	/**
	 * Get the declared output_schema for a tool from any loaded agent manifest.
	 *
	 * Scans all currently-cached manifests for the first one that declares an
	 * `output_schema` for the given tool. This supports abilities.json v2 format
	 * where each ability entry may carry an optional `output_schema` field:
	 *
	 *   "abilities": {
	 *     "db_get_posts": {
	 *       "risk": "none",
	 *       "output_schema": { "type": "object", "properties": { "posts": { "type": "array" } } }
	 *     }
	 *   }
	 *
	 * @param string $tool_name Tool name (snake_case slug).
	 * @return array|null JSON Schema array or null if not declared by any agent.
	 */
	public static function get_output_schema( string $tool_name ): ?array {
		foreach ( self::$cache as $manifest ) {
			if ( ! is_array( $manifest ) || ! isset( $manifest['abilities'][ $tool_name ] ) ) {
				continue;
			}
			$ability = $manifest['abilities'][ $tool_name ];
			if ( isset( $ability['output_schema'] ) && is_array( $ability['output_schema'] ) ) {
				return $ability['output_schema'];
			}
		}
		return null;
	}

	/**
	 * Validate a manifest against its agent's get_tool_names().
	 *
	 * Returns an array of warnings/errors found during validation.
	 *
	 * @param string $agent_slug    Agent identifier.
	 * @param array  $code_tools    Tool names from agent's get_tool_names().
	 * @return array{valid: bool, errors: string[], warnings: string[], risk_summary: array<string, int>}
	 */
	public static function validate( string $agent_slug, array $code_tools = array() ): array {
		$result = array(
			'valid'        => true,
			'errors'       => array(),
			'warnings'     => array(),
			'risk_summary' => array_fill_keys( Risk_Level::ALL, 0 ),
		);

		$manifest = self::load( $agent_slug );

		if ( ! $manifest ) {
			$result['valid']    = false;
			$result['errors'][] = "No abilities.json found for agent '{$agent_slug}'.";
			return $result;
		}

		// Check required fields.
		if ( empty( $manifest['version'] ) ) {
			$result['warnings'][] = 'Missing "version" field.';
		}

		if ( empty( $manifest['abilities'] ) || ! is_array( $manifest['abilities'] ) ) {
			$result['valid']    = false;
			$result['errors'][] = 'Missing or empty "abilities" object.';
			return $result;
		}

		// Validate each declared ability.
		foreach ( $manifest['abilities'] as $tool_name => $entry ) {
			if ( ! isset( $entry['risk'] ) ) {
				$result['valid']    = false;
				$result['errors'][] = "Tool '{$tool_name}' is missing a 'risk' field.";
				continue;
			}
			if ( ! Risk_Level::is_valid( $entry['risk'] ) ) {
				$result['valid']    = false;
				$result['errors'][] = "Tool '{$tool_name}' has invalid risk level: '{$entry['risk']}'.";
				continue;
			}

			// Warn if medium+ tools lack a reason.
			if ( Risk_Level::weight( $entry['risk'] ) >= Risk_Level::weight( Risk_Level::MEDIUM )
				&& empty( $entry['reason'] ) ) {
				$result['warnings'][] = "Tool '{$tool_name}' is {$entry['risk']} risk but has no 'reason'.";
			}

			// webmcp_expose/webmcp_context — the WebMCP Bridge's frontend allowlist.
			// Fail-closed on the one combination that matters: a HIGH/EXTREME-risk
			// tool must never be exposed to the current-browser-session surface,
			// which serves anonymous visitors. This is a hard error, not a warning,
			// mirroring the "manifests can only escalate, never downgrade" rule
			// applied to a new axis (exposure) rather than a downgrade.
			if ( isset( $entry['webmcp_expose'] ) && ! is_bool( $entry['webmcp_expose'] ) ) {
				$result['valid']    = false;
				$result['errors'][] = "Tool '{$tool_name}' has a non-boolean 'webmcp_expose'.";
			}
			if ( isset( $entry['webmcp_context'] ) && ! in_array( $entry['webmcp_context'], array( 'frontend', 'admin', 'both' ), true ) ) {
				$result['valid']    = false;
				$result['errors'][] = "Tool '{$tool_name}' has an invalid 'webmcp_context': '{$entry['webmcp_context']}'.";
			}
			if ( ! empty( $entry['webmcp_expose'] ) && Risk_Level::weight( $entry['risk'] ) > Risk_Level::weight( Risk_Level::MEDIUM ) ) {
				$result['valid']    = false;
				$result['errors'][] = "Tool '{$tool_name}' is webmcp_expose:true but risk '{$entry['risk']}' is above medium — never allowed.";
			}

			++$result['risk_summary'][ $entry['risk'] ];
		}

		// Validate wp_abilities if present.
		if ( ! empty( $manifest['wp_abilities'] ) && is_array( $manifest['wp_abilities'] ) ) {
			foreach ( $manifest['wp_abilities'] as $i => $entry ) {
				if ( empty( $entry['name'] ) ) {
					$result['warnings'][] = "wp_abilities[{$i}] is missing 'name'.";
					continue;
				}
				if ( ! isset( $entry['risk'] ) || ! Risk_Level::is_valid( $entry['risk'] ) ) {
					$result['warnings'][] = "wp_abilities '{$entry['name']}' has invalid or missing risk.";
					continue;
				}
				++$result['risk_summary'][ $entry['risk'] ];
			}
		}

		// Cross-reference with code-declared tools.
		if ( ! empty( $code_tools ) ) {
			$declared = self::get_declared_tools( $agent_slug );

			// Framework-injected global tools (e.g. report_issue) are added to every
			// agent at runtime by Agent_Base and are not declared per-agent, so exempt
			// them from the manifest cross-check.
			$global = apply_filters( 'agentic_global_agent_tools', array( 'report_issue' ), null );

			// Site tools registered outside a bundled agent's own manifest (source =
			// 'site') are injected via agentic_agent_tool_names and must not fail
			// bundled agent activation.
			$site_local = array();
			if ( class_exists( __NAMESPACE__ . '\\Tools_Registry' ) ) {
				foreach ( Tools_Registry::get_all() as $name => $row ) {
					if ( is_array( $row ) && 'site' === ( $row['source'] ?? '' ) ) {
						$site_local[] = (string) $name;
					}
				}
			}
			$site_local = array_values( array_unique( $site_local ) );

			// Tools in code but not in manifest.
			$undeclared = array_diff( $code_tools, $declared, (array) $global, $site_local );
			foreach ( $undeclared as $tool ) {
				$result['valid']    = false;
				$result['errors'][] = "Tool '{$tool}' is in get_tool_names() but not declared in abilities.json.";
			}

			// Tools in manifest but not in code.
			$abilities_only = isset( $manifest['abilities'] ) ? array_keys( $manifest['abilities'] ) : array();
			$extra          = array_diff( $abilities_only, $code_tools );
			foreach ( $extra as $tool ) {
				$result['warnings'][] = "Tool '{$tool}' is declared in abilities.json but not in get_tool_names().";
			}
		}

		return $result;
	}

	/**
	 * Every ability, across all active agents, that is exposed to WebMCP.
	 *
	 * Scans each active agent's own loaded manifest (not a global registry —
	 * abilities.json is per-agent) for abilities with webmcp_expose === true,
	 * optionally filtered to one context ('frontend' or 'admin' also matches
	 * 'both'). Used by Webmcp_Bridge (building the tool manifest it registers
	 * per request context) and Agent_Ready_Score (the webmcp_tools_registered
	 * and approval_gate_configured checks).
	 *
	 * @param string $context Optional: 'frontend' or 'admin'. Empty means any context.
	 * @return array<int, array{agent_slug:string, tool_name:string, webmcp_context:string, risk:string}>
	 */
	public static function get_webmcp_exposed( string $context = '' ): array {
		$exposed = array();

		$slugs = class_exists( '\\Agentic_Agent_Registry' )
			? array_keys( \Agentic_Agent_Registry::get_instance()->get_all_instances() )
			: array();

		foreach ( $slugs as $slug ) {
			$manifest = self::load( $slug );
			if ( ! $manifest || empty( $manifest['abilities'] ) || ! is_array( $manifest['abilities'] ) ) {
				continue;
			}

			foreach ( $manifest['abilities'] as $tool_name => $entry ) {
				if ( empty( $entry['webmcp_expose'] ) ) {
					continue;
				}

				$tool_context = $entry['webmcp_context'] ?? 'both';
				if ( '' !== $context && 'both' !== $tool_context && $context !== $tool_context ) {
					continue;
				}

				$exposed[] = array(
					'agent_slug'     => $slug,
					'tool_name'      => (string) $tool_name,
					'webmcp_context' => $tool_context,
					'risk'           => $entry['risk'] ?? Risk_Level::NONE,
				);
			}
		}

		return $exposed;
	}

	/**
	 * The site-specific secret used for every HMAC-based integrity check this
	 * plugin performs — manifest signing here, and the audit-log hash chain
	 * in Audit_Log_Integrity. One key source, so there's only one place that
	 * ever has to reason about where it comes from or how it degrades when
	 * AUTH_SALT isn't defined (e.g. a very old wp-config.php).
	 *
	 * @return string
	 */
	public static function get_integrity_key(): string {
		return defined( 'AUTH_SALT' ) ? AUTH_SALT : 'agentic-fallback-salt';
	}

	/**
	 * Generate an HMAC-SHA256 signature for an agent's abilities.json.
	 *
	 * Uses the site's AUTH_SALT as the secret key so signatures are
	 * site-specific and cannot be transferred between installs.
	 *
	 * @param string $agent_slug Agent identifier.
	 * @return string|null Hex-encoded HMAC or null if no manifest found.
	 */
	public static function generate_integrity_hash( string $agent_slug ): ?string {
		$path = self::resolve_path( $agent_slug );
		if ( ! $path ) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local plugin file; WP_Filesystem initialised after plugins_loaded, not guaranteed here.
		$contents = file_get_contents( $path );
		if ( false === $contents ) {
			return null;
		}

		return hash_hmac( 'sha256', $contents, self::get_integrity_key() );
	}

	/**
	 * Write the integrity signature file alongside abilities.json.
	 *
	 * Creates an abilities-signature file in the same directory. This file
	 * is checked at runtime to detect post-install tampering.
	 *
	 * @param string $agent_slug Agent identifier.
	 * @return bool Whether the signature file was written successfully.
	 */
	public static function save_integrity_hash( string $agent_slug ): bool {
		$manifest_path = self::resolve_path( $agent_slug );
		if ( ! $manifest_path ) {
			return false;
		}

		$hash = self::generate_integrity_hash( $agent_slug );
		if ( ! $hash ) {
			return false;
		}

		$sig_path = dirname( $manifest_path ) . '/' . self::SIGNATURE_FILE;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		return false !== file_put_contents( $sig_path, $hash );
	}

	/**
	 * Write an agent's abilities.json and its integrity signature as one
	 * atomic step.
	 *
	 * save_integrity_hash() can only sign a manifest that's already on disk
	 * (it resolves the path by looking for the file), so any writer that
	 * calls file_put_contents() and save_integrity_hash() as two separate
	 * steps is one skipped/forgotten line away from shipping a manifest
	 * whose signature silently never gets written or goes stale against its
	 * own content — exactly the class of bug this method closes off by
	 * construction, for every future caller.
	 *
	 * @param string $agent_dir  Directory the agent's files live in (already created).
	 * @param string $agent_slug Agent identifier.
	 * @param array  $manifest   Manifest data, already assembled and JSON-encodable.
	 * @return bool Whether the manifest was written and signed.
	 */
	public static function write_manifest( string $agent_dir, string $agent_slug, array $manifest ): bool {
		$manifest_json = wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( false === $manifest_json ) {
			return false;
		}

		$path = trailingslashit( $agent_dir ) . 'abilities.json';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === file_put_contents( $path, $manifest_json ) ) {
			return false;
		}

		self::clear_cache( $agent_slug );
		return self::save_integrity_hash( $agent_slug );
	}

	/**
	 * Verify that abilities.json has not been modified since its signature was recorded.
	 *
	 * @param string $agent_slug Agent identifier.
	 * @return bool True if the signature matches (file is untampered), false otherwise.
	 */
	public static function verify_integrity( string $agent_slug ): bool {
		$manifest_path = self::resolve_path( $agent_slug );
		if ( ! $manifest_path ) {
			// No manifest at all — nothing to verify (agent has no abilities.json).
			return true;
		}

		$sig_path = dirname( $manifest_path ) . '/' . self::SIGNATURE_FILE;
		if ( ! file_exists( $sig_path ) ) {
			// No signature yet — fresh install or signature not written during activation.
			// Sign now so the next request passes cleanly.
			self::save_integrity_hash( $agent_slug );
			return true;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local plugin file; reading a small trusted signature file.
		$stored_hash = trim( file_get_contents( $sig_path ) );
		if ( empty( $stored_hash ) ) {
			return false;
		}

		$current_hash = self::generate_integrity_hash( $agent_slug );
		if ( ! $current_hash ) {
			return false;
		}

		return hash_equals( $stored_hash, $current_hash );
	}

	/**
	 * Check that a decoded manifest array contains the required top-level fields.
	 *
	 * Called from load() so downstream code can trust the structure.
	 *
	 * @param mixed $data Decoded JSON value.
	 * @return bool
	 */
	private static function is_valid_manifest( mixed $data ): bool {
		return is_array( $data )
			&& ! empty( $data['version'] )
			&& isset( $data['abilities'] )
			&& is_array( $data['abilities'] );
	}

	/**
	 * Clear the manifest cache (useful after installing/uninstalling agents).
	 *
	 * @param string|null $agent_slug Clear specific agent, or all if null.
	 * @return void
	 */
	public static function clear_cache( ?string $agent_slug = null ): void {
		if ( $agent_slug ) {
			unset( self::$cache[ $agent_slug ] );
		} else {
			self::$cache = array();
		}
	}
}
