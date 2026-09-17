<?php
/**
 * Agent Registry - Manages agent installation, activation, and lifecycle
 *
 * Similar to WordPress plugin management, this class handles:
 * - Discovering installed agents
 * - Activating/deactivating agents
 * - Loading active agents
 * - Managing the agent library
 *
 * @package    Agent_Builder
 * @subpackage Includes
 * @author     Agent Builder Team <support@agentic-plugin.com>
 * @license    GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 * @link       https://agentic-plugin.com
 * @since      0.2.0
 *
 * php version 8.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Agentic_Agent_Registry
 *
 * Central registry for all agents in the system.
 */
class Agentic_Agent_Registry {

	/**
	 * Singleton instance
	 *
	 * @var Agentic_Agent_Registry|null
	 */
	private static ?Agentic_Agent_Registry $instance = null;

	/**
	 * Directory where agents are installed
	 *
	 * @var string
	 */
	private string $agents_dir;

	/**
	 * Directory for the local agent library (available agents to install)
	 *
	 * @var string
	 */
	private string $library_dir;

	/**
	 * Cache of discovered agents
	 *
	 * @var array
	 */
	private array $agents_cache = array();

	/**
	 * Slugs in agents_dir that displace a bundled agent of the same name.
	 *
	 * However it got there, such a copy pins that bundled agent to whatever sits
	 * on disk: prompt, tool and risk-level changes from plugin updates stop
	 * reaching it, silently. The .uploaded marker records only the route in —
	 * the update checker or a zip upload, both of which now refuse a bundled
	 * slug. Populated by get_installed_agents().
	 *
	 * @var array<string, array{path: string, uploaded: bool}> Keyed by slug.
	 */
	private array $shadowed_bundled = array();

	/**
	 * Registered agent instances (Agent_Base objects)
	 *
	 * @var array<string, \Agentic\Agent_Base>
	 */
	private array $agent_instances = array();

	/**
	 * Option name for active agents
	 */
	const ACTIVE_AGENTS_OPTION = 'agentic_active_agents';

	/**
	 * Required agent header fields
	 */
	const REQUIRED_HEADERS = array(
		'Agent Name',
		'Version',
		'Description',
		'Author',
	);

	/**
	 * Get singleton instance
	 *
	 * @return Agentic_Agent_Registry
	 */
	public static function get_instance(): Agentic_Agent_Registry {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 */
	private function __construct() {
		$this->agents_dir  = AGENT_BUILDER_AGENTS_DIR;
		$this->library_dir = AGENT_BUILDER_DIR . 'library/agents';

		// Ensure directories exist.
		$this->ensure_directories();
	}

	/**
	 * Ensure required directories exist
	 */
	private function ensure_directories(): void {
		// Not guarded by file_exists( $this->agents_dir ): ensure_protected_dir()
		// already no-ops cheaply once the directory and its protection files
		// exist, and calling it unconditionally means a site upgrading from
		// before these protections existed gets them added on its very next
		// request, not only on a fresh install where the directory doesn't
		// exist yet at all.
		\Agentic\File_Manager::ensure_protected_dir( $this->agents_dir );

		if ( ! file_exists( $this->library_dir ) ) {
			wp_mkdir_p( $this->library_dir );
		}
	}

	/**
	 * Get all installed agents
	 *
	 * Includes both user-installed agents (wp-content/agents) and
	 * bundled library agents (agent-builder/library).
	 *
	 * @param bool $force_refresh Force refresh the cache.
	 * @return array
	 */
	public function get_installed_agents( bool $force_refresh = false ): array {
		if ( ! empty( $this->agents_cache ) && ! $force_refresh ) {
			return $this->agents_cache;
		}

		$agents                 = array();
		$this->shadowed_bundled = array();

		// First, load agents from wp-content/agents (user-installed).
		if ( is_dir( $this->agents_dir ) ) {
			$agent_folders = scandir( $this->agents_dir );

			foreach ( $agent_folders as $folder ) {
				if ( '.' === $folder || '..' === $folder || 'index.php' === $folder ) {
					continue;
				}

				$agent_path = $this->agents_dir . '/' . $folder;

				if ( ! is_dir( $agent_path ) ) {
					continue;
				}

				$main_file = $this->find_agent_main_file( $agent_path, $folder, true );

				if ( $main_file ) {
					$agent_data = $this->get_agent_data( $main_file );

					if ( $agent_data ) {
						$agent_data['slug']      = $folder;
						$agent_data['path']      = $main_file;
						$agent_data['directory'] = $agent_path;
						$agent_data['active']    = $this->is_agent_active( $folder );
						$agent_data['bundled']   = false;

						$agents[ $folder ] = $agent_data;
					}
				}
			}
		}

		// Then, load bundled agents from all registered library directories (skip if already installed).
		$library_dirs = apply_filters( 'agentic_library_dirs', array( $this->library_dir ) );

		foreach ( $library_dirs as $library_dir ) {
			if ( ! is_dir( $library_dir ) ) {
				continue;
			}

			$library_folders = scandir( $library_dir );

			foreach ( $library_folders as $folder ) {
				if ( '.' === $folder || '..' === $folder || 'README.md' === $folder ) {
					continue;
				}

				// Skip if already loaded from agents_dir or a previous library dir.
				if ( isset( $agents[ $folder ] ) ) {
					$shadow = $this->agents_dir . '/' . $folder;

					// Either way this bundled agent is pinned to whatever sits on
					// disk and plugin updates no longer reach it. The marker only
					// tells us how it got there: .uploaded means the update
					// checker or a zip upload put it there, which we now refuse.
					if ( is_dir( $shadow ) ) {
						$this->shadowed_bundled[ $folder ] = array(
							'path'     => $shadow,
							'uploaded' => file_exists( $shadow . '/.uploaded' ),
						);
					}

					continue;
				}

				$agent_path = $library_dir . '/' . $folder;

				if ( ! is_dir( $agent_path ) ) {
					continue;
				}

				$main_file = $this->find_agent_main_file( $agent_path, $folder );

				if ( $main_file ) {
					$agent_data = $this->get_agent_data( $main_file );

					if ( $agent_data ) {
						$agent_data['slug']      = $folder;
						$agent_data['path']      = $main_file;
						$agent_data['directory'] = $agent_path;
						$agent_data['active']    = $this->is_agent_active( $folder );
						$agent_data['bundled']   = true;

						$agents[ $folder ] = $agent_data;
					}
				}
			}
		}

		// Finally, load agents stored in the agent_builder_agent_library table.
		// These are the canonical records for bundled, purchased, and
		// user-created agents. Only manifest-kind rows are interpreted here;
		// php-kind rows are records whose executable code is loaded from the
		// reviewed library directory above (already merged into $agents).
		foreach ( \Agentic\Agent_Library::get_enabled() as $row ) {
			$slug = isset( $row['slug'] ) ? (string) $row['slug'] : '';

			if ( '' === $slug || isset( $agents[ $slug ] ) ) {
				continue;
			}

			if ( 'manifest' !== ( $row['kind'] ?? 'manifest' ) ) {
				continue;
			}

			$manifest = \Agentic\Agent_Manifest_Validator::validate(
				\Agentic\Agent_Library::manifest_of( $row )
			);

			if ( is_wp_error( $manifest ) ) {
				continue;
			}

			$agent_data                = $this->manifest_to_agent_data( $manifest );
			$agent_data['slug']        = $slug;
			$agent_data['path']        = '';
			$agent_data['directory']   = '';
			$agent_data['db']          = true;
			$agent_data['db_manifest'] = $manifest;
			$agent_data['active']      = $this->is_agent_active( $slug );
			$agent_data['bundled']     = ( 'bundled' === ( $row['source'] ?? '' ) );

			$agents[ $slug ] = $agent_data;
		}

		$this->agents_cache = $agents;

		return $agents;
	}

	/**
	 * Find the main agent file in a directory
	 *
	 * @param string $agent_path Path to agent directory.
	 * @param string $folder     Folder name.
	 * @return string|null
	 */
	private function find_agent_main_file( string $agent_path, string $folder, bool $manifest_only = false ): ?string {
		// Declarative agents ship an agent.json manifest. Agents loaded from the
		// writable user directory MUST be manifest-only — the plugin never
		// includes PHP from a location an admin/LLM can write to (WP.org
		// Guideline 8: no arbitrary code execution). PHP agents are permitted
		// only from the bundled, reviewed library/agents directory.
		if ( $manifest_only ) {
			return file_exists( $agent_path . '/agent.json' ) ? $agent_path . '/agent.json' : null;
		}

		// First check for agent.php.
		if ( file_exists( $agent_path . '/agent.php' ) ) {
			return $agent_path . '/agent.php';
		}

		// Then check for {folder-name}.php.
		if ( file_exists( $agent_path . '/' . $folder . '.php' ) ) {
			return $agent_path . '/' . $folder . '.php';
		}

		// Declarative agents ship an agent.json manifest instead of PHP.
		if ( file_exists( $agent_path . '/agent.json' ) ) {
			return $agent_path . '/agent.json';
		}

		// Look for any PHP file with agent headers. Hidden files are skipped:
		// macOS writes an AppleDouble sidecar (._agent.php) next to every file
		// on a non-native volume, and glob() happily returns it. Parsing one
		// spews its binary resource fork into the output.
		$php_files = glob( $agent_path . '/*.php' );

		foreach ( $php_files as $file ) {
			if ( str_starts_with( basename( $file ), '.' ) ) {
				continue;
			}
			$data = $this->get_agent_data( $file );
			if ( $data && ! empty( $data['name'] ) ) {
				return $file;
			}
		}

		return null;
	}

	/**
	 * Parse agent file headers (similar to get_plugin_data)
	 *
	 * @param string $file Path to agent file.
	 * @return array|null
	 */
	public function get_agent_data( string $file ): ?array {
		if ( ! file_exists( $file ) ) {
			return null;
		}

		// Declarative manifest agents carry their metadata in agent.json.
		if ( str_ends_with( $file, '.json' ) ) {
			return $this->get_manifest_agent_data( $file );
		}

		$default_headers = array(
			'name'         => 'Agent Name',
			'version'      => 'Version',
			'description'  => 'Description',
			'author'       => 'Author',
			'author_uri'   => 'Author URI',
			'agent_uri'    => 'Agent URI',
			'license'      => 'License',
			'license_uri'  => 'License URI',
			'text_domain'  => 'Text Domain',
			'requires_wp'  => 'Requires at least',
			'requires_php' => 'Requires PHP',
			'capabilities' => 'Capabilities',
			'category'     => 'Category',
			'tags'         => 'Tags',
			'icon'         => 'Icon',
		);

		$data = get_file_data( $file, $default_headers );

		// Must have at least a name.
		if ( empty( $data['name'] ) ) {
			return null;
		}

		// Parse capabilities as comma-separated list.
		if ( ! empty( $data['capabilities'] ) ) {
			$data['capabilities'] = array_map( 'trim', explode( ',', $data['capabilities'] ) );
		} else {
			$data['capabilities'] = array();
		}

		// Parse tags as comma-separated list.
		if ( ! empty( $data['tags'] ) ) {
			$data['tags'] = array_map( 'trim', explode( ',', $data['tags'] ) );
		} else {
			$data['tags'] = array();
		}

		return $data;
	}

	/**
	 * Build agent metadata from a declarative agent.json manifest.
	 *
	 * Mirrors the shape returned by get_agent_data() for PHP agents so the rest
	 * of the registry can treat manifest agents uniformly.
	 *
	 * @param string $file Path to agent.json.
	 * @return array|null
	 */
	private function get_manifest_agent_data( string $file ): ?array {
		$manifest = \Agentic\Agent_Manifest_Validator::from_file( $file );
		if ( null === $manifest ) {
			return null;
		}

		return $this->manifest_to_agent_data( $manifest );
	}

	/**
	 * Build the installed-agent data array from a validated manifest.
	 *
	 * Shared by file-backed manifest agents (agent.json) and database-backed
	 * agents (agent_builder_agent_library rows) so both present identically to the UI.
	 *
	 * @param array<string, mixed> $manifest A validated manifest.
	 * @return array<string, mixed>
	 */
	private function manifest_to_agent_data( array $manifest ): array {
		return array(
			'name'         => $manifest['name'],
			'version'      => $manifest['version'],
			'description'  => $manifest['description'],
			'author'       => $manifest['author'],
			'author_uri'   => $manifest['author_uri'] ?? '',
			'agent_uri'    => '',
			'license'      => 'GPL-2.0-or-later',
			'license_uri'  => 'https://www.gnu.org/licenses/gpl-2.0.html',
			'text_domain'  => 'agent-builder',
			'requires_wp'  => '',
			'requires_php' => '',
			'capabilities' => $manifest['capabilities'],
			'category'     => $manifest['category'],
			'tags'         => array(),
			'icon'         => $manifest['icon'],
			'manifest'     => true,
		);
	}

	/**
	 * Check if an agent is active
	 *
	 * @param string $slug Agent slug.
	 * @return bool
	 */
	public function is_agent_active( string $slug ): bool {
		$active_agents = get_option( self::ACTIVE_AGENTS_OPTION, array() );
		return in_array( $slug, $active_agents, true );
	}

	/**
	 * Get all active agents
	 *
	 * @return array
	 */
	public function get_active_agents(): array {
		return get_option( self::ACTIVE_AGENTS_OPTION, array() );
	}

	/**
	 * Activate an agent
	 *
	 * @param string $slug Agent slug.
	 * @return bool|WP_Error
	 */
	public function activate_agent( string $slug ) {
		$agents = $this->get_installed_agents();

		\Agentic\Security_Log::log_system( 'agent_activate_started', 'agents', array( 'slug' => $slug ) );

		if ( class_exists( '\Agentic\Emergency_Stop' ) && \Agentic\Emergency_Stop::is_active() ) {
			\Agentic\Security_Log::log_system(
				'agent_activate_failed',
				'agents',
				array(
					'slug'   => $slug,
					'reason' => 'emergency_stop',
				)
			);
			return new WP_Error(
				'emergency_stop',
				\Agentic\Emergency_Stop::blocked_message()
			);
		}

		if ( ! isset( $agents[ $slug ] ) ) {
			\Agentic\Security_Log::log_system(
				'agent_activate_failed',
				'agents',
				array(
					'slug'   => $slug,
					'reason' => 'agent_not_found',
				)
			);
			return new WP_Error( 'agent_not_found', __( 'Agent not found.', 'agent-builder' ) );
		}

		if ( $this->is_agent_active( $slug ) ) {
			return new WP_Error( 'already_active', __( 'Agent is already active.', 'agent-builder' ) );
		}

		$agent = $agents[ $slug ];

		// Block premium agents (those with a .requires-license marker) — this build
		// has no license path at all.
		if ( ! empty( $agent['directory'] ) && file_exists( $agent['directory'] . '/.requires-license' ) ) {
			\Agentic\Security_Log::log_system(
				'agent_activate_failed',
				'agents',
				array(
					'slug'   => $slug,
					'reason' => 'license_required',
				)
			);
			return new WP_Error(
				'license_required',
				__( 'This agent requires a premium license. Upgrade at agentic-plugin.com to activate it.', 'agent-builder' )
			);
		}

		// Check PHP version requirement.
		if ( ! empty( $agent['requires_php'] ) && version_compare( PHP_VERSION, $agent['requires_php'], '<' ) ) {
			\Agentic\Security_Log::log_system(
				'agent_activate_failed',
				'agents',
				array(
					'slug'     => $slug,
					'reason'   => 'php_version',
					'requires' => $agent['requires_php'],
					'current'  => PHP_VERSION,
				)
			);
			return new WP_Error(
				'php_version',
				/* translators: %s: minimum required PHP version */
			);
		}

		// Check WordPress version requirement.
		if ( ! empty( $agent['requires_wp'] ) && version_compare( get_bloginfo( 'version' ), $agent['requires_wp'], '<' ) ) {
			\Agentic\Security_Log::log_system(
				'agent_activate_failed',
				'agents',
				array(
					'slug'     => $slug,
					'reason'   => 'wp_version',
					'requires' => $agent['requires_wp'],
					'current'  => get_bloginfo( 'version' ),
				)
			);
			return new WP_Error(
				'wp_version',
				/* translators: %s: minimum required WordPress version */
			);
		}

		// Load the agent to check for errors.
		$result = $this->load_agent( $agent );

		if ( is_wp_error( $result ) ) {
			\Agentic\Security_Log::log_system(
				'agent_activate_failed',
				'agents',
				array(
					'slug'    => $slug,
					'reason'  => $result->get_error_code(),
					'message' => $result->get_error_message(),
				)
			);
			return $result;
		}

		// Validate abilities.json manifest.
		if ( class_exists( '\Agentic\Abilities_Manifest' ) ) {
			$instance   = $this->get_agent_instance( $slug );
			$code_tools = $instance ? $instance->get_tool_names() : array();
			$validation = \Agentic\Abilities_Manifest::validate( $slug, $code_tools );

			if ( ! $validation['valid'] ) {
				\Agentic\Security_Log::log_system(
					'agent_activate_failed',
					'agents',
					array(
						'slug'   => $slug,
						'reason' => 'manifest_invalid',
						'errors' => $validation['errors'],
					)
				);
				return new WP_Error(
					'manifest_invalid',
					sprintf(
						/* translators: %s: validation errors */
						__( 'Agent abilities.json validation failed: %s', 'agent-builder' ),
						implode( '; ', $validation['errors'] )
					),
					array(
						'errors'       => $validation['errors'],
						'warnings'     => $validation['warnings'],
						'risk_summary' => $validation['risk_summary'],
					)
				);
			}
		}

		// Call activation hook if exists.
		$activation_hook = 'agent_builder_agent_' . $slug . '_activate';
		if ( has_action( $activation_hook ) ) {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Dynamic hook name by design.
			do_action( $activation_hook );
		}

		$active_agents   = $this->get_active_agents();
		$active_agents[] = $slug;
		update_option( self::ACTIVE_AGENTS_OPTION, array_unique( $active_agents ) );

		// Clear cache.
		$this->agents_cache = array();

		// Log activation.
		if ( class_exists( 'Agentic_Audit_Log' ) ) {
			Agentic_Audit_Log::get_instance()->log(
				'agent_activated',
				sprintf( 'Agent activated: %s', $agent['name'] ),
				array(
					'slug'    => $slug,
					'version' => $agent['version'],
				)
			);
		}

		do_action( 'agent_builder_agent_activated', $slug, $agent );

		return true;
	}

	/**
	 * Deactivate an agent
	 *
	 * @param string $slug Agent slug.
	 * @return bool|WP_Error
	 */
	public function deactivate_agent( string $slug ) {
		\Agentic\Security_Log::log_system( 'agent_deactivate_started', 'agents', array( 'slug' => $slug ) );

		if ( ! $this->is_agent_active( $slug ) ) {
			return new WP_Error( 'not_active', __( 'Agent is not active.', 'agent-builder' ) );
		}

		$agents = $this->get_installed_agents( true );
		$agent  = $agents[ $slug ] ?? null;

		// Call deactivation hook if exists.
		$deactivation_hook = 'agent_builder_agent_' . $slug . '_deactivate';
		if ( has_action( $deactivation_hook ) ) {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Dynamic hook name by design.
			do_action( $deactivation_hook );
		}

		// Remove from active agents.
		$active_agents = $this->get_active_agents();
		$active_agents = array_diff( $active_agents, array( $slug ) );
		update_option( self::ACTIVE_AGENTS_OPTION, array_values( $active_agents ) );

		// Clear cache.
		$this->agents_cache = array();

		// Log deactivation.
		if ( class_exists( 'Agentic_Audit_Log' ) && $agent ) {
			Agentic_Audit_Log::get_instance()->log(
				'agent_deactivated',
				sprintf( 'Agent deactivated: %s', $agent['name'] ),
				array( 'slug' => $slug )
			);
		}

		do_action( 'agent_builder_agent_deactivated', $slug, $agent );

		return true;
	}

	/**
	 * Load a single agent
	 *
	 * @param array $agent Agent data.
	 * @return bool|WP_Error
	 */
	public function load_agent( array $agent ) {
		// Database-backed manifest agents: the validated manifest travels with
		// the agent data (no filesystem read). Interpreted via Manifest_Agent —
		// no PHP from the manifest is ever included.
		if ( ! empty( $agent['db'] ) && is_array( $agent['db_manifest'] ?? null ) ) {
			$this->register( new \Agentic\Manifest_Agent( $agent['db_manifest'], '' ) );
			return true;
		}

		if ( empty( $agent['path'] ) || ! file_exists( $agent['path'] ) ) {
			return new WP_Error( 'file_not_found', __( 'Agent file not found.', 'agent-builder' ) );
		}

		// Declarative manifest agents: interpret agent.json via the shipped
		// Manifest_Agent class — no PHP from the manifest is ever included.
		if ( str_ends_with( $agent['path'], '.json' ) ) {
			$manifest = \Agentic\Agent_Manifest_Validator::from_file( $agent['path'] );
			if ( null === $manifest ) {
				return new WP_Error( 'invalid_manifest', __( 'Agent manifest is invalid.', 'agent-builder' ) );
			}
			$this->register( new \Agentic\Manifest_Agent( $manifest, dirname( $agent['path'] ) ) );
			return true;
		}

		// Defence in depth: only ever include PHP that lives inside a bundled,
		// reviewed library directory. PHP under the writable user agents
		// directory is never executed (WP.org Guideline 8). User agents must be
		// declarative agent.json manifests, handled above.
		if ( ! $this->is_bundled_php_path( $agent['path'] ) ) {
			return new WP_Error(
				'untrusted_agent_php',
				__( 'Refusing to load PHP agent from outside the bundled library. User-created agents must use an agent.json manifest.', 'agent-builder' )
			);
		}

		// Pre-check for class name conflicts to prevent fatal errors.
		$class_name = $this->extract_class_name( $agent['path'] );
		if ( $class_name && class_exists( $class_name, false ) ) {
			// This plugin creates several Agent_Registry instances per request
			// (see load_active_agents() callers in agent-builder.php and
			// class-chat-assets.php) — a PHP agent's own file being re-"loaded"
			// from a second instance is expected, not a collision: PHP already
			// tracks included files by resolved path, so re-including the exact
			// same file here is always a safe no-op, and this instance's own
			// load_active_agents() still fires
			// do_action( 'agentic_register_agents', $this ), which is what
			// actually registers the (already-declared) class onto THIS
			// registry instance. Only warn/block when a DIFFERENT file already
			// declared this class name — a real naming collision between two
			// agents.
			$agentic_real_path         = realpath( $agent['path'] );
			$agentic_already_this_file = $agentic_real_path && in_array( $agentic_real_path, get_included_files(), true );

			if ( $agentic_already_this_file ) {
				return true;
			}

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging only when WP_DEBUG is enabled.
				error_log(
					sprintf(
						'Agentic: Skipping agent %s — class %s already declared.',
						$agent['slug'] ?? basename( $agent['path'] ),
						$class_name
					)
				);
			}
			return new WP_Error(
				'class_exists',
				sprintf(
					/* translators: %s: Class name */
					__( 'Cannot load agent: class %s is already declared by another agent.', 'agent-builder' ),
					$class_name
				)
			);
		}

		try {
			include_once $agent['path'];
			return true;
		} catch ( \Throwable $e ) {
			return new WP_Error(
				'load_error', /* translators: %s: Error message */
				sprintf( __( 'Error loading agent: %s', 'agent-builder' ), $e->getMessage() )
			);
		}
	}

	/**
	 * Whether a PHP agent file lives inside a bundled, reviewed library directory.
	 *
	 * Only bundled library directories (shipped and code-reviewed as part of the
	 * plugin) may contribute executable PHP agents. Anything under the writable
	 * user agents directory is rejected to satisfy WP.org Guideline 8.
	 *
	 * @param string $path Absolute path to the agent PHP file.
	 * @return bool
	 */
	private function is_bundled_php_path( string $path ): bool {
		$real = realpath( $path );
		if ( false === $real ) {
			return false;
		}

		$library_dirs = apply_filters( 'agentic_library_dirs', array( $this->library_dir ) );
		foreach ( $library_dirs as $library_dir ) {
			$real_dir = realpath( $library_dir );
			if ( false !== $real_dir && str_starts_with( $real, $real_dir . DIRECTORY_SEPARATOR ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Extract the main class name from an agent file without loading it
	 *
	 * Scans for "class ClassName extends" pattern in the file.
	 *
	 * @param string $file Path to agent file.
	 * @return string|null Class name or null if not found.
	 */
	private function extract_class_name( string $file ): ?string {
		// Read first 4KB — class declaration is always near the top.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local file for class name extraction.
		$contents = file_get_contents( $file, false, null, 0, 4096 );
		if ( ! $contents ) {
			return null;
		}

		if ( preg_match( '/^\s*class\s+(\w+)\s+extends\s/m', $contents, $matches ) ) {
			return $matches[1];
		}

		return null;
	}

	/**
	 * Load all active agents
	 *
	 * Called during WordPress init to load all activated agents.
	 */
	public function load_active_agents(): void {
		$active_slugs = $this->get_active_agents();

		if ( empty( $active_slugs ) ) {
			// Still init agent instances for base functionality.
			$this->init_agent_instances();
			return;
		}

		// Include base class first.
		require_once AGENT_BUILDER_DIR . 'includes/class-agent-base.php';

		$installed = $this->get_installed_agents();

		foreach ( $active_slugs as $slug ) {
			if ( isset( $installed[ $slug ] ) ) {
				$result = $this->load_agent( $installed[ $slug ] );

				if ( is_wp_error( $result ) ) {
					// Log error but continue loading other agents.
					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging only when WP_DEBUG is enabled.
						error_log(
							sprintf(
								'Agentic: Failed to load agent %s: %s',
								$slug,
								$result->get_error_message()
							)
						);
					}
				}
			}
		}

		// Allow agents to register their instances.
		do_action( 'agentic_register_agents', $this );

		do_action( 'agentic_agents_loaded' );
	}

	/**
	 * Get agents from the library (available to install)
	 *
	 * @param array $args Search/filter arguments.
	 * @return array
	 */
	public function get_library_agents( array $args = array() ): array {
		$defaults = array(
			'search'   => '',
			'category' => '',
			'page'     => 1,
			'per_page' => 12,
		);

		$args   = wp_parse_args( $args, $defaults );
		$agents = array();

		if ( ! is_dir( $this->library_dir ) ) {
			return array(
				'agents' => array(),
				'total'  => 0,
			);
		}

		$library_folders = scandir( $this->library_dir );

		foreach ( $library_folders as $folder ) {
			if ( '.' === $folder || '..' === $folder ) {
				continue;
			}

			$agent_path = $this->library_dir . '/' . $folder;

			if ( ! is_dir( $agent_path ) ) {
				continue;
			}

			$main_file = $this->find_agent_main_file( $agent_path, $folder );

			if ( $main_file ) {
				$agent_data = $this->get_agent_data( $main_file );

				if ( $agent_data ) {
					$agent_data['slug']         = $folder;
					$agent_data['library_path'] = $agent_path;
					$agent_data['installed']    = $this->is_agent_installed( $folder ) || $this->is_agent_active( $folder );

					// Apply search filter.
					if ( ! empty( $args['search'] ) ) {
						$search = strtolower( $args['search'] );
						$match  = str_contains( strtolower( $agent_data['name'] ), $search )
									|| str_contains( strtolower( $agent_data['description'] ), $search );

						if ( ! $match ) {
							continue;
						}
					}

					// Apply category filter.
					if ( ! empty( $args['category'] ) && ! empty( $agent_data['category'] ) ) {
						if ( strtolower( $agent_data['category'] ) !== strtolower( $args['category'] ) ) {
							continue;
						}
					}

					$agents[ $folder ] = $agent_data;
				}
			}
		}

		$total = count( $agents );

		// Pagination.
		$offset = ( $args['page'] - 1 ) * $args['per_page'];
		$agents = array_slice( $agents, $offset, $args['per_page'], true );

		return array(
			'agents' => $agents,
			'total'  => $total,
			'pages'  => ceil( $total / $args['per_page'] ),
		);
	}

	/**
	 * Check if an agent is installed
	 *
	 * Bundled agents count as installed: they load in place from whichever
	 * library directory ships them. Checking only the free library made every
	 * Pro-bundled agent report "not installed".
	 *
	 * @param string $slug Agent slug.
	 * @return bool
	 */
	public function is_agent_installed( string $slug ): bool {
		if ( is_dir( $this->agents_dir . '/' . $slug ) ) {
			return true;
		}

		return '' !== $this->find_in_library_dirs( $slug );
	}

	/**
	 * Locate a slug in any registered bundled-agent library.
	 *
	 * @param string $slug Agent slug.
	 * @return string Absolute path to the agent directory, or '' when not bundled.
	 */
	private function find_in_library_dirs( string $slug ): string {
		if ( '' === $slug ) {
			return '';
		}

		/** This filter is documented in includes/class-agent-registry.php */
		$library_dirs = apply_filters( 'agentic_library_dirs', array( $this->library_dir ) );

		foreach ( (array) $library_dirs as $library_dir ) {
			$path = rtrim( (string) $library_dir, '/' ) . '/' . $slug;

			if ( is_dir( $path ) ) {
				return $path;
			}
		}

		return '';
	}

	/**
	 * Whether a slug is shipped by a plugin as a bundled agent.
	 *
	 * @param string $slug Agent slug.
	 * @return bool
	 */
	public function is_bundled_slug( string $slug ): bool {
		return '' !== $this->find_in_library_dirs( $slug );
	}

	/**
	 * Bundled agents that a directory in agents_dir is silently shadowing.
	 *
	 * These never receive prompt, tool, or risk-level changes from a plugin
	 * update, because the registry loads agents_dir before any library. We do
	 * not touch the files — agents_dir belongs to the site owner — we surface
	 * them so a human can decide.
	 *
	 * @return array<string, array{path: string, uploaded: bool}> Keyed by slug.
	 */
	public function get_shadowed_bundled_agents(): array {
		if ( empty( $this->agents_cache ) ) {
			$this->get_installed_agents();
		}

		return $this->shadowed_bundled;
	}

	/**
	 * Delete an installed agent
	 *
	 * @param string $slug Agent slug.
	 * @return bool|WP_Error
	 */
	public function delete_agent( string $slug ) {
		\Agentic\Security_Log::log_system( 'agent_delete_started', 'agents', array( 'slug' => $slug ) );

		if ( ! $this->is_agent_installed( $slug ) ) {
			return new WP_Error( 'not_installed', __( 'Agent is not installed.', 'agent-builder' ) );
		}

		// Deactivate first if active.
		if ( $this->is_agent_active( $slug ) ) {
			$this->deactivate_agent( $slug );
		}

		$agents = $this->get_installed_agents();
		$agent  = $agents[ $slug ] ?? null;

		// Use the actual directory stored on the agent record (covers both agents_dir and library_dir).
		$agent_path = $agent['directory'] ?? ( $this->agents_dir . '/' . $slug );

		// Prevent deleting a bundled library agent — its files live inside a
		// plugin directory, so removing them would vandalise the installation.
		if ( $this->is_bundled_agent( $agent_path ) ) {
			return new \WP_Error( 'delete_bundled', __( 'Bundled library agents cannot be deleted.', 'agent-builder' ) );
		}

		// Call uninstall hook if exists.
		$uninstall_hook = 'agent_builder_agent_' . $slug . '_uninstall';
		if ( has_action( $uninstall_hook ) ) {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Dynamic hook name by design.
			do_action( $uninstall_hook );
		}

		$result = $this->delete_directory( $agent_path );

		if ( ! $result ) {
			\Agentic\Security_Log::log_system(
				'agent_delete_failed',
				'agents',
				array(
					'slug'   => $slug,
					'reason' => 'delete_failed',
					'path'   => $agent_path,
				)
			);
			return new WP_Error( 'delete_failed', __( 'Failed to delete agent files.', 'agent-builder' ) );
		}

		// Clear cache.
		$this->agents_cache = array();

		// Log deletion.
		if ( class_exists( 'Agentic_Audit_Log' ) && $agent ) {
			Agentic_Audit_Log::get_instance()->log(
				'agent_deleted',
				sprintf( 'Agent deleted: %s', $agent['name'] ),
				array( 'slug' => $slug )
			);
		}

		do_action( 'agent_builder_agent_deleted', $slug );

		return true;
	}

	/**
	 * Delete a directory recursively
	 *
	 * @param string $dir Directory path.
	 * @return bool
	 */
	private function delete_directory( string $dir ): bool {
		return \Agentic\File_Manager::rmdir( $dir, true );
	}

	/**
	 * Get agent categories from library
	 *
	 * @return array
	 */
	public function get_agent_categories(): array {
		$library    = $this->get_library_agents( array( 'per_page' => 1000 ) );
		$categories = array();

		foreach ( $library['agents'] as $agent ) {
			if ( ! empty( $agent['category'] ) ) {
				$cat = $agent['category'];
				if ( ! isset( $categories[ $cat ] ) ) {
					$categories[ $cat ] = 0;
				}
				++$categories[ $cat ];
			}
		}

		return $categories;
	}

	/**
	 * Get all agent tags from library
	 *
	 * @return array Associative array of tag => count
	 */
	public function get_agent_tags(): array {
		$library = $this->get_library_agents( array( 'per_page' => 1000 ) );
		$tags    = array();

		foreach ( $library['agents'] as $agent ) {
			if ( ! empty( $agent['tags'] ) && is_array( $agent['tags'] ) ) {
				foreach ( $agent['tags'] as $tag ) {
					$tag = strtolower( trim( $tag ) );
					if ( ! isset( $tags[ $tag ] ) ) {
						$tags[ $tag ] = 0;
					}
					++$tags[ $tag ];
				}
			}
		}

		arsort( $tags );
		return $tags;
	}

	/**
	 * Get agents directory path
	 *
	 * @return string
	 */
	public function get_agents_dir(): string {
		return $this->agents_dir;
	}

	/**
	 * Whether an agent's files live inside a bundled library directory.
	 *
	 * Bundled agents ship inside a plugin (this one, or Pro, or any add-on that
	 * hooks `agentic_library_dirs`). Deleting one would remove files from the
	 * plugin's own directory, and the agent would reappear on the next update
	 * anyway. Agents the user installed live under agents_dir and are freely
	 * removable.
	 *
	 * Every registered library dir is consulted, not just this plugin's — Pro
	 * registers its own, and checking only ours left all eight of its bundled
	 * agents deletable.
	 *
	 * An explicit `.uploaded` marker means the user put the agent there
	 * themselves, so it stays deletable wherever it lives.
	 *
	 * @param string $agent_path Absolute path to the agent's directory.
	 * @return bool
	 */
	private function is_bundled_agent( string $agent_path ): bool {
		if ( '' === $agent_path || file_exists( $agent_path . '/.uploaded' ) ) {
			return false;
		}

		$real = realpath( $agent_path );
		if ( ! $real ) {
			return false;
		}

		/** This filter is documented in includes/class-agent-registry.php */
		$library_dirs = apply_filters( 'agentic_library_dirs', array( $this->library_dir ) );

		foreach ( (array) $library_dirs as $library_dir ) {
			$real_library = realpath( $library_dir );
			if ( $real_library && str_starts_with( $real, trailingslashit( $real_library ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get library directory path
	 *
	 * @return string
	 */
	public function get_library_dir(): string {
		return $this->library_dir;
	}

	/**
	 * Register an agent instance
	 *
	 * Agents call this via the 'agentic_register_agents' hook.
	 *
	 * @param \Agentic\Agent_Base $agent Agent instance.
	 * @return bool Whether registration succeeded.
	 */
	public function register( \Agentic\Agent_Base $agent ): bool {
		$id = $agent->get_id();

		if ( isset( $this->agent_instances[ $id ] ) ) {
			return false; // Already registered.
		}

		$this->agent_instances[ $id ] = $agent;

		do_action( 'agent_builder_agent_instance_registered', $id, $agent );

		return true;
	}

	/**
	 * Get a registered agent instance by ID
	 *
	 * @param string $agent_id Agent ID.
	 * @return \Agentic\Agent_Base|null Agent instance or null.
	 */
	public function get_agent_instance( string $agent_id ): ?\Agentic\Agent_Base {
		return $this->agent_instances[ $agent_id ] ?? null;
	}

	/**
	 * Get all registered agent instances
	 *
	 * @return array<string, \Agentic\Agent_Base> All agent instances.
	 */
	public function get_all_instances(): array {
		return $this->agent_instances;
	}

	/**
	 * Get agent instances accessible by current user
	 *
	 * @return array<string, \Agentic\Agent_Base> Accessible agents.
	 */
	public function get_accessible_instances(): array {
		return array_filter(
			$this->agent_instances,
			fn( \Agentic\Agent_Base $agent ) => $agent->current_user_can_access()
		);
	}

	/**
	 * Load Agent_Base class and trigger agent registration
	 *
	 * Called after active agents are loaded.
	 */
	public function init_agent_instances(): void {
		// Include base class.
		require_once AGENT_BUILDER_DIR . 'includes/class-agent-base.php';

		// Allow agents to register themselves.
		do_action( 'agentic_register_agents', $this );
	}
}
