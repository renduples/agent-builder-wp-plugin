<?php
/**
 * Tool: form_export_entries
 *
 * Export native form entries as a CSV string.
 *
 * @package    Agent_Builder
 * @subpackage Tools
 * @since      2.9.0
 *
 * php version 8.1
 */

declare( strict_types=1 );

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Export form entries as CSV.
 *
 * Fetches entries for a native agentic_form and returns a CSV string
 * with a header row derived from field labels, plus a Date column.
 */
class Form_Export_Entries extends \Agentic\Tool_Base {

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'form_export_entries';
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return 'Export native form entries as a CSV string. ' .
			'Returns a CSV with a header row from field labels and a Date column.';
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
			'properties' => array(
				'form_id' => array(
					'type'        => 'integer',
					'description' => 'The ID of the native form to export entries from.',
				),
				'limit'   => array(
					'type'        => 'integer',
					'description' => 'Maximum number of entries to export. Defaults to 100, max 500.',
					'default'     => 100,
				),
			),
			'required'   => array( 'form_id' ),
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array
	 */
	public function execute( array $arguments ): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			return array( 'error' => 'You do not have permission to export form entries.' );
		}

		$form_id = absint( $arguments['form_id'] ?? 0 );
		$limit   = max( 1, min( 500, absint( $arguments['limit'] ?? 100 ) ) );

		if ( 0 === $form_id ) {
			return array( 'error' => 'A form_id is required.' );
		}

		$post = get_post( $form_id );
		if ( ! $post || \Agentic_Native_Forms::FORM_CPT !== $post->post_type ) {
			return array( 'error' => 'Native form with ID ' . $form_id . ' was not found.' );
		}

		$engine     = \Agentic_Native_Forms::get_instance();
		$definition = $engine->get_definition( $form_id );
		$result     = $engine->get_entries( $form_id, $limit, 1 );
		$entries    = $result['entries'] ?? array();

		if ( empty( $entries ) ) {
			return array(
				'form_id'    => $form_id,
				'form_title' => $post->post_title,
				'csv'        => '',
				'count'      => 0,
			);
		}

		// Build header columns from the form definition fields.
		$fields  = $definition['fields'] ?? array();
		$headers = array();
		foreach ( $fields as $field ) {
			$headers[] = sanitize_text_field( $field['label'] ?? $field['name'] ?? 'Field' );
		}
		$headers[] = 'Date';

		// Build CSV as a string (avoids fopen/fclose which Plugin Check flags).
		$csv_rows   = array();
		$csv_rows[] = self::csv_row( $headers );

		foreach ( $entries as $entry ) {
			$row     = array();
			$payload = $entry['fields'] ?? array();

			foreach ( $fields as $field ) {
				$name  = $field['name'] ?? '';
				$label = $field['label'] ?? '';
				$value = '';

				if ( isset( $payload[ $name ] ) ) {
					$value = is_array( $payload[ $name ] ) ? ( $payload[ $name ]['value'] ?? '' ) : $payload[ $name ];
				} else {
					foreach ( $payload as $p ) {
						if ( is_array( $p ) && ( ( $p['label'] ?? '' ) === $label || ( $p['name'] ?? '' ) === $name ) ) {
							$value = $p['value'] ?? '';
							break;
						}
					}
				}

				$row[] = (string) $value;
			}

			$row[]      = $entry['submitted'] ?? '';
			$csv_rows[] = self::csv_row( $row );
		}

		$csv = implode( "\n", $csv_rows );

		return array(
			'form_id'    => $form_id,
			'form_title' => $post->post_title,
			'csv'        => $csv,
			'count'      => count( $entries ),
		);
	}

	/**
	 * Get tool annotations.
	 *
	 * @return array
	 */
	public function get_annotations(): array {
		return array(
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
			'personal_data' => true,
		);
	}

	/**
	 * Convert an array of values into a CSV row string.
	 *
	 * @param array $fields Row values.
	 * @return string CSV-formatted row.
	 */
	private static function csv_row( array $fields ): string {
		$escaped = array();
		foreach ( $fields as $field ) {
			$field     = str_replace( '"', '""', (string) $field );
			$escaped[] = '"' . $field . '"';
		}
		return implode( ',', $escaped );
	}
}

return new Form_Export_Entries();
