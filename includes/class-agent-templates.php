<?php
/**
 * Agent Templates — duplicate, export and import agents as local template zips.
 *
 * Duplicating copies an agent (manifest, system prompt, abilities, profile and
 * locally-assigned skills) to a sibling slug. Exporting serialises the same
 * payload into a zip — excluding provider keys/settings/credentials and the
 * site-specific avatar attachment id — under a random, non-guessable on-disk
 * filename in AGENT_BUILDER_EXPORTS_DIR (wp-content/agentic-exports/, a
 * directory sibling of uploads/ that can still sit inside the document root,
 * and whose .htaccess/web.config markers Nginx ignores outright). The archive
 * therefore never leaves the server as a static file: its on-disk name is
 * random (never the agent's slug), and it is read — and deleted the instant it
 * has been read — only by export_for_download(), which is called exclusively
 * from an authenticated, capability- and nonce-gated admin-ajax handler.
 * Importing reverses export(): it validates the
 * archive (including a bound on its extracted size before unpacking it),
 * refuses any abilities that would downgrade a tool below its risk floor, and
 * writes the agent in place (inactive until the owner activates it).
 *
 * All three operate on the *portable* representation of an agent — a
 * declarative agent.json manifest plus templates/system-prompt.txt and
 * abilities.json — so a bundled PHP agent is re-created as a manifest agent,
 * never as copied executable code.
 *
 * Usage:
 *   $slug = Agent_Templates::duplicate( 'content-writer' );      // '…-copy'
 *   $path = Agent_Templates::export( 'content-writer' );         // zip path
 *   $slug = Agent_Templates::import( $_FILES['agent_zip'] );     // new slug
 *
 * @package    Agent_Builder
 * @subpackage Includes
 * @since      4.1.0
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Duplicate/export/import of agents as local, portable templates.
 */
class Agent_Templates {

	/**
	 * Maximum number of entries an import archive may contain. A template
	 * agent is a handful of small text files (agent.json, abilities.json,
	 * system-prompt.txt, profile.json, a few skills/*.SKILL.md) — generous
	 * headroom, still low enough to reject a zip-bomb-style entry flood.
	 */
	private const MAX_IMPORT_ENTRIES = 100;

	/**
	 * Maximum total uncompressed size (bytes) an import archive may expand
	 * to, checked from the zip's own per-entry size table before any bytes
	 * are extracted.
	 */
	private const MAX_IMPORT_UNCOMPRESSED_BYTES = 5 * 1024 * 1024; // 5 MB.

	/**
	 * Duplicate an installed agent to a new sibling slug.
	 *
	 * The copy is written as a manifest agent, re-signed, given the display
	 * name "<name> (copy)", then activated and integrity-checked.
	 *
	 * @param string $slug Source agent slug.
	 * @return string|\WP_Error New agent slug, or error.
	 */
	public static function duplicate( string $slug ) {
		$payload = self::payload( $slug );
		if ( null === $payload ) {
			return new \WP_Error( 'agent_not_found', __( 'Agent not found.', 'agent-builder' ) );
		}

		$new_slug     = self::unique_copy_slug( $slug );
		$display_name = Agent_Profile::get( $slug )['display_name'];

		$manifest         = $payload['manifest'];
		$manifest['slug'] = $new_slug;
		$manifest['name'] = $display_name . ' (copy)';

		$written = self::write_agent( $new_slug, $manifest, $payload['system_prompt'], $payload['abilities'] );
		if ( is_wp_error( $written ) ) {
			return $written;
		}

		// Copy the source's settings wholesale, then pin the copy's display name.
		foreach ( Agent_Settings::get_all( $slug ) as $key => $value ) {
			Agent_Settings::update( $new_slug, (string) $key, (string) $value );
		}
		Agent_Profile::save( $new_slug, array( 'profile_display_name' => $display_name . ' (copy)' ) );

		self::copy_skills( $new_slug, $payload['skills'] );

		$registry = \Agentic_Agent_Registry::get_instance();
		$registry->get_installed_agents( true );
		$activated = $registry->activate_agent( $new_slug );
		if ( is_wp_error( $activated ) ) {
			return $activated;
		}

		if ( ! Abilities_Manifest::verify_integrity( $new_slug ) ) {
			return new \WP_Error( 'integrity_failed', __( 'The duplicated agent failed its integrity check.', 'agent-builder' ) );
		}

		return $new_slug;
	}

	/**
	 * Export an agent to a local template zip.
	 *
	 * The zip holds agent.json, abilities.json, system-prompt.txt, profile.json
	 * and any locally-assigned skills under skills/*.SKILL.md. It never contains
	 * provider keys, settings, credentials, the integrity signature, or the
	 * site-specific avatar attachment id.
	 *
	 * The returned path uses a random on-disk filename, not the agent's slug:
	 * this is a transient working file, never a stable download URL — see
	 * export_for_download() and the class docblock for how it actually
	 * reaches an admin.
	 *
	 * @param string $slug Agent slug.
	 * @return string|\WP_Error Absolute path to the zip, or error.
	 */
	public static function export( string $slug ) {
		$payload = self::payload( $slug );
		if ( null === $payload ) {
			return new \WP_Error( 'agent_not_found', __( 'Agent not found.', 'agent-builder' ) );
		}

		if ( ! class_exists( 'ZipArchive' ) ) {
			return new \WP_Error( 'zip_unavailable', __( 'The ZipArchive extension is required to export agents.', 'agent-builder' ) );
		}

		$exports_dir = untrailingslashit( AGENT_BUILDER_EXPORTS_DIR );
		if ( ! File_Manager::ensure_protected_dir( $exports_dir ) ) {
			return new \WP_Error( 'mkdir_failed', __( 'Could not create the export directory.', 'agent-builder' ) );
		}

		$zip_path = $exports_dir . '/' . wp_generate_password( 32, false ) . '.zip';

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $zip_path, \ZipArchive::CREATE ) ) {
			return new \WP_Error( 'zip_failed', __( 'Could not create the export archive.', 'agent-builder' ) );
		}

		$manifest = $payload['manifest'];
		unset( $manifest['system_prompt'] ); // Carried by system-prompt.txt instead.

		$abilities = $payload['abilities'];
		if ( ! is_array( $abilities ) ) {
			// A DB-backed manifest agent has no on-disk abilities.json
			// (Abilities_Manifest::load() only reads files, never the library
			// table). Synthesize a valid manifest from the agent's declared
			// tools at their risk floors so the export round-trips through
			// import(), which requires abilities.json.
			$abilities = self::synthesize_abilities( (array) ( $manifest['tools'] ?? array() ) );
		}

		$zip->addFromString( 'agent.json', (string) wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );

		if ( '' !== $payload['system_prompt'] ) {
			$zip->addFromString( 'system-prompt.txt', $payload['system_prompt'] );
		}

		$zip->addFromString( 'abilities.json', (string) wp_json_encode( $abilities, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );

		if ( ! empty( $payload['profile'] ) ) {
			$zip->addFromString( 'profile.json', (string) wp_json_encode( $payload['profile'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		}

		foreach ( $payload['skills'] as $skill ) {
			$name = sanitize_file_name( (string) ( $skill['slug'] ?? 'skill' ) );
			$zip->addFromString( 'skills/' . $name . '.SKILL.md', (string) ( $skill['content'] ?? '' ) );
		}

		if ( ! $zip->close() ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Cleanup; failure is non-fatal.
			@wp_delete_file( $zip_path );
			return new \WP_Error( 'zip_failed', __( 'Could not finalize the export archive.', 'agent-builder' ) );
		}

		return $zip_path;
	}

	/**
	 * Export an agent and return its zip bytes for an authenticated caller
	 * to hand back to the browser — the sole path an export ever leaves the
	 * server by (see the class docblock). The on-disk file export() wrote is
	 * deleted before this returns, whether or not it could be read, so a
	 * stale/uncleaned export is never left sitting at a discoverable path.
	 *
	 * @param string $slug Agent slug.
	 * @return array{filename: string, content: string}|\WP_Error
	 */
	public static function export_for_download( string $slug ) {
		$zip_path = self::export( $slug );
		if ( is_wp_error( $zip_path ) ) {
			return $zip_path;
		}

		$content = File_Manager::get_contents( $zip_path );
		wp_delete_file( $zip_path );

		if ( false === $content ) {
			return new \WP_Error( 'read_failed', __( 'Could not read the export archive.', 'agent-builder' ) );
		}

		return array(
			'filename' => $slug . '.zip',
			'content'  => $content,
		);
	}

	/**
	 * Import an agent from an uploaded template zip.
	 *
	 * Accepts only zip uploads, validates the manifest, refuses ability risk
	 * downgrades, resolves slug collisions, writes the agent, applies any
	 * profile.json, and imports locally-assigned skills. The agent is returned
	 * inactive — activation is the site owner's decision.
	 *
	 * @param array<string, mixed> $file A single $_FILES entry (name, type, tmp_name, …).
	 * @return string|\WP_Error New agent slug, or error.
	 */
	public static function import( array $file ) {
		if ( empty( $file['tmp_name'] ) || empty( $file['name'] ) ) {
			return new \WP_Error( 'invalid_upload', __( 'No file was uploaded.', 'agent-builder' ) );
		}

		$overrides = array(
			'test_form' => false,
			'mimes'     => array( 'zip' => 'application/zip' ),
		);

		$moved = wp_handle_sideload( $file, $overrides );
		if ( isset( $moved['error'] ) ) {
			return new \WP_Error( 'upload_error', $moved['error'] );
		}
		$zip_path = $moved['file'];

		$tmp_dir = self::unzip_to_temp( $zip_path );
		@wp_delete_file( $zip_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Cleanup; failure is non-fatal.
		if ( is_wp_error( $tmp_dir ) ) {
			return $tmp_dir;
		}

		$manifest = self::read_manifest( $tmp_dir );
		if ( is_wp_error( $manifest ) ) {
			self::cleanup_dir( $tmp_dir );
			return $manifest;
		}

		$abilities = self::read_abilities( $tmp_dir );
		if ( is_wp_error( $abilities ) ) {
			self::cleanup_dir( $tmp_dir );
			return $abilities;
		}

		$slug             = self::unique_slug( (string) $manifest['slug'] );
		$manifest['slug'] = $slug;

		$written = self::write_agent( $slug, $manifest, self::read_system_prompt( $tmp_dir, $manifest ), $abilities );
		if ( is_wp_error( $written ) ) {
			self::cleanup_dir( $tmp_dir );
			return $written;
		}

		// Force-refresh so a caller that immediately activates $slug in the
		// same request doesn't hit a stale pre-import cache (duplicate()
		// already does this after its own write_agent() call).
		\Agentic_Agent_Registry::get_instance()->get_installed_agents( true );

		self::apply_profile( $slug, $tmp_dir );
		self::import_skills( $slug, $tmp_dir );

		self::cleanup_dir( $tmp_dir );

		return $slug;
	}

	// -------------------------------------------------------------------------
	// Payload extraction
	// -------------------------------------------------------------------------

	/**
	 * Extract the portable representation of an installed agent.
	 *
	 * @param string $slug Agent slug.
	 * @return array<string, mixed>|null {
	 *     @type array<string, mixed>  $manifest       Validated manifest.
	 *     @type array<string, mixed>|null $abilities   Decoded abilities.json, or null.
	 *     @type string                $system_prompt  Resolved system prompt text.
	 *     @type array<string, string> $profile        Set profile override keys.
	 *     @type array<int, array<string, mixed>> $skills Locally-assigned skills.
	 * }
	 */
	private static function payload( string $slug ): ?array {
		$registry = \Agentic_Agent_Registry::get_instance();
		$agents   = $registry->get_installed_agents( true );
		if ( ! isset( $agents[ $slug ] ) ) {
			return null;
		}
		$info = $agents[ $slug ];

		$manifest = null;
		if ( ! empty( $info['db_manifest'] ) && is_array( $info['db_manifest'] ) ) {
			$manifest = $info['db_manifest'];
		} elseif ( ! empty( $info['directory'] ) && file_exists( $info['directory'] . '/agent.json' ) ) {
			$manifest = Agent_Manifest_Validator::from_file( $info['directory'] . '/agent.json' );
		}
		if ( null === $manifest ) {
			$manifest = self::synthesize_manifest( $slug, $info );
		}

		return array(
			'manifest'      => $manifest,
			'abilities'     => Abilities_Manifest::load( $slug ),
			'system_prompt' => self::system_prompt_for( $info, $manifest ),
			'profile'       => Agent_Profile::export_fields( $slug ),
			'skills'        => self::skills_for( $slug ),
		);
	}

	/**
	 * Build a declarative manifest from registry metadata, for agents (bundled
	 * PHP agents) that carry no agent.json of their own.
	 *
	 * @param string               $slug Agent slug.
	 * @param array<string, mixed> $info Installed-agent record.
	 * @return array<string, mixed>
	 */
	private static function synthesize_manifest( string $slug, array $info ): array {
		return array(
			'schema'            => Agent_Manifest_Validator::SCHEMA_VERSION,
			'slug'              => $slug,
			'name'              => (string) ( $info['name'] ?? $slug ),
			'description'       => (string) ( $info['description'] ?? '' ),
			'category'          => (string) ( $info['category'] ?? 'admin' ),
			'icon'              => (string) ( $info['icon'] ?? '🤖' ),
			'version'           => (string) ( $info['version'] ?? '1.0.0' ),
			'author'            => (string) ( $info['author'] ?? '' ),
			'capabilities'      => is_array( $info['capabilities'] ?? null ) ? $info['capabilities'] : array( 'read' ),
			'tools'             => Abilities_Manifest::get_declared_tools( $slug ),
			'suggested_prompts' => array(),
			'team'              => false,
		);
	}

	/**
	 * Build a valid abilities manifest for an agent that has no on-disk
	 * abilities.json (a DB-backed manifest agent), declaring each of its tools
	 * at that tool's risk floor. A floor declaration is the lowest-risk
	 * statement that is still valid, so the synthesized manifest is both
	 * importable (import() requires abilities.json) and never a downgrade.
	 *
	 * @param string[] $tools Tool names declared by the agent.
	 * @return array<string, mixed>
	 */
	private static function synthesize_abilities( array $tools ): array {
		$abilities = array(
			'version'   => '1.0',
			'abilities' => array(),
		);
		foreach ( $tools as $tool ) {
			$tool = (string) $tool;
			if ( '' === $tool ) {
				continue;
			}
			$abilities['abilities'][ $tool ] = array(
				'risk' => Risk_Level::get_tool_default( $tool ),
			);
		}
		return $abilities;
	}

	/**
	 * Resolve an agent's system prompt (file first, then inline manifest field).
	 *
	 * @param array<string, mixed> $info     Installed-agent record.
	 * @param array<string, mixed> $manifest Validated manifest.
	 * @return string
	 */
	private static function system_prompt_for( array $info, array $manifest ): string {
		if ( ! empty( $info['directory'] ) ) {
			$file = trailingslashit( $info['directory'] ) . 'templates/system-prompt.txt';
			if ( file_exists( $file ) ) {
				$contents = File_Manager::get_contents( $file );
				if ( false !== $contents ) {
					return $contents;
				}
			}
		}
		return (string) ( $manifest['system_prompt'] ?? '' );
	}

	/**
	 * Skills assigned to a specific agent (global skills are excluded — they
	 * are already available to every agent, so they need no copying).
	 *
	 * @param string $slug Agent slug.
	 * @return array<int, array<string, mixed>>
	 */
	private static function skills_for( string $slug ): array {
		if ( ! class_exists( Skills_Registry::class ) ) {
			return array();
		}
		return array_values(
			array_filter(
				Skills_Registry::get_all(),
				static fn( array $s ): bool => in_array( $slug, Skills_Registry::decode_agent_slugs( (string) ( $s['agent_slug'] ?? '' ) ), true )
			)
		);
	}

	// -------------------------------------------------------------------------
	// Writing
	// -------------------------------------------------------------------------

	/**
	 * Write a manifest agent (agent.json + templates/system-prompt.txt +
	 * abilities.json with signature) into the user agents directory.
	 *
	 * import()/duplicate() pick $slug via unique_slug()/unique_copy_slug(),
	 * whose collision check is not atomic with this write, so a concurrent
	 * caller can win the same slug first. Every failure branch below removes
	 * $agent_dir again — that is only safe because the mkdir() below is
	 * itself an atomic create: if $agent_dir already exists (another writer
	 * got there first, or a previous attempt left it behind), this call
	 * bails immediately instead of writing into, and potentially later
	 * deleting, a directory it does not own.
	 *
	 * @param string                    $slug          New agent slug.
	 * @param array<string, mixed>      $manifest      Manifest (slug already set).
	 * @param string                    $system_prompt System prompt text.
	 * @param array<string, mixed>|null $abilities     Decoded abilities.json, or null.
	 * @return true|\WP_Error
	 */
	private static function write_agent( string $slug, array $manifest, string $system_prompt, ?array $abilities ) {
		if ( ! wp_mkdir_p( AGENT_BUILDER_AGENTS_DIR ) ) {
			return new \WP_Error( 'mkdir_failed', __( 'Could not create the agents directory.', 'agent-builder' ) );
		}

		$agent_dir = AGENT_BUILDER_AGENTS_DIR . '/' . $slug;

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Deliberate: a plain mkdir() is an atomic create-or-fail, unlike wp_mkdir_p()'s "succeed either way"; its EEXIST warning is the race signal handled below.
		$created = @mkdir( $agent_dir, self::agent_dir_mode() );
		if ( ! $created ) {
			// If the directory now exists, the mkdir failed because another
			// writer won the race (or a previous attempt left it behind) — a
			// real collision. Otherwise the failure is something else
			// (permissions, a missing parent, …) and must not be reported as a
			// taken slug, which would silently suppress the real error.
			if ( is_dir( $agent_dir ) ) {
				return new \WP_Error( 'slug_taken', __( 'Another request is already writing an agent with this slug.', 'agent-builder' ) );
			}
			return new \WP_Error( 'mkdir_failed', __( 'Could not create the agent directory.', 'agent-builder' ) );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- mkdir()'s mode argument is still subject to umask; pin it explicitly, same as File_Manager::mkdir().
		chmod( $agent_dir, self::agent_dir_mode() );

		unset( $manifest['system_prompt'] ); // Written to its own file below.
		$manifest_json = wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $manifest_json ) {
			File_Manager::rmdir( $agent_dir, true );
			return new \WP_Error( 'encode_failed', __( 'Could not encode the agent manifest.', 'agent-builder' ) );
		}
		if ( ! File_Manager::put_contents( $agent_dir . '/agent.json', $manifest_json ) ) {
			File_Manager::rmdir( $agent_dir, true );
			return new \WP_Error( 'write_failed', __( 'Could not write agent.json.', 'agent-builder' ) );
		}

		if ( '' !== $system_prompt ) {
			if ( ! wp_mkdir_p( $agent_dir . '/templates' ) ) {
				File_Manager::rmdir( $agent_dir, true );
				return new \WP_Error( 'mkdir_failed', __( 'Could not create the agent templates directory.', 'agent-builder' ) );
			}
			if ( ! File_Manager::put_contents( $agent_dir . '/templates/system-prompt.txt', $system_prompt ) ) {
				File_Manager::rmdir( $agent_dir, true );
				return new \WP_Error( 'write_failed', __( 'Could not write the system prompt.', 'agent-builder' ) );
			}
		}

		if ( is_array( $abilities ) ) {
			$abilities['agent'] = $slug;
			if ( ! Abilities_Manifest::write_manifest( $agent_dir, $slug, $abilities ) ) {
				File_Manager::rmdir( $agent_dir, true );
				return new \WP_Error( 'write_failed', __( 'Could not write abilities.json.', 'agent-builder' ) );
			}
		}

		return true;
	}

	/**
	 * Directory permission mode for a freshly-created agent directory,
	 * matching what wp_mkdir_p()/File_Manager::mkdir() would have applied.
	 *
	 * @return int
	 */
	private static function agent_dir_mode(): int {
		return defined( 'FS_CHMOD_DIR' ) ? FS_CHMOD_DIR : 0755;
	}

	/**
	 * Copy locally-assigned skills to a new agent slug.
	 *
	 * @param string                     $slug   New agent slug.
	 * @param array<int, array<string, mixed>> $skills Source skills.
	 * @return void
	 */
	private static function copy_skills( string $slug, array $skills ): void {
		if ( ! class_exists( Skills_Registry::class ) ) {
			return;
		}
		foreach ( $skills as $skill ) {
			Skills_Registry::create(
				array(
					'name'        => (string) ( $skill['name'] ?? $skill['slug'] ?? 'Skill' ),
					'description' => (string) ( $skill['description'] ?? '' ),
					'content'     => (string) ( $skill['content'] ?? '' ),
					'agent_slug'  => array( $slug ),
					'source'      => 'local',
					'source_id'   => '',
					'version'     => (string) ( $skill['version'] ?? '1.0.0' ),
					'author'      => (string) ( $skill['author'] ?? '' ),
					'enabled'     => ! empty( $skill['enabled'] ),
				)
			);
		}
	}

	// -------------------------------------------------------------------------
	// Import helpers
	// -------------------------------------------------------------------------

	/**
	 * Unzip an uploaded archive into a unique temporary directory.
	 *
	 * @param string $zip_path Absolute path to the zip.
	 * @return string|\WP_Error Temp directory path, or error.
	 */
	private static function unzip_to_temp( string $zip_path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new \WP_Error( 'zip_unavailable', __( 'The ZipArchive extension is required to import agents.', 'agent-builder' ) );
		}

		$bounds_error = self::check_archive_bounds( $zip_path );
		if ( is_wp_error( $bounds_error ) ) {
			return $bounds_error;
		}

		if ( ! function_exists( 'unzip_file' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		global $wp_filesystem;
		if ( empty( $wp_filesystem ) ) {
			WP_Filesystem();
		}

		$tmp_dir = trailingslashit( get_temp_dir() ) . 'agentic-import-' . wp_generate_password( 12, false );
		if ( ! wp_mkdir_p( $tmp_dir ) ) {
			return new \WP_Error( 'mkdir_failed', __( 'Could not create a temporary directory.', 'agent-builder' ) );
		}

		$result = unzip_file( $zip_path, $tmp_dir );
		if ( is_wp_error( $result ) ) {
			self::cleanup_dir( $tmp_dir );
			return $result;
		}

		return $tmp_dir;
	}

	/**
	 * Reject an oversized or entry-flooded archive before any bytes are
	 * extracted, using the zip's own entry-count and per-entry size table
	 * (both available from ZipArchive without decompressing anything).
	 *
	 * @param string $zip_path Absolute path to the zip.
	 * @return true|\WP_Error
	 */
	private static function check_archive_bounds( string $zip_path ) {
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			return new \WP_Error( 'zip_failed', __( 'Could not open the archive.', 'agent-builder' ) );
		}

		$count = $zip->numFiles; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Native ZipArchive property name.
		if ( $count > self::MAX_IMPORT_ENTRIES ) {
			$zip->close();
			return new \WP_Error( 'zip_too_many_entries', __( 'The archive has too many entries.', 'agent-builder' ) );
		}

		$total = 0;
		for ( $i = 0; $i < $count; $i++ ) {
			$stat = $zip->statIndex( $i );
			if ( false === $stat ) {
				continue;
			}
			$total += (int) ( $stat['size'] ?? 0 );
			if ( $total > self::MAX_IMPORT_UNCOMPRESSED_BYTES ) {
				$zip->close();
				return new \WP_Error( 'zip_too_large', __( 'The archive expands to more data than allowed.', 'agent-builder' ) );
			}
		}

		$zip->close();
		return true;
	}

	/**
	 * Read and validate agent.json from an unpacked archive.
	 *
	 * @param string $tmp_dir Unpacked archive directory.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function read_manifest( string $tmp_dir ) {
		$agent_json = $tmp_dir . '/agent.json';
		if ( ! file_exists( $agent_json ) ) {
			return new \WP_Error( 'missing_agent', __( 'The archive is missing agent.json.', 'agent-builder' ) );
		}
		$raw = json_decode( (string) File_Manager::get_contents( $agent_json ), true );
		if ( ! is_array( $raw ) ) {
			return new \WP_Error( 'invalid_agent', __( 'agent.json is not valid JSON.', 'agent-builder' ) );
		}
		$manifest = Agent_Manifest_Validator::validate( $raw );
		if ( is_wp_error( $manifest ) ) {
			return $manifest;
		}
		return $manifest;
	}

	/**
	 * Read and risk-check abilities.json from an unpacked archive.
	 *
	 * @param string $tmp_dir Unpacked archive directory.
	 * @return array<string, mixed>|\WP_Error Decoded abilities, or error.
	 */
	private static function read_abilities( string $tmp_dir ) {
		$abilities_json = $tmp_dir . '/abilities.json';
		if ( ! file_exists( $abilities_json ) ) {
			// Agentic_Agent_Registry::activate_agent() refuses to activate an
			// agent with no abilities manifest, so accepting the import here
			// would only hand back a slug that can never be activated.
			return new \WP_Error( 'missing_abilities', __( 'The archive is missing abilities.json.', 'agent-builder' ) );
		}
		$raw = json_decode( (string) File_Manager::get_contents( $abilities_json ), true );
		if ( ! is_array( $raw ) ) {
			return new \WP_Error( 'invalid_abilities', __( 'abilities.json is not valid JSON.', 'agent-builder' ) );
		}

		$downgrades = self::risk_downgrade_errors( $raw );
		if ( ! empty( $downgrades ) ) {
			return new \WP_Error(
				'risk_downgrade',
				__( 'The archive declares invalid or under-declared tool risk:', 'agent-builder' ) . ' ' . implode( '; ', $downgrades )
			);
		}

		return $raw;
	}

	/**
	 * Read the unpacked system prompt (file wins over the inline manifest field).
	 *
	 * @param string               $tmp_dir  Unpacked archive directory.
	 * @param array<string, mixed> $manifest Validated manifest.
	 * @return string
	 */
	private static function read_system_prompt( string $tmp_dir, array $manifest ): string {
		$file = $tmp_dir . '/system-prompt.txt';
		if ( file_exists( $file ) ) {
			$contents = File_Manager::get_contents( $file );
			if ( false !== $contents ) {
				return $contents;
			}
		}
		return (string) ( $manifest['system_prompt'] ?? '' );
	}

	/**
	 * Apply an unpacked profile.json to the imported agent.
	 *
	 * @param string $slug    Imported agent slug.
	 * @param string $tmp_dir Unpacked archive directory.
	 * @return void
	 */
	private static function apply_profile( string $slug, string $tmp_dir ): void {
		$file = $tmp_dir . '/profile.json';
		if ( ! file_exists( $file ) ) {
			return;
		}
		$profile = json_decode( (string) File_Manager::get_contents( $file ), true );
		if ( is_array( $profile ) ) {
			Agent_Profile::save( $slug, $profile );
		}
	}

	/**
	 * Import locally-assigned skills from skills/*.SKILL.md.
	 *
	 * @param string $slug    Imported agent slug.
	 * @param string $tmp_dir Unpacked archive directory.
	 * @return void
	 */
	private static function import_skills( string $slug, string $tmp_dir ): void {
		if ( ! class_exists( Skills_Registry::class ) ) {
			return;
		}
		$skills_dir = $tmp_dir . '/skills';
		if ( ! is_dir( $skills_dir ) ) {
			return;
		}

		$files = glob( $skills_dir . '/*.SKILL.md' );
		foreach ( ( $files ? $files : array() ) as $file ) {
			$content = File_Manager::get_contents( $file );
			if ( false === $content || '' === $content ) {
				continue;
			}
			$identity = Skills_Registry::parse_front_matter_identity( $content );
			$base     = basename( $file, '.SKILL.md' );
			$name     = '' !== $identity['name'] ? $identity['name'] : $base;

			Skills_Registry::create(
				array(
					'name'        => $name,
					'description' => $identity['description'],
					'content'     => $content,
					'agent_slug'  => array( $slug ),
					'source'      => 'local',
					'source_id'   => '',
					'version'     => '1.0.0',
					'author'      => '',
					'enabled'     => true,
				)
			);
		}
	}

	/**
	 * List ability declarations that would lower a tool below its risk floor.
	 *
	 * @param array<string, mixed> $abilities Decoded abilities.json.
	 * @return string[]
	 */
	private static function risk_downgrade_errors( array $abilities ): array {
		$errors = array();
		$map    = $abilities['abilities'] ?? array();
		if ( ! is_array( $map ) ) {
			return $errors;
		}
		foreach ( $map as $tool => $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['risk'] ) ) {
				// An entry with no risk field would otherwise import as
				// "fine" and then fail at activation for the same reason —
				// flag it here instead, same as an explicitly-invalid risk.
				$errors[] = sprintf( /* translators: %s: tool name */ __( '%s has no risk declared', 'agent-builder' ), (string) $tool );
				continue;
			}
			$declared = (string) $entry['risk'];
			if ( ! Risk_Level::is_valid( $declared ) ) {
				$errors[] = sprintf( /* translators: 1: tool name, 2: risk */ __( '%1$s has invalid risk %2$s', 'agent-builder' ), (string) $tool, $declared );
				continue;
			}
			$floor = Risk_Level::get_tool_default( (string) $tool );
			if ( Risk_Level::weight( $declared ) < Risk_Level::weight( $floor ) ) {
				$errors[] = sprintf( /* translators: 1: tool name, 2: declared risk, 3: floor risk */ __( '%1$s declares %2$s below its minimum %3$s', 'agent-builder' ), (string) $tool, $declared, $floor );
			}
		}
		return $errors;
	}

	// -------------------------------------------------------------------------
	// Slug resolution
	// -------------------------------------------------------------------------

	/**
	 * Resolve a unique copy slug of the form "<slug>-copy[-N]".
	 *
	 * @param string $slug Source slug.
	 * @return string
	 */
	private static function unique_copy_slug( string $slug ): string {
		$base = $slug . '-copy';
		if ( ! self::slug_taken( $base ) ) {
			return $base;
		}
		$i = 2;
		while ( self::slug_taken( $base . '-' . $i ) ) {
			++$i;
		}
		return $base . '-' . $i;
	}

	/**
	 * Resolve a unique import slug of the form "<slug>[-N]".
	 *
	 * @param string $slug Desired slug.
	 * @return string
	 */
	private static function unique_slug( string $slug ): string {
		if ( ! self::slug_taken( $slug ) ) {
			return $slug;
		}
		$i = 2;
		while ( self::slug_taken( $slug . '-' . $i ) ) {
			++$i;
		}
		return $slug . '-' . $i;
	}

	/**
	 * Whether a slug is already taken by an installed agent or directory.
	 *
	 * Agentic_Agent_Registry::is_agent_installed() only checks the writable
	 * agents directory and bundled library directories — it never queries
	 * the agent_builder_agent_library table, so a DB-backed (purchased or
	 * Assistant-Trainer-built) agent would otherwise be wrongly treated as
	 * free and silently shadowed. get_installed_agents() merges those rows
	 * in, so it is checked here too.
	 *
	 * @param string $slug Agent slug.
	 * @return bool
	 */
	private static function slug_taken( string $slug ): bool {
		$registry = \Agentic_Agent_Registry::get_instance();
		if ( $registry->is_agent_installed( $slug ) ) {
			return true;
		}
		if ( is_dir( AGENT_BUILDER_AGENTS_DIR . '/' . $slug ) ) {
			return true;
		}
		return isset( $registry->get_installed_agents( true )[ $slug ] );
	}

	/**
	 * Recursively delete a directory (used for the import temp dir).
	 *
	 * @param string $dir Directory path.
	 * @return void
	 */
	private static function cleanup_dir( string $dir ): void {
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return;
		}
		File_Manager::rmdir( $dir, true );
	}
}
