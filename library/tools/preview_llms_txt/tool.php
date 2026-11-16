<?php
/**
 * Tool: Preview Llms Txt
 *
 * Preview generated llms.txt content without writing to disk.
 *
 * @package Agentic\Tools
 */

declare(strict_types=1);

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates and previews llms.txt content for AI platform discovery.
 */
class Preview_Llms_Txt extends \Agentic\Tool_Base {

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'preview_llms_txt';
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return 'Generate and preview the llms.txt file content without writing to disk. Shows what would be created for AI platform discovery. Use generate_llms_txt to write.';
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
				'include_posts' => array(
					'type'        => 'integer',
					'description' => 'Number of recent posts to include (1-20). Defaults to 10.',
				),
			),
		);
	}

	/**
	 * Execute the llms.txt preview.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array Generated content preview.
	 */
	public function execute( array $arguments ): array {
		$include_posts = min( max( (int) ( $arguments['include_posts'] ?? 10 ), 1 ), 20 );
		$current       = \Agentic\Virtual_Site_Files::llms_txt();

		$content = Generate_Llms_Txt::build_content( $include_posts );

		return array(
			'llms_txt_content' => $content,
			'line_count'       => substr_count( $content, "\n" ) + 1,
			'url'              => home_url( '/llms.txt' ),
			'already_exists'   => 'none' !== $current['source'],
			'current_source'   => $current['source'],
			'message'          => 'physical_file' === $current['source']
				? 'Preview only. Note: this site has a physical llms.txt file, which the web server serves instead of the plugin\'s version; the administrator would need to replace or delete it.'
				: 'Preview only — nothing saved. Call generate_llms_txt to publish it at /llms.txt (served by the plugin, no file written).',
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

return new Preview_Llms_Txt();
