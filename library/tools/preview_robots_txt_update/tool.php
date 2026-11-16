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
		$plan = \Agentic\Tool_Helpers::plan_robots_ai_rules( $arguments['allow_bots'] ?? null );

		if ( empty( $plan['added_bots'] ) ) {
			return array(
				'success' => true,
				'message' => 'All specified AI bots are already allowed.',
				'changed' => false,
			);
		}

		if ( 'physical_file' === $plan['source'] ) {
			return array(
				'success'       => true,
				'current'       => $plan['current'],
				'lines_to_add'  => $plan['block'],
				'added_bots'    => $plan['added_bots'],
				'source'        => 'physical_file',
				'physical_file' => $plan['path'],
				'note'          => 'This site has a physical robots.txt file. The plugin never writes files at the site root, so update_robots_txt cannot apply this: the administrator has to add these lines to the file themselves (or delete the file so WordPress generates robots.txt and the plugin adds the rules).',
			);
		}

		return array(
			'success'    => true,
			'current'    => $plan['current'],
			'proposed'   => $plan['proposed'],
			'added_bots' => $plan['added_bots'],
			'source'     => 'virtual',
			'note'       => 'Review the proposed content, then call update_robots_txt to apply. The rules are added to the robots.txt WordPress generates; no file is written.',
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
