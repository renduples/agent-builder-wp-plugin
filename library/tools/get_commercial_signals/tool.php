<?php
/**
 * Tool: Get Commercial Signals
 *
 * Detect commercial signals: contact forms, e-commerce, email signup,
 * booking plugins, contact page, phone/email on homepage, CTA presence
 * on key pages, and trust signals.
 *
 * @package Agentic\Tools
 */

declare(strict_types=1);

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Get_Commercial_Signals extends \Agentic\Tool_Base {

	public function get_name(): string {
		return 'get_commercial_signals';
	}

	public function get_description(): string {
		return 'Detect commercial signals: contact forms, e-commerce, email signup, booking plugins, contact page, phone/email on homepage, CTA presence on key pages, and trust signals.';
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
			++$trust_score;
		}
		if ( str_contains( $home_text, 'review' ) || str_contains( $home_text, 'stars' ) || str_contains( $home_text, '★' ) || str_contains( $home_text, '⭐' ) ) {
			++$trust_score;
		}
		if ( str_contains( $home_text, 'certified' ) || str_contains( $home_text, 'award' ) || str_contains( $home_text, 'partner' ) ) {
			++$trust_score;
		}

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
}

return new Get_Commercial_Signals();
