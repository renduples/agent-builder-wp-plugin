<?php
/**
 * Tool: browse_community_skills
 *
 * Browse or import community-published skills. Server-side port of the
 * "Browse Community Skills" hub and "Recommended Skills" grid in
 * admin/skills.php, restricted to the same two sources Basic mode's UI
 * exposes there — WordPress.org's official, human-reviewed repository and
 * Agentic's own curated feed. Anthropic's repo and ClawHub's open-publish
 * registry are Advanced-only in the UI and are not reachable through this
 * tool at all, not just hidden, so behaviour stays identical regardless of
 * site-wide mode.
 *
 * @package    Agent_Builder
 * @subpackage Tools
 * @since      3.3.75
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Agentic\Skills_Registry;

/**
 * Browse and import community skills from curated sources.
 */
class Browse_Community_Skills extends \Agentic\Tool_Base {

	/**
	 * WordPress.org's official agent-skills repository.
	 */
	private const WORDPRESS_OWNER  = 'WordPress';
	private const WORDPRESS_REPO   = 'agent-skills';
	private const WORDPRESS_BRANCH = 'trunk';

	/**
	 * Agentic's curated recommended-skills feed.
	 */
	private const AGENTIC_API = 'https://agentic-plugin.com/wp-json/agentic/v1/skills';

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'browse_community_skills';
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return "Browse or import skills other people have published. Two sources: \"wordpress\" (WordPress.org's official, human-reviewed skill repository) and \"agentic\" (Agent Builder's own curated recommended feed).";
	}

	/**
	 * Get the tool category.
	 *
	 * @return string
	 */
	public function get_category(): string {
		return 'skills-assistant';
	}

	/**
	 * Get the parameter schema.
	 *
	 * @return array
	 */
	public function get_parameters(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'action' => array(
					'type'        => 'string',
					'enum'        => array( 'browse', 'import' ),
					'description' => 'browse: list available skills from a source. import: install one into this site.',
				),
				'source' => array(
					'type'        => 'string',
					'enum'        => array( 'wordpress', 'agentic' ),
					'description' => 'Which catalog to browse or import from.',
				),
				'slug'   => array(
					'type'        => 'string',
					'description' => 'The skill\'s slug from a prior browse result. Required for import.',
				),
			),
			'required'   => array( 'action', 'source' ),
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Validated arguments from the LLM.
	 * @return array Result data.
	 */
	public function execute( array $arguments ): array {
		$denied = \Agentic\Tool_Helpers::deny_unless_admin_user();
		if ( null !== $denied ) {
			return $denied;
		}

		$action = sanitize_key( (string) ( $arguments['action'] ?? '' ) );
		$source = sanitize_key( (string) ( $arguments['source'] ?? '' ) );

		if ( ! in_array( $source, array( 'wordpress', 'agentic' ), true ) ) {
			return array( 'error' => 'source must be "wordpress" or "agentic".' );
		}

		if ( 'browse' === $action ) {
			return 'wordpress' === $source ? $this->browse_wordpress() : $this->browse_agentic();
		}
		if ( 'import' === $action ) {
			$slug = sanitize_text_field( (string) ( $arguments['slug'] ?? '' ) );
			if ( '' === $slug ) {
				return array( 'error' => 'slug is required for import — browse the source first to get one.' );
			}
			return 'wordpress' === $source ? $this->import_wordpress( $slug ) : $this->import_agentic( $slug );
		}

		return array( 'error' => 'Unknown action. Use browse or import.' );
	}

	/**
	 * List every skill in WordPress.org's agent-skills repo, fetching each
	 * file's frontmatter so the listing has real names/descriptions to show.
	 * Mirrors admin/skills.php's loadGithubSource() exactly, with a transient
	 * cache since it's the same N+1-fetch pattern the existing UI already
	 * accepts, and repeated tool calls across conversations shouldn't each
	 * re-hit GitHub from scratch.
	 *
	 * @return array
	 */
	private function browse_wordpress(): array {
		$cache_key = 'agentic_skill_browse_wordpress';
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return array( 'skills' => $cached );
		}

		$tree_url = sprintf(
			'https://api.github.com/repos/%s/%s/git/trees/%s?recursive=1',
			self::WORDPRESS_OWNER,
			self::WORDPRESS_REPO,
			self::WORDPRESS_BRANCH
		);

		$tree_response = wp_remote_get( $tree_url, array( 'headers' => array( 'Accept' => 'application/vnd.github+json' ) ) );
		if ( is_wp_error( $tree_response ) || 200 !== wp_remote_retrieve_response_code( $tree_response ) ) {
			return array( 'error' => 'Could not reach WordPress.org\'s skill repository. Please try again.' );
		}

		$tree = json_decode( wp_remote_retrieve_body( $tree_response ), true );
		$slugs = array();
		foreach ( ( $tree['tree'] ?? array() ) as $entry ) {
			$path = (string) ( $entry['path'] ?? '' );
			if ( preg_match( '#^skills/([^/]+)/SKILL\.md$#', $path, $m ) ) {
				$slugs[] = $m[1];
			}
		}

		$skills = array();
		foreach ( $slugs as $slug ) {
			$raw_url = sprintf(
				// phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Server-side text fetch (a Markdown doc, not a script/asset) for the explicit, user-initiated "browse community skills" action; nothing runs until the site owner opens this screen.
				'https://raw.githubusercontent.com/%s/%s/%s/skills/%s/SKILL.md',
				self::WORDPRESS_OWNER,
				self::WORDPRESS_REPO,
				self::WORDPRESS_BRANCH,
				rawurlencode( $slug )
			);
			$raw_response = wp_remote_get( $raw_url );
			if ( is_wp_error( $raw_response ) || 200 !== wp_remote_retrieve_response_code( $raw_response ) ) {
				continue;
			}
			$raw = wp_remote_retrieve_body( $raw_response );
			if ( '' === $raw ) {
				continue;
			}
			$identity = Skills_Registry::parse_front_matter_identity( $raw );
			$skills[] = array(
				'slug'        => $slug,
				'name'        => '' !== $identity['name'] ? $identity['name'] : $slug,
				'description' => $identity['description'],
			);
		}

		usort( $skills, static fn( array $a, array $b ) => strcasecmp( (string) $a['name'], (string) $b['name'] ) );

		set_transient( $cache_key, $skills, HOUR_IN_SECONDS );

		return array( 'skills' => $skills );
	}

	/**
	 * List Agentic's curated recommended-skills feed. Mirrors the
	 * "Recommended Skills" grid's fetch logic exactly (featured endpoint,
	 * falling back to a plain paged listing if nothing is marked featured).
	 *
	 * @return array
	 */
	private function browse_agentic(): array {
		$response = wp_remote_get( self::AGENTIC_API . '/featured' );
		$skills   = array();

		if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
			$skills = json_decode( wp_remote_retrieve_body( $response ), true );
		}

		if ( empty( $skills ) ) {
			$fallback = wp_remote_get( self::AGENTIC_API . '?per_page=12' );
			if ( ! is_wp_error( $fallback ) && 200 === wp_remote_retrieve_response_code( $fallback ) ) {
				$decoded = json_decode( wp_remote_retrieve_body( $fallback ), true );
				$skills  = $decoded['skills'] ?? array();
			}
		}

		if ( ! is_array( $skills ) ) {
			return array( 'error' => 'Could not reach the recommended skills feed. Please try again.' );
		}

		$out = array();
		foreach ( $skills as $skill ) {
			$out[] = array(
				'slug'        => (string) ( $skill['slug'] ?? '' ),
				'name'        => (string) ( $skill['name'] ?? '' ),
				'description' => (string) ( $skill['description'] ?? '' ),
				'version'     => (string) ( $skill['version'] ?? '1.0.0' ),
			);
		}

		return array( 'skills' => $out );
	}

	/**
	 * Import one skill from WordPress.org's repository by slug.
	 *
	 * @param string $slug Skill slug, from a prior browse() result.
	 * @return array
	 */
	private function import_wordpress( string $slug ): array {
		$raw_url = sprintf(
			// phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Server-side text fetch (a Markdown doc, not a script/asset) for the explicit, user-initiated "import a community skill" action; nothing runs until the site owner opens this screen.
			'https://raw.githubusercontent.com/%s/%s/%s/skills/%s/SKILL.md',
			self::WORDPRESS_OWNER,
			self::WORDPRESS_REPO,
			self::WORDPRESS_BRANCH,
			rawurlencode( $slug )
		);
		$response = wp_remote_get( $raw_url );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return array( 'error' => sprintf( 'Could not find a skill with slug "%s" in the WordPress.org repository.', $slug ) );
		}

		$raw = wp_remote_retrieve_body( $response );
		if ( '' === $raw ) {
			return array( 'error' => 'That skill\'s file was empty.' );
		}

		$identity = Skills_Registry::parse_front_matter_identity( $raw );

		$id = Skills_Registry::import_from_hub(
			array(
				'name'        => '' !== $identity['name'] ? $identity['name'] : $slug,
				'description' => $identity['description'],
				'content'     => $raw,
				'source'      => 'wordpress',
				'source_id'   => $slug,
				'version'     => '1.0.0',
				'author'      => 'WordPress.org',
			)
		);

		if ( ! $id ) {
			return array( 'error' => 'Could not import this skill.' );
		}

		$skill = Skills_Registry::get( $id );
		return array(
			'ok'    => true,
			'skill' => array(
				'id'     => (int) $skill['id'],
				'slug'   => $skill['slug'],
				'name'   => $skill['name'],
				'source' => $skill['source'],
			),
		);
	}

	/**
	 * Import one skill from Agentic's recommended feed by slug.
	 *
	 * @param string $slug Skill slug, from a prior browse() result.
	 * @return array
	 */
	private function import_agentic( string $slug ): array {
		$response = wp_remote_get( self::AGENTIC_API . '/' . rawurlencode( $slug ) );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return array( 'error' => sprintf( 'Could not find a recommended skill with slug "%s".', $slug ) );
		}

		$detail = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $detail ) ) {
			return array( 'error' => 'Could not read that skill\'s details.' );
		}

		$id = Skills_Registry::import_from_hub(
			array(
				'name'        => $detail['name'] ?? $slug,
				'description' => $detail['description'] ?? '',
				'content'     => $detail['content'] ?? '',
				'source'      => 'agentic',
				'source_id'   => $slug,
				'version'     => $detail['version'] ?? '1.0.0',
				'author'      => $detail['author'] ?? '',
			)
		);

		if ( ! $id ) {
			return array( 'error' => 'Could not import this skill.' );
		}

		// Best-effort import-count tracking, matching the classic UI — never
		// let a failure here affect the (already-successful) import result.
		wp_remote_post(
			self::AGENTIC_API . '/' . rawurlencode( $slug ) . '/import',
			array(
				'timeout'  => 5,
				'blocking' => false,
			)
		);

		$skill = Skills_Registry::get( $id );
		return array(
			'ok'    => true,
			'skill' => array(
				'id'     => (int) $skill['id'],
				'slug'   => $skill['slug'],
				'name'   => $skill['name'],
				'source' => $skill['source'],
			),
		);
	}

	/**
	 * Get tool annotations.
	 *
	 * @return array
	 */
	public function get_annotations(): array {
		return array(
			'readonly'    => false,
			'destructive' => false,
		);
	}
}

return new Browse_Community_Skills();
