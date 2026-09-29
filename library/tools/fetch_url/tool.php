<?php
/**
 * Tool: fetch_url
 *
 * Retrieve the text content of an external URL using the WordPress HTTP API.
 *
 * @package    Agent_Builder
 * @subpackage Tools
 * @since      2.7.0
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fetch external URL content via WordPress HTTP API.
 */
class Fetch_Url extends \Agentic\Tool_Base {

	private const MAX_BYTES = 524288; // 512 KB

	public function get_name(): string {
		return 'fetch_url';
	}

	public function get_description(): string {
		return 'Fetch the content of an external URL and return its text. Useful for reading web pages, RSS feeds, JSON APIs, sitemaps, or any public URL. Returns page title, plain text body (HTML stripped), and optionally the raw HTML or JSON. Respects robots.txt is not enforced — only use on URLs you have permission to access.';
	}

	public function get_category(): string {
		return 'web';
	}

	public function get_parameters(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'url'       => array(
					'type'        => 'string',
					'description' => 'The full URL to fetch (must begin with http:// or https://).',
				),
				'format'    => array(
					'type'        => 'string',
					'description' => '"text" (default) — returns plain text with HTML stripped. "html" — returns raw HTML. "json" — parses and returns JSON (for API endpoints).',
				),
				'max_chars' => array(
					'type'        => 'integer',
					'description' => 'Truncate response to this many characters (default 8000, max 40000).',
				),
				'headers'   => array(
					'type'        => 'object',
					'description' => 'Optional extra request headers as key/value pairs, e.g. {"Accept": "application/json"}.',
				),
			),
			'required'   => array( 'url' ),
		);
	}

	public function execute( array $arguments ): array {
		$url    = $arguments['url'] ?? '';
		$format = $arguments['format'] ?? 'text';
		$format = in_array( $format, array( 'text', 'html', 'json' ), true ) ? $format : 'text';

		$max_chars = min( max( (int) ( $arguments['max_chars'] ?? 8000 ), 500 ), 40000 );

		if ( ! str_starts_with( $url, 'http://' ) && ! str_starts_with( $url, 'https://' ) ) {
			return array( 'error' => 'URL must begin with http:// or https://' );
		}

		// Block private/internal addresses.
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		if ( $this->is_private_host( $host ) ) {
			return array( 'error' => 'Fetching internal or private network addresses is not allowed.' );
		}

		$request_args = array(
			'timeout'    => 20,
			'user-agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . get_bloginfo( 'url' ),
			'headers'    => array(),
		);

		if ( ! empty( $arguments['headers'] ) && is_array( $arguments['headers'] ) ) {
			foreach ( $arguments['headers'] as $key => $value ) {
				$request_args['headers'][ sanitize_text_field( (string) $key ) ] = sanitize_text_field( (string) $value );
			}
		}

		$response = wp_remote_get( $url, $request_args );

		if ( is_wp_error( $response ) ) {
			return array( 'error' => 'Request failed: ' . $response->get_error_message() );
		}

		$status_code  = wp_remote_retrieve_response_code( $response );
		$content_type = wp_remote_retrieve_header( $response, 'content-type' );
		$body         = wp_remote_retrieve_body( $response );

		if ( strlen( $body ) > self::MAX_BYTES ) {
			$body = substr( $body, 0, self::MAX_BYTES );
		}

		if ( (int) $status_code >= 400 ) {
			return array(
				'error'       => "HTTP {$status_code} response.",
				'status_code' => (int) $status_code,
				'url'         => $url,
			);
		}

		$result = array(
			'url'          => $url,
			'status_code'  => (int) $status_code,
			'content_type' => $content_type,
		);

		if ( 'json' === $format || str_contains( $content_type, 'json' ) ) {
			$decoded = json_decode( $body, true );
			if ( null !== $decoded ) {
				$encoded          = wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
				$result['data']   = substr( $encoded ? $encoded : '', 0, $max_chars );
				$result['format'] = 'json';
				return $result;
			}
		}

		if ( 'html' === $format ) {
			$result['html']   = substr( $body, 0, $max_chars );
			$result['format'] = 'html';
			return $result;
		}

		// Default: strip HTML to plain text and extract title.
		$title = '';
		if ( preg_match( '/<title[^>]*>(.*?)<\/title>/is', $body, $matches ) ) {
			$title = html_entity_decode( wp_strip_all_tags( $matches[1] ), ENT_QUOTES | ENT_HTML5 );
		}

		// Remove script/style blocks before stripping tags.
		$clean = preg_replace( '/<(script|style)[^>]*>.*?<\/\1>/is', '', $body ) ?? $body;
		$text  = wp_strip_all_tags( $clean );

		// Collapse whitespace.
		$text = preg_replace( '/[ \t]+/', ' ', $text ) ?? $text;
		$text = preg_replace( '/\n{3,}/', "\n\n", trim( $text ) ) ?? $text;

		$truncated = strlen( $text ) > $max_chars;
		$text      = substr( $text, 0, $max_chars );

		$result['title']     = $title;
		$result['text']      = $text;
		$result['format']    = 'text';
		$result['truncated'] = $truncated;

		return $result;
	}

	/**
	 * Block requests to loopback, private ranges, and localhost.
	 */
	private function is_private_host( string $host ): bool {
		if ( in_array( strtolower( $host ), array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
			return true;
		}
		// RFC1918 + link-local ranges via filter_var FILTER_VALIDATE_IP flags.
		$ip = gethostbyname( $host );
		if ( $ip && filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) === false ) {
			return true;
		}
		return false;
	}

	public function get_annotations(): array {
		return array(
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		);
	}
}

return new Fetch_Url();
