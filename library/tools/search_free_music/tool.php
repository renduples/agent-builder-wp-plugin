<?php
/**
 * Tool: search_free_music
 *
 * Search Jamendo for royalty-free audio tracks via the Agentic Video
 * Generation service. Returns direct preview MP3 URLs ready for add_audio_track.
 *
 * @package    Agent_Builder
 * @subpackage Tools
 * @since      3.1.0
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Search Jamendo for royalty-free background music or SFX.
 * Returns preview_url values ready to pass to add_audio_track.
 * Free — no credits charged.
 */
class Search_Free_Music extends \Agentic\Tool_Base {

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'search_free_music';
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return 'Search Jamendo for royalty-free Creative Commons background music. Returns a list of tracks with direct audio_url values (MP3) that can be passed straight to add_audio_track. Free — no credits charged. Use descriptive mood/genre terms like "cinematic dramatic", "upbeat tech corporate", or "ambient calm".';
	}

	/**
	 * Get the tool category.
	 *
	 * @return string
	 */
	public function get_category(): string {
		return 'media';
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
				'query'        => array(
					'type'        => 'string',
					'description' => 'Search terms describing the desired mood, genre, or style. Examples: "cinematic dramatic launch", "upbeat corporate tech", "ambient calm background", "epic orchestral".',
				),
				'duration_min' => array(
					'type'        => 'integer',
					'description' => 'Minimum track duration in seconds. Default 5. Use at least the video length so the track does not need heavy looping.',
					'minimum'     => 1,
					'maximum'     => 600,
				),
				'duration_max' => array(
					'type'        => 'integer',
					'description' => 'Maximum track duration in seconds. Default 120.',
					'minimum'     => 5,
					'maximum'     => 600,
				),
				'limit'        => array(
					'type'        => 'integer',
					'description' => 'Number of results to return (1–15). Default 5. Return 3–5 options so the user can pick.',
					'minimum'     => 1,
					'maximum'     => 15,
				),
			),
			'required'   => array( 'query' ),
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Validated arguments from the LLM.
	 * @return array Result data.
	 */
	public function execute( array $arguments ): array {
		$config = $this->get_videogen_config();
		if ( isset( $config['error'] ) ) {
			return $config;
		}

		$query        = sanitize_text_field( $arguments['query'] ?? '' );
		$duration_min = (int) ( $arguments['duration_min'] ?? 5 );
		$duration_max = (int) ( $arguments['duration_max'] ?? 120 );
		$limit        = (int) ( $arguments['limit'] ?? 5 );

		if ( empty( $query ) ) {
			return array( 'error' => 'query is required.' );
		}

		$params = http_build_query(
			array(
				'query'        => $query,
				'duration_min' => $duration_min,
				'duration_max' => $duration_max,
				'limit'        => $limit,
				'user_id'      => $config['user_id'],
			)
		);

		$response = wp_remote_get(
			\Agentic\Service_Registry::url( 'agentic-videogen', '/search-music?' . $params ),
			array(
				'timeout' => 15,
				'headers' => array(
					'X-API-Key'        => $config['api_key'],
					'X-Plugin-Version' => AGENT_BUILDER_VERSION,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'error' => 'Music search request failed: ' . $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 400 ) {
			$msg = isset( $data['detail'] ) ? (string) $data['detail'] : 'Music search error (HTTP ' . $code . ')';
			return array( 'error' => $msg );
		}

		return is_array( $data ) ? $data : array();
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Read and validate the videogen service configuration.
	 *
	 * @return array Config with api_key + user_id, or array with 'error' key.
	 */
	private function get_videogen_config(): array {
		$api_key = (string) get_option( 'agent_builder_rag_api_secret', '' );
		if ( empty( $api_key ) && defined( 'AGENTIC_RAG_API_KEY' ) ) {
			$api_key = AGENTIC_RAG_API_KEY;
		}
		if ( empty( $api_key ) ) {
			$api_key = (string) ( \Agentic\Provider_Registry::get( 'agentic' )['api_key'] ?? '' );
		}
		$user_id = $api_key;

		if ( empty( $api_key ) ) {
			return array( 'error' => 'Agentic Video Generation requires connecting an Agentic AI account — go to Agent Builder → Settings → Providers and connect Agentic AI.' );
		}

		return array(
			'api_key' => $api_key,
			'user_id' => $user_id,
		);
	}
}

return new Search_Free_Music();
