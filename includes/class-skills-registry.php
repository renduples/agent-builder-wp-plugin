<?php
/**
 * Skills Registry — database-backed CRUD for agent skills.
 *
 * Skills contain SKILL.md instructions that teach agents when and how
 * to use channel-specific tools. They live above channels and below agents
 * in the stack: agent → skill → channel → tool.
 *
 * @package    Agent_Builder
 * @subpackage Includes
 * @since      2.5.2
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Skills_Registry
 *
 * @since 2.5.2
 */
final class Skills_Registry {

	/**
	 * Runtime cache (null = not yet loaded).
	 *
	 * @var array<int, array<string, mixed>>|null
	 */
	private static ?array $cache = null;

	// ── Cache ─────────────────────────────────────────────────────────────

	/**
	 * Clear both static and persistent caches.
	 *
	 * @return void
	 */
	public static function bust_cache(): void {
		self::$cache = null;
		wp_cache_delete( 'agentic_skills_all', 'agentic' );
	}

	// ── Agent scoping ────────────────────────────────────────────────────────

	/**
	 * Decode a stored `agent_slug` value into a list of agent slugs.
	 *
	 * A skill scoped to every agent stores '' — callers should check for that
	 * separately (an empty array here would incorrectly read as "no agents").
	 * Also tolerates a legacy pre-3.3.90 row that still holds one bare slug
	 * (not JSON), so an un-migrated or hand-written row degrades gracefully
	 * instead of matching nothing.
	 *
	 * @param string $raw Raw `agent_slug` column value.
	 * @return string[] Agent slugs this skill is scoped to.
	 */
	public static function decode_agent_slugs( string $raw ): array {
		if ( '' === $raw ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		if ( is_array( $decoded ) ) {
			return array_values( array_filter( array_map( 'strval', $decoded ), fn( $s ) => '' !== $s ) );
		}
		return array( $raw );
	}

	/**
	 * Normalize a caller-supplied agent scope (array of slugs, a single slug
	 * string, already-encoded JSON, or empty) into the JSON string stored in
	 * `agent_slug`. Each slug is sanitized individually — sanitize_key() on
	 * the whole value would destroy JSON's punctuation.
	 *
	 * @param array<int, string>|string|null $raw Caller-supplied scope.
	 * @return string JSON-encoded slug list, or '' for "every agent".
	 */
	private static function normalize_agent_slugs( $raw ): string {
		if ( is_array( $raw ) ) {
			$slugs = $raw;
		} elseif ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			$slugs   = is_array( $decoded ) ? $decoded : array( $raw );
		} else {
			$slugs = array();
		}

		$slugs = array_values( array_unique( array_filter( array_map( 'sanitize_key', $slugs ) ) ) );

		return empty( $slugs ) ? '' : (string) wp_json_encode( $slugs );
	}

	// ── Read ──────────────────────────────────────────────────────────────

	/**
	 * Get all skills from the database.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_all(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$cached = wp_cache_get( 'agentic_skills_all', 'agentic' );
		if ( is_array( $cached ) ) {
			self::$cache = $cached;
			return self::$cache;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'agent_builder_skills';

		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table, manual cache.
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY name ASC", ARRAY_A );

		self::$cache = is_array( $rows ) ? $rows : array();

		wp_cache_set( 'agentic_skills_all', self::$cache, 'agentic', HOUR_IN_SECONDS );

		return self::$cache;
	}

	/**
	 * Get a single skill by ID.
	 *
	 * @param int $id Skill ID.
	 * @return array<string, mixed>|null
	 */
	public static function get( int $id ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . 'agent_builder_skills';

		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Get a single skill by slug.
	 *
	 * @param string $slug Skill slug.
	 * @return array<string, mixed>|null
	 */
	public static function get_by_slug( string $slug ): ?array {
		foreach ( self::get_all() as $skill ) {
			if ( $slug === $skill['slug'] ) {
				return $skill;
			}
		}
		return null;
	}

	/**
	 * Get skills assigned to a specific agent.
	 *
	 * @param string $agent_slug Agent slug.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_for_agent( string $agent_slug ): array {
		return array_values(
			array_filter(
				self::get_all(),
				static fn( array $s ): bool => '1' === ( $s['enabled'] ?? '0' )
					&& ( '' === $s['agent_slug']
						|| in_array( $agent_slug, self::decode_agent_slugs( (string) $s['agent_slug'] ), true ) )
			)
		);
	}

	/**
	 * Compact skills index for progressive disclosure, mirroring
	 * Okf_Store::prompt_index_block(). Only name + description (the
	 * agentskills.io spec's "metadata" tier, ~100 tokens each) are injected
	 * into every prompt; the full SKILL.md body is loaded on demand via the
	 * load_skill tool once the agent recognises a matching task, so an
	 * agent with many enabled skills does not carry all of their bodies in
	 * context on every turn.
	 *
	 * @param string $agent_slug Agent identifier.
	 * @param int    $max_chars  Hard cap on the returned block's length.
	 * @return string
	 */
	public static function prompt_index_block( string $agent_slug, int $max_chars = 3000 ): string {
		$skills = self::get_for_agent( $agent_slug );
		if ( empty( $skills ) ) {
			return '';
		}

		$lines = array();
		foreach ( $skills as $skill ) {
			$desc    = (string) ( $skill['description'] ?? '' );
			$lines[] = sprintf(
				'- %s — %s [slug: %s]',
				(string) ( $skill['name'] ?? $skill['slug'] ),
				'' !== $desc ? $desc : __( 'No description', 'agent-builder' ),
				(string) ( $skill['slug'] ?? '' )
			);
		}

		$block = "\n\n[SKILLS]\n"
			. __( 'The entries below are titles only. When a request matches one, call load_skill with its [slug] to load the full instructions before acting — do not guess at a workflow from the description alone. Skip skills that clearly do not apply.', 'agent-builder' )
			. "\n"
			. implode( "\n", $lines )
			. "\n";

		if ( strlen( $block ) > $max_chars ) {
			$block = substr( $block, 0, $max_chars - 20 ) . "\n…\n";
		}

		return $block;
	}

	/**
	 * Count skills grouped by source.
	 *
	 * @return array<string, int>
	 */
	public static function count_by_source(): array {
		$counts = array();
		foreach ( self::get_all() as $skill ) {
			$src            = $skill['source'] ?? 'local';
			$counts[ $src ] = ( $counts[ $src ] ?? 0 ) + 1;
		}
		return $counts;
	}

	// ── Write ─────────────────────────────────────────────────────────────

	/**
	 * Create a new skill.
	 *
	 * @param array<string, mixed> $data Skill data.
	 * @return int|false Inserted row ID or false on failure.
	 */
	public static function create( array $data ) {
		global $wpdb;
		$table = $wpdb->prefix . 'agent_builder_skills';
		$now   = current_time( 'mysql', true );

		$slug = sanitize_title( $data['name'] ?? 'skill' );

		// Ensure unique slug.
		$existing = self::get_by_slug( $slug );
		if ( $existing ) {
			$slug .= '-' . wp_generate_password( 4, false );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table insert.
		$result = $wpdb->insert(
			$table,
			array(
				'name'        => sanitize_text_field( $data['name'] ?? '' ),
				'slug'        => $slug,
				'description' => sanitize_text_field( $data['description'] ?? '' ),
				'content'     => $data['content'] ?? '',
				'agent_slug'  => self::normalize_agent_slugs( $data['agent_slug'] ?? '' ),
				'source'      => sanitize_key( $data['source'] ?? 'local' ),
				'source_id'   => sanitize_text_field( $data['source_id'] ?? '' ),
				'version'     => sanitize_text_field( $data['version'] ?? '1.0.0' ),
				'author'      => sanitize_text_field( $data['author'] ?? '' ),
				'source_hash' => sanitize_text_field( $data['source_hash'] ?? '' ),
				'enabled'     => ! empty( $data['enabled'] ) ? 1 : 0,
				'created_at'  => $now,
				'updated_at'  => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		if ( false === $result ) {
			return false;
		}

		self::bust_cache();
		return (int) $wpdb->insert_id;
	}

	/**
	 * Update an existing skill.
	 *
	 * @param int                  $id   Skill ID.
	 * @param array<string, mixed> $data Fields to update.
	 * @return bool
	 */
	public static function update( int $id, array $data ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'agent_builder_skills';

		$fields = array();
		$format = array();

		$allowed = array(
			'name'        => '%s',
			'description' => '%s',
			'content'     => '%s',
			'agent_slug'  => '%s',
			'version'     => '%s',
			'author'      => '%s',
			'source_hash' => '%s',
			'enabled'     => '%d',
		);

		foreach ( $allowed as $key => $fmt ) {
			if ( array_key_exists( $key, $data ) ) {
				if ( 'content' === $key ) {
					$fields[ $key ] = $data[ $key ];
				} elseif ( 'enabled' === $key ) {
					$fields[ $key ] = ! empty( $data[ $key ] ) ? 1 : 0;
				} elseif ( 'agent_slug' === $key ) {
					$fields[ $key ] = self::normalize_agent_slugs( $data[ $key ] );
				} else {
					$fields[ $key ] = sanitize_text_field( $data[ $key ] );
				}
				$format[] = $fmt;
			}
		}

		if ( empty( $fields ) ) {
			return false;
		}

		$fields['updated_at'] = current_time( 'mysql', true );
		$format[]             = '%s';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table update.
		$result = $wpdb->update( $table, $fields, array( 'id' => $id ), $format, array( '%d' ) );

		self::bust_cache();

		return false !== $result;
	}

	/**
	 * Delete a skill.
	 *
	 * @param int $id Skill ID.
	 * @return bool
	 */
	public static function delete( int $id ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'agent_builder_skills';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table delete.
		$result = $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );

		self::bust_cache();

		return false !== $result && $result > 0;
	}

	/**
	 * Seed bundled core skills from library/skills/{slug}/SKILL.md files.
	 *
	 * New skills are inserted. Existing core-sourced skills are refreshed
	 * from disk only when their DB content still matches the hash recorded
	 * at the last seed/refresh — i.e. the user never edited them — so a
	 * plugin update can deliver bundled-skill improvements without silently
	 * overwriting a customization. Rows from before source_hash existed have
	 * no baseline to compare against, so their current content is adopted as
	 * the baseline on first sight rather than guessed at. User-created and
	 * ClawHub-imported skills (source !== 'core') are never touched here.
	 *
	 * @return array{seeded: int, refreshed: int}
	 */
	public static function seed_core_skills(): array {
		$core_dir = defined( 'AGENT_BUILDER_DIR' ) ? AGENT_BUILDER_DIR . 'library/skills/' : '';

		$dirs = array();
		if ( '' !== $core_dir && is_dir( $core_dir ) ) {
			$dirs[] = $core_dir;
		}

		// Allow Pro (and other extensions) to register additional skill directories.
		$dirs = apply_filters( 'agentic_skill_dirs', $dirs );
		$dirs = array_unique( array_filter( $dirs, 'is_dir' ) );

		if ( empty( $dirs ) ) {
			return array(
				'seeded'   => 0,
				'refreshed' => 0,
			);
		}

		$seeded    = 0;
		$refreshed = 0;

		foreach ( $dirs as $skills_dir ) {
			$skill_files = glob( rtrim( $skills_dir, '/' ) . '/*/SKILL.md' );
			foreach ( ( $skill_files ? $skill_files : array() ) as $skill_file ) {
				$slug = basename( dirname( $skill_file ) );

				$raw = file_get_contents( $skill_file );
				if ( false === $raw || '' === $raw ) {
					continue;
				}
				$hash = hash( 'sha256', $raw );

				$name        = ucwords( str_replace( '-', ' ', $slug ) );
				$description = '';

				if ( preg_match( '/^---\s*\n(.*?)\n---\s*\n/s', $raw, $m ) ) {
					if ( preg_match( '/^name:\s*(.+)$/m', $m[1], $nm ) ) {
						$name = trim( $nm[1], " \t\"'" );
					}
					$description = self::extract_description( $m[1] );
				}

				$existing = self::get_by_slug( $slug );

				if ( ! $existing ) {
					self::create(
						array(
							'name'        => $name,
							'description' => $description,
							'content'     => $raw,
							'agent_slug'  => '',
							'source'      => 'core',
							'source_id'   => $slug,
							'version'     => '1.0.0',
							'author'      => 'Agentic',
							'source_hash' => $hash,
							'enabled'     => true,
						)
					);
					++$seeded;
					continue;
				}

				// Only core-sourced rows are eligible for auto-refresh — a user
				// skill or ClawHub import that happens to reuse a slug is untouched.
				if ( 'core' !== ( $existing['source'] ?? '' ) ) {
					continue;
				}

				$stored_hash = (string) ( $existing['source_hash'] ?? '' );

				// No baseline recorded yet (row predates source_hash tracking).
				// Adopt its current content as the baseline instead of guessing
				// whether it was user-edited; the next real bundled-file change
				// will then be detected correctly.
				if ( '' === $stored_hash ) {
					self::update(
						(int) $existing['id'],
						array( 'source_hash' => hash( 'sha256', (string) ( $existing['content'] ?? '' ) ) )
					);
					continue;
				}

				$is_customized = hash( 'sha256', (string) ( $existing['content'] ?? '' ) ) !== $stored_hash;
				$has_update    = $hash !== $stored_hash;

				if ( ! $is_customized && $has_update ) {
					self::update(
						(int) $existing['id'],
						array(
							'description' => $description,
							'content'     => $raw,
							'source_hash' => $hash,
						)
					);
					++$refreshed;
				}
			}
		}

		return array(
			'seeded'    => $seeded,
			'refreshed' => $refreshed,
		);
	}

	/**
	 * Whether a core-sourced skill has local edits (its content no longer
	 * matches the hash recorded when it was last seeded/refreshed from the
	 * bundled file). Used by the admin UI to offer "Reset to shipped version".
	 *
	 * @param array<string, mixed> $skill Skill row from get_all()/get().
	 * @return bool
	 */
	public static function is_customized( array $skill ): bool {
		if ( 'core' !== ( $skill['source'] ?? '' ) ) {
			return false;
		}
		$stored_hash = (string) ( $skill['source_hash'] ?? '' );
		if ( '' === $stored_hash ) {
			return false;
		}
		return hash( 'sha256', (string) ( $skill['content'] ?? '' ) ) !== $stored_hash;
	}

	/**
	 * Extract the tool list from SKILL.md YAML front matter.
	 *
	 * Prefers the agentskills.io spec's `allowed-tools: foo bar baz`
	 * (space-separated string). Falls back to this plugin's pre-spec
	 * `tools: [foo, bar]` inline array or `tools:\n  - foo\n  - bar` block
	 * sequence for skills authored before the spec existed. Returns an
	 * empty array when neither key is present — absence is not an error.
	 *
	 * @param string $content Raw SKILL.md content.
	 * @return string[] Tool name list, or empty array.
	 */
	public static function parse_front_matter_tools( string $content ): array {
		if ( ! preg_match( '/^---\s*\n(.*?)\n---\s*\n/s', $content, $m ) ) {
			return array();
		}
		$front_matter = $m[1];

		// Spec field: allowed-tools: foo bar baz (space-separated string).
		if ( preg_match( '/^allowed-tools:\s*["\']?(.+?)["\']?\s*$/m', $front_matter, $spec ) ) {
			$names = preg_split( '/\s+/', trim( $spec[1] ) );
			return array_values( array_filter( (array) $names ) );
		}

		// Legacy inline array: tools: [foo, bar, baz].
		if ( preg_match( '/^tools:\s*\[([^\]]*)\]/m', $front_matter, $inline ) ) {
			$names = array_map( 'trim', explode( ',', $inline[1] ) );
			return array_values( array_filter( $names ) );
		}

		// Legacy block sequence:
		// tools:
		// - foo
		// - bar.
		if ( preg_match( '/^tools:\s*\n((?:\s+-\s+\S+\n?)+)/m', $front_matter, $block ) ) {
			preg_match_all( '/^\s+-\s+(\S+)/m', $block[1], $items );
			return array_values( array_filter( $items[1] ) );
		}

		return array();
	}

	/**
	 * Extract the optional spec fields (license, compatibility, metadata)
	 * from SKILL.md YAML front matter. `name` and `description` are handled
	 * separately by seed_core_skills() and the admin form since they map to
	 * dedicated DB columns.
	 *
	 * @param string $content Raw SKILL.md content.
	 * @return array{license: string, compatibility: string, metadata: array<string, string>}
	 */
	public static function parse_front_matter_meta( string $content ): array {
		$result = array(
			'license'       => '',
			'compatibility' => '',
			'metadata'      => array(),
		);

		if ( ! preg_match( '/^---\s*\n(.*?)\n---\s*\n/s', $content, $m ) ) {
			return $result;
		}
		$front_matter = $m[1];

		foreach ( array( 'license', 'compatibility' ) as $key ) {
			if ( preg_match( '/^' . $key . ':\s*["\']?(.*?)["\']?\s*$/m', $front_matter, $km ) ) {
				$result[ $key ] = trim( $km[1] );
			}
		}

		// metadata:
		//   author: example-org
		//   version: "1.0"
		if ( preg_match( '/^metadata:\s*\n((?:[ \t]+\S.*\n?)+)/m', $front_matter, $meta_block ) ) {
			if ( preg_match_all( '/^[ \t]+([a-zA-Z0-9_-]+):\s*["\']?(.*?)["\']?\s*$/m', $meta_block[1], $pairs, PREG_SET_ORDER ) ) {
				foreach ( $pairs as $p ) {
					$result['metadata'][ $p[1] ] = trim( $p[2] );
				}
			}
		}

		return $result;
	}

	/**
	 * Extract just `name` and `description` from SKILL.md front matter, for
	 * callers (like the admin editor) that want to validate/preview the
	 * content's own identity fields rather than the DB row's columns.
	 *
	 * @param string $content Raw SKILL.md content.
	 * @return array{name: string, description: string}
	 */
	public static function parse_front_matter_identity( string $content ): array {
		$result = array(
			'name'        => '',
			'description' => '',
		);
		if ( ! preg_match( '/^---\s*\n(.*?)\n---\s*\n/s', $content, $m ) ) {
			return $result;
		}
		if ( preg_match( '/^name:\s*(.+)$/m', $m[1], $nm ) ) {
			$result['name'] = trim( $nm[1], " \t\"'" );
		}
		$result['description'] = self::extract_description( $m[1] );
		return $result;
	}

	/**
	 * Extract a `description:` value from YAML front matter, accepting both
	 * a plain single-line value and YAML's block scalar form
	 * (`description: |`, `|-`, `>`, `>-` followed by indented lines) —
	 * third-party skills use both; e.g. Anthropic's own claude-api skill is
	 * block-scalar.
	 *
	 * @param string $front_matter Front matter text (without the --- fences).
	 * @return string
	 */
	private static function extract_description( string $front_matter ): string {
		if ( preg_match( '/^description:\s*[|>][-+]?[ \t]*\n((?:[ \t]+.*\n?)+)/m', $front_matter, $block ) ) {
			$lines = array_filter(
				array_map( 'trim', explode( "\n", $block[1] ) ),
				static fn( string $l ): bool => '' !== $l
			);
			return implode( ' ', $lines );
		}
		if ( preg_match( '/^description:\s*["\']?(.*?)["\']?\s*$/m', $front_matter, $dm ) ) {
			return trim( $dm[1], " \t\"'" );
		}
		return '';
	}

	/**
	 * Validate a skill's `name` and `description` against the agentskills.io
	 * spec's constraints. Skills that fail this are still usable — the spec
	 * fields are for cross-tool interop, not a hard runtime gate — but the
	 * admin UI surfaces violations so authored skills stay portable.
	 *
	 * @param string $name        Candidate name/slug identifier.
	 * @param string $description Candidate trigger description.
	 * @return string[] Human-readable violation messages, empty if valid.
	 */
	public static function validate_spec_fields( string $name, string $description ): array {
		$errors = array();

		if ( '' === $name ) {
			$errors[] = __( 'Name is required.', 'agent-builder' );
		} elseif ( strlen( $name ) > 64 ) {
			$errors[] = __( 'Name must be 64 characters or fewer.', 'agent-builder' );
		} elseif ( ! preg_match( '/^[a-z0-9]+(-[a-z0-9]+)*$/', $name ) ) {
			$errors[] = __( 'Name may only contain lowercase letters, numbers, and single hyphens (no leading/trailing/double hyphens).', 'agent-builder' );
		}

		if ( '' === $description ) {
			$errors[] = __( 'Description is required — it is what the agent matches against to decide when to use this skill.', 'agent-builder' );
		} elseif ( strlen( $description ) > 1024 ) {
			$errors[] = __( 'Description must be 1024 characters or fewer.', 'agent-builder' );
		}

		return $errors;
	}

	/**
	 * Validate all skills with declared tools against the live Tools_Registry.
	 *
	 * Only skills that include a `tools:` list in their SKILL.md front matter
	 * are checked — absence of the key is not treated as an error, allowing
	 * incremental adoption.
	 *
	 * @return array<string, string[]> Map of skill_slug => unknown tool names.
	 */
	public static function validate(): array {
		$errors      = array();
		$known_tools = array_keys( Tools_Registry::get_all() );

		foreach ( self::get_all() as $skill ) {
			$declared = self::parse_front_matter_tools( $skill['content'] ?? '' );
			if ( empty( $declared ) ) {
				continue;
			}

			$unknown = array_values( array_diff( $declared, $known_tools ) );
			if ( ! empty( $unknown ) ) {
				$errors[ $skill['slug'] ] = $unknown;
			}
		}

		return $errors;
	}

	/**
	 * Import a skill from ClawHub data.
	 *
	 * @param array<string, mixed> $hub_data Data from ClawHub API.
	 * @return int|false Inserted ID or false.
	 */
	public static function import_from_hub( array $hub_data ) {
		// ClawHub uses slug as stable identifier; also accept _id or id.
		$source_id = $hub_data['slug'] ?? ( $hub_data['_id'] ?? ( $hub_data['id'] ?? '' ) );

		// Normalise ClawHub field names.
		if ( empty( $hub_data['name'] ) && ! empty( $hub_data['displayName'] ) ) {
			$hub_data['name'] = $hub_data['displayName'];
		}
		if ( empty( $hub_data['description'] ) && ! empty( $hub_data['summary'] ) ) {
			$hub_data['description'] = $hub_data['summary'];
		}

		$source = sanitize_key( $hub_data['source'] ?? 'clawhub' );
		if ( ! in_array( $source, array( 'agentic', 'clawhub', 'wordpress', 'anthropic' ), true ) ) {
			$source = 'clawhub';
		}

		// Check if already imported from this exact source. Matching on
		// source_id alone (regardless of source) would let two different
		// registries' skills that happen to share a slug — e.g. "pdf" exists
		// in both Anthropic's and ClawHub's catalogs — clobber each other.
		if ( $source_id ) {
			foreach ( self::get_all() as $existing ) {
				if ( $source === $existing['source'] && $source_id === $existing['source_id'] ) {
					// Update existing import.
					self::update(
						(int) $existing['id'],
						array(
							'name'        => $hub_data['name'] ?? $existing['name'],
							'description' => $hub_data['description'] ?? $existing['description'],
							'content'     => $hub_data['content'] ?? ( $hub_data['skill_md'] ?? $existing['content'] ),
							'version'     => $hub_data['version'] ?? $existing['version'],
							'author'      => $hub_data['author'] ?? $existing['author'],
						)
					);
					return (int) $existing['id'];
				}
			}
		}

		return self::create(
			array(
				'name'        => $hub_data['name'] ?? 'Imported Skill',
				'description' => $hub_data['description'] ?? '',
				'content'     => $hub_data['content'] ?? ( $hub_data['skill_md'] ?? '' ),
				'agent_slug'  => '',
				'source'      => $source,
				'source_id'   => (string) $source_id,
				'version'     => $hub_data['version'] ?? '1.0.0',
				'author'      => $hub_data['author'] ?? '',
				'enabled'     => true,
			)
		);
	}

	/**
	 * Read a core skill's current bundled SKILL.md straight from disk,
	 * searching the same directories seed_core_skills() does (so Pro-added
	 * directories via the agentic_skill_dirs filter are honoured too). Used
	 * by the admin UI's "Reset to shipped version" action — the DB row may
	 * have been customized, but the file on disk is always the true shipped
	 * baseline.
	 *
	 * @param string $source_id Skill slug as shipped (matches the row's source_id).
	 * @return string|null Raw SKILL.md content, or null if not found.
	 */
	public static function get_bundled_content( string $source_id ): ?string {
		$source_id = sanitize_file_name( $source_id );
		if ( '' === $source_id ) {
			return null;
		}

		$dirs = array();
		if ( defined( 'AGENT_BUILDER_DIR' ) && is_dir( AGENT_BUILDER_DIR . 'library/skills/' ) ) {
			$dirs[] = AGENT_BUILDER_DIR . 'library/skills/';
		}
		$dirs = apply_filters( 'agentic_skill_dirs', $dirs );

		foreach ( (array) $dirs as $skills_dir ) {
			if ( ! is_dir( (string) $skills_dir ) ) {
				continue;
			}
			$file = rtrim( (string) $skills_dir, '/' ) . '/' . $source_id . '/SKILL.md';
			if ( is_file( $file ) ) {
				$raw = file_get_contents( $file );
				return false !== $raw ? $raw : null;
			}
		}

		return null;
	}

	/**
	 * Starter templates for the "Create Skill" gallery. Each gives a
	 * ready-to-edit SKILL.md skeleton following the same structure
	 * skill-creator teaches: frontmatter, Available Tools table, Workflows,
	 * Quality Rules.
	 *
	 * @return array<string, array{label: string, category: string, description: string, content: string}>
	 */
	public static function get_templates(): array {
		$blank = "---\nname: my-skill\ndescription: \"Use this skill when... Trigger on phrases like '...'. Do NOT trigger when...\"\n---\n\n# My Skill\n\n## Workflow\n1. \n2. \n\n## Quality Rules\n- \n";

		$with_tools = static function ( string $name, string $title, string $trigger_hint ): string {
			return "---\nname: {$name}\ndescription: \"Use this skill when {$trigger_hint}. Trigger on phrases like '...'. Do NOT trigger when...\"\nallowed-tools: \n---\n\n# {$title}\n\n## Available Tools\n\n| Tool | When to use |\n|---|---|\n| `tool_name` | ... |\n\n## Workflow\n1. \n2. \n\n## Quality Rules\n- \n";
		};

		return array(
			'blank'          => array(
				'label'       => __( 'Blank skill', 'agent-builder' ),
				'category'    => __( 'General', 'agent-builder' ),
				'description' => __( 'Start from an empty SKILL.md skeleton.', 'agent-builder' ),
				'content'     => $blank,
			),
			'wp-content'     => array(
				'label'       => __( 'WordPress content workflow', 'agent-builder' ),
				'category'    => __( 'WordPress', 'agent-builder' ),
				'description' => __( 'For a skill that creates, edits, or reviews posts and pages.', 'agent-builder' ),
				'content'     => $with_tools( 'my-content-skill', 'My Content Skill', 'the user wants to [create/edit/review] WordPress content' ),
			),
			'woocommerce'    => array(
				'label'       => __( 'WooCommerce workflow', 'agent-builder' ),
				'category'    => __( 'WooCommerce', 'agent-builder' ),
				'description' => __( 'For a skill touching products, orders, or store data.', 'agent-builder' ),
				'content'     => $with_tools( 'my-woocommerce-skill', 'My WooCommerce Skill', 'the user wants to work with WooCommerce products/orders' ),
			),
			'dev-tool'       => array(
				'label'       => __( 'Developer / technical workflow', 'agent-builder' ),
				'category'    => __( 'Developer', 'agent-builder' ),
				'description' => __( 'For a skill wrapping technical or maintenance tools.', 'agent-builder' ),
				'content'     => $with_tools( 'my-dev-skill', 'My Developer Skill', 'the user has a [specific technical] request' ),
			),
			'communication'  => array(
				'label'       => __( 'Communication / support workflow', 'agent-builder' ),
				'category'    => __( 'Communication', 'agent-builder' ),
				'description' => __( 'For a skill handling messaging, notifications, or support handoffs.', 'agent-builder' ),
				'content'     => $with_tools( 'my-comms-skill', 'My Communication Skill', 'the user wants to [notify/message/escalate]' ),
			),
		);
	}
}
