<?php
/**
 * Plugin activation handler.
 *
 * Everything that runs on register_activation_hook.
 *
 * @package Agent_Builder
 * @since   2.3.0
 */

declare(strict_types=1);

namespace Agentic;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Activator
 *
 * @since 2.3.0
 */
final class Activator {

	/**
	 * Structured log collected throughout activation.
	 * Written to the audit log and to agentic_last_activation_log at the end.
	 *
	 * @var array<int, array{step: string, status: string, details: mixed}>
	 */
	private static array $activation_log = array();

	/**
	 * Append one step to the in-memory activation log.
	 * Errors are also sent to error_log() immediately for PHP debug log visibility.
	 *
	 * @param string $step    Short identifier for the step, e.g. 'create_table'.
	 * @param string $status  'ok', 'skipped', 'warning', or 'error'.
	 * @param mixed  $details Any serialisable value (string, array, etc.).
	 */
	private static function record( string $step, string $status, mixed $details = null ): void {
		self::$activation_log[] = array(
			'step'    => $step,
			'status'  => $status,
			'details' => $details,
			'time'    => gmdate( 'Y-m-d H:i:s' ),
		);

		if ( in_array( $status, array( 'error', 'warning' ), true ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional activation debug output.
			error_log( '[Agent Builder activation] ' . $step . ' [' . $status . ']: ' . ( is_string( $details ) ? $details : wp_json_encode( $details ) ) );
		}
	}

	/**
	 * Run all activation tasks.
	 *
	 * @param string $schema_version Current DB_SCHEMA_VERSION from Plugin class.
	 * @return void
	 */
	public static function activate( string $schema_version ): void {
		// Reset collector so repeated activations don't accumulate across requests.
		self::$activation_log = array();

		self::record(
			'activate_start',
			'ok',
			array(
				'schema_version' => $schema_version,
				'wp_version'     => get_bloginfo( 'version' ),
				'php_version'    => PHP_VERSION,
				'fresh_install'  => ( false === get_option( 'agentic_db_schema_version' ) ),
			)
		);

		$fresh_install = ( false === get_option( 'agentic_db_schema_version' ) );

		self::set_flags();
		self::create_tables();  // Security-log table is created here — safe to log after this point.
		self::set_default_options();
		self::import_agents_dir();
		self::activate_bundled_agents();
		self::seed_bundled_agents();
		self::seed_tools( $schema_version );
		self::seed_skills( $schema_version );
		// Demo Knowledge Wiki concepts (example: true — hidden from agents).
		if ( class_exists( __NAMESPACE__ . '\\Okf_Store' ) ) {
			$okf_seeded = Okf_Store::seed_examples();
			self::record( 'seed_okf_examples', 'ok', array( 'wrote' => $okf_seeded ) );
		}
		self::schedule_cron_events();
		self::run_migrations();

		flush_rewrite_rules();

		// Mark schema as current so migrations don't run redundantly.
		update_option( 'agentic_db_schema_version', $schema_version );

		// Persist the full activation log to an option (readable even if tables failed).
		update_option(
			'agentic_last_activation_log',
			array(
				'schema_version' => $schema_version,
				'activated_at'   => gmdate( 'Y-m-d H:i:s' ),
				'steps'          => self::$activation_log,
			)
		);

		// Security log is now available — record the activation event.
		\Agentic\Security_Log::log_system(
			'plugin_activated',
			'agent-builder',
			array(
				'version'        => AGENT_BUILDER_VERSION,
				'schema_version' => $schema_version,
				'fresh_install'  => $fresh_install,
				'wp_version'     => get_bloginfo( 'version' ),
				'php_version'    => PHP_VERSION,
			)
		);
	}

	/**
	 * Run activation tasks only when the stored schema version is behind.
	 *
	 * Called on every request via Plugin::init() so post-update schema changes
	 * are applied without requiring a manual re-activation.
	 *
	 * @param string $schema_version Current DB_SCHEMA_VERSION from Plugin class.
	 * @return void
	 */
	public static function maybe_upgrade( string $schema_version ): void {
		$current = get_option( 'agentic_db_schema_version', '0.0.0' );

		if ( version_compare( $current, $schema_version, '>=' ) ) {
			return;
		}

		$from_version = $current;

		// Log that the upgrade is beginning before running activate(), which
		// can fail mid-way (e.g. dbDelta error). If support sees only this entry
		// with no plugin_upgraded entry after it, they know activate() failed.
		\Agentic\Security_Log::log_system(
			'plugin_upgrade_started',
			'agent-builder',
			array(
				'from_version'   => $from_version,
				'to_version'     => AGENT_BUILDER_VERSION,
				'schema_version' => $schema_version,
			)
		);

		// Re-run the activator to ensure all tables exist with the latest schema.
		self::activate( $schema_version );

		// Log the upgrade separately so support can identify version transitions.
		\Agentic\Security_Log::log_system(
			'plugin_upgraded',
			'agent-builder',
			array(
				'from_version'   => $from_version,
				'to_version'     => AGENT_BUILDER_VERSION,
				'schema_version' => $schema_version,
			)
		);
	}

	/**
	 * Set one-time activation flags.
	 *
	 * @return void
	 */
	private static function set_flags(): void {
		// Welcome admin notice.
		add_option( 'agentic_show_welcome_notice', true );

		self::record(
			'set_flags',
			'ok',
			array(
				'agentic_show_welcome_notice' => 'added',
			)
		);
	}

	/**
	 * Set default plugin options (only if they don't already exist).
	 *
	 * @return void
	 */
	private static function set_default_options(): void {
		$results = array();

		// Read the default model from the provider table (single source of truth).
		$agentic_provider = \Agentic\Provider_Registry::get( 'agentic' );
		$default_model    = $agentic_provider['default_model'] ?? 'gemini-2.5-flash';

		$defaults = array(
			'agentic_agent_mode'              => 'supervised',
			'agentic_audit_enabled'           => true,
			'agentic_llm_provider'            => 'agentic',
			'agentic_model'                   => $default_model,
			// Default chat chrome for new installs (admin + frontend shortcode).
			'agentic_chat_theme'              => 'light',
			// GDPR: default retention to 30 days so data is not kept indefinitely on fresh installs.
			'agentic_chat_tts'                => '1',
			'agentic_chat_whitelabel'         => '1',
			'agentic_show_whatsapp_cta'       => '0',
			'agentic_allow_platform_sync'     => '0',
			'agentic_retention_conversations' => 30,
			'agentic_retention_audit_log'     => 30,
			\Agentic\Usage_Limits::OPTION_KEY => \Agentic\Usage_Limits::get_install_defaults(),
			// Gutenberg sidebar: enabled by default so new installs have it working out of the box.
			'agentic_editor_sidebar_settings' => array(
				'enabled'         => '1',
				'agent_slug'      => 'content-writer',
				'agent_slugs'     => array( 'content-writer', 'seo-optimizer', 'wordpress-assistant' ),
				'post_types'      => array( 'post', 'page' ),
				'inject_context'  => '1',
				'agent_mode'      => 'autonomous',
				'toolbar_enabled' => '1',
			),
		);

		// Options that are large or only read on specific admin/editor screens
		// are not autoloaded on every request (performance).
		$no_autoload = array(
			'agentic_editor_sidebar_settings',
			'agentic_retention_conversations',
			'agentic_retention_audit_log',
			\Agentic\Usage_Limits::OPTION_KEY,
		);

		foreach ( $defaults as $key => $value ) {
			$autoload        = in_array( $key, $no_autoload, true ) ? 'no' : 'yes';
			$results[ $key ] = add_option( $key, $value, '', $autoload ) ? 'added' : 'already_exists';
		}

		self::record( 'set_default_options', 'ok', $results );
	}

	/**
	 * Activate all bundled library agents.
	 *
	 * Scans the library directory and ensures every bundled agent
	 * is present in the agentic_active_agents option.
	 *
	 * @return void
	 */
	private static function activate_bundled_agents(): void {
		$library_dirs  = apply_filters( 'agentic_library_dirs', array( AGENT_BUILDER_DIR . 'library/agents' ) );
		$bundled_slugs = array();

		foreach ( $library_dirs as $library_dir ) {
			if ( ! is_dir( $library_dir ) ) {
				self::record( 'activate_bundled_agents', 'warning', 'library directory not found: ' . $library_dir );
				continue;
			}

			$folders = scandir( $library_dir );

			if ( ! is_array( $folders ) ) {
				self::record( 'activate_bundled_agents', 'error', 'scandir() failed on library directory: ' . $library_dir );
				continue;
			}

			foreach ( $folders as $folder ) {
				if ( '.' === $folder || '..' === $folder || 'README.md' === $folder ) {
					continue;
				}

				$agent_path = $library_dir . '/' . $folder;

				// Must be a directory with an agent.php file.
				if ( is_dir( $agent_path ) && ( file_exists( $agent_path . '/agent.php' ) || file_exists( $agent_path . '/agent.json' ) ) ) {
					$bundled_slugs[] = $folder;
				}
			}
		}

		$bundled_slugs = array_unique( $bundled_slugs );

		if ( empty( $bundled_slugs ) ) {
			self::record( 'activate_bundled_agents', 'warning', 'no agent directories found in library' );
			return;
		}

		$active_agents = get_option( 'agentic_active_agents', array() );
		$merged        = array_unique( array_merge( $active_agents, $bundled_slugs ) );
		$newly_added   = array_diff( $bundled_slugs, $active_agents );

		update_option( 'agentic_active_agents', array_values( $merged ) );

		// Generate abilities.json integrity hashes for bundled agents.
		include_once AGENT_BUILDER_DIR . 'includes/class-abilities-manifest.php';
		$hash_results = array();
		foreach ( $bundled_slugs as $slug ) {
			$saved                 = Abilities_Manifest::save_integrity_hash( $slug );
			$hash_results[ $slug ] = $saved ? 'hash_saved' : 'hash_failed';
			if ( ! $saved ) {
				self::record( 'integrity_hash', 'warning', "save_integrity_hash() returned false for agent '$slug'" );
			}
		}

		self::record(
			'activate_bundled_agents',
			'ok',
			array(
				'found'        => $bundled_slugs,
				'newly_added'  => array_values( $newly_added ),
				'total_active' => count( $merged ),
				'hashes'       => $hash_results,
			)
		);
	}

	/**
	 * Import on-disk agents from the writable agents directory into the library
	 * table (one-time migration).
	 *
	 * Nothing the ~100 existing free installs created may be lost when the table
	 * becomes the authoritative store. This reads the writable directory as DATA
	 * only — it never includes or executes agent.php from there (WP.org
	 * Guideline 8). Declarative agent.json agents import directly; legacy
	 * agent.php agents are synthesised into a manifest ONLY when every declared
	 * tool resolves to a registered library tool, otherwise they are recorded
	 * for admin-assisted migration and never silently dropped.
	 *
	 * Idempotency is tracked in an option ledger (not a marker file) so it holds
	 * even when the directory is not writable during CLI activation, and so an
	 * agent the user later deletes from the library is not re-imported.
	 *
	 * @param string|null $dir Directory to import from. Defaults to AGENTIC_AGENTS_DIR.
	 * @return void
	 */
	private static function import_agents_dir( ?string $dir = null ): void {
		if ( ! class_exists( '\Agentic\Agent_Library' ) ) {
			return;
		}

		if ( null === $dir ) {
			$dir = defined( 'AGENTIC_AGENTS_DIR' ) ? AGENTIC_AGENTS_DIR : WP_CONTENT_DIR . '/agentic-agents';
		}

		if ( ! is_dir( $dir ) ) {
			return;
		}

		$folders = scandir( $dir );
		if ( ! is_array( $folders ) ) {
			return;
		}

		$imported_ledger = get_option( 'agentic_agents_dir_imported', array() );
		if ( ! is_array( $imported_ledger ) ) {
			$imported_ledger = array();
		}

		$needs_migration = get_option( 'agentic_agents_needing_migration', array() );
		if ( ! is_array( $needs_migration ) ) {
			$needs_migration = array();
		}

		$imported = array();
		$flagged  = array();

		foreach ( $folders as $folder ) {
			// Skip dotfiles/dot-dirs and non-directories.
			if ( '' === $folder || '.' === $folder[0] ) {
				continue;
			}

			$path = trailingslashit( $dir ) . $folder;
			if ( ! is_dir( $path ) ) {
				continue;
			}

			// Already imported once — never re-import (owner may have since deleted it).
			if ( in_array( $folder, $imported_ledger, true ) ) {
				continue;
			}

			// A row already owns this slug — respect it, mark handled.
			if ( \Agentic\Agent_Library::get_by_slug( $folder ) ) {
				$imported_ledger[] = $folder;
				continue;
			}

			$has_json = file_exists( $path . '/agent.json' );
			$has_php  = file_exists( $path . '/agent.php' );

			if ( ! $has_json && ! $has_php ) {
				continue; // Not an agent directory (e.g. a shared library).
			}

			$source = file_exists( $path . '/.requires-license' ) ? 'purchased' : 'user';

			// Declarative agent: import the manifest as-is.
			if ( $has_json ) {
				$manifest = \Agentic\Agent_Manifest_Validator::from_file( $path . '/agent.json' );
				if ( is_array( $manifest ) ) {
					self::import_manifest_row( $folder, $manifest, $source );
					$imported_ledger[] = $folder;
					$imported[]        = $folder;
				}
				continue;
			}

			// Legacy PHP agent: convert to a manifest ONLY if it is "thin" — every
			// declared tool resolves to a registered library tool. The PHP file is
			// never executed; tool names come from abilities.json (data).
			$header = self::read_agent_php_header( $path . '/agent.php' );
			$tools  = self::read_abilities_tool_names( $path . '/abilities.json' );

			if ( null !== $tools && '' !== ( $header['name'] ?? '' ) && self::all_tools_resolve( $tools ) ) {
				$manifest = self::synthesise_manifest( $folder, $header, $tools, $path );
				self::import_manifest_row( $folder, $manifest, $source );
				$imported_ledger[] = $folder;
				$imported[]        = $folder;
				continue;
			}

			// Heavy / undetermined agent: do NOT execute, do NOT drop. Record for
			// admin-assisted migration. Not added to the ledger, so a later
			// resolution (e.g. converted agent.json) is still picked up.
			$reason                     = ( null === $tools ) ? 'no_abilities_manifest' : 'unresolved_tools';
			$needs_migration[ $folder ] = array(
				'slug'   => $folder,
				'name'   => $header['name'] ?? $folder,
				'reason' => $reason,
				'tools'  => $tools ?? array(),
			);
			$flagged[]                  = $folder;
		}

		update_option( 'agentic_agents_dir_imported', array_values( array_unique( $imported_ledger ) ) );
		update_option( 'agentic_agents_needing_migration', $needs_migration );

		self::record(
			'import_agents_dir',
			'ok',
			array(
				'dir'             => $dir,
				'imported'        => $imported,
				'needs_migration' => $flagged,
			)
		);
	}

	/**
	 * Upsert a manifest agent discovered in the writable directory into the table.
	 *
	 * @param string               $slug     Directory slug.
	 * @param array<string, mixed> $manifest Validated manifest.
	 * @param string               $source   'user' or 'purchased'.
	 * @return void
	 */
	private static function import_manifest_row( string $slug, array $manifest, string $source ): void {
		\Agentic\Agent_Library::upsert(
			array(
				'slug'     => $slug,
				'name'     => (string) ( $manifest['name'] ?? $slug ),
				'manifest' => $manifest,
				'kind'     => 'manifest',
				'source'   => $source,
				'origin'   => 'agents_dir_import',
				'version'  => (string) ( $manifest['version'] ?? '1.0.0' ),
				'author'   => (string) ( $manifest['author'] ?? '' ),
			)
		);
	}

	/**
	 * Read an agent.php plugin-style header as DATA (never executes the file).
	 *
	 * @param string $file Absolute path to agent.php.
	 * @return array<string, string>
	 */
	private static function read_agent_php_header( string $file ): array {
		$headers = array(
			'name'         => 'Agent Name',
			'version'      => 'Version',
			'description'  => 'Description',
			'author'       => 'Author',
			'author_uri'   => 'Author URI',
			'category'     => 'Category',
			'capabilities' => 'Capabilities',
			'icon'         => 'Icon',
		);
		$data    = get_file_data( $file, $headers );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Read declared tool names from an abilities.json as DATA.
	 *
	 * @param string $file Absolute path to abilities.json.
	 * @return string[]|null List of tool names, or null when no manifest exists.
	 */
	private static function read_abilities_tool_names( string $file ): ?array {
		if ( ! file_exists( $file ) ) {
			return null;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read as data during activation; WP_Filesystem unavailable.
		$json = file_get_contents( $file );
		if ( false === $json ) {
			return null;
		}
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			return null;
		}
		$tools = array();
		if ( isset( $data['abilities'] ) && is_array( $data['abilities'] ) ) {
			$tools = array_keys( $data['abilities'] );
		}
		if ( ! empty( $data['wp_abilities'] ) && is_array( $data['wp_abilities'] ) ) {
			foreach ( $data['wp_abilities'] as $entry ) {
				if ( ! empty( $entry['name'] ) ) {
					$tools[] = (string) $entry['name'];
				}
			}
		}
		return array_values( array_unique( array_map( 'strval', $tools ) ) );
	}

	/**
	 * Whether every tool name resolves to a registered library tool.
	 *
	 * @param string[] $tools Tool names.
	 * @return bool True when the list is non-empty and all tools resolve.
	 */
	private static function all_tools_resolve( array $tools ): bool {
		if ( empty( $tools ) || ! class_exists( '\Agentic\Tools_Registry' ) ) {
			return false;
		}
		foreach ( $tools as $tool ) {
			if ( null === \Agentic\Tools_Registry::get( (string) $tool ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Build a manifest for a thin legacy agent from its header, tools, and
	 * (optionally) its on-disk system prompt template — all read as data.
	 *
	 * @param string                $slug   Directory slug.
	 * @param array<string, string> $header Parsed agent.php header.
	 * @param string[]              $tools  Resolved tool names.
	 * @param string                $path   Absolute agent directory path.
	 * @return array<string, mixed> Validated manifest.
	 */
	private static function synthesise_manifest( string $slug, array $header, array $tools, string $path ): array {
		$caps = array();
		if ( ! empty( $header['capabilities'] ) ) {
			$caps = array_filter( array_map( 'trim', explode( ',', $header['capabilities'] ) ) );
		}

		$raw = array(
			'slug'         => $slug,
			'name'         => $header['name'] ?? $slug,
			'description'  => $header['description'] ?? '',
			'version'      => $header['version'] ?? '1.0.0',
			'author'       => $header['author'] ?? '',
			'author_uri'   => $header['author_uri'] ?? '',
			'category'     => $header['category'] ?? 'admin',
			'icon'         => $header['icon'] ?? '🤖',
			'capabilities' => array_values( $caps ),
			'tools'        => $tools,
		);

		$prompt_file = $path . '/templates/system-prompt.txt';
		if ( file_exists( $prompt_file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local template read as data during activation.
			$prompt = file_get_contents( $prompt_file );
			if ( is_string( $prompt ) && '' !== trim( $prompt ) ) {
				$raw['system_prompt'] = $prompt;
			}
		}

		$manifest = \Agentic\Agent_Manifest_Validator::validate( $raw );
		return is_wp_error( $manifest ) ? $raw : $manifest;
	}

	/**
	 * Mirror every bundled agent shipped in the plugin into the library table.
	 *
	 * Runs on activation/upgrade. Bundled agents live as reviewed files under
	 * library/agents/{slug} (agent.json for declarative agents, agent.php for
	 * reviewed PHP ones). Their rows are the single-source-derived mirror:
	 * declarative agents seed kind=manifest; PHP agents seed kind=php with a
	 * path, and the registry keeps running those from the reviewed file. Because
	 * the file also wins the registry's directory scan, these rows serve
	 * uniformity and queryability, not runtime dispatch.
	 *
	 * A PHP agent's row is DERIVED at seed time (header + abilities.json), so the
	 * reviewed class stays the one authored source and cannot drift.
	 *
	 * Version rule (§8.5): a newer bundled version overwrites, but only a
	 * source=bundled row the admin has not edited since we last seeded it (its
	 * stored hash still matches our recorded seed hash). An edited row is left
	 * intact and flagged "update available" instead of being clobbered. Rows
	 * owned by purchased/user sources are never touched.
	 *
	 * @param string|null $dirs Library dirs to scan. Defaults to the filtered set.
	 * @return void
	 */
	private static function seed_bundled_agents( ?array $dirs = null ): void {
		if ( ! class_exists( '\Agentic\Agent_Library' ) ) {
			return;
		}

		$library_dirs = $dirs ?? apply_filters( 'agentic_library_dirs', array( AGENT_BUILDER_DIR . 'library/agents' ) );

		$seed_hashes = get_option( 'agentic_bundled_seed_hashes', array() );
		$seed_hashes = is_array( $seed_hashes ) ? $seed_hashes : array();

		$updates = get_option( 'agentic_bundled_updates_available', array() );
		$updates = is_array( $updates ) ? $updates : array();

		$seeded  = array();
		$skipped = array();
		$flagged = array();

		foreach ( $library_dirs as $library_dir ) {
			if ( ! is_dir( $library_dir ) ) {
				continue;
			}

			$origin  = self::origin_for_library_dir( $library_dir );
			$folders = scandir( $library_dir );
			if ( ! is_array( $folders ) ) {
				continue;
			}

			foreach ( $folders as $folder ) {
				if ( '' === $folder || '.' === $folder[0] || 'README.md' === $folder ) {
					continue;
				}

				$path = trailingslashit( $library_dir ) . $folder;
				if ( ! is_dir( $path ) ) {
					continue;
				}

				$has_json = file_exists( $path . '/agent.json' );
				$has_php  = file_exists( $path . '/agent.php' );

				if ( $has_json ) {
					$manifest = \Agentic\Agent_Manifest_Validator::from_file( $path . '/agent.json' );
					if ( ! is_array( $manifest ) ) {
						continue;
					}
					$kind     = 'manifest';
					$row_path = '';
				} elseif ( $has_php ) {
					$header = self::read_agent_php_header( $path . '/agent.php' );
					if ( '' === ( $header['name'] ?? '' ) ) {
						continue;
					}
					$tools    = self::read_abilities_tool_names( $path . '/abilities.json' ) ?? array();
					$manifest = self::synthesise_manifest( $folder, $header, $tools, $path );
					$kind     = 'php';
					// Portable, informational reference only — the registry always
					// loads PHP agents from the reviewed library directory scan, not
					// from this column, so a relative slug path suffices.
					$row_path = $folder . '/agent.php';
				} else {
					continue;
				}

				$version  = (string) ( $manifest['version'] ?? '1.0.0' );
				$new_hash = hash( 'sha256', (string) wp_json_encode( $manifest ) );
				$row      = \Agentic\Agent_Library::get_by_slug( $folder );

				if ( ! $row ) {
					self::upsert_bundled_row( $folder, $manifest, $kind, $row_path, $origin, $version );
					$seed_hashes[ $folder ] = $new_hash;
					unset( $updates[ $folder ] );
					$seeded[] = $folder;
					continue;
				}

				if ( 'bundled' !== ( $row['source'] ?? '' ) ) {
					$skipped[] = $folder; // purchased/user owns this slug.
					continue;
				}

				if ( ! version_compare( $version, (string) ( $row['version'] ?? '0' ), '>' ) ) {
					$skipped[] = $folder; // same or older.
					continue;
				}

				$prev_seed = (string) ( $seed_hashes[ $folder ] ?? '' );
				if ( '' === $prev_seed || ( $row['hash'] ?? '' ) === $prev_seed ) {
					// Unmodified since our last seed — clean upgrade.
					self::upsert_bundled_row( $folder, $manifest, $kind, $row_path, $origin, $version );
					$seed_hashes[ $folder ] = $new_hash;
					unset( $updates[ $folder ] );
					$seeded[] = $folder;
				} else {
					// Admin edited the bundled row — never clobber; surface a notice.
					$updates[ $folder ] = array(
						'slug' => $folder,
						'from' => (string) ( $row['version'] ?? '' ),
						'to'   => $version,
					);
					$flagged[]          = $folder;
				}
			}
		}

		update_option( 'agentic_bundled_seed_hashes', $seed_hashes );
		update_option( 'agentic_bundled_updates_available', $updates );

		self::record(
			'seed_bundled_agents',
			'ok',
			array(
				'seeded'  => $seeded,
				'skipped' => $skipped,
				'flagged' => $flagged,
			)
		);
	}

	/**
	 * Upsert a bundled-agent row with a consistent field bag.
	 *
	 * @param string               $slug     Agent slug.
	 * @param array<string, mixed> $manifest Derived/validated manifest.
	 * @param string               $kind     'manifest' or 'php'.
	 * @param string               $path     Relative-safe path for php rows, '' otherwise.
	 * @param string               $origin   Owning plugin origin.
	 * @param string               $version  Agent version.
	 * @return void
	 */
	private static function upsert_bundled_row( string $slug, array $manifest, string $kind, string $path, string $origin, string $version ): void {
		\Agentic\Agent_Library::upsert(
			array(
				'slug'        => $slug,
				'name'        => (string) ( $manifest['name'] ?? $slug ),
				'description' => (string) ( $manifest['description'] ?? '' ),
				'manifest'    => $manifest,
				'kind'        => $kind,
				'path'        => $path,
				'source'      => 'bundled',
				'origin'      => $origin,
				'version'     => $version,
				'author'      => (string) ( $manifest['author'] ?? '' ),
			)
		);
	}

	/**
	 * Derive the origin tag for a library directory so plugin deactivation can
	 * later disable only its own rows.
	 *
	 * @param string $dir Absolute library directory path.
	 * @return string
	 */
	private static function origin_for_library_dir( string $dir ): string {
		if ( false !== strpos( $dir, 'agent-builder-pro' ) ) {
			return 'agent-builder-pro';
		}
		if ( defined( 'AGENT_BUILDER_DIR' ) && 0 === strpos( $dir, AGENT_BUILDER_DIR ) ) {
			return 'agent-builder';
		}
		return 'agent-builder';
	}

	/**
	 * Create all custom database tables.
	 *
	 * @return void
	 */
	private static function create_tables(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		// Audit log table.
		$sql_audit = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}agentic_audit_log (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            agent_id varchar(64) NOT NULL,
            action varchar(128) NOT NULL,
            target_type varchar(64),
            target_id varchar(128),
            details longtext,
            reasoning text,
            mode varchar(32) DEFAULT '',
            provider varchar(64) DEFAULT '',
            tokens_used int unsigned DEFAULT 0,
            cost decimal(10,6) DEFAULT 0,
            user_id bigint(20) unsigned,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            agent_author varchar(191) DEFAULT '',
            agent_version varchar(32) DEFAULT '',
            integrity_hash char(64) DEFAULT NULL,
            PRIMARY KEY (id),
            KEY agent_id (agent_id),
            KEY action (action),
            KEY created_at (created_at),
            KEY user_created (user_id, created_at),
            KEY idx_agent_created (agent_id, created_at)
        ) $charset_collate;";

		// Approval queue table.
		$sql_queue = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}agentic_approval_queue (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            agent_id varchar(64) NOT NULL,
            action varchar(128) NOT NULL,
            params longtext NOT NULL,
            reasoning text,
            risk_level varchar(32) DEFAULT 'none',
            status varchar(32) DEFAULT 'pending',
            approved_by bigint(20) unsigned,
            approved_at datetime,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            expires_at datetime,
            executed_at datetime DEFAULT NULL,
            mode varchar(32) DEFAULT '',
            invocation varchar(32) DEFAULT '',
            PRIMARY KEY (id),
            KEY status (status),
            KEY created_at (created_at),
            KEY idx_status_created (status, created_at),
            KEY idx_expires (expires_at)
        ) $charset_collate;";

		// Memory table.
		$sql_memory = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}agentic_memory (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            memory_type varchar(50) NOT NULL,
            entity_id varchar(100) NOT NULL,
            memory_key varchar(255) NOT NULL,
            memory_value longtext NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            expires_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY memory_type_entity (memory_type, entity_id),
            KEY memory_type_created (memory_type, created_at),
            KEY memory_key (memory_key),
            KEY idx_expires (expires_at)
        ) $charset_collate;";

		// Tools registry table.
		$sql_tools = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}agentic_tools (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(128) NOT NULL,
            description text NOT NULL,
            category varchar(64) NOT NULL DEFAULT 'WordPress',
            source varchar(64) NOT NULL DEFAULT 'core',
            enabled tinyint(1) NOT NULL DEFAULT 1,
            risk_level varchar(32) NOT NULL DEFAULT 'none',
            parameters longtext,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY name (name),
            KEY category (category),
            KEY source (source),
            KEY enabled (enabled)
        ) $charset_collate;";

		include_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_results = array();
		$table_errors  = array();

		// Helper to run dbDelta and capture any error.
		//
		// dbDelta() cannot parse "CREATE TABLE IF NOT EXISTS" — its regex takes
		// the first word after "CREATE TABLE " as the table name, so it reads
		// "IF" as the table name and silently operates on a bogus table called
		// "IF" instead of the real one. Every $sql_* string below is written
		// with "IF NOT EXISTS" (dbDelta already no-ops safely on a table that
		// already exists with matching columns, so it was never needed), which
		// means every schema change to an already-existing table — a widened
		// column, a new column, a new index — has been silently applying to
		// nothing on any site that installed before that change shipped. Strip
		// it here rather than rewrite 10 SQL strings, so this is fixed for
		// every table at once.
		$run_delta = function ( string $name, string $sql ) use ( $wpdb, &$table_results, &$table_errors ): void {
			$sql                    = preg_replace( '/CREATE TABLE IF NOT EXISTS/i', 'CREATE TABLE', $sql, 1 );
			$wpdb->last_error       = '';
			$delta                  = dbDelta( $sql );
			$table_results[ $name ] = $delta;
			if ( $wpdb->last_error ) {
				$table_errors[ $name ] = $wpdb->last_error;
			}
		};

		$run_delta( 'agentic_audit_log', $sql_audit );
		$run_delta( 'agentic_approval_queue', $sql_queue );
		$run_delta( 'agentic_memory', $sql_memory );
		$run_delta( 'agentic_tools', $sql_tools );

		// Conversations table — stores each chat turn for efficient session browsing.
		$sql_conversations = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}agentic_conversations (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            session_id varchar(36) NOT NULL,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            agent_id varchar(64) NOT NULL DEFAULT '',
            role varchar(16) NOT NULL DEFAULT 'user',
            content longtext NOT NULL,
            tools_used text,
            feedback tinyint(1) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY session_user (session_id, user_id),
            KEY user_agent (user_id, agent_id),
            KEY created_at (created_at)
        ) $charset_collate;";
		$run_delta( 'agentic_conversations', $sql_conversations );

		// Agent settings table.
		$sql_agent_settings = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}agentic_agent_settings (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            agent_slug varchar(128) NOT NULL,
            meta_key varchar(128) NOT NULL,
            meta_value longtext,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY agent_key (agent_slug, meta_key),
            KEY agent_slug (agent_slug),
            KEY meta_key (meta_key)
        ) $charset_collate;";
		$run_delta( 'agentic_agent_settings', $sql_agent_settings );

		// Providers table — stores LLM provider configuration and Agentic service endpoints.
		$sql_providers = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}agentic_providers (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            slug varchar(100) NOT NULL,
            name varchar(255) NOT NULL DEFAULT '',
            endpoint text,
            default_model varchar(255) NOT NULL DEFAULT '',
            vision_model varchar(255) NOT NULL DEFAULT '',
            auth_type varchar(50) NOT NULL DEFAULT 'bearer',
            req_format varchar(50) NOT NULL DEFAULT 'openai',
            resp_format varchar(50) NOT NULL DEFAULT 'openai',
            requires_key tinyint(1) NOT NULL DEFAULT 1,
            api_key text,
            key_url varchar(2048) NOT NULL DEFAULT '',
            icon text,
            models longtext,
            model_pricing longtext,
            is_builtin tinyint(1) NOT NULL DEFAULT 0,
            sort_order int(11) NOT NULL DEFAULT 99,
            provider_type varchar(32) NOT NULL DEFAULT 'llm',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug),
            KEY sort_order (sort_order),
            KEY provider_type (provider_type)
        ) $charset_collate;";
		$run_delta( 'agentic_providers', $sql_providers );

		// Skills table. agent_slug holds a JSON array of agent slugs (e.g.
		// '["content-writer","seo-optimizer"]'), or '' to mean every agent —
		// see Skills_Registry::normalize_agent_slugs()/decode_agent_slugs().
		// Widened from varchar(128) in 3.3.90 to fit multiple slugs.
		$sql_skills = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}agentic_skills (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            slug varchar(255) NOT NULL,
            description text,
            content longtext,
            agent_slug varchar(1024) NOT NULL DEFAULT '',
            source varchar(64) NOT NULL DEFAULT 'local',
            source_id varchar(255) NOT NULL DEFAULT '',
            version varchar(32) NOT NULL DEFAULT '1.0.0',
            author varchar(255) NOT NULL DEFAULT '',
            source_hash varchar(64) NOT NULL DEFAULT '',
            enabled tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug),
            KEY agent_slug (agent_slug),
            KEY source (source),
            KEY enabled (enabled)
        ) $charset_collate;";
		$run_delta( 'agentic_skills', $sql_skills );

		// Orchestration runs table — one row per top-level multi-agent (team) run.
		// Tracks delegation depth, fan-out, accumulated tokens/cost, and a small
		// JSON scratchpad shared across delegated agents within the run.
		$sql_runs = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}agentic_runs (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            run_id varchar(36) NOT NULL,
            root_agent varchar(64) NOT NULL DEFAULT '',
            status varchar(16) NOT NULL DEFAULT 'running',
            delegations int unsigned NOT NULL DEFAULT 0,
            max_depth smallint unsigned NOT NULL DEFAULT 0,
            tokens_used int unsigned NOT NULL DEFAULT 0,
            cost decimal(10,6) NOT NULL DEFAULT 0,
            state longtext,
            started_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            finished_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY run_id (run_id),
            KEY root_agent (root_agent),
            KEY status (status),
            KEY started_at (started_at)
        ) $charset_collate;";
		$run_delta( 'agentic_runs', $sql_runs );

		// Agent library — one row per agent, whatever its origin. Declarative
		// agents (kind=manifest) are interpreted from the manifest column by
		// Manifest_Agent; reviewed PHP agents (kind=php) keep running from their
		// file and the row is a version/record anchor. Dropped on uninstall when
		// the admin opted into full data deletion (see uninstall.php); otherwise
		// left in place so a reinstall finds existing purchased/user rows.
		$sql_agent_library = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}agentic_agent_library (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            slug varchar(128) NOT NULL,
            name varchar(255) NOT NULL,
            description text,
            manifest longtext NOT NULL,
            kind varchar(16) NOT NULL DEFAULT 'manifest',
            path varchar(255) NOT NULL DEFAULT '',
            source varchar(32) NOT NULL DEFAULT 'user',
            origin varchar(64) NOT NULL DEFAULT '',
            source_id varchar(255) NOT NULL DEFAULT '',
            version varchar(32) NOT NULL DEFAULT '1.0.0',
            author varchar(255) NOT NULL DEFAULT '',
            hash char(64) NOT NULL DEFAULT '',
            enabled tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug),
            KEY source (source),
            KEY origin (origin),
            KEY enabled (enabled)
        ) $charset_collate;";
		$run_delta( 'agentic_agent_library', $sql_agent_library );

		// Ensure Job_Manager, Security_Log, and Deployments are available (activation fires early).
		include_once AGENT_BUILDER_DIR . 'includes/class-job-manager.php';
		include_once AGENT_BUILDER_DIR . 'includes/class-security-log.php';
		include_once AGENT_BUILDER_DIR . 'includes/class-deployments.php';

		// Create jobs table.
		$wpdb->last_error = '';
		Job_Manager::create_table();
		if ( $wpdb->last_error ) {
			$table_errors['agentic_jobs'] = $wpdb->last_error;
		} else {
			$table_results['agentic_jobs'] = 'ok';
		}

		// Create security log table.
		$wpdb->last_error = '';
		Security_Log::create_table();
		if ( $wpdb->last_error ) {
			$table_errors['agentic_security_log'] = $wpdb->last_error;
		} else {
			$table_results['agentic_security_log'] = 'ok';
		}

		// Create deployments table.
		$wpdb->last_error = '';
		Deployments::create_table();
		if ( $wpdb->last_error ) {
			$table_errors['agentic_deployments'] = $wpdb->last_error;
		} else {
			$table_results['agentic_deployments'] = 'ok';
		}

		$status = empty( $table_errors ) ? 'ok' : 'warning';
		self::record(
			'create_tables',
			$status,
			array(
				'tables' => $table_results,
				'errors' => $table_errors,
			)
		);
	}

	/**
	 * Run one-time data migrations (idempotent — each migration checks its own flag).
	 */
	private static function run_migrations(): void {
		// Content / data migrations (not schema).
		include_once AGENT_BUILDER_DIR . 'includes/class-deployments-migrator.php';
		Deployments_Migrator::run();

		self::migrate_agent_personas();
		self::migrate_agent_overrides();
		self::migrate_editor_sidebar_seo_slug();
		self::migrate_remove_agentic_rag_service();
		self::migrate_skills_agent_slug_to_array();

		// Schema migrations (proper versioned table changes).
		self::run_schema_migrations();
	}

	/**
	 * Run pending schema migrations in order.
	 * This is the new recommended way to evolve the database schema.
	 */
	private static function run_schema_migrations(): void {
		$current = get_option( 'agentic_db_schema_version', '0.0.0' );
		$target  = AGENT_BUILDER_DB_VERSION;

		if ( version_compare( $current, $target, '>=' ) ) {
			return;
		}

		$migrations = array(
			'2.10.1' => array( self::class, 'migrate_schema_2_10_1' ),
			'2.10.2' => array( self::class, 'migrate_schema_2_10_2' ),
			'2.10.3' => array( self::class, 'migrate_schema_2_10_3' ),
			'2.10.4' => array( self::class, 'migrate_schema_2_10_4' ),
			'2.12.1' => array( self::class, 'migrate_schema_2_12_1' ),
			'2.13.0' => array( self::class, 'migrate_schema_2_13_0' ),
			'2.13.6' => array( self::class, 'migrate_schema_2_13_6' ),
			// Add new version => method pairs here when schema changes are needed.
			// Future changes must go through this system (no more ad-hoc ALTERs in bootstrap/tests).
		);

		foreach ( $migrations as $version => $callback ) {
			if ( version_compare( $current, $version, '<' ) ) {
				self::record( 'schema_migration_start', 'info', "Running migration to {$version}" );

				$success = call_user_func( $callback );

				if ( $success ) {
					update_option( 'agentic_db_schema_version', $version );
					$current = $version;
					self::record( 'schema_migration_complete', 'ok', "Migrated to {$version}" );
				} else {
					self::record( 'schema_migration_failed', 'error', "Failed to migrate to {$version}" );
					// Stop further migrations to avoid making things worse.
					break;
				}
			}
		}
	}

	/**
	 * Schema changes introduced around 2.10.1.
	 */
	private static function migrate_schema_2_10_1(): bool {
		global $wpdb;

		$success = true;

		// agentic_approval_queue columns.
		$success = $success && self::add_column_if_missing(
			$wpdb->prefix . 'agentic_approval_queue',
			'executed_at',
			'datetime DEFAULT NULL'
		);

		$success = $success && self::add_column_if_missing(
			$wpdb->prefix . 'agentic_approval_queue',
			'mode',
			"varchar(32) DEFAULT ''"
		);

		$success = $success && self::add_column_if_missing(
			$wpdb->prefix . 'agentic_approval_queue',
			'invocation',
			"varchar(32) DEFAULT ''"
		);

		// agentic_providers columns.
		$success = $success && self::add_column_if_missing(
			$wpdb->prefix . 'agentic_providers',
			'vision_model',
			"varchar(255) NOT NULL DEFAULT ''"
		);

		$success = $success && self::add_column_if_missing(
			$wpdb->prefix . 'agentic_providers',
			'model_pricing',
			// NB: TEXT/BLOB/JSON columns cannot have a literal DEFAULT on MySQL
			// (error 1101); MariaDB allows it. Keep nullable, no default — reads
			// coalesce (?? '{}'). See create_tables() providers definition.
			'longtext'
		);

		return $success;
	}

	/**
	 * Schema changes for 2.10.2 (placeholder for future changes in this version).
	 */
	private static function migrate_schema_2_10_2(): bool {
		// Add any 2.10.2 schema changes here when needed.
		// For now this is a no-op so upgrades to 2.10.2 still succeed cleanly.
		return true;
	}

	/**
	 * Schema stabilization changes for 2.10.3:
	 * - Ensure provider_type column exists on providers (was added to CREATE without migration).
	 * - Ensure critical composite indexes exist on audit/approval/memory tables
	 *   (previously only enforced via test bootstrap hacks).
	 */
	private static function migrate_schema_2_10_3(): bool {
		global $wpdb;

		$success = true;

		// Providers: ensure provider_type column for service/llm distinction (used by Provider_Registry).
		$success = $success && self::add_column_if_missing(
			$wpdb->prefix . 'agentic_providers',
			'provider_type',
			"varchar(32) NOT NULL DEFAULT 'llm'"
		);

		// Composite indexes for query performance and consistency with test expectations.
		$success = $success && self::add_index_if_missing(
			$wpdb->prefix . 'agentic_audit_log',
			'idx_agent_created',
			'agent_id, created_at'
		);

		$success = $success && self::add_index_if_missing(
			$wpdb->prefix . 'agentic_approval_queue',
			'idx_status_created',
			'status, created_at'
		);

		$success = $success && self::add_index_if_missing(
			$wpdb->prefix . 'agentic_approval_queue',
			'idx_expires',
			'expires_at'
		);

		$success = $success && self::add_index_if_missing(
			$wpdb->prefix . 'agentic_memory',
			'idx_expires',
			'expires_at'
		);

		return $success;
	}

	/**
	 * Schema repair for 2.12.1 — re-ensure the providers.provider_type column.
	 *
	 * Sites that installed the 3.0.0 line jumped straight to DB version 2.12.0 and
	 * never ran migrate_schema_2_10_3, so their agentic_providers table can be
	 * missing the provider_type column. Without it every provider-row INSERT fails,
	 * so the built-in providers (including 'agentic') never seed. That breaks the
	 * free-API signup (save_api_key returns false) and, because no LLM ends up
	 * configured, hides the chat page — surfacing as "Sorry, you are not allowed to
	 * access this page." after signup. Adding the column lets Provider_Registry
	 * re-seed the built-ins on its next load.
	 *
	 * @return bool True on success.
	 */
	private static function migrate_schema_2_12_1(): bool {
		global $wpdb;

		$ok = self::add_column_if_missing(
			$wpdb->prefix . 'agentic_providers',
			'provider_type',
			"varchar(32) NOT NULL DEFAULT 'llm'"
		);

		// Drop the cached (possibly empty) provider list so the next load() re-seeds
		// any built-ins that failed to insert while the column was missing.
		if ( $ok && class_exists( '\Agentic\Provider_Registry' ) ) {
			\Agentic\Provider_Registry::invalidate();
		}

		return $ok;
	}

	/**
	 * Migration to 2.13.0 — add source_hash to agentic_skills.
	 *
	 * Tracks the hash of a core-sourced skill's content at the time it was
	 * last seeded/refreshed from its bundled library/skills/ file, so a
	 * plugin update can tell whether the user customized it (leave alone)
	 * or never touched it (safe to refresh) — see Skills_Registry::
	 * seed_core_skills() and is_customized().
	 *
	 * @return bool
	 */
	private static function migrate_schema_2_13_0(): bool {
		global $wpdb;

		return self::add_column_if_missing(
			$wpdb->prefix . 'agentic_skills',
			'source_hash',
			"varchar(64) NOT NULL DEFAULT ''"
		);
	}

	/**
	 * Migration to 2.13.6 — seed the Agent-Ready Score / WebMCP Bridge options.
	 *
	 * No new tables: agentic_score_latest and agentic_directory_submission are
	 * single-row JSON options (no queryable/per-row need in free tier — score
	 * history is Pro-only), and agentic_webmcp_enabled is a plain boolean flag
	 * read on every front-end request, so it is autoloaded. Idempotent: only
	 * seeds options that don't already exist, so re-running is always safe.
	 *
	 * @return bool
	 */
	private static function migrate_schema_2_13_6(): bool {
		if ( false === get_option( 'agentic_score_latest' ) ) {
			add_option( 'agentic_score_latest', array(), '', 'no' );
		}
		if ( false === get_option( 'agentic_webmcp_enabled' ) ) {
			add_option( 'agentic_webmcp_enabled', '', '', 'yes' );
		}
		if ( false === get_option( 'agentic_directory_submission' ) ) {
			add_option( 'agentic_directory_submission', array(), '', 'no' );
		}
		return true;
	}

	/**
	 * Migration to 2.10.4 — create the missing agentic_runs table.
	 *
	 * Agent_Run has always queried wp_agentic_runs, but no CREATE TABLE for it
	 * existed anywhere in the plugin. Every delegate_to_agent call ran a SELECT
	 * and an INSERT against a table that was not there: persist() returned
	 * false, so no run was ever recorded for audit, cost or resume.
	 *
	 * The in-request delegation depth guard and per-run budget cap were not
	 * affected — both read the live Agent_Run object, which is handed down
	 * through the agentic_current_run filter rather than reloaded from the
	 * database. Only persistence was lost.
	 *
	 * create_tables() also declares this table, so a fresh install gets it
	 * directly; this migration covers every site that installed before 2.10.4.
	 *
	 * @return bool True on success.
	 */
	private static function migrate_schema_2_10_4(): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'agentic_runs';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
			return true;
		}

		// create_tables() owns the canonical definition; re-run it so the two
		// never drift apart.
		self::create_tables();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	/**
	 * Helper to safely add a column if it doesn't exist.
	 *
	 * @param string $table     Full table name (with prefix).
	 * @param string $column    Column name to add.
	 * @param string $definition Column type + default clause (e.g. "varchar(32) DEFAULT ''").
	 * @return bool True on success or if already present.
	 */
	private static function add_column_if_missing( string $table, string $column, string $definition ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema introspection (information_schema) is the correct and only way to check column/index existence portably.
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.COLUMNS 
				 WHERE TABLE_SCHEMA = DATABASE() 
				   AND TABLE_NAME = %s 
				   AND COLUMN_NAME = %s',
				$table,
				$column
			)
		);

		if ( $exists ) {
			return true;
		}

		// $table is always $wpdb->prefix . known_identifier from callers; never user input.
		$sql = "ALTER TABLE {$table} ADD COLUMN {$column} {$definition}";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$result = $wpdb->query( $sql );

		if ( false === $result ) {
			self::record( 'add_column_failed', 'error', "Failed to add column {$column} to {$table}: " . $wpdb->last_error );
			return false;
		}

		return true;
	}

	/**
	 * Helper to safely add a (non-unique) index/key if it doesn't exist.
	 * Used by schema migrations to converge production tables with CREATE definitions.
	 *
	 * @param string $table      Full table name (with prefix).
	 * @param string $index_name Name for the new KEY.
	 * @param string $columns    Column list (e.g. "agent_id, created_at").
	 * @return bool True on success or if already present.
	 */
	private static function add_index_if_missing( string $table, string $index_name, string $columns ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema introspection (information_schema) is the correct and only way to check column/index existence portably.
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.STATISTICS 
				 WHERE TABLE_SCHEMA = DATABASE() 
				   AND TABLE_NAME = %s 
				   AND INDEX_NAME = %s',
				$table,
				$index_name
			)
		);

		if ( $exists ) {
			return true;
		}

		// $table is always $wpdb->prefix . known_identifier from callers; never user input.
		$sql = "ALTER TABLE {$table} ADD KEY {$index_name} ({$columns})";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$result = $wpdb->query( $sql );

		if ( false === $result ) {
			self::record( 'add_index_failed', 'error', "Failed to add index {$index_name} to {$table}: " . $wpdb->last_error );
			return false;
		}

		return true;
	}

	/**
	 * Migrate legacy agentic_agent_personas wp_options data into the agent_settings table.
	 *
	 * Runs once; idempotency is guarded by the agentic_personas_migrated_v1 option.
	 *
	 * @return void
	 */
	private static function migrate_agent_personas(): void {
		if ( get_option( 'agentic_personas_migrated_v1' ) ) {
			self::record( 'migrate_agent_personas', 'skipped', 'already migrated' );
			return;
		}

		$personas = get_option( 'agentic_agent_personas', null );

		if ( ! is_array( $personas ) || empty( $personas ) ) {
			update_option( 'agentic_personas_migrated_v1', true );
			self::record( 'migrate_agent_personas', 'ok', 'no persona data to migrate' );
			return;
		}

		include_once AGENT_BUILDER_DIR . 'includes/class-agent-settings.php';

		$migrated = array();

		foreach ( $personas as $slug => $data ) {
			$slug = sanitize_key( (string) $slug );
			if ( empty( $slug ) || ! is_array( $data ) ) {
				continue;
			}

			$keys = array();

			if ( ! empty( $data['welcome_message'] ) ) {
				Agent_Settings::update( $slug, 'persona_welcome_message', (string) $data['welcome_message'] );
				$keys[] = 'welcome_message';
			}
			if ( ! empty( $data['persona_notes'] ) ) {
				Agent_Settings::update( $slug, 'persona_notes', (string) $data['persona_notes'] );
				$keys[] = 'persona_notes';
			}
			if ( ! empty( $data['response_style'] ) ) {
				Agent_Settings::update( $slug, 'persona_response_style', (string) $data['response_style'] );
				$keys[] = 'response_style';
			}
			if ( ! empty( $data['suggested_prompts'] ) && is_array( $data['suggested_prompts'] ) ) {
				Agent_Settings::update( $slug, 'persona_suggested_prompts', (string) wp_json_encode( array_values( $data['suggested_prompts'] ) ) );
				$keys[] = 'suggested_prompts';
			}

			if ( ! empty( $keys ) ) {
				$migrated[ $slug ] = $keys;
			}
		}

		update_option( 'agentic_personas_migrated_v1', true );

		self::record(
			'migrate_agent_personas',
			'ok',
			array(
				'agents_migrated' => count( $migrated ),
				'detail'          => $migrated,
			)
		);
	}

	/**
	 * Migrate legacy agentic_agent_overrides wp_options data into the agent_settings table.
	 *
	 * Keys migrated per agent slug:
	 *   provider → override_provider, model → override_model,
	 *   vision_model → override_vision_model, mode → override_mode,
	 *   audio/tts/vision/costs/cache → override_{key}
	 *
	 * @return void
	 */
	private static function migrate_agent_overrides(): void {
		if ( get_option( 'agentic_overrides_migrated_v1' ) ) {
			self::record( 'migrate_agent_overrides', 'skipped', 'already migrated' );
			return;
		}

		$overrides = get_option( 'agentic_agent_overrides', null );

		if ( ! is_array( $overrides ) || empty( $overrides ) ) {
			update_option( 'agentic_overrides_migrated_v1', true );
			self::record( 'migrate_agent_overrides', 'ok', 'no override data to migrate' );
			return;
		}

		include_once AGENT_BUILDER_DIR . 'includes/class-agent-settings.php';

		$keys_map = array(
			'provider'     => 'override_provider',
			'model'        => 'override_model',
			'vision_model' => 'override_vision_model',
			'mode'         => 'override_mode',
			'audio'        => 'override_audio',
			'tts'          => 'override_tts',
			'vision'       => 'override_vision',
			'costs'        => 'override_costs',
			'cache'        => 'override_cache',
		);

		$migrated = array();

		foreach ( $overrides as $slug => $data ) {
			$slug = sanitize_key( (string) $slug );
			if ( empty( $slug ) || ! is_array( $data ) ) {
				continue;
			}
			$written = array();
			foreach ( $keys_map as $old_key => $new_key ) {
				if ( isset( $data[ $old_key ] ) && '' !== (string) $data[ $old_key ] ) {
					Agent_Settings::update( $slug, $new_key, (string) $data[ $old_key ] );
					$written[] = $old_key;
				}
			}
			if ( ! empty( $written ) ) {
				$migrated[ $slug ] = $written;
			}
		}

		update_option( 'agentic_overrides_migrated_v1', true );

		self::record(
			'migrate_agent_overrides',
			'ok',
			array(
				'agents_migrated' => count( $migrated ),
				'detail'          => $migrated,
			)
		);
	}

	/**
	 * Fix a since-day-one typo in the fresh-install default: the SEO agent's
	 * slug was hardcoded as 'seo-assistant' in set_default_options() instead
	 * of the real slug 'seo-optimizer'. Any install whose editor-sidebar
	 * settings still have the never-edited default carries this bad slug in
	 * TWO places that must both be fixed — it silently never matches a real
	 * agent, so "SEO Optimizer" always showed as unchecked in the classic
	 * Editor tab despite being listed as enabled by anything that reads the
	 * raw data (e.g. tool output):
	 *
	 * 1. The `agentic_editor_sidebar_settings` option itself (fresh-install
	 *    default, or fallback source when no Deployments rows exist yet).
	 * 2. A `wp_agentic_deployments` row (type admin_ui) with this same slug,
	 *    which Deployments_Migrator copied verbatim from the option the
	 *    first time it ran — the *primary* source the classic Editor tab
	 *    and manage_editor_sidebar_agent tool actually read once rows exist.
	 *
	 * @return void
	 */
	private static function migrate_editor_sidebar_seo_slug(): void {
		if ( get_option( 'agentic_editor_sidebar_seo_slug_fixed_v1' ) ) {
			self::record( 'migrate_editor_sidebar_seo_slug', 'skipped', 'already migrated' );
			return;
		}

		$option_changed = false;
		$settings       = get_option( 'agentic_editor_sidebar_settings', null );

		if ( is_array( $settings ) && ! empty( $settings['agent_slugs'] ) && is_array( $settings['agent_slugs'] ) ) {
			$fixed = array_values(
				array_unique(
					array_map(
						static fn( $slug ) => 'seo-assistant' === $slug ? 'seo-optimizer' : $slug,
						$settings['agent_slugs']
					)
				)
			);

			$option_changed = $fixed !== array_values( $settings['agent_slugs'] );
			if ( $option_changed ) {
				$settings['agent_slugs'] = $fixed;
				update_option( 'agentic_editor_sidebar_settings', $settings );
			}
		}

		$rows_changed = 0;
		if ( class_exists( Deployments::class ) ) {
			global $wpdb;
			$table = Deployments::table();

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$has_correct = (bool) $wpdb->get_var(
				$wpdb->prepare( 'SELECT id FROM %i WHERE type = %s AND agent_slug = %s', $table, Deployments::TYPE_ADMIN_UI, 'seo-optimizer' )
			);

			if ( $has_correct ) {
				// A correct row already exists — drop the bad one rather than collide.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$rows_changed = (int) $wpdb->delete( $table, array( 'type' => Deployments::TYPE_ADMIN_UI, 'agent_slug' => 'seo-assistant' ) );
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$rows_changed = (int) $wpdb->update(
					$table,
					array( 'agent_slug' => 'seo-optimizer' ),
					array( 'type' => Deployments::TYPE_ADMIN_UI, 'agent_slug' => 'seo-assistant' )
				);
			}
		}

		update_option( 'agentic_editor_sidebar_seo_slug_fixed_v1', true );

		self::record(
			'migrate_editor_sidebar_seo_slug',
			'ok',
			array(
				'option_changed' => $option_changed,
				'rows_changed'   => $rows_changed,
			)
		);
	}

	/**
	 * Remove the orphaned 'agentic-rag' service row from wp_agentic_providers.
	 *
	 * The RAG/Vector Store feature's own admin UI and calling code
	 * (class-rag-manager.php) were removed from this free build in an earlier
	 * release, but Provider_Registry::builtin_providers() kept registering the
	 * 'agentic-rag' endpoint as a configurable service — Provider_Registry::load()
	 * only ever adds a missing built-in row, never removes one no longer in the
	 * PHP definition, so every existing install carries a dead, unused endpoint
	 * override option in Settings > Endpoints. Provider_Registry::delete() also
	 * refuses built-ins by design, so this deletes the row directly.
	 *
	 * @return void
	 */
	private static function migrate_remove_agentic_rag_service(): void {
		if ( get_option( 'agentic_rag_service_removed_v1' ) ) {
			self::record( 'migrate_remove_agentic_rag_service', 'skipped', 'already migrated' );
			return;
		}

		global $wpdb;
		$deleted = 0;
		$table   = $wpdb->prefix . 'agentic_providers';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$deleted = (int) $wpdb->delete( $table, array( 'slug' => 'agentic-rag' ) );
		}

		if ( class_exists( '\Agentic\Provider_Registry' ) ) {
			\Agentic\Provider_Registry::invalidate();
		}

		update_option( 'agentic_rag_service_removed_v1', true );

		self::record( 'migrate_remove_agentic_rag_service', 'ok', array( 'deleted' => $deleted ) );
	}

	/**
	 * Wrap existing single-slug wp_agentic_skills.agent_slug values into the
	 * JSON-array format Skills_Registry now uses, so a skill can be scoped to
	 * more than one agent. Empty values (meaning "every agent") are untouched.
	 *
	 * @return void
	 */
	private static function migrate_skills_agent_slug_to_array(): void {
		if ( get_option( 'agentic_skills_agent_slug_array_v1' ) ) {
			self::record( 'migrate_skills_agent_slug_to_array', 'skipped', 'already migrated' );
			return;
		}

		global $wpdb;
		$table   = $wpdb->prefix . 'agentic_skills';
		$updated = 0;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results( "SELECT id, agent_slug FROM {$table} WHERE agent_slug != ''", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is $wpdb->prefix . 'agentic_skills', not user input.

			foreach ( (array) $rows as $row ) {
				$raw = (string) $row['agent_slug'];
				// Already JSON (e.g. re-run after a partial migration, or a
				// row created by newer code before this migration ran) — skip.
				if ( is_array( json_decode( $raw, true ) ) ) {
					continue;
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update(
					$table,
					array( 'agent_slug' => wp_json_encode( array( $raw ) ) ),
					array( 'id' => (int) $row['id'] ),
					array( '%s' ),
					array( '%d' )
				);
				++$updated;
			}
		}

		if ( class_exists( '\Agentic\Skills_Registry' ) ) {
			\Agentic\Skills_Registry::bust_cache();
		}

		update_option( 'agentic_skills_agent_slug_array_v1', true );

		self::record( 'migrate_skills_agent_slug_to_array', 'ok', array( 'updated' => $updated ) );
	}

	/**
	 * Seed core tools and sync agent-contributed tools into the registry.
	 *
	 * @param string $schema_version Current DB_SCHEMA_VERSION.
	 * @return void
	 */
	public static function seed_tools( string $schema_version ): void {
		global $wpdb;

		// Skip if already seeded for this version.
		$seeded_version = get_option( 'agentic_tools_seeded_version', '' );
		if ( $seeded_version === $schema_version ) {
			self::record(
				'seed_tools',
				'skipped',
				array(
					'reason'         => 'already_seeded',
					'schema_version' => $schema_version,
				)
			);
			return;
		}

		// Safety: skip if the tools table doesn't exist yet.
		$table = $wpdb->prefix . 'agentic_tools';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			self::record( 'seed_tools', 'error', 'tools table does not exist — skipping seed' );
			return;
		}

		// Ensure dependencies are loaded (activation fires before init).
		include_once AGENT_BUILDER_DIR . 'includes/class-risk-level.php';
		include_once AGENT_BUILDER_DIR . 'includes/class-tool-base.php';
		include_once AGENT_BUILDER_DIR . 'includes/class-tool-loader.php';
		include_once AGENT_BUILDER_DIR . 'includes/class-tools-registry.php';

		$category_map = array(
			'db_update_option' => 'database',
			'db_create_post'   => 'database',
			'db_update_post'   => 'database',
			'db_delete_post'   => 'database',
			'agents_available' => 'agents',
		);

		$seeded = Tools_Registry::seed_core_tools( $category_map );

		update_option( 'agentic_tools_seeded_version', $schema_version );

		// Sync agent-contributed tools.
		Tool_Loader::get_instance()->sync_to_registry();

		// sync_to_registry() only ever upserts tools still present on disk — it
		// never removes a row for a tool whose files were deleted. run_wp_cli and
		// manage_cli_settings no longer ship in this build at all (removed, not
		// just gated); clean up their now-orphaned rows on every upgrading site
		// so the Tools page never lists a tool with no backing file. Idempotent —
		// a no-op once already cleaned.
		Tools_Registry::unregister( 'run_wp_cli' );
		Tools_Registry::unregister( 'manage_cli_settings' );

		self::record(
			'seed_tools',
			'ok',
			array(
				'seeded'         => $seeded,
				'schema_version' => $schema_version,
			)
		);
	}

	/**
	 * Seed bundled core skills into the database.
	 *
	 * Reads SKILL.md files from library/skills/ and inserts any that are not
	 * already present. Skipped on repeat activations for the same schema version.
	 *
	 * @param string $schema_version Current DB_SCHEMA_VERSION.
	 * @return void
	 */
	public static function seed_skills( string $schema_version ): void {
		global $wpdb;

		// Gated on both the DB schema version and the plugin version: bundled
		// SKILL.md content can change in a content-only release with no
		// schema bump, and seed_core_skills() needs to run then too so
		// unedited core skills pick up the improved wording.
		$seeded_version = get_option( 'agentic_skills_seeded_version', '' );
		$seeded_plugin  = get_option( 'agentic_skills_seeded_plugin_version', '' );
		$plugin_version = defined( 'AGENT_BUILDER_VERSION' ) ? AGENT_BUILDER_VERSION : '';

		if ( $seeded_version === $schema_version && $seeded_plugin === $plugin_version ) {
			self::record(
				'seed_skills',
				'skipped',
				array(
					'reason'         => 'already_seeded',
					'schema_version' => $schema_version,
					'plugin_version' => $plugin_version,
				)
			);
			return;
		}

		$table = $wpdb->prefix . 'agentic_skills';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			self::record( 'seed_skills', 'error', 'skills table does not exist — skipping seed' );
			return;
		}

		include_once AGENT_BUILDER_DIR . 'includes/class-skills-registry.php';

		$result = Skills_Registry::seed_core_skills();

		update_option( 'agentic_skills_seeded_version', $schema_version );
		update_option( 'agentic_skills_seeded_plugin_version', $plugin_version );

		self::record(
			'seed_skills',
			'ok',
			array(
				'seeded'         => $result['seeded'],
				'refreshed'      => $result['refreshed'],
				'schema_version' => $schema_version,
				'plugin_version' => $plugin_version,
			)
		);
	}

	/**
	 * Schedule recurring cron events.
	 *
	 * @return void
	 */
	private static function schedule_cron_events(): void {
		$results = array();

		if ( ! wp_next_scheduled( 'agentic_cleanup_audit_log' ) ) {
			wp_schedule_event( time(), 'daily', 'agentic_cleanup_audit_log' );
			$results['agentic_cleanup_audit_log'] = 'scheduled';
		} else {
			$results['agentic_cleanup_audit_log'] = 'already_scheduled';
		}

		if ( ! wp_next_scheduled( 'agentic_costs_check_alerts' ) ) {
			wp_schedule_event( time(), 'daily', 'agentic_costs_check_alerts' );
			$results['agentic_costs_check_alerts'] = 'scheduled';
		} else {
			$results['agentic_costs_check_alerts'] = 'already_scheduled';
		}

		self::record( 'schedule_cron_events', 'ok', $results );
	}
}
