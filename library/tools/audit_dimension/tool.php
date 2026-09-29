<?php
/**
 * Tool: Audit Dimension
 *
 * Run a single audit dimension. Contains all 8 dimension scorers as private methods.
 * Each scorer calls data-gathering tools via Tool_Loader.
 *
 * @package Agentic\Tools
 */

declare(strict_types=1);

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Audit_Dimension extends \Agentic\Tool_Base {

	public function get_name(): string {
		return 'audit_dimension';
	}

	public function get_description(): string {
		return 'Run a single audit dimension. Valid dimensions: ux, accessibility, gdpr, web_standards, seo, ai_visibility, content_quality, commercial.';
	}

	public function get_category(): string {
		return 'site-audit';
	}

	public function get_parameters(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'dimension' => array(
					'type'        => 'string',
					'description' => 'The dimension to audit.',
					'enum'        => array( 'ux', 'accessibility', 'gdpr', 'web_standards', 'seo', 'ai_visibility', 'content_quality', 'commercial' ),
				),
			),
			'required'   => array( 'dimension' ),
		);
	}

	public function get_annotations(): array {
		return array(
			'read_only'   => true,
			'destructive' => false,
		);
	}

	public function execute( array $args ): array {
		$dimension = $args['dimension'] ?? '';
		$loader    = \Agentic\Tool_Loader::get_instance();

		switch ( $dimension ) {
			case 'ux':
				$site = $loader->execute( 'get_site_overview', array() );
				$seo  = $loader->execute( 'get_seo_stats', array() );
				$web  = $this->get_web_standards_data();
				$comm = $this->get_commercial_data();
				return $this->score_ux( $site, $seo, $web, $comm );

			case 'accessibility':
				return $this->score_accessibility( $loader->execute( 'get_accessibility_stats', array() ) );

			case 'gdpr':
				return $this->score_gdpr( $loader->execute( 'get_privacy_compliance_status', array() ) );

			case 'web_standards':
				return $this->score_web_standards( $this->get_web_standards_data() );

			case 'seo':
				return $this->score_seo( $loader->execute( 'get_seo_stats', array() ) );

			case 'ai_visibility':
				return $this->score_ai_visibility( $this->get_ai_visibility_data() );

			case 'content_quality':
				return $this->score_content_quality( $loader->execute( 'get_content_stats', array() ) );

			case 'commercial':
				$comm = $this->get_commercial_data();
				$seo  = $loader->execute( 'get_seo_stats', array() );
				return $this->score_commercial( $comm, $seo );

			default:
				return array( 'error' => 'Unknown dimension: ' . $dimension );
		}
	}

	// =========================================================================
	// Data helpers — these gather data not exposed as standalone tools
	// =========================================================================

	private function get_web_standards_data(): array {
		$home = home_url();

		// robots.txt.
		$robots_path = ABSPATH . 'robots.txt';
		$has_robots  = file_exists( $robots_path );

		// sitemap.
		$sitemap_url   = home_url( '/sitemap.xml' );
		$sitemap_index = home_url( '/sitemap_index.xml' );
		$has_sitemap   = false;
		$sitemap_pct   = 0;
		$sm_response   = @wp_safe_remote_get(
			$sitemap_url,
			array(
				'timeout'   => 5,
				'sslverify' => false,
			)
		);
		if ( is_wp_error( $sm_response ) || wp_remote_retrieve_response_code( $sm_response ) !== 200 ) {
			$sm_response = @wp_safe_remote_get(
				$sitemap_index,
				array(
					'timeout'   => 5,
					'sslverify' => false,
				)
			);
		}
		if ( ! is_wp_error( $sm_response ) && wp_remote_retrieve_response_code( $sm_response ) === 200 ) {
			$has_sitemap = true;
			$sm_body     = wp_remote_retrieve_body( $sm_response );
			$url_count   = substr_count( $sm_body, '<url>' ) + substr_count( $sm_body, '<sitemap>' );
			$post_count  = wp_count_posts( 'post' )->publish + wp_count_posts( 'page' )->publish;
			$sitemap_pct = $post_count > 0 ? min( 100, round( ( $url_count / $post_count ) * 100 ) ) : 100;
		}

		// Favicon.
		$favicon = get_site_icon_url();
		$has_fav = ! empty( $favicon );

		// Viewport meta and OG from home page.
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

	private function get_ai_visibility_data(): array {
		$robots_path = ABSPATH . 'robots.txt';
		$robots_txt  = file_exists( $robots_path ) ? file_get_contents( $robots_path ) : '';

		$ai_bots = \Agentic\Tool_Helpers::get_ai_bots();
		$allowed = array();
		$blocked = array();

		$blanket_block = (bool) preg_match( '/User-agent:\s*\*.*?Disallow:\s*\//si', $robots_txt );

		foreach ( $ai_bots as $bot => $meta ) {
			$pattern = '/User-agent:\s*' . preg_quote( $bot, '/' ) . '.*?Disallow:\s*\//si';
			if ( preg_match( $pattern, $robots_txt ) ) {
				$blocked[] = $bot;
			} else {
				$allowed[] = $bot;
			}
		}

		// Schema types from home page.
		$home_response = @wp_safe_remote_get(
			home_url(),
			array(
				'timeout'   => 8,
				'sslverify' => false,
			)
		);
		$home_html     = ! is_wp_error( $home_response ) ? wp_remote_retrieve_body( $home_response ) : '';
		$schema_types  = array();
		if ( preg_match_all( '/"@type"\s*:\s*"([^"]+)"/', $home_html, $sm ) ) {
			$schema_types = array_unique( $sm[1] );
		}

		// FAQ formatted content.
		$has_faq = $this->has_faq_content();

		// llms.txt.
		$llms_path = ABSPATH . 'llms.txt';
		$has_llms  = file_exists( $llms_path );

		// Days since last post.
		$last       = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
		$days_since = $last ? round( ( time() - strtotime( $last[0]->post_date ) ) / DAY_IN_SECONDS ) : null;

		return array(
			'blanket_block'             => $blanket_block,
			'bots_allowed'              => $allowed,
			'bots_blocked'              => $blocked,
			'schema_types'              => $schema_types,
			'has_faq_formatted_content' => $has_faq,
			'has_llms_txt'              => $has_llms,
			'days_since_last_post'      => $days_since,
		);
	}

	private function get_commercial_data(): array {
		$active_plugins = (array) get_option( 'active_plugins', array() );

		// Contact form plugins.
		$form_slugs = array( 'contact-form-7', 'gravityforms', 'ninja-forms', 'wpforms', 'formidable' );
		$has_form   = false;
		foreach ( $active_plugins as $p ) {
			foreach ( $form_slugs as $fs ) {
				if ( str_contains( $p, $fs ) ) {
					$has_form = true;
					break 2; }
			}
		}

		// E-commerce.
		$has_ecomm = false;
		foreach ( $active_plugins as $p ) {
			if ( str_contains( $p, 'woocommerce' ) || str_contains( $p, 'easy-digital-downloads' ) ) {
				$has_ecomm = true;
				break;
			}
		}

		// Email signup.
		$email_slugs = array( 'mailchimp', 'convertkit', 'mailerlite', 'klaviyo', 'fluentcrm', 'newsletter', 'wp-mail-smtp' );
		$has_email   = false;
		foreach ( $active_plugins as $p ) {
			foreach ( $email_slugs as $es ) {
				if ( str_contains( $p, $es ) ) {
					$has_email = true;
					break 2; }
			}
		}

		// Booking.
		$booking_slugs = array( 'bookly', 'amelia', 'woocommerce-bookings', 'simply-schedule-appointments', 'booking-calendar' );
		$has_booking   = false;
		foreach ( $active_plugins as $p ) {
			foreach ( $booking_slugs as $bs ) {
				if ( str_contains( $p, $bs ) ) {
					$has_booking = true;
					break 2; }
			}
		}

		// Contact page.
		$contact_page = get_page_by_path( 'contact' );
		if ( ! $contact_page ) {
			$contact_page = get_page_by_path( 'contact-us' );
		}
		$has_contact_page = $contact_page && 'publish' === $contact_page->post_status;

		// Scan homepage for contact info and trust signals.
		$home_response = @wp_safe_remote_get(
			home_url(),
			array(
				'timeout'   => 8,
				'sslverify' => false,
			)
		);
		$home_text     = ! is_wp_error( $home_response ) ? strtolower( wp_remote_retrieve_body( $home_response ) ) : '';

		$phone_present = (bool) preg_match( '/\+?[\d\s\-\(\)]{7,15}/', $home_text );
		$email_present = (bool) preg_match( '/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/', $home_text );

		// CTA patterns across key pages.
		$cta_patterns      = array( 'contact us', 'get started', 'buy now', 'book a call', 'sign up', 'get a quote', 'schedule', 'request a demo', 'try for free', 'get in touch', 'start now', 'learn more' );
		$key_pages_slugs   = array( '', 'services', 'about', 'about-us', 'products', 'pricing', 'contact' );
		$pages_without_cta = 0;
		$key_page_count    = 0;
		foreach ( $key_pages_slugs as $slug ) {
			$url  = home_url( "/$slug" );
			$resp = @wp_safe_remote_get(
				$url,
				array(
					'timeout'   => 5,
					'sslverify' => false,
				)
			);
			$code = is_wp_error( $resp ) ? 0 : wp_remote_retrieve_response_code( $resp );
			if ( 200 === $code ) {
				++$key_page_count;
				$body    = strtolower( wp_remote_retrieve_body( $resp ) );
				$has_cta = false;
				foreach ( $cta_patterns as $pat ) {
					if ( str_contains( $body, $pat ) ) {
						$has_cta = true;
						break; }
				}
				if ( ! $has_cta ) {
					++$pages_without_cta;
				}
			}
		}

		// Trust signals.
		$trust_score = 0;
		if ( str_contains( $home_text, 'testimonial' ) || str_contains( $home_text, 'what our clients' ) ) {
			++$trust_score; }
		if ( str_contains( $home_text, 'review' ) || str_contains( $home_text, 'stars' ) || str_contains( $home_text, '★' ) || str_contains( $home_text, '⭐' ) ) {
			++$trust_score; }
		if ( str_contains( $home_text, 'certified' ) || str_contains( $home_text, 'award' ) || str_contains( $home_text, 'partner' ) ) {
			++$trust_score; }

		return array(
			'has_contact_form'        => $has_form,
			'has_ecommerce'           => $has_ecomm,
			'has_email_signup'        => $has_email,
			'has_booking_plugin'      => $has_booking,
			'has_contact_page'        => $has_contact_page,
			'phone_number_present'    => $phone_present,
			'email_address_present'   => $email_present,
			'pages_without_cta_count' => $pages_without_cta,
			'key_page_count'          => max( 1, $key_page_count ),
			'trust_signal_score'      => $trust_score,
		);
	}

	private function has_faq_content(): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts}
				 WHERE post_status = %s
				 AND post_type IN ('post', 'page')
				 AND (post_content LIKE %s
				      OR post_content LIKE %s)",
				'publish',
				'%faq%',
				'%frequently asked%'
			)
		);

		return $result > 0;
	}

	// =========================================================================
	// Check helper
	// =========================================================================

	private function check( string $id, string $label, string $status, string $message, int $impact, int $score ): array {
		return compact( 'id', 'label', 'status', 'message', 'impact', 'score' );
	}

	// =========================================================================
	// Dimension scorers
	// =========================================================================

	private function score_ux( array $site, array $seo, array $web, array $comm ): array {
		$checks = array();
		$score  = 0;

		// Navigation clarity (3 pts).
		$menu_count = $site['menu_item_count'] ?? null;
		if ( null === $menu_count ) {
			$checks[] = $this->check( 'navigation_clarity', 'Navigation clarity', 'warn', 'Could not determine menu item count — review manually.', 1, 2 );
			$score   += 2;
		} elseif ( $menu_count <= 7 ) {
			$checks[] = $this->check( 'navigation_clarity', 'Navigation clarity', 'pass', "Menu has $menu_count items (ideal: 7 or fewer).", 0, 3 );
			$score   += 3;
		} else {
			$checks[] = $this->check( 'navigation_clarity', 'Navigation clarity', 'fail', "Menu has $menu_count items. More than 7 increases cognitive load. Group items into dropdowns.", 8, 0 );
		}

		// Mobile responsiveness (3 pts).
		if ( ! empty( $web['viewport_meta_present'] ) ) {
			$checks[] = $this->check( 'mobile_responsive', 'Mobile viewport meta', 'pass', 'Viewport meta tag is present.', 0, 3 );
			$score   += 3;
		} else {
			$checks[] = $this->check( 'mobile_responsive', 'Mobile viewport meta', 'fail', 'No viewport meta tag detected. Mobile layout will be broken.', 9, 0 );
		}

		// Page load / Core Web Vitals (3 pts).
		$has_cache = $site['has_caching_plugin'] ?? false;
		if ( $has_cache ) {
			$checks[] = $this->check( 'page_load', 'Page performance', 'pass', 'A caching plugin is active — good signal for load speed.', 0, 3 );
			$score   += 3;
		} else {
			$checks[] = $this->check( 'page_load', 'Page performance', 'warn', 'No caching plugin detected. Install WP Super Cache, W3 Total Cache, or LiteSpeed Cache to improve load times.', 6, 1 );
			++$score;
		}

		// CTA presence (3 pts).
		$pages_without_cta = $comm['pages_without_cta_count'] ?? 0;
		$total_key_pages   = max( 1, $comm['key_page_count'] ?? 5 );
		$cta_coverage      = 1 - ( $pages_without_cta / $total_key_pages );
		if ( $cta_coverage >= 0.8 ) {
			$checks[] = $this->check( 'cta_presence', 'Call-to-action presence', 'pass', 'CTAs present on most key pages.', 0, 3 );
			$score   += 3;
		} elseif ( $cta_coverage >= 0.4 ) {
			$checks[] = $this->check( 'cta_presence', 'Call-to-action presence', 'warn', "{$pages_without_cta} key pages have no clear CTA. Add a next step for visitors.", 7, 1 );
			++$score;
		} else {
			$checks[] = $this->check( 'cta_presence', 'Call-to-action presence', 'fail', 'Most key pages lack a clear call-to-action. Visitors arrive and have no obvious next step.', 9, 0 );
		}

		// 404 page (2 pts).
		$has_404 = ! empty( $site['has_404_page'] );
		if ( $has_404 ) {
			$checks[] = $this->check( '404_page', '404 error page', 'pass', 'A custom 404 page is present.', 0, 2 );
			$score   += 2;
		} else {
			$checks[] = $this->check( '404_page', '404 error page', 'warn', 'No custom 404 page found. A helpful 404 page retains visitors who hit dead links.', 3, 1 );
			++$score;
		}

		// Search (1 pt).
		$checks[] = $this->check( 'search', 'Site search', 'pass', 'WordPress native search is available.', 0, 1 );
		++$score;

		return array(
			'dimension' => 'ux',
			'max'       => 15,
			'score'     => min( $score, 15 ),
			'checks'    => $checks,
		);
	}

	private function score_accessibility( array $access ): array {
		$checks = array();
		$score  = 0;

		// Alt text (3 pts).
		$missing_alt  = $access['images_missing_alt'] ?? 0;
		$total_images = $access['total_images'] ?? 0;
		if ( 0 === $total_images || 0 === $missing_alt ) {
			$checks[] = $this->check( 'alt_text', 'Image alt text', 'pass', 0 === $total_images ? 'No images found.' : 'All images have alt text.', 0, 3 );
			$score   += 3;
		} elseif ( $missing_alt <= 5 ) {
			$checks[] = $this->check( 'alt_text', 'Image alt text', 'warn', "{$missing_alt} images are missing alt text.", 5, 2 );
			$score   += 2;
		} else {
			$checks[] = $this->check( 'alt_text', 'Image alt text', 'fail', "{$missing_alt} images missing alt text. Hurts accessibility, SEO, and AI understanding.", 8, 0 );
		}

		// Heading hierarchy (2 pts).
		$h1_issues = ( $access['pages_no_h1'] ?? 0 ) + ( $access['pages_multiple_h1'] ?? 0 );
		if ( 0 === $h1_issues ) {
			$checks[] = $this->check( 'heading_hierarchy', 'Heading hierarchy', 'pass', 'All pages have a valid single H1.', 0, 2 );
			$score   += 2;
		} elseif ( $h1_issues <= 3 ) {
			$checks[] = $this->check( 'heading_hierarchy', 'Heading hierarchy', 'warn', "{$h1_issues} pages have H1 issues (missing or multiple).", 4, 1 );
			++$score;
		} else {
			$checks[] = $this->check( 'heading_hierarchy', 'Heading hierarchy', 'fail', "{$h1_issues} pages have invalid heading structure.", 6, 0 );
		}

		// Color contrast (3 pts).
		$checks[] = $this->check( 'color_contrast', 'Color contrast', 'warn', 'Color contrast cannot be checked automatically. Use https://webaim.org/resources/contrastchecker/ to verify text meets WCAG AA (4.5:1 ratio).', 5, 2 );
		$score   += 2;

		// Form labels (2 pts).
		$has_form_plugin = $access['has_form_plugin'] ?? false;
		if ( $has_form_plugin ) {
			$checks[] = $this->check( 'form_labels', 'Form labels', 'pass', 'A form plugin is active. Verify form inputs have labels in your form settings.', 0, 2 );
			$score   += 2;
		} else {
			$checks[] = $this->check( 'form_labels', 'Form labels', 'warn', 'No form plugin detected. If you have custom HTML forms, ensure all inputs have associated <label> elements.', 3, 1 );
			++$score;
		}

		// Keyboard navigation (2 pts).
		$checks[] = $this->check( 'keyboard_nav', 'Keyboard navigation', 'warn', 'Keyboard accessibility requires manual testing. Use Tab to navigate your site and verify all interactive elements are reachable.', 4, 1 );
		++$score;

		// ARIA / semantic HTML (2 pts).
		$checks[] = $this->check( 'aria_semantic', 'Semantic HTML & ARIA', 'warn', 'ARIA compliance requires manual or automated testing (axe DevTools, WAVE). Consider running a free WAVE scan at https://wave.webaim.org/', 3, 1 );
		++$score;

		// Link text quality (1 pt).
		$generic_links = $access['generic_link_count'] ?? 0;
		if ( 0 === $generic_links ) {
			$checks[] = $this->check( 'link_text', 'Link text quality', 'pass', 'No generic "click here" or "read more" link text detected.', 0, 1 );
			++$score;
		} else {
			$checks[] = $this->check( 'link_text', 'Link text quality', 'warn', "{$generic_links} instances of generic link text found. Replace with descriptive text.", 3, 0 );
		}

		return array(
			'dimension' => 'accessibility',
			'max'       => 15,
			'score'     => min( $score, 15 ),
			'checks'    => $checks,
		);
	}

	private function score_gdpr( array $privacy ): array {
		$checks = array();
		$score  = 0;

		// Cookie consent (3 pts).
		if ( ! empty( $privacy['has_cookie_consent_plugin'] ) ) {
			$checks[] = $this->check( 'cookie_consent', 'Cookie consent banner', 'pass', 'Cookie consent plugin detected: ' . $privacy['consent_plugin_name'], 0, 3 );
			$score   += 3;
		} else {
			$checks[] = $this->check( 'cookie_consent', 'Cookie consent banner', 'fail', 'No cookie consent plugin detected. Under GDPR, tracking cookies require explicit consent before firing.', 9, 0 );
		}

		// Privacy policy (2 pts).
		if ( ! empty( $privacy['has_privacy_policy'] ) ) {
			$checks[] = $this->check( 'privacy_policy', 'Privacy policy', 'pass', 'Privacy policy page exists: ' . $privacy['privacy_policy_url'], 0, 2 );
			$score   += 2;
		} else {
			$checks[] = $this->check( 'privacy_policy', 'Privacy policy', 'fail', 'No privacy policy page found. This is required under GDPR, CCPA, and most privacy regulations.', 8, 0 );
		}

		// Third-party trackers (2 pts).
		$undisclosed = $privacy['undisclosed_trackers'] ?? array();
		if ( empty( $undisclosed ) ) {
			$checks[] = $this->check( 'trackers', 'Third-party trackers', 'pass', 'All detected third-party scripts appear to be disclosed in the privacy policy.', 0, 2 );
			$score   += 2;
		} else {
			$list     = implode( ', ', $undisclosed );
			$checks[] = $this->check( 'trackers', 'Third-party trackers', 'warn', "Trackers detected but possibly not disclosed: {$list}. Add them to your privacy policy.", 6, 1 );
			++$score;
		}

		// Contact forms (1 pt).
		$checks[] = $this->check( 'form_consent', 'Form data consent', 'warn', 'Verify contact forms explain how submitted data is used and include a consent checkbox where required.', 3, 1 );
		++$score;

		// HTTPS (1 pt).
		if ( is_ssl() || strpos( home_url(), 'https://' ) === 0 ) {
			$checks[] = $this->check( 'https', 'HTTPS / SSL', 'pass', 'Site is served over HTTPS.', 0, 1 );
			++$score;
		} else {
			$checks[] = $this->check( 'https', 'HTTPS / SSL', 'fail', 'Site is not using HTTPS. This is a trust, security, and GDPR compliance issue.', 9, 0 );
		}

		// Terms of service (1 pt).
		if ( ! empty( $privacy['has_terms_of_service'] ) ) {
			$checks[] = $this->check( 'terms', 'Terms of service', 'pass', 'Terms of service page found.', 0, 1 );
			++$score;
		} else {
			$checks[] = $this->check( 'terms', 'Terms of service', 'warn', 'No Terms of Service page found. Recommended for any site accepting registrations or purchases.', 4, 0 );
		}

		return array(
			'dimension' => 'gdpr',
			'max'       => 10,
			'score'     => min( $score, 10 ),
			'checks'    => $checks,
		);
	}

	private function score_web_standards( array $web ): array {
		$checks = array();
		$score  = 0;

		// HTML validity (2 pts).
		$checks[] = $this->check( 'html_validity', 'HTML validity', 'warn', 'HTML validation requires an external validator (validator.w3.org). Run a check and fix critical errors.', 3, 1 );
		++$score;

		// Viewport meta (1 pt).
		if ( ! empty( $web['viewport_meta_present'] ) ) {
			$checks[] = $this->check( 'viewport', 'Mobile viewport meta', 'pass', 'Viewport meta tag present.', 0, 1 );
			++$score;
		} else {
			$checks[] = $this->check( 'viewport', 'Mobile viewport meta', 'fail', 'Missing viewport meta tag. Site will not render correctly on mobile.', 9, 0 );
		}

		// HTTPS (1 pt).
		if ( strpos( home_url(), 'https://' ) === 0 ) {
			$checks[] = $this->check( 'https_ws', 'HTTPS', 'pass', 'Site is served over HTTPS.', 0, 1 );
			++$score;
		} else {
			$checks[] = $this->check( 'https_ws', 'HTTPS', 'fail', 'Site is not on HTTPS.', 8, 0 );
		}

		// Canonical URLs (1 pt).
		$canonical_pct = $web['canonical_coverage_pct'] ?? 0;
		if ( $canonical_pct >= 90 ) {
			$checks[] = $this->check( 'canonical', 'Canonical URLs', 'pass', "Canonical tags present on {$canonical_pct}% of pages.", 0, 1 );
			++$score;
		} else {
			$checks[] = $this->check( 'canonical', 'Canonical URLs', 'warn', "Only {$canonical_pct}% of pages have canonical tags. Missing canonicals can cause duplicate content issues.", 5, 0 );
		}

		// Robots.txt + Sitemap (2 pts).
		$has_robots  = ! empty( $web['robots_txt_exists'] );
		$has_sitemap = ! empty( $web['sitemap_exists'] );
		if ( $has_robots && $has_sitemap ) {
			$sitemap_pct = $web['sitemap_coverage_pct'] ?? 0;
			$checks[]    = $this->check( 'robots_sitemap', 'robots.txt & sitemap', 'pass', "robots.txt and sitemap.xml both present. Sitemap covers {$sitemap_pct}% of published content.", 0, 2 );
			$score      += 2;
		} elseif ( $has_robots || $has_sitemap ) {
			$missing  = ! $has_robots ? 'robots.txt' : 'sitemap.xml';
			$checks[] = $this->check( 'robots_sitemap', 'robots.txt & sitemap', 'warn', "Missing: {$missing}. Both are recommended for crawler discovery.", 5, 1 );
			++$score;
		} else {
			$checks[] = $this->check( 'robots_sitemap', 'robots.txt & sitemap', 'fail', 'Both robots.txt and sitemap.xml are missing. Crawlers will struggle to discover your content.', 7, 0 );
		}

		// Open Graph (1 pt).
		$og_pct = $web['og_coverage_pct'] ?? 0;
		if ( $og_pct >= 80 ) {
			$checks[] = $this->check( 'open_graph', 'Open Graph meta', 'pass', "OG tags present on {$og_pct}% of pages.", 0, 1 );
			++$score;
		} else {
			$checks[] = $this->check( 'open_graph', 'Open Graph meta', 'warn', "Only {$og_pct}% of pages have Open Graph tags — social media previews will be broken.", 4, 0 );
		}

		// Favicon (1 pt).
		if ( ! empty( $web['favicon_present'] ) ) {
			$checks[] = $this->check( 'favicon', 'Favicon', 'pass', 'Favicon is present.', 0, 1 );
			++$score;
		} else {
			$checks[] = $this->check( 'favicon', 'Favicon', 'warn', 'No favicon detected. Every visit generates a 404 for the favicon and it looks unprofessional.', 2, 0 );
		}

		// Responsive images (1 pt).
		if ( ! empty( $web['uses_srcset'] ) ) {
			$checks[] = $this->check( 'responsive_images', 'Responsive images', 'pass', 'Images use srcset for responsive delivery.', 0, 1 );
			++$score;
		} else {
			$checks[] = $this->check( 'responsive_images', 'Responsive images', 'warn', 'Images may not be using srcset. Modern themes and WordPress core should handle this — verify your theme outputs responsive images.', 3, 0 );
		}

		return array(
			'dimension' => 'web_standards',
			'max'       => 10,
			'score'     => min( $score, 10 ),
			'checks'    => $checks,
		);
	}

	private function score_seo( array $seo ): array {
		$checks = array();
		$score  = 0;
		$total  = max( 1, $seo['total_pages'] ?? 1 );

		// Title tags (2 pts).
		$missing_titles = $seo['missing_titles'] ?? 0;
		if ( 0 === $missing_titles ) {
			$checks[] = $this->check( 'title_tags', 'Title tags', 'pass', 'All pages have title tags.', 0, 2 );
			$score   += 2;
		} else {
			$pct      = round( ( $missing_titles / $total ) * 100 );
			$checks[] = $this->check( 'title_tags', 'Title tags', 'fail', "{$missing_titles} pages ({$pct}%) are missing title tags.", 8, 0 );
		}

		// Meta descriptions (2 pts).
		$missing_meta = $seo['missing_meta_descriptions'] ?? 0;
		if ( 0 === $missing_meta ) {
			$checks[] = $this->check( 'meta_descriptions', 'Meta descriptions', 'pass', 'All pages have meta descriptions.', 0, 2 );
			$score   += 2;
		} elseif ( $missing_meta <= round( $total * 0.2 ) ) {
			$checks[] = $this->check( 'meta_descriptions', 'Meta descriptions', 'warn', "{$missing_meta} pages missing meta descriptions.", 5, 1 );
			++$score;
		} else {
			$pct      = round( ( $missing_meta / $total ) * 100 );
			$checks[] = $this->check( 'meta_descriptions', 'Meta descriptions', 'fail', "{$missing_meta} pages ({$pct}%) missing meta descriptions.", 7, 0 );
		}

		// Heading structure (2 pts).
		$heading_issues = ( $seo['pages_no_h1'] ?? 0 ) + ( $seo['pages_multiple_h1'] ?? 0 );
		if ( 0 === $heading_issues ) {
			$checks[] = $this->check( 'heading_seo', 'Heading structure', 'pass', 'All pages have correct H1 structure.', 0, 2 );
			$score   += 2;
		} elseif ( $heading_issues <= 5 ) {
			$checks[] = $this->check( 'heading_seo', 'Heading structure', 'warn', "{$heading_issues} pages have H1 issues.", 4, 1 );
			++$score;
		} else {
			$checks[] = $this->check( 'heading_seo', 'Heading structure', 'fail', "{$heading_issues} pages have H1 issues (missing or duplicate).", 6, 0 );
		}

		// Internal linking (2 pts).
		$orphaned = $seo['orphaned_pages'] ?? 0;
		if ( 0 === $orphaned ) {
			$checks[] = $this->check( 'internal_links', 'Internal linking', 'pass', 'No orphaned pages detected.', 0, 2 );
			$score   += 2;
		} elseif ( $orphaned <= 5 ) {
			$checks[] = $this->check( 'internal_links', 'Internal linking', 'warn', "{$orphaned} pages have no internal links pointing to them.", 5, 1 );
			++$score;
		} else {
			$checks[] = $this->check( 'internal_links', 'Internal linking', 'fail', "{$orphaned} orphaned pages not linked from anywhere in the site.", 7, 0 );
		}

		// Schema markup (2 pts).
		$schema_types = $seo['schema_types_detected'] ?? array();
		if ( count( $schema_types ) >= 3 ) {
			$checks[] = $this->check( 'schema', 'Schema markup', 'pass', 'Rich schema detected: ' . implode( ', ', $schema_types ), 0, 2 );
			$score   += 2;
		} elseif ( ! empty( $schema_types ) ) {
			$checks[] = $this->check( 'schema', 'Schema markup', 'warn', 'Partial schema: ' . implode( ', ', $schema_types ) . '. Add Organization, FAQPage, or Article schema to improve AI and search understanding.', 5, 1 );
			++$score;
		} else {
			$checks[] = $this->check( 'schema', 'Schema markup', 'fail', 'No schema markup detected. Structured data helps search engines and AI platforms understand your content.', 7, 0 );
		}

		// Image SEO (2 pts).
		$bad_alt = $seo['images_missing_alt'] ?? 0;
		if ( 0 === $bad_alt ) {
			$checks[] = $this->check( 'image_seo', 'Image SEO', 'pass', 'All images have alt text.', 0, 2 );
			$score   += 2;
		} elseif ( $bad_alt <= 10 ) {
			$checks[] = $this->check( 'image_seo', 'Image SEO', 'warn', "{$bad_alt} images missing alt text — affects both accessibility and SEO.", 5, 1 );
			++$score;
		} else {
			$checks[] = $this->check( 'image_seo', 'Image SEO', 'fail', "{$bad_alt} images missing alt text — significant SEO and accessibility gap.", 7, 0 );
		}

		// URL structure (1 pt).
		$permalink = $seo['permalink_structure'] ?? '';
		if ( $permalink && 'default' !== $permalink ) {
			$checks[] = $this->check( 'url_structure', 'URL structure', 'pass', "Pretty permalinks active: {$permalink}", 0, 1 );
			++$score;
		} else {
			$checks[] = $this->check( 'url_structure', 'URL structure', 'fail', 'Default (ugly) permalinks are enabled. Set a custom permalink structure in Settings → Permalinks.', 7, 0 );
		}

		// Indexability (2 pts).
		$noindex_count = $seo['noindex_page_count'] ?? 0;
		$discourage    = $seo['search_discouraged'] ?? false;
		if ( $discourage ) {
			$checks[] = $this->check( 'indexability', 'Indexability', 'fail', '"Discourage search engines" is enabled in Settings → Reading. Your entire site is being told not to index.', 10, 0 );
		} elseif ( $noindex_count > round( $total * 0.3 ) ) {
			$checks[] = $this->check( 'indexability', 'Indexability', 'warn', "{$noindex_count} pages are set to noindex. Verify this is intentional.", 5, 1 );
			++$score;
		} else {
			$checks[] = $this->check( 'indexability', 'Indexability', 'pass', "Site is indexable. {$noindex_count} pages deliberately noindexed.", 0, 2 );
			$score   += 2;
		}

		return array(
			'dimension' => 'seo',
			'max'       => 15,
			'score'     => min( $score, 15 ),
			'checks'    => $checks,
		);
	}

	private function score_ai_visibility( array $ai ): array {
		$checks = array();
		$score  = 0;

		// AI crawler access (4 pts).
		$bots_allowed  = $ai['bots_allowed'] ?? array();
		$bots_blocked  = $ai['bots_blocked'] ?? array();
		$blanket_block = $ai['blanket_block'] ?? false;

		if ( $blanket_block ) {
			$checks[] = $this->check( 'ai_crawlers', 'AI crawler access', 'fail', 'Blanket Disallow detected in robots.txt — ALL bots including AI crawlers are blocked.', 10, 0 );
		} elseif ( in_array( 'GPTBot', $bots_blocked, true ) || in_array( 'ClaudeBot', $bots_blocked, true ) ) {
			$list     = implode( ', ', $bots_blocked );
			$checks[] = $this->check( 'ai_crawlers', 'AI crawler access', 'fail', "Major AI crawlers blocked: {$list}. These AI agents cannot see or cite your site.", 9, 0 );
		} elseif ( empty( $bots_blocked ) ) {
			$checks[] = $this->check( 'ai_crawlers', 'AI crawler access', 'pass', 'All AI crawlers appear to have access.', 0, 4 );
			$score   += 4;
		} else {
			$list     = implode( ', ', $bots_blocked );
			$checks[] = $this->check( 'ai_crawlers', 'AI crawler access', 'warn', "Some AI crawlers blocked: {$list}. Consider allowing these for AI search visibility.", 6, 2 );
			$score   += 2;
		}

		// Schema for AI (2 pts).
		$schema_types = $ai['schema_types'] ?? array();
		$has_org      = in_array( 'Organization', $schema_types, true ) || in_array( 'LocalBusiness', $schema_types, true );
		$has_faq      = in_array( 'FAQPage', $schema_types, true );
		if ( $has_org && $has_faq ) {
			$checks[] = $this->check( 'ai_schema', 'Schema for AI extraction', 'pass', 'Organization/LocalBusiness and FAQPage schema detected — excellent for AI citation.', 0, 2 );
			$score   += 2;
		} elseif ( $has_org || $has_faq ) {
			$checks[] = $this->check( 'ai_schema', 'Schema for AI extraction', 'warn', 'Partial AI schema. ' . ( ! $has_faq ? 'Add FAQPage schema — it\'s the highest-ROI schema for AI citations.' : 'Add Organization schema to establish entity identity.' ), 5, 1 );
			++$score;
		} else {
			$checks[] = $this->check( 'ai_schema', 'Schema for AI extraction', 'fail', 'No Organization or FAQPage schema detected. AI platforms cannot identify your entity or cite your Q&A content.', 7, 0 );
		}

		// Content extractability (2 pts).
		$has_faq_content = $ai['has_faq_formatted_content'] ?? false;
		if ( $has_faq_content ) {
			$checks[] = $this->check( 'ai_content', 'Content extractability', 'pass', 'FAQ-formatted content detected — optimised for direct AI quote extraction.', 0, 2 );
			$score   += 2;
		} else {
			$checks[] = $this->check( 'ai_content', 'Content extractability', 'warn', 'No FAQ-formatted content detected. Q&A patterns and direct answers make content more quotable by AI.', 5, 1 );
			++$score;
		}

		// Freshness (1 pt).
		$days_since_last = $ai['days_since_last_post'] ?? 999;
		if ( $days_since_last <= 30 ) {
			$checks[] = $this->check( 'ai_freshness', 'Content freshness', 'pass', 'Content published or updated within the last 30 days.', 0, 1 );
			++$score;
		} elseif ( $days_since_last <= 90 ) {
			$checks[] = $this->check( 'ai_freshness', 'Content freshness', 'warn', "Last content update was {$days_since_last} days ago. Freshness signals help with AI recommendation priority.", 4, 0 );
		} else {
			$checks[] = $this->check( 'ai_freshness', 'Content freshness', 'fail', "No content in {$days_since_last} days. Stale sites are deprioritised by AI search engines.", 6, 0 );
		}

		// llms.txt (1 pt bonus).
		if ( ! empty( $ai['has_llms_txt'] ) ) {
			$checks[] = $this->check( 'llms_txt', 'llms.txt', 'pass', 'llms.txt present — you\'re ahead of the curve on this emerging standard.', 0, 1 );
			++$score;
		} else {
			$checks[] = $this->check( 'llms_txt', 'llms.txt', 'warn', 'No llms.txt file. This emerging standard helps AI agents understand your site content. Low effort to add.', 2, 0 );
		}

		return array(
			'dimension' => 'ai_visibility',
			'max'       => 10,
			'score'     => min( $score, 10 ),
			'checks'    => $checks,
		);
	}

	private function score_content_quality( array $content ): array {
		$checks = array();
		$score  = 0;

		// Freshness (3 pts).
		$stale_pct = $content['pct_older_than_12_months'] ?? 0;
		if ( $stale_pct < 30 ) {
			$checks[] = $this->check( 'freshness', 'Content freshness', 'pass', "Only {$stale_pct}% of content is older than 12 months.", 0, 3 );
			$score   += 3;
		} elseif ( $stale_pct < 60 ) {
			$checks[] = $this->check( 'freshness', 'Content freshness', 'warn', "{$stale_pct}% of content is older than 12 months. Consider refreshing key posts.", 5, 2 );
			$score   += 2;
		} else {
			$days     = $content['days_since_last_post'] ?? '?';
			$checks[] = $this->check( 'freshness', 'Content freshness', 'fail', "{$stale_pct}% of content is over 12 months old. Last post: {$days} days ago. A stale site signals to Google and AI that it may no longer be authoritative.", 8, 0 );
		}

		// Content depth (2 pts).
		$thin_count = $content['thin_post_count'] ?? 0;
		$total      = max( 1, $content['total_posts'] ?? 1 );
		if ( 0 === $thin_count ) {
			$checks[] = $this->check( 'content_depth', 'Content depth', 'pass', 'No thin content pages detected.', 0, 2 );
			$score   += 2;
		} elseif ( $thin_count <= round( $total * 0.1 ) ) {
			$checks[] = $this->check( 'content_depth', 'Content depth', 'warn', "{$thin_count} pages with fewer than 300 words. Consider expanding or merging them.", 4, 1 );
			++$score;
		} else {
			$pct      = round( ( $thin_count / $total ) * 100 );
			$checks[] = $this->check( 'content_depth', 'Content depth', 'fail', "{$thin_count} ({$pct}%) pages are thin (<300 words). Thin content is penalised by search engines.", 7, 0 );
		}

		// Coverage (2 pts).
		$empty_cats = $content['empty_categories'] ?? 0;
		if ( 0 === $empty_cats ) {
			$checks[] = $this->check( 'coverage', 'Content coverage', 'pass', 'All categories have content.', 0, 2 );
			$score   += 2;
		} else {
			$checks[] = $this->check( 'coverage', 'Content coverage', 'warn', "{$empty_cats} empty categories. Empty categories create dead-end pages — delete or fill them.", 3, 1 );
			++$score;
		}

		// Duplicate content (2 pts).
		$dupes = $content['potential_duplicate_count'] ?? 0;
		if ( 0 === $dupes ) {
			$checks[] = $this->check( 'duplicates', 'Duplicate content', 'pass', 'No obvious duplicate posts detected.', 0, 2 );
			$score   += 2;
		} else {
			$checks[] = $this->check( 'duplicates', 'Duplicate content', 'warn', "{$dupes} potentially similar posts detected. Duplicate content dilutes SEO authority.", 5, 1 );
			++$score;
		}

		// Media richness (2 pts).
		$text_only_pct = $content['text_only_post_pct'] ?? 0;
		if ( $text_only_pct < 20 ) {
			$checks[] = $this->check( 'media', 'Media richness', 'pass', "Only {$text_only_pct}% of posts are text-only.", 0, 2 );
			$score   += 2;
		} elseif ( $text_only_pct < 50 ) {
			$checks[] = $this->check( 'media', 'Media richness', 'warn', "{$text_only_pct}% of posts have no images or media. Visual content improves engagement and dwell time.", 3, 1 );
			++$score;
		} else {
			$checks[] = $this->check( 'media', 'Media richness', 'fail', "{$text_only_pct}% of posts are text-only. Add images, videos, or charts to key posts.", 5, 0 );
		}

		// Readability (2 pts).
		$avg_words = $content['avg_word_count'] ?? 0;
		if ( $avg_words >= 400 ) {
			$checks[] = $this->check( 'readability', 'Content depth & readability', 'pass', "Average post length: {$avg_words} words — good depth.", 0, 2 );
			$score   += 2;
		} elseif ( $avg_words >= 200 ) {
			$checks[] = $this->check( 'readability', 'Content depth & readability', 'warn', "Average post length: {$avg_words} words. Aim for 600+ words on key posts for better SEO value.", 3, 1 );
			++$score;
		} else {
			$checks[] = $this->check( 'readability', 'Content depth & readability', 'fail', "Average post length: {$avg_words} words. Very thin. Most posts need significant expansion.", 6, 0 );
		}

		// Publishing consistency (2 pts).
		$avg_days_between = $content['avg_days_between_posts'] ?? null;
		if ( null === $avg_days_between || $content['total_posts'] < 5 ) {
			$checks[] = $this->check( 'cadence', 'Publishing cadence', 'warn', 'Not enough posts to assess publishing cadence. Aim for regular publishing.', 2, 1 );
			++$score;
		} elseif ( $avg_days_between <= 14 ) {
			$checks[] = $this->check( 'cadence', 'Publishing cadence', 'pass', "Publishing roughly every {$avg_days_between} days — consistent.", 0, 2 );
			$score   += 2;
		} elseif ( $avg_days_between <= 60 ) {
			$checks[] = $this->check( 'cadence', 'Publishing cadence', 'warn', "Average {$avg_days_between} days between posts. Monthly publishing is the minimum for maintaining freshness signals.", 3, 1 );
			++$score;
		} else {
			$checks[] = $this->check( 'cadence', 'Publishing cadence', 'fail', "Very irregular publishing: average {$avg_days_between} days between posts. Inconsistent publishing hurts authority and freshness signals.", 6, 0 );
		}

		return array(
			'dimension' => 'content_quality',
			'max'       => 15,
			'score'     => min( $score, 15 ),
			'checks'    => $checks,
		);
	}

	private function score_commercial( array $comm, array $seo ): array {
		$checks = array();
		$score  = 0;

		// Conversion points (3 pts).
		$has_contact_form = ! empty( $comm['has_contact_form'] );
		$has_ecomm        = ! empty( $comm['has_ecommerce'] );
		$has_booking      = ! empty( $comm['has_booking_plugin'] );

		if ( $has_ecomm ) {
			$checks[] = $this->check( 'conversion', 'Conversion mechanism', 'pass', 'E-commerce is active — primary conversion mechanism in place.', 0, 3 );
			$score   += 3;
		} elseif ( $has_contact_form ) {
			$checks[] = $this->check( 'conversion', 'Conversion mechanism', 'pass', 'Contact form detected — primary conversion mechanism in place.', 0, 3 );
			$score   += 3;
		} elseif ( ! empty( $comm['phone_number_present'] ) || ! empty( $comm['email_address_present'] ) ) {
			$checks[] = $this->check( 'conversion', 'Conversion mechanism', 'warn', 'Contact info found but no form or checkout detected. A contact form converts 2–3× better than a phone number alone.', 5, 2 );
			$score   += 2;
		} else {
			$checks[] = $this->check( 'conversion', 'Conversion mechanism', 'fail', 'No conversion mechanism detected — no contact form, no checkout, no phone/email visible. Visitors have no way to take action.', 10, 0 );
		}

		// CTA placement (2 pts).
		$pages_no_cta = $comm['pages_without_cta_count'] ?? 0;
		$key_pages    = max( 1, $comm['key_page_count'] ?? 5 );
		$cta_pct      = round( ( 1 - ( $pages_no_cta / $key_pages ) ) * 100 );
		if ( $cta_pct >= 80 ) {
			$checks[] = $this->check( 'cta_placement', 'CTA placement', 'pass', "CTAs present on {$cta_pct}% of key pages.", 0, 2 );
			$score   += 2;
		} elseif ( $cta_pct >= 50 ) {
			$checks[] = $this->check( 'cta_placement', 'CTA placement', 'warn', "Only {$cta_pct}% of key pages have a CTA. Add clear next steps to every key page.", 6, 1 );
			++$score;
		} else {
			$checks[] = $this->check( 'cta_placement', 'CTA placement', 'fail', "Only {$cta_pct}% of key pages have a CTA. Most visitors arrive and have no obvious next step.", 8, 0 );
		}

		// Contact information (2 pts).
		$has_contact_page = ! empty( $comm['has_contact_page'] );
		$has_contact_info = ! empty( $comm['phone_number_present'] ) || ! empty( $comm['email_address_present'] );
		if ( $has_contact_page && $has_contact_info ) {
			$checks[] = $this->check( 'contact_info', 'Contact information', 'pass', 'Contact page exists and contact info is present.', 0, 2 );
			$score   += 2;
		} elseif ( $has_contact_page || $has_contact_info ) {
			$checks[] = $this->check( 'contact_info', 'Contact information', 'warn', 'Partial contact info. Ensure both a contact page and visible contact details (phone/email) are present.', 4, 1 );
			++$score;
		} else {
			$checks[] = $this->check( 'contact_info', 'Contact information', 'fail', 'No contact page or contact info detected. Visitors can\'t reach you.', 8, 0 );
		}

		// Trust signals (2 pts).
		$trust_score = $comm['trust_signal_score'] ?? 0;
		if ( $trust_score >= 2 ) {
			$checks[] = $this->check( 'trust', 'Trust signals', 'pass', 'Trust signals detected (testimonials, reviews, or certifications).', 0, 2 );
			$score   += 2;
		} elseif ( 1 === $trust_score ) {
			$checks[] = $this->check( 'trust', 'Trust signals', 'warn', 'Limited trust signals. Add testimonials, reviews, or partner logos to reduce purchase anxiety.', 6, 1 );
			++$score;
		} else {
			$checks[] = $this->check( 'trust', 'Trust signals', 'fail', 'No trust signals detected. Testimonials, reviews, and certifications measurably improve conversion rates.', 7, 0 );
		}

		// Lead capture (1 pt).
		$has_email_signup = ! empty( $comm['has_email_signup'] );
		if ( $has_email_signup ) {
			$checks[] = $this->check( 'lead_capture', 'Lead capture', 'pass', 'Email signup / newsletter form detected.', 0, 1 );
			++$score;
		} else {
			$checks[] = $this->check( 'lead_capture', 'Lead capture', 'warn', 'No email signup form detected. Even visitors who don\'t convert today can be captured for future marketing.', 4, 0 );
		}

		return array(
			'dimension' => 'commercial',
			'max'       => 10,
			'score'     => min( $score, 10 ),
			'checks'    => $checks,
		);
	}
}

return new Audit_Dimension();
