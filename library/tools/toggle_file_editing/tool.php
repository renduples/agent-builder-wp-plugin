<?php
/**
 * Tool: toggle_file_editing
 *
 * Reports whether the wp-admin theme/plugin file editor is disabled and,
 * when it is not, tells the administrator how to disable it in
 * wp-config.php. It does not change anything itself: disabling the editor
 * means defining the WordPress constant DISALLOW_FILE_EDIT, and a plugin
 * must not define WordPress core constants (that changes behaviour for the
 * whole site, outside the plugin's own scope).
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
		return 'Check whether the theme and plugin file editor in wp-admin is disabled, and get the exact wp-config.php line the site administrator should add to disable it (a security best practice). This tool never changes any setting: the administrator edits wp-config.php themselves.';
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
					'description' => 'Optional. The outcome the user wants: false = disable the file editor, true = allow it. Only used to tailor the instructions.',
				),
			),
		);
	}

	public function get_annotations(): array {
		return array(
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		);
	}

	public function execute( array $args ): array {
		$disabled = defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT;
		$wants    = isset( $args['allow_editing'] ) ? (bool) $args['allow_editing'] : false;
		$line     = "define( 'DISALLOW_FILE_EDIT', true );";

		if ( $wants ) {
			$instructions = $disabled
				? 'The file editor is disabled by DISALLOW_FILE_EDIT in wp-config.php (or another must-use plugin). To allow editing again, the site administrator removes that line from wp-config.php.'
				: 'The file editor is already allowed. Nothing to change.';
		} else {
			$instructions = $disabled
				? 'The file editor is already disabled. Nothing to change.'
				: 'Recommended: ask the site administrator to add this line to wp-config.php, above the line "/* That\'s all, stop editing! */": ' . $line . ' AgentStructor does not change this setting itself.';
		}

		return array(
			'file_editing_allowed' => ! $disabled,
			'recommended'          => ! $disabled,
			'wp_config_line'       => $line,
			'instructions'         => $instructions,
			'changed'              => false,
		);
	}
}

return new Toggle_File_Editing();
