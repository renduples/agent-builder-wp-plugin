<?php
/**
 * Directory Submission — the one deliberate external call the Agent-Ready
 * Score feature ever makes, and only when an administrator explicitly
 * clicks "Submit to Directory."
 *
 * Never triggered automatically — not by the weekly re-scan cron, not by
 * activation, not by viewing the Agent-Ready page. See readme.txt's "Site
 * Passport Directory (Optional)" External Services entry for the exact
 * payload this sends, and SUBMISSION-NOTES.md for the compliance framing.
 *
 * @package    Agent_Builder
 * @subpackage Includes
 * @since      3.3.90
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Submits this site's URL and a minimal score summary to Site Passport.
 */
class Directory_Submission {

	/**
	 * Directory API endpoint — Site Passport's real, deployed submission
	 * route (verified directly against its source: sitepassport.org's own
	 * api/submit.php).
	 */
	public const SUBMIT_URL = 'https://sitepassport.org/api/submit.php';

	/**
	 * Option storing the last submission's outcome.
	 */
	public const OPTION = 'agent_builder_directory_submission';

	/**
	 * Submit this site to the directory.
	 *
	 * Site Passport's whole trust model is that it never accepts a
	 * self-reported score — submitting one here would be not just the wrong
	 * shape but pointless, since the endpoint ignores any score field and
	 * always computes its own by live-checking this site's own public
	 * llms.txt/robots.txt/schema.org/.well-known/webmcp.json itself. So the
	 * only thing this ever sends is the URL; the real score comes back in
	 * the response.
	 *
	 * @return array{submitted_at:string,status:string,slug:?string,score:?int,grade:?string,badge_url:?string,directory_url:?string,error:?string}
	 */
	public static function submit(): array {
		$body = array( 'url' => home_url( '/' ) );

		$response = wp_remote_post(
			self::SUBMIT_URL,
			array(
				'timeout' => 15,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $body ),
			)
		);

		$result = array(
			'submitted_at'  => gmdate( 'Y-m-d H:i:s' ),
			'status'        => 'error',
			'slug'          => null,
			'score'         => null,
			'grade'         => null,
			'badge_url'     => null,
			'directory_url' => null,
			'error'         => null,
		);

		if ( is_wp_error( $response ) ) {
			$result['error'] = $response->get_error_message();
		} else {
			$code = wp_remote_retrieve_response_code( $response );
			$data = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( $code >= 200 && $code < 300 && is_array( $data ) ) {
				$result['status']        = 'submitted';
				$result['slug']          = is_string( $data['slug'] ?? null ) ? $data['slug'] : null;
				$result['score']         = is_int( $data['score'] ?? null ) ? $data['score'] : null;
				$result['grade']         = is_string( $data['grade'] ?? null ) ? $data['grade'] : null;
				$result['badge_url']     = is_string( $data['badge_url'] ?? null ) ? $data['badge_url'] : null;
				$result['directory_url'] = is_string( $data['directory_url'] ?? null ) ? $data['directory_url'] : null;
			} else {
				// The API returns {"error": "..."} on 4xx (bad URL, unreachable, rate-limited) — surface that over a bare status code where available.
				$result['error'] = is_array( $data ) && is_string( $data['error'] ?? null )
					? $data['error']
					: sprintf( 'Directory responded with HTTP %d.', $code );
			}
		}

		update_option( self::OPTION, $result, false );

		return $result;
	}
}
