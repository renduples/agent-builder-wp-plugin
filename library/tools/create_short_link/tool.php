<?php
/**
 * Tool: create_short_link
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

class Create_Short_Link extends Tool_Base {
	public function get_name(): string {
		return 'create_short_link';
	}

	public function get_description(): string {
		return 'Create a short redirect link at /go/{slug} pointing to any URL. Auto-generates a unique 6-character slug if not provided.';
	}

	public function get_category(): string {
		return 'utility';
	}

	public function get_parameters(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'target_url' => array(
					'type'        => 'string',
					'description' => 'The destination URL the short link should redirect to.',
				),
				'slug'       => array(
					'type'        => 'string',
					'description' => 'Custom slug for the short link (e.g. "sale2025"). Auto-generated if not provided.',
				),
			),
			'required'   => array( 'target_url' ),
		);
	}

	public function get_annotations(): array {
		return array(
			'read_only'   => false,
			'destructive' => false,
		);
	}

	public function execute( array $args ): array {
		$target_url = esc_url_raw( trim( $args['target_url'] ?? '' ) );
		$slug       = sanitize_key( $args['slug'] ?? '' );

		if ( ! $target_url ) {
			return array( 'error' => 'target_url is required.' );
		}

		$links = (array) get_option( 'agent_builder_short_links', array() );

		// Generate unique slug if not provided.
		if ( ! $slug ) {
			$chars    = 'abcdefghijklmnopqrstuvwxyz0123456789';
			$attempts = 0;
			do {
				$slug = '';
				for ( $i = 0; $i < 6; $i++ ) {
					$slug .= $chars[ wp_rand( 0, strlen( $chars ) - 1 ) ];
				}
				++$attempts;
			} while ( isset( $links[ $slug ] ) && $attempts < 20 );
		} else {
			// Check uniqueness.
			if ( isset( $links[ $slug ] ) ) {
				return array(
					'error'    => "Slug '{$slug}' is already in use.",
					'existing' => array(
						'target_url' => $links[ $slug ]['target_url'],
						'short_url'  => site_url( '/go/' . $slug ),
					),
				);
			}
		}

		$links[ $slug ] = array(
			'target_url' => $target_url,
			'created_at' => gmdate( 'c' ),
			'clicks'     => 0,
		);

		// Cap the stored links so the option cannot grow without bound. When the
		// limit is exceeded, drop the oldest entries by creation time.
		$max_links = 500;
		if ( count( $links ) > $max_links ) {
			uasort(
				$links,
				static fn( $a, $b ) => strcmp( (string) ( $a['created_at'] ?? '' ), (string) ( $b['created_at'] ?? '' ) )
			);
			$links = array_slice( $links, count( $links ) - $max_links, null, true );
		}

		update_option( 'agent_builder_short_links', $links, false );

		return array(
			'slug'        => $slug,
			'short_url'   => site_url( '/go/' . $slug ),
			'target_url'  => $target_url,
			'redirect'    => '301 permanent',
			'enforced_by' => 'template_redirect hook in Agent Builder (active on every frontend request)',
		);
	}
}

return new Create_Short_Link();
