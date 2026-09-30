<?php
/**
 * Tool: get_form
 *
 * Retrieve the full configuration of a specific form including all fields,
 * notification settings, and confirmation text.
 *
 * @package    Agent_Builder
 * @subpackage Tools
 * @since      2.3.0
 *
 * php version 8.1
 */

declare( strict_types=1 );

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get the full configuration of a specific form.
 *
 * Returns field definitions, submission notification settings, and
 * confirmation message for the given form ID.
 */
class Get_Form extends \Agentic\Tool_Base {

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'get_form';
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return 'Get the full configuration of a specific form: its fields, notifications, and confirmation message.';
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
					'description' => 'The ID of the form to retrieve.',
				),
				'plugin'  => array(
					'type'        => 'string',
					'description' => 'Plugin slug: contact-form-7, wpforms, gravityforms, fluentform. Omit to auto-detect.',
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
		$form_id = (int) ( $arguments['form_id'] ?? 0 );
		$plugin  = sanitize_key( $arguments['plugin'] ?? '' );

		if ( $form_id <= 0 ) {
			return array( 'error' => 'form_id is required and must be a positive integer.' );
		}

		if ( '' === $plugin ) {
			$plugin = $this->detect_plugin();
		}

		switch ( $plugin ) {
			case 'contact-form-7':
				return $this->get_cf7( $form_id );
			case 'wpforms':
				return $this->get_wpforms( $form_id );
			case 'gravityforms':
				return $this->get_gravity( $form_id );
			case 'fluentform':
				return $this->get_fluent( $form_id );
			default:
				return array( 'error' => 'No supported form plugin detected. Specify the "plugin" parameter explicitly.' );
		}
	}

	// -------------------------------------------------------------------------
	// Plugin-specific implementations
	// -------------------------------------------------------------------------

	/**
	 * Get a Contact Form 7 form.
	 *
	 * @param int $form_id Post ID.
	 * @return array
	 */
	private function get_cf7( int $form_id ): array {
		if ( ! class_exists( 'WPCF7_ContactForm' ) ) {
			return array( 'error' => 'Contact Form 7 is not active.' );
		}

		$cf7 = \WPCF7_ContactForm::get_instance( $form_id );
		if ( ! $cf7 ) {
			return array( 'error' => "Contact Form 7 form ID {$form_id} not found." );
		}

		$mail = $cf7->prop( 'mail' );

		return array(
			'plugin'               => 'contact-form-7',
			'id'                   => $form_id,
			'title'                => $cf7->title(),
			'shortcode'            => '[contact-form-7 id="' . $form_id . '" title="' . esc_attr( $cf7->title() ) . '"]',
			'form_markup'          => $cf7->prop( 'form' ),
			'notification_to'      => $mail['recipient'] ?? '',
			'notification_subject' => $mail['subject'] ?? '',
			'notification_body'    => $mail['body'] ?? '',
			'messages'             => $cf7->prop( 'messages' ),
		);
	}

	/**
	 * Get a WPForms form.
	 *
	 * @param int $form_id Post ID.
	 * @return array
	 */
	private function get_wpforms( int $form_id ): array {
		if ( ! function_exists( 'wpforms' ) ) {
			return array( 'error' => 'WPForms is not active.' );
		}

		$form = wpforms()->form->get( $form_id );
		if ( ! $form ) {
			return array( 'error' => "WPForms form ID {$form_id} not found." );
		}

		$data          = wpforms_decode( $form->post_content );
		$fields        = array();
		$notifications = array();
		$confirmations = array();

		if ( ! empty( $data['fields'] ) ) {
			foreach ( $data['fields'] as $field ) {
				$fields[] = array(
					'id'          => $field['id'] ?? '',
					'type'        => $field['type'] ?? '',
					'label'       => $field['label'] ?? '',
					'required'    => ! empty( $field['required'] ),
					'placeholder' => $field['placeholder'] ?? '',
					'choices'     => $field['choices'] ?? array(),
				);
			}
		}

		if ( ! empty( $data['settings']['notifications'] ) ) {
			foreach ( $data['settings']['notifications'] as $notif ) {
				$notifications[] = array(
					'email'   => $notif['email'] ?? '',
					'subject' => $notif['subject'] ?? '',
				);
			}
		}

		if ( ! empty( $data['settings']['confirmations'] ) ) {
			foreach ( $data['settings']['confirmations'] as $conf ) {
				$confirmations[] = array(
					'type'     => $conf['type'] ?? '',
					'message'  => $conf['message'] ?? '',
					'page'     => $conf['page'] ?? '',
					'redirect' => $conf['redirect'] ?? '',
				);
			}
		}

		return array(
			'plugin'        => 'wpforms',
			'id'            => $form_id,
			'title'         => $form->post_title,
			'shortcode'     => '[wpforms id="' . $form_id . '"]',
			'fields'        => $fields,
			'notifications' => $notifications,
			'confirmations' => $confirmations,
		);
	}

	/**
	 * Get a Gravity Forms form.
	 *
	 * @param int $form_id Gravity Forms form ID.
	 * @return array
	 */
	private function get_gravity( int $form_id ): array {
		if ( ! class_exists( 'GFAPI' ) ) {
			return array( 'error' => 'Gravity Forms is not active.' );
		}

		$form = \GFAPI::get_form( $form_id );
		if ( ! $form || is_wp_error( $form ) ) {
			return array( 'error' => "Gravity Forms form ID {$form_id} not found." );
		}

		$fields = array();
		if ( ! empty( $form['fields'] ) ) {
			foreach ( $form['fields'] as $field ) {
				$choices = array();
				if ( ! empty( $field->choices ) ) {
					foreach ( $field->choices as $choice ) {
						$choices[] = $choice['text'] ?? '';
					}
				}
				$fields[] = array(
					'id'          => $field->id,
					'type'        => $field->type,
					'label'       => $field->label,
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Gravity Forms field property name.
					'required'    => (bool) $field->isRequired,
					'placeholder' => $field->placeholder ?? '',
					'choices'     => $choices,
				);
			}
		}

		$notifications = array();
		if ( ! empty( $form['notifications'] ) ) {
			foreach ( $form['notifications'] as $notif ) {
				$notifications[] = array(
					'name'    => $notif['name'] ?? '',
					'to'      => $notif['to'] ?? '',
					'subject' => $notif['subject'] ?? '',
				);
			}
		}

		$confirmations = array();
		if ( ! empty( $form['confirmations'] ) ) {
			foreach ( $form['confirmations'] as $conf ) {
				$confirmations[] = array(
					'type'     => $conf['type'] ?? '',
					'message'  => $conf['message'] ?? '',
					'redirect' => $conf['url'] ?? '',
				);
			}
		}

		return array(
			'plugin'        => 'gravityforms',
			'id'            => $form_id,
			'title'         => $form['title'],
			'shortcode'     => '[gravityforms id="' . $form_id . '"]',
			'fields'        => $fields,
			'notifications' => $notifications,
			'confirmations' => $confirmations,
			'button'        => $form['button'] ?? array(),
		);
	}

	/**
	 * Get a Fluent Forms form.
	 *
	 * @param int $form_id Fluent Forms form ID.
	 * @return array
	 */
	private function get_fluent( int $form_id ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'fluentform_forms';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return array( 'error' => 'Fluent Forms is not active or its tables are missing.' );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$form = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $form_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			ARRAY_A
		);

		if ( ! $form ) {
			return array( 'error' => "Fluent Forms form ID {$form_id} not found." );
		}

		$form_fields     = json_decode( $form['form_fields'] ?? '{}', true );
		$fields          = array();
		$raw_field_items = $form_fields['fields'] ?? array();

		foreach ( $raw_field_items as $field ) {
			$choices = array();
			if ( ! empty( $field['settings']['advanced_options'] ) ) {
				foreach ( $field['settings']['advanced_options'] as $opt ) {
					$choices[] = $opt['label'] ?? '';
				}
			}
			$fields[] = array(
				'type'        => $field['element'] ?? '',
				'label'       => $field['settings']['label'] ?? '',
				'required'    => ! empty( $field['settings']['validation_rules']['required']['value'] ),
				'placeholder' => $field['settings']['placeholder'] ?? '',
				'choices'     => $choices,
			);
		}

		return array(
			'plugin'    => 'fluentform',
			'id'        => (int) $form['id'],
			'title'     => $form['title'],
			'shortcode' => '[fluentform id="' . $form['id'] . '"]',
			'fields'    => $fields,
			'status'    => $form['status'],
		);
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Auto-detect the primary active form plugin.
	 *
	 * @return string Plugin slug or empty string if none found.
	 */
	private function detect_plugin(): string {
		if ( class_exists( 'WPCF7' ) ) {
			return 'contact-form-7';
		}
		if ( function_exists( 'wpforms' ) ) {
			return 'wpforms';
		}
		if ( class_exists( 'GFAPI' ) ) {
			return 'gravityforms';
		}
		if ( function_exists( 'wpFluentForm' ) ) {
			return 'fluentform';
		}
		return '';
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
		);
	}
}

return new Get_Form();
