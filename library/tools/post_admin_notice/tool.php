<?php
/**
 * Tool: Post Admin Notice
 *
 * Store an admin notice to be shown in the WordPress dashboard. Used by
 * scheduled audits to alert site owners about score changes.
 *
 * @package Agentic\Tools
 */

declare(strict_types=1);

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Post_Admin_Notice extends \Agentic\Tool_Base {

	public function get_name(): string {
		return 'post_admin_notice';
	}

	public function get_description(): string {
		return 'Store an admin notice to be shown in the WordPress dashboard. Used by scheduled audits to alert site owners about score changes.';
	}

	public function get_category(): string {
		return 'site-audit';
	}

	public function get_parameters(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'message' => array(
					'type'        => 'string',
					'description' => 'The notice message to display.',
				),
				'type'    => array(
					'type'        => 'string',
					'enum'        => array( 'info', 'success', 'warning', 'error' ),
					'description' => 'The notice type: info, success, warning, or error.',
				),
			),
			'required'   => array( 'message', 'type' ),
		);
	}

	public function get_annotations(): array {
		return array(
			'read_only'   => false,
			'destructive' => false,
		);
	}

	public function execute( array $args ): array {
		update_option(
			'agent_builder_site_auditor_notice',
			array(
				'message' => $args['message'],
				'type'    => $args['type'],
				'date'    => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		return array( 'posted' => true );
	}
}

return new Post_Admin_Notice();
