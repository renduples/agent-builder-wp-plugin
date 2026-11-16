<?php
/**
 * Tool: Update Robots Txt
 *
 * Allow AI search bots in robots.txt. The rules are stored in an option and
 * added to the robots.txt WordPress generates; no file is ever written.
 *
 * @package Agentic\Tools
 */

declare(strict_types=1);

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds robots.txt rules that allow AI search bots (served virtually).
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
		return 'Allow AI search bots in robots.txt. Adds the rules to the robots.txt WordPress generates (no file is written). If the site has a physical robots.txt file, nothing is changed and the exact lines for the administrator to add by hand are returned. ALWAYS use preview_robots_txt_update first to show the user what will change before calling this.';
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
		if ( ! current_user_can( 'manage_options' ) ) {
			return array(
				'success' => false,
				'error'   => 'You do not have permission to change robots.txt rules.',
			);
		}

		$plan = \Agentic\Tool_Helpers::plan_robots_ai_rules( $arguments['allow_bots'] ?? null );

		if ( 'physical_file' === $plan['source'] ) {
			if ( empty( $plan['added_bots'] ) ) {
				return array(
					'success' => true,
					'changed' => false,
					'message' => 'All specified AI bots are already allowed by the robots.txt file. No changes needed.',
				);
			}
			return array(
				'success'       => false,
				'changed'       => false,
				'physical_file' => true,
				'path'          => $plan['path'],
				'lines_to_add'  => $plan['block'],
				'message'       => 'This site has a physical robots.txt file, so WordPress serves that file and the plugin cannot add rules to it (the plugin never writes files at the site root). Ask the administrator to add these lines at the top of ' . $plan['path'] . ' themselves, or to delete that file so WordPress generates robots.txt and the plugin can add the rules automatically.',
			);
		}

		if ( empty( $plan['added_bots'] ) ) {
			return array(
				'success' => true,
				'message' => 'All specified AI bots are already allowed. No changes needed.',
				'changed' => false,
			);
		}

		\Agentic\Virtual_Site_Files::set_robots_allowed_bots( $plan['allowed_bots'] );
		delete_transient( 'agentic_ai_radar_last_scan' );

		$served = \Agentic\Virtual_Site_Files::robots_txt();

		$result = array(
			'success'     => true,
			'applied'     => true,
			'changed'     => true,
			'added_bots'  => $plan['added_bots'],
			'url'         => home_url( '/robots.txt' ),
			'new_content' => $served['content'],
			'message'     => 'robots.txt rules saved. WordPress now serves them at ' . home_url( '/robots.txt' ) . ' (no file was written).',
		);
		if ( ! get_option( 'blog_public' ) ) {
			$result['note'] = 'The site is set to discourage search engines (Settings > Reading), so WordPress serves "Disallow: /" and these rules are only added once that setting is turned off.';
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
