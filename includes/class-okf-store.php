<?php
/**
 * Open Knowledge Format (OKF) store — local filesystem wiki for agents.
 *
 * Free-plugin knowledge layer: directories of markdown concepts with YAML frontmatter.
 * No cloud, no credits. Progressive disclosure via index.md + tools.
 *
 * @package    Agent_Builder
 * @subpackage Includes
 * @since      3.1.0
 */

declare(strict_types=1);

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Filesystem store for OKF knowledge bundles.
 */
class Okf_Store {

	/**
	 * Bundle version written into root index.md.
	 */
	const OKF_VERSION = '0.2';

	/**
	 * Reserved filenames that are not concepts.
	 *
	 * @var string[]
	 */
	const RESERVED = array( 'index.md', 'log.md' );

	/**
	 * Absolute path to the site-wide OKF root.
	 */
	public static function root_dir(): string {
		return trailingslashit( AGENT_BUILDER_KNOWLEDGE_DIR ) . 'okf';
	}

	/**
	 * Absolute path for an agent's bundle (or site-wide when $agent_slug is empty).
	 *
	 * @param string $agent_slug Agent slug or '' for site-wide.
	 */
	public static function bundle_dir( string $agent_slug = '' ): string {
		$agent_slug = sanitize_key( $agent_slug );
		if ( '' === $agent_slug || 'site' === $agent_slug ) {
			return trailingslashit( self::root_dir() ) . 'site';
		}
		return trailingslashit( self::root_dir() ) . 'agents/' . $agent_slug;
	}

	/**
	 * Ensure the bundle directory exists with a root index.md.
	 *
	 * @param string $agent_slug Agent slug or site.
	 * @return true|\WP_Error
	 */
	public static function ensure_bundle( string $agent_slug = '' ) {
		// Protects the whole AGENT_BUILDER_KNOWLEDGE_DIR tree (Apache/IIS config
		// applies to subdirectories automatically), not just this one bundle
		// — idempotent, so calling it again on every request is cheap.
		File_Manager::ensure_protected_dir( AGENT_BUILDER_KNOWLEDGE_DIR );

		$dir = self::bundle_dir( $agent_slug );
		if ( ! wp_mkdir_p( $dir ) ) {
			return new \WP_Error( 'okf_mkdir', __( 'Could not create knowledge wiki directory.', 'agent-builder' ) );
		}

		$index = trailingslashit( $dir ) . 'index.md';
		if ( ! file_exists( $index ) ) {
			$title = ( '' === $agent_slug || 'site' === $agent_slug )
				? __( 'Site knowledge wiki', 'agent-builder' )
				: sprintf(
					/* translators: %s: agent slug */
					__( 'Knowledge wiki for %s', 'agent-builder' ),
					$agent_slug
				);
			$body = "---\nokf_version: \"" . self::OKF_VERSION . "\"\n---\n\n# " . $title . "\n\n"
				. __( 'Concepts in this wiki:', 'agent-builder' ) . "\n\n";
			File_Manager::put_contents( $index, $body );
		}

		return true;
	}

	/**
	 * Whether frontmatter marks a demo/example concept (not for agents).
	 *
	 * @param array<string, mixed> $frontmatter Frontmatter.
	 */
	public static function is_example( array $frontmatter ): bool {
		$ex = $frontmatter['example'] ?? false;
		if ( true === $ex || 1 === $ex || '1' === $ex ) {
			return true;
		}
		if ( is_string( $ex ) ) {
			return in_array( strtolower( $ex ), array( 'true', 'yes', 'on' ), true );
		}
		return false;
	}

	/**
	 * Whether a concept should be injected into every system prompt (always-on).
	 *
	 * Frontmatter: always_on: true  OR  inject: always
	 *
	 * @param array<string, mixed> $frontmatter Frontmatter.
	 */
	public static function is_always_on( array $frontmatter ): bool {
		$always = $frontmatter['always_on'] ?? false;
		if ( true === $always || 1 === $always || '1' === $always ) {
			return true;
		}
		if ( is_string( $always ) && in_array( strtolower( $always ), array( 'true', 'yes', 'on' ), true ) ) {
			return true;
		}
		$inject = $frontmatter['inject'] ?? '';
		if ( is_string( $inject ) && 'always' === strtolower( trim( $inject ) ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Fixed concept id for site-wide instructions migrated from Settings → General.
	 */
	const SITE_OVERVIEW_ID = 'site-overview';

	/**
	 * Directory of bundled example concepts shipped with the plugin.
	 */
	public static function examples_source_dir(): string {
		return trailingslashit( AGENT_BUILDER_DIR ) . 'library/okf-examples';
	}

	/**
	 * Copy bundled demo concepts into the site wiki once (on install / upgrade).
	 *
	 * Does not overwrite files the site already has. Marks seeded with an option.
	 *
	 * @param bool $force Re-seed missing example files even if option is set.
	 * @return int Number of files written.
	 */
	public static function seed_examples( bool $force = false ): int {
		if ( ! $force && get_option( 'agentic_okf_examples_seeded', '' ) === self::OKF_VERSION ) {
			// Still fill any missing example-*.md files so upgrades can add new samples.
			$force_missing = true;
		} else {
			$force_missing = true;
		}

		$src = self::examples_source_dir();
		if ( ! is_dir( $src ) ) {
			return 0;
		}

		self::ensure_bundle( 'site' );
		$dest  = self::bundle_dir( 'site' );
		$wrote = 0;

		foreach ( glob( trailingslashit( $src ) . '*.md' ) ?: array() as $file ) {
			$base = basename( $file );
			$to   = trailingslashit( $dest ) . $base;
			if ( file_exists( $to ) ) {
				continue;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$content = (string) file_get_contents( $file );
			if ( '' === $content ) {
				continue;
			}
			if ( File_Manager::put_contents( $to, $content ) ) {
				++$wrote;
			}
		}

		if ( $wrote > 0 || ! get_option( 'agentic_okf_examples_seeded', '' ) ) {
			update_option( 'agentic_okf_examples_seeded', self::OKF_VERSION, false );
			if ( $wrote > 0 ) {
				self::rebuild_index( 'site' );
			}
		}

		unset( $force_missing );
		return $wrote;
	}

	/**
	 * Whether the site has real knowledge for onboarding / Getting Started.
	 *
	 * True when there is at least one non-example OKF concept, or when a
	 * vector store integration reports knowledge via the filter.
	 */
	public static function has_active_knowledge(): bool {
		// Live OKF wiki: non-example concepts only.
		if ( count( self::list_concepts( '', false ) ) > 0 ) {
			update_option( 'agentic_has_knowledge', '1', false );
			return true;
		}

		/**
		 * Whether hosted vector / RAG knowledge is present or the store is enabled.
		 *
		 * Pro (and other add-ons) should return true when the vector store is
		 * enabled and/or has trained sources.
		 *
		 * @param bool $has Default false.
		 */
		$has_vector = (bool) apply_filters( 'agentic_has_vector_knowledge', false );
		if ( $has_vector ) {
			update_option( 'agentic_has_knowledge', '1', false );
			return true;
		}

		// Keep option in sync so older readers stay accurate.
		update_option( 'agentic_has_knowledge', '0', false );
		return false;
	}

	/**
	 * List concept files in a bundle (relative paths without .md).
	 *
	 * @param string $agent_slug        Agent slug or site.
	 * @param bool   $include_examples  When false (agent tools), skip demo concepts.
	 * @return array<int, array{id:string,path:string,type:string,title:string,description:string,status:string,tags:array,stale:bool,example:bool}>
	 */
	public static function list_concepts( string $agent_slug = '', bool $include_examples = true ): array {
		$dir = self::bundle_dir( $agent_slug );
		if ( ! is_dir( $dir ) ) {
			return array();
		}

		$concepts = array();
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || 'md' !== strtolower( $file->getExtension() ) ) {
				continue;
			}
			$name = $file->getFilename();
			if ( in_array( $name, self::RESERVED, true ) ) {
				continue;
			}

			$abs      = $file->getPathname();
			$relative = ltrim( str_replace( $dir, '', $abs ), '/\\' );
			$id       = preg_replace( '/\.md$/i', '', str_replace( '\\', '/', $relative ) );
			$parsed   = self::parse_file( $abs );
			$example  = self::is_example( $parsed['frontmatter'] );

			if ( $example && ! $include_examples ) {
				continue;
			}

			$concepts[] = array(
				'id'          => $id,
				'path'        => $relative,
				'type'        => (string) ( $parsed['frontmatter']['type'] ?? 'Concept' ),
				'title'       => (string) ( $parsed['frontmatter']['title'] ?? basename( $id ) ),
				'description' => (string) ( $parsed['frontmatter']['description'] ?? '' ),
				'status'      => (string) ( $parsed['frontmatter']['status'] ?? 'stable' ),
				'tags'        => self::normalize_tags( $parsed['frontmatter']['tags'] ?? array() ),
				'stale'       => self::is_stale( $parsed['frontmatter'] ),
				'example'     => $example,
				'always_on'   => self::is_always_on( $parsed['frontmatter'] ),
			);
		}

		usort(
			$concepts,
			static function ( array $a, array $b ): int {
				// Examples last in admin lists.
				if ( $a['example'] !== $b['example'] ) {
					return $a['example'] ? 1 : -1;
				}
				return strcasecmp( $a['title'], $b['title'] );
			}
		);

		return $concepts;
	}

	/**
	 * Read one concept by id (path without .md).
	 *
	 * @param string $concept_id       Concept id / relative path without extension.
	 * @param string $agent_slug       Bundle scope.
	 * @param bool   $allow_examples   When false (agent tools), deny demo concepts.
	 * @return array{id:string,path:string,frontmatter:array,body:string,raw:string,example:bool}|\WP_Error
	 */
	public static function get_concept( string $concept_id, string $agent_slug = '', bool $allow_examples = true ) {
		$path = self::resolve_concept_path( $concept_id, $agent_slug );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		if ( ! file_exists( $path ) ) {
			return new \WP_Error( 'okf_missing', __( 'Concept not found.', 'agent-builder' ) );
		}

		$parsed   = self::parse_file( $path );
		$example  = self::is_example( $parsed['frontmatter'] );
		$relative = ltrim( str_replace( self::bundle_dir( $agent_slug ), '', $path ), '/\\' );

		if ( $example && ! $allow_examples ) {
			return new \WP_Error(
				'okf_example',
				__( 'This is demo example knowledge and is not available to agents. Edit it in Knowledge Wiki and turn off Example when ready.', 'agent-builder' )
			);
		}

		return array(
			'id'          => preg_replace( '/\.md$/i', '', str_replace( '\\', '/', $relative ) ),
			'path'        => $relative,
			'frontmatter' => $parsed['frontmatter'],
			'body'        => $parsed['body'],
			'raw'         => $parsed['raw'],
			'example'     => $example,
		);
	}

	/**
	 * Create or update a concept document.
	 *
	 * @param string               $concept_id  Relative id (no .md).
	 * @param array<string, mixed> $frontmatter Frontmatter fields.
	 * @param string               $body        Markdown body.
	 * @param string               $agent_slug  Bundle scope.
	 * @return true|\WP_Error
	 */
	public static function save_concept( string $concept_id, array $frontmatter, string $body, string $agent_slug = '' ) {
		$ensured = self::ensure_bundle( $agent_slug );
		if ( is_wp_error( $ensured ) ) {
			return $ensured;
		}

		$concept_id = self::sanitize_concept_id( $concept_id );
		if ( '' === $concept_id ) {
			return new \WP_Error( 'okf_id', __( 'Invalid concept id.', 'agent-builder' ) );
		}

		$type = trim( (string) ( $frontmatter['type'] ?? '' ) );
		if ( '' === $type ) {
			return new \WP_Error( 'okf_type', __( 'Every concept needs a type field.', 'agent-builder' ) );
		}

		$path = trailingslashit( self::bundle_dir( $agent_slug ) ) . $concept_id . '.md';
		$dir  = dirname( $path );
		if ( ! wp_mkdir_p( $dir ) ) {
			return new \WP_Error( 'okf_mkdir', __( 'Could not create concept directory.', 'agent-builder' ) );
		}

		// Default generated stamp when missing.
		if ( empty( $frontmatter['generated'] ) ) {
			$user                     = wp_get_current_user();
			$by                       = $user && $user->ID ? 'human:' . sanitize_key( $user->user_login ) : 'human:admin';
			$frontmatter['generated'] = array(
				'by' => $by,
				'at' => gmdate( 'c' ),
			);
		}

		$raw = self::serialize( $frontmatter, $body );
		if ( ! File_Manager::put_contents( $path, $raw ) ) {
			return new \WP_Error( 'okf_write', __( 'Could not write concept file.', 'agent-builder' ) );
		}

		self::rebuild_index( $agent_slug );
		self::append_log( $agent_slug, 'Update', $frontmatter['title'] ?? $concept_id, $concept_id );

		// Getting Started "Add knowledge" step.
		if ( ! self::is_example( $frontmatter ) ) {
			update_option( 'agentic_has_knowledge', '1', false );
		} else {
			self::has_active_knowledge();
		}

		return true;
	}

	/**
	 * Delete a concept.
	 *
	 * @param string $concept_id Concept id.
	 * @param string $agent_slug Bundle scope.
	 * @return true|\WP_Error
	 */
	public static function delete_concept( string $concept_id, string $agent_slug = '' ) {
		$path = self::resolve_concept_path( $concept_id, $agent_slug );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		if ( ! file_exists( $path ) ) {
			return new \WP_Error( 'okf_missing', __( 'Concept not found.', 'agent-builder' ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		if ( ! unlink( $path ) ) {
			return new \WP_Error( 'okf_delete', __( 'Could not delete concept file.', 'agent-builder' ) );
		}

		self::rebuild_index( $agent_slug );
		self::append_log( $agent_slug, 'Deletion', $concept_id, $concept_id );
		self::has_active_knowledge();

		return true;
	}

	/**
	 * Keyword search across titles, descriptions, tags, and bodies.
	 *
	 * @param string $query      Search string.
	 * @param string $agent_slug Bundle scope ('' = site).
	 * @param int    $limit      Max hits.
	 * @return array<int, array{id:string,title:string,type:string,snippet:string,score:int}>
	 */
	public static function search( string $query, string $agent_slug = '', int $limit = 8, bool $include_examples = false ): array {
		$terms = self::tokenize( $query );
		if ( empty( $terms ) ) {
			return array();
		}

		$hits = array();
		// Agent search never includes demos; admin can pass include_examples true if needed.
		foreach ( self::list_concepts( $agent_slug, $include_examples ) as $meta ) {
			$full = self::get_concept( $meta['id'], $agent_slug, $include_examples );
			if ( is_wp_error( $full ) ) {
				continue;
			}
			$haystack = strtolower(
				$meta['title'] . ' ' . $meta['description'] . ' ' . implode( ' ', $meta['tags'] ) . ' ' . $full['body']
			);
			$score    = 0;
			foreach ( $terms as $term ) {
				if ( str_contains( $haystack, $term ) ) {
					$score += ( str_contains( strtolower( $meta['title'] ), $term ) ) ? 3 : 1;
				}
			}
			if ( $score <= 0 ) {
				continue;
			}
			$snippet = wp_html_excerpt( wp_strip_all_tags( $full['body'] ), 180, '…' );
			$hits[]  = array(
				'id'      => $meta['id'],
				'title'   => $meta['title'],
				'type'    => $meta['type'],
				'snippet' => $snippet,
				'score'   => $score,
			);
		}

		usort(
			$hits,
			static function ( array $a, array $b ): int {
				return $b['score'] <=> $a['score'];
			}
		);

		return array_slice( $hits, 0, max( 1, $limit ) );
	}

	/**
	 * Full bodies of always-on concepts (site wiki + agent wiki) for system prompts.
	 *
	 * Cap keeps prompts from exploding if many concepts are marked always_on.
	 *
	 * @param string $agent_slug Agent slug (optional agent bundle).
	 * @param int    $max_chars  Soft character cap for the whole block.
	 * @return string
	 */
	public static function always_on_prompt_block( string $agent_slug = '', int $max_chars = 4000 ): string {
		$scopes = array();
		if ( '' !== $agent_slug && 'site' !== $agent_slug ) {
			$scopes[] = $agent_slug;
		}
		$scopes[] = 'site';

		$chunks = array();
		foreach ( $scopes as $scope ) {
			foreach ( self::list_concepts( $scope, false ) as $meta ) {
				if ( empty( $meta['always_on'] ) ) {
					continue;
				}
				$full = self::get_concept( $meta['id'], $scope, false );
				if ( is_wp_error( $full ) ) {
					continue;
				}
				$body = trim( (string) ( $full['body'] ?? '' ) );
				if ( '' === $body ) {
					continue;
				}
				$title    = (string) ( $meta['title'] ?? $meta['id'] );
				$chunks[] = '### ' . $title . "\n" . $body;
			}
		}

		if ( empty( $chunks ) ) {
			return '';
		}

		$block = "\n\n[SITE KNOWLEDGE — always on]\n"
			. __( 'Standing site knowledge from the Knowledge Wiki. Prefer this over guessing about the business. This content is already fully loaded — do not call read_okf_concept, list_okf_concepts, or search_okf for anything covered below; that would be redundant.', 'agent-builder' )
			. "\n\n"
			. implode( "\n\n", $chunks )
			. "\n";

		if ( strlen( $block ) > $max_chars ) {
			$block = substr( $block, 0, $max_chars - 20 ) . "\n…\n";
		}

		return $block;
	}

	/**
	 * Migrate Settings → General “Instructions for Agents” into an always-on OKF concept.
	 *
	 * Idempotent: runs once (option flag). Does not overwrite a richer existing concept body.
	 *
	 * @return bool True when a concept was created or updated from the option.
	 */
	public static function migrate_global_instructions_to_okf(): bool {
		if ( get_option( 'agentic_okf_global_instructions_migrated', '' ) === '1' ) {
			return false;
		}

		$legacy = trim( (string) get_option( 'agentic_global_instructions', '' ) );
		self::ensure_bundle( 'site' );

		$existing = self::get_concept( self::SITE_OVERVIEW_ID, 'site', true );
		$wrote    = false;

		if ( '' !== $legacy ) {
			$need_write = is_wp_error( $existing );
			if ( ! $need_write && is_array( $existing ) ) {
				// Only overwrite if empty body or still the placeholder.
				$body       = trim( (string) ( $existing['body'] ?? '' ) );
				$need_write = ( '' === $body );
			}
			if ( $need_write ) {
				$result = self::save_concept(
					self::SITE_OVERVIEW_ID,
					array(
						'type'        => 'Policy',
						'title'       => __( 'Site overview', 'agent-builder' ),
						'description' => __( 'Standing guidance for every agent. Always included in prompts.', 'agent-builder' ),
						'tags'        => array( 'site', 'overview', 'always-on' ),
						'status'      => 'stable',
						'always_on'   => true,
					),
					$legacy,
					'site'
				);
				$wrote  = ! is_wp_error( $result );
			} elseif ( is_array( $existing ) && ! self::is_always_on( $existing['frontmatter'] ?? array() ) ) {
				// Ensure flag if concept already had content but not always_on.
				$fm              = $existing['frontmatter'] ?? array();
				$fm['always_on'] = true;
				$fm['type']      = $fm['type'] ?? 'Policy';
				$fm['title']     = $fm['title'] ?? __( 'Site overview', 'agent-builder' );
				self::save_concept( self::SITE_OVERVIEW_ID, $fm, (string) ( $existing['body'] ?? '' ), 'site' );
				$wrote = true;
			}
		}

		update_option( 'agentic_okf_global_instructions_migrated', '1', false );
		return $wrote;
	}

	/**
	 * Build a compact prompt block from the wiki index + optional always-on concepts.
	 *
	 * @param string $agent_slug Bundle to prefer; falls back to site if empty.
	 * @param int    $max_chars  Soft cap for the block.
	 */
	public static function prompt_index_block( string $agent_slug = '', int $max_chars = 2500 ): string {
		$scopes = array();
		if ( '' !== $agent_slug && 'site' !== $agent_slug ) {
			$scopes[] = $agent_slug;
		}
		$scopes[] = 'site';

		$lines = array();
		foreach ( $scopes as $scope ) {
			// Never inject demo/example concepts into agent prompts. Always-on
			// concepts are skipped here too — always_on_prompt_block() already
			// injects their full body (with the same site+agent scope
			// resolution as this method), so listing them again as a
			// title-only stub would contradict that block and needlessly
			// prompt a read_okf_concept call for content the model already has.
			$concepts = array_values(
				array_filter(
					self::list_concepts( $scope, false ),
					static function ( array $c ): bool {
						return empty( $c['always_on'] );
					}
				)
			);
			if ( empty( $concepts ) ) {
				continue;
			}
			$label   = ( 'site' === $scope || '' === $scope ) ? 'site' : $scope;
			$lines[] = sprintf( '## Bundle: %s (%d concepts)', $label, count( $concepts ) );
			foreach ( array_slice( $concepts, 0, 40 ) as $c ) {
				$stale   = ! empty( $c['stale'] ) ? ' [stale]' : '';
				$lines[] = sprintf(
					'- %s (%s)%s — %s [id: %s]',
					$c['title'],
					$c['type'],
					$stale,
					$c['description'] !== '' ? $c['description'] : __( 'No description', 'agent-builder' ),
					$c['id']
				);
			}
		}

		if ( empty( $lines ) ) {
			return '';
		}

		$block = "\n\n[KNOWLEDGE WIKI — Open Knowledge Format]\n"
			. __( 'Curated local knowledge. The entries below are titles only, not the answer — before answering any question with one of them, call read_okf_concept with its [id] to load the full text. Never answer from a title alone; if you have not called read_okf_concept for it in this turn, you do not yet know its content.', 'agent-builder' )
			. "\n"
			. implode( "\n", $lines )
			. "\n";

		if ( strlen( $block ) > $max_chars ) {
			$block = substr( $block, 0, $max_chars - 20 ) . "\n…\n";
		}

		return $block;
	}

	/**
	 * Import a simple FAQ / plain-text knowledge blob as one concept.
	 *
	 * @param string $title      Concept title.
	 * @param string $body       Markdown/plain body.
	 * @param string $agent_slug Bundle.
	 * @return true|\WP_Error
	 */
	public static function import_plain_text( string $title, string $body, string $agent_slug = '' ) {
		$id = sanitize_title( $title );
		if ( '' === $id ) {
			$id = 'imported-knowledge';
		}
		return self::save_concept(
			$id,
			array(
				'type'        => 'Reference',
				'title'       => $title,
				'description' => __( 'Imported from plain knowledge text.', 'agent-builder' ),
				'tags'        => array( 'imported' ),
				'status'      => 'stable',
			),
			$body,
			$agent_slug
		);
	}

	/**
	 * Parse frontmatter + body from a markdown file.
	 *
	 * @param string $path Absolute path.
	 * @return array{frontmatter:array,body:string,raw:string}
	 */
	public static function parse_file( string $path ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$raw = (string) file_get_contents( $path );
		return self::parse_raw( $raw );
	}

	/**
	 * Parse raw markdown with optional YAML frontmatter.
	 *
	 * @param string $raw File contents.
	 * @return array{frontmatter:array,body:string,raw:string}
	 */
	public static function parse_raw( string $raw ): array {
		$frontmatter = array();
		$body        = $raw;

		if ( preg_match( '/\A---\r?\n(.*?)\r?\n---\r?\n(.*)\z/s', $raw, $m ) ) {
			$frontmatter = self::parse_simple_yaml( $m[1] );
			$body        = ltrim( $m[2], "\r\n" );
		}

		return array(
			'frontmatter' => $frontmatter,
			'body'        => $body,
			'raw'         => $raw,
		);
	}

	/**
	 * Serialize frontmatter + body to OKF markdown.
	 *
	 * @param array<string, mixed> $frontmatter Fields.
	 * @param string               $body        Markdown body.
	 */
	public static function serialize( array $frontmatter, string $body ): string {
		$yaml = self::emit_simple_yaml( $frontmatter );
		return "---\n" . $yaml . "---\n\n" . rtrim( $body ) . "\n";
	}

	/**
	 * Rebuild root index.md from concepts.
	 *
	 * @param string $agent_slug Bundle scope.
	 */
	public static function rebuild_index( string $agent_slug = '' ): void {
		$concepts = self::list_concepts( $agent_slug );
		$by_type  = array();
		foreach ( $concepts as $c ) {
			$by_type[ $c['type'] ][] = $c;
		}
		ksort( $by_type );

		$lines   = array();
		$lines[] = '---';
		$lines[] = 'okf_version: "' . self::OKF_VERSION . '"';
		$lines[] = '---';
		$lines[] = '';
		$lines[] = '# ' . __( 'Knowledge index', 'agent-builder' );
		$lines[] = '';
		$lines[] = sprintf(
			/* translators: %d: concept count */
			__( '%d concepts in this wiki.', 'agent-builder' ),
			count( $concepts )
		);
		$lines[] = '';

		foreach ( $by_type as $type => $items ) {
			$lines[] = '## ' . $type;
			$lines[] = '';
			foreach ( $items as $c ) {
				$desc    = $c['description'] !== '' ? $c['description'] : __( 'No description', 'agent-builder' );
				$lines[] = sprintf( '* [%s](%s) - %s', $c['title'], $c['path'], $desc );
			}
			$lines[] = '';
		}

		$path = trailingslashit( self::bundle_dir( $agent_slug ) ) . 'index.md';
		File_Manager::put_contents( $path, implode( "\n", $lines ) . "\n" );
	}

	/**
	 * Append a log.md entry.
	 *
	 * @param string $agent_slug Bundle.
	 * @param string $action     Update|Creation|Deletion.
	 * @param string $title      Display title.
	 * @param string $concept_id Concept id for link.
	 */
	public static function append_log( string $agent_slug, string $action, string $title, string $concept_id ): void {
		$path = trailingslashit( self::bundle_dir( $agent_slug ) ) . 'log.md';
		$day  = gmdate( 'Y-m-d' );
		$line = sprintf( '* **%s**: %s (`%s`).', $action, $title, $concept_id );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$existing = file_exists( $path ) ? (string) file_get_contents( $path ) : "# Directory Update Log\n\n";

		if ( preg_match( '/^## ' . preg_quote( $day, '/' ) . '\s*$/m', $existing ) ) {
			$existing = preg_replace(
				'/^## ' . preg_quote( $day, '/' ) . '\s*$/m',
				"## {$day}\n" . $line,
				$existing,
				1
			);
		} else {
			$existing = preg_replace(
				'/# Directory Update Log\s*/',
				"# Directory Update Log\n\n## {$day}\n{$line}\n\n",
				$existing,
				1
			);
			if ( null === $existing || ! str_contains( (string) $existing, $line ) ) {
				$existing = "# Directory Update Log\n\n## {$day}\n{$line}\n";
			}
		}

		File_Manager::put_contents( $path, (string) $existing );
	}

	/**
	 * Resolve a concept id to an absolute path inside the bundle.
	 *
	 * @param string $concept_id Id.
	 * @param string $agent_slug Scope.
	 * @return string|\WP_Error
	 */
	private static function resolve_concept_path( string $concept_id, string $agent_slug ) {
		$concept_id = self::sanitize_concept_id( $concept_id );
		if ( '' === $concept_id ) {
			return new \WP_Error( 'okf_id', __( 'Invalid concept id.', 'agent-builder' ) );
		}
		$base = realpath( self::bundle_dir( $agent_slug ) );
		if ( false === $base ) {
			return new \WP_Error( 'okf_missing', __( 'Knowledge wiki not found.', 'agent-builder' ) );
		}
		$full = $base . '/' . $concept_id . '.md';
		// Prevent path traversal.
		$real_dir = realpath( dirname( $full ) );
		if ( false !== $real_dir && ! str_starts_with( $real_dir, $base ) ) {
			return new \WP_Error( 'okf_path', __( 'Invalid concept path.', 'agent-builder' ) );
		}
		return $full;
	}

	/**
	 * Sanitize concept id to relative path segments.
	 *
	 * @param string $concept_id Raw id.
	 */
	private static function sanitize_concept_id( string $concept_id ): string {
		$concept_id = str_replace( '\\', '/', $concept_id );
		$concept_id = preg_replace( '/\.md$/i', '', $concept_id );
		$parts      = array_filter( explode( '/', (string) $concept_id ) );
		$clean      = array();
		foreach ( $parts as $part ) {
			$part = sanitize_file_name( $part );
			if ( '' === $part || '.' === $part || '..' === $part ) {
				continue;
			}
			$clean[] = $part;
		}
		return implode( '/', $clean );
	}

	/**
	 * @param mixed $tags Tags field.
	 * @return string[]
	 */
	private static function normalize_tags( $tags ): array {
		if ( is_string( $tags ) ) {
			$tags = array_map( 'trim', explode( ',', $tags ) );
		}
		if ( ! is_array( $tags ) ) {
			return array();
		}
		return array_values(
			array_filter(
				array_map(
					static function ( $t ) {
						return sanitize_text_field( (string) $t );
					},
					$tags
				)
			)
		);
	}

	/**
	 * @param array<string, mixed> $frontmatter Frontmatter.
	 */
	private static function is_stale( array $frontmatter ): bool {
		$stale_after = $frontmatter['stale_after'] ?? '';
		if ( ! is_string( $stale_after ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $stale_after ) ) {
			return false;
		}
		return gmdate( 'Y-m-d' ) >= $stale_after;
	}

	/**
	 * Minimal YAML subset parser (scalars, simple lists, one-level maps).
	 *
	 * @param string $yaml Frontmatter body.
	 * @return array<string, mixed>
	 */
	private static function parse_simple_yaml( string $yaml ): array {
		$result = array();
		$lines  = preg_split( '/\r?\n/', $yaml ) ?: array();
		$i      = 0;
		$n      = count( $lines );

		while ( $i < $n ) {
			$line = $lines[ $i ];
			if ( '' === trim( $line ) || str_starts_with( ltrim( $line ), '#' ) ) {
				++$i;
				continue;
			}
			if ( ! preg_match( '/^([A-Za-z0-9_]+):\s*(.*)$/', $line, $m ) ) {
				++$i;
				continue;
			}
			$key = $m[1];
			$val = trim( $m[2] );

			if ( '' === $val ) {
				// Nested list or map on following indented lines.
				$list = array();
				++$i;
				while ( $i < $n && preg_match( '/^\s+-\s+(.*)$/', $lines[ $i ], $lm ) ) {
					$item = trim( $lm[1] );
					if ( preg_match( '/^\{.*\}$/', $item ) ) {
						$list[] = self::parse_inline_map( $item );
					} else {
						$list[] = self::unquote( $item );
					}
					++$i;
				}
				$result[ $key ] = $list;
				continue;
			}

			if ( str_starts_with( $val, '[' ) && str_ends_with( $val, ']' ) ) {
				$inner          = trim( substr( $val, 1, -1 ) );
				$items          = '' === $inner ? array() : array_map( 'trim', explode( ',', $inner ) );
				$result[ $key ] = array_map( array( self::class, 'unquote' ), $items );
			} elseif ( str_starts_with( $val, '{' ) && str_ends_with( $val, '}' ) ) {
				$result[ $key ] = self::parse_inline_map( $val );
			} else {
				$result[ $key ] = self::unquote( $val );
			}
			++$i;
		}

		return $result;
	}

	/**
	 * @param string $inline `{ by: x, at: y }` style map.
	 * @return array<string, string>
	 */
	private static function parse_inline_map( string $inline ): array {
		$inline = trim( $inline );
		$inline = trim( $inline, '{}' );
		$map    = array();
		foreach ( array_map( 'trim', explode( ',', $inline ) ) as $pair ) {
			if ( ! str_contains( $pair, ':' ) ) {
				continue;
			}
			[ $k, $v ] = array_map( 'trim', explode( ':', $pair, 2 ) );
			$map[ $k ] = self::unquote( $v );
		}
		return $map;
	}

	/**
	 * @param array<string, mixed> $data Frontmatter.
	 */
	private static function emit_simple_yaml( array $data ): string {
		$out = '';
		foreach ( $data as $key => $value ) {
			$key = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $key );
			if ( '' === $key ) {
				continue;
			}
			if ( is_bool( $value ) ) {
				$out .= $key . ': ' . ( $value ? 'true' : 'false' ) . "\n";
				continue;
			}
			if ( is_array( $value ) ) {
				if ( self::is_list( $value ) ) {
					// List of scalars or maps.
					if ( empty( $value ) ) {
						$out .= $key . ": []\n";
						continue;
					}
					$all_scalar = true;
					foreach ( $value as $item ) {
						if ( is_array( $item ) ) {
							$all_scalar = false;
							break;
						}
					}
					if ( $all_scalar ) {
						$quoted = array_map(
							static function ( $v ) {
								return self::quote( (string) $v );
							},
							$value
						);
						$out   .= $key . ': [' . implode( ', ', $quoted ) . "]\n";
					} else {
						$out .= $key . ":\n";
						foreach ( $value as $item ) {
							if ( is_array( $item ) ) {
								$parts = array();
								foreach ( $item as $ik => $iv ) {
									$parts[] = $ik . ': ' . self::quote( (string) $iv );
								}
								$out .= '  - { ' . implode( ', ', $parts ) . " }\n";
							} else {
								$out .= '  - ' . self::quote( (string) $item ) . "\n";
							}
						}
					}
				} else {
					// Associative map → inline.
					$parts = array();
					foreach ( $value as $ik => $iv ) {
						if ( is_array( $iv ) ) {
							continue;
						}
						$parts[] = $ik . ': ' . self::quote( (string) $iv );
					}
					$out .= $key . ': { ' . implode( ', ', $parts ) . " }\n";
				}
			} else {
				$out .= $key . ': ' . self::quote( (string) $value ) . "\n";
			}
		}
		return $out;
	}

	/**
	 * Polyfill for array_is_list() — avoid WP 6.5+ API so Requires at least: 6.4 stays valid.
	 *
	 * @param array<mixed> $arr Array.
	 */
	private static function is_list( array $arr ): bool {
		$i = 0;
		foreach ( $arr as $k => $_ ) {
			if ( $k !== $i ) {
				return false;
			}
			++$i;
		}
		return true;
	}

	/**
	 * @param string $value Scalar.
	 */
	private static function quote( string $value ): string {
		if ( preg_match( '/[:#\[\]{},\n"]/', $value ) || '' === $value ) {
			return '"' . str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), $value ) . '"';
		}
		return $value;
	}

	/**
	 * @param string $value Quoted or bare.
	 */
	private static function unquote( string $value ): string {
		$value = trim( $value );
		if (
			( str_starts_with( $value, '"' ) && str_ends_with( $value, '"' ) )
			|| ( str_starts_with( $value, "'" ) && str_ends_with( $value, "'" ) )
		) {
			return stripcslashes( substr( $value, 1, -1 ) );
		}
		return $value;
	}

	/**
	 * @param string $text Query.
	 * @return string[]
	 */
	private static function tokenize( string $text ): array {
		$text  = strtolower( $text );
		$parts = preg_split( '/[^a-z0-9]+/', $text ) ?: array();
		return array_values(
			array_filter(
				$parts,
				static function ( string $t ): bool {
					return strlen( $t ) >= 2;
				}
			)
		);
	}
}
