<?php
/**
 * Tool: add_custom_css
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

class Add_Custom_Css extends Tool_Base {
	public function get_name(): string {
		return 'add_custom_css';
	}

	public function get_description(): string {
		return 'Store a custom CSS snippet for later reference (e.g. for a developer to paste into Appearance → Customize). Snippets are saved to the database only — Agent Builder does not output them on the frontend.';
	}

	public function get_category(): string {
		return 'utility';
	}

	public function get_parameters(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'css'      => array(
					'type'        => 'string',
					'description' => 'The CSS code to add.',
				),
				'label'    => array(
					'type'        => 'string',
					'description' => 'A descriptive label for this snippet. Defaults to "Custom CSS".',
				),
				'location' => array(
					'type'        => 'string',
					'description' => 'Stored for reference only (not output): "global" or "frontend_only".',
					'enum'        => array( 'global', 'frontend_only' ),
				),
			),
			'required'   => array( 'css' ),
		);
	}

	public function get_annotations(): array {
		return array( 'read_only' => false, 'destructive' => false );
	}

	public function execute( array $args ): array {
		$css      = $args['css'] ?? '';
		$label    = sanitize_text_field( $args['label'] ?? 'Custom CSS' );
		$location = in_array( $args['location'] ?? '', array( 'global', 'frontend_only' ), true )
			? $args['location']
			: 'global';

		if ( ! trim( $css ) ) {
			return array( 'error' => 'css is required and cannot be empty.' );
		}

		$snippets    = (array) get_option( 'agent_builder_custom_css', array() );
		$snippet_id  = 'css_' . time() . '_' . wp_rand( 1000, 9999 );

		$snippets[ $snippet_id ] = array(
			'label'      => $label,
			'css'        => $css,
			'location'   => $location,
			'created_at' => gmdate( 'c' ),
		);

		update_option( 'agent_builder_custom_css', $snippets );

		return array(
			'snippet_id'  => $snippet_id,
			'label'       => $label,
			'location'    => $location,
			'css_preview' => substr( $css, 0, 100 ) . ( strlen( $css ) > 100 ? '...' : '' ),
			'message'     => 'Stored only. Agent Builder does not output this CSS — paste it into Appearance → Customize if you want it on the site.',
		);
	}
}

return new Add_Custom_Css();
