<?php
/**
 * Tool: list_native_forms
 *
 * Lists all native agentic_form records with their IDs, titles, shortcodes,
 * field count, and entry count. Used by agents to discover existing forms
 * before updating, styling, or querying them.
 *
 * @package    Agent_Builder
 * @subpackage Tools
 * @since      2.9.17
 *
 * php version 8.1
 */

declare( strict_types=1 );

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * List all native agentic_form CPT records.
 */
class List_Native_Forms extends \Agentic\Tool_Base {

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'list_native_forms';
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return 'List all native forms created with save_native_form. Returns each form\'s ID, title, shortcode, entries shortcode, field count, and submission count. Use this to find a form before updating it, restyling it, or reading its entries.';
	}

	/**
	 * Get the tool category.
	 *
	 * @return string
	 */
	public function get_category(): string {
		return 'forms';
	}

	/**
	 * Get the parameter schema.
	 *
	 * @return array
	 */
	public function get_parameters(): array {
		return array(
			'type'       => 'object',
			'properties' => array(),
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments (none required).
	 * @return array
	 */
	public function execute( array $arguments ): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			return array( 'error' => 'You do not have permission to list forms.' );
		}

		$posts = get_posts(
			array(
				'post_type'      => 'agentic_form',
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		if ( empty( $posts ) ) {
			return array(
				'forms' => array(),
				'count' => 0,
				'note'  => 'No native forms found. Use save_native_form to create one.',
			);
		}

		$engine = \Agentic_Native_Forms::get_instance();
		$forms  = array();

		foreach ( $posts as $post ) {
			$form_id    = (int) $post->ID;
			$definition = $engine->get_definition( $form_id );
			$fields     = $definition['fields'] ?? array();

			// Entries are agentic_form_entry posts parented to the form, not rows
			// in a table — the engine counts them.
			$entry_count = $engine->count_entries( $form_id );

			$forms[] = array(
				'id'                => $form_id,
				'title'             => $post->post_title,
				'shortcode'         => '[agentic_form id="' . $form_id . '"]',
				'entries_shortcode' => '[agentic_form_entries id="' . $form_id . '"]',
				'field_count'       => count( $fields ),
				'fields'            => array_map( static fn( $f ) => $f['label'] ?? $f['name'] ?? '', $fields ),
				'entry_count'       => $entry_count,
				'created'           => $post->post_date,
			);
		}

		return array(
			'forms' => $forms,
			'count' => count( $forms ),
		);
	}
}

return new List_Native_Forms();
