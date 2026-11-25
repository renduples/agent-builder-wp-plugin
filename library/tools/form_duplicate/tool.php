<?php
/**
 * Tool: form_duplicate
 *
 * Duplicate a native agentic_form including all meta data.
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
 * Duplicate a native form.
 *
 * Creates a new form CPT post with the title "Copy of {original}" and
 * copies all associated meta (definition, notifications, webhook, spam,
 * conditions).
 */
class Form_Duplicate extends \Agentic\Tool_Base {

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'form_duplicate';
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return 'Duplicate a native form including all settings (fields, notifications, webhook, spam protection, conditions). ' .
			'Creates a new form titled "Copy of {original}" with its own shortcode.';
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
					'description' => 'The ID of the native form to duplicate.',
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
			return array( 'error' => 'You do not have permission to duplicate forms.' );
		}

		$form_id = absint( $arguments['form_id'] ?? 0 );

		if ( 0 === $form_id ) {
			return array( 'error' => 'A form_id is required.' );
		}

		$source = get_post( $form_id );
		if ( ! $source || \Agentic_Native_Forms::FORM_CPT !== $source->post_type ) {
			return array( 'error' => 'Native form with ID ' . $form_id . ' was not found.' );
		}

		/* translators: %s: original form title */
		$new_title = sprintf( __( 'Copy of %s', 'agentstructor' ), $source->post_title );

		$new_id = wp_insert_post(
			array(
				'post_type'   => \Agentic_Native_Forms::FORM_CPT,
				'post_status' => 'publish',
				'post_title'  => $new_title,
				'post_author' => get_current_user_id(),
			)
		);

		if ( is_wp_error( $new_id ) ) {
			return array( 'error' => $new_id->get_error_message() );
		}

		// Copy all relevant meta keys from the source form.
		$meta_keys = array(
			\Agentic_Native_Forms::META_DEFINITION,
			\Agentic_Native_Forms::META_NOTIFICATIONS,
			\Agentic_Native_Forms::META_WEBHOOK,
			\Agentic_Native_Forms::META_SPAM,
			\Agentic_Native_Forms::META_CONDITIONS,
		);

		foreach ( $meta_keys as $key ) {
			$value = get_post_meta( $form_id, $key, true );
			if ( '' !== $value && false !== $value ) {
				update_post_meta( $new_id, $key, $value );
			}
		}

		$engine    = \Agentic_Native_Forms::get_instance();
		$shortcode = $engine->build_shortcode( $new_id );

		return array(
			'source_form_id' => $form_id,
			'new_form_id'    => $new_id,
			'title'          => $new_title,
			'shortcode'      => $shortcode,
			'success'        => true,
		);
	}

	/**
	 * Get tool annotations.
	 *
	 * @return array
	 */
	public function get_annotations(): array {
		return array(
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		);
	}
}

return new Form_Duplicate();
