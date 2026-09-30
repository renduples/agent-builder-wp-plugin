<?php
/**
 * Tool: analyze_post_seo
 *
 * Full SEO audit of a single post with scoring and recommendations.
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
 * Full SEO audit of a single post with score, analysis, and recommendations.
 */
class Analyze_Post_Seo extends \Agentic\Tool_Base {

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'analyze_post_seo';
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return 'Full SEO audit of a single post. Returns a score (0–100), title length, meta description, heading structure, keyword density, internal/external link counts, image alt text coverage, and a list of specific recommendations. Always analyse before updating.';
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
				'post_id'       => array(
					'type'        => 'integer',
					'description' => 'The WordPress post ID to audit.',
				),
				'focus_keyword' => array(
					'type'        => 'string',
					'description' => 'Optional focus keyword to check for in title, meta, headings, first paragraph, and body.',
				),
			),
			'required'   => array( 'post_id' ),
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Validated arguments from the LLM.
	 * @return array Result data.
	 */
	public function execute( array $arguments ): array {
		$post = $this->get_viewable_post( (int) ( $arguments['post_id'] ?? 0 ) );
		if ( ! $post ) {
			return array( 'error' => 'Post not found.' );
		}

		$content    = $post->post_content;
		$plain_text = wp_strip_all_tags( $content );
		$title      = $post->post_title;
		$title_len  = mb_strlen( $title );
		$word_count = str_word_count( $plain_text );
		$meta_desc  = \Agentic\Tool_Helpers::get_meta_description( $post->ID, $post->post_excerpt );
		$slug       = $post->post_name;
		$site_url   = untrailingslashit( get_bloginfo( 'url' ) );

		// Headings.
		preg_match_all( '/<h([1-6])[^>]*>(.*?)<\/h[1-6]>/is', $content, $headings );
		$h1_texts = array();
		$h2_texts = array();
		foreach ( $headings[1] as $i => $level ) {
			if ( '1' === $level ) {
				$h1_texts[] = wp_strip_all_tags( $headings[2][ $i ] );
			} elseif ( '2' === $level ) {
				$h2_texts[] = wp_strip_all_tags( $headings[2][ $i ] );
			}
		}

		// Links.
		preg_match_all( '/<a[^>]+href=["\']([^"\']+)["\'][^>]*>/i', $content, $links );
		$internal_links = 0;
		$external_links = 0;
		foreach ( $links[1] as $href ) {
			if ( str_starts_with( $href, $site_url ) || str_starts_with( $href, '/' ) ) {
				++$internal_links;
			} else {
				++$external_links;
			}
		}

		// Images.
		preg_match_all( '/<img([^>]*)>/i', $content, $img_matches );
		$imgs_without_alt = 0;
		foreach ( $img_matches[1] as $img_attrs ) {
			if ( ! preg_match( '/alt=["\'][^"\']+["\']/', $img_attrs ) ) {
				++$imgs_without_alt;
			}
		}

		// Focus keyword analysis.
		$kw_analysis = null;
		if ( ! empty( $arguments['focus_keyword'] ) ) {
			$kw      = strtolower( trim( $arguments['focus_keyword'] ) );
			$lc_text = strtolower( $plain_text );
			$count   = substr_count( $lc_text, $kw );
			$density = $word_count > 0 ? round( ( $count / $word_count ) * 100, 2 ) : 0;

			$kw_analysis = array(
				'keyword'       => $arguments['focus_keyword'],
				'in_title'      => str_contains( strtolower( $title ), $kw ),
				'in_meta_desc'  => str_contains( strtolower( $meta_desc ), $kw ),
				'in_slug'       => str_contains( strtolower( $slug ), str_replace( ' ', '-', $kw ) ),
				'in_h1'         => (bool) array_filter( $h1_texts, fn( $h ) => str_contains( strtolower( $h ), $kw ) ),
				'in_first_para' => str_contains( strtolower( mb_substr( $plain_text, 0, 200 ) ), $kw ),
				'occurrences'   => $count,
				'density'       => $density,
				'density_note'  => $density < 0.5 ? 'Too low — use the keyword more naturally' : ( $density > 3.0 ? 'Too high — risk of keyword stuffing' : 'Good (0.5–3%)' ),
			);
		}

		// Score.
		$score  = 100;
		$issues = array();
		$pass   = array();

		if ( $title_len < 30 ) {
			$score   -= 15;
			$issues[] = "Title too short ({$title_len} chars). Aim for 30–60 characters.";
		} elseif ( $title_len > 60 ) {
			$score   -= 10;
			$issues[] = "Title too long ({$title_len} chars). Aim for 30–60 characters.";
		} else {
			$pass[] = "Title length is good ({$title_len} chars).";
		}

		$meta_len = mb_strlen( $meta_desc );
		if ( empty( $meta_desc ) ) {
			$score   -= 20;
			$issues[] = 'No meta description. Add one (120–158 chars) to control your search snippet.';
		} elseif ( $meta_len < 120 ) {
			$score   -= 10;
			$issues[] = "Meta description is short ({$meta_len} chars). Aim for 120–158 characters.";
		} elseif ( $meta_len > 158 ) {
			$score   -= 5;
			$issues[] = "Meta description too long ({$meta_len} chars). Keep under 158 to avoid truncation.";
		} else {
			$pass[] = "Meta description length is good ({$meta_len} chars).";
		}

		if ( $word_count < 300 ) {
			$score   -= 15;
			$issues[] = "Thin content ({$word_count} words). Aim for 600+ words for competitive topics.";
		} else {
			$pass[] = "Content length is good ({$word_count} words).";
		}

		if ( count( $headings[0] ) === 0 ) {
			$score   -= 10;
			$issues[] = 'No headings found. Add an H1 and at least one H2.';
		} elseif ( count( $h1_texts ) === 0 ) {
			$score   -= 5;
			$issues[] = 'No H1 heading found.';
		} else {
			$pass[] = 'Heading structure present.';
		}

		if ( 0 === $internal_links ) {
			$score   -= 10;
			$issues[] = 'No internal links. Link to related content to improve crawlability.';
		} else {
			$pass[] = "{$internal_links} internal link(s) found.";
		}

		if ( count( $img_matches[0] ) === 0 ) {
			$score   -= 5;
			$issues[] = 'No images found. Images improve engagement and provide alt text opportunities.';
		} elseif ( $imgs_without_alt > 0 ) {
			$score   -= 5;
			$issues[] = "{$imgs_without_alt} image(s) missing alt text.";
		} else {
			$pass[] = 'All images have alt text.';
		}

		if ( strlen( $slug ) > 50 ) {
			$score   -= 5;
			$issues[] = 'URL slug is long. Keep it concise and keyword-rich (2–5 words).';
		} else {
			$pass[] = 'URL slug is concise.';
		}

		// Rendered page analysis.
		$rendered_page = null;
		$rendered      = \Agentic\Page_Renderer::fetch_by_post_id( $post->ID );
		if ( $rendered['success'] ) {
			$meta          = $rendered['meta'];
			$rendered_page = array(
				'title'        => $meta['title'] ?? '',
				'description'  => $meta['description'] ?? '',
				'canonical'    => $meta['canonical'] ?? '',
				'og_title'     => $meta['og_title'] ?? '',
				'og_desc'      => $meta['og_description'] ?? '',
				'og_image'     => $meta['og_image'] ?? '',
				'schema_types' => $meta['schema_types'] ?? array(),
				'h1_count'     => count( $meta['h1'] ?? array() ),
				'h2_count'     => count( $meta['h2'] ?? array() ),
				'word_count'   => $meta['word_count'] ?? 0,
				'link_count'   => $meta['link_count'] ?? 0,
				'image_count'  => $meta['image_count'] ?? 0,
			);

			if ( empty( $meta['canonical'] ) ) {
				$score   -= 5;
				$issues[] = 'No canonical URL found on the rendered page.';
			} else {
				$pass[] = 'Canonical URL is set.';
			}

			if ( empty( $meta['og_title'] ) || empty( $meta['og_description'] ) ) {
				$score   -= 5;
				$issues[] = 'Missing Open Graph tags (og:title or og:description). Social sharing will use fallbacks.';
			} else {
				$pass[] = 'Open Graph tags present.';
			}

			if ( empty( $meta['schema_types'] ) ) {
				$score   -= 5;
				$issues[] = 'No JSON-LD schema markup detected on the rendered page.';
			} else {
				$pass[] = 'Schema markup found: ' . implode( ', ', $meta['schema_types'] ) . '.';
			}
		}

		return array(
			'id'               => $post->ID,
			'title'            => $title,
			'title_chars'      => $title_len,
			'meta_description' => $meta_desc,
			'meta_desc_chars'  => $meta_len,
			'slug'             => $slug,
			'word_count'       => $word_count,
			'h1_headings'      => $h1_texts,
			'h2_headings'      => $h2_texts,
			'total_headings'   => count( $headings[0] ),
			'internal_links'   => $internal_links,
			'external_links'   => $external_links,
			'images'           => count( $img_matches[0] ),
			'images_no_alt'    => $imgs_without_alt,
			'seo_score'        => max( 0, $score ),
			'passing'          => $pass,
			'issues'           => $issues,
			'focus_keyword'    => $kw_analysis,
			'rendered_page'    => $rendered_page,
		);
	}

	/**
	 * Get tool annotations.
	 *
	 * @return array
	 */
	public function get_annotations(): array {
		return array(
			'readonly' => true,
		);
	}
}

return new Analyze_Post_Seo();
