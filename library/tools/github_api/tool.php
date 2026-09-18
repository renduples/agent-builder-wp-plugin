<?php
/**
 * Tool: github_api
 *
 * @package    Agent_Builder
 * @subpackage Tools
 * @since      2.6.0
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Make an authenticated call to the GitHub REST API.
 */
class Github_Api extends \Agentic\Tool_Base {

	/** GitHub API base URL. */
	private const BASE_URL = 'https://api.github.com';

	/** WordPress option key for the GitHub personal access token. */
	private const TOKEN_OPTION = 'agent_builder_github_token';

	public function get_name(): string {
		return 'github_api';
	}

	public function get_description(): string {
		return 'Make an authenticated call to the GitHub REST API. Supports any endpoint: repos, issues, pull requests, commits, releases, actions, and more. Requires a GitHub personal access token stored in the plugin settings. The endpoint should start with "/" (e.g. "/repos/owner/repo/issues"). Returns the decoded JSON response.';
	}

	public function get_category(): string {
		return 'git';
	}

	public function get_parameters(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'method'   => array(
					'type'        => 'string',
					'enum'        => array( 'GET', 'POST', 'PATCH', 'PUT', 'DELETE' ),
					'description' => 'HTTP method. Use GET for reading, POST to create, PATCH to update, DELETE to remove.',
				),
				'endpoint' => array(
					'type'        => 'string',
					'description' => 'GitHub REST API endpoint path starting with "/", e.g. "/repos/owner/repo/issues" or "/user/repos".',
				),
				'body'     => array(
					'type'        => 'object',
					'description' => 'Request body for POST/PATCH/PUT calls. Pass as a JSON object.',
				),
				'params'   => array(
					'type'                 => 'object',
					'description'          => 'Query string parameters for GET requests, e.g. {"state": "open", "per_page": 20}.',
					'additionalProperties' => array( 'type' => 'string' ),
				),
			),
			'required'   => array( 'method', 'endpoint' ),
		);
	}

	public function execute( array $arguments ): array {
		$token = get_option( self::TOKEN_OPTION, '' );
		if ( '' === $token ) {
			return array(
				'error' => 'No GitHub token configured. There is no settings-screen field for this yet — set one with WP-CLI: wp option update agent_builder_github_token "<your personal access token>".',
			);
		}

		$method   = strtoupper( $arguments['method'] ?? 'GET' );
		$endpoint = $arguments['endpoint'] ?? '';
		$body     = $arguments['body'] ?? null;
		$params   = $arguments['params'] ?? array();

		if ( '' === $endpoint || '/' !== $endpoint[0] ) {
			return array( 'error' => 'endpoint must start with "/" (e.g. "/repos/owner/repo/issues").' );
		}

		$url = self::BASE_URL . $endpoint;

		if ( 'GET' === $method && ! empty( $params ) ) {
			$url = add_query_arg( array_map( 'strval', (array) $params ), $url );
		}

		$request_args = array(
			'method'  => $method,
			'headers' => array(
				'Authorization'        => 'Bearer ' . $token,
				'Accept'               => 'application/vnd.github+json',
				'X-GitHub-Api-Version' => '2022-11-28',
				'User-Agent'           => 'Agent-Builder-WordPress/' . AGENT_BUILDER_VERSION,
			),
			'timeout' => 20,
		);

		if ( null !== $body && in_array( $method, array( 'POST', 'PATCH', 'PUT' ), true ) ) {
			$request_args['headers']['Content-Type'] = 'application/json';
			$request_args['body']                    = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $request_args );

		if ( is_wp_error( $response ) ) {
			return array( 'error' => 'HTTP request failed: ' . $response->get_error_message() );
		}

		$status  = wp_remote_retrieve_response_code( $response );
		$raw     = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $raw, true );

		$result = array(
			'status' => $status,
			'data'   => $decoded ?? $raw,
		);

		if ( $status >= 400 ) {
			$result['error'] = $decoded['message'] ?? "GitHub API returned HTTP {$status}.";
		}

		return $result;
	}

	public function get_annotations(): array {
		return array(
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		);
	}
}

return new Github_Api();
