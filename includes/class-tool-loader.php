<?php
/**
 * Tool Loader
 *
 * Discovers, loads, and manages standalone tools from the tools/ directory.
 * Each tool lives in tools/<tool-name>/tool.php and extends Tool_Base.
 *
 * @package    Agent_Builder
 * @subpackage Tools
 * @since      2.0.0
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads standalone tools from the filesystem and provides them to agents.
 */
class Tool_Loader {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Loaded tool instances keyed by name.
	 *
	 * @var array<string, Tool_Base>
	 */
	private array $tools = array();

	/**
	 * Whether tools have been loaded.
	 *
	 * @var bool
	 */
	private bool $loaded = false;

	/**
	 * Get singleton instance.
	 *
	 * @return self
	 */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Scan the tools/ directory and load all tool classes.
	 *
	 * Each tool directory must contain a tool.php that defines a class extending Tool_Base.
	 * The class is instantiated and registered by its get_name() return value.
	 *
	 * Tool paths are cached in a transient so the glob() filesystem scan only runs
	 * once per process across page loads (cleared by sync_to_registry()).
	 *
	 * @return void
	 */
	public function load(): void {
		if ( $this->loaded ) {
			return;
		}

		// Agent Builder, Pro, and any add-on register their reviewed tool
		// libraries on this filter (Pro prepends its own), mirroring
		// agentic_library_dirs for agents. Each directory holds one subdir per
		// tool, containing tool.php that returns a Tool_Base instance.
		$tool_dirs = apply_filters( 'agentic_tool_dirs', array( AGENT_BUILDER_DIR . 'library/tools/' ) );

		// Use a transient to cache the list of tool paths across requests.
		// Version-keyed so an update that adds, moves or removes a tool never
		// serves a stale path list for the rest of the cache lifetime.
		$cache_key  = 'agentic_tool_paths_' . AGENT_BUILDER_VERSION;
		$tool_files = get_transient( $cache_key );

		if ( false === $tool_files ) {
			$tool_files = array();
			foreach ( (array) $tool_dirs as $tools_dir ) {
				$tools_dir = trailingslashit( (string) $tools_dir );
				if ( ! is_dir( $tools_dir ) ) {
					continue;
				}
				$dirs = glob( $tools_dir . '*', GLOB_ONLYDIR );
				if ( $dirs ) {
					foreach ( $dirs as $dir ) {
						$tool_file = $dir . '/tool.php';
						if ( file_exists( $tool_file ) ) {
							$tool_files[] = $tool_file;
						}
					}
				}
			}
			set_transient( $cache_key, $tool_files, DAY_IN_SECONDS );
		}

		// A tool slug can appear in more than one registered directory: a Pro-
		// installed tool in the managed dir overriding a bundled copy, or a Pro
		// override of a free tool. Directories are in precedence order, so keep
		// the first file seen for each slug and drop the rest — including a second
		// file that declares the same class would fatal ("Cannot redeclare class").
		$seen_slugs   = array();
		$unique_files = array();
		foreach ( $tool_files as $tf ) {
			$slug = basename( dirname( (string) $tf ) );
			if ( isset( $seen_slugs[ $slug ] ) ) {
				continue;
			}
			$seen_slugs[ $slug ] = true;
			$unique_files[]      = $tf;
		}
		$tool_files = $unique_files;

		foreach ( $tool_files as $tool_file ) {
			// A cached path can outlive its file (tool removed, plugin swapped);
			// skip it rather than emit an include warning on every request.
			if ( ! is_readable( $tool_file ) ) {
				continue;
			}

			// include_once prevents fatal "Cannot redeclare class" if load() is
			// called more than once in a request (e.g. after sync_to_registry reset).
			$tool_instance = include_once $tool_file;

			if ( $tool_instance instanceof Tool_Base ) {
				// Pro-only tools (e.g. a future licensed add-on's own tool files)
				// never load here — this build has no license path at all.
				if ( $tool_instance->is_pro_only() ) {
					continue;
				}
				$this->tools[ $tool_instance->get_name() ] = $tool_instance;
			}
		}

		$this->loaded = true;
	}

	/**
	 * Get a tool instance by name.
	 *
	 * @param string $name Tool name.
	 * @return Tool_Base|null
	 */
	public function get( string $name ): ?Tool_Base {
		$this->load();
		return $this->tools[ $name ] ?? null;
	}

	/**
	 * Get all loaded tool instances, including any that cannot run here.
	 *
	 * Admin surfaces use this so an unavailable tool can still be listed with
	 * an explanation. Agent-facing code should use get_available().
	 *
	 * @return array<string, Tool_Base>
	 */
	public function get_all(): array {
		$this->load();
		return $this->tools;
	}

	/**
	 * Get only the tools that can actually run on this installation.
	 *
	 * @return array<string, Tool_Base>
	 */
	public function get_available(): array {
		$this->load();
		return array_filter(
			$this->tools,
			static function ( Tool_Base $tool ): bool {
				return $tool->is_available();
			}
		);
	}

	/**
	 * Get tool definitions for a specific list of tool names.
	 *
	 * Used by agents to get only the tools they've declared. Tools whose
	 * optional library is missing are omitted, so an agent is never handed a
	 * capability this site cannot perform.
	 *
	 * @param string[] $tool_names Array of tool name strings.
	 * @return array OpenAI function-calling definitions.
	 */
	public function get_definitions_for( array $tool_names ): array {
		$this->load();
		$disabled = Tools_Registry::get_disabled_names();
		$defs     = array();

		foreach ( $tool_names as $name ) {
			if ( in_array( $name, $disabled, true ) ) {
				continue;
			}
			if ( isset( $this->tools[ $name ] ) && $this->tools[ $name ]->is_available() ) {
				$defs[] = $this->tools[ $name ]->get_definition();
			}
		}

		return $defs;
	}

	/**
	 * Execute a tool by name.
	 *
	 * @param string $name      Tool name.
	 * @param array  $arguments Tool arguments.
	 * @return array|null Result or null if tool not found.
	 */
	public function execute( string $name, array $arguments ): ?array {
		$this->load();

		if ( ! isset( $this->tools[ $name ] ) ) {
			return null;
		}

		if ( ! $this->tools[ $name ]->is_available() ) {
			return array( 'error' => $this->tools[ $name ]->get_unavailable_reason() );
		}

		try {
			return $this->tools[ $name ]->execute( $arguments );
		} catch ( \Throwable $e ) {
			\Agentic\Security_Log::log_system(
				'tool_execution_exception',
				'tools',
				array(
					'tool'  => $name,
					'error' => $e->getMessage(),
					'file'  => $e->getFile() . ':' . $e->getLine(),
				)
			);
			return array( 'error' => sprintf( 'Tool "%s" encountered an unexpected error.', $name ) );
		}
	}

	/**
	 * Get all tool definitions (for admin/registry sync).
	 *
	 * @return array OpenAI function-calling definitions for all loaded tools.
	 */
	public function get_all_definitions(): array {
		$this->load();
		$defs = array();
		foreach ( $this->tools as $tool ) {
			$defs[] = $tool->get_definition();
		}
		return $defs;
	}

	/**
	 * Sync all loaded tools to the Tools_Registry database table.
	 *
	 * Called on plugin load and from admin tools page.
	 *
	 * @return void
	 */
	public function sync_to_registry(): void {
		// Bust the cached path list and reset the in-process loaded flag so the
		// glob() re-scan runs immediately in this request (not just the next one).
		delete_transient( 'agentic_tool_paths_' . AGENT_BUILDER_VERSION );
		$this->loaded = false;
		$this->load();

		foreach ( $this->tools as $tool ) {
			$tool_name = $tool->get_name();
			Tools_Registry::register(
				array(
					'name'        => $tool_name,
					'description' => $tool->get_description(),
					'category'    => $tool->get_category(),
					'source'      => 'tool',
					'enabled'     => ! Tools_Registry::is_disabled_by_default( $tool_name ),
					'risk_level'  => $tool->get_risk_level(),
					'parameters'  => $tool->get_parameters()['properties'] ?? array(),
				)
			);
		}
	}

	/**
	 * Reset the loader (for testing).
	 *
	 * @return void
	 */
	public function reset(): void {
		$this->tools  = array();
		$this->loaded = false;
	}
}
