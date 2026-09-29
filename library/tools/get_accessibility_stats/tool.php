<?php
/**
 * Tool: Get Accessibility Stats
 *
 * Check basic accessibility: images without alt text, heading hierarchy
 * compliance, generic link text, and form accessibility plugin detection.
 *
 * @package Agentic\Tools
 */

declare(strict_types=1);

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Get_Accessibility_Stats extends \Agentic\Tool_Base {

	public function get_name(): string {
		return 'get_accessibility_stats';
	}

	public function get_description(): string {
		return 'Check basic accessibility: images without alt text, heading hierarchy compliance, generic link text, and form accessibility plugin detection.';
	}

	public function get_category(): string {
		return 'site-audit';
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
		$posts = get_posts(
			array(
				'post_type'      => array( 'post', 'page' ),
				'post_status'    => 'publish',
				'posts_per_page' => -1,
			)
		);

		$total_images      = 0;
		$missing_alt       = 0;
		$empty_alt         = 0;
		$pages_no_h1       = 0;
		$pages_multiple_h1 = 0;
		$generic_links     = 0;

		$generic_patterns = array( 'click here', 'read more', 'here', 'learn more', 'more info', 'more information' );

		foreach ( $posts as $post ) {
			preg_match_all( '/<img[^>]+>/i', $post->post_content, $img_matches );
			foreach ( $img_matches[0] as $img ) {
				++$total_images;
				if ( preg_match( '/alt=["\']["\']/', $img ) ) {
					++$empty_alt;
				} elseif ( ! preg_match( '/alt=["\'][^"\']+["\']/', $img ) ) {
					++$missing_alt;
				}
			}

			preg_match_all( '/<h1[^>]*>/i', $post->post_content, $h1m );
			$h1c = count( $h1m[0] );
			if ( 0 === $h1c ) {
				++$pages_no_h1; }
			if ( $h1c > 1 ) {
				++$pages_multiple_h1; }

			foreach ( $generic_patterns as $pattern ) {
				$generic_links += substr_count( strtolower( $post->post_content ), ">$pattern<" );
			}
		}

		$active_plugins  = (array) get_option( 'active_plugins', array() );
		$form_plugins    = array( 'contact-form-7', 'gravityforms', 'ninja-forms', 'wpforms' );
		$has_form_plugin = false;
		foreach ( $active_plugins as $p ) {
			foreach ( $form_plugins as $fp ) {
				if ( str_contains( $p, $fp ) ) {
					$has_form_plugin = true;
					break 2;
				}
			}
		}

		return array(
			'total_images'       => $total_images,
			'images_missing_alt' => $missing_alt,
			'images_empty_alt'   => $empty_alt,
			'pages_no_h1'        => $pages_no_h1,
			'pages_multiple_h1'  => $pages_multiple_h1,
			'generic_link_count' => $generic_links,
			'has_form_plugin'    => $has_form_plugin,
		);
	}
}

return new Get_Accessibility_Stats();
