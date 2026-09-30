<?php
/**
 * Tool: Check AI Headers
 *
 * Inspect HTTP headers and meta tags for AI-specific directives.
 *
 * @package Agentic\Tools
 */

declare(strict_types=1);

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checks response headers and HTML meta tags for AI-blocking directives.
 */
class Check_AI_Headers extends \Agentic\Tool_Base {

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'check_ai_headers';
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return "Inspect a page's HTTP response headers and HTML meta tags for AI-specific directives: X-Robots-Tag, meta robots, Cloudflare bot-fight headers, and cache headers.";
	}

	/**
	 * Get the tool category.
	 *
	 * @return string
	 */
	public function get_category(): string {
		return 'ai-visibility';
	}

	/**
	 * Get the tool parameters.
	 *
	 * @return array
	 */
	public function get_parameters(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'url' => array(
					'type'        => 'string',
					'description' => 'The URL to inspect. Defaults to the site homepage.',
				),
			),
		);
	}

	/**
	 * Execute the AI headers check.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array Header analysis findings with issues and passing checks.
	 */
	public function execute( array $arguments ): array {
		$url      = $arguments['url'] ?? home_url( '/' );
		$rendered = \Agentic\Page_Renderer::fetch( $url, array( 'strip_assets' => false ) );

		if ( ! $rendered['success'] ) {
			return array(
				'error' => $rendered['error'] ?? 'Could not fetch URL.',
				'url'   => $url,
			);
		}

		$headers  = $rendered['headers'];
		$body     = $rendered['html'];
		$findings = array();
		$issues   = array();
		$passing  = array();

		// X-Robots-Tag header.
		$x_robots = $headers['x-robots-tag'] ?? '';
		if ( ! empty( $x_robots ) ) {
			$x_robots_str             = is_array( $x_robots ) ? implode( ', ', $x_robots ) : $x_robots;
			$findings['x_robots_tag'] = $x_robots_str;
			$found_blocks             = array();
			foreach ( array( 'noai', 'noimageai', 'noindex', 'none' ) as $directive ) {
				if ( stripos( $x_robots_str, $directive ) !== false ) {
					$found_blocks[] = $directive;
				}
			}
			if ( ! empty( $found_blocks ) ) {
				$issues[] = array(
					'message'  => 'X-Robots-Tag contains AI-blocking directives: ' . implode( ', ', $found_blocks ),
					'severity' => 'critical',
				);
			} else {
				$passing[] = 'X-Robots-Tag present but no AI-blocking directives';
			}
		} else {
			$findings['x_robots_tag'] = null;
			$passing[]                = 'No X-Robots-Tag header';
		}

		// Meta robots tags.
		$meta_robots = array();
		if ( preg_match_all( '/<meta\s+name=["\']robots["\']\s+content=["\']([^"\']+)["\']/i', $body, $matches ) ) {
			$meta_robots = $matches[1];
		}
		if ( preg_match_all( '/<meta\s+name=["\'](googlebot|bingbot|GPTBot|ChatGPT-User|ClaudeBot|anthropic-ai|PerplexityBot)["\']\s+content=["\']([^"\']+)["\']/i', $body, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$meta_robots[] = $match[1] . ': ' . $match[2];
			}
		}
		$findings['meta_robots'] = $meta_robots;
		if ( ! empty( $meta_robots ) ) {
			$has_ai_block = false;
			foreach ( $meta_robots as $content ) {
				if ( preg_match( '/\b(noai|noimageai|noindex|none)\b/i', $content ) ) {
					$has_ai_block = true;
					$issues[]     = array(
						'message'  => 'Meta robots tag contains AI-blocking directive: ' . $content,
						'severity' => 'critical',
					);
				}
			}
			if ( ! $has_ai_block ) {
				$passing[] = 'Meta robots tags present but no AI-blocking directives';
			}
		} else {
			$passing[] = 'No restrictive meta robots tags found';
		}

		// Cloudflare indicators.
		$cf_indicators = array();
		if ( $headers['cf-ray'] ?? null ) {
			$cf_indicators[] = 'Cloudflare detected (cf-ray header)';
		}
		if ( stripos( $headers['server'] ?? '', 'cloudflare' ) !== false ) {
			$cf_indicators[] = 'Server: cloudflare';
		}
		if ( preg_match( '/cf-chl-bypass|challenge-platform|__cf_bm/i', $body ) ) {
			$cf_indicators[] = 'Cloudflare Bot Fight Mode markers in HTML';
			$issues[]        = array(
				'message'  => 'Cloudflare Bot Fight Mode may be active — can block AI crawlers even when robots.txt allows them.',
				'severity' => 'important',
			);
		}
		$findings['cloudflare'] = $cf_indicators;
		if ( ! empty( $cf_indicators ) && empty( array_filter( $issues, fn( $i ) => str_contains( $i['message'], 'Cloudflare' ) ) ) ) {
			$passing[] = 'Cloudflare detected but no Bot Fight Mode markers';
		}

		// Cache-Control header.
		$cache_control             = $headers['cache-control'] ?? '';
		$findings['cache_control'] = $cache_control ? $cache_control : null;
		if ( ! empty( $cache_control ) && stripos( $cache_control, 'no-store' ) !== false ) {
			$issues[] = array(
				'message'  => 'Cache-Control: no-store detected.',
				'severity' => 'low',
			);
		}

		return array(
			'url'      => $url,
			'status'   => $rendered['status'],
			'findings' => $findings,
			'issues'   => $issues,
			'passing'  => $passing,
			'summary'  => empty( $issues )
				? 'No AI-blocking headers or meta tags detected.'
				: count( $issues ) . ' potential issue(s) found.',
		);
	}

	/**
	 * Get the tool annotations.
	 *
	 * @return array
	 */
	public function get_annotations(): array {
		return array(
			'readonly' => true,
		);
	}
}

return new Check_AI_Headers();
