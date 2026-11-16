<?php
/**
 * Tool: Update Robots Txt
 *
 * Apply updates to robots.txt for AI bot access.
 *
 * @package Agentic\Tools
 */

declare(strict_types=1);

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes robots.txt changes to allow AI search bots.
 */
class Update_Robots_Txt extends \Agentic\Tool_Base {

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'update_robots_txt';
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return 'Write updated robots.txt that allows AI search bots. ALWAYS use preview_robots_txt_update first to show the user what will change before calling this.';
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
	 * Execute the robots.txt update.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array Write result with confirmation.
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
				'message' => 'All specified AI bots are already allowed. No changes needed.',
				'changed' => false,
			);
		}

		$header      = "# AI Search Engine Access\n";
		$addition    = $header . implode( "\n", $additions );
		$new_content = ! empty( $current ) ? $addition . "\n" . ltrim( $current ) : $addition . "\nUser-agent: *\nDisallow:\n";

		// Back up the existing file before overwriting.
		$backup_path = \Agentic\Tool_Helpers::backup_file( $robots['path'] );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$written = file_put_contents( $robots['path'], $new_content );
		if ( false === $written ) {
			return array(
				'success' => false,
				'error'   => 'File write failed. Check permissions on: ' . $robots['path'],
			);
		}

		delete_transient( 'agentic_ai_radar_last_scan' );

		$result = array(
			'success'     => true,
			'applied'     => true,
			'bytes'       => $written,
			'path'        => $robots['path'],
			'new_content' => $new_content,
			'message'     => 'robots.txt updated successfully.',
		);

		if ( $backup_path ) {
			$result['backup'] = $backup_path;
		}

		return $result;
	}

	/**
	 * Get the tool annotations.
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

return new Update_Robots_Txt();
