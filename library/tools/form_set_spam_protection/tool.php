<?php
/**
 * Tool: form_set_spam_protection
 *
 * Configure spam protection settings for a native agentic_form.
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
 * Configure spam protection for a native form.
 *
 * Supports honeypot field and Cloudflare Turnstile integration.
 * Saves config as JSON to the form's META_SPAM post meta.
 */
class Form_Set_Spam_Protection extends \Agentic\Tool_Base {

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'form_set_spam_protection';
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return 'Configure spam protection for a native form. ' .
			'Supports honeypot fields and Cloudflare Turnstile captcha.';
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
				'form_id'   => array(
					'type'        => 'integer',
					'description' => 'The ID of the native form to configure spam protection for.',
				),
				'honeypot'  => array(
					'type'        => 'boolean',
					'description' => 'Whether to enable the honeypot hidden field. Defaults to true.',
					'default'     => true,
				),
				'turnstile' => array(
					'type'        => 'boolean',
					'description' => 'Whether to enable Cloudflare Turnstile captcha. Defaults to false.',
					'default'     => false,
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
			return array( 'error' => 'You do not have permission to configure spam protection.' );
		}

		$form_id = absint( $arguments['form_id'] ?? 0 );

		if ( 0 === $form_id ) {
			return array( 'error' => 'A form_id is required.' );
		}

		$post = get_post( $form_id );
		if ( ! $post || \Agentic_Native_Forms::FORM_CPT !== $post->post_type ) {
			return array( 'error' => 'Native form with ID ' . $form_id . ' was not found.' );
		}

		$engine  = \Agentic_Native_Forms::get_instance();
		$current = $engine->get_spam_config( $form_id );

		$config = array(
			'honeypot'  => (bool) ( $arguments['honeypot'] ?? $current['honeypot'] ),
			'turnstile' => (bool) ( $arguments['turnstile'] ?? $current['turnstile'] ),
		);

		update_post_meta( $form_id, \Agentic_Native_Forms::META_SPAM, wp_json_encode( $config ) );

		// Check whether Turnstile keys are configured in site options.
		$turnstile_site_key   = get_option( 'agent_builder_turnstile_site_key', '' );
		$turnstile_secret_key = get_option( 'agent_builder_turnstile_secret_key', '' );
		$turnstile_keys_set   = '' !== $turnstile_site_key && '' !== $turnstile_secret_key;

		$result = array(
			'form_id'    => $form_id,
			'form_title' => $post->post_title,
			'spam'       => $config,
			'success'    => true,
		);

		if ( $config['turnstile'] && ! $turnstile_keys_set ) {
			$result['warning'] = 'Turnstile is enabled but site key and/or secret key are not configured. ' .
				'Set them under Agentic > Settings > Security or via the agent_builder_turnstile_site_key and agent_builder_turnstile_secret_key options.';
		}

		$result['turnstile_keys_configured'] = $turnstile_keys_set;

		return $result;
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
			'idempotent'  => true,
		);
	}
}

return new Form_Set_Spam_Protection();
