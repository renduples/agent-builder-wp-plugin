<?php
/**
 * Skill Commands — expose enabled skills as slash commands for the chat palette
 * and resolve a `/<slug>` prefix on an inbound message back into a skill.
 *
 * Two read-only responsibilities:
 *
 * 1. `for_js()` maps an agent's enabled skills (shared + agent-specific) to the
 *    same entry shape `Chat_Assets::get_slash_commands_for_js()` already emits,
 *    so a free install without the Pro `Slash_Commands` class still sees its
 *    skills in the `/` palette. Every entry is server-side: the client only
 *    collects an optional argument and posts the raw text back to `/chat`.
 *
 * 2. `parse()` splits a leading `/<slug>` off a message, and `injection_block()`
 *    turns a resolved skill back into a sanitised SKILL.md body block for the
 *    prompt. `parse()` never throws and returns null for a plain message, so an
 *    unknown or malformed slash prefix is left untouched by the caller.
 *
 * @package    Agent_Builder
 * @subpackage Includes
 * @since      4.4.0
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Slash-command surface built from the skills registry.
 */
class Skill_Commands {

	/**
	 * Build the slash-command entries for one agent's enabled skills.
	 *
	 * @param string $agent_slug Agent slug whose skills (shared + specific) to list.
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_js( string $agent_slug ): array {
		$result = array();
		foreach ( Skills_Registry::get_for_agent( $agent_slug ) as $skill ) {
			$slug = (string) ( $skill['slug'] ?? '' );
			if ( '' === $slug ) {
				continue;
			}

			$result[] = array(
				'name'        => $slug,
				'description' => (string) ( $skill['description'] ?? '' ),
				'client_side' => false,
				'has_args'    => true,
				'arg_hint'    => __( 'describe the task', 'agent-builder' ),
				'contexts'    => array( 'backend', 'frontend' ),
			);
		}
		return $result;
	}

	/**
	 * Split a leading `/<slug>` from a message.
	 *
	 * Returns `{ slug, args }` when the message starts with a slash command,
	 * or null for a plain message. The slug is returned exactly as typed (case
	 * preserved), so `/Help` is not silently rewritten to a lowercase `help`
	 * skill; the caller matches it against stored slugs case-sensitively.
	 *
	 * @param string $message Inbound message.
	 * @return array{slug: string, args: string}|null
	 */
	public static function parse( string $message ): ?array {
		$message = trim( $message );
		if ( '' === $message || '/' !== $message[0] ) {
			return null;
		}

		if ( ! preg_match( '#^/([^/\s]+)(?:\s+(.*))?$#s', $message, $matches ) ) {
			return null;
		}

		return array(
			'slug' => $matches[1],
			'args' => $matches[2] ?? '',
		);
	}

	/**
	 * Resolve a skill slug into a sanitised SKILL.md body block for the prompt.
	 *
	 * The body is passed through `wp_strip_all_tags()` so a hostile skill body
	 * cannot smuggle raw HTML (or a prompt-injection in a tag attribute) into the
	 * system prompt. Returns an empty string when the slug is not among the
	 * agent's enabled skills.
	 *
	 * @param string $agent_slug Agent slug to resolve the skill against.
	 * @param string $skill_slug Skill slug to inject.
	 * @return string
	 */
	public static function injection_block( string $agent_slug, string $skill_slug ): string {
		if ( '' === $skill_slug ) {
			return '';
		}

		foreach ( Skills_Registry::get_for_agent( $agent_slug ) as $skill ) {
			if ( (string) ( $skill['slug'] ?? '' ) !== $skill_slug ) {
				continue;
			}

			$body = wp_strip_all_tags( (string) ( $skill['content'] ?? '' ) );
			$body = self::neutralise_skill_delimiters( $body );
			return "\n\n[SKILL: " . $skill_slug . "]\n" . $body . "\n[/SKILL]\n";
		}

		return '';
	}

	/**
	 * Neutralise any embedded skill-block delimiters in a body so a hostile
	 * SKILL.md cannot close (or open) the wrapper block early and inject text
	 * outside it.
	 *
	 * `[/SKILL]` and `[SKILL` are replaced case-insensitively with inert text
	 * whose brackets are HTML entities, so they no longer read as delimiters.
	 *
	 * @param string $body Sanitised body text.
	 * @return string Body with embedded delimiters rendered inert.
	 */
	private static function neutralise_skill_delimiters( string $body ): string {
		$body = str_ireplace( '[/skill]', '&#91;/SKILL&#93;', $body );
		return str_ireplace( '[skill', '&#91;SKILL', $body );
	}

	/**
	 * Whether a slug is an enabled Pro `Slash_Commands` name.
	 *
	 * An enabled Pro command takes precedence over a same-named skill, so the
	 * skill path must not consume it. The `agentic_pro_slash_command_names`
	 * filter overrides the list (used by tests; Pro populates the default from
	 * `Slash_Commands::get_all()`).
	 *
	 * @param string $slug Slash slug as typed.
	 * @return bool
	 */
	public static function is_pro_command( string $slug ): bool {
		if ( '' === $slug ) {
			return false;
		}

		$names = apply_filters( 'agentic_pro_slash_command_names', self::pro_command_names() );

		foreach ( (array) $names as $name ) {
			if ( (string) $name === $slug ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Enabled Pro Slash_Commands names (empty when the Pro class is absent).
	 *
	 * @return string[]
	 */
	private static function pro_command_names(): array {
		if ( ! class_exists( '\\Agentic\\Slash_Commands' ) ) {
			return array();
		}

		$names = array();
		foreach ( \Agentic\Slash_Commands::get_all() as $cmd ) {
			if ( ! empty( $cmd['enabled'] ) && isset( $cmd['name'] ) ) {
				$names[] = (string) $cmd['name'];
			}
		}
		return $names;
	}
}
