<?php
/**
 * Tool: Get AI Visibility Status
 *
 * Check how visible your site is to AI systems: AI bot blocking in robots.txt,
 * schema markup presence, FAQ content, llms.txt file, and content freshness.
 *
 * @package Agentic\Tools
 */

declare(strict_types=1);

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Get_Ai_Visibility_Status extends \Agentic\Tool_Base {

	public function get_name(): string {
		return 'get_ai_visibility_status';
	}

	public function get_description(): string {
		return 'Check how visible your site is to AI systems: AI bot blocking in robots.txt, schema markup presence, FAQ content, llms.txt file, and content freshness.';
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
		$robots_data = \Agentic\Tool_Helpers::read_robots_txt();
		$robots_txt  = $robots_data['content'] ?? '';
		$ai_bots     = \Agentic\Tool_Helpers::get_ai_bots();
		$allowed     = array();
		$blocked     = array();

		// Check blanket block.
		$blanket_block = (bool) preg_match( '/User-agent:\s*\*.*?Disallow:\s*\//si', $robots_txt );

		foreach ( $ai_bots as $bot => $meta ) {
			$pattern = '/User-agent:\s*' . preg_quote( $bot, '/' ) . '.*?Disallow:\s*\//si';
			if ( preg_match( $pattern, $robots_txt ) ) {
				$blocked[] = $bot;
			} else {
				$allowed[] = $bot;
			}
		}

		// Schema types from homepage — use Page_Renderer for rendered HTML.
		$schema_types = array();
		$rendered     = \Agentic\Page_Renderer::fetch( home_url( '/' ) );
		if ( $rendered['success'] ) {
			$schema_types = $rendered['meta']['schema_types'] ?? array();
		}

		// FAQ formatted content.
		$faq_content = $this->has_faq_content();

		// llms.txt.
		$has_llms = '' !== \Agentic\Virtual_Site_Files::llms_txt()['content'];

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
			'has_faq_formatted_content' => $faq_content,
			'has_llms_txt'              => $has_llms,
			'days_since_last_post'      => $days_since,
		);
	}

	private function has_faq_content(): bool {
		$cache_key = 'agentic_has_faq_content';
		$cached    = wp_cache_get( $cache_key, 'agentic' );
		if ( false !== $cached ) {
			return (bool) $cached;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
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

		$has_faq = $result > 0;
		wp_cache_set( $cache_key, (int) $has_faq, 'agentic', HOUR_IN_SECONDS );
		return $has_faq;
	}
}

return new Get_Ai_Visibility_Status();
