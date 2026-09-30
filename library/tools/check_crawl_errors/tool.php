<?php
/**
 * Tool: Check Crawl Errors
 *
 * Scan sitemap URLs for non-200 status codes (404s, redirects, server errors).
 * Checks for redirect chains. Limited to prevent rate limiting.
 *
 * @package Agentic\Tools
 */

declare(strict_types=1);

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Check_Crawl_Errors extends \Agentic\Tool_Base {

	public function get_name(): string {
		return 'check_crawl_errors';
	}

	public function get_description(): string {
		return 'Scan sitemap URLs for non-200 status codes (404s, redirects, server errors). Checks for redirect chains. Limited to prevent rate limiting.';
	}

	public function get_category(): string {
		return 'site-audit';
	}

	public function get_parameters(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'max_urls' => array(
					'type'        => 'integer',
					'description' => 'Maximum number of URLs to check. Capped at 100.',
					'default'     => 50,
				),
			),
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
		$max_urls = min( 100, (int) ( $args['max_urls'] ?? 50 ) );

		// Get URLs from sitemap.
		$sitemap_urls = $this->get_sitemap_urls( $max_urls );
		if ( empty( $sitemap_urls ) ) {
			// Fall back to published post URLs.
			$posts        = get_posts(
				array(
					'post_type'      => array( 'post', 'page' ),
					'post_status'    => 'publish',
					'posts_per_page' => $max_urls,
				)
			);
			$sitemap_urls = array_map( fn( $p ) => get_permalink( $p->ID ), $posts );
		}

		$errors    = array();
		$redirects = array();
		$ok_count  = 0;

		foreach ( $sitemap_urls as $url ) {
			$response = @wp_safe_remote_head(
				$url,
				array(
					'timeout'     => 5,
					'sslverify'   => false,
					'redirection' => 0,
				)
			);

			if ( is_wp_error( $response ) ) {
				$errors[] = array(
					'url'    => $url,
					'status' => 'error',
					'detail' => $response->get_error_message(),
				);
				continue;
			}

			$code = wp_remote_retrieve_response_code( $response );
			if ( 200 === $code ) {
				++$ok_count;
			} elseif ( $code >= 300 && $code < 400 ) {
				$location    = wp_remote_retrieve_header( $response, 'location' );
				$redirects[] = array(
					'url'          => $url,
					'status'       => $code,
					'redirects_to' => $location,
				);
			} else {
				$errors[] = array(
					'url'    => $url,
					'status' => $code,
					'detail' => "HTTP {$code}",
				);
			}
		}

		// Check for redirect chains (double redirects).
		$chains = array();
		foreach ( $redirects as $r ) {
			if ( ! empty( $r['redirects_to'] ) ) {
				$chain_resp = @wp_safe_remote_head(
					$r['redirects_to'],
					array(
						'timeout'     => 5,
						'sslverify'   => false,
						'redirection' => 0,
					)
				);
				if ( ! is_wp_error( $chain_resp ) ) {
					$chain_code = wp_remote_retrieve_response_code( $chain_resp );
					if ( $chain_code >= 300 && $chain_code < 400 ) {
						$chains[] = array(
							'url'            => $r['url'],
							'via'            => $r['redirects_to'],
							'final_redirect' => wp_remote_retrieve_header( $chain_resp, 'location' ),
						);
					}
				}
			}
		}

		return array(
			'urls_checked'    => count( $sitemap_urls ),
			'ok_count'        => $ok_count,
			'error_count'     => count( $errors ),
			'redirect_count'  => count( $redirects ),
			'errors'          => array_slice( $errors, 0, 20 ),
			'redirects'       => array_slice( $redirects, 0, 20 ),
			'redirect_chains' => $chains,
		);
	}

	/**
	 * Extract URLs from sitemap.xml.
	 */
	private function get_sitemap_urls( int $limit ): array {
		$urls     = array();
		$attempts = array( home_url( '/sitemap.xml' ), home_url( '/sitemap_index.xml' ), home_url( '/wp-sitemap.xml' ) );

		foreach ( $attempts as $sitemap_url ) {
			$response = @wp_safe_remote_get(
				$sitemap_url,
				array(
					'timeout'   => 8,
					'sslverify' => false,
				)
			);
			if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
				continue;
			}

			$body = wp_remote_retrieve_body( $response );

			// If sitemap index, get first sub-sitemap.
			if ( str_contains( $body, '<sitemapindex' ) ) {
				if ( preg_match( '/<loc>([^<]+)<\/loc>/', $body, $sm ) ) {
					$sub_resp = @wp_safe_remote_get(
						$sm[1],
						array(
							'timeout'   => 8,
							'sslverify' => false,
						)
					);
					if ( ! is_wp_error( $sub_resp ) ) {
						$body = wp_remote_retrieve_body( $sub_resp );
					}
				}
			}

			if ( preg_match_all( '/<loc>([^<]+)<\/loc>/', $body, $matches ) ) {
				$urls = array_slice( $matches[1], 0, $limit );
				break;
			}
		}

		return $urls;
	}
}
return new Check_Crawl_Errors();
