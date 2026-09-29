<?php
/**
 * Tool: Check Brand Signals
 *
 * Check brand consistency: site name presence across pages, social media
 * profile links, branded elements, and social proof signals.
 *
 * @package Agentic\Tools
 */

declare(strict_types=1);

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Check_Brand_Signals extends \Agentic\Tool_Base {

	public function get_name(): string {
		return 'check_brand_signals';
	}

	public function get_description(): string {
		return 'Check brand consistency: site name presence across pages, social media profile links, branded elements, and social proof signals.';
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
		$site_name = get_bloginfo( 'name' );
		$score     = 0;
		$checks    = array();

		// Fetch homepage.
		$home_response = @wp_safe_remote_get(
			home_url(),
			array(
				'timeout'   => 8,
				'sslverify' => false,
			)
		);
		$home_html     = ! is_wp_error( $home_response ) ? wp_remote_retrieve_body( $home_response ) : '';
		$home_lower    = strtolower( $home_html );

		// 1. Site name in title tag (15 pts).
		if ( preg_match( '/<title[^>]*>([^<]+)<\/title>/i', $home_html, $tm ) ) {
			if ( str_contains( strtolower( $tm[1] ), strtolower( $site_name ) ) ) {
				$score   += 15;
				$checks[] = array(
					'check'  => 'Brand in title',
					'status' => 'pass',
					'detail' => "Site name \"{$site_name}\" appears in homepage title.",
				);
			} else {
				$checks[] = array(
					'check'  => 'Brand in title',
					'status' => 'warn',
					'detail' => "Site name \"{$site_name}\" not found in homepage title tag.",
				);
			}
		}

		// 2. Social media links (15 pts).
		$social_domains = array( 'facebook.com', 'twitter.com', 'x.com', 'linkedin.com', 'instagram.com', 'youtube.com', 'tiktok.com' );
		$found_social   = array();
		foreach ( $social_domains as $sd ) {
			if ( str_contains( $home_lower, $sd ) ) {
				$found_social[] = $sd;
			}
		}
		if ( count( $found_social ) >= 3 ) {
			$score   += 15;
			$checks[] = array(
				'check'  => 'Social profiles',
				'status' => 'pass',
				'detail' => count( $found_social ) . ' social links found.',
			);
		} elseif ( ! empty( $found_social ) ) {
			$score   += 8;
			$checks[] = array(
				'check'  => 'Social profiles',
				'status' => 'warn',
				'detail' => count( $found_social ) . ' social link(s) — add more for stronger brand signals.',
			);
		} else {
			$checks[] = array(
				'check'  => 'Social profiles',
				'status' => 'fail',
				'detail' => 'No social media links found on homepage.',
			);
		}

		// 3. Phone number consistency across pages (20 pts).
		$phone_pattern  = '/(?:\+\d{1,3}[\s.-]?)?\(?\d{2,4}\)?[\s.-]?\d{3,4}[\s.-]?\d{3,4}/';
		$phones_found   = array();
		$pages_to_check = array( '', 'contact', 'about', 'about-us' );
		foreach ( $pages_to_check as $slug ) {
			$url  = home_url( "/$slug" );
			$resp = @wp_safe_remote_get(
				$url,
				array(
					'timeout'   => 5,
					'sslverify' => false,
				)
			);
			if ( ! is_wp_error( $resp ) && wp_remote_retrieve_response_code( $resp ) === 200 ) {
				$body = wp_remote_retrieve_body( $resp );
				if ( preg_match_all( $phone_pattern, $body, $pm ) ) {
					foreach ( $pm[0] as $phone ) {
						$cleaned = preg_replace( '/\s+/', '', $phone );
						if ( strlen( $cleaned ) >= 7 ) {
							$phones_found[ $slug ? $slug : 'home' ][] = $cleaned;
						}
					}
				}
			}
		}
		$all_phones = array();
		foreach ( $phones_found as $page_phones ) {
			$all_phones = array_merge( $all_phones, $page_phones );
		}
		$unique_phones = array_unique( $all_phones );
		if ( count( $unique_phones ) === 1 ) {
			$score   += 20;
			$checks[] = array(
				'check'  => 'Phone consistency',
				'status' => 'pass',
				'detail' => 'Consistent phone number across pages.',
			);
		} elseif ( count( $unique_phones ) > 1 ) {
			$score   += 10;
			$checks[] = array(
				'check'  => 'Phone consistency',
				'status' => 'warn',
				'detail' => count( $unique_phones ) . ' different phone numbers found — ensure NAP consistency.',
			);
		} elseif ( empty( $unique_phones ) ) {
			$checks[] = array(
				'check'  => 'Phone consistency',
				'status' => 'warn',
				'detail' => 'No phone numbers detected. Adding a phone number improves trust and local SEO.',
			);
		}

		// 4. Trust signals on homepage (20 pts).
		$trust = 0;
		if ( str_contains( $home_lower, 'testimonial' ) || str_contains( $home_lower, 'what our' ) ) {
			++$trust;
		}
		if ( preg_match( '/★|⭐|star|review/i', $home_lower ) ) {
			++$trust;
		}
		if ( preg_match( '/certified|award|partner|accredited/i', $home_lower ) ) {
			++$trust;
		}
		if ( preg_match( '/guarantee|money.back|satisfaction/i', $home_lower ) ) {
			++$trust;
		}
		if ( $trust >= 3 ) {
			$score   += 20;
			$checks[] = array(
				'check'  => 'Trust signals',
				'status' => 'pass',
				'detail' => "{$trust} trust signal types found.",
			);
		} elseif ( $trust >= 1 ) {
			$score   += 10;
			$checks[] = array(
				'check'  => 'Trust signals',
				'status' => 'warn',
				'detail' => "Only {$trust} trust signal type(s). Add testimonials, reviews, certifications, or guarantees.",
			);
		} else {
			$checks[] = array(
				'check'  => 'Trust signals',
				'status' => 'fail',
				'detail' => 'No trust signals found on homepage. This significantly impacts conversion.',
			);
		}

		// 5. Favicon / logo (15 pts).
		$has_favicon = ! empty( get_site_icon_url() );
		$has_logo    = (bool) preg_match( '/class="[^"]*custom-logo|id="[^"]*logo/i', $home_html );
		if ( $has_favicon && $has_logo ) {
			$score   += 15;
			$checks[] = array(
				'check'  => 'Visual branding',
				'status' => 'pass',
				'detail' => 'Favicon and logo detected.',
			);
		} elseif ( $has_favicon || $has_logo ) {
			$score   += 8;
			$missing  = ! $has_favicon ? 'favicon' : 'logo';
			$checks[] = array(
				'check'  => 'Visual branding',
				'status' => 'warn',
				'detail' => "Missing {$missing}. Both favicon and logo reinforce brand recognition.",
			);
		} else {
			$checks[] = array(
				'check'  => 'Visual branding',
				'status' => 'fail',
				'detail' => 'No favicon or logo detected.',
			);
		}

		// 6. Tagline (15 pts).
		$tagline = get_bloginfo( 'description' );
		if ( ! empty( $tagline ) && 'Just another WordPress site' !== $tagline ) {
			$score   += 15;
			$checks[] = array(
				'check'  => 'Tagline',
				'status' => 'pass',
				'detail' => "Custom tagline set: \"{$tagline}\"",
			);
		} else {
			$checks[] = array(
				'check'  => 'Tagline',
				'status' => 'warn',
				'detail' => 'Default or empty tagline. Set a descriptive tagline in Settings → General.',
			);
		}

		return array(
			'brand_score' => min( 100, $score ),
			'max_score'   => 100,
			'site_name'   => $site_name,
			'checks'      => $checks,
		);
	}
}
return new Check_Brand_Signals();
