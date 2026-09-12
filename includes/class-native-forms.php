<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- Class is Agentic_Native_Forms; file correctly named class-native-forms.php.
/**
 * Native Forms Engine
 *
 * Zero-dependency form system built into the plugin.
 * Provides:
 *   - CPT  `agentic_form`          — stores form JSON definitions
 *   - CPT  `agentic_form_entry`    — stores individual submissions
 *   - Shortcode [agentic_form id="X"] — renders & handles the form on the front-end
 *   - REST POST /agentic/v1/native-forms           — create / upsert a form (auth required)
 *   - REST GET  /agentic/v1/native-forms/{id}      — read a form definition (auth required)
 *   - REST POST /agentic/v1/native-forms/{id}/submit — public submission endpoint
 *
 * Used by the save_native_form and get_native_form_submissions tools,
 * and by the client-side form-preview.js renderer.
 *
 * @package    Agent_Builder
 * @since      2.5.0
 *
 * php version 8.1
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Native Forms Engine.
 */
class Agentic_Native_Forms {

	/** CPT slug for form definitions. */
	const FORM_CPT = 'agentic_form';

	/** CPT slug for form entries / submissions. */
	const ENTRY_CPT = 'agentic_form_entry';

	/** Meta key that stores the JSON form definition on a form CPT post. */
	const META_DEFINITION = '_agentic_form_definition';

	/** Meta key that stores a serialised submission payload on an entry CPT post. */
	const META_PAYLOAD = '_agentic_entry_payload';

	/** Meta key for per-form notification config (JSON). */
	const META_NOTIFICATIONS = '_agentic_form_notifications';

	/** Meta key for per-form webhook config (JSON). */
	const META_WEBHOOK = '_agentic_form_webhook';

	/** Meta key for per-form spam protection config (JSON). */
	const META_SPAM = '_agentic_form_spam';

	/** Meta key for per-form conditional logic rules (JSON). */
	const META_CONDITIONS = '_agentic_form_conditions';

	/** Meta key for entry read/starred/archived status. */
	const META_ENTRY_STATUS = '_agentic_entry_status';

	/** Meta key for admin notes on an entry. */
	const META_ENTRY_NOTES = '_agentic_entry_notes';

	/** REST namespace. */
	const REST_NS = 'agentic/v1';

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return self
	 */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Boot — register hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'init', array( $this, 'register_cpts' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_shortcode( 'agentic_form', array( $this, 'render_shortcode' ) );
		add_shortcode( 'agentic_form_entries', array( $this, 'render_entries_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// CPT registration
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Register the agentic_form and agentic_form_entry CPTs.
	 *
	 * @return void
	 */
	public function register_cpts(): void {
		// Form definitions.
		register_post_type(
			self::FORM_CPT,
			array(
				'label'           => __( 'Forms', 'agent-builder' ),
				'labels'          => array(
					'name'               => __( 'Agentic Forms', 'agent-builder' ),
					'singular_name'      => __( 'Agentic Form', 'agent-builder' ),
					'menu_name'          => __( 'Forms', 'agent-builder' ),
					'all_items'          => __( 'All Forms', 'agent-builder' ),
					'view_item'          => __( 'View Form', 'agent-builder' ),
					'add_new_item'       => __( 'Add New Form', 'agent-builder' ),
					'add_new'            => __( 'Add New', 'agent-builder' ),
					'edit_item'          => __( 'Edit Form', 'agent-builder' ),
					'search_items'       => __( 'Search Forms', 'agent-builder' ),
					'not_found'          => __( 'No forms found.', 'agent-builder' ),
					'not_found_in_trash' => __( 'No forms found in Trash.', 'agent-builder' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => false,   // added under Agent Builder menu separately.
				'show_in_rest'    => false,   // managed via custom REST routes below.
				'capability_type' => 'post',
				'capabilities'    => array(
					'create_posts' => 'manage_options',
				),
				'map_meta_cap'    => true,
				'supports'        => array( 'title' ),
				'rewrite'         => false,
			)
		);

		// Form entries / submissions.
		register_post_type(
			self::ENTRY_CPT,
			array(
				'label'           => __( 'Form Entries', 'agent-builder' ),
				'public'          => false,
				'show_ui'         => false,
				'show_in_menu'    => false,
				'show_in_rest'    => false,
				'capability_type' => 'post',
				'capabilities'    => array(
					'create_posts' => 'manage_options',
				),
				'map_meta_cap'    => true,
				'supports'        => array( 'title' ),
				'rewrite'         => false,
			)
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// REST routes
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Register REST API routes.
	 *
	 * @return void
	 */
	public function register_rest_routes(): void {
		// Create / upsert a form definition (authenticated).
		register_rest_route(
			self::REST_NS,
			'/native-forms',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'rest_save_form' ),
				'permission_callback' => function (): bool {
					return current_user_can( 'manage_options' );
				},
				'args'                => array(
					'title'        => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'fields'       => array(
						'required' => true,
					),
					'submit_label' => array(
						'default'           => 'Submit',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'form_id'      => array(
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// Read a form definition (authenticated).
		register_rest_route(
			self::REST_NS,
			'/native-forms/(?P<id>[\d]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'rest_get_form' ),
				'permission_callback' => function (): bool {
					return current_user_can( 'manage_options' );
				},
				'args'                => array(
					'id' => array( 'validate_callback' => static fn( $v ) => is_numeric( $v ) ),
				),
			)
		);

		// Public submission endpoint.
		register_rest_route(
			self::REST_NS,
			'/native-forms/(?P<id>[\d]+)/submit',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'rest_submit_form' ),
				'permission_callback' => '__return_true',  // Public endpoint.
				'args'                => array(
					'id' => array( 'validate_callback' => static fn( $v ) => is_numeric( $v ) ),
				),
			)
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// REST callbacks
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * REST: create or update a native form.
	 *
	 * Request body: { title, fields[], submit_label?, form_id? }
	 * Response:     { id, shortcode, success }
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_save_form( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$title        = $request->get_param( 'title' );
		$fields       = $request->get_param( 'fields' );
		$submit_label = $request->get_param( 'submit_label' );
		$submit_label = ! empty( $submit_label ) ? $submit_label : 'Submit';
		$form_id      = (int) $request->get_param( 'form_id' );

		if ( empty( $fields ) || ! is_array( $fields ) ) {
			return new WP_Error( 'invalid_fields', 'At least one field is required.', array( 'status' => 400 ) );
		}

		$definition = array(
			'title'        => $title,
			'submit_label' => $submit_label,
			'fields'       => $fields,
		);

		$result = $this->save_form( $title, $definition, $form_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result, 201 );
	}

	/**
	 * REST: read a form definition.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_get_form( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$form_id = (int) $request->get_param( 'id' );
		$post    = get_post( $form_id );

		if ( ! $post || self::FORM_CPT !== $post->post_type ) {
			return new WP_Error( 'not_found', 'Form not found.', array( 'status' => 404 ) );
		}

		$definition = $this->get_definition( $form_id );

		return new WP_REST_Response(
			array(
				'id'         => $form_id,
				'title'      => $post->post_title,
				'definition' => $definition,
				'shortcode'  => $this->build_shortcode( $form_id ),
			),
			200
		);
	}

	/**
	 * REST: handle a public form submission.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_submit_form( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$form_id = (int) $request->get_param( 'id' );
		$post    = get_post( $form_id );

		if ( ! $post || self::FORM_CPT !== $post->post_type ) {
			return new WP_Error( 'not_found', 'Form not found.', array( 'status' => 404 ) );
		}

		$definition = $this->get_definition( $form_id );
		if ( empty( $definition['fields'] ) ) {
			return new WP_Error( 'invalid_form', 'Form has no fields.', array( 'status' => 400 ) );
		}

		// Mandatory baseline anti-abuse control: the shortcode renders a
		// `wp_rest` nonce into the form (data-nonce), sent back as the
		// X-WP-Nonce header — verify it rather than merely rendering it.
		// Honeypot/Turnstile stay separate, per-form opt-in checks below;
		// this is the one check every submission must pass regardless of
		// per-form configuration.
		$submitted_nonce = $request->get_header( 'x_wp_nonce' );
		if ( empty( $submitted_nonce ) || ! wp_verify_nonce( $submitted_nonce, 'wp_rest' ) ) {
			return new WP_Error(
				'invalid_nonce',
				__( 'Security check failed. Please refresh the page and try again.', 'agent-builder' ),
				array( 'status' => 403 )
			);
		}

		// Collect and sanitise submitted values.
		$payload = array();
		$errors  = array();

		foreach ( $definition['fields'] as $field ) {
			$name     = sanitize_key( $field['name'] ?? $field['label'] ?? 'field' );
			$label    = sanitize_text_field( $field['label'] ?? $name );
			$required = ! empty( $field['required'] );
			$value    = $request->get_param( $name );

			if ( is_array( $value ) ) {
				$value = implode( ', ', array_map( 'sanitize_text_field', $value ) );
			} else {
				$value = sanitize_textarea_field( (string) ( $value ?? '' ) );
			}

			if ( $required && '' === trim( $value ) ) {
				/* translators: %s: field label */
				$errors[] = sprintf( __( '%s is required.', 'agent-builder' ), $label );
			}

			$payload[ $name ] = array(
				'label' => $label,
				'value' => $value,
			);
		}

		if ( ! empty( $errors ) ) {
			return new WP_Error(
				'validation_failed',
				implode( ' ', $errors ),
				array(
					'status' => 422,
					'errors' => $errors,
				)
			);
		}

		// Honeypot spam check.
		$spam_config = $this->get_spam_config( $form_id );
		if ( $spam_config['honeypot'] ) {
			$hp_value = $request->get_param( 'agentic_hp_field' );
			if ( ! empty( $hp_value ) ) {
				// Bot filled the hidden field — silently reject.
				return new WP_REST_Response(
					array(
						'success'  => true,
						'entry_id' => 0,
						'message'  => $definition['confirmation_message'] ?? __( 'Thank you — your submission has been received!', 'agent-builder' ),
					),
					201
				);
			}
		}

		// Turnstile verification, when the form has it enabled.
		if ( $spam_config['turnstile'] ) {
			$turnstile_token  = (string) ( $request->get_param( 'cf-turnstile-response' ) ?? '' );
			$turnstile_result = \Agentic\Turnstile::verify( $turnstile_token );
			if ( null !== $turnstile_result && ! $turnstile_result['pass'] ) {
				return new WP_Error( 'turnstile_failed', __( 'Security verification failed. Please try again.', 'agent-builder' ), array( 'status' => 403 ) );
			}
		}

		// Save the entry.
		$entry_id = $this->save_entry( $form_id, $post->post_title, $payload );

		if ( is_wp_error( $entry_id ) ) {
			return $entry_id;
		}

		// Mark entry as unread.
		update_post_meta( $entry_id, self::META_ENTRY_STATUS, 'unread' );

		// Email notifications.
		$this->send_notification( $form_id, $post->post_title, $payload );

		// Webhook dispatch.
		$this->dispatch_webhook( $form_id, $post->post_title, $payload, $entry_id );

		/**
		 * Fires after a native form submission is saved.
		 *
		 * @param int    $form_id    Form ID.
		 * @param int    $entry_id   Entry post ID.
		 * @param array  $payload    Sanitised field data.
		 * @param string $form_title Form title.
		 */
		do_action( 'agentic_form_submitted', $form_id, $entry_id, $payload, $post->post_title );

		// Confirmation: redirect or message.
		$confirmation = $definition['confirmation'] ?? array();
		$message      = $definition['confirmation_message'] ?? __( 'Thank you — your submission has been received!', 'agent-builder' );
		$redirect     = '';

		if ( ! empty( $confirmation['type'] ) && 'redirect' === $confirmation['type'] && ! empty( $confirmation['url'] ) ) {
			$redirect = esc_url_raw( $confirmation['url'] );
		}

		return new WP_REST_Response(
			array(
				'success'  => true,
				'entry_id' => $entry_id,
				'message'  => $message,
				'redirect' => $redirect,
			),
			201
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Core helpers (called by tools and REST handlers)
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Create or update a native form CPT record.
	 *
	 * This is the shared write path used by both the REST handler and the
	 * save_native_form tool — keep business logic here, not duplicated.
	 *
	 * @param string $title      Form title.
	 * @param array  $definition Full definition array (title, submit_label, fields[]).
	 * @param int    $form_id    Existing post ID to update, or 0 to create.
	 * @return array|WP_Error { id, shortcode, created } or WP_Error.
	 */
	public function save_form( string $title, array $definition, int $form_id = 0 ): array|WP_Error {
		$post_data = array(
			'post_type'   => self::FORM_CPT,
			'post_title'  => $title,
			'post_status' => 'publish',
		);

		if ( $form_id > 0 ) {
			$existing = get_post( $form_id );
			if ( ! $existing || self::FORM_CPT !== $existing->post_type ) {
				return new WP_Error( 'not_found', 'Form ID does not exist.', array( 'status' => 404 ) );
			}
			$post_data['ID'] = $form_id;
			$result_id       = wp_update_post( $post_data, true );
			$created         = false;
		} else {
			$result_id = wp_insert_post( $post_data, true );
			$created   = true;
		}

		if ( is_wp_error( $result_id ) ) {
			return $result_id;
		}

		update_post_meta( (int) $result_id, self::META_DEFINITION, wp_json_encode( $definition ) );

		return array(
			'id'                => (int) $result_id,
			'title'             => $title,
			'shortcode'         => $this->build_shortcode( (int) $result_id ),
			'entries_shortcode' => $this->build_entries_shortcode( (int) $result_id ),
			'created'           => $created,
			'success'           => true,
		);
	}

	/**
	 * Return the definition array for a form.
	 *
	 * @param int $form_id Form CPT post ID.
	 * @return array Definition array, or empty array if not found.
	 */
	public function get_definition( int $form_id ): array {
		$json = get_post_meta( $form_id, self::META_DEFINITION, true );
		if ( ! is_string( $json ) || '' === $json ) {
			return array();
		}

		$data = json_decode( $json, true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Build the form shortcode string.
	 *
	 * @param int $form_id Form CPT post ID.
	 * @return string e.g. [agentic_form id="42"]
	 */
	public function build_shortcode( int $form_id ): string {
		return '[agentic_form id="' . $form_id . '"]';
	}

	/**
	 * Build the entries-viewer shortcode string.
	 *
	 * @param int $form_id Form CPT post ID.
	 * @return string e.g. [agentic_form_entries id="42"]
	 */
	public function build_entries_shortcode( int $form_id ): string {
		return '[agentic_form_entries id="' . $form_id . '"]';
	}

	/**
	 * Persist a form submission as an entry CPT post.
	 *
	 * @param int    $form_id    Parent form ID.
	 * @param string $form_title Form title (used in entry title).
	 * @param array  $payload    Sanitised field data.
	 * @return int|WP_Error Entry CPT post ID.
	 */
	public function save_entry( int $form_id, string $form_title, array $payload ): int|WP_Error {
		$entry_title = sprintf(
			/* translators: 1: form title, 2: date/time */
			__( '%1$s — %2$s', 'agent-builder' ),
			$form_title,
			current_time( 'mysql' )
		);

		$entry_id = wp_insert_post(
			array(
				'post_type'   => self::ENTRY_CPT,
				'post_title'  => $entry_title,
				'post_status' => 'publish',
				'post_parent' => $form_id,
			),
			true
		);

		if ( is_wp_error( $entry_id ) ) {
			return $entry_id;
		}

		update_post_meta( $entry_id, self::META_PAYLOAD, wp_json_encode( $payload ) );
		update_post_meta( $entry_id, '_agentic_form_id', $form_id );

		return $entry_id;
	}

	/**
	 * Get entries for a form.
	 *
	 * @param int $form_id  Form CPT post ID.
	 * @param int $per_page Max entries to return.
	 * @param int $page     Page number (1-based).
	 * @return array { entries[], total }
	 */
	public function get_entries( int $form_id, int $per_page = 20, int $page = 1 ): array {
		$query = new WP_Query(
			array(
				'post_type'      => self::ENTRY_CPT,
				'post_parent'    => $form_id,
				'post_status'    => 'publish',
				'posts_per_page' => $per_page,
				'paged'          => $page,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => false,
			)
		);

		$entries = array();
		foreach ( $query->posts as $post ) {
			$raw       = get_post_meta( $post->ID, self::META_PAYLOAD, true );
			$payload   = is_string( $raw ) ? json_decode( $raw, true ) : array();
			$entries[] = array(
				'entry_id'  => $post->ID,
				'submitted' => $post->post_date,
				'fields'    => is_array( $payload ) ? $payload : array(),
			);
		}

		return array(
			'entries' => $entries,
			'total'   => (int) $query->found_posts,
		);
	}

	/**
	 * Send notification emails on new submission.
	 *
	 * Reads per-form notification config from meta. Falls back to admin email
	 * if no config is set. Supports admin notification, custom recipients,
	 * and submitter confirmation emails.
	 *
	 * @param int    $form_id    Form ID.
	 * @param string $form_title Form title.
	 * @param array  $payload    Sanitised field data.
	 * @return void
	 */
	private function send_notification( int $form_id, string $form_title, array $payload ): void {
		$config = $this->get_notification_config( $form_id );

		/* translators: %s: form title */
		$subject = sprintf( __( 'New form submission: %s', 'agent-builder' ), $form_title );

		// Build HTML body for branded email.
		$body_html = '<p>' . sprintf(
			/* translators: %s: form title */
			esc_html__( 'You received a new submission for "%s".', 'agent-builder' ),
			esc_html( $form_title )
		) . '</p>';

		$body_html .= '<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="width:100%;margin:16px 0;">';
		foreach ( $payload as $field ) {
			$label      = $field['label'] ?? '';
			$value      = $field['value'] ?? '';
			$body_html .= '<tr>'
				. '<td style="padding:6px 12px 6px 0;font-weight:600;vertical-align:top;white-space:nowrap;">' . esc_html( $label ) . '</td>'
				. '<td style="padding:6px 0;vertical-align:top;">' . esc_html( $value ) . '</td>'
				. '</tr>';
		}
		$body_html .= '</table>';

		$entries_url = admin_url( 'edit.php?post_type=' . self::ENTRY_CPT . '&post_parent=' . $form_id );
		$body_html  .= \Agentic\Email_Helper::button( $entries_url, __( 'View All Entries', 'agent-builder' ) );

		// Admin notification.
		if ( $config['admin_enabled'] ) {
			$admin_email = get_option( 'admin_email', '' );
			if ( '' !== $admin_email ) {
				\Agentic\Email_Helper::send(
					$admin_email,
					$subject,
					array(
						'heading' => __( 'New Form Submission', 'agent-builder' ),
						'body'    => $body_html,
					)
				);
			}
		}

		// Custom recipients.
		if ( ! empty( $config['recipients'] ) ) {
			foreach ( $config['recipients'] as $email ) {
				$email = sanitize_email( $email );
				if ( is_email( $email ) ) {
					\Agentic\Email_Helper::send(
						$email,
						$subject,
						array(
							'heading' => __( 'New Form Submission', 'agent-builder' ),
							'body'    => $body_html,
						)
					);
				}
			}
		}

		// Submitter confirmation.
		if ( $config['confirmation_enabled'] && ! empty( $config['confirmation_subject'] ) ) {
			$submitter_email = $this->find_submitter_email( $payload );
			if ( $submitter_email ) {
				$conf_body_text = ! empty( $config['confirmation_body'] ) ? $config['confirmation_body'] : __( 'Thank you for your submission. We will be in touch soon.', 'agent-builder' );
				$conf_body      = '<p>' . esc_html( $conf_body_text ) . '</p>';
				\Agentic\Email_Helper::send(
					$submitter_email,
					$config['confirmation_subject'],
					array(
						'heading' => esc_html( $config['confirmation_subject'] ),
						'body'    => $conf_body,
					)
				);
			}
		}
	}

	/**
	 * Get notification config for a form, with defaults.
	 *
	 * @param int $form_id Form ID.
	 * @return array Notification config.
	 */
	public function get_notification_config( int $form_id ): array {
		$raw    = get_post_meta( $form_id, self::META_NOTIFICATIONS, true );
		$config = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : array();
		if ( ! is_array( $config ) ) {
			$config = array();
		}

		return array_merge(
			array(
				'admin_enabled'        => true,
				'recipients'           => array(),
				'confirmation_enabled' => false,
				'confirmation_subject' => '',
				'confirmation_body'    => '',
			),
			$config
		);
	}

	/**
	 * Find the submitter's email address from the payload.
	 *
	 * @param array $payload Submission payload.
	 * @return string|null Email address or null.
	 */
	private function find_submitter_email( array $payload ): ?string {
		foreach ( $payload as $field ) {
			$value = $field['value'] ?? '';
			if ( is_email( $value ) ) {
				return sanitize_email( $value );
			}
		}
		return null;
	}

	/**
	 * Get spam protection config for a form, with defaults.
	 *
	 * @param int $form_id Form ID.
	 * @return array Spam config with keys: honeypot (bool), turnstile (bool).
	 */
	public function get_spam_config( int $form_id ): array {
		$raw    = get_post_meta( $form_id, self::META_SPAM, true );
		$config = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : array();
		if ( ! is_array( $config ) ) {
			$config = array();
		}

		return array_merge(
			array(
				'honeypot'  => true,
				'turnstile' => false,
			),
			$config
		);
	}

	/**
	 * Dispatch webhook(s) for a form submission.
	 *
	 * @param int    $form_id    Form ID.
	 * @param string $form_title Form title.
	 * @param array  $payload    Sanitised field data.
	 * @param int    $entry_id   Entry post ID.
	 * @return void
	 */
	private function dispatch_webhook( int $form_id, string $form_title, array $payload, int $entry_id ): void {
		$raw    = get_post_meta( $form_id, self::META_WEBHOOK, true );
		$config = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : array();
		if ( empty( $config['url'] ) ) {
			return;
		}

		$flat = array();
		foreach ( $payload as $key => $field ) {
			$flat[ $key ] = $field['value'] ?? '';
		}

		$body = array(
			'form_id'    => $form_id,
			'form_title' => $form_title,
			'entry_id'   => $entry_id,
			'timestamp'  => current_time( 'c' ),
			'fields'     => $flat,
		);

		$json    = wp_json_encode( $body );
		$headers = array( 'Content-Type' => 'application/json' );

		// HMAC signature if secret is set.
		if ( ! empty( $config['secret'] ) ) {
			$headers['X-Agentic-Signature'] = hash_hmac( 'sha256', $json, $config['secret'] );
		}

		wp_remote_post(
			$config['url'],
			array(
				'body'     => $json,
				'headers'  => $headers,
				'timeout'  => 10,
				'blocking' => false,
			)
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Shortcode renderer
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Render [agentic_form id="X"] on the front end.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public function render_shortcode( array $atts ): string {
		$atts    = shortcode_atts( array( 'id' => 0 ), $atts, 'agentic_form' );
		$form_id = absint( $atts['id'] );

		if ( 0 === $form_id ) {
			return '<p class="agentic-form-error">' . esc_html__( 'Invalid form ID.', 'agent-builder' ) . '</p>';
		}

		$post = get_post( $form_id );
		if ( ! $post || self::FORM_CPT !== $post->post_type ) {
			return '<p class="agentic-form-error">' . esc_html__( 'Form not found.', 'agent-builder' ) . '</p>';
		}

		$definition = $this->get_definition( $form_id );
		if ( empty( $definition['fields'] ) ) {
			return '<p class="agentic-form-error">' . esc_html__( 'This form has no fields.', 'agent-builder' ) . '</p>';
		}

		$submit_url = rest_url( self::REST_NS . '/native-forms/' . $form_id . '/submit' );
		$nonce      = wp_create_nonce( 'wp_rest' );

		ob_start();
		$this->render_form_styles( $form_id, $definition['styles'] ?? array() );
		?>
		<div class="agentic-form-wrap" id="agentic-form-<?php echo esc_attr( (string) $form_id ); ?>" data-form-id="<?php echo esc_attr( (string) $form_id ); ?>">
			<form class="agentic-form"
					method="post"
					novalidate
					data-submit-url="<?php echo esc_attr( $submit_url ); ?>"
					data-nonce="<?php echo esc_attr( $nonce ); ?>">

			<?php
			// Conditional logic data for JS.
			$conditions      = get_post_meta( $form_id, self::META_CONDITIONS, true );
			$conditions_json = is_string( $conditions ) && '' !== $conditions ? $conditions : '';
			if ( '' !== $conditions_json ) :
				?>
				<script type="application/json" class="agentic-form-conditions"><?php echo $conditions_json; // phpcs:ignore WordPress.Security.EscapeOutput -- validated JSON ?></script>
			<?php endif; ?>

				<?php foreach ( $definition['fields'] as $idx => $field ) : ?>
					<?php $this->render_field( $field, $idx ); ?>
				<?php endforeach; ?>


			<?php
			// Honeypot field — hidden from humans, bots fill it.
			$spam_config = $this->get_spam_config( $form_id );
			if ( $spam_config['honeypot'] ) :
				?>
				<div class="agentic-hp-wrap" aria-hidden="true">
					<label for="agentic_hp_<?php echo esc_attr( (string) $form_id ); ?>">Leave this empty</label>
					<input type="text" name="agentic_hp_field" id="agentic_hp_<?php echo esc_attr( (string) $form_id ); ?>" value="" tabindex="-1" autocomplete="off">
				</div>
			<?php endif; ?>

			<?php
			// Turnstile widget.
			if ( $spam_config['turnstile'] ) :
				$turnstile_site_key = get_option( 'agentic_turnstile_site_key', '' );
				if ( '' !== $turnstile_site_key ) :
					?>
				<div class="cf-turnstile" data-sitekey="<?php echo esc_attr( $turnstile_site_key ); ?>"></div>
					<?php
				endif;
			endif;
			?>

				<p class="agentic-form-submit">
					<button type="submit" class="agentic-form-btn">
						<?php echo esc_html( $definition['submit_label'] ?? __( 'Submit', 'agent-builder' ) ); ?>
					</button>
					<span class="agentic-form-spinner" style="display:none;" aria-hidden="true"></span>
				</p>

			</form>
			<div class="agentic-form-success" style="display:none;" role="alert">
				<?php echo esc_html( $definition['confirmation_message'] ?? __( 'Thank you — your submission has been received!', 'agent-builder' ) ); ?>
			</div>
			<div class="agentic-form-errors" style="display:none;" role="alert"></div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Inject a scoped <style> block for per-form styling.
	 *
	 * @param int   $form_id Form post ID.
	 * @param array $styles  Styles array from the form definition.
	 * @return void
	 */
	private function render_form_styles( int $form_id, array $styles ): void {
		if ( empty( $styles ) ) {
			return;
		}

		$s = '#agentic-form-' . (int) $form_id;
		$f = $s . ' .agentic-form';
		$i = $f . ' input:not([type=hidden]):not([type=radio]):not([type=checkbox]),' . $f . ' select,' . $f . ' textarea';
		$b = $s . ' .agentic-form-btn';

		$rules = array();

		// Wrapper / layout.
		$wrap = array();
		if ( isset( $styles['max_width'] ) ) {
			$wrap[] = 'max-width:' . $styles['max_width'];
		}
		if ( isset( $styles['form_background'] ) ) {
			$wrap[] = 'background:' . $styles['form_background'];
		}
		if ( isset( $styles['form_padding'] ) ) {
			$wrap[] = 'padding:' . $styles['form_padding'];
		}
		if ( isset( $styles['form_border'] ) ) {
			$wrap[] = 'border:' . $styles['form_border'];
		}
		if ( isset( $styles['form_border_radius'] ) ) {
			$wrap[] = 'border-radius:' . $styles['form_border_radius'];
		}
		if ( isset( $styles['font_family'] ) ) {
			$wrap[] = 'font-family:' . $styles['font_family'];
		}
		if ( isset( $styles['font_size'] ) ) {
			$wrap[] = 'font-size:' . $styles['font_size'];
		}
		if ( ! empty( $wrap ) ) {
			$rules[] = $s . '{' . implode( ';', $wrap ) . '}';
		}

		// Field gap.
		if ( isset( $styles['field_gap'] ) ) {
			$rules[] = $f . ' .agentic-form-field{margin-bottom:' . $styles['field_gap'] . '}';
		}

		// Labels.
		$lbl = array();
		if ( isset( $styles['label_color'] ) ) {
			$lbl[] = 'color:' . $styles['label_color'];
		}
		if ( isset( $styles['label_font_size'] ) ) {
			$lbl[] = 'font-size:' . $styles['label_font_size'];
		}
		if ( ! empty( $lbl ) ) {
			$rules[] = $f . ' label{' . implode( ';', $lbl ) . '}';
		}

		// Inputs.
		$inp = array();
		if ( isset( $styles['input_text_color'] ) ) {
			$inp[] = 'color:' . $styles['input_text_color'];
		}
		if ( isset( $styles['input_background'] ) ) {
			$inp[] = 'background:' . $styles['input_background'];
		}
		if ( isset( $styles['input_border_color'] ) ) {
			$inp[] = 'border-color:' . $styles['input_border_color'];
		}
		if ( isset( $styles['input_border_radius'] ) ) {
			$inp[] = 'border-radius:' . $styles['input_border_radius'];
		}
		if ( isset( $styles['input_padding'] ) ) {
			$inp[] = 'padding:' . $styles['input_padding'];
		}
		if ( isset( $styles['font_size'] ) ) {
			$inp[] = 'font-size:' . $styles['font_size'];
		}
		if ( ! empty( $inp ) ) {
			$rules[] = $i . '{' . implode( ';', $inp ) . '}';
		}

		// Input focus.
		if ( isset( $styles['input_focus_color'] ) ) {
			$fc      = $styles['input_focus_color'];
			$rules[] = $f . ' input:focus,' . $f . ' select:focus,' . $f . ' textarea:focus{border-color:' . $fc . ';box-shadow:0 0 0 2px ' . $fc . '33}';
		}

		// Button.
		$btn = array();
		if ( isset( $styles['button_background'] ) ) {
			$btn[] = 'background:' . $styles['button_background'];
		}
		if ( isset( $styles['button_text_color'] ) ) {
			$btn[] = 'color:' . $styles['button_text_color'];
		}
		if ( isset( $styles['button_border_radius'] ) ) {
			$btn[] = 'border-radius:' . $styles['button_border_radius'];
		}
		if ( isset( $styles['button_padding'] ) ) {
			$btn[] = 'padding:' . $styles['button_padding'];
		}
		if ( isset( $styles['button_font_size'] ) ) {
			$btn[] = 'font-size:' . $styles['button_font_size'];
		}
		if ( ! empty( $btn ) ) {
			$rules[] = $b . '{' . implode( ';', $btn ) . '}';
		}

		if ( isset( $styles['button_hover_background'] ) ) {
			$rules[] = $b . ':hover{background:' . $styles['button_hover_background'] . '}';
		}

		if ( empty( $rules ) ) {
			return;
		}

		echo '<style>' . implode( ' ', $rules ) . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput -- values sanitised in save_native_form tool
	}

	/**
	 * Render an individual field row.
	 *
	 * @param array $field Field definition.
	 * @param int   $idx   Zero-based index (used for id attributes).
	 * @return void
	 */
	private function render_field( array $field, int $idx ): void {
		$name        = sanitize_key( $field['name'] ?? ( 'field_' . $idx ) );
		$label       = sanitize_text_field( $field['label'] ?? $name );
		$type        = sanitize_key( $field['type'] ?? 'text' );
		$required    = ! empty( $field['required'] );
		$placeholder = sanitize_text_field( $field['placeholder'] ?? '' );
		$field_id    = 'af_field_' . $idx;

		$req_attr = $required ? ' required aria-required="true"' : '';
		$req_mark = $required ? ' <span class="agentic-required" aria-hidden="true">*</span>' : '';
		?>
		<div class="agentic-form-field agentic-field-type-<?php echo esc_attr( $type ); ?>">
			<?php if ( 'hidden' !== $type ) : ?>
				<label for="<?php echo esc_attr( $field_id ); ?>">
					<?php echo esc_html( $label ); ?>
					<?php
					echo wp_kses(
						$req_mark,
						array(
							'span' => array(
								'class'       => array(),
								'aria-hidden' => array(),
							),
						)
					);
					?>
				</label>
			<?php endif; ?>

			<?php if ( 'textarea' === $type ) : ?>
				<textarea id="<?php echo esc_attr( $field_id ); ?>"
							name="<?php echo esc_attr( $name ); ?>"
							placeholder="<?php echo esc_attr( $placeholder ); ?>"
							<?php echo $req_attr; // phpcs:ignore WordPress.Security.EscapeOutput ?> rows="4"></textarea>

			<?php elseif ( 'select' === $type ) : ?>
				<select id="<?php echo esc_attr( $field_id ); ?>"
						name="<?php echo esc_attr( $name ); ?>"
						<?php echo $req_attr; // phpcs:ignore WordPress.Security.EscapeOutput ?>>
					<option value=""><?php echo esc_html( ! empty( $placeholder ) ? $placeholder : '— Select —' ); ?></option>
					<?php foreach ( (array) ( $field['options'] ?? array() ) as $opt ) : ?>
						<?php
						$opt_val   = is_array( $opt ) ? ( $opt['value'] ?? $opt['label'] ?? '' ) : $opt;
						$opt_label = is_array( $opt ) ? ( $opt['label'] ?? $opt_val ) : $opt;
						?>
						<option value="<?php echo esc_attr( (string) $opt_val ); ?>"><?php echo esc_html( (string) $opt_label ); ?></option>
					<?php endforeach; ?>
				</select>

			<?php elseif ( in_array( $type, array( 'radio', 'checkbox' ), true ) ) : ?>
				<fieldset>
					<legend class="screen-reader-text"><?php echo esc_html( $label ); ?></legend>
					<?php foreach ( (array) ( $field['options'] ?? array() ) as $oi => $opt ) : ?>
						<?php
						$opt_val   = is_array( $opt ) ? ( $opt['value'] ?? $opt['label'] ?? '' ) : $opt;
						$opt_label = is_array( $opt ) ? ( $opt['label'] ?? $opt_val ) : $opt;
						$opt_id    = $field_id . '_' . $oi;
						$opt_name  = 'checkbox' === $type ? $name . '[]' : $name;
						?>
						<label class="agentic-option-label" for="<?php echo esc_attr( $opt_id ); ?>">
							<input type="<?php echo esc_attr( $type ); ?>"
									id="<?php echo esc_attr( $opt_id ); ?>"
									name="<?php echo esc_attr( $opt_name ); ?>"
									value="<?php echo esc_attr( (string) $opt_val ); ?>"
									<?php echo $req_attr; // phpcs:ignore WordPress.Security.EscapeOutput ?>>
							<?php echo esc_html( (string) $opt_label ); ?>
						</label>
					<?php endforeach; ?>
				</fieldset>

			<?php else : ?>
				<input type="<?php echo esc_attr( 'hidden' === $type ? 'hidden' : $type ); ?>"
						id="<?php echo 'hidden' === $type ? '' : esc_attr( $field_id ); ?>"
						name="<?php echo esc_attr( $name ); ?>"
						placeholder="<?php echo esc_attr( $placeholder ); ?>"
						value="<?php echo 'hidden' === $type ? esc_attr( $field['value'] ?? '' ) : ''; ?>"
						<?php echo $req_attr; // phpcs:ignore WordPress.Security.EscapeOutput ?>>
			<?php endif; ?>
		</div>
		<?php
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Front-end assets
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Render [agentic_form_entries id="X" limit="20" public="0"] on the front end.
	 *
	 * By default only logged-in admins (manage_options) can view entries.
	 * Pass public="1" to allow any logged-in user (use with care).
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public function render_entries_shortcode( array $atts ): string {
		$atts = shortcode_atts(
			array(
				'id'     => 0,
				'limit'  => 20,
				'page'   => 1,
				'public' => '0',
			),
			$atts,
			'agentic_form_entries'
		);

		$form_id   = absint( $atts['id'] );
		$limit     = min( absint( $atts['limit'] ), 100 );
		$page      = max( 1, absint( $atts['page'] ) );
		$is_public = '1' === (string) $atts['public'];

		// Access control.
		$can_view = $is_public ? is_user_logged_in() : current_user_can( 'manage_options' );
		if ( ! $can_view ) {
			return '<p class="agentic-form-error">' . esc_html__( 'You do not have permission to view form entries.', 'agent-builder' ) . '</p>';
		}

		if ( 0 === $form_id ) {
			return '<p class="agentic-form-error">' . esc_html__( 'Invalid form ID.', 'agent-builder' ) . '</p>';
		}

		$post = get_post( $form_id );
		if ( ! $post || self::FORM_CPT !== $post->post_type ) {
			return '<p class="agentic-form-error">' . esc_html__( 'Form not found.', 'agent-builder' ) . '</p>';
		}

		$definition = $this->get_definition( $form_id );
		$field_defs = $definition['fields'] ?? array();
		$result     = $this->get_entries( $form_id, $limit, $page );
		$entries    = $result['entries'];
		$total      = $result['total'];

		ob_start();
		?>
		<div class="agentic-entries-wrap">
			<h3 class="agentic-entries-title">
				<?php
				/* translators: %s: form title */
				printf( esc_html__( 'Entries: %s', 'agent-builder' ), esc_html( $post->post_title ) );
				?>
				<span class="agentic-entries-count">(<?php echo esc_html( (string) $total ); ?>)</span>
			</h3>

			<?php if ( empty( $entries ) ) : ?>
				<p class="agentic-entries-empty"><?php esc_html_e( 'No entries yet.', 'agent-builder' ); ?></p>
			<?php else : ?>
				<div class="agentic-entries-table-wrap">
					<table class="agentic-entries-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Date', 'agent-builder' ); ?></th>
								<?php foreach ( $field_defs as $fdef ) : ?>
									<th><?php echo esc_html( $fdef['label'] ?? $fdef['name'] ?? '' ); ?></th>
								<?php endforeach; ?>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $entries as $entry ) : ?>
								<tr>
									<td class="agentic-entry-date">
										<?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $entry['submitted'] ) ) ); ?>
									</td>
									<?php
									foreach ( $field_defs as $fdef ) :
										$key = sanitize_key( $fdef['name'] ?? $fdef['label'] ?? '' );
										$val = $entry['fields'][ $key ]['value'] ?? '';
										?>
										<td><?php echo esc_html( (string) $val ); ?></td>
									<?php endforeach; ?>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Enqueue front-end CSS + JS for native forms.
	 * Only loads when the current page contains a native form shortcode.
	 *
	 * @return void
	 */
	public function enqueue_frontend_assets(): void {
		global $post;

		if ( ! is_a( $post, 'WP_Post' ) ) {
			return;
		}

		if ( false === strpos( $post->post_content, '[agentic_form' ) ) {
			return;
		}

		wp_enqueue_style(
			'agentic-native-forms',
			AGENT_BUILDER_URL . 'assets/css/native-forms.css',
			array(),
			(string) filemtime( AGENT_BUILDER_DIR . 'assets/css/native-forms.css' )
		);

		wp_enqueue_script(
			'agentic-native-forms',
			AGENT_BUILDER_URL . 'assets/js/native-forms.js',
			array(),
			(string) filemtime( AGENT_BUILDER_DIR . 'assets/js/native-forms.js' ),
			true
		);

		// Enqueue Turnstile script if any form on the page uses it.
		if ( \Agentic\Turnstile::is_required() ) {
			\Agentic\Turnstile::enqueue_script( 'cf-turnstile', false );
		}
	}
}
