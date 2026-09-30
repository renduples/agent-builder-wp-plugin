<?php
/**
 * Tool: Check Permalink Structure
 *
 * Verify SEO-friendly URLs. Checks permalink setting, flags posts with overly
 * long slugs, numeric-only slugs, or stop-word-heavy slugs.
 *
 * @package Agentic\Tools
 */

declare(strict_types=1);

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Check_Permalink_Structure extends \Agentic\Tool_Base {

	public function get_name(): string {
		return 'check_permalink_structure';
	}

	public function get_description(): string {
		return 'Verify SEO-friendly URLs. Checks permalink setting, flags posts with overly long slugs, numeric-only slugs, or stop-word-heavy slugs.';
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
		$permalink = get_option( 'permalink_structure' );
		$issues    = array();

		if ( empty( $permalink ) ) {
			$issues[] = array(
				'type'   => 'critical',
				'detail' => 'Default permalink structure (plain). Set to /%postname%/ in Settings → Permalinks.',
			);
		} elseif ( preg_match( '/archives|%year%|%monthnum%|%day%/', $permalink ) ) {
			$issues[] = array(
				'type'   => 'warning',
				'detail' => "Date-based permalink structure: {$permalink}. Consider /%postname%/ for cleaner URLs.",
			);
		}

		// Check for problematic slugs.
		$posts      = get_posts(
			array(
				'post_type'      => array( 'post', 'page' ),
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		$long_slugs = array();
		$bad_slugs  = array();

		foreach ( $posts as $id ) {
			$post = get_post( $id );
			$slug = $post->post_name;

			if ( strlen( $slug ) > 75 ) {
				$long_slugs[] = array(
					'post_id' => $id,
					'title'   => $post->post_title,
					'slug'    => $slug,
					'length'  => strlen( $slug ),
				);
			}

			if ( preg_match( '/^\d+$/', $slug ) ) {
				$bad_slugs[] = array(
					'post_id' => $id,
					'title'   => $post->post_title,
					'slug'    => $slug,
					'issue'   => 'Numeric-only slug.',
				);
			}

			// Stop-word-heavy slugs (more than 3 stop words out of 5+ word slugs).
			$slug_words = explode( '-', $slug );
			$stop_words = array( 'a', 'an', 'the', 'is', 'in', 'on', 'at', 'to', 'for', 'of', 'and', 'or', 'but', 'with', 'this', 'that', 'it' );
			if ( count( $slug_words ) >= 5 ) {
				$stops = count( array_intersect( $slug_words, $stop_words ) );
				if ( $stops > 3 ) {
					$bad_slugs[] = array(
						'post_id' => $id,
						'title'   => $post->post_title,
						'slug'    => $slug,
						'issue'   => "Stop-word heavy ({$stops} stop words).",
					);
				}
			}
		}

		return array(
			'permalink_structure' => $permalink ? $permalink : '(default)',
			'is_seo_friendly'     => ! empty( $permalink ) && str_contains( $permalink, '%postname%' ),
			'total_posts_checked' => count( $posts ),
			'long_slugs'          => array_slice( $long_slugs, 0, 10 ),
			'problematic_slugs'   => array_slice( $bad_slugs, 0, 10 ),
			'issues'              => $issues,
		);
	}
}
return new Check_Permalink_Structure();
