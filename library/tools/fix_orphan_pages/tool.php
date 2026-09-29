<?php
/**
 * Tool: fix_orphan_pages
 *
 * One-shot tool that fixes orphan pages by adding inbound links from hub pages.
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
 * Fix orphan pages by adding inbound internal links from the best hub pages.
 */
class Fix_Orphan_Pages extends \Agentic\Tool_Base {

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'fix_orphan_pages';
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return 'One-shot tool that fixes orphan pages (pages with no inbound internal links, invisible to crawlers). For each orphan, finds the best hub page that is topically related and adds a link TO the orphan from that hub page. Uses contextual linking (inline text) when possible, otherwise appends to or creates a Related Articles section on the hub page. Skips utility pages. Use this when a user says "fix orphan pages" or asks about pages with no inbound links.';
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
				'dry_run' => array(
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

		// Build URL → post_id index and inbound link map.
		$url_to_id = array();
		$inbound   = array();
		foreach ( $posts as $p ) {
			$permalink                             = get_permalink( $p->ID );
			$url_to_id[ $permalink ]               = $p->ID;
			$url_to_id[ rtrim( $permalink, '/' ) ] = $p->ID;
			$path                                  = wp_parse_url( $permalink, PHP_URL_PATH );
			if ( $path ) {
				$url_to_id[ $path ]               = $p->ID;
				$url_to_id[ rtrim( $path, '/' ) ] = $p->ID;
			}
			$inbound[ $p->ID ] = array();
		}

		$outbound_count = array();
		foreach ( $posts as $p ) {
			$out = 0;
			preg_match_all( '/<a[^>]+href=["\']([^"\']+)["\'][^>]*>/i', $p->post_content, $matches );
			foreach ( $matches[1] as $href ) {
				if ( str_starts_with( $href, '/' ) && ! str_starts_with( $href, '//' ) ) {
					$href_full = $site_url . $href;
				} elseif ( str_starts_with( $href, $site_url ) ) {
					$href_full = $href;
				} else {
					continue;
				}
				$clean = strtok( $href_full, '?#' );
				$tid   = $url_to_id[ $clean ] ?? $url_to_id[ rtrim( $clean, '/' ) ] ?? null;
				if ( $tid && $tid !== $p->ID ) {
					$inbound[ $tid ][] = $p->ID;
					++$out;
				}
			}
			$outbound_count[ $p->ID ] = $out;
		}

		$home_id = (int) get_option( 'page_on_front' );
		$orphans = array();
		$hubs    = array();
		foreach ( $posts as $p ) {
			if ( in_array( $p->post_name, $skip_slugs, true ) ) {
				continue;
			}
			if ( empty( $inbound[ $p->ID ] ) && $p->ID !== $home_id ) {
				$orphans[] = $p;
			}
			if ( ( $outbound_count[ $p->ID ] ?? 0 ) >= 3 ) {
				$hubs[ $p->ID ] = $p;
			}
		}

		if ( empty( $orphans ) ) {
			return array(
				'total_posts'   => count( $posts ),
				'orphans_found' => 0,
				'message'       => 'No orphan pages found — all content pages have at least one inbound internal link.',
			);
		}
		if ( empty( $hubs ) ) {
			return array(
				'total_posts'   => count( $posts ),
				'orphans_found' => count( $orphans ),
				'error'         => 'No hub pages found (3+ outbound links) to link from. Build hub pages first.',
			);
		}

		$fixed       = array();
		$skipped     = array();
		$errors      = array();
		$tool_loader = \Agentic\Tool_Loader::get_instance();

		foreach ( $orphans as $orphan ) {
			$orphan_url  = get_permalink( $orphan->ID );
			$orphan_path = wp_parse_url( $orphan_url, PHP_URL_PATH );
			if ( ! $orphan_path ) {
				$orphan_path = $orphan_url;
			}
			$orphan_title = $orphan->post_title;

			// Score each hub for relevance.
			$hub_scores = array();
			foreach ( $hubs as $hub ) {
				if ( $hub->ID === $orphan->ID ) {
					continue;
				}
				if ( in_array( $hub->ID, $inbound[ $orphan->ID ] ?? array(), true ) ) {
					continue;
				}

				$score              = 0;
				$hub_content_lower  = strtolower( wp_strip_all_tags( $hub->post_content ) );
				$orphan_title_lower = strtolower( $orphan_title );

				if ( str_contains( $hub_content_lower, $orphan_title_lower ) ) {
					$score += 10;
				}

				$default_cat = (int) get_option( 'default_category' );
				$orphan_cats = wp_get_post_categories( $orphan->ID, array( 'fields' => 'ids' ) );
				$hub_cats    = wp_get_post_categories( $hub->ID, array( 'fields' => 'ids' ) );
				$shared_cats = array_diff( array_intersect( $orphan_cats, $hub_cats ), array( $default_cat ) );
				$score      += count( $shared_cats ) * 3;

				$orphan_tags = wp_get_post_tags( $orphan->ID, array( 'fields' => 'ids' ) );
				$hub_tags    = wp_get_post_tags( $hub->ID, array( 'fields' => 'ids' ) );
				$score      += count( array_intersect( $orphan_tags, $hub_tags ) ) * 2;

				$stop    = array( 'the', 'and', 'for', 'with', 'how', 'what', 'why', 'your', 'from', 'that', 'this', 'are', 'was', 'has', 'have', 'not', 'but', 'can', 'will', 'its', 'our', 'agent', 'builder' );
				$o_words = array_diff( array_filter( array_map( 'strtolower', preg_split( '/[\s\-—:|\/ ]+/', $orphan_title ) ), fn( $w ) => mb_strlen( $w ) >= 3 ), $stop );
				$h_words = array_diff( array_filter( array_map( 'strtolower', preg_split( '/[\s\-—:|\/ ]+/', $hub->post_title ) ), fn( $w ) => mb_strlen( $w ) >= 3 ), $stop );
				$score  += count( array_intersect( $o_words, $h_words ) ) * 2;

				if ( ( $outbound_count[ $hub->ID ] ?? 0 ) >= 5 ) {
					++$score;
				}

				if ( $score > 0 ) {
					$hub_scores[] = array(
						'hub'   => $hub,
						'score' => $score,
					);
				}
			}

			if ( empty( $hub_scores ) ) {
				$skipped[] = array(
					'post_id' => $orphan->ID,
					'title'   => $orphan_title,
					'reason'  => 'No topically related hub page found',
				);
				continue;
			}

			usort( $hub_scores, fn( $a, $b ) => $b['score'] <=> $a['score'] );
			$best_hub = $hub_scores[0]['hub'];

			if ( $dry_run ) {
				$fixed[] = array(
					'orphan_id'    => $orphan->ID,
					'orphan_title' => $orphan_title,
					'hub_id'       => $best_hub->ID,
					'hub_title'    => $best_hub->post_title,
					'score'        => $hub_scores[0]['score'],
					'dry_run'      => true,
				);
				continue;
			}

			// Strategy 1: contextual link using full title.
			$contextual_result = $tool_loader->execute(
				'insert_contextual_link',
				array(
					'post_id'     => $best_hub->ID,
					'anchor_text' => $orphan_title,
					'target_url'  => $orphan_path,
				)
			);
			if ( ! empty( $contextual_result['success'] ) ) {
				$fixed[] = array(
					'orphan_id'    => $orphan->ID,
					'orphan_title' => $orphan_title,
					'hub_id'       => $best_hub->ID,
					'hub_title'    => $best_hub->post_title,
					'method'       => 'contextual_link',
					'anchor_text'  => $orphan_title,
				);
				continue;
			}

			// Strategy 2: short anchor from significant words.
			$stop         = array( 'the', 'and', 'for', 'with', 'how', 'what', 'why', 'your', 'from', 'that', 'this', 'are', 'was', 'a', 'an', 'to', 'of', 'in', 'on' );
			$sig_words    = array_values(
				array_filter(
					preg_split( '/[\s\-—]+/', $orphan_title ),
					fn( $w ) => ! in_array( strtolower( $w ), $stop, true ) && mb_strlen( $w ) >= 3
				)
			);
			$short_anchor = implode( ' ', array_slice( $sig_words, 0, 2 ) );

			if ( ! empty( $short_anchor ) && strtolower( $short_anchor ) !== strtolower( $orphan_title ) ) {
				$short_result = $tool_loader->execute(
					'insert_contextual_link',
					array(
						'post_id'     => $best_hub->ID,
						'anchor_text' => $short_anchor,
						'target_url'  => $orphan_path,
					)
				);
				if ( ! empty( $short_result['success'] ) ) {
					$fixed[] = array(
						'orphan_id'    => $orphan->ID,
						'orphan_title' => $orphan_title,
						'hub_id'       => $best_hub->ID,
						'hub_title'    => $best_hub->post_title,
						'method'       => 'contextual_link_short',
						'anchor_text'  => $short_anchor,
					);
					continue;
				}
			}

			// Strategy 3: append to existing Related Articles section.
			$hub_content = $best_hub->post_content;
			if ( preg_match( '/<h[23][^>]*>\s*Related\s+Articles\s*<\/h[23]>/i', $hub_content ) ) {
				$new_item    = '<li><a href="' . esc_url( $orphan_path ) . '">' . esc_html( $orphan_title ) . '</a></li>';
				$hub_content = preg_replace(
					'/(<ul[^>]*class="wp-block-list"[^>]*>)(.*?)(<\/ul>)/is',
					'$1$2' . $new_item . '$3',
					$hub_content,
					1,
					$count
				);
				if ( $count > 0 ) {
					$result = wp_update_post(
						array(
							'ID'           => $best_hub->ID,
							'post_content' => $hub_content,
						),
						true
					);
					if ( ! is_wp_error( $result ) ) {
						$fixed[]                = array(
							'orphan_id'    => $orphan->ID,
							'orphan_title' => $orphan_title,
							'hub_id'       => $best_hub->ID,
							'hub_title'    => $best_hub->post_title,
							'method'       => 'appended_to_related_articles',
						);
						$best_hub->post_content = $hub_content;
						continue;
					}
				}
			}

			// Strategy 4: new Related Articles section.
			$add_result = $tool_loader->execute(
				'add_related_links_section',
				array(
					'post_id' => $best_hub->ID,
					'links'   => array(
						array(
							'title' => $orphan_title,
							'url'   => $orphan_path,
						),
					),
				)
			);

			if ( isset( $add_result['error'] ) ) {
				$errors[] = array(
					'orphan_id'    => $orphan->ID,
					'orphan_title' => $orphan_title,
					'hub_id'       => $best_hub->ID,
					'hub_title'    => $best_hub->post_title,
					'error'        => $add_result['error'],
				);
			} else {
				$fixed[]   = array(
					'orphan_id'    => $orphan->ID,
					'orphan_title' => $orphan_title,
					'hub_id'       => $best_hub->ID,
					'hub_title'    => $best_hub->post_title,
					'method'       => 'new_related_articles_section',
				);
				$refreshed = get_post( $best_hub->ID );
				if ( $refreshed ) {
					$best_hub->post_content = $refreshed->post_content;
				}
			}
		}

		return array(
			'total_posts'    => count( $posts ),
			'orphans_found'  => count( $orphans ),
			'hub_pages_used' => count( $hubs ),
			'fixed'          => $fixed,
			'fixed_count'    => count( $fixed ),
			'skipped'        => $skipped,
			'skipped_count'  => count( $skipped ),
			'errors'         => $errors,
			'error_count'    => count( $errors ),
			'dry_run'        => $dry_run,
			'message'        => $dry_run
				? sprintf( 'Dry run: would fix %d orphan page(s) by adding inbound links from hub pages. Skipped %d.', count( $fixed ), count( $skipped ) )
				: sprintf( 'Fixed %d orphan page(s) with inbound links from hub pages. Skipped %d, %d error(s).', count( $fixed ), count( $skipped ), count( $errors ) ),
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

return new Fix_Orphan_Pages();
