<?php
/**
 * Tool: list_code_snippets
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

class List_Code_Snippets extends Tool_Base {
	public function get_name(): string {
		return 'list_code_snippets';
	}

	public function get_description(): string {
		return 'List all custom CSS and JavaScript snippets managed by Agent Builder, sorted by creation date.';
	}

	public function get_category(): string {
		return 'utility';
	}

	public function get_parameters(): array {
		return array(
			'type'       => 'object',
			'properties' => array(),
			'required'   => array(),
		);
	}

	public function get_annotations(): array {
		return array(
			'read_only'   => true,
			'destructive' => false,
		);
	}

	public function execute( array $args ): array {
		$css_snippets = (array) get_option( 'agent_builder_custom_css', array() );
		$js_snippets  = (array) get_option( 'agent_builder_custom_js', array() );

		$all = array();

		foreach ( $css_snippets as $id => $snippet ) {
			$code  = $snippet['css'] ?? '';
			$all[] = array(
				'snippet_id' => $id,
				'type'       => 'css',
				'label'      => $snippet['label'] ?? 'Custom CSS',
				'location'   => $snippet['location'] ?? 'global',
				'created_at' => $snippet['created_at'] ?? null,
				'size_bytes' => strlen( $code ),
				'preview'    => substr( $code, 0, 80 ) . ( strlen( $code ) > 80 ? '...' : '' ),
			);
		}

		foreach ( $js_snippets as $id => $snippet ) {
			$code  = $snippet['js'] ?? '';
			$all[] = array(
				'snippet_id' => $id,
				'type'       => 'js',
				'label'      => $snippet['label'] ?? 'Custom JS',
				'location'   => $snippet['location'] ?? 'footer',
				'created_at' => $snippet['created_at'] ?? null,
				'size_bytes' => strlen( $code ),
				'preview'    => substr( $code, 0, 80 ) . ( strlen( $code ) > 80 ? '...' : '' ),
			);
		}

		// Sort by created_at descending.
		usort(
			$all,
			fn( $a, $b ) => strcmp( $b['created_at'] ?? '', $a['created_at'] ?? '' )
		);

		return array(
			'snippets' => $all,
			'total'    => count( $all ),
		);
	}
}

return new List_Code_Snippets();
