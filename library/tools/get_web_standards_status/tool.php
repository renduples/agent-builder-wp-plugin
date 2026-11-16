<?php
/**
 * Tool: Get Web Standards Status
 *
 * Check web standards compliance: robots.txt, XML sitemap, favicon,
 * Open Graph tags, canonical URLs, viewport meta, and responsive images.
 *
 * @package Agentic\Tools
 */

declare(strict_types=1);

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Get_Web_Standards_Status extends \Agentic\Tool_Base {

	public function get_name(): string {
		return 'get_web_standards_status';
	}

	public function get_description(): string {
		return 'Check web standards compliance: robots.txt, XML sitemap, favicon, Open Graph tags, canonical URLs, viewport meta, and responsive images (srcset).';
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
		$home = home_url();

		// robots.txt.
		// WordPress serves a robots.txt either way (a physical file or its own).
		$has_robots = '' !== trim( \Agentic\Virtual_Site_Files::robots_txt()['content'] );

		// Sitemap.
		$has_sitemap  = false;
		$sitemap_pct  = 0;
		$sitemap_urls = array(
			home_url( '/sitemap.xml' ),
			home_url( '/sitemap_index.xml' ),
			home_url( '/wp-sitemap.xml' ),
		);

		foreach ( $sitemap_urls as $sitemap_url ) {
			$sm_response = @wp_safe_remote_get(
				$sitemap_url,
				array(
					'timeout'   => 5,
					'sslverify' => false,
				)
			);
			if ( ! is_wp_error( $sm_response ) && wp_remote_retrieve_response_code( $sm_response ) === 200 ) {
				$has_sitemap = true;
				$sm_body     = wp_remote_retrieve_body( $sm_response );
				$url_count   = substr_count( $sm_body, '<url>' ) + substr_count( $sm_body, '<sitemap>' );
				$post_count  = wp_count_posts( 'post' )->publish + wp_count_posts( 'page' )->publish;
				$sitemap_pct = $post_count > 0 ? min( 100, round( ( $url_count / $post_count ) * 100 ) ) : 100;
				break;
			}
		}

		// Favicon.
		$favicon = get_site_icon_url();
		$has_fav = ! empty( $favicon );

		// Viewport meta, OG tags, canonical, srcset — from homepage HTML.
		$home_response = @wp_safe_remote_get(
			$home,
			array(
				'timeout'   => 8,
				'sslverify' => false,
			)
		);
		$home_html     = ! is_wp_error( $home_response ) ? wp_remote_retrieve_body( $home_response ) : '';

		$viewport_meta = (bool) preg_match( '/<meta[^>]+name=["\']viewport["\'][^>]*>/i', $home_html );
		$has_og_home   = (bool) preg_match( '/<meta[^>]+property=["\']og:/i', $home_html );
		$has_canonical = (bool) preg_match( '/<link[^>]+rel=["\']canonical["\'][^>]*>/i', $home_html );
		$uses_srcset   = (bool) preg_match( '/srcset=["\'][^"\']+/', $home_html );

		// Coverage approximation based on homepage.
		$og_pct        = $has_og_home ? 90 : 0;
		$canonical_pct = $has_canonical ? 90 : 0;

		return array(
			'robots_txt_exists'      => $has_robots,
			'sitemap_exists'         => $has_sitemap,
			'sitemap_coverage_pct'   => $sitemap_pct,
			'favicon_present'        => $has_fav,
			'viewport_meta_present'  => $viewport_meta,
			'og_coverage_pct'        => $og_pct,
			'canonical_coverage_pct' => $canonical_pct,
			'uses_srcset'            => $uses_srcset,
		);
	}
}

return new Get_Web_Standards_Status();
