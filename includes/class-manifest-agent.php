<?php
/**
 * Manifest Agent — a declarative, data-driven agent.
 *
 * Agents created at runtime (e.g. by the Assistant Trainer) are stored as a
 * declarative `agent.json` manifest rather than executable PHP. This single,
 * shipped-and-reviewed class interprets such a manifest at load time, so the
 * plugin never writes or includes LLM-generated PHP code. All behaviour comes
 * from this class plus the separately risk-gated tools declared in the agent's
 * abilities.json.
 *
 * @package    Agent_Builder
 * @subpackage Includes
 * @author     AgentStructor Team <support@agentic-plugin.com>
 * @license    GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 * @link       https://agentstructor.com
 * @since      2.10.0
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runtime interpreter for declarative `agent.json` manifests.
 *
 * Unlike bundled agents (which are reviewed PHP subclasses of Agent_Base),
 * a Manifest_Agent carries its identity in a plain-data manifest array and
 * never contains executable logic of its own.
 */
final class Manifest_Agent extends Agent_Base {

	/**
	 * The validated, sanitized manifest data.
	 *
	 * @var array<string, mixed>
	 */
	private array $manifest;

	/**
	 * Absolute path to the agent's directory (holds templates/system-prompt.txt).
	 *
	 * @var string
	 */
	private string $directory;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $manifest  Validated manifest data.
	 * @param string               $directory Absolute path to the agent directory.
	 */
	public function __construct( array $manifest, string $directory ) {
		$this->manifest  = $manifest;
		$this->directory = rtrim( $directory, '/' );
	}

	/**
	 * Agent slug / unique identifier.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return (string) ( $this->manifest['slug'] ?? '' );
	}

	/**
	 * Display name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return (string) ( $this->manifest['name'] ?? '' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return (string) ( $this->manifest['description'] ?? '' );
	}

	/**
	 * Icon (emoji or dashicon).
	 *
	 * @return string
	 */
	public function get_icon(): string {
		$icon = (string) ( $this->manifest['icon'] ?? '' );
		return '' !== $icon ? $icon : '🤖';
	}

	/**
	 * Category.
	 *
	 * @return string
	 */
	public function get_category(): string {
		$category = (string) ( $this->manifest['category'] ?? '' );
		return '' !== $category ? $category : 'admin';
	}

	/**
	 * Required WordPress capabilities.
	 *
	 * @return string[]
	 */
	public function get_required_capabilities(): array {
		$caps = $this->manifest['capabilities'] ?? array();
		if ( ! is_array( $caps ) || empty( $caps ) ) {
			return array( 'read' );
		}
		return array_values( array_filter( array_map( 'strval', $caps ) ) );
	}

	/**
	 * Version.
	 *
	 * @return string
	 */
	public function get_version(): string {
		$version = (string) ( $this->manifest['version'] ?? '' );
		return '' !== $version ? $version : '1.0.0';
	}

	/**
	 * Author.
	 *
	 * @return string
	 */
	public function get_author(): string {
		$author = (string) ( $this->manifest['author'] ?? '' );
		return '' !== $author ? $author : __( 'Assistant Trainer', 'agentstructor' );
	}

	/**
	 * Author URI.
	 *
	 * @return string
	 */
	public function get_author_uri(): string {
		return (string) ( $this->manifest['author_uri'] ?? '' );
	}

	/**
	 * System prompt, loaded from templates/system-prompt.txt in the agent dir.
	 *
	 * @return string
	 */
	public function get_system_prompt(): string {
		$file    = $this->directory . '/templates/system-prompt.txt';
		$content = File_Manager::get_contents( $file );
		if ( false !== $content && '' !== $content ) {
			return $content;
		}
		return (string) ( $this->manifest['system_prompt'] ?? '' );
	}

	/**
	 * Tool names this agent may call.
	 *
	 * A declarative manifest carries its tool list inline (validated to a list
	 * of tool-name slugs). This is essential for database-backed agents, which
	 * have no directory and therefore no on-disk abilities.json for the parent
	 * implementation to read. When the manifest declares no tools we fall back
	 * to the on-disk abilities.json lookup for backwards compatibility with
	 * trainer-created agents that ship one alongside their agent.json.
	 *
	 * @return string[]
	 */
	public function get_tool_names(): array {
		$tools = $this->manifest['tools'] ?? array();
		if ( is_array( $tools ) && ! empty( $tools ) ) {
			$tools = $this->with_global_tools( array_values( array_filter( array_map( 'strval', $tools ) ) ) );
			return apply_filters( 'agentic_agent_tool_names', $tools, $this->get_id() );
		}
		return parent::get_tool_names();
	}

	/**
	 * Default welcome message.
	 *
	 * @return string
	 */
	protected function get_default_welcome_message(): string {
		$welcome = (string) ( $this->manifest['welcome_message'] ?? '' );
		if ( '' !== $welcome ) {
			return $welcome;
		}
		return parent::get_default_welcome_message();
	}

	/**
	 * Default suggested prompts.
	 *
	 * @return array<int, string>
	 */
	protected function get_default_suggested_prompts(): array {
		$prompts = $this->manifest['suggested_prompts'] ?? array();
		if ( ! is_array( $prompts ) ) {
			return array();
		}
		return array_slice( array_values( array_filter( array_map( 'strval', $prompts ) ) ), 0, 4 );
	}

	/**
	 * Scheduled (WP-Cron) tasks declared by the manifest.
	 *
	 * The manifest carries these as validated plain data; the shared
	 * Agent_Lifecycle dispatcher registers and runs them exactly as it does for
	 * bundled PHP agents. Every action they trigger is still gated by the same
	 * tool permission + risk enforcement as a chat turn.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_scheduled_tasks(): array {
		$tasks = $this->manifest['scheduled_tasks'] ?? array();
		return is_array( $tasks ) ? $tasks : array();
	}

	/**
	 * Event listeners (WordPress action hooks) declared by the manifest.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_event_listeners(): array {
		$listeners = $this->manifest['event_listeners'] ?? array();
		return is_array( $listeners ) ? $listeners : array();
	}

	/**
	 * Manifest agents are tool-driven assistants.
	 *
	 * @return bool
	 */
	public function requires_tool_use(): bool {
		return true;
	}

	/**
	 * Whether this manifest agent is a team lead that can delegate to others.
	 *
	 * Reads the "team" boolean from the manifest.
	 *
	 * @return bool
	 */
	public function is_team_lead(): bool {
		return ! empty( $this->manifest['team'] );
	}
}
