<?php
/**
 * Tool: create_form
 *
 * Create a new form using a universal field schema.
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
 * Create a new form on the specified form plugin.
 *
 * Accepts a universal field schema and translates it to the target plugin's
 * native format, applying sensible defaults for notifications and confirmations.
 */
class Create_Form extends \Agentic\Tool_Base {

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'create_form';
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return 'Create a new WordPress form using a universal field schema. Supports Contact Form 7, WPForms, Gravity Forms, and Fluent Forms.';
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
				'plugin'               => array(
					'type'        => 'string',
					'description' => 'Plugin slug: contact-form-7, wpforms, gravityforms, fluentform. Omit to auto-detect.',
				),
				'title'                => array(
					'type'        => 'string',
					'description' => 'The form title.',
				),
				'fields'               => array(
					'type'        => 'array',
					'description' => 'Array of field objects. Each field: { type (text|email|tel|textarea|select|radio|checkbox|number|date|file|hidden), label, required (bool), placeholder, options (array for select/radio/checkbox) }',
					'items'       => array( 'type' => 'object' ),
				),
				'notification_email'   => array(
					'type'        => 'string',
					'description' => 'Email address to send submission notifications to. Defaults to site admin email.',
				),
				'confirmation_message' => array(
					'type'        => 'string',
					'description' => 'Message shown to the user after successful form submission.',
				),
			),
			'required'   => array( 'title', 'fields' ),
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
			return array( 'error' => 'You do not have permission to create forms.' );
		}

		$plugin               = sanitize_key( $arguments['plugin'] ?? '' );
		$title                = sanitize_text_field( $arguments['title'] ?? '' );
		$fields               = $arguments['fields'] ?? array();
		$notification_email   = sanitize_email( $arguments['notification_email'] ?? get_option( 'admin_email' ) );
		$confirmation_message = sanitize_text_field( $arguments['confirmation_message'] ?? 'Thank you for your message. We will get back to you soon.' );

		if ( '' === $title ) {
			return array( 'error' => 'A form title is required.' );
		}

		if ( empty( $fields ) || ! is_array( $fields ) ) {
			return array( 'error' => 'At least one field is required.' );
		}

		if ( '' === $plugin ) {
			$plugin = $this->detect_plugin();
		}

		switch ( $plugin ) {
			case 'contact-form-7':
				return $this->create_cf7( $title, $fields, $notification_email, $confirmation_message );
			case 'wpforms':
				return $this->create_wpforms( $title, $fields, $notification_email, $confirmation_message );
			case 'gravityforms':
				return $this->create_gravity( $title, $fields, $notification_email, $confirmation_message );
			case 'fluentform':
				return $this->create_fluent( $title, $fields, $notification_email, $confirmation_message );
			default:
				// No third-party plugin found — fall back to the built-in native forms engine.
				return $this->create_native( $title, $fields, $confirmation_message );
		}
	}

	// -------------------------------------------------------------------------
	// Plugin-specific create implementations
	// -------------------------------------------------------------------------

	/**
	 * Create a Contact Form 7 form.
	 *
	 * @param string $title                Form title.
	 * @param array  $fields               Universal fields array.
	 * @param string $notification_email   Notification recipient.
	 * @param string $confirmation_message Success message.
	 * @return array
	 */
	private function create_cf7( string $title, array $fields, string $notification_email, string $confirmation_message ): array {
		if ( ! class_exists( 'WPCF7_ContactForm' ) ) {
			return array( 'error' => 'Contact Form 7 is not active.' );
		}

		$cf7_markup   = $this->build_cf7_markup( $fields );
		$mail_body    = $this->build_cf7_mail_body( $fields );
		$mail_subject = '[' . get_bloginfo( 'name' ) . '] ' . $title;

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'wpcf7_contact_form',
				'post_status' => 'publish',
				'post_title'  => $title,
			)
		);

		if ( is_wp_error( $post_id ) ) {
			return array( 'error' => $post_id->get_error_message() );
		}

		update_post_meta( $post_id, '_form', $cf7_markup );
		update_post_meta(
			$post_id,
			'_mail',
			array(
				'active'             => true,
				'recipient'          => $notification_email,
				'sender'             => get_bloginfo( 'name' ) . ' <wordpress@' . wp_parse_url( get_site_url(), PHP_URL_HOST ) . '>',
				'subject'            => $mail_subject,
				'body'               => $mail_body,
				'additional_headers' => 'Reply-To: [your-email]',
				'attachments'        => '',
				'use_html'           => false,
				'exclude_blank'      => false,
			)
		);
		update_post_meta(
			$post_id,
			'_messages',
			array(
				'mail_sent_ok'                => $confirmation_message,
				'mail_sent_ng'                => 'There was an error trying to send your message. Please try again later.',
				'validation_error'            => 'One or more fields have an error. Please check and try again.',
				'spam'                        => 'There was an error trying to send your message. Please try again later.',
				'accept_terms'                => 'You must accept the terms and conditions before sending your message.',
				'invalid_required'            => 'The field is required.',
				'invalid_email'               => 'The e-mail address entered is invalid.',
				'invalid_url'                 => 'The URL you entered seems to be invalid.',
				'invalid_number'              => 'The number you entered seems to be invalid.',
				'number_too_small'            => 'The number is too small.',
				'number_too_large'            => 'The number is too large.',
				'invalid_date'                => 'The date you entered seems to be invalid.',
				'date_too_early'              => 'The date is too early.',
				'date_too_late'               => 'The date is too late.',
				'upload_failed'               => 'There was an unknown error uploading the file.',
				'upload_file_type_invalid'    => 'You are not allowed to upload files of this type.',
				'upload_file_too_large'       => 'The file is too large.',
				'upload_failed_php_error'     => 'There was an error uploading the file.',
				'invalid_too_long'            => 'The text is too long.',
				'invalid_too_short'           => 'The text is too short.',
				'email_confirmation_mismatch' => 'The email addresses do not match.',
				'phone_in_use'                => 'This phone number is already being used.',
				'email_in_use'                => 'This email address is already being used.',
			)
		);

		return array(
			'plugin'    => 'contact-form-7',
			'id'        => $post_id,
			'title'     => $title,
			'shortcode' => '[contact-form-7 id="' . $post_id . '" title="' . esc_attr( $title ) . '"]',
			'success'   => true,
		);
	}

	/**
	 * Create a WPForms form.
	 *
	 * @param string $title                Form title.
	 * @param array  $fields               Universal fields array.
	 * @param string $notification_email   Notification recipient.
	 * @param string $confirmation_message Success message.
	 * @return array
	 */
	private function create_wpforms( string $title, array $fields, string $notification_email, string $confirmation_message ): array {
		if ( ! function_exists( 'wpforms' ) ) {
			return array( 'error' => 'WPForms is not active.' );
		}

		$form_data = $this->build_wpforms_data( $title, $fields, $notification_email, $confirmation_message );
		$form_id   = wpforms()->form->add( $title, $form_data );

		if ( ! $form_id ) {
			return array( 'error' => 'WPForms failed to create the form.' );
		}

		return array(
			'plugin'    => 'wpforms',
			'id'        => $form_id,
			'title'     => $title,
			'shortcode' => '[wpforms id="' . $form_id . '"]',
			'success'   => true,
		);
	}

	/**
	 * Create a Gravity Forms form.
	 *
	 * @param string $title                Form title.
	 * @param array  $fields               Universal fields array.
	 * @param string $notification_email   Notification recipient.
	 * @param string $confirmation_message Success message.
	 * @return array
	 */
	private function create_gravity( string $title, array $fields, string $notification_email, string $confirmation_message ): array {
		if ( ! class_exists( 'GFAPI' ) ) {
			return array( 'error' => 'Gravity Forms is not active.' );
		}

		$gf_fields       = $this->build_gf_fields( $fields );
		$notification_id = uniqid( '', true );
		$confirmation_id = uniqid( '', true );

		$form_array = array(
			'title'         => $title,
			'fields'        => $gf_fields,
			'button'        => array(
				'type' => 'text',
				'text' => 'Submit',
			),
			'notifications' => array(
				$notification_id => array(
					'id'       => $notification_id,
					'name'     => 'Admin Notification',
					'to'       => $notification_email,
					'from'     => get_bloginfo( 'name' ),
					'subject'  => 'New form submission: ' . $title,
					'message'  => '{all_fields}',
					'isActive' => true,
				),
			),
			'confirmations' => array(
				$confirmation_id => array(
					'id'        => $confirmation_id,
					'name'      => 'Default Confirmation',
					'type'      => 'message',
					'message'   => $confirmation_message,
					'isDefault' => '1',
					'isActive'  => true,
				),
			),
		);

		$form_id = \GFAPI::add_form( $form_array );

		if ( is_wp_error( $form_id ) ) {
			return array( 'error' => $form_id->get_error_message() );
		}

		return array(
			'plugin'    => 'gravityforms',
			'id'        => $form_id,
			'title'     => $title,
			'shortcode' => '[gravityforms id="' . $form_id . '"]',
			'success'   => true,
		);
	}

	/**
	 * Create a Fluent Forms form.
	 *
	 * @param string $title                Form title.
	 * @param array  $fields               Universal fields array.
	 * @param string $notification_email   Notification recipient.
	 * @param string $confirmation_message Success message.
	 * @return array
	 */
	private function create_fluent( string $title, array $fields, string $notification_email, string $confirmation_message ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'fluentform_forms';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return array( 'error' => 'Fluent Forms is not active or its tables are missing.' );
		}

		$form_fields = $this->build_fluentform_fields( $fields );
		$form_data   = wp_json_encode( array( 'fields' => $form_fields ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->insert(
			$table,
			array(
				'title'       => $title,
				'status'      => 'published',
				'form_fields' => $form_data,
				'type'        => 'contact',
				'created_by'  => get_current_user_id(),
				'created_at'  => current_time( 'mysql' ),
				'updated_at'  => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		$form_id = $wpdb->insert_id;

		if ( ! $form_id ) {
			return array( 'error' => 'Fluent Forms failed to create the form.' );
		}

		// Insert default notification meta.
		$notifications_table = $wpdb->prefix . 'fluentform_form_meta';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table.
		$notifications_exist = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $notifications_table ) );
		if ( $notifications_exist ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->insert(
				$notifications_table,
				array(
					'form_id'  => $form_id,
					'meta_key' => 'notifications', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- inserting into a third-party plugin table, not a WP_Query.
					'value'    => wp_json_encode(
						array(
							array(
								'sendTo'  => array(
						'type'  => 'email',
						'email' => $notification_email,
							),
								'subject' => 'New submission: ' . $title,
								'body'    => '<p>{all_data}</p>',
								'enabled' => true,
							),
						)
					),
				),
				array( '%d', '%s', '%s' )
			);
		}

		return array(
			'plugin'    => 'fluentform',
			'id'        => $form_id,
			'title'     => $title,
			'shortcode' => '[fluentform id="' . $form_id . '"]',
			'success'   => true,
		);
	}

	// -------------------------------------------------------------------------
	// Field format translators
	// -------------------------------------------------------------------------

	/**
	 * Build Contact Form 7 form markup from universal fields.
	 *
	 * @param array $fields Universal fields array.
	 * @return string CF7 markup.
	 */
	private function build_cf7_markup( array $fields ): string {
		$lines = array();

		foreach ( $fields as $index => $field ) {
			$type        = sanitize_key( $field['type'] ?? 'text' );
			$label       = sanitize_text_field( $field['label'] ?? 'Field ' . ( $index + 1 ) );
			$required    = ! empty( $field['required'] );
			$placeholder = sanitize_text_field( $field['placeholder'] ?? '' );
			$slug        = sanitize_title( $label );
			$req_mark    = $required ? '*' : '';

			$tag_placeholder = $placeholder ? ' placeholder "' . $placeholder . '"' : '';

			switch ( $type ) {
				case 'email':
					$lines[] = '<p>' . esc_html( $label ) . ( $required ? ' <span aria-label="required">*</span>' : '' ) . '<br />';
					$lines[] = '    [email' . $req_mark . ' ' . $slug . $tag_placeholder . ']</p>';
					break;

				case 'tel':
					$lines[] = '<p>' . esc_html( $label ) . ( $required ? ' <span aria-label="required">*</span>' : '' ) . '<br />';
					$lines[] = '    [tel' . $req_mark . ' ' . $slug . $tag_placeholder . ']</p>';
					break;

				case 'textarea':
					$lines[] = '<p>' . esc_html( $label ) . ( $required ? ' <span aria-label="required">*</span>' : '' ) . '<br />';
					$lines[] = '    [textarea' . $req_mark . ' ' . $slug . $tag_placeholder . ']</p>';
					break;

				case 'select':
					$options = array_map( 'sanitize_text_field', (array) ( $field['options'] ?? array() ) );
					$opts    = implode( '" "', $options );
					$lines[] = '<p>' . esc_html( $label ) . ( $required ? ' <span aria-label="required">*</span>' : '' ) . '<br />';
					$lines[] = '    [select' . $req_mark . ' ' . $slug . ' "' . $opts . '"]</p>';
					break;

				case 'radio':
					$options = array_map( 'sanitize_text_field', (array) ( $field['options'] ?? array() ) );
					$opts    = implode( '" "', $options );
					$lines[] = '<p>' . esc_html( $label ) . ( $required ? ' <span aria-label="required">*</span>' : '' ) . '<br />';
					$lines[] = '    [radio' . $req_mark . ' ' . $slug . ' "' . $opts . '"]</p>';
					break;

				case 'checkbox':
					$options = array_map( 'sanitize_text_field', (array) ( $field['options'] ?? array() ) );
					$opts    = implode( '" "', $options );
					$lines[] = '<p>' . esc_html( $label ) . '<br />';
					$lines[] = '    [checkbox ' . $slug . ' "' . $opts . '"]</p>';
					break;

				case 'number':
					$lines[] = '<p>' . esc_html( $label ) . ( $required ? ' <span aria-label="required">*</span>' : '' ) . '<br />';
					$lines[] = '    [number' . $req_mark . ' ' . $slug . $tag_placeholder . ']</p>';
					break;

				case 'file':
					$lines[] = '<p>' . esc_html( $label ) . ( $required ? ' <span aria-label="required">*</span>' : '' ) . '<br />';
					$lines[] = '    [file' . $req_mark . ' ' . $slug . ']</p>';
					break;

				default: // text, hidden, date, etc.
					$cf7_type = 'hidden' === $type ? 'hidden' : 'text';
					$lines[]  = '<p>' . esc_html( $label ) . ( $required ? ' <span aria-label="required">*</span>' : '' ) . '<br />';
					$lines[]  = '    [' . $cf7_type . $req_mark . ' ' . $slug . $tag_placeholder . ']</p>';
					break;
			}
		}

		$lines[] = '';
		$lines[] = '<p>[submit "Send"]</p>';

		return implode( "\n", $lines );
	}

	/**
	 * Build CF7 mail body listing all fields.
	 *
	 * @param array $fields Universal fields array.
	 * @return string CF7 mail body.
	 */
	private function build_cf7_mail_body( array $fields ): string {
		$lines = array( 'From: [your-name] <[your-email]>', '' );
		foreach ( $fields as $field ) {
			$label   = sanitize_text_field( $field['label'] ?? '' );
			$slug    = sanitize_title( $label );
			$lines[] = $label . ': [' . $slug . ']';
		}
		return implode( "\n", $lines );
	}

	/**
	 * Build WPForms form data array.
	 *
	 * @param string $title                Form title.
	 * @param array  $fields               Universal fields array.
	 * @param string $notification_email   Notification recipient.
	 * @param string $confirmation_message Confirmation message.
	 * @return array WPForms form data.
	 */
	private function build_wpforms_data( string $title, array $fields, string $notification_email, string $confirmation_message ): array {
		$wpf_fields      = array();
		$field_id_cursor = 1;

		foreach ( $fields as $field ) {
			$type     = sanitize_key( $field['type'] ?? 'text' );
			$label    = sanitize_text_field( $field['label'] ?? 'Field ' . $field_id_cursor );
			$required = ! empty( $field['required'] ) ? '1' : '';

			$wpf_field = array(
				'id'          => $field_id_cursor,
				'type'        => $type,
				'label'       => $label,
				'required'    => $required,
				'placeholder' => sanitize_text_field( $field['placeholder'] ?? '' ),
				'size'        => 'medium',
				'label_hide'  => '',
			);

			if ( in_array( $type, array( 'select', 'radio', 'checkbox' ), true ) ) {
				$options = array_map( 'sanitize_text_field', (array) ( $field['options'] ?? array() ) );
				$choices = array();
				foreach ( $options as $i => $opt ) {
					$choices[ $i + 1 ] = array(
						'label' => $opt,
						'value' => '',
						'image' => '',
					);
				}
				$wpf_field['choices'] = $choices;
			}

			$wpf_fields[ $field_id_cursor ] = $wpf_field;
			++$field_id_cursor;
		}

		$notification_id = 1;
		$confirmation_id = 1;

		return array(
			'fields'   => $wpf_fields,
			'settings' => array(
				'form_title'    => $title,
				'form_desc'     => '',
				'submit_text'   => 'Submit',
				'notifications' => array(
					$notification_id => array(
						'notification_name' => 'Default Notification',
						'enable'            => 1,
						'email'             => $notification_email,
						'sender_name'       => '{site_name}',
						'sender_address'    => '{admin_email}',
						'replyto'           => '{field_id="' . 1 . '"}',
						'subject'           => 'New Entry: ' . $title,
						'message'           => '{all_fields}',
					),
				),
				'confirmations' => array(
					$confirmation_id => array(
						'name'           => 'Default Confirmation',
						'type'           => 'message',
						'message'        => $confirmation_message,
						'message_scroll' => '1',
					),
				),
			),
		);
	}

	/**
	 * Build Gravity Forms fields array from universal schema.
	 *
	 * @param array $fields Universal fields array.
	 * @return array GF fields.
	 */
	private function build_gf_fields( array $fields ): array {
		$gf_fields = array();
		$id_cursor = 1;

		foreach ( $fields as $field ) {
			$type     = sanitize_key( $field['type'] ?? 'text' );
			$label    = sanitize_text_field( $field['label'] ?? 'Field ' . $id_cursor );
			$required = ! empty( $field['required'] );

			$gf_field = array(
				'id'          => $id_cursor,
				'type'        => $type,
				'label'       => $label,
				'isRequired'  => $required,
				'placeholder' => sanitize_text_field( $field['placeholder'] ?? '' ),
				'size'        => 'medium',
			);

			if ( in_array( $type, array( 'select', 'radio', 'checkbox' ), true ) ) {
				$options = array_map( 'sanitize_text_field', (array) ( $field['options'] ?? array() ) );
				$choices = array();
				foreach ( $options as $opt ) {
					$choices[] = array(
						'text'       => $opt,
						'value'      => $opt,
						'isSelected' => false,
						'price'      => '',
					);
				}
				$gf_field['choices'] = $choices;
			}

			$gf_fields[] = $gf_field;
			++$id_cursor;
		}

		return $gf_fields;
	}

	/**
	 * Build Fluent Forms fields array from universal schema.
	 *
	 * @param array $fields Universal fields array.
	 * @return array FF fields.
	 */
	private function build_fluentform_fields( array $fields ): array {
		$ff_fields = array();

		foreach ( $fields as $index => $field ) {
			$type        = sanitize_key( $field['type'] ?? 'text' );
			$label       = sanitize_text_field( $field['label'] ?? 'Field ' . ( $index + 1 ) );
			$placeholder = sanitize_text_field( $field['placeholder'] ?? '' );
			$required    = ! empty( $field['required'] );
			$slug        = 'field_' . ( $index + 1 );

			$el_map = array(
				'text'     => 'input_text',
				'email'    => 'input_email',
				'tel'      => 'phone',
				'textarea' => 'textarea',
				'select'   => 'select',
				'radio'    => 'input_radio',
				'checkbox' => 'input_checkbox',
				'number'   => 'input_number',
				'date'     => 'input_date',
				'file'     => 'input_file',
				'hidden'   => 'input_hidden',
			);

			$element  = $el_map[ $type ] ?? 'input_text';
			$ff_field = array(
				'index'      => $index,
				'element'    => $element,
				'columns'    => array(
					array(
						'fields' => array(),
						'width'  => '100',
					),
				),
				'settings'   => array(
					'label'            => $label,
					'placeholder'      => $placeholder,
					'name'             => $slug,
					'validation_rules' => array(
						'required' => array(
							'value'   => $required,
							'message' => 'This field is required.',
						),
					),
				),
				'attributes' => array(
					'type'        => $type,
					'name'        => $slug,
					'placeholder' => $placeholder,
				),
			);

			if ( in_array( $type, array( 'select', 'radio', 'checkbox' ), true ) ) {
				$options  = array_map( 'sanitize_text_field', (array) ( $field['options'] ?? array() ) );
				$adv_opts = array();
				foreach ( $options as $opt ) {
					$adv_opts[] = array(
						'label' => $opt,
						'value' => sanitize_title( $opt ),
					);
				}
				$ff_field['settings']['advanced_options'] = $adv_opts;
			}

			$ff_fields[] = $ff_field;
		}

		return $ff_fields;
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
	 * Native fallback: save the form via the built-in Agentic_Native_Forms engine.
	 *
	 * Called automatically when no supported third-party form plugin is installed.
	 * Produces an [agentic_form id="X"] shortcode that works out of the box.
	 *
	 * @param string $title                Form title.
	 * @param array  $fields               Universal fields array.
	 * @param string $confirmation_message Confirmation message after submission.
	 * @return array
	 */
	private function create_native( string $title, array $fields, string $confirmation_message ): array {
		if ( ! class_exists( 'Agentic_Native_Forms' ) ) {
			return array( 'error' => 'No supported form plugin is installed and the native forms engine is unavailable. Please install Contact Form 7, WPForms, Gravity Forms, or Fluent Forms.' );
		}

		$definition = array(
			'title'                => $title,
			'submit_label'         => 'Submit',
			'fields'               => $fields,
			'confirmation_message' => $confirmation_message ? $confirmation_message : 'Thank you — your submission has been received!',
		);

		$engine = \Agentic_Native_Forms::get_instance();
		$result = $engine->save_form( $title, $definition, 0 );

		if ( is_wp_error( $result ) ) {
			return array( 'error' => $result->get_error_message() );
		}

		return array_merge(
			$result,
			array(
				'plugin' => 'native',
				'note'   => 'No third-party form plugin was detected so this form was saved using the built-in Agentic forms engine. ' .
					'Insert the shortcode into any post or page. ' .
					'Submissions are stored in WordPress and emailed to the site admin.',
			)
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

return new Create_Form();
