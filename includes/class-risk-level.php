<?php
/**
 * Risk Level — defines the five risk tiers for agent tool access
 *
 * Each tool declares a default risk level. Agent abilities.json files declare
 * per-tool risk (can only escalate, never downgrade). Site owners can override
 * risk per-agent-tool from the admin UI.
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
 * Risk level constants and helpers for tool access control.
 */
class Risk_Level {

	/**
	 * Cached registry data from the wp_agentic_tools database table.
	 *
	 * @var array|null
	 */
	private static ?array $registry = null;

	/**
	 * Read-only on non-personal information. No logging beyond normal audit trail.
	 */
	public const NONE = 'none';

	/**
	 * Read operations that may expose personal information. Logged with PII flag.
	 */
	public const LOW = 'low';

	/**
	 * Write operations. Agent pauses for in-chat confirmation (Proposals system).
	 */
	public const MEDIUM = 'medium';

	/**
	 * Significant write or bulk operations. Queued for admin approval at
	 * /wp-admin/admin.php?page=agentic-approvals. Logged with user sign-off.
	 */
	public const HIGH = 'high';

	/**
	 * Destructive operations that neither agent nor user should perform.
	 * Tool is hidden from the LLM entirely; execution is always blocked.
	 */
	public const EXTREME = 'extreme';

	/**
	 * Ordered list of all valid levels (lowest to highest).
	 */
	public const ALL = array(
		self::NONE,
		self::LOW,
		self::MEDIUM,
		self::HIGH,
		self::EXTREME,
	);

	/**
	 * Numeric weight for each level (used for max() comparisons).
	 */
	private const WEIGHTS = array(
		self::NONE    => 0,
		self::LOW     => 1,
		self::MEDIUM  => 2,
		self::HIGH    => 3,
		self::EXTREME => 4,
	);

	/**
	 * Human-readable labels.
	 */
	public const LABELS = array(
		self::NONE    => 'No Risk',
		self::LOW     => 'Low Risk',
		self::MEDIUM  => 'Medium Risk',
		self::HIGH    => 'High Risk',
		self::EXTREME => 'Extreme Risk',
	);

	/**
	 * Check if a given string is a valid risk level.
	 *
	 * @param string $level Risk level to validate.
	 * @return bool
	 */
	public static function is_valid( string $level ): bool {
		return in_array( $level, self::ALL, true );
	}

	/**
	 * Return the higher of two risk levels.
	 *
	 * Used to enforce "can only escalate, never downgrade":
	 *   effective_risk = max( tool_default, abilities_json_risk )
	 *
	 * @param string $a First risk level.
	 * @param string $b Second risk level.
	 * @return string The higher risk level.
	 */
	public static function max( string $a, string $b ): string {
		$wa = self::WEIGHTS[ $a ] ?? 0;
		$wb = self::WEIGHTS[ $b ] ?? 0;
		return $wa >= $wb ? $a : $b;
	}

	/**
	 * Get the numeric weight for a risk level.
	 *
	 * @param string $level Risk level.
	 * @return int Weight (0–4).
	 */
	public static function weight( string $level ): int {
		return self::WEIGHTS[ $level ] ?? 0;
	}

	/**
	 * Get the maximum risk level an agent mode auto-approves.
	 *
	 * Anything above the ceiling requires confirmation or queuing.
	 *
	 * @param string $mode Agent mode ('disabled', 'supervised', 'autonomous').
	 * @return string Maximum auto-approve risk level.
	 */
	public static function mode_ceiling( string $mode ): string {
		return match ( $mode ) {
			'autonomous' => self::LOW,
			'supervised' => self::NONE,
			default      => self::NONE,
		};
	}

	/**
	 * Determine the required enforcement action for a given risk and mode.
	 *
	 * @param string $risk Effective risk level of the tool.
	 * @param string $mode Agent operating mode.
	 * @return string One of: 'allow', 'confirm', 'queue', 'block'.
	 */
	public static function enforcement( string $risk, string $mode ): string {
		if ( self::EXTREME === $risk ) {
			return 'block';
		}

		if ( 'disabled' === $mode ) {
			return 'block';
		}

		$ceiling = self::mode_ceiling( $mode );

		// Site preference: auto-approve tools up to a chosen risk (never extreme).
		// Stored by Approvals preferences UI (careful / balanced / hands-off).
		$auto_max = sanitize_key( (string) get_option( 'agentic_approval_auto_max_risk', self::NONE ) );
		if ( ! self::is_valid( $auto_max ) || self::EXTREME === $auto_max ) {
			$auto_max = self::NONE;
		}
		// Effective auto-allow ceiling = max( mode ceiling, preference ).
		if ( self::weight( $auto_max ) > self::weight( $ceiling ) ) {
			$ceiling = $auto_max;
		}

		if ( self::weight( $risk ) <= self::weight( $ceiling ) ) {
			return 'allow';
		}

		// Above ceiling: medium → confirm, high → queue.
		if ( self::HIGH === $risk ) {
			return 'queue';
		}

		return 'confirm';
	}

	/**
	 * Load the master risk registry from the wp_agentic_tools database table.
	 *
	 * @return array Flat map of tool_name => entry array with at least 'risk'.
	 */
	private static function load_registry(): array {
		if ( null !== self::$registry ) {
			return self::$registry;
		}

		self::$registry = array();

		$all_tools = Tools_Registry::get_all();
		foreach ( $all_tools as $name => $row ) {
			self::$registry[ $name ] = array(
				'risk'     => $row['risk_level'] ?? self::NONE,
				'category' => $row['category'] ?? 'wordpress',
			);
		}

		return self::$registry;
	}

	/**
	 * Irreducible minimum risk for tools that can cause real harm.
	 *
	 * The wp_agentic_tools registry cannot be trusted to hold a sane value for
	 * these. It is seeded from Tool_Base::get_risk_level(), which reads the
	 * registry back — a tool with no row seeds as 'none', and 'none' runs
	 * silently even in supervised mode. Every tool below therefore sat at
	 * 'none' unless it happened to override get_risk_level(): installing
	 * arbitrary code from a URL, injecting front-end JavaScript, rewriting the
	 * deployed git tree, resetting passwords and issuing refunds all executed
	 * with no confirmation and no approval.
	 *
	 * This map is a floor, never a ceiling. get_tool_default() returns the
	 * higher of the registry value and the floor, so an admin can still
	 * escalate a tool but can never quietly drop one of these below its floor.
	 *
	 * HIGH is queued for explicit admin approval; MEDIUM asks the user to
	 * confirm in the moment. Nothing here is EXTREME, which would hide the tool
	 * from agents entirely and remove the capability.
	 *
	 * @var array<string, string>
	 */
	private const BASELINE_RISKS = array(
		// Executes or installs code. WP MCP / Abilities guidance: shell and
		// remote-install style tools are not "normal" content abilities — keep
		// floors high/extreme so free agents + MCP cannot silently run them.
		'install_plugin_from_url'              => self::HIGH,
		'add_custom_js'                        => self::HIGH,
		'run_wp_cli'                           => self::EXTREME,
		// EXTREME hides a tool from the LLM entirely and always blocks it — no
		// approval path, for anyone, at any trust level. That's right for
		// run_wp_cli (arbitrary shell), but create_agent_files is Assistant
		// Trainer's core, documented capability: its own system prompt says
		// "the approval queue handles safety automatically," which requires
		// the HIGH (queue-for-approval) path, not an unconditional block. HIGH
		// still stops a remote MCP client from creating an agent silently — it
		// queues for a human either way — while restoring the local,
		// intentional chat flow this tool exists for.
		'create_agent_files'                   => self::HIGH,
		'validate_agent_code'                  => self::HIGH,

		// Mutates the deployed codebase (Pro-only tools when free is stripped).
		'git_pull'                             => self::HIGH,
		'git_push'                             => self::HIGH,
		'git_commit'                           => self::HIGH,

		// Generic authenticated proxy to the full GitHub REST API (any method,
		// any endpoint, using the admin's own PAT) — no risk_level override of
		// its own, so without a floor here it would default to NONE if a
		// future agent ever declares it.
		'github_api'                           => self::HIGH,

		// Account takeover surface.
		'force_password_reset'                 => self::HIGH,

		// Moves money.
		'wc_create_refund'                     => self::HIGH,

		// Mutates the visitor's own session cart. No money moves and no other
		// visitor's data is touched, but a write should never silently
		// resolve to risk 'none' by omission — same principle as the Agent
		// Orchestrator deploy-surface floors below.
		'wc_add_to_cart'                       => self::LOW,
		'wc_update_cart_item'                  => self::LOW,

		// Irreversibly destroys user data.
		'delete_form'                          => self::HIGH,

		// Removes or rewrites a security control.
		'cloudflare_delete_rule'               => self::HIGH,
		'cloudflare_apply_wp_security_profile' => self::HIGH,

		// Persistent style injection — overlays and clickjacking.
		'add_custom_css'                       => self::MEDIUM,

		// Notifies the site's own team on channels the owner already configured,
		// and is throttled per user. Lower than send_email, which can address
		// anyone.
		'request_human_help'                   => self::LOW,

		// Outbound communication in the site owner's name.
		'send_email'                           => self::MEDIUM,
		'cloudflare_send_email'                => self::MEDIUM,

		// Changes traffic handling.
		'cloudflare_create_rate_limit_rule'    => self::MEDIUM,

		// Bulk deletion. Recoverable in principle, not in practice.
		'cleanup_auto_drafts'                  => self::MEDIUM,
		'cleanup_post_revisions'               => self::MEDIUM,
		'cleanup_spam_comments'                => self::MEDIUM,
		'purge_expired_transients'             => self::MEDIUM,

		// Agent Orchestrator deploy surfaces (3.3.67+). Intrinsic floors so a
		// tool reassigned outside orchestrator abilities cannot run writes at
		// risk "none". Read actions still drop via risk_by_action + max().
		'manage_agent_shortcode'               => self::LOW,
		'manage_editor_sidebar_agent'          => self::LOW,
		'manage_frontend_modal_agent'          => self::LOW,
		'manage_gutenberg_block_agent'         => self::LOW,
		'manage_admin_bar_launcher'            => self::LOW,
		'manage_scheduled_task'                => self::MEDIUM,
		'manage_event_listener'                => self::MEDIUM,
		'manage_cli_settings'                  => self::HIGH,
		// Skills / Users admin tools (3.3.75–3.3.76) — floor HIGH so MCP
		// is_tool_mcp_safe() excludes them (chat still uses abilities risk).
		'manage_skill'                         => self::HIGH,
		'manage_user_privileges'               => self::HIGH,
		'browse_community_skills'              => self::MEDIUM,
	);

	/**
	 * Irreducible minimum risk map (tool slug => risk).
	 *
	 * Safety Center uses this for representative examples per tier so the
	 * screen never duplicates the floor list.
	 *
	 * @return array<string, string>
	 */
	public static function get_baseline_risks(): array {
		return self::BASELINE_RISKS;
	}

	/**
	 * Get the authoritative default risk level for a tool.
	 *
	 * Reads the wp_agentic_tools registry, floored by BASELINE_RISKS so a
	 * dangerous tool can never resolve below its minimum. Returns NONE for a
	 * tool that is neither listed nor in the baseline.
	 *
	 * @param string $tool_name Tool slug (e.g. 'create_post_content' or 'wp-extended/get-posts').
	 * @return string Risk level constant.
	 */
	public static function get_tool_default( string $tool_name ): string {
		$registry = self::load_registry();

		$risk = self::NONE;
		if ( isset( $registry[ $tool_name ]['risk'] ) && self::is_valid( $registry[ $tool_name ]['risk'] ) ) {
			$risk = $registry[ $tool_name ]['risk'];
		}

		if ( isset( self::BASELINE_RISKS[ $tool_name ] ) ) {
			$risk = self::max( $risk, self::BASELINE_RISKS[ $tool_name ] );
		}

		return $risk;
	}

	/**
	 * Get the full registry entry for a tool (risk, category, writes, pii, etc.).
	 *
	 * @param string $tool_name Tool slug.
	 * @return array|null Entry array or null if not in registry.
	 */
	public static function get_tool_entry( string $tool_name ): ?array {
		$registry = self::load_registry();
		return $registry[ $tool_name ] ?? null;
	}

	/**
	 * Get the entire risk registry as a flat map of tool_name => entry.
	 *
	 * @return array<string, array>
	 */
	public static function get_registry(): array {
		return self::load_registry();
	}

	/**
	 * Clear the cached registry, forcing a fresh read on next access.
	 */
	public static function bust_cache(): void {
		self::$registry = null;
	}
}
