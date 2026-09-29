<?php
/**
 * Tool: Get Site Overview
 *
 * Return a high-level overview of the WordPress installation.
 *
 * @package Agentic\Tools
 */

declare(strict_types=1);

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Get_Site_Overview extends \Agentic\Tool_Base {

	public function get_name(): string {
		return 'get_site_overview';
	}

	public function get_description(): string {
		return 'Return a high-level overview of the WordPress installation: version, theme, plugin count, permalink structure, menus, and search visibility.';
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
		$active_plugins = (array) get_option( 'active_plugins', array() );

		// Detect caching plugins.
		$cache_plugins    = array( 'wp-super-cache', 'w3-total-cache', 'litespeed-cache', 'wp-rocket', 'wp-fastest-cache', 'comet-cache' );
		$has_cache_plugin = false;
		foreach ( $active_plugins as $plugin ) {
			foreach ( $cache_plugins as $cp ) {
				if ( str_contains( $plugin, $cp ) ) {
					$has_cache_plugin = true;
					break 2;
				}
			}
		}

		// Check for a custom 404 page.
		$has_404 = (bool) get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'meta_key'       => '_wp_page_template', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => '404', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'posts_per_page' => 1,
			)
		);
		if ( ! $has_404 ) {
			$page_404_query = new \WP_Query(
				array(
					'post_type'      => 'page',
					'post_status'    => 'publish',
					'title'          => '404',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			);
			$has_404        = ! empty( $page_404_query->posts );
		}

		// Primary nav menu item count.
		$menus      = wp_get_nav_menus();
		$menu_count = null;
		if ( ! empty( $menus ) ) {
			$items      = wp_get_nav_menu_items( $menus[0]->term_id );
			$menu_count = $items ? count( array_filter( $items, fn( $i ) => ! $i->menu_item_parent ) ) : null;
		}

		$permalink_struct = get_option( 'permalink_structure' );
		$permalink_name   = match ( true ) {
			empty( $permalink_struct )               => 'default',
			'/%postname%/' === $permalink_struct     => 'plain',
			str_contains( $permalink_struct, 'date' ) => 'date-based',
			default                                  => $permalink_struct,
		};

		return array(
			'site_url'            => home_url(),
			'site_title'          => get_bloginfo( 'name' ),
			'tagline'             => get_bloginfo( 'description' ),
			'wp_version'          => get_bloginfo( 'version' ),
			'active_theme'        => wp_get_theme()->get( 'Name' ),
			'active_plugin_count' => count( $active_plugins ),
			'permalink_structure' => $permalink_name,
			'has_caching_plugin'  => $has_cache_plugin,
			'has_404_page'        => $has_404,
			'menu_item_count'     => $menu_count,
			'search_discouraged'  => (bool) get_option( 'blog_public' ) === false,
		);
	}
}

return new Get_Site_Overview();
