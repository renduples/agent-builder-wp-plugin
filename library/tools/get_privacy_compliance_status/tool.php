<?php
/**
 * Tool: Get Privacy Compliance Status
 *
 * Audit GDPR/privacy compliance: consent plugin detection, privacy policy page,
 * terms page, cookie notice, and tracker disclosure.
 *
 * @package Agentic\Tools
 */

declare(strict_types=1);

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Get_Privacy_Compliance_Status extends \Agentic\Tool_Base {

	public function get_name(): string {
		return 'get_privacy_compliance_status';
	}

	public function get_description(): string {
		return 'Audit GDPR/privacy compliance: consent plugin detection, privacy policy page, terms page, cookie notice, and tracker disclosure.';
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

		// Consent plugins.
		$consent_plugins     = array(
			'cookie-law-info'     => 'Cookie Law Info',
			'gdpr-cookie-consent' => 'GDPR Cookie Consent',
			'cookiebot'           => 'Cookiebot',
			'cookie-notice'       => 'Cookie Notice',
			'complianz'           => 'Complianz',
			'uk-cookie-consent'   => 'UK Cookie Consent',
			'cookieyyes'          => 'CookieYes',
			'wp-gdpr-compliance'  => 'WP GDPR Compliance',
			'weforms'             => 'weForms',
		);
		$has_consent_plugin  = false;
		$consent_plugin_name = '';
		foreach ( $active_plugins as $p ) {
			foreach ( $consent_plugins as $slug => $name ) {
				if ( str_contains( $p, $slug ) ) {
					$has_consent_plugin  = true;
					$consent_plugin_name = $name;
					break 2;
				}
			}
		}

		// Privacy policy page.
		$privacy_page_id = (int) get_option( 'wp_page_for_privacy_policy' );
		$has_privacy     = $privacy_page_id && get_post_status( $privacy_page_id ) === 'publish';
		$privacy_url     = $has_privacy ? get_permalink( $privacy_page_id ) : null;

		// Terms page (look by common slugs/titles).
		$terms_page = get_page_by_path( 'terms' );
		if ( ! $terms_page ) {
			$terms_page = get_page_by_path( 'terms-of-service' );
		}
		if ( ! $terms_page ) {
			$terms_page = get_page_by_path( 'terms-and-conditions' );
		}
		$has_terms = $terms_page && 'publish' === $terms_page->post_status;

		// Known tracker script domains.
		$known_trackers    = array(
			'google-analytics.com' => 'Google Analytics',
			'googletagmanager.com' => 'Google Tag Manager',
			'connect.facebook.net' => 'Facebook Pixel',
			'static.hotjar.com'    => 'Hotjar',
			'script.hotjar.com'    => 'Hotjar',
			'cdn.mouseflow.com'    => 'Mouseflow',
			'static.clarity.ms'    => 'Microsoft Clarity',
		);
		$detected_trackers = array();

		// Scan theme header.php for tracker domains.
		$header_file = get_template_directory() . '/header.php';
		$header_html = file_exists( $header_file ) ? file_get_contents( $header_file ) : '';
		foreach ( $known_trackers as $domain => $tracker_name ) {
			if ( str_contains( $header_html, $domain ) ) {
				$detected_trackers[] = $tracker_name;
			}
		}

		// Compare to privacy policy content.
		$privacy_content = $has_privacy ? strtolower( get_post_field( 'post_content', $privacy_page_id ) ) : '';
		$undisclosed     = array();
		foreach ( $detected_trackers as $tracker ) {
			if ( ! str_contains( $privacy_content, strtolower( $tracker ) ) && ! str_contains( $privacy_content, strtolower( explode( ' ', $tracker )[0] ) ) ) {
				$undisclosed[] = $tracker;
			}
		}

		return array(
			'has_cookie_consent_plugin' => $has_consent_plugin,
			'consent_plugin_name'       => $consent_plugin_name,
			'has_privacy_policy'        => $has_privacy,
			'privacy_policy_url'        => $privacy_url,
			'has_terms_of_service'      => $has_terms,
			'detected_trackers'         => $detected_trackers,
			'undisclosed_trackers'      => $undisclosed,
		);
	}
}

return new Get_Privacy_Compliance_Status();
