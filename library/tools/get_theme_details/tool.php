<?php
declare(strict_types=1);

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get Theme Details Tool
 *
 * Returns detailed information about the active theme including
 * block theme support, parent/child relationship, template files,
 * theme features, and global styles configuration.
 *
 * @package Agentic\Tools
 */
class Get_Theme_Details extends \Agentic\Tool_Base {

	public function get_name(): string {
		return 'get_theme_details';
	}

	public function get_description(): string {
		return 'Get detailed information about the active WordPress theme: block theme support, parent/child status, template files, supported features, and theme.json configuration.';
	}

	public function get_category(): string {
		return 'themes';
	}

	public function get_parameters(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'stylesheet' => array(
					'type'        => 'string',
					'description' => 'Theme stylesheet slug. Omit to use the active theme.',
				),
			),
		);
	}

	public function execute( array $arguments ): array {
		$stylesheet = $arguments['stylesheet'] ?? get_stylesheet();
		$theme      = wp_get_theme( $stylesheet );

		if ( ! $theme->exists() ) {
			return array( 'error' => 'Theme not found: ' . $stylesheet );
		}

		$parent         = $theme->parent();
		$is_block_theme = $theme->is_block_theme();

		$details = array(
			'name'           => $theme->get( 'Name' ),
			'version'        => $theme->get( 'Version' ),
			'author'         => $theme->get( 'Author' ),
			'description'    => $theme->get( 'Description' ),
			'stylesheet'     => $theme->get_stylesheet(),
			'template'       => $theme->get_template(),
			'is_active'      => ( get_stylesheet() === $stylesheet ),
			'is_child_theme' => $theme->parent() !== false,
			'parent_theme'   => $parent ? $parent->get( 'Name' ) : null,
			'is_block_theme' => $is_block_theme,
			'text_domain'    => $theme->get( 'TextDomain' ),
			'tags'           => $theme->get( 'Tags' ) ? $theme->get( 'Tags' ) : array(),
			'status'         => $theme->get( 'Status' ),
		);

		// Theme supports.
		$supports = array();
		$features = array(
			'title-tag',
			'custom-logo',
			'custom-header',
			'custom-background',
			'post-thumbnails',
			'automatic-feed-links',
			'html5',
			'post-formats',
			'editor-styles',
			'wp-block-styles',
			'responsive-embeds',
			'align-wide',
			'editor-color-palette',
			'editor-font-sizes',
		);
		foreach ( $features as $feature ) {
			if ( current_theme_supports( $feature ) ) {
				$supports[] = $feature;
			}
		}
		$details['supports'] = $supports;

		// Template files (block themes).
		if ( $is_block_theme ) {
			$template_dir = $theme->get_stylesheet_directory() . '/templates';
			$templates    = array();
			if ( is_dir( $template_dir ) ) {
				foreach ( glob( $template_dir . '/*.html' ) as $file ) {
					$templates[] = basename( $file );
				}
			}
			$details['block_templates'] = $templates;

			$parts_dir = $theme->get_stylesheet_directory() . '/parts';
			$parts     = array();
			if ( is_dir( $parts_dir ) ) {
				foreach ( glob( $parts_dir . '/*.html' ) as $file ) {
					$parts[] = basename( $file );
				}
			}
			$details['template_parts'] = $parts;
		}

		// Check for theme.json.
		$theme_json_path           = $theme->get_stylesheet_directory() . '/theme.json';
		$details['has_theme_json'] = file_exists( $theme_json_path );

		// Update available?
		$updates                     = get_site_transient( 'update_themes' );
		$details['update_available'] = isset( $updates->response[ $stylesheet ] );
		if ( $details['update_available'] ) {
			$details['update_version'] = $updates->response[ $stylesheet ]['new_version'] ?? null;
		}

		return $details;
	}

	public function get_annotations(): array {
		return array( 'readonly' => true );
	}
}

return new Get_Theme_Details();
