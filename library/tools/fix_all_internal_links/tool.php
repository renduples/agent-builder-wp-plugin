<?php
/**
 * Tool: fix_all_internal_links
 *
 * One-shot tool that fixes dead-end pages across the entire site.
 *
 * @package    Agent_Builder
 * @subpackage Tools
 * @since      2.0.0
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fix dead-end pages by adding Related Articles sections with best link suggestions.
 */
class Fix_All_Internal_Links extends \Agentic\Tool_Base {

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'fix_all_internal_links';
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return 'One-shot tool that fixes dead-end pages across the entire site. Analyses the full link graph, then automatically adds a "Related Articles" section to every dead-end page using the best link suggestions. Skips pages that already have a Related Articles section and skips utility pages. Use this when a user says "fix internal links" or "fix dead-end pages".';
	}

	/**
	 * Get the tool category.
	 *
	 * @return string
	 */
	public function get_category(): string {
		return 'seo';
	}

	/**
	 * Get the parameter schema.
	 *
	 * @return array
	 */
	public function get_parameters(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'max_links_per_page' => array(
					'type'        => 'integer',
					'description' => 'Maximum links to add per Related Articles section (1–8). Defaults to 4.',
				),
				'dry_run'            => array(
					'type'        => 'boolean',
					'description' => 'If true, returns what would be changed without making edits. Defaults to false.',
				),
			),
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Validated arguments from the LLM.
	 * @return array Result data.
	 */
	public function execute( array $arguments ): array {
		$max_links  = min( max( (int) ( $arguments['max_links_per_page'] ?? 4 ), 1 ), 8 );
		$dry_run    = (bool) ( $arguments['dry_run'] ?? false );
		$skip_slugs = array(
			'checkout',
			'checkout-success',
			'purchase-complete',
			'invoice',
			'my-account',
			'privacy-policy',
			'cookie-policy',
			'terms-of-service',
			'refund-policy',
			'accessibility',
			'subprocessors',
			'gdpr-policy',
			'developer-register',
			'agent-checkout',
			'donate',
		);
		$site_url   = untrailingslashit( get_bloginfo( 'url' ) );

		$posts = get_posts(
			array(
				'post_type'      => array( 'post', 'page' ),
				'post_status'    => 'publish',
				'posts_per_page' => 200,
			)
		);

		if ( empty( $posts ) ) {
			return array( 'error' => 'No published posts found.' );
		}

		// Identify dead-end pages (no outbound internal links).
		$dead_ends = array();
		foreach ( $posts as $p ) {
			if ( in_array( $p->post_name, $skip_slugs, true ) ) {
				continue;
			}
			preg_match_all( '/<a[^>]+href=["\']([^"\']+)["\'][^>]*>/i', $p->post_content, $matches );
			$has_outbound = false;
			foreach ( $matches[1] as $href ) {
				if ( ( str_starts_with( $href, '/' ) && ! str_starts_with( $href, '//' ) ) || str_starts_with( $href, $site_url ) ) {
					$has_outbound = true;
					break;
				}
			}
			if ( ! $has_outbound ) {
				$dead_ends[] = $p;
			}
		}

		$fixed       = array();
		$skipped     = array();
		$errors      = array();
		$tool_loader = \Agentic\Tool_Loader::get_instance();

		foreach ( $dead_ends as $de ) {
			if ( preg_match( '/<h[23][^>]*>\s*Related\s+Articles\s*<\/h[23]>/i', $de->post_content ) ) {
				$skipped[] = array(
					'post_id' => $de->ID,
					'title'   => $de->post_title,
					'reason'  => 'Related Articles section already exists',
				);
				continue;
			}

			$scored = \Agentic\Tool_Helpers::score_link_candidates( $de );
			usort( $scored, fn( $a, $b ) => $b['score'] <=> $a['score'] );
			$top = array_slice( $scored, 0, $max_links );

			if ( empty( $top ) ) {
				$skipped[] = array(
					'post_id' => $de->ID,
					'title'   => $de->post_title,
					'reason'  => 'No related pages found to link to',
				);
				continue;
			}

			$links = array_map(
				fn( $s ) => array(
					'title' => $s['title'],
					'url'   => wp_parse_url( $s['url'], PHP_URL_PATH ) ? wp_parse_url( $s['url'], PHP_URL_PATH ) : $s['url'],
				),
				$top
			);

			if ( $dry_run ) {
				$fixed[] = array(
					'post_id'        => $de->ID,
					'title'          => $de->post_title,
					'proposed_links' => $links,
					'dry_run'        => true,
				);
				continue;
			}

			$add_result = $tool_loader->execute(
				'add_related_links_section',
				array(
					'post_id' => $de->ID,
					'links'   => $links,
				)
			);

			if ( isset( $add_result['error'] ) ) {
				$errors[] = array(
					'post_id' => $de->ID,
					'title'   => $de->post_title,
					'error'   => $add_result['error'],
				);
			} else {
				$fixed[] = array(
					'post_id'     => $de->ID,
					'title'       => $de->post_title,
					'links_added' => count( $links ),
					'links'       => $links,
				);
			}
		}

		return array(
			'total_posts'     => count( $posts ),
			'dead_ends_found' => count( $dead_ends ),
			'fixed'           => $fixed,
			'fixed_count'     => count( $fixed ),
			'skipped'         => $skipped,
			'skipped_count'   => count( $skipped ),
			'errors'          => $errors,
			'error_count'     => count( $errors ),
			'dry_run'         => $dry_run,
			'message'         => $dry_run
				? sprintf( 'Dry run: would fix %d dead-end page(s), skipped %d. Re-run with dry_run=false to apply.', count( $fixed ), count( $skipped ) )
				: sprintf( 'Fixed %d dead-end page(s) with Related Articles sections. Skipped %d, %d error(s).', count( $fixed ), count( $skipped ), count( $errors ) ),
		);
	}

	/**
	 * Get tool annotations.
	 *
	 * @return array
	 */
	public function get_annotations(): array {
		return array(
			'destructive' => false,
		);
	}
}

return new Fix_All_Internal_Links();
