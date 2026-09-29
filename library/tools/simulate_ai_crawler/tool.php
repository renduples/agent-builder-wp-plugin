<?php
/**
 * Tool: Simulate AI Crawler
 *
 * Fetch a page as each major AI bot and detect server-level blocks.
 *
 * @package Agentic\Tools
 */

declare(strict_types=1);

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Simulates AI crawler requests to detect WAF blocks and content differences.
 */
class Simulate_AI_Crawler extends \Agentic\Tool_Base {

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'simulate_ai_crawler';
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return 'Fetch a page as each major AI bot and compare to a normal browser request. Detects server-level blocks, WAF challenges, content differences, and redirects.';
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
				'url' => array(
					'type'        => 'string',
					'description' => 'The URL to fetch. Defaults to the site homepage.',
				),
			),
		);
	}

	/**
	 * Execute the AI crawler simulation.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array Comparison results for each bot against the browser baseline.
	 */
	public function execute( array $arguments ): array {
		$url       = $arguments['url'] ?? home_url( '/' );
		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
		$req_host  = wp_parse_url( $url, PHP_URL_HOST );

		if ( ! $req_host || strtolower( $req_host ) !== strtolower( $site_host ) ) {
			return array(
				'error' => 'URL must be on the same site (' . $site_host . ') to prevent external requests.',
			);
		}

		$browser_ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';
		$baseline   = wp_remote_get(
			$url,
			array(
				'timeout'    => 15,
				'sslverify'  => false,
				'user-agent' => $browser_ua,
			)
		);

		if ( is_wp_error( $baseline ) ) {
			return array(
				'error' => 'Could not fetch URL as browser: ' . $baseline->get_error_message(),
				'url'   => $url,
			);
		}

		$baseline_status = wp_remote_retrieve_response_code( $baseline );
		$baseline_body   = wp_remote_retrieve_body( $baseline );
		$baseline_length = strlen( $baseline_body );

		$bot_agents = array(
			'GPTBot'          => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.0; +https://openai.com/gptbot)',
			'ChatGPT-User'    => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; ChatGPT-User/1.0; +https://openai.com/bot)',
			'ClaudeBot'       => 'ClaudeBot/1.0 (https://www.anthropic.com)',
			'Google-Extended' => 'Mozilla/5.0 (compatible; Google-Extended)',
			'PerplexityBot'   => 'PerplexityBot/1.0',
		);

		$results = array();
		$blocked = array();

		foreach ( $bot_agents as $bot => $ua ) {
			$response = wp_remote_get(
				$url,
				array(
					'timeout'    => 15,
					'sslverify'  => false,
					'user-agent' => $ua,
				)
			);

			if ( is_wp_error( $response ) ) {
				$err_code   = $response->get_error_code();
				$is_timeout = in_array( $err_code, array( 'http_request_failed', 'request_timeout' ), true )
					&& stripos( $response->get_error_message(), 'timed out' ) !== false;

				$results[ $bot ] = array(
					'status'  => $is_timeout ? 'timeout' : 'error',
					'error'   => $response->get_error_message(),
					'blocked' => ! $is_timeout,
				);
				if ( ! $is_timeout ) {
					$blocked[] = $bot;
				}
				continue;
			}

			$status     = wp_remote_retrieve_response_code( $response );
			$body       = wp_remote_retrieve_body( $response );
			$body_len   = strlen( $body );
			$is_blocked = false;
			$notes      = array();

			if ( $status !== $baseline_status ) {
				$is_blocked = in_array( $status, array( 403, 406, 429, 503 ), true );
				$notes[]    = "Status {$status} (browser got {$baseline_status})";
			}

			if ( 403 === $status || ( $body_len < $baseline_length * 0.3 && $body_len < 5000 ) ) {
				if ( preg_match( '/cloudflare|challenge-platform|cf-chl-bypass|captcha|blocked|access denied/i', $body ) ) {
					$is_blocked = true;
					$notes[]    = 'WAF challenge or block page detected';
				}
			}

			if ( $baseline_length > 0 && $body_len < $baseline_length * 0.5 && $body_len < ( $baseline_length - 2000 ) ) {
				$notes[] = sprintf(
					'Content %d%% smaller than browser (%d vs %d bytes)',
					(int) round( ( 1 - $body_len / $baseline_length ) * 100 ),
					$body_len,
					$baseline_length
				);
				if ( $body_len < $baseline_length * 0.2 ) {
					$is_blocked = true;
				}
			}

			if ( $is_blocked ) {
				$blocked[] = $bot;
			}

			$results[ $bot ] = array(
				'status'         => $status,
				'content_length' => $body_len,
				'blocked'        => $is_blocked,
				'notes'          => $notes,
			);
		}

		return array(
			'url'              => $url,
			'baseline'         => array(
				'status'         => $baseline_status,
				'content_length' => $baseline_length,
				'user_agent'     => 'Chrome browser',
			),
			'bots'             => $results,
			'blocked_bots'     => $blocked,
			'all_bots_allowed' => empty( $blocked ),
			'summary'          => empty( $blocked )
				? 'All AI crawlers received the same response — no server-level blocks.'
				: count( $blocked ) . ' bot(s) blocked at server level: ' . implode( ', ', $blocked ) . '.',
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

return new Simulate_AI_Crawler();
