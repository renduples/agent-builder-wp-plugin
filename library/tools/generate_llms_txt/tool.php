<?php
/**
 * Tool: Generate Llms Txt
 *
 * Write the llms.txt file to the WordPress root.
 *
 * Follows the llms.txt specification by Jeremy Howard (llmstxt.org):
 * H1 title, blockquote summary, H2-delimited link sections with
 * [title](url): description format.
 *
 * @package Agentic\Tools
 */

declare(strict_types=1);

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes an llms.txt file describing the site for AI platforms.
 */
class Generate_Llms_Txt extends \Agentic\Tool_Base {

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'generate_llms_txt';
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return 'Publish an llms.txt for this site (llmstxt.org format: site name, summary, key pages, recent content). The plugin stores it and serves it at /llms.txt; no file is written. Use preview_llms_txt first.';
	}

	/**
	 * Get the tool category.
	 *
	 * @return string
	 */
	public function get_category(): string {
		return 'ai-visibility';
	}

	/**
	 * Get the tool parameters.
	 *
	 * @return array
	 */
	public function get_parameters(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'include_posts' => array(
					'type'        => 'integer',
					'description' => 'Number of recent posts to include (1-20). Defaults to 10.',
				),
			),
		);
	}

	/**
	 * Execute the llms.txt generation and write.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array Write result with confirmation.
	 */
	public function execute( array $arguments ): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			return array( 'error' => 'You do not have permission to publish llms.txt.' );
		}

		$include_posts = min( max( (int) ( $arguments['include_posts'] ?? 10 ), 1 ), 20 );
		$content       = self::build_content( $include_posts );

		// Stored and served at /llms.txt by the plugin; never written to disk.
		\Agentic\Virtual_Site_Files::set_llms_txt( $content );

		$result = array(
			'saved'   => true,
			'bytes'   => strlen( $content ),
			'url'     => home_url( '/llms.txt' ),
			'message' => 'llms.txt saved. The plugin serves it at ' . home_url( '/llms.txt' ) . ' (no file was written).',
		);

		if ( \Agentic\Virtual_Site_Files::physical_exists( 'llms.txt' ) ) {
			$result['physical_file'] = \Agentic\Virtual_Site_Files::root_path( 'llms.txt' );
			$result['content']       = $content;
			$result['message']       = 'llms.txt content saved, but this site already has a physical llms.txt file at ' . $result['physical_file'] . ', which the web server serves instead. The plugin never writes files at the site root: ask the administrator to replace that file\'s content with the content above, or to delete the file so the plugin serves the new version.';
		}

		return $result;
	}

	/**
	 * Build llms.txt content following the llmstxt.org specification.
	 *
	 * Format: H1 title, blockquote summary, H2-delimited link sections.
	 * Each link uses [title](url): description format per spec.
	 *
	 * @param int $include_posts Number of recent posts to include.
	 * @return string Complete llms.txt content.
	 */
	public static function build_content( int $include_posts = 10 ): string {
		$site_name = get_bloginfo( 'name' );
		$site_desc = get_bloginfo( 'description' );
		$site_url  = home_url( '/' );

		// H1 title (required by spec).
		$lines = array( '# ' . $site_name, '' );

		// Blockquote summary (recommended by spec).
		if ( ! empty( $site_desc ) ) {
			$lines[] = '> ' . $site_desc;
			$lines[] = '';
		}

		// Key Pages section.
		$lines[] = '## Key Pages';
		$lines[] = '';
		$lines[] = '- [Home](' . $site_url . '): Main landing page';

		$front_page_id = (int) get_option( 'page_on_front' );
		$pages         = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'posts_per_page' => 20,
				'post_parent'    => 0,
				'orderby'        => 'menu_order',
				'order'          => 'ASC',
			)
		);

		foreach ( $pages as $page ) {
			if ( $page->ID === $front_page_id ) {
				continue;
			}
			$desc  = self::get_page_description( $page );
			$entry = '- [' . $page->post_title . '](' . get_permalink( $page->ID ) . ')';
			if ( ! empty( $desc ) ) {
				$entry .= ': ' . $desc;
			}
			$lines[] = $entry;
		}
		$lines[] = '';

		// Recent Content section.
		$posts = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => $include_posts,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		if ( ! empty( $posts ) ) {
			$lines[] = '## Recent Content';
			$lines[] = '';
			foreach ( $posts as $post ) {
				$desc  = self::get_page_description( $post );
				$entry = '- [' . $post->post_title . '](' . get_permalink( $post->ID ) . ')';
				if ( ! empty( $desc ) ) {
					$entry .= ': ' . $desc;
				}
				$lines[] = $entry;
			}
			$lines[] = '';
		}

		// Optional section (per spec — LLMs can skip this for shorter context).
		$optional = array();

		foreach ( array( '/sitemap_index.xml', '/sitemap.xml', '/wp-sitemap.xml' ) as $path ) {
			$full_url = home_url( $path );
			$response = wp_remote_head(
				$full_url,
				array(
					'timeout'   => 5,
					'sslverify' => false,
				)
			);
			if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
				$optional[] = '- [Sitemap](' . $full_url . '): XML sitemap for crawlers';
				break;
			}
		}

		if ( ! empty( $optional ) ) {
			$lines[] = '## Optional';
			$lines[] = '';
			$lines   = array_merge( $lines, $optional );
			$lines[] = '';
		}

		return implode( "\n", $lines );
	}

	/**
	 * Get a short description for a post/page to use after the link.
	 *
	 * Checks SEO meta description (Yoast, Rank Math, AIOSEO), then falls
	 * back to the post excerpt, then to a trimmed version of the content.
	 *
	 * @param \WP_Post $post Post object.
	 * @return string Description (max 160 chars, single line).
	 */
	private static function get_page_description( \WP_Post $post ): string {
		// Try Yoast SEO meta description.
		$desc = (string) get_post_meta( $post->ID, '_yoast_wpseo_metadesc', true );

		// Try Rank Math.
		if ( empty( $desc ) ) {
			$desc = (string) get_post_meta( $post->ID, 'rank_math_description', true );
		}

		// Try AIOSEO.
		if ( empty( $desc ) ) {
			$desc = (string) get_post_meta( $post->ID, '_aioseo_description', true );
		}

		// Fall back to post excerpt.
		if ( empty( $desc ) && ! empty( $post->post_excerpt ) ) {
			$desc = $post->post_excerpt;
		}

		// Last resort: trim content (strip shortcodes and HTML first).
		if ( empty( $desc ) && ! empty( $post->post_content ) ) {
			$desc = strip_shortcodes( $post->post_content );
			$desc = wp_strip_all_tags( $desc );
			$desc = preg_replace( '/\s+/', ' ', $desc );
		}

		// Clean up and truncate.
		$desc = trim( (string) $desc );
		if ( strlen( $desc ) > 160 ) {
			$desc = substr( $desc, 0, 157 ) . '...';
		}

		// Remove line breaks — must be single-line per spec link format.
		$desc = str_replace( array( "\r\n", "\r", "\n" ), ' ', $desc );

		return $desc;
	}

	/**
	 * Get the tool annotations.
	 *
	 * @return array
	 */
	public function get_annotations(): array {
		return array(
			'readonly'    => false,
			'destructive' => false,
		);
	}
}

return new Generate_Llms_Txt();
