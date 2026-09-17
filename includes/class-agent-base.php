<?php
/**
 * Base Agent Class
 *
 * All agents extend this class to provide their identity, system prompt,
 * and available tools. The Agent Controller uses this to run conversations.
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
 * Abstract base class for all Agentic agents
 *
 * Implementations must provide identifiers, descriptions, and optionally tools
 * to be used by the agent controller.
 */
abstract class Agent_Base {

	/**
	 * Get the agent's unique identifier (slug)
	 *
	 * @return string Agent ID (e.g., 'content-assistant', 'wordpress-assistant')
	 */
	abstract public function get_id(): string;

	/**
	 * Get the agent's display name.
	 *
	 * Reads "Agent Name:" from the file header. Override only when the name
	 * cannot be derived from the header.
	 *
	 * @return string Human-readable name
	 */
	public function get_name(): string {
		return $this->parse_header()['agent name'] ?? '';
	}

	/**
	 * Get the agent's description.
	 *
	 * Reads "Description:" from the file header.
	 *
	 * @return string Description of what the agent does
	 */
	public function get_description(): string {
		return $this->parse_header()['description'] ?? '';
	}

	/**
	 * Get the agent's system prompt.
	 *
	 * Loads from templates/system-prompt.txt relative to the agent's PHP file.
	 *
	 * @return string System prompt for the LLM
	 */
	public function get_system_prompt(): string {
		try {
			$file    = dirname( ( new \ReflectionClass( $this ) )->getFileName() ) . '/templates/system-prompt.txt';
			$content = File_Manager::get_contents( $file );
			if ( false !== $content ) {
				return $content;
			}
		} catch ( \ReflectionException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Intentional fallback.
			// Fall through.
		}
		return '';
	}

	/**
	 * Get the agent's icon (emoji or dashicon).
	 *
	 * Reads "Icon:" from the file header. Falls back to 🤖.
	 *
	 * @return string Icon for display
	 */
	public function get_icon(): string {
		return $this->parse_header()['icon'] ?? '🤖';
	}

	/**
	 * Get the agent's category.
	 *
	 * Reads "Category:" from the file header. Falls back to 'admin'.
	 *
	 * @return string Category (content, admin, ecommerce, frontend, developer)
	 */
	public function get_category(): string {
		return $this->parse_header()['category'] ?? 'admin';
	}

	/**
	 * Get required WordPress capabilities.
	 *
	 * Reads "Capabilities:" from the file header as a comma-separated list.
	 * Falls back to ['read'].
	 *
	 * @return string[]
	 */
	public function get_required_capabilities(): array {
		$caps = $this->parse_header()['capabilities'] ?? '';
		if ( '' === $caps ) {
			return array( 'read' );
		}
		return array_values( array_filter( array_map( 'trim', explode( ',', $caps ) ) ) );
	}

	/**
	 * Get the agent's preferred default operating mode.
	 *
	 * Agents can override this to declare a default mode that makes sense for
	 * their risk profile. The user's per-agent override always takes priority.
	 * Return empty string to fall back to the global site default.
	 *
	 * @return string 'supervised'|'autonomous'|'' (empty = use global default).
	 */
	public function get_default_mode(): string {
		return '';
	}

	/**
	 * Whether this agent is a team lead that can delegate to other agents.
	 *
	 * Team leads are given a roster of the other active agents in their system
	 * prompt and are expected to use the delegate_to_agent tool to coordinate
	 * work. Reads the "Team:" header field (truthy value enables it).
	 *
	 * @return bool
	 */
	public function is_team_lead(): bool {
		$team = strtolower( trim( $this->parse_header()['team'] ?? '' ) );
		return in_array( $team, array( 'true', '1', 'yes', 'lead', 'on' ), true );
	}

	/**
	 * Whether this agent requires tool use on every turn.
	 *
	 * When true, the LLM request will force at least one tool call per turn
	 * instead of allowing the model to respond with text only. Useful for
	 * agents whose purpose is to execute tools (e.g. the Assistant Trainer).
	 *
	 * @return bool
	 */
	public function requires_tool_use(): bool {
		return false;
	}

	/**
	 * Parse all header fields from the agent's PHP file — one file read per class per request.
	 *
	 * Returns an array keyed by lowercase field name, e.g. ['version' => '1.2', 'author' => 'Acme'].
	 *
	 * @return array<string, string>
	 */
	private function parse_header(): array {
		static $cache = array();
		$class        = static::class;
		if ( isset( $cache[ $class ] ) ) {
			return $cache[ $class ];
		}
		$parsed = array();
		try {
			$file   = ( new \ReflectionClass( $this ) )->getFileName();
			$header = file_get_contents( $file, false, null, 0, 1024 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read for agent header fields.
			if ( preg_match_all( '/^\s*\*\s*([\w\s]+?):\s*(.+)/m', $header, $matches, PREG_SET_ORDER ) ) {
				foreach ( $matches as $match ) {
					$parsed[ strtolower( trim( $match[1] ) ) ] = trim( $match[2] );
				}
			}
		} catch ( \ReflectionException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Intentional fallback.
			// Fall through — $parsed stays empty.
		}
		$cache[ $class ] = $parsed;
		return $parsed;
	}

	/**
	 * Get the agent's version.
	 *
	 * Reads the "Version:" header from the agent's PHP file. Falls back to 1.0.0.
	 *
	 * @return string Version number
	 */
	public function get_version(): string {
		return $this->parse_header()['version'] ?? '1.0.0';
	}

	/**
	 * Get the agent's author.
	 *
	 * Reads the "Author:" header from the agent's PHP file. Falls back to "Unknown".
	 *
	 * @return string Author name
	 */
	public function get_author(): string {
		return $this->parse_header()['author'] ?? __( 'Unknown', 'agent-builder' );
	}

	/**
	 * Get the agent's author URI.
	 *
	 * Reads the "Author URI:" header from the agent's PHP file.
	 *
	 * @return string Author URL or empty string.
	 */
	public function get_author_uri(): string {
		return $this->parse_header()['author uri'] ?? '';
	}

	/**
	 * Get the names of tools this agent uses.
	 *
	 * Override this to declare which standalone tools from tools/ this agent
	 * should have access to. The Tool_Loader resolves these names to
	 * definitions and executors at runtime.
	 *
	 * @return string[] Tool name slugs (e.g., ['analyze_internal_links', 'fix_orphan_pages']).
	 */
	public function get_tool_names(): array {
		$manifest = Abilities_Manifest::load( $this->get_id() );
		$tools    = ( $manifest && ! empty( $manifest['abilities'] ) )
			? array_keys( (array) $manifest['abilities'] )
			: array();
		$tools    = $this->with_global_tools( $tools );
		/**
		 * Filter the tool name list for an agent (e.g. site-local tools).
		 *
		 * @param string[] $tools    Tool slugs.
		 * @param string   $agent_id Agent id.
		 */
		return apply_filters( 'agentic_agent_tool_names', $tools, $this->get_id() );
	}

	/**
	 * Append tools that every agent should always have, regardless of its
	 * manifest — currently the diagnostics/reporting tool so any assistant can
	 * self-diagnose an error and (for admins) report it to support.
	 *
	 * @param string[] $tools The agent's declared tool names.
	 * @return string[]
	 */
	protected function with_global_tools( array $tools ): array {
		$global = apply_filters( 'agentic_global_agent_tools', array( 'report_issue' ), $this );
		foreach ( (array) $global as $global_tool ) {
			if ( is_string( $global_tool ) && '' !== $global_tool && ! in_array( $global_tool, $tools, true ) ) {
				$tools[] = $global_tool;
			}
		}
		return $tools;
	}

	/**
	 * Get agent-inline tool definitions.
	 *
	 * Override this to define tools that are implemented directly inside the
	 * agent class rather than as standalone Tool_Base files in tools/.
	 * Each entry must follow the OpenAI function-calling schema:
	 * [ 'type' => 'function', 'function' => [ 'name' => ..., 'parameters' => ... ] ]
	 *
	 * @return array[] Tool definitions in OpenAI function-calling format.
	 */
	public function get_tools(): array {
		return array();
	}

	/**
	 * Execute an agent-inline tool.
	 *
	 * Called by the Agent_Controller when a tool call matches a tool defined
	 * in get_tools() rather than a standalone Tool_Base. Override this to
	 * route tool names to their implementations.
	 *
	 * @param string $_tool_name Tool name.
	 * @param array  $_arguments Tool arguments from the LLM.
	 * @return array|null Tool result, or null if the tool is not handled.
	 */
	public function execute_tool( string $_tool_name, array $_arguments ): ?array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Base stub; subclasses use both params.
		return null;
	}

	/**
	 * Get welcome message for chat interface.
	 *
	 * Returns the admin-configured message (Settings → Instructions) if set,
	 * otherwise falls back to the child class's hardcoded default.
	 *
	 * @return string Welcome message shown when chat opens.
	 */
	public function get_welcome_message(): string {
		$override = Agent_Settings::get( $this->get_id(), 'persona_welcome_message' );
		$message  = ( ! empty( trim( (string) $override ) ) ) ? (string) $override : $this->get_default_welcome_message();
		return $this->expand_welcome_tokens( $message );
	}

	/**
	 * Expand dynamic tokens in a welcome message.
	 *
	 * Supports {{active_agents}}, which renders a live markdown list of the
	 * installed + activated agents accessible to the current user (excluding
	 * this agent). This keeps the greeting honest on every install instead of
	 * hardcoding a roster the site may not actually have.
	 *
	 * @param string $message Raw welcome message.
	 * @return string Message with tokens expanded.
	 */
	protected function expand_welcome_tokens( string $message ): string {
		if ( false === strpos( $message, '{{active_agents}}' ) ) {
			return $message;
		}
		return str_replace( '{{active_agents}}', $this->build_active_agents_list(), $message );
	}

	/**
	 * Build a markdown bullet list of the installed + activated agents the
	 * current user can access, excluding this agent.
	 *
	 * @return string Markdown list, or '' when no other agents are active.
	 */
	protected function build_active_agents_list(): string {
		if ( ! class_exists( '\Agentic_Agent_Registry' ) ) {
			return '';
		}

		$lines = array();
		foreach ( \Agentic_Agent_Registry::get_instance()->get_accessible_instances() as $agent ) {
			if ( $agent->get_id() === $this->get_id() ) {
				continue;
			}
			$icon   = trim( $agent->get_icon() );
			$prefix = '' !== $icon ? $icon . ' ' : '';

			// Keep the roster scannable: first sentence, hard-capped at a word
			// boundary, so the greeting stays a menu, not a wall of text.
			$desc = trim( (string) $agent->get_description() );
			if ( preg_match( '/^(.*?[.!?])(?:\s|$)/u', $desc, $m ) ) {
				$desc = $m[1];
			}
			if ( function_exists( 'mb_strlen' ) && mb_strlen( $desc ) > 100 ) {
				$clip = mb_substr( $desc, 0, 100 );
				$sp   = mb_strrpos( $clip, ' ' );
				if ( false !== $sp && $sp > 60 ) {
					$clip = mb_substr( $clip, 0, $sp );
				}
				$desc = rtrim( $clip, ' ,;:-' ) . '…';
			}

			$lines[] = sprintf( '- %s**%s** — %s', $prefix, $agent->get_name(), $desc );
		}

		return implode( "\n", $lines );
	}

	/**
	 * Default welcome message — override in child classes.
	 *
	 * @return string
	 */
	protected function get_default_welcome_message(): string {
		return sprintf(
			/* translators: 1: agent name, 2: agent description */
			__( "Hello! I'm %1\$s. %2\$s\n\nHow can I help you today?", 'agent-builder' ),
			$this->get_name(),
			$this->get_description()
		);
	}

	/**
	 * Get suggested prompts for the chat interface.
	 *
	 * Returns admin-saved prompts (Settings → Instructions) if available,
	 * otherwise falls back to the child class's hardcoded defaults.
	 *
	 * @return array Array of suggested prompts
	 */
	public function get_suggested_prompts(): array {
		$saved_json = Agent_Settings::get( $this->get_id(), 'persona_suggested_prompts' );
		if ( ! empty( $saved_json ) ) {
			$saved = json_decode( $saved_json, true );
			if ( is_array( $saved ) && ! empty( $saved ) ) {
				return array_slice( array_filter( $saved ), 0, 4 );
			}
		}
		return $this->get_default_suggested_prompts();
	}

	/**
	 * Default suggested prompts — override in child classes.
	 *
	 * @return array Array of suggested prompts
	 */
	protected function get_default_suggested_prompts(): array {
		return array();
	}

	/**
	 * Return the default settings to seed into agent_builder_agent_settings on first activation.
	 *
	 * These values are written once by Agent_Settings::seed_defaults() and are
	 * never overwritten — admin changes persist across activations. Subclasses
	 * should call parent::get_default_settings() and merge their own keys on top.
	 *
	 * Keys defined here (shared by every agent):
	 *  - admin_bar_display   '1' = show in admin bar, '' = hidden (default: hidden)
	 *  - admin_bar_position  'bottom-right' | 'bottom-left'
	 *  - admin_bar_pages     'all' | 'admin' | 'front'
	 *
	 * @return array<string,string>
	 */
	public function get_default_settings(): array {
		return array(
			'admin_bar_display'  => '',
			'admin_bar_position' => 'bottom-right',
			'admin_bar_pages'    => 'all',
		);
	}

	/**
	 * Check if current user can access this agent
	 *
	 * @return bool Whether user has access
	 */
	public function current_user_can_access(): bool {
		// Anonymous users have no WP capabilities. If the site allows anonymous chat,
		// the REST API permission callback already validated that; skip cap checks here.
		if ( ! is_user_logged_in() ) {
			return (bool) get_option( 'agent_builder_allow_anonymous_chat', false );
		}
		foreach ( $this->get_required_capabilities() as $cap ) {
			if ( ! current_user_can( $cap ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Get agent metadata for registration/display
	 *
	 * @return array Agent metadata
	 */
	public function get_metadata(): array {
		return array(
			'id'          => $this->get_id(),
			'name'        => $this->get_name(),
			'description' => $this->get_description(),
			'icon'        => $this->get_icon(),
			'category'    => $this->get_category(),
			'tools'       => $this->get_tool_names(),
			'team_lead'   => $this->is_team_lead(),
		);
	}

	/**
	 * Get event listeners for this agent
	 *
	 * Override this to react to WordPress actions as they happen.
	 * Each listener array must include:
	 *   - 'id'            (string) Unique listener identifier within this agent.
	 *   - 'hook'          (string) WordPress action hook name (e.g., 'save_post', 'wp_login').
	 *   - 'name'          (string) Human-readable listener name.
	 *   - 'callback'      (string) Method name on this agent to call.
	 *
	 * Optional:
	 *   - 'description'   (string) What this listener does.
	 *   - 'priority'      (int)    Hook priority (default 10).
	 *   - 'accepted_args' (int)    Number of arguments the callback accepts (default 1).
	 *   - 'prompt'        (string) If set, queues an async LLM task via wp_schedule_single_event
	 *                              instead of calling the callback synchronously. The hook
	 *                              arguments are JSON-serialized into the prompt context.
	 *                              Falls back to 'callback' if LLM is not configured.
	 *
	 * @return array[] Array of listener definitions.
	 */
	public function get_event_listeners(): array {
		return array();
	}

	/**
	 * Get scheduled tasks for this agent
	 *
	 * Override this to define recurring tasks the agent should perform.
	 * Each task array must include:
	 *   - 'id'       (string) Unique task identifier within this agent.
	 *   - 'name'     (string) Human-readable task name.
	 *   - 'callback' (string) Method name on this agent to call (fallback).
	 *   - 'schedule' (string) WP-Cron recurrence: 'hourly', 'twicedaily', 'daily', 'weekly'.
	 *
	 * Optional:
	 *   - 'description' (string) What this task does.
	 *   - 'prompt'      (string) If set, the task runs through the LLM autonomously
	 *                            using Agent_Controller::run_autonomous_task(). The agent
	 *                            will receive this prompt, use its tools, and produce a
	 *                            summary. Falls back to 'callback' if LLM is not configured.
	 *
	 * @return array[] Array of task definitions.
	 */
	public function get_scheduled_tasks(): array {
		return array();
	}

	/**
	 * Get the WP-Cron hook name for a scheduled task
	 *
	 * @param string $task_id Task identifier.
	 * @return string Hook name.
	 */
	public function get_cron_hook( string $task_id ): string {
		return 'agentic_task_' . $this->get_id() . '_' . $task_id;
	}

	/**
	 * Register all scheduled tasks for this agent
	 *
	 * Called when the agent is activated.
	 *
	 * @return void
	 */
	public function register_scheduled_tasks(): void {
		foreach ( $this->get_scheduled_tasks() as $task ) {
			$hook = $this->get_cron_hook( $task['id'] );

			if ( ! wp_next_scheduled( $hook ) ) {
				wp_schedule_event( time(), $task['schedule'], $hook );
			}
		}
	}

	/**
	 * Unregister all scheduled tasks for this agent
	 *
	 * Called when the agent is deactivated.
	 *
	 * @return void
	 */
	public function unregister_scheduled_tasks(): void {
		foreach ( $this->get_scheduled_tasks() as $task ) {
			$hook      = $this->get_cron_hook( $task['id'] );
			$timestamp = wp_next_scheduled( $hook );

			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, $hook );
			}
		}
	}
}
