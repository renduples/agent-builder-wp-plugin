<?php
/**
 * Tool: Preview Robots Txt Update
 *
 * Preview proposed robots.txt changes for AI bot access without writing.
 *
 * @package Agentic\Tools
 */

declare(strict_types=1);

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Previews proposed robots.txt updates to allow AI search bots.
 */
class Preview_Robots_Txt_Update extends \Agentic\Tool_Base {

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'preview_robots_txt_update';
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return 'Preview proposed robots.txt changes that would allow AI search bots. Shows a before/after diff without writing anything. Use update_robots_txt to apply.';
	}

	/**
	 * Get the tool category.
	 *
	 * @return string
	 */
	public function get_category(): string {
		return 'ai-visibility';
	}

	/**
	 * Get the tool parameters.
	 *
	 * @return array
	 */
	public function get_parameters(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'allow_bots' => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => 'Bot names to allow. Defaults to all major AI bots.',
				),
			),
		);
	}

	/**
	 * Execute the robots.txt preview.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array Preview showing current and proposed robots.txt content.
	 */
	public function execute( array $arguments ): array {
		$allow_bots = $arguments['allow_bots'] ?? array( 'GPTBot', 'ChatGPT-User', 'ClaudeBot', 'Google-Extended', 'PerplexityBot', 'anthropic-ai' );

		$robots = \Agentic\Tool_Helpers::read_robots_txt();
		if ( ! $robots['writable'] ) {
			return array(
				'success' => false,
				'error'   => 'robots.txt location is not writable. Path: ' . $robots['path'],
			);
		}

		$current   = $robots['content'];
		$parsed    = \Agentic\Tool_Helpers::parse_robots_bots( $current );
		$additions = array();

		foreach ( $allow_bots as $bot ) {
			$access = $parsed['bot_access'][ $bot ] ?? 'allowed';
			if ( 'allowed' !== $access ) {
				$additions[] = "User-agent: {$bot}";
				$additions[] = 'Allow: /';
				$additions[] = '';
			}
		}

		if ( empty( $additions ) ) {
			return array(
				'success' => true,
				'message' => 'All specified AI bots are already allowed.',
				'changed' => false,
			);
		}

		$header      = "# AI Search Engine Access\n# Added by AI Radar (Agent Builder — agentic-plugin.com)\n";
		$addition    = $header . implode( "\n", $additions );
		$new_content = ! empty( $current ) ? $addition . "\n" . ltrim( $current ) : $addition . "\nUser-agent: *\nDisallow:\n";

		return array(
			'success'    => true,
			'current'    => $current ? $current : '(no robots.txt — will be created)',
			'proposed'   => $new_content,
			'added_bots' => array_values( array_filter( $additions, fn( $l ) => str_starts_with( $l, 'User-agent' ) ) ),
			'source'     => $robots['source'],
			'write_path' => $robots['path'],
			'note'       => 'Review the proposed content, then call update_robots_txt to apply.',
		);
	}

	/**
	 * Get the tool annotations.
	 *
	 * @return array
	 */
	public function get_annotations(): array {
		return array(
			'readonly' => true,
		);
	}
}

return new Preview_Robots_Txt_Update();
