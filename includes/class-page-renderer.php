<?php
/**
 * Page Renderer — fetch and parse fully rendered page HTML
 *
 * Core platform utility that fetches pages via HTTP to get the complete
 * rendered output including header, footer, menus, widgets, and dynamic
 * content. This is what search engines and AI crawlers actually see —
 * unlike SEO plugins that only read post_content from the database.
 *
 * @package    Agent_Builder
 * @subpackage Includes
 * @author     AgentStructor Team <support@agentic-plugin.com>
 * @license    GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 * @link       https://agentstructor.com
 * @since      2.2.0
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fetches and parses fully rendered WordPress pages via HTTP.
 *
 * Returns the same HTML that Google, AI crawlers, and real visitors see —
 * not the raw post_content from the database.
 */
class Page_Renderer {

	/**
	 * Transient cache prefix.
	 */
	private const CACHE_PREFIX = 'agentic_rendered_';

	/**
	 * Default cache TTL in seconds (5 minutes).
	 */
	private const CACHE_TTL = 300;

	/**
	 * Maximum HTML size we'll process (2 MB).
	 */
	private const MAX_HTML_SIZE = 2 * 1024 * 1024;

	/**
	 * Fetch a fully rendered page via HTTP.
	 *
	 * Issues a real HTTP request to the URL (via wp_remote_get) so the
	 * response includes everything WordPress renders: theme header/footer,
	 * navigation menus, sidebar widgets, shortcode output, block output,
	 * schema markup, Open Graph tags, and all other dynamic content.
	 *
	 * Results are cached in a transient for 5 minutes to avoid hammering
	 * the server during multi-page audits.
	 *
	 * @param string $url     Full URL to fetch. Must be on the current site.
	 * @param array  $options {
	 *     Optional settings.
	 *
	 *     @type bool $strip_assets  Strip <script> and <style> tags for LLM consumption. Default true.
	 *     @type bool $extract_sections  Parse HTML into semantic sections (head, header, nav, main, footer, aside). Default true.
	 *     @type bool $bypass_cache  Skip transient cache. Default false.
	 *     @type int  $timeout       HTTP request timeout in seconds. Default 15.
	 * }
	 * @return array{
	 *     success: bool,
	 *     url: string,
	 *     status: int,
	 *     headers: array,
	 *     html: string,
	 *     html_size: int,
	 *     sections: array,
	 *     meta: array,
	 *     cached: bool,
	 *     error?: string
	 * }
	 */
	public static function fetch( string $url, array $options = array() ): array {
		$defaults = array(
			'strip_assets'     => true,
			'extract_sections' => true,
			'bypass_cache'     => false,
			'timeout'          => 15,
		);
		$options  = wp_parse_args( $options, $defaults );

		// Validate URL is on the current site.
		if ( ! self::is_same_site( $url ) ) {
			return self::error_response( $url, 'URL is not on the current site. Only same-site fetching is allowed.' );
		}

		// Check transient cache.
		$cache_key = self::CACHE_PREFIX . md5( $url );
		if ( ! $options['bypass_cache'] ) {
			$cached = get_transient( $cache_key );
			if ( false !== $cached && is_array( $cached ) ) {
				$cached['cached'] = true;
				return $cached;
			}
		}

		// Fetch the page.
		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => $options['timeout'],
				'sslverify'  => false,
				'user-agent' => 'Agentic-Page-Renderer/1.0 (WordPress/' . get_bloginfo( 'version' ) . ')',
				'headers'    => array(
					'Accept' => 'text/html',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return self::error_response( $url, $response->get_error_message() );
		}

		$status = wp_remote_retrieve_response_code( $response );
		$html   = wp_remote_retrieve_body( $response );

		if ( $status >= 400 ) {
			return self::error_response( $url, "HTTP {$status} response", $status );
		}

		if ( empty( $html ) ) {
			return self::error_response( $url, 'Empty response body', $status );
		}

		// Enforce size limit.
		if ( strlen( $html ) > self::MAX_HTML_SIZE ) {
			$html = substr( $html, 0, self::MAX_HTML_SIZE );
		}

		$raw_size = strlen( $html );

		// Extract metadata from <head> before stripping.
		$meta = self::extract_meta( $html );

		// Extract semantic sections if requested.
		$sections = array();
		if ( $options['extract_sections'] ) {
			$sections = self::extract_sections( $html );
		}

		// Strip scripts and styles for LLM consumption.
		if ( $options['strip_assets'] ) {
			$html = self::strip_assets( $html );
		}

		$result = array(
			'success'   => true,
			'url'       => $url,
			'status'    => $status,
			'headers'   => self::extract_response_headers( $response ),
			'html'      => $html,
			'html_size' => $raw_size,
			'sections'  => $sections,
			'meta'      => $meta,
			'cached'    => false,
		);

		// Cache the result.
		set_transient( $cache_key, $result, self::CACHE_TTL );

		return $result;
	}

	/**
	 * Fetch a page by post ID.
	 *
	 * Convenience method that looks up the permalink for a post ID and
	 * delegates to fetch().
	 *
	 * @param int   $post_id Post ID.
	 * @param array $options Same options as fetch().
	 * @return array Same return format as fetch().
	 */
	public static function fetch_by_post_id( int $post_id, array $options = array() ): array {
		$url = get_permalink( $post_id );
		if ( ! $url ) {
			return self::error_response(
				'post_id:' . $post_id,
				'Could not resolve permalink for post ID ' . $post_id
			);
		}
		return self::fetch( $url, $options );
	}

	/**
	 * Invalidate the cache for a specific URL.
	 *
	 * @param string $url URL to invalidate.
	 */
	public static function invalidate( string $url ): void {
		delete_transient( self::CACHE_PREFIX . md5( $url ) );
	}

	/**
	 * Invalidate cache for a post (called on post save/update).
	 *
	 * @param int $post_id Post ID.
	 */
	public static function invalidate_post( int $post_id ): void {
		$url = get_permalink( $post_id );
		if ( $url ) {
			self::invalidate( $url );
		}
	}

	// -------------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------------

	/**
	 * Check if a URL belongs to the current WordPress site.
	 *
	 * @param string $url URL to check.
	 * @return bool
	 */
	private static function is_same_site( string $url ): bool {
		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
		$url_host  = wp_parse_url( $url, PHP_URL_HOST );

		if ( ! $site_host || ! $url_host ) {
			return false;
		}

		return strtolower( $site_host ) === strtolower( $url_host );
	}

	/**
	 * Extract key metadata from the HTML <head>.
	 *
	 * Pulls title, meta description, canonical, OG tags, robots directives,
	 * and JSON-LD schema types — everything an SEO analysis needs.
	 *
	 * @param string $html Full HTML.
	 * @return array Extracted metadata.
	 */
	private static function extract_meta( string $html ): array {
		$meta = array(
			'title'            => '',
			'meta_description' => '',
			'canonical'        => '',
			'robots'           => '',
			'og_title'         => '',
			'og_description'   => '',
			'og_image'         => '',
			'og_type'          => '',
			'schema_types'     => array(),
			'h1'               => array(),
			'h2'               => array(),
			'link_count'       => 0,
			'image_count'      => 0,
			'word_count'       => 0,
		);

		// Title tag.
		if ( preg_match( '/<title[^>]*>(.*?)<\/title>/si', $html, $m ) ) {
			$meta['title'] = html_entity_decode( trim( $m[1] ), ENT_QUOTES, 'UTF-8' );
		}

		// Meta tags (description, robots).
		if ( preg_match( '/<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']*)["\'][^>]*>/si', $html, $m ) ) {
			$meta['meta_description'] = html_entity_decode( trim( $m[1] ), ENT_QUOTES, 'UTF-8' );
		}
		if ( preg_match( '/<meta[^>]+content=["\']([^"\']*)["\'][^>]+name=["\']description["\'][^>]*>/si', $html, $m ) ) {
			$meta['meta_description'] = ! empty( $meta['meta_description'] ) ? $meta['meta_description'] : html_entity_decode( trim( $m[1] ), ENT_QUOTES, 'UTF-8' );
		}
		if ( preg_match( '/<meta[^>]+name=["\']robots["\'][^>]+content=["\']([^"\']*)["\'][^>]*>/si', $html, $m ) ) {
			$meta['robots'] = trim( $m[1] );
		}

		// Canonical.
		if ( preg_match( '/<link[^>]+rel=["\']canonical["\'][^>]+href=["\']([^"\']*)["\'][^>]*>/si', $html, $m ) ) {
			$meta['canonical'] = trim( $m[1] );
		}

		// Open Graph tags.
		$og_map = array(
			'og:title'       => 'og_title',
			'og:description' => 'og_description',
			'og:image'       => 'og_image',
			'og:type'        => 'og_type',
		);
		foreach ( $og_map as $property => $key ) {
			if ( preg_match( '/<meta[^>]+property=["\']' . preg_quote( $property, '/' ) . '["\'][^>]+content=["\']([^"\']*)["\'][^>]*>/si', $html, $m ) ) {
				$meta[ $key ] = html_entity_decode( trim( $m[1] ), ENT_QUOTES, 'UTF-8' );
			}
		}

		// JSON-LD schema types.
		$meta['schema_warnings'] = array();
		if ( preg_match_all( '/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/si', $html, $matches ) ) {
			foreach ( $matches[1] as $json_str ) {
				$data = json_decode( trim( $json_str ), true );
				if ( ! $data ) {
					$snippet                   = mb_substr( trim( $json_str ), 0, 80 );
					$meta['schema_warnings'][] = 'Malformed JSON-LD block (parse error): ' . $snippet . ( mb_strlen( trim( $json_str ) ) > 80 ? '…' : '' );
					continue;
				}
				if ( isset( $data['@graph'] ) && is_array( $data['@graph'] ) ) {
					foreach ( $data['@graph'] as $item ) {
						$types                = (array) ( $item['@type'] ?? array() );
						$meta['schema_types'] = array_merge( $meta['schema_types'], $types );
					}
				} else {
					$types                = (array) ( $data['@type'] ?? array() );
					$meta['schema_types'] = array_merge( $meta['schema_types'], $types );
				}
			}
			$meta['schema_types'] = array_values( array_unique( array_filter( $meta['schema_types'] ) ) );
		}

		// Heading counts.
		if ( preg_match_all( '/<h1[^>]*>(.*?)<\/h1>/si', $html, $m ) ) {
			$meta['h1'] = array_map( fn( $h ) => trim( wp_strip_all_tags( $h ) ), $m[1] );
		}
		if ( preg_match_all( '/<h2[^>]*>(.*?)<\/h2>/si', $html, $m ) ) {
			$meta['h2'] = array_map( fn( $h ) => trim( wp_strip_all_tags( $h ) ), $m[1] );
		}

		// Counts.
		$meta['link_count']  = preg_match_all( '/<a\s/i', $html );
		$meta['image_count'] = preg_match_all( '/<img\s/i', $html );

		// Word count from visible text (strip tags, collapse whitespace).
		$visible            = wp_strip_all_tags( $html );
		$visible            = preg_replace( '/\s+/', ' ', $visible );
		$meta['word_count'] = str_word_count( trim( $visible ) );

		return $meta;
	}

	/**
	 * Extract semantic HTML sections.
	 *
	 * Parses the rendered HTML into logical sections that agents can analyze
	 * independently: head metadata, site header/nav, main content, sidebar,
	 * and footer.
	 *
	 * @param string $html Full HTML.
	 * @return array{head: string, header: string, nav: string, main: string, footer: string, aside: string}
	 */
	private static function extract_sections( string $html ): array {
		$sections = array(
			'head'   => '',
			'header' => '',
			'nav'    => '',
			'main'   => '',
			'footer' => '',
			'aside'  => '',
		);

		// <head> content.
		if ( preg_match( '/<head[^>]*>(.*?)<\/head>/si', $html, $m ) ) {
			$sections['head'] = trim( $m[1] );
		}

		// <header> — typically site header with logo, nav, etc.
		if ( preg_match( '/<header[^>]*>(.*?)<\/header>/si', $html, $m ) ) {
			$sections['header'] = trim( strip_tags( $m[1], '<a><img><nav><ul><li><span>' ) );
		}

		// <nav> — primary navigation (first one found, or within header).
		if ( preg_match( '/<nav[^>]*>(.*?)<\/nav>/si', $html, $m ) ) {
			$sections['nav'] = trim( strip_tags( $m[1], '<a><ul><li><span>' ) );
		}

		// <main> or <div id="content"> or <article> — the primary content area.
		if ( preg_match( '/<main[^>]*>(.*?)<\/main>/si', $html, $m ) ) {
			$sections['main'] = trim( $m[1] );
		} elseif ( preg_match( '/<article[^>]*>(.*?)<\/article>/si', $html, $m ) ) {
			$sections['main'] = trim( $m[1] );
		} elseif ( preg_match( '/<div[^>]+id=["\']content["\'][^>]*>(.*?)<\/div>/si', $html, $m ) ) {
			$sections['main'] = trim( $m[1] );
		}

		// <footer> — site footer.
		if ( preg_match( '/<footer[^>]*>(.*?)<\/footer>/si', $html, $m ) ) {
			$sections['footer'] = trim( strip_tags( $m[1], '<a><ul><li><span><p>' ) );
		}

		// <aside> — sidebar widgets.
		if ( preg_match( '/<aside[^>]*>(.*?)<\/aside>/si', $html, $m ) ) {
			$sections['aside'] = trim( strip_tags( $m[1], '<a><ul><li><span><p><h3><h4>' ) );
		}

		return $sections;
	}

	/**
	 * Strip <script> and <style> tags from HTML.
	 *
	 * Produces clean HTML suitable for LLM consumption — keeps structural
	 * markup, text content, images, and links but removes JavaScript and CSS.
	 * Also strips HTML comments and collapses excessive whitespace.
	 *
	 * @param string $html Raw HTML.
	 * @return string Cleaned HTML.
	 */
	private static function strip_assets( string $html ): string {
		// Remove <script> tags and contents.
		$html = preg_replace( '/<script[^>]*>.*?<\/script>/si', '', $html );

		// Remove <style> tags and contents.
		$html = preg_replace( '/<style[^>]*>.*?<\/style>/si', '', $html );

		// Remove HTML comments (but keep IE conditionals for debugging).
		$html = preg_replace( '/<!--(?!\[if).*?-->/s', '', $html );

		// Remove inline event handlers (onclick, onload, etc.).
		$html = preg_replace( '/\s+on\w+="[^"]*"/i', '', $html );
		$html = preg_replace( "/\s+on\w+='[^']*'/i", '', $html );

		// Collapse excessive whitespace.
		$html = preg_replace( '/\n{3,}/', "\n\n", $html );
		$html = preg_replace( '/[ \t]{2,}/', ' ', $html );

		return trim( $html );
	}

	/**
	 * Extract relevant HTTP response headers.
	 *
	 * @param array|\WP_HTTP_Requests_Response $response wp_remote_get response.
	 * @return array Selected headers.
	 */
	private static function extract_response_headers( $response ): array {
		$headers = wp_remote_retrieve_headers( $response );
		$result  = array();

		$interesting = array(
			'content-type',
			'x-robots-tag',
			'x-frame-options',
			'content-security-policy',
			'cache-control',
			'last-modified',
			'link',
			'server',
			'cf-ray',
			'cf-cache-status',
		);

		if ( $headers instanceof \Requests_Utility_CaseInsensitiveDictionary || is_array( $headers ) ) {
			foreach ( $interesting as $name ) {
				$value = is_array( $headers ) ? ( $headers[ $name ] ?? '' ) : ( $headers[ $name ] ?? '' );
				if ( ! empty( $value ) ) {
					$result[ $name ] = $value;
				}
			}
		}

		return $result;
	}

	/**
	 * Build a standardised error response.
	 *
	 * @param string $url    The requested URL.
	 * @param string $error  Error message.
	 * @param int    $status HTTP status code (0 if no HTTP response).
	 * @return array
	 */
	private static function error_response( string $url, string $error, int $status = 0 ): array {
		return array(
			'success'   => false,
			'url'       => $url,
			'status'    => $status,
			'headers'   => array(),
			'html'      => '',
			'html_size' => 0,
			'sections'  => array(),
			'meta'      => array(),
			'cached'    => false,
			'error'     => $error,
		);
	}
}
