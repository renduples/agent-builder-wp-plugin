<?php
/**
 * Tool: add_custom_js
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

class Add_Custom_Js extends Tool_Base {
	public function get_name(): string {
		return 'add_custom_js';
	}

	public function get_description(): string {
		return 'Store a custom JavaScript snippet for later reference (e.g. for a developer to add manually, or for a future release to enqueue). Snippets are saved to the database only — Agent Builder does not currently output them on the frontend.';
	}

	public function get_category(): string {
		return 'utility';
	}

	public function get_parameters(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'js'       => array(
					'type'        => 'string',
					'description' => 'The JavaScript code to add.',
				),
				'label'    => array(
					'type'        => 'string',
					'description' => 'A descriptive label for this snippet. Defaults to "Custom JS".',
				),
				'location' => array(
					'type'        => 'string',
					'description' => 'Stored for reference only (not output): "footer" or "head".',
					'enum'        => array( 'head', 'footer' ),
				),
			),
			'required'   => array( 'js' ),
		);
	}

	public function get_annotations(): array {
		return array( 'read_only' => false, 'destructive' => false );
	}

	public function execute( array $args ): array {
		$js       = $args['js'] ?? '';
		$label    = sanitize_text_field( $args['label'] ?? 'Custom JS' );
		$location = in_array( $args['location'] ?? '', array( 'head', 'footer' ), true )
			? $args['location']
			: 'footer';

		if ( ! trim( $js ) ) {
			return array( 'error' => 'js is required and cannot be empty.' );
		}

		$snippets   = (array) get_option( 'agent_builder_custom_js', array() );
		$snippet_id = 'js_' . time() . '_' . wp_rand( 1000, 9999 );

		$snippets[ $snippet_id ] = array(
			'label'      => $label,
			'js'         => $js,
			'location'   => $location,
			'created_at' => gmdate( 'c' ),
		);

		update_option( 'agent_builder_custom_js', $snippets );

		return array(
			'snippet_id' => $snippet_id,
			'label'      => $label,
			'location'   => $location,
			'js_preview' => substr( $js, 0, 100 ) . ( strlen( $js ) > 100 ? '...' : '' ),
			'note'       => 'Saved to the database only — not currently output on the site. Use list_code_snippets to review stored snippets.',
		);
	}
}

return new Add_Custom_Js();
