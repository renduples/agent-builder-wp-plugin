<?php
/**
 * Tool: Recheck AI Crawler Access
 *
 * Re-checks whether AI crawlers are blocked after a robots.txt-related setting
 * changes, invalidates the cached AI Radar scan, and raises an admin notice if a
 * critical AI bot was newly blocked. Extracted from the AI Radar agent's
 * on_robots_option_updated event callback so the behaviour runs as a reviewed
 * library tool that a declarative manifest can bind to the `updated_option` hook.
 *
 * @package Agentic\Tools
 */

declare(strict_types=1);

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Re-checks AI crawler access when a robots-related option is updated.
 */
class Recheck_AI_Crawler_Access extends \Agentic\Tool_Base {

	/**
	 * Transient key for the cached AI Radar scan.
	 */
	private const SCAN_TRANSIENT = 'agentic_ai_radar_last_scan';

	/**
	 * Admin-notice option key.
	 */
	private const NOTICE_OPTION = 'agent_builder_ai_radar_notice';

	/**
	 * Option names whose changes can affect robots.txt / crawler access.
	 *
	 * @var string[]
	 */
	private const ROBOTS_OPTIONS = array(
		'disallow_crawl',
		'blog_public',
		'wpseo',
		'wpseo_titles',
		'rank_math_robots_extra_directives',
		'all-in-one-seo-pack',
	);

	/**
	 * Tool name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'recheck_ai_crawler_access';
	}

	/**
	 * Tool description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return 'Re-check AI crawler access after a robots.txt-related setting changes: invalidates the cached AI Radar scan and raises an admin notice if a critical AI bot (GPTBot, ChatGPT-User, ClaudeBot) was newly blocked.';
	}

	/**
	 * Tool category.
	 *
	 * @return string
	 */
	public function get_category(): string {
		return 'ai-visibility';
	}

	/**
	 * Writes an admin-notice option and invalidates a transient — not read-only.
	 *
	 * @return string
	 */
	public function get_risk_level(): string {
		return 'low';
	}

	/**
	 * Parameter schema.
	 *
	 * The three parameters mirror the `updated_option` hook arguments so a
	 * manifest event listener can map them positionally.
	 *
	 * @return array
	 */
	public function get_parameters(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'option'    => array(
					'type'        => 'string',
					'description' => 'Name of the option that changed.',
				),
				'old_value' => array(
					'description' => 'Previous option value (unused; accepted for hook-argument parity).',
				),
				'new_value' => array(
					'description' => 'New option value (unused; accepted for hook-argument parity).',
				),
			),
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Validated arguments.
	 * @return array
	 */
	public function execute( array $arguments ): array {
		$option = (string) ( $arguments['option'] ?? '' );

		// Only react to robots.txt-related option changes. An empty option (e.g.
		// a manual invocation) is treated as "check anyway".
		if ( '' !== $option && ! in_array( $option, self::ROBOTS_OPTIONS, true ) ) {
			return array(
				'relevant' => false,
				'option'   => $option,
			);
		}

		// Invalidate the cached scan so the next one is fresh.
		delete_transient( self::SCAN_TRANSIENT );

		$robots = \Agentic\Tool_Helpers::read_robots_txt();
		$parsed = \Agentic\Tool_Helpers::parse_robots_bots( (string) ( $robots['content'] ?? '' ) );

		$newly_blocked = array();
		foreach ( array( 'GPTBot', 'ChatGPT-User', 'ClaudeBot' ) as $bot ) {
			if ( 'allowed' !== ( $parsed['bot_access'][ $bot ] ?? 'allowed' ) ) {
				$newly_blocked[] = $bot;
			}
		}

		if ( ! empty( $newly_blocked ) ) {
			update_option(
				self::NOTICE_OPTION,
				array(
					'type'    => 'warning',
					'message' => '⚡ AI Radar: A recent settings change may have blocked AI crawlers (' . implode( ', ', $newly_blocked ) . ') from reading your site. ' .
						'<a href="/wp-admin/admin.php?page=agentic-chat">Open AI Radar to check →</a>',
					'time'    => time(),
				)
			);
		}

		return array(
			'relevant'      => true,
			'cache_flushed' => true,
			'newly_blocked' => $newly_blocked,
		);
	}
}

return new Recheck_AI_Crawler_Access();
