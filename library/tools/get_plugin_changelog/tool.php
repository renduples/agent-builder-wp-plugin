<?php
/**
 * Tool: get_plugin_changelog
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

class Get_Plugin_Changelog extends Tool_Base {
	public function get_name(): string {
		return 'get_plugin_changelog';
	}

	public function get_description(): string {
		return 'Fetch the changelog for a WordPress.org plugin by its slug. Returns the changelog section as plain text (HTML stripped), truncated to 3000 characters.';
	}

	public function get_category(): string {
		return 'plugins';
	}

	public function get_parameters(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'plugin_slug' => array(
					'type'        => 'string',
					'description' => 'The WordPress.org plugin slug, e.g. "woocommerce" or "contact-form-7".',
				),
			),
			'required'   => array( 'plugin_slug' ),
		);
	}

	public function get_annotations(): array {
		return array(
			'read_only'   => true,
			'destructive' => false,
		);
	}

	public function execute( array $args ): array {
		$slug = sanitize_key( $args['plugin_slug'] ?? '' );
		if ( ! $slug ) {
			return array( 'error' => 'plugin_slug is required.' );
		}

		$url = 'https://api.wordpress.org/plugins/info/1.2/?action=plugin_information'
			. '&request[slug]=' . rawurlencode( $slug )
			. '&request[fields][sections]=1';

		$response = wp_remote_get( $url, array( 'timeout' => 15 ) );
		if ( is_wp_error( $response ) ) {
			return array( 'error' => 'API request failed: ' . $response->get_error_message() );
		}

		if ( wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return array( 'error' => 'WP.org API returned non-200 response.' );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data ) || isset( $data['error'] ) ) {
			return array( 'error' => "Plugin '{$slug}' not found on WordPress.org." );
		}

		$changelog = $data['sections']['changelog'] ?? '';
		$changelog = wp_strip_all_tags( $changelog );
		$changelog = trim( $changelog );

		if ( strlen( $changelog ) > 3000 ) {
			$changelog = substr( $changelog, 0, 3000 ) . '... [truncated]';
		}

		return array(
			'name'      => $data['name'] ?? $slug,
			'version'   => $data['version'] ?? 'unknown',
			'slug'      => $slug,
			'changelog' => $changelog ? $changelog : 'No changelog available for this plugin.',
		);
	}
}

return new Get_Plugin_Changelog();
