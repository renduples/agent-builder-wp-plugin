<?php
/**
 * Agent Controller
 *
 * Handles conversations with any registered agent using their system prompt and tools.
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
 * Main agent controller handling conversations and tool orchestration
 */
class Agent_Controller {

	/**
	 * LLM client
	 *
	 * @var LLM_Client
	 */
	private LLM_Client $llm;

	/**
	 * Tool loader
	 *
	 * @var Tool_Loader
	 */
	private Tool_Loader $tool_loader;

	/**
	 * Abilities bridge for WP 6.9+ integration
	 *
	 * @var Abilities_Bridge|null
	 */
	private ?Abilities_Bridge $abilities_bridge = null;

	/**
	 * Tool executor — handles risk gating, approval routing, and dispatch.
	 *
	 * @var Tool_Executor
	 */
	private Tool_Executor $executor;

	/**
	 * Audit log
	 *
	 * @var Audit_Log
	 */
	private Audit_Log $audit;

	/**
	 * Current agent being used
	 *
	 * @var \Agentic\Agent_Base|null
	 */
	private ?\Agentic\Agent_Base $current_agent = null;

	/**
	 * Effective operating mode for the current agent (set per-request).
	 *
	 * @var string 'disabled'|'supervised'|'autonomous'
	 */
	private string $current_agent_mode = 'supervised';

	/**
	 * Invocation context: how this agent run was triggered.
	 *
	 * @var string 'chat'|'cron'|'hook'|'cli'
	 */
	private string $invocation_context = 'chat';

	/**
	 * Per-request cache for 'agent_builder_agent_mode' option.
	 *
	 * @var string|null
	 */
	private ?string $global_mode_cache = null;

	/**
	 * Session identifier for the current chat() call.
	 *
	 * Passed to Tool_Executor so session-scoped tool grants can be resolved.
	 *
	 * @var string
	 */
	private string $current_session_id = '';

	/**
	 * SSE streaming emit callback, or null when not streaming.
	 * Signature: fn(string $type, mixed $data): void.
	 *
	 * @var callable|null
	 */
	private $stream_emit = null;

	/**
	 * Default maximum tool iterations per request (filterable via agentic_max_tool_iterations).
	 */
	private const MAX_ITERATIONS_DEFAULT = 10;

	/**
	 * Constructor
	 *
	 * @param LLM_Client|null $llm Optional LLM client to inject (tests); a
	 *                             production client is created when omitted.
	 */
	public function __construct( ?LLM_Client $llm = null ) {
		$this->llm         = $llm ?? new LLM_Client();
		$this->tool_loader = Tool_Loader::get_instance();
		$this->audit       = new Audit_Log();

		// Initialize abilities bridge on WP 6.9+.
		if ( WP_Optional_API::has( 'wp_register_ability' ) ) {
			$this->abilities_bridge = new Abilities_Bridge();
		}

		$this->executor = new Tool_Executor( $this->tool_loader, $this->audit, $this->abilities_bridge );
	}

	/**
	 * Notice appended to a response whose final completion was cut off by the
	 * provider's token limit (finish_reason 'length'/'max_tokens'), so a
	 * truncated fragment is never presented to the user as if it were the
	 * complete answer.
	 *
	 * @return string
	 */
	private static function truncation_notice(): string {
		return "\n\n" . __( '⚠️ This response was cut short — the model hit its token limit before finishing. Try asking me to continue, or break the request into smaller steps.', 'agent-builder' );
	}

	/**
	 * Get the LLM client instance.
	 *
	 * @return LLM_Client
	 */
	public function get_llm(): LLM_Client {
		return $this->llm;
	}

	/**
	 * Set the invocation context (how this agent run was triggered).
	 *
	 * @param string $context One of 'chat', 'cron', 'hook', 'cli'.
	 */
	public function set_invocation_context( string $context ): void {
		$this->invocation_context = $context;
	}

	/**
	 * Enable SSE streaming mode for the next chat() call.
	 *
	 * The callback is invoked for each SSE event emitted during the conversation:
	 *   - ('live', string $token)        — text token from the LLM
	 *   - ('tool_start', array $data)    — tool execution started; $data contains 'name'
	 *   - ('tool_end',   array $data)    — tool execution completed; $data contains 'name'
	 *
	 * @param callable $emit fn(string $type, mixed $data): void.
	 * @return void
	 */
	public function enable_streaming( callable $emit ): void {
		$this->stream_emit = $emit;
	}

	/**
	 * Emit a streaming event if streaming is enabled.
	 *
	 * @param string $type Event type.
	 * @param mixed  $data Event payload.
	 * @return void
	 */
	private function emit_stream( string $type, mixed $data ): void {
		if ( null !== $this->stream_emit ) {
			( $this->stream_emit )( $type, $data );
		}
	}

	/**
	 * Set the current agent for conversation
	 *
	 * @param string $agent_id Agent identifier.
	 * @return bool Whether agent was set successfully.
	 */
	public function set_agent( string $agent_id ): bool {
		$registry = \Agentic_Agent_Registry::get_instance();
		$agent    = $registry->get_agent_instance( $agent_id );

		if ( ! $agent ) {
			return false;
		}

		if ( ! $agent->current_user_can_access() ) {
			return false;
		}

		$this->current_agent = $agent;

		// Apply per-agent overrides early so callers see the correct provider/model.
		$effective_mode           = $this->apply_agent_overrides();
		$this->current_agent_mode = $effective_mode;

		return true;
	}

	/**
	 * Get the current agent
	 *
	 * @return \Agentic\Agent_Base|null Current agent or null.
	 */
	public function get_agent(): ?\Agentic\Agent_Base {
		return $this->current_agent;
	}

	/**
	 * Apply per-agent LLM and mode overrides.
	 *
	 * Reads per-agent overrides from Agent_Settings and, if an override exists for
	 * the current agent, reconfigures the LLM client's provider/model and sets
	 * the Agent_Permissions confirmation-mode override for this request lifecycle.
	 *
	 * @return string Effective agent mode ('disabled'|'supervised'|'autonomous').
	 */
	private function apply_agent_overrides(): string {
		if ( null === $this->global_mode_cache ) {
			$this->global_mode_cache = (string) get_option( 'agent_builder_agent_mode', 'supervised' );
		}
		$global_mode = $this->global_mode_cache;

		if ( ! $this->current_agent ) {
			return $global_mode;
		}

		$slug     = $this->current_agent->get_id();
		$provider = Agent_Settings::get( $slug, 'override_provider' );
		$model    = Agent_Settings::get( $slug, 'override_model' );
		$mode_ov  = Agent_Settings::get( $slug, 'override_mode' );

		// Provider / model override.
		if ( ! empty( $provider ) ) {
			$this->llm->set_provider( sanitize_text_field( $provider ) );
		}
		if ( ! empty( $model ) ) {
			$this->llm->set_model( sanitize_text_field( $model ) );
		}

		// Ensure the active model is valid for the active provider.
		$this->llm->validate_model();

		// Mode override: per-agent user override → agent default → global setting.
		if ( ! empty( $mode_ov ) ) {
			$mode = $mode_ov;
		} else {
			$agent_default = $this->current_agent->get_default_mode();
			$mode          = ! empty( $agent_default ) ? $agent_default : $global_mode;
		}
		$mode = in_array( $mode, array( 'disabled', 'supervised', 'autonomous' ), true ) ? $mode : $global_mode;

		// Push into Agent_Permissions so requires_confirmation() respects it.
		if ( 'autonomous' === $mode ) {
			Agent_Permissions::set_mode_override( Agent_Permissions::MODE_AUTO );
		} else {
			// supervised or disabled both use confirm mode (disabled is handled before any LLM call).
			Agent_Permissions::set_mode_override( Agent_Permissions::MODE_CONFIRM );
		}

		return $mode;
	}

	/**
	 * Merge consecutive same-role messages in a message array.
	 *
	 * LLM providers expect alternating user/assistant turns. History replayed
	 * from the frontend can produce consecutive messages of the same role;
	 * this method concatenates their content to satisfy provider constraints.
	 * System messages are always kept standalone and never merged.
	 *
	 * @param array $messages Flat array of message arrays with 'role' and 'content' keys.
	 * @return array Normalised message array with consecutive same-role entries merged.
	 */
	private function merge_consecutive_roles( array $messages ): array {
		$merged = array();
		foreach ( $messages as $msg ) {
			$last_idx = count( $merged ) - 1;

			// System messages are always standalone — never merge into them.
			if ( 'system' === $msg['role'] || $last_idx < 0 || 'system' === $merged[ $last_idx ]['role'] ) {
				$merged[] = $msg;
				continue;
			}

			// Merge consecutive same-role messages by concatenating content.
			if ( $merged[ $last_idx ]['role'] === $msg['role'] && is_string( $merged[ $last_idx ]['content'] ) && is_string( $msg['content'] ) ) {
				$merged[ $last_idx ]['content'] .= "\n\n" . $msg['content'];
			} else {
				$merged[] = $msg;
			}
		}
		return $merged;
	}

	/**
	 * Get tools for the current agent.
	 *
	 * Loads tool definitions from the Tool_Loader based on the agent's
	 * declared tool names. All tools are standalone; agents just declare
	 * which ones they need.
	 *
	 * Extreme-risk tools are excluded from the definitions so the LLM
	 * never sees them. Undeclared tools (in get_tool_names() but missing
	 * from abilities.json) are also excluded with a logged warning.
	 *
	 * @return array[] Tool definitions in OpenAI function-calling format.
	 */
	private function get_tools_for_agent(): array {
		if ( ! $this->current_agent ) {
			return array();
		}

		$agent_slug = $this->current_agent->get_id();
		$manifest   = Abilities_Manifest::load( $agent_slug );

		// Verify abilities.json has not been tampered with since it was signed.
		// If a manifest exists but fails integrity check, block all tools.
		if ( $manifest && ! Abilities_Manifest::verify_integrity( $agent_slug ) ) {
			$this->audit->log(
				$agent_slug,
				'integrity_failure',
				'abilities.json',
				array(
					'reason' => 'Manifest signature mismatch — possible tampering detected',
				)
			);
			return array();
		}

		// No manifest → fail-closed: standalone tools require an explicit abilities.json.
		// This prevents a missing manifest from silently granting all tool access (C2).
		if ( ! $manifest ) {
			$this->audit->log(
				$agent_slug,
				'tool_blocked',
				'all',
				array( 'reason' => 'No abilities.json manifest — standalone tools disabled until manifest is created' )
			);
		}

		// Get tool definitions for the agent's declared tool names.
		$tool_names = $this->current_agent->get_tool_names();
		$tools      = $this->tool_loader->get_definitions_for( $tool_names );

		// Filter tools based on risk level and manifest declaration.
		$filtered_tools = array();
		$all_tool_names = array();

		foreach ( $tools as $t ) {
			$name = $t['function']['name'] ?? '';
			if ( ! $name ) {
				continue;
			}

			// Block if no manifest OR tool is not declared in manifest (fail-closed).
			if ( ! $manifest || ! Abilities_Manifest::is_declared( $agent_slug, $name ) ) {
				if ( $manifest ) {
					$this->audit->log( $agent_slug, 'tool_blocked', $name, array( 'reason' => 'Not declared in abilities.json' ) );
				}
				continue;
			}

			// Block extreme-risk tools — LLM never sees them.
			$tool_instance = $this->tool_loader->get( $name );
			$risk          = Abilities_Manifest::get_effective_risk( $agent_slug, $name, $tool_instance );
			if ( Risk_Level::EXTREME === $risk ) {
				$this->audit->log( $agent_slug, 'tool_blocked', $name, array( 'reason' => 'Extreme risk — hidden from agent' ) );
				continue;
			}

			$filtered_tools[] = $t;
			$all_tool_names[] = $name;
		}

		// Merge agent-inline tools (defined in agent's get_tools() method).
		$inline_tools = $this->current_agent->get_tools();
		foreach ( $inline_tools as $inline_tool ) {
			$inline_fn = $inline_tool['function']['name'] ?? '';
			if ( ! $inline_fn || in_array( $inline_fn, $all_tool_names, true ) ) {
				continue;
			}

			// Block inline tool if no manifest OR if not declared (fail-closed).
			if ( ! $manifest || ! Abilities_Manifest::is_declared( $agent_slug, $inline_fn ) ) {
				if ( $manifest ) {
					$this->audit->log( $agent_slug, 'tool_blocked', $inline_fn, array( 'reason' => 'Not declared in abilities.json' ) );
				}
				continue;
			}

			$risk = Abilities_Manifest::get_effective_risk( $agent_slug, $inline_fn, null );
			if ( Risk_Level::EXTREME === $risk ) {
				$this->audit->log( $agent_slug, 'tool_blocked', $inline_fn, array( 'reason' => 'Extreme risk — hidden from agent' ) );
				continue;
			}

			$filtered_tools[] = $inline_tool;
			$all_tool_names[] = $inline_fn;
		}

		// Merge third-party abilities as tools (WP 6.9+).
		// These are opt-in via the Abilities bridge; they do not need an abilities.json
		// entry (that file only gates Agent Builder's own tools). Schemas are sanitized
		// in LLM_Client before the provider API call (OpenAI rejects top-level oneOf/etc).
		//
		// A generic bridged tool (e.g. a third-party "get all users" ability) is withheld
		// when the agent already holds a bundled tool that supersedes it — a system-prompt
		// nudge to "prefer your own tools" was not reliable enough on its own (the model
		// kept reaching for the generic CRUD tool over the purpose-built, risk-gated one).
		// An agent that lacks the bundled equivalent still gets the bridged tool: this only
		// narrows an agent's own duplicate options, it never strips a capability the plugin
		// itself doesn't provide.
		if ( $this->abilities_bridge ) {
			$ability_tools = $this->abilities_bridge->get_third_party_abilities_as_tools();
			foreach ( $ability_tools as $ability_tool ) {
				$ability_fn = $ability_tool['function']['name'] ?? '';
				if ( ! $ability_fn || in_array( $ability_fn, $all_tool_names, true ) ) {
					continue;
				}

				$superseded_by = $this->find_superseding_bundled_tool( $ability_fn, $all_tool_names );
				if ( $superseded_by ) {
					$this->audit->log(
						$agent_slug,
						'tool_suppressed',
						$ability_fn,
						array(
							'reason'        => 'bundled equivalent present',
							'superseded_by' => $superseded_by,
						)
					);
					continue;
				}

				$filtered_tools[] = $ability_tool;
				$all_tool_names[] = $ability_fn;
			}
		}

		return $filtered_tools;
	}

	/**
	 * Bridged generic tool slugs mapped to the bundled Agent Builder tool
	 * slugs that make them redundant for an agent that already holds one.
	 *
	 * Covers the generic third-party/core CRUD abilities the WP 6.9+ Abilities
	 * bridge tends to import (get_users, get_posts, get_environment_info style
	 * tools) that the model otherwise reaches for instead of an agent's own
	 * purpose-built, risk-gated equivalent.
	 *
	 * @return array<string, string[]> Bridged tool name => superseding bundled tool slugs.
	 */
	private static function get_bridge_suppression_map(): array {
		$user_tools     = array(
			'list_privileged_users',
			'find_inactive_users',
			'get_recent_registrations',
			'lock_user_account',
			'manage_user_privileges',
		);
		$post_tools     = array(
			'list_posts',
			'get_post_content',
			'list_posts_needing_seo',
			'analyze_content_quality',
			'get_seo_overview',
			'get_post_performance',
			'rewrite_for_readability',
			'get_post_revisions',
			'list_registered_post_types',
		);
		$media_tools    = array(
			'scan_media_library',
			'find_oversized_images',
			'find_unused_media',
			'get_media_storage_report',
			'search_media_library',
		);
		$env_tools      = array(
			'get_site_overview',
			'get_site_context',
			'check_plugin_status',
			'get_plugin_maintenance_status',
			'get_abandoned_plugins',
			'run_health_check',
		);
		$comment_tools  = array(
			'list_comments',
			'moderate_comment',
		);
		$taxonomy_tools = array(
			'manage_categories',
			'manage_tags',
			'list_registered_taxonomies',
		);

		$map = array(
			// Users.
			'wp_extended__get_users'          => $user_tools,
			'wp_extended__get_roles'          => $user_tools,
			'core__get_user_info'             => $user_tools,

			// Content / posts.
			'wp_extended__get_posts'          => $post_tools,
			'wp_extended__get_post'           => $post_tools,
			'wp_extended__get_recent_posts'   => $post_tools,
			'wp_extended__get_post_counts'    => $post_tools,
			'wp_extended__get_post_meta'      => $post_tools,
			'wp_extended__get_post_revisions' => $post_tools,
			'wp_extended__get_post_types'     => $post_tools,
			'wp_extended__search_content'     => $post_tools,

			// Media.
			'wp_extended__get_media'          => $media_tools,
			'wp_extended__get_image_sizes'    => $media_tools,

			// Plugins / environment / site.
			'wp_extended__get_plugins'        => $env_tools,
			'core__get_environment_info'      => $env_tools,
			'core__get_site_info'             => $env_tools,
			'wp_extended__get_site_health'    => $env_tools,

			// Comments.
			'wp_extended__get_comments'       => $comment_tools,

			// Taxonomy.
			'wp_extended__get_categories'     => $taxonomy_tools,
			'wp_extended__get_tags'           => $taxonomy_tools,
			'wp_extended__get_taxonomies'     => $taxonomy_tools,
			'wp_extended__get_terms'          => $taxonomy_tools,
		);

		/**
		 * Filter the bridged-tool suppression map.
		 *
		 * @param array<string, string[]> $map Bridged tool name => superseding bundled tool slugs.
		 */
		return apply_filters( 'agent_builder_bridge_suppressed_tools', $map );
	}

	/**
	 * Find the bundled tool slug (if any) that supersedes a bridged tool for
	 * an agent whose bundled/inline tool names have already been resolved.
	 *
	 * @param string   $ability_fn     Bridged tool's function name.
	 * @param string[] $all_tool_names Tool names already selected for this agent so far.
	 * @return string|null The superseding bundled slug, or null when not suppressed.
	 */
	private function find_superseding_bundled_tool( string $ability_fn, array $all_tool_names ): ?string {
		$map = self::get_bridge_suppression_map();
		if ( empty( $map[ $ability_fn ] ) ) {
			return null;
		}

		foreach ( $map[ $ability_fn ] as $bundled_slug ) {
			if ( in_array( $bundled_slug, $all_tool_names, true ) ) {
				return $bundled_slug;
			}
		}

		return null;
	}

	/**
	 * Execute a tool call — delegates to Tool_Executor for risk gating and dispatch.
	 *
	 * @param string $tool_name Tool name.
	 * @param array  $arguments Tool arguments decoded from the LLM response.
	 * @return array Tool result.
	 */
	private function execute_tool( string $tool_name, array $arguments ): array {
		$agent_id = $this->current_agent ? $this->current_agent->get_id() : 'unknown';
		return $this->executor->execute(
			$tool_name,
			$arguments,
			$agent_id,
			$this->current_agent_mode,
			$this->invocation_context,
			$this->current_agent,
			$this->current_session_id
		);
	}

	/**
	 * Process a chat message
	 *
	 * @param string     $message      User message.
	 * @param array      $history      Conversation history.
	 * @param int        $user_id      User ID.
	 * @param string     $session_id   Session identifier.
	 * @param string     $agent_id     Agent ID (optional, uses current agent if not set).
	 * @param array|null $image_data   Image data for vision models (optional).
	 * @param string     $page_context        Optional page context to inject into the system prompt.
	 * @param string     $deployment_context  Where the agent is running: admin_chat, gutenberg_sidebar, shortcode, modal, etc.
	 * @param string     $handoff_from        Optional: agent slug this conversation was delegated from (P0 multi-agent).
	 * @param string     $handoff_context     Optional: rich context from the previous agent (P0 multi-agent).
	 * @return array Response data.
	 */
	public function chat( string $message, array $history = array(), int $user_id = 0, string $session_id = '', string $agent_id = '', ?array $image_data = null, string $page_context = '', string $deployment_context = '', string $handoff_from = '', string $handoff_context = '' ): array {
		if ( class_exists( __NAMESPACE__ . '\\Emergency_Stop' ) && Emergency_Stop::is_active() ) {
			return array(
				'response' => Emergency_Stop::blocked_message(),
				'error'    => true,
				'agent_id' => $agent_id,
			);
		}

		// Set agent if specified.
		if ( $agent_id && ( ! $this->current_agent || $this->current_agent->get_id() !== $agent_id ) ) {
			if ( ! $this->set_agent( $agent_id ) ) {
				return array(
					'response' => sprintf(
						/* translators: %s: agent identifier */
						__( "Agent '%s' is not available or you don't have access to it.", 'agent-builder' ),
						$agent_id
					),
					'error'    => true,
					'agent_id' => $agent_id,
				);
			}
		}

		if ( ! $this->current_agent ) {
			return array(
				'response' => __( 'No agent selected. Please select an agent to chat with.', 'agent-builder' ),
				'error'    => true,
				'agent_id' => '',
			);
		}

		// Store session ID so execute_tool() can resolve session-scoped grants.
		$this->current_session_id = $session_id;

		// Apply per-agent provider/model/mode overrides (falls back to global settings).
		$effective_mode           = $this->apply_agent_overrides();
		$this->current_agent_mode = $effective_mode;
		Audit_Log::set_mode_context( $effective_mode );

		if ( 'disabled' === $effective_mode ) {
			return array(
				'response' => __( 'This agent is currently disabled.', 'agent-builder' ),
				'error'    => true,
				'agent_id' => $this->current_agent->get_id(),
			);
		}

		if ( ! $this->llm->is_configured() ) {
			return array(
				'response' => __( 'The AI service is not configured. Please ask an administrator to set up the API key in Settings > Agentic.', 'agent-builder' ),
				'error'    => true,
				'agent_id' => $this->current_agent->get_id(),
			);
		}

		$current_agent_id = $this->current_agent->get_id();

		// P0 Basic Multi-Agent Orchestration: handle handoff from another agent.
		if ( $handoff_from || $handoff_context ) {
			$this->audit->log(
				$current_agent_id,
				'agent_delegation',
				'conversation',
				array(
					'from_agent'  => ! empty( $handoff_from ) ? $handoff_from : 'unknown',
					'session_id'  => $session_id,
					'has_context' => ! empty( $handoff_context ),
				),
				! empty( $handoff_context ) ? substr( $handoff_context, 0, 600 ) : 'Delegated from ' . ( ! empty( $handoff_from ) ? $handoff_from : 'another agent' )
			);
		}

		// Verify abilities.json integrity before proceeding.
		$has_manifest = Abilities_Manifest::load( $current_agent_id ) !== null;
		if ( $has_manifest && ! Abilities_Manifest::verify_integrity( $current_agent_id ) ) {
			$this->audit->log(
				$current_agent_id,
				'integrity_failure',
				'abilities.json',
				array(
					'reason'  => 'Manifest signature mismatch — agent blocked',
					'context' => 'chat',
				)
			);
			Security_Log::log_system(
				'integrity_failure',
				$current_agent_id,
				'abilities.json signature mismatch — agent blocked'
			);
			return array(
				'response' => __( 'This agent has been temporarily disabled due to a security check. Please deactivate and reactivate the Agent Builder plugin to restore it.', 'agent-builder' ),
				'error'    => true,
				'agent_id' => $current_agent_id,
			);
		}

		// Check response cache — avoids calling the LLM for repeated identical messages.
		if ( Response_Cache::should_cache( $message, $history, $current_agent_id ) ) {
			$cached = Response_Cache::get( $message, $current_agent_id, $user_id );
			if ( null !== $cached ) {
				$this->audit->log(
					$current_agent_id,
					'cache_hit',
					'conversation',
					array(
						'session_id' => $session_id,
						'user_id'    => $user_id,
						'prompt'     => substr( $message, 0, 500 ),
					)
				);
				if ( null !== $this->stream_emit && ! empty( $cached['response'] ) ) {
					foreach ( $cached['tools_used'] ?? array() as $tool_name ) {
						$this->emit_stream( 'tool_start', array( 'name' => $tool_name ) );
						$this->emit_stream( 'tool_end', array( 'name' => $tool_name ) );
					}
					$this->emit_stream( 'live', $cached['response'] );
				}
				return $cached;
			}
		}

		// Build page context block if provided.
		$page_context_block = '';
		if ( ! empty( $page_context ) ) {
			$page_context_block = "\n\n[PAGE CONTEXT]\n" . $page_context . "\n[/PAGE CONTEXT]";
		}

		// Build deployment context block — tells the agent where it is running.
		$deployment_context_block = '';
		if ( ! empty( $deployment_context ) ) {
			$deployment_context_block = "\n\n[DEPLOYMENT CONTEXT]\n" . sanitize_text_field( $deployment_context ) . "\n[/DEPLOYMENT CONTEXT]";
		}

		// Recall relevant local memories for this user/agent (no-cloud, opt-in).
		$memory_block = Local_Memory::recall_block( $current_agent_id, $user_id, $message );

		/**
		 * Optional retrieval context (e.g. Pro Vector Store / RAG passages).
		 *
		 * Free plugin leaves this empty. Agent Builder Pro hooks here to inject
		 * semantic search hits before generation.
		 *
		 * @param string $block    Context block (empty by default).
		 * @param string $agent_id Agent slug.
		 * @param string $message  User message.
		 * @param int    $user_id  WordPress user id.
		 */
		$retrieval_block = (string) apply_filters( 'agentic_retrieval_context', '', $current_agent_id, $message, $user_id );

		// Build messages array with agent's system prompt + site context + persona notes + page context + handoff (if any).
		$use_weak_guidance = $this->should_use_weak_model_tool_guidance();
		$messages          = array(
			array(
				'role'    => 'system',
				'content' => Agent_Prompt_Builder::build(
					$this->current_agent,
					$page_context_block . $deployment_context_block . $memory_block . $retrieval_block,
					$handoff_from,
					$handoff_context,
					$use_weak_guidance
				),
			),
		);

		// Add history.
		foreach ( $history as $entry ) {
			$messages[] = array(
				'role'    => $entry['role'],
				'content' => $entry['content'],
			);
		}

		// Add current message (multimodal if image attached).
		if ( $image_data ) {
			// Use the user-configured vision model (per-agent override or global agent_builder_vision_model).
			// When both models are identical, no switch is needed.
			$chat_model        = $this->llm->get_model();
			$effective         = agent_builder_get_effective_provider_model( $current_agent_id );
			$configured_vision = $effective['vision_model'];

			$switched_to_vision = false;
			if ( ! empty( $configured_vision ) && $configured_vision !== $chat_model ) {
				$this->llm->set_model( $configured_vision );
				$switched_to_vision = true;
			}

			if ( $switched_to_vision || ! empty( $configured_vision ) ) {
				// Provider-specific URL selection:
				// - xAI: requires HTTPS URL (temp upload), doesn't support base64 data URLs.
				// - OpenAI/Mistral: support base64 data URLs natively.
				// - Anthropic/Google: converted from data URL in LLM_Client.
				$image_url = $image_data['data_url'];
				if ( 'xai' === $this->llm->get_provider() && ! empty( $image_data['url'] ) ) {
					$image_url = $image_data['url'];
				}

				$messages[] = array(
					'role'    => 'user',
					'content' => array(
						array(
							'type' => 'text',
							'text' => $message,
						),
						array(
							'type'      => 'image_url',
							'image_url' => array(
								'url' => $image_url,
							),
						),
					),
				);
			} else {
				// No vision model configured — send text only with a note.
				$messages[] = array(
					'role'    => 'user',
					'content' => $message . "\n\n(An image was attached but no vision model is configured. Set a Vision Model in Settings → General.)",
				);
			}
		} else {
			$messages[] = array(
				'role'    => 'user',
				'content' => $message,
			);
		}

		// Sanitize message history: merge consecutive same-role messages.
		// The frontend stores only final text responses (no tool_calls or tool results),
		// so replayed history can produce consecutive user or assistant messages.
		// All LLM providers expect (or strongly prefer) alternating user/assistant turns.
		$messages = $this->merge_consecutive_roles( $messages );

		// Get tools for this agent.
		$tools = $this->get_tools_for_agent();

		// Log the conversation start.
		$this->audit->log(
			$current_agent_id,
			'chat_start',
			'conversation',
			array(
				'session_id' => $session_id,
				'user_id'    => $user_id,
				'message'    => substr( $message, 0, 200 ),
			)
		);

		// Process with potential tool calls.
		$response                = null;
		$total_tokens            = 0;
		$total_prompt_tokens     = 0;
		$total_completion_tokens = 0;
		$iterations              = 0;
		$tool_retries            = 0;   // Separate counter for tool error feedback retries (P0 Item 3).
		$tool_results            = array();
		$usage                   = array(
			'prompt_tokens'     => 0,
			'completion_tokens' => 0,
		);
		$agent_id                = $this->current_agent ? $this->current_agent->get_id() : '';
		$max_iterations          = (int) apply_filters( 'agentic_max_tool_iterations', self::MAX_ITERATIONS_DEFAULT, $agent_id );
		// Resolve max tool retries with per-agent > global > default (P0 Item 3)
		$default_retries = 3;
		if ( $agent_id ) {
			$per_agent_retries = Agent_Settings::get( $agent_id, 'max_tool_retries', '' );
			if ( '' !== $per_agent_retries ) {
				$default_retries = max( 1, (int) $per_agent_retries );
			}
		}
		$global_retries = get_option( 'agent_builder_max_tool_retries', '' );
		if ( '' !== $global_retries ) {
			$default_retries = max( 1, (int) $global_retries );
		}

		$max_tool_retries = (int) apply_filters( 'agent_builder_max_tool_retries', $default_retries, $agent_id ); // For weak model error recovery.

		while ( $iterations < $max_iterations ) {
			++$iterations;

			// Force tool use on the first iteration for agents that require it.
			$force_tools = ( 1 === $iterations )
				&& ! empty( $tools )
				&& $this->current_agent
				&& $this->current_agent->requires_tool_use();

			if ( null !== $this->stream_emit ) {
				$result = $this->llm->stream_chat(
					$messages,
					function ( string $token ) {
						$this->emit_stream( 'live', $token );
					},
					$tools,
					$force_tools
				);
			} else {
				$result = $this->llm->chat( $messages, $tools, $force_tools );
			}

			if ( is_wp_error( $result ) ) {
				$error_data = $result->get_error_data();
				return array(
					'response'    => ! empty( $error_data['user_facing'] )
						? $result->get_error_message()
						: sprintf(
							/* translators: %s: error message from AI provider */
							__( 'Error communicating with AI: %s', 'agent-builder' ),
							$result->get_error_message()
						),
					'error'       => true,
					'user_facing' => ! empty( $error_data['user_facing'] ),
					'retriable'   => ! empty( $error_data['retriable'] ),
					'retry_after' => $error_data['retry_after'] ?? 0,
					'agent_id'    => $current_agent_id,
				);
			}

			$usage                    = $this->llm->get_usage( $result );
			$total_tokens            += $usage['total_tokens'];
			$total_prompt_tokens     += $usage['prompt_tokens'] ?? 0;
			$total_completion_tokens += $usage['completion_tokens'] ?? 0;

			$choice = $result['choices'][0] ?? null;
			if ( ! $choice ) {
				return array(
					'response' => __( 'Invalid response from AI.', 'agent-builder' ),
					'error'    => true,
					'agent_id' => $current_agent_id,
				);
			}

			$assistant_message = $choice['message'];

			// Ensure the message has content (required by some providers).
			if ( ! isset( $assistant_message['content'] ) || null === $assistant_message['content'] ) {
				$assistant_message['content'] = '';
			}

			$messages[] = $assistant_message;

			// Capture reasoning from this turn for observability (P0).
			$step_reasoning = $this->extract_reasoning( $assistant_message );

			// When the model chose tools, log the reasoning that led to the decision.
			// This gives per-iteration visibility in the Audit Log immediately.
			if ( ! empty( $assistant_message['tool_calls'] ) ) {
				$this->audit->log(
					$current_agent_id,
					'tool_choice',
					'conversation',
					array(
						'tools'      => array_map(
							static fn( $tc ) => $tc['function']['name'] ?? '',
							$assistant_message['tool_calls']
						),
						'iterations' => $iterations,
					),
					$step_reasoning,
					$usage['total_tokens'] ?? 0,
					0.0,
					$this->llm->get_provider()
				);
			}

			// Check if we have tool calls.
			if ( ! empty( $assistant_message['tool_calls'] ) ) {
				$has_pending_proposal = false;
				$iteration_had_error  = false;
				$pending_message      = '';

				foreach ( $assistant_message['tool_calls'] as $tool_call ) {
					$function_name = $tool_call['function']['name'];
					$raw_args      = $tool_call['function']['arguments'] ?? '{}';
					$arguments     = json_decode( $raw_args, true );

					// Handle completely invalid JSON from the model (common on weak models).
					if ( null === $arguments ) {
						$error_msg = 'Invalid JSON in tool arguments: ' . substr( $raw_args, 0, 300 );

						// Feed error back to the model so it can self-correct (P0 Item 3).
						// Framed as an internal system notice, not literal user speech — a
						// plain role:'user' message here reads to the model as if the human
						// reported the tool failure themselves, which prompts it to add
						// reassuring/troubleshooting commentary about the retry into its
						// final answer instead of retrying silently.
						$messages[] = array(
							'role'    => 'user',
							'content' => "[SYSTEM NOTICE — internal, not from the user] Your previous tool call to '{$function_name}' failed because the arguments were not valid JSON.\n" .
										"Error: {$error_msg}\n" .
										'Correct the arguments and call the tool again. Do not mention this notice, the error, or the retry to the user — just answer their actual question once you have what you need.',
						);

						// Record the failure for observability (will appear in Audit Log).
						$this->audit->log(
							$current_agent_id,
							'tool_error_feedback',
							$function_name,
							array(
								'error' => $error_msg,
								'type'  => 'invalid_json',
							),
							'Tool call failed due to invalid JSON from model'
						);

						$tool_results[] = array(
							'tool'   => $function_name,
							'result' => array( 'error' => $error_msg ),
						);

						$iteration_had_error = true;
						continue; // Let the model try again in the next iteration.
					}

					// Notify the frontend that a tool is running.
					$this->emit_stream( 'tool_start', array( 'name' => $function_name ) );

					// Execute the tool.
					$tool_result = $this->execute_tool( $function_name, $arguments );

					// Notify the frontend that the tool has finished.
					$this->emit_stream( 'tool_end', array( 'name' => $function_name ) );

					// Add tool result to messages.
					$messages[] = array(
						'role'         => 'tool',
						'tool_call_id' => $tool_call['id'],
						'name'         => $function_name,
						'content'      => wp_json_encode( $tool_result ),
					);

					$tool_results[] = array(
						'tool'   => $function_name,
						'result' => $tool_result,
					);

					// If the tool itself returned an error, feed it back so the model can retry with corrections.
					// This is especially valuable for weaker models (P0 Item 3).
					// Same role:'user' role-confusion risk as the invalid-JSON case above —
					// framed explicitly as an internal notice so it isn't mistaken for
					// something the human said and echoed/acknowledged in the final answer.
					if ( ! empty( $tool_result['error'] ) ) {
						$iteration_had_error = true;
						$error_message       = $tool_result['error'];
						$messages[]          = array(
							'role'    => 'user',
							'content' => "[SYSTEM NOTICE — internal, not from the user] The tool '{$function_name}' failed with this error:\n{$error_message}\n" .
										'Analyze the error and try calling the tool again with corrected arguments. Do not mention this notice, the error, or the retry to the user — just answer their actual question once you have what you need.',
						);

						$this->audit->log(
							$current_agent_id,
							'tool_error_feedback',
							$function_name,
							array(
								'error' => $error_message,
								'type'  => 'tool_execution',
							),
							'Tool execution failed — feedback sent to model for retry'
						);
					}

					// Stop looping as soon as a tool needs user sign-off: in-chat
					// confirmation (medium) OR the admin approval queue (high). Both are
					// successful pauses, NOT errors — relay the tool's own message.
					//
					// This must break the tool_calls loop itself, not just note the
					// pending state and keep going — a model that batches several tool
					// calls in one turn (e.g. one per deployment channel to answer "where
					// is everything deployed") would otherwise create a separate proposal
					// for every one of them, leaving the top-level response text (built
					// from whichever proposal's message was seen LAST) mismatched against
					// the "Proposed Change" card actually shown (built from whichever
					// proposal was seen FIRST, further down in $tool_results).
					$tool_status = $tool_result['status'] ?? '';
					if ( 'confirmation_required' === $tool_status || 'queued_for_approval' === $tool_status ) {
						$has_pending_proposal = true;
						if ( ! empty( $tool_result['message'] ) ) {
							$pending_message = (string) $tool_result['message'];
						}
						break;
					}
				}

				// Break before sending tool results back to the LLM — a pending proposal
				// or queued action must be approved by the user first.
				if ( $has_pending_proposal ) {
					$response = '' !== $pending_message
						? $pending_message
						: __( 'Please review and approve the action below to proceed.', 'agent-builder' );
					break;
				}

				// Safety cap on tool ERROR-feedback retries only. A successful multi-tool
				// workflow must never trip this — only iterations that hit a real tool
				// error count toward the limit.
				if ( $iteration_had_error ) {
					++$tool_retries;
					if ( $tool_retries >= $max_tool_retries ) {
						$response = 'I attempted to use tools multiple times but kept encountering errors. Please try rephrasing your request or use a more capable model.';
						break;
					}
				}
			} else {
				// No more tool calls, we have our final response.
				$response = $assistant_message['content'];
				if ( 'length' === ( $choice['finish_reason'] ?? '' ) ) {
					$response .= self::truncation_notice();
				}
				break;
			}
		}

		if ( null === $response ) {
			$response = 'I reached the maximum number of tool iterations. Please try a simpler request.';
		}

		// Estimate cost using per-model pricing from the providers table, summed
		// across every iteration's usage — not just the final turn's (a request
		// that took several tool-calling round trips previously under-reported
		// cost/tokens down to whatever the last LLM call alone used).
		$current_provider = $this->llm->get_provider();
		$current_model    = $this->llm->get_model();
		$estimated_cost   = class_exists( '\Agentic\Costs_Manager' )
			? \Agentic\Costs_Manager::estimate_cost( $current_provider, $total_prompt_tokens, $total_completion_tokens, $current_model )
			: 0.0;

		// Log completion with real reasoning from the final assistant turn (P0 observability).
		$final_reasoning = $this->extract_reasoning( $assistant_message );
		$this->audit->log(
			$current_agent_id,
			'chat_complete',
			'conversation',
			array(
				'session_id' => $session_id,
				'iterations' => $iterations,
				'tools_used' => array_column( $tool_results, 'tool' ),
				'prompt'     => substr( $message, 0, 500 ),
				'response'   => substr( $response, 0, 1000 ),
			),
			$final_reasoning,
			$total_tokens,
			$estimated_cost,
			$current_provider
		);

		$result = array(
			'response'    => $response,
			'agent_id'    => $current_agent_id,
			'agent_name'  => $this->current_agent->get_name(),
			'agent_icon'  => $this->current_agent->get_icon(),
			'session_id'  => $session_id,
			'tokens_used' => $total_tokens,
			'cost'        => round( $estimated_cost, 6 ),
			'tools_used'  => array_column( $tool_results, 'tool' ),
			'iterations'  => $iterations,
			'reasoning'   => $final_reasoning,  // P0 observability. — surfaced for chat UI cards.
		);

		// Surface any pending proposal or high-risk queued approval from tool
		// results to the chat UI, so the user can act on it without leaving
		// the conversation (queued approvals otherwise sit invisibly until a
		// separate trip to the Approval Queue admin page).
		foreach ( $tool_results as $tr ) {
			$tr_status = $tr['result']['status'] ?? '';

			if ( 'confirmation_required' === $tr_status || ! empty( $tr['result']['pending_proposal'] ) ) {
				$result['pending_proposal'] = true;
				$result['proposal']         = array(
					'kind'        => 'proposal',
					'id'          => $tr['result']['proposal_id'],
					'description' => $tr['result']['description'] ?? $tr['result']['reason'] ?? '',
					'diff'        => $tr['result']['diff'] ?? '',
					'tool'        => $tr['tool'],
				);
				break; // One proposal per response.
			}

			if ( 'queued_for_approval' === $tr_status ) {
				$result['pending_proposal'] = true;
				$result['proposal']         = array(
					'kind'        => 'approval',
					'id'          => $tr['result']['approval_id'],
					'description' => sprintf(
						'%s — %s',
						str_replace( '_', ' ', (string) $tr['tool'] ),
						(string) ( $tr['result']['reason'] ?? '' )
					),
					'tool'        => $tr['tool'],
				);
				break;
			}
		}

		// Clear per-agent overrides so they don't bleed into subsequent calls in the same process.
		Agent_Permissions::set_mode_override( null );

		// Record usage against per-role daily limits (only for real user requests).
		if ( $user_id >= 0 ) {
			Usage_Limits::record_query( $user_id );
			if ( $total_tokens > 0 ) {
				Usage_Limits::record_tokens( $user_id, $total_tokens );
			}
		}

		// Persist this turn to local memory for future recall (no-cloud, opt-in).
		Local_Memory::record_turn( $current_agent_id, $user_id, $message, (string) $response );

		// Store response in cache for future identical queries.
		if ( Response_Cache::should_cache( $message, $history, $current_agent_id ) ) {
			Response_Cache::set( $message, $current_agent_id, $result, $user_id );
		}

		return $result;
	}

	/**
	 * Run an autonomous task through the LLM
	 *
	 * Used by scheduled tasks that define a `prompt` field. The agent runs
	 * through the full LLM loop (with tool calls) in autonomous mode,
	 * meaning no human user is present.
	 *
	 * Bypasses user access checks since this is system-initiated.
	 *
	 * Backed by {@see Agent_Run} for iteration/cost bookkeeping, cooperative
	 * cancellation, and resumable transcript checkpoints — except when this
	 * call is itself a nested delegation (delegate_to_agent already owns the
	 * shared run and accumulates this call's totals into it via add_usage();
	 * recording them again here would double-count).
	 *
	 * @param \Agentic\Agent_Base $agent   Agent instance.
	 * @param string              $prompt  Task prompt describing what to do.
	 * @param string              $task_id Task identifier for logging.
	 * @param array               $options {
	 *     Optional.
	 *
	 *     @type string $run_id       Id of a previously paused run to resume (required with resume_state).
	 *     @type array  $resume_state Value previously returned by Agent_Run::resume_state().
	 *     @type array  $tool_result  Resolved outcome of the tool call the run was waiting on, appended
	 *                                as a tool-role message before the loop continues.
	 * }
	 * @return array|null Response data, or null if LLM is not configured or the run errored.
	 */
	public function run_autonomous_task( \Agentic\Agent_Base $agent, string $prompt, string $task_id = '', array $options = array() ): ?array {
		$this->current_agent = $agent;
		$agent_id            = $agent->get_id();

		// Fail fast — before any LLM call — when this agent has been explicitly
		// disabled via its per-agent override. Autonomous tasks otherwise ignore
		// the global/agent-default confirmation mode entirely (MODE_AUTO below
		// forces no-confirmation execution), so this is the only mode check that
		// can stop a scheduled run from starting.
		if ( 'disabled' === Agent_Settings::get( $agent_id, 'override_mode' ) ) {
			return array(
				'error'    => true,
				'response' => __( 'This agent is disabled and cannot run autonomous tasks.', 'agent-builder' ),
				'agent_id' => $agent_id,
				'task_id'  => $task_id,
				'run_id'   => '',
				'status'   => 'error',
				'cards'    => array(),
			);
		}

		if ( ! $this->llm->is_configured() ) {
			return null; // Caller will fall back to direct callback.
		}

		// Apply per-agent provider/model overrides (confirmation mode is always
		// bypassed for scheduled tasks, regardless of the resolved mode).
		$this->apply_agent_overrides();
		$this->current_agent_mode = 'autonomous';
		Agent_Permissions::set_mode_override( Agent_Permissions::MODE_AUTO );
		Audit_Log::set_mode_context( 'autonomous' );

		// A run is already active when this call is a nested delegation —
		// delegate_to_agent begins/owns the shared run itself and accumulates
		// this call's returned totals into it via add_usage(). In that case we
		// must not also record iterations or finish the run ourselves.
		$owns_run = ! ( Agent_Run::current() instanceof Agent_Run );

		$resume_state       = isset( $options['resume_state'] ) && is_array( $options['resume_state'] ) ? $options['resume_state'] : null;
		$resume_tool_result = isset( $options['tool_result'] ) && is_array( $options['tool_result'] ) ? $options['tool_result'] : null;
		$resume_run_id      = (string) ( $options['run_id'] ?? '' );

		if ( null !== $resume_state && '' !== $resume_run_id ) {
			$run = Agent_Run::load( $resume_run_id );
			if ( ! $run ) {
				return array(
					'error'    => true,
					'response' => __( 'Cannot resume: run not found.', 'agent-builder' ),
					'agent_id' => $agent_id,
					'task_id'  => $task_id,
					'run_id'   => $resume_run_id,
					'status'   => 'error',
					'cards'    => array(),
				);
			}
			$run->make_current();
			$owns_run = true; // Resuming a paused run in a fresh process; this leg owns it.

			$messages   = is_array( $resume_state['messages'] ?? null ) ? $resume_state['messages'] : array();
			$iterations = max( 0, (int) ( $resume_state['iterations'] ?? 0 ) );

			if ( null !== $resume_tool_result ) {
				$messages[] = array(
					'role'         => 'tool',
					'tool_call_id' => (string) ( $resume_state['awaiting_id'] ?? '' ),
					'name'         => (string) ( $resume_tool_result['tool'] ?? '' ),
					'content'      => wp_json_encode( $resume_tool_result ),
				);
			}
		} else {
			$run = Agent_Run::begin(
				$agent_id,
				array(
					'kind'       => 'task',
					'task_text'  => $prompt,
					'invocation' => '' !== $this->invocation_context ? $this->invocation_context : 'cron',
					'source_ref' => '' !== $task_id ? ( 'task:' . $task_id ) : '',
				)
			);

			// Build autonomous system prompt.
			$autonomous_context = "\n\n[AUTONOMOUS MODE]\n"
				. "You are running autonomously as a scheduled task (task: {$task_id}). "
				. "There is no human user in this conversation.\n"
				. 'Execute the requested task using your available tools, then provide '
				. "a concise summary of what you did and any findings.\n";

			$use_weak_guidance = $this->should_use_weak_model_tool_guidance();
			$system_prompt     = Agent_Prompt_Builder::build( $agent, $autonomous_context, '', '', $use_weak_guidance );

			$messages   = array(
				array(
					'role'    => 'system',
					'content' => $system_prompt,
				),
				array(
					'role'    => 'user',
					'content' => $prompt,
				),
			);
			$iterations = 0;
		}

		// Distinguishable session id for this autonomous run (mirrors chat(),
		// which uses the chat session id) — fixes autonomous runs previously
		// sharing whatever current_session_id a prior chat() call left behind.
		$this->current_session_id = $run->get_run_id();
		$session_id               = $run->get_run_id();

		$tools = $this->get_tools_for_agent();

		$this->audit->log(
			$agent_id,
			$iterations > 0 ? 'autonomous_chat_resume' : 'autonomous_chat_start',
			'scheduled_task',
			array(
				'task_id'    => $task_id,
				'session_id' => $session_id,
				'run_id'     => $run->get_run_id(),
				'prompt'     => substr( $prompt, 0, 500 ),
			)
		);

		// Process with tool calls (same loop shape as chat()).
		$response                = null;
		$total_tokens            = 0;
		$total_prompt_tokens     = 0;
		$total_completion_tokens = 0;
		$tool_results            = array();
		$usage                   = array(
			'prompt_tokens'     => 0,
			'completion_tokens' => 0,
		);
		$assistant_message       = array( 'content' => '' );
		$max_iterations          = (int) apply_filters( 'agentic_max_tool_iterations', self::MAX_ITERATIONS_DEFAULT, $agent_id );
		$final_status            = 'completed';
		$loop_start              = microtime( true );
		// The elapsed-time guard only applies when this call owns the run: a
		// nested delegation shares the outer loop's own time budget instead.
		$max_execution_time = $owns_run ? $this->get_autonomous_time_budget() : 0;

		while ( $iterations < $max_iterations ) {
			++$iterations;

			if ( class_exists( __NAMESPACE__ . '\\Emergency_Stop' ) && Emergency_Stop::is_active() ) {
				$final_status = 'aborted';
				$response     = Emergency_Stop::blocked_message();
				break;
			}

			if ( $run->cancel_requested() ) {
				$final_status = 'cancelled';
				$response     = __( 'This task was cancelled.', 'agent-builder' );
				break;
			}

			if ( $max_execution_time > 0 && ( microtime( true ) - $loop_start ) >= 0.7 * $max_execution_time ) {
				$run->checkpoint_transcript( $messages );
				$this->dispatch_continuation( $run, $agent, $prompt, $task_id );

				$estimated_cost = class_exists( '\Agentic\Costs_Manager' )
					? \Agentic\Costs_Manager::estimate_cost( $this->llm->get_provider(), $total_prompt_tokens, $total_completion_tokens, $this->llm->get_model() )
					: 0.0;

				return array(
					'response'    => __( 'This task is taking a while and will continue in the background.', 'agent-builder' ),
					'agent_id'    => $agent_id,
					'task_id'     => $task_id,
					'mode'        => 'autonomous',
					'run_id'      => $run->get_run_id(),
					'status'      => 'continuing',
					'session_id'  => $session_id,
					'tokens_used' => $total_tokens,
					'cost'        => round( $estimated_cost, 6 ),
					'tools_used'  => array_column( $tool_results, 'tool' ),
					'iterations'  => $iterations - 1,
					'cards'       => array(),
				);
			}

			$force_tools = ( 1 === $iterations )
				&& ! empty( $tools )
				&& $this->current_agent
				&& $this->current_agent->requires_tool_use();

			$result = $this->llm->chat( $messages, $tools, $force_tools );

			if ( is_wp_error( $result ) ) {
				$this->audit->log(
					$agent_id,
					'autonomous_chat_error',
					'scheduled_task',
					array(
						'task_id' => $task_id,
						'error'   => $result->get_error_message(),
					)
				);
				if ( $owns_run ) {
					$run->finish( 'failed', array( 'error' => $result->get_error_message() ) );
				}
				return null;
			}

			$usage                    = $this->llm->get_usage( $result );
			$total_tokens            += $usage['total_tokens'] ?? 0;
			$total_prompt_tokens     += $usage['prompt_tokens'] ?? 0;
			$total_completion_tokens += $usage['completion_tokens'] ?? 0;

			$choice = $result['choices'][0] ?? null;
			if ( ! $choice ) {
				if ( $owns_run ) {
					$run->finish( 'failed', array( 'error' => 'Invalid response from AI.' ) );
				}
				return null;
			}

			$assistant_message = $choice['message'];
			if ( ! isset( $assistant_message['content'] ) || null === $assistant_message['content'] ) {
				$assistant_message['content'] = '';
			}
			$messages[] = $assistant_message;

			// Capture reasoning for observability (P0) — autonomous path.
			$step_reasoning = $this->extract_reasoning( $assistant_message );

			$iter_tools   = array();
			$has_pending  = false;
			$pending_msg  = '';
			$pending_type = '';
			$pending_id   = '';

			if ( ! empty( $assistant_message['tool_calls'] ) ) {
				$this->audit->log(
					$agent_id,
					'tool_choice',
					'scheduled_task',
					array(
						'tools'      => array_map(
							static fn( $tc ) => $tc['function']['name'] ?? '',
							$assistant_message['tool_calls']
						),
						'iterations' => $iterations,
					),
					$step_reasoning,
					$usage['total_tokens'] ?? 0,
					0.0,
					$this->llm->get_provider()
				);

				foreach ( $assistant_message['tool_calls'] as $tool_call ) {
					$function_name = $tool_call['function']['name'];
					$arguments     = json_decode( $tool_call['function']['arguments'], true ) ?? array();
					$iter_tools[]  = $function_name;

					$tool_result = $this->execute_tool( $function_name, $arguments );

					$messages[] = array(
						'role'         => 'tool',
						'tool_call_id' => $tool_call['id'],
						'name'         => $function_name,
						'content'      => wp_json_encode( $tool_result ),
					);

					$tool_results[] = array(
						'tool'   => $function_name,
						'result' => $tool_result,
					);

					// Same as interactive chat: stop at first confirmation/queue so
					// autonomous turns do not fan out multiple pending approvals.
					$tool_status = $tool_result['status'] ?? '';
					if ( 'confirmation_required' === $tool_status || 'queued_for_approval' === $tool_status ) {
						$has_pending  = true;
						$pending_type = 'confirmation_required' === $tool_status ? 'proposal' : 'approval';
						$pending_id   = (string) ( $tool_result['proposal_id'] ?? $tool_result['approval_id'] ?? '' );
						if ( ! empty( $tool_result['message'] ) ) {
							$pending_msg = (string) $tool_result['message'];
						}
						break;
					}
				}
			} else {
				$response = $assistant_message['content'];
				if ( 'length' === ( $choice['finish_reason'] ?? '' ) ) {
					$response .= "\n\n" . __( '[Note: this result was cut short — the model hit its token limit before finishing.]', 'agent-builder' );
				}
			}

			if ( $owns_run ) {
				$iter_cost = class_exists( '\Agentic\Costs_Manager' )
					? \Agentic\Costs_Manager::estimate_cost( $this->llm->get_provider(), $usage['prompt_tokens'] ?? 0, $usage['completion_tokens'] ?? 0, $this->llm->get_model() )
					: 0.0;
				$run->record_iteration( $iter_tools, $usage['total_tokens'] ?? 0, $iter_cost );
				$run->checkpoint_transcript( $messages );
			}

			if ( $has_pending ) {
				if ( $owns_run ) {
					$run->mark_waiting( $pending_type, $pending_id, $messages );
				}

				$estimated_cost = class_exists( '\Agentic\Costs_Manager' )
					? \Agentic\Costs_Manager::estimate_cost( $this->llm->get_provider(), $total_prompt_tokens, $total_completion_tokens, $this->llm->get_model() )
					: 0.0;

				return array(
					'response'    => '' !== $pending_msg
						? $pending_msg
						: __( 'A tool action is waiting for approval before this task can continue.', 'agent-builder' ),
					'agent_id'    => $agent_id,
					'task_id'     => $task_id,
					'mode'        => 'autonomous',
					'run_id'      => $run->get_run_id(),
					'status'      => 'waiting',
					'session_id'  => $session_id,
					'tokens_used' => $total_tokens,
					'cost'        => round( $estimated_cost, 6 ),
					'tools_used'  => array_column( $tool_results, 'tool' ),
					'iterations'  => $iterations,
					'cards'       => array(),
				);
			}

			if ( null !== $response ) {
				break; // Final answer reached.
			}
		}

		if ( null === $response ) {
			$response = 'Reached maximum tool iterations for autonomous task.';
		}

		// Estimate cost using per-model pricing from the providers table, summed
		// across every iteration's usage.
		$current_provider = $this->llm->get_provider();
		$current_model    = $this->llm->get_model();
		$estimated_cost   = class_exists( '\Agentic\Costs_Manager' )
			? \Agentic\Costs_Manager::estimate_cost( $current_provider, $total_prompt_tokens, $total_completion_tokens, $current_model )
			: 0.0;

		// Log autonomous completion with real reasoning (P0 observability).
		$final_reasoning = $this->extract_reasoning( $assistant_message );
		$this->audit->log(
			$agent_id,
			'autonomous_chat_complete',
			'scheduled_task',
			array(
				'task_id'    => $task_id,
				'session_id' => $session_id,
				'iterations' => $iterations,
				'tools_used' => array_column( $tool_results, 'tool' ),
				'response'   => substr( $response, 0, 1000 ),
			),
			$final_reasoning,
			$total_tokens,
			$estimated_cost,
			$current_provider
		);

		if ( $owns_run ) {
			$run->finish( $final_status, array( 'text' => (string) $response ) );
		}

		return array(
			'response'    => $response,
			'agent_id'    => $agent_id,
			'task_id'     => $task_id,
			'mode'        => 'autonomous',
			'run_id'      => $run->get_run_id(),
			'status'      => $final_status,
			'session_id'  => $session_id,
			'tokens_used' => $total_tokens,
			'cost'        => round( $estimated_cost, 6 ),
			'tools_used'  => array_column( $tool_results, 'tool' ),
			'iterations'  => $iterations,
			'reasoning'   => $final_reasoning,  // P0 observability.
			'cards'       => array(),
		);
	}

	/**
	 * Fire the continuation seam for a run that has used ~70% of its
	 * execution-time budget.
	 *
	 * Does not itself dispatch a background job — Agent_Task_Job_Processor
	 * (10d2) hooks this action to schedule one that resumes the run via
	 * run_autonomous_task()'s $options['run_id']/['resume_state']. Until that
	 * processor exists, this just stops the loop cleanly instead of letting
	 * it run into the PHP time limit.
	 *
	 * @param Agent_Run           $run     Run being checkpointed.
	 * @param \Agentic\Agent_Base $agent   Agent instance.
	 * @param string              $prompt  Original task prompt.
	 * @param string              $task_id Task identifier.
	 * @return void
	 */
	protected function dispatch_continuation( Agent_Run $run, \Agentic\Agent_Base $agent, string $prompt, string $task_id ): void {
		/**
		 * Fires when an autonomous run has used ~70% of its execution-time
		 * budget and must hand off to a background continuation job.
		 *
		 * @param string              $run_id  Run id to resume.
		 * @param \Agentic\Agent_Base $agent   Agent instance.
		 * @param string              $prompt  Original task prompt.
		 * @param string              $task_id Task identifier.
		 */
		do_action( 'agent_builder_run_needs_continuation', $run->get_run_id(), $agent, $prompt, $task_id );
	}

	/**
	 * Resolve the execution-time budget (in seconds) an autonomous run should
	 * treat as its ceiling, for the ~70% elapsed-time guard.
	 *
	 * Falls back to a filterable default when max_execution_time is 0
	 * (unlimited — the common case on CLI/WP-Cron).
	 *
	 * @return int Seconds, or 0 to disable the guard entirely.
	 */
	protected function get_autonomous_time_budget(): int {
		$ini_limit = (int) ini_get( 'max_execution_time' );
		$default   = $ini_limit > 0 ? $ini_limit : 300;

		return (int) apply_filters( 'agent_builder_autonomous_max_execution_time', $default );
	}

	/**
	 * Extract a concise reasoning snippet from an assistant message.
	 *
	 * Used to populate the previously under-used `reasoning` column in the
	 * audit log. This is what powers the new visibility in chat history,
	 * Audit Log, and Conversations.
	 *
	 * For P0 we use a simple, robust heuristic (the model's own words).
	 * Future slices / Pro can evolve this into XML-aware parsing or
	 * structured output extraction via the `agentic_extracted_reasoning` filter.
	 *
	 * @param array $assistant_message The message array from the LLM response.
	 * @return string Truncated plain-text reasoning (or empty).
	 */
	private function extract_reasoning( array $assistant_message ): string {
		$content = isset( $assistant_message['content'] ) ? trim( (string) $assistant_message['content'] ) : '';

		if ( '' === $content ) {
			return '';
		}

		// 1200 chars is plenty for UI + audit without bloating the DB.
		// The full response is still stored in details/response columns.
		return substr( $content, 0, 1200 );
	}

	/**
	 * Determine if we should inject extra tool guidance optimized for weaker models.
	 *
	 * Used for P0 Item 3 (Reliable Tool Use on Weaker Models).
	 * Respects per-agent setting first, then global option, then heuristic.
	 *
	 * @return bool
	 */
	private function should_use_weak_model_tool_guidance(): bool {
		$agent_id = $this->current_agent ? $this->current_agent->get_id() : '';

		// Per-agent override (highest priority).
		if ( $agent_id ) {
			$per_agent = Agent_Settings::get( $agent_id, 'weak_model_tool_guidance', '' );
			if ( '' !== $per_agent ) {
				return '1' === $per_agent;
			}
		}

		// Global option.
		$global = get_option( 'agent_builder_enable_weak_model_tool_guidance', '' );
		if ( '' !== $global ) {
			return '1' === $global;
		}

		if ( ! $this->llm ) {
			return false;
		}

		$provider = $this->llm->get_provider();
		$model    = strtolower( $this->llm->get_model() ?? '' );

		// 'agentic' provider is our main path for smaller/local models.
		if ( 'agentic' === $provider ) {
			return true;
		}

		// Heuristic for direct Ollama and other small models.
		$default_indicators = array( 'ollama', 'gemma', 'phi', 'llama3:8b', 'llama3.1:8b', 'qwen2:7b', 'mistral:7b' );
		$weak_indicators    = apply_filters( 'agentic_weak_model_indicators', $default_indicators );

		foreach ( (array) $weak_indicators as $indicator ) {
			if ( str_contains( $model, $indicator ) ) {
				return true;
			}
		}

		return false;
	}
}
