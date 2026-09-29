<?php
/**
 * Tool: manage_skill
 *
 * Create, list, get, update, delete, and reset skills — the same rows the
 * classic Skills admin page manages, via the shared Skills_Registry data
 * layer.
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
 * Manage skills — reusable how-to instructions agents load on demand.
 */
class Manage_Skill extends \Agentic\Tool_Base {

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'manage_skill';
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return 'Create, list, get, update, delete, or reset a skill — reusable how-to instructions that teach an agent when and how to perform a workflow. Use this to save a new skill after drafting its content, or to look up/edit/remove an existing one.';
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
				'action'        => array(
					'type'        => 'string',
					'enum'        => array( 'list', 'get', 'create', 'update', 'delete', 'reset' ),
					'description' => "list: show existing skills. get: read one skill's full content. create: make a new one. update: change an existing one. delete: remove a skill (only allowed for skills you created or fully replaced — built-in skills that haven't been customized can't be deleted, only edited or reset). reset: restore a built-in skill to its shipped version, discarding edits.",
				),
				'id'            => array(
					'type'        => 'integer',
					'description' => 'Skill id. Required for get/update/delete/reset unless slug is given instead.',
				),
				'slug'          => array(
					'type'        => 'string',
					'description' => 'Skill slug. Alternative to id for get/update/delete/reset.',
				),
				'name'          => array(
					'type'        => 'string',
					'description' => 'Human-readable skill name (also used to derive the slug on create). Required for create unless a full SKILL.md is passed via content.',
				),
				'description'   => array(
					'type'        => 'string',
					'description' => 'The trigger description — what request should make an agent use this skill, and what NOT to trigger on. Shown to agents in their prompt index, so it must be specific. Required for create unless a full SKILL.md is passed via content.',
				),
				'allowed_tools' => array(
					'type'        => 'string',
					'description' => 'Space-separated list of tool names this skill\'s instructions reference (matches SKILL.md\'s "allowed-tools" field). Optional.',
				),
				'content'       => array(
					'type'        => 'string',
					'description' => 'The skill body (markdown — workflow steps, rules; no frontmatter needed if name/description/allowed_tools are also given). Alternatively, pass a complete SKILL.md including "---" frontmatter here and omit name/description/allowed_tools.',
				),
				'agent_slug'    => array(
					'type'        => 'string',
					'description' => 'Scope this skill to one specific agent\'s slug. Leave empty (default) to make it available to every agent. For more than one agent, use agent_slugs instead.',
				),
				'agent_slugs'   => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => 'Scope this skill to two or more specific agents\' slugs. Takes precedence over agent_slug if both are given. Leave empty/omitted to make it available to every agent.',
				),
			),
			'required'   => array( 'action' ),
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

		if ( 'list' === $action ) {
			return $this->do_list( $arguments );
		}
		if ( 'get' === $action ) {
			return $this->do_get( $arguments );
		}
		if ( 'create' === $action ) {
			return $this->do_create( $arguments );
		}
		if ( 'update' === $action ) {
			return $this->do_update( $arguments );
		}
		if ( 'delete' === $action ) {
			return $this->do_delete( $arguments );
		}
		if ( 'reset' === $action ) {
			return $this->do_reset( $arguments );
		}

		return array( 'error' => 'Unknown action. Use list, get, create, update, delete, or reset.' );
	}

	/**
	 * List skills, optionally filtered by agent.
	 *
	 * @param array $args Tool arguments.
	 * @return array
	 */
	private function do_list( array $args ): array {
		$agent_filter = isset( $args['agent_slug'] ) ? sanitize_key( (string) $args['agent_slug'] ) : '';

		$rows = array();
		foreach ( Skills_Registry::get_all() as $skill ) {
			$scoped_to = Skills_Registry::decode_agent_slugs( (string) ( $skill['agent_slug'] ?? '' ) );
			if ( '' !== $agent_filter && ! empty( $scoped_to ) && ! in_array( $agent_filter, $scoped_to, true ) ) {
				continue;
			}
			$rows[] = $this->summarize( $skill );
		}
		return array( 'skills' => $rows );
	}

	/**
	 * Resolve the agent-scope arguments — agent_slugs (array, two or more
	 * agents) takes precedence over agent_slug (a single agent) when both
	 * are given.
	 *
	 * @param array $args Tool arguments.
	 * @return array<int, string>|string|null Scope to pass to Skills_Registry,
	 *                                         or null when neither argument was
	 *                                         given (do_update uses null to mean
	 *                                         "leave the current scope alone").
	 */
	private function resolve_agent_scope( array $args ) {
		if ( isset( $args['agent_slugs'] ) && is_array( $args['agent_slugs'] ) ) {
			return array_map( 'sanitize_key', array_map( 'strval', $args['agent_slugs'] ) );
		}
		if ( isset( $args['agent_slug'] ) ) {
			return sanitize_key( (string) $args['agent_slug'] );
		}
		return null;
	}

	/**
	 * Get a single skill's full content.
	 *
	 * @param array $args Tool arguments.
	 * @return array
	 */
	private function do_get( array $args ): array {
		$skill = $this->resolve_skill( $args );
		if ( ! $skill ) {
			return array( 'error' => $this->not_found_message( $args ) );
		}
		$identity = Skills_Registry::parse_front_matter_identity( (string) $skill['content'] );
		return array(
			'ok'       => true,
			'skill'    => $this->summarize( $skill, true ),
			'warnings' => Skills_Registry::validate_spec_fields( $this->spec_name( $identity, (string) $skill['name'] ), (string) $skill['description'] ),
		);
	}

	/**
	 * Create a new local skill.
	 *
	 * @param array $args Tool arguments.
	 * @return array
	 */
	private function do_create( array $args ): array {
		$content       = isset( $args['content'] ) ? (string) $args['content'] : '';
		$name          = isset( $args['name'] ) ? sanitize_text_field( (string) $args['name'] ) : '';
		$description   = isset( $args['description'] ) ? sanitize_text_field( (string) $args['description'] ) : '';
		$allowed_tools = isset( $args['allowed_tools'] ) ? sanitize_text_field( (string) $args['allowed_tools'] ) : '';
		$agent_slug    = $this->resolve_agent_scope( $args ) ?? '';

		$full_content = $this->build_full_content( $content, $name, $description, $allowed_tools );
		if ( '' === $full_content ) {
			return array( 'error' => 'Provide either a full SKILL.md in content, or name + description (+ optional content body).' );
		}

		// Prefer the explicit name/description args (the common structured-fields
		// path) over what parse_front_matter_identity() reads back out of the
		// frontmatter build_full_content() just assembled from those same
		// values — its "name:" field is spec-mandated to be a slug (see
		// validate_spec_fields()), not the human-readable display name, so
		// trusting it here would silently replace "Test Ping Reply" with
		// "test-ping-reply". Only fall back to the parsed identity when no
		// explicit name/description was given — i.e. the caller passed a
		// complete pre-made SKILL.md straight through via content.
		$identity          = Skills_Registry::parse_front_matter_identity( $full_content );
		$final_name        = '' !== $name ? $name : $identity['name'];
		$final_description = '' !== $description ? $description : $identity['description'];

		if ( '' === $final_name || '' === $final_description ) {
			return array( 'error' => 'A skill needs both a name and a description — the description is what an agent matches against to decide when to use it.' );
		}

		$id = Skills_Registry::create(
			array(
				'name'        => $final_name,
				'description' => $final_description,
				'content'     => $full_content,
				'agent_slug'  => $agent_slug,
				'source'      => 'local',
				'enabled'     => true,
			)
		);
		if ( ! $id ) {
			return array( 'error' => 'Could not create the skill.' );
		}

		$skill = Skills_Registry::get( $id );
		return array(
			'ok'       => true,
			'skill'    => $this->summarize( $skill ),
			'warnings' => Skills_Registry::validate_spec_fields( $this->spec_name( $identity, $final_name ), $final_description ),
		);
	}

	/**
	 * Update an existing skill's content and/or scope.
	 *
	 * @param array $args Tool arguments.
	 * @return array
	 */
	private function do_update( array $args ): array {
		$skill = $this->resolve_skill( $args );
		if ( ! $skill ) {
			return array( 'error' => $this->not_found_message( $args ) );
		}

		$content       = isset( $args['content'] ) ? (string) $args['content'] : null;
		$name          = isset( $args['name'] ) ? sanitize_text_field( (string) $args['name'] ) : null;
		$description   = isset( $args['description'] ) ? sanitize_text_field( (string) $args['description'] ) : null;
		$allowed_tools = isset( $args['allowed_tools'] ) ? sanitize_text_field( (string) $args['allowed_tools'] ) : null;
		$agent_slug    = $this->resolve_agent_scope( $args );

		$update_data = array();

		if ( null !== $content || null !== $name || null !== $description || null !== $allowed_tools ) {
			$base_content = null !== $content ? $content : '';
			$full_content = $this->build_full_content(
				$base_content,
				$name ?? '',
				$description ?? '',
				$allowed_tools ?? '',
				(string) $skill['content']
			);
			if ( '' !== $full_content ) {
				$update_data['content'] = $full_content;

				// Same precedence as do_create(): an explicit name/description
				// arg wins over the frontmatter's spec-mandated slug-shaped
				// "name:" field, which is only authoritative when the caller
				// passed a complete pre-made SKILL.md with no separate args.
				$identity = Skills_Registry::parse_front_matter_identity( $full_content );
				if ( null !== $name ) {
					$update_data['name'] = $name;
				} elseif ( '' !== $identity['name'] ) {
					$update_data['name'] = $identity['name'];
				}
				if ( null !== $description ) {
					$update_data['description'] = $description;
				} elseif ( '' !== $identity['description'] ) {
					$update_data['description'] = $identity['description'];
				}
			}
		}

		if ( null !== $agent_slug ) {
			$update_data['agent_slug'] = $agent_slug;
		}

		if ( empty( $update_data ) ) {
			return array( 'error' => 'Nothing to update — provide at least one of content, name, description, allowed_tools, agent_slug, or agent_slugs.' );
		}

		Skills_Registry::update( (int) $skill['id'], $update_data );
		$updated = Skills_Registry::get( (int) $skill['id'] );

		$identity = Skills_Registry::parse_front_matter_identity( (string) $updated['content'] );
		return array(
			'ok'       => true,
			'skill'    => $this->summarize( $updated ),
			'warnings' => Skills_Registry::validate_spec_fields( $this->spec_name( $identity, (string) $updated['name'] ), (string) $updated['description'] ),
		);
	}

	/**
	 * Delete a skill. Un-customized built-in skills are protected — they
	 * come back on the next plugin update via seed_core_skills() anyway, so
	 * "delete" isn't a meaningful action for them; edit or reset instead.
	 *
	 * @param array $args Tool arguments.
	 * @return array
	 */
	private function do_delete( array $args ): array {
		$skill = $this->resolve_skill( $args );
		if ( ! $skill ) {
			return array( 'error' => $this->not_found_message( $args ) );
		}

		if ( 'core' === ( $skill['source'] ?? '' ) && ! Skills_Registry::is_customized( $skill ) ) {
			return array( 'error' => "This is a built-in skill that hasn't been customized — it can't be deleted, only edited or reset. Deleting is only available for skills you created or fully replaced." );
		}

		$ok = Skills_Registry::delete( (int) $skill['id'] );
		if ( ! $ok ) {
			return array( 'error' => 'Could not delete the skill.' );
		}
		return array( 'ok' => true );
	}

	/**
	 * Restore a built-in (core) skill to its shipped version, discarding
	 * any customization.
	 *
	 * @param array $args Tool arguments.
	 * @return array
	 */
	private function do_reset( array $args ): array {
		$skill = $this->resolve_skill( $args );
		if ( ! $skill ) {
			return array( 'error' => $this->not_found_message( $args ) );
		}
		if ( 'core' !== ( $skill['source'] ?? '' ) ) {
			return array( 'error' => "Only built-in skills can be reset — this one was created locally, so there's no shipped version to restore." );
		}

		$source_id = (string) ( $skill['source_id'] ? $skill['source_id'] : $skill['slug'] );
		$bundled   = Skills_Registry::get_bundled_content( $source_id );
		if ( null === $bundled ) {
			return array( 'error' => 'Could not find the shipped version of this skill to restore.' );
		}

		$identity = Skills_Registry::parse_front_matter_identity( $bundled );
		Skills_Registry::update(
			(int) $skill['id'],
			array(
				'name'        => '' !== $identity['name'] ? $identity['name'] : $skill['name'],
				'description' => '' !== $identity['description'] ? $identity['description'] : $skill['description'],
				'content'     => $bundled,
				'source_hash' => hash( 'sha256', $bundled ),
			)
		);
		$updated = Skills_Registry::get( (int) $skill['id'] );

		return array(
			'ok'    => true,
			'skill' => $this->summarize( $updated ),
		);
	}

	/**
	 * Resolve an id-or-slug argument pair into a skill row.
	 *
	 * @param array $args Tool arguments.
	 * @return array|null
	 */
	private function resolve_skill( array $args ): ?array {
		if ( ! empty( $args['id'] ) ) {
			return Skills_Registry::get( absint( $args['id'] ) );
		}
		if ( ! empty( $args['slug'] ) ) {
			return Skills_Registry::get_by_slug( sanitize_key( (string) $args['slug'] ) );
		}
		return null;
	}

	/**
	 * Build a "not found" / missing-identifier error message.
	 *
	 * @param array $args Tool arguments.
	 * @return string
	 */
	private function not_found_message( array $args ): string {
		if ( ! empty( $args['id'] ) ) {
			return sprintf( 'No skill found with id %d.', absint( $args['id'] ) );
		}
		if ( ! empty( $args['slug'] ) ) {
			return sprintf( 'No skill found with slug "%s".', sanitize_key( (string) $args['slug'] ) );
		}
		return 'id or slug is required.';
	}

	/**
	 * The name to validate against agentskills.io's slug pattern
	 * (validate_spec_fields()) — the SKILL.md frontmatter's "name:" field
	 * when one was parsed (that's the field the spec actually governs, and
	 * what the classic admin/skills.php page validates), falling back to
	 * slugifying the human-readable display name when content had none.
	 *
	 * @param array  $identity     Result of Skills_Registry::parse_front_matter_identity().
	 * @param string $display_name The DB row's human-readable name.
	 * @return string
	 */
	private function spec_name( array $identity, string $display_name ): string {
		return '' !== $identity['name'] ? $identity['name'] : sanitize_title( $display_name );
	}

	/**
	 * Build a full SKILL.md string. If $content already has frontmatter
	 * (starts with "---"), it's used as-is. Otherwise frontmatter is
	 * assembled from name/description/allowed_tools and $content becomes the
	 * body. When updating, $existing_content supplies fallback values for
	 * any of name/description/allowed_tools the caller didn't pass, so a
	 * partial update (e.g. just a new body) doesn't blank out the others.
	 *
	 * @param string $content        New body or full SKILL.md.
	 * @param string $name           Skill name, if not already in $content's frontmatter.
	 * @param string $description    Trigger description, if not already in $content's frontmatter.
	 * @param string $allowed_tools  Space-separated tool names, if not already in $content's frontmatter.
	 * @param string $existing_content Previous full SKILL.md, for fallback values on update.
	 * @return string Full SKILL.md, or '' if nothing usable was provided.
	 */
	private function build_full_content( string $content, string $name, string $description, string $allowed_tools, string $existing_content = '' ): string {
		$content = trim( $content );
		if ( '' !== $content && str_starts_with( $content, '---' ) ) {
			return $content;
		}

		$existing_identity = '' !== $existing_content
			? Skills_Registry::parse_front_matter_identity( $existing_content )
			: array(
				'name'        => '',
				'description' => '',
			);

		if ( '' === $name ) {
			$name = $existing_identity['name'];
		}
		if ( '' === $description ) {
			$description = $existing_identity['description'];
		}
		if ( '' === $allowed_tools && '' !== $existing_content ) {
			$allowed_tools = implode( ' ', Skills_Registry::parse_front_matter_tools( $existing_content ) );
		}
		if ( '' === $content && '' !== $existing_content ) {
			// Body wasn't re-sent — keep the existing body, only frontmatter changed.
			$content = (string) preg_replace( '/^---\s*\n.*?\n---\s*\n\n?/s', '', $existing_content );
		}

		if ( '' === $name && '' === $description && '' === $content ) {
			return '';
		}

		$slug_name   = sanitize_title( $name );
		$frontmatter = "---\nname: {$slug_name}\ndescription: \"" . str_replace( '"', '\\"', $description ) . "\"\n";
		if ( '' !== $allowed_tools ) {
			$frontmatter .= "allowed-tools: {$allowed_tools}\n";
		}
		$frontmatter .= "---\n\n";

		return $frontmatter . $content;
	}

	/**
	 * Flatten a Skills_Registry row for the LLM.
	 *
	 * @param array $skill        Skill row.
	 * @param bool  $with_content Include full content + parsed allowed_tools.
	 * @return array
	 */
	private function summarize( array $skill, bool $with_content = false ): array {
		$row = array(
			'id'            => (int) $skill['id'],
			'slug'          => $skill['slug'],
			'name'          => $skill['name'],
			'description'   => $skill['description'],
			'agent_slugs'   => Skills_Registry::decode_agent_slugs( (string) ( $skill['agent_slug'] ?? '' ) ),
			'source'        => $skill['source'],
			'enabled'       => (bool) $skill['enabled'],
			'is_core'       => 'core' === ( $skill['source'] ?? '' ),
			'is_customized' => Skills_Registry::is_customized( $skill ),
		);
		if ( $with_content ) {
			$row['content']       = $skill['content'];
			$row['allowed_tools'] = Skills_Registry::parse_front_matter_tools( (string) $skill['content'] );
		}
		return $row;
	}

	/**
	 * Get tool annotations.
	 *
	 * @return array
	 */
	public function get_annotations(): array {
		return array(
			'readonly'    => false,
			'destructive' => true,
		);
	}
}

return new Manage_Skill();
