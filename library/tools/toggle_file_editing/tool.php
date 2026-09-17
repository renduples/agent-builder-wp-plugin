<?php
/**
 * Tool: toggle_file_editing
 *
 * @package    Agent_Builder
 * @subpackage Tools
 * @since      2.0.0
 *
 * php version 8.1
 */

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Agentic\Tool_Base;

class Toggle_File_Editing extends Tool_Base {
	public function get_name(): string {
		return 'toggle_file_editing';
	}

	public function get_description(): string {
		return 'Enable or disable the theme and plugin file editor in wp-admin. Disabling file editing (DISALLOW_FILE_EDIT) is a security best practice.';
	}

	public function get_category(): string {
		return 'maintenance';
	}

	public function get_parameters(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'allow_editing' => array(
					'type'        => 'boolean',
					'description' => 'True to allow file editing in wp-admin, false to disable it.',
				),
			),
			'required'   => array( 'allow_editing' ),
		);
	}

	public function get_annotations(): array {
		return array(
			'read_only'   => false,
			'destructive' => false,
		);
	}

	public function execute( array $args ): array {
		if ( ! isset( $args['allow_editing'] ) ) {
			return array( 'error' => 'allow_editing (boolean) is required.' );
		}

		$allow_editing = (bool) $args['allow_editing'];

		// Store: 1 = disallowed, 0 = allowed.
		update_option( 'agent_builder_disallow_file_edit', $allow_editing ? 0 : 1 );

		return array(
			'file_editing_allowed' => $allow_editing,
			'enforced_by'          => 'DISALLOW_FILE_EDIT constant defined at plugins_loaded by Agent Builder',
			'takes_effect'         => 'next page load',
		);
	}
}

return new Toggle_File_Editing();
