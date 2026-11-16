<?php
/**
 * Tool: Check Technical Readiness
 *
 * Check technical AI readiness signals for the site.
 *
 * @package Agentic\Tools
 */

declare(strict_types=1);

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checks HTTPS, sitemap, noindex, and llms.txt for AI readiness.
 */
class Check_Technical_Readiness extends \Agentic\Tool_Base {

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'check_technical_readiness';
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return 'Check technical AI readiness signals: HTTPS, sitemap.xml, noindex settings, llms.txt presence. Returns a score out of 20.';
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
			'properties' => new \stdClass(),
		);
	}

	/**
	 * Execute the technical readiness check.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array Technical check results with score and issues.
	 */
	public function execute( array $arguments ): array {
		$score   = 0;
		$issues  = array();
		$passing = array();

		$is_https = str_starts_with( home_url(), 'https://' );
		if ( $is_https ) {
			$score    += 6;
			$passing[] = 'Site is served over HTTPS';
		} else {
			$issues[] = array(
				'message'  => 'Site is not using HTTPS.',
				'severity' => 'critical',
			);
		}

		$sitemap_url  = null;
		$sitemap_ok   = false;
		$sitemap_urls = array( '/sitemap.xml', '/sitemap_index.xml', '/wp-sitemap.xml' );

		foreach ( $sitemap_urls as $path ) {
			$response = wp_remote_head(
				home_url( $path ),
				array(
					'timeout'   => 7,
					'sslverify' => false,
				)
			);
			if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
				$sitemap_ok  = true;
				$sitemap_url = home_url( $path );
				break;
			}
		}

		if ( $sitemap_ok ) {
			$score    += 6;
			$passing[] = 'Sitemap found at ' . ( $sitemap_url ?? 'known location' );
		} else {
			$issues[] = array(
				'message'  => 'No sitemap.xml found.',
				'severity' => 'important',
			);
		}

		$search_allowed = (bool) get_option( 'blog_public', 1 );
		if ( $search_allowed ) {
			$score    += 5;
			$passing[] = 'Site is not set to discourage search engines';
		} else {
			$issues[] = array(
				'message'  => '"Discourage search engines" is enabled.',
				'severity' => 'critical',
			);
		}

		$has_llms = '' !== \Agentic\Virtual_Site_Files::llms_txt()['content'];
		if ( $has_llms ) {
			$score    += 3;
			$passing[] = 'llms.txt found';
		} else {
			$issues[] = array(
				'message'  => 'No llms.txt file — low priority but worth adding.',
				'severity' => 'low',
			);
		}

		return array(
			'score'   => min( 20, $score ),
			'max'     => 20,
			'checks'  => array(
				'https'      => $is_https,
				'sitemap'    => $sitemap_ok,
				'searchable' => $search_allowed,
				'llms_txt'   => $has_llms,
			),
			'issues'  => $issues,
			'passing' => $passing,
		);
	}

	/**
	 * Get the tool annotations.
	 *
	 * @return array
	 */
	public function get_annotations(): array {
		return array(
			'readonly' => true,
		);
	}
}

return new Check_Technical_Readiness();
