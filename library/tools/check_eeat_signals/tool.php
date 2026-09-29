<?php
/**
 * Tool: Check E-E-A-T Signals
 *
 * Audit Experience, Expertise, Authoritativeness, and Trustworthiness signals.
 * Checks about page, author bios, contact page, HTTPS, privacy policy,
 * organization schema, and content depth.
 *
 * @package Agentic\Tools
 */

declare(strict_types=1);

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Check_Eeat_Signals extends \Agentic\Tool_Base {

	public function get_name(): string {
		return 'check_eeat_signals';
	}

	public function get_description(): string {
		return 'Audit Experience, Expertise, Authoritativeness, and Trustworthiness signals. Checks about page, author bios, contact page, HTTPS, privacy policy, organization schema, and content depth.';
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
		$score  = 0;
		$checks = array();

		// 1. About page exists (15 pts).
		$about = get_page_by_path( 'about' );
		if ( ! $about ) {
			$about = get_page_by_path( 'about-us' );
		}
		$has_about = $about && 'publish' === $about->post_status;
		if ( $has_about ) {
			$about_words = str_word_count( wp_strip_all_tags( $about->post_content ) );
			if ( $about_words >= 200 ) {
				$score   += 15;
				$checks[] = array(
					'check'  => 'About page',
					'status' => 'pass',
					'detail' => "About page exists ({$about_words} words).",
				);
			} else {
				$score   += 8;
				$checks[] = array(
					'check'  => 'About page',
					'status' => 'warn',
					'detail' => "About page exists but is thin ({$about_words} words). Expand with credentials and experience.",
				);
			}
		} else {
			$checks[] = array(
				'check'  => 'About page',
				'status' => 'fail',
				'detail' => 'No About page found. Create one explaining who you are, your expertise, and credentials.',
			);
		}

		// 2. Author bios exist (20 pts).
		$authors          = get_users(
			array(
				'capability'          => array( 'edit_posts' ),
				'has_published_posts' => true,
			)
		);
		$authors_with_bio = 0;
		foreach ( $authors as $a ) {
			if ( ! empty( get_the_author_meta( 'description', $a->ID ) ) ) {
				++$authors_with_bio;
			}
		}
		$author_count = count( $authors );
		if ( $author_count > 0 && $authors_with_bio === $author_count ) {
			$score   += 20;
			$checks[] = array(
				'check'  => 'Author bios',
				'status' => 'pass',
				'detail' => "All {$author_count} authors have bios.",
			);
		} elseif ( $authors_with_bio > 0 ) {
			$score   += 10;
			$missing  = $author_count - $authors_with_bio;
			$checks[] = array(
				'check'  => 'Author bios',
				'status' => 'warn',
				'detail' => "{$missing} of {$author_count} authors missing bios.",
			);
		} else {
			$checks[] = array(
				'check'  => 'Author bios',
				'status' => 'fail',
				'detail' => 'No author bios found. Add biographical descriptions in Users → Profile.',
			);
		}

		// 3. Contact page / transparency (15 pts).
		$contact = get_page_by_path( 'contact' );
		if ( ! $contact ) {
			$contact = get_page_by_path( 'contact-us' );
		}
		$has_contact = $contact && 'publish' === $contact->post_status;
		if ( $has_contact ) {
			$score   += 15;
			$checks[] = array(
				'check'  => 'Contact page',
				'status' => 'pass',
				'detail' => 'Contact page exists.',
			);
		} else {
			$checks[] = array(
				'check'  => 'Contact page',
				'status' => 'fail',
				'detail' => 'No contact page. Transparency and reachability are core E-E-A-T signals.',
			);
		}

		// 4. HTTPS (10 pts).
		if ( strpos( home_url(), 'https://' ) === 0 ) {
			$score   += 10;
			$checks[] = array(
				'check'  => 'HTTPS',
				'status' => 'pass',
				'detail' => 'Site served over HTTPS.',
			);
		} else {
			$checks[] = array(
				'check'  => 'HTTPS',
				'status' => 'fail',
				'detail' => 'No HTTPS. Trust and security baseline not met.',
			);
		}

		// 5. Privacy policy (10 pts).
		$privacy_id = (int) get_option( 'wp_page_for_privacy_policy' );
		if ( $privacy_id && get_post_status( $privacy_id ) === 'publish' ) {
			$score   += 10;
			$checks[] = array(
				'check'  => 'Privacy policy',
				'status' => 'pass',
				'detail' => 'Privacy policy page exists.',
			);
		} else {
			$checks[] = array(
				'check'  => 'Privacy policy',
				'status' => 'fail',
				'detail' => 'No privacy policy. Required for trust and compliance.',
			);
		}

		// 6. Organization schema (15 pts).
		$home_response  = @wp_safe_remote_get(
			home_url(),
			array(
				'timeout'   => 8,
				'sslverify' => false,
			)
		);
		$home_html      = ! is_wp_error( $home_response ) ? wp_remote_retrieve_body( $home_response ) : '';
		$has_org_schema = (bool) preg_match( '/"@type"\s*:\s*"(Organization|LocalBusiness)"/', $home_html );
		if ( $has_org_schema ) {
			$score   += 15;
			$checks[] = array(
				'check'  => 'Organization schema',
				'status' => 'pass',
				'detail' => 'Organization/LocalBusiness schema detected on homepage.',
			);
		} else {
			$checks[] = array(
				'check'  => 'Organization schema',
				'status' => 'warn',
				'detail' => 'No Organization schema. Add structured data to establish entity identity.',
			);
		}

		// 7. Content depth / expertise signals (15 pts).
		$posts      = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => 10,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
		$deep_posts = 0;
		foreach ( $posts as $p ) {
			$words = str_word_count( wp_strip_all_tags( $p->post_content ) );
			if ( $words >= 800 ) {
				++$deep_posts;
			}
		}
		$post_count = count( $posts );
		if ( $post_count > 0 && $deep_posts >= $post_count * 0.6 ) {
			$score   += 15;
			$checks[] = array(
				'check'  => 'Content depth',
				'status' => 'pass',
				'detail' => "{$deep_posts}/{$post_count} recent posts are 800+ words — demonstrates expertise.",
			);
		} elseif ( $deep_posts > 0 ) {
			$score   += 8;
			$checks[] = array(
				'check'  => 'Content depth',
				'status' => 'warn',
				'detail' => "Only {$deep_posts}/{$post_count} recent posts are 800+ words. More in-depth content strengthens E-E-A-T.",
			);
		} else {
			$checks[] = array(
				'check'  => 'Content depth',
				'status' => 'fail',
				'detail' => 'No recent posts reach 800 words. Shallow content weakens expertise signals.',
			);
		}

		return array(
			'eeat_score' => min( 100, $score ),
			'max_score'  => 100,
			'checks'     => $checks,
		);
	}
}

return new Check_Eeat_Signals();
