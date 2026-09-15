<?php
/**
 * Turnstile — Native Cloudflare Turnstile bot verification.
 *
 * @package    Agent_Builder
 * @subpackage Includes
 * @since      3.3.89
 */

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cloudflare Turnstile (https://developers.cloudflare.com/turnstile/) support:
 * a free, public challenge/response API. Site/secret keys are configured at
 * Agentic > Settings > Security; per-form use is toggled via
 * form_set_spam_protection / Agentic_Native_Forms::META_SPAM.
 */
class Turnstile {

	/**
	 * Cloudflare's script + verification endpoints.
	 *
	 * The challenge script must be loaded live from Cloudflare by design —
	 * it can't be self-hosted/bundled, since the whole point is a live,
	 * server-verified bot challenge. Same accepted pattern as any
	 * reCAPTCHA/hCaptcha integration.
	 */
	// phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Required live third-party bot-verification script; cannot be self-hosted without defeating its purpose.
	const SCRIPT_URL = 'https://challenges.cloudflare.com/turnstile/v0/api.js';
	// phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Server-side verification endpoint for the same Turnstile service, not resource loading.
	const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

	/**
	 * Whether Turnstile is configured and should apply for the current visitor.
	 *
	 * @return bool
	 */
	public static function is_required(): bool {
		if ( '' === self::get_site_key() || '' === self::get_secret_key() ) {
			return false;
		}

		if ( get_option( 'agentic_turnstile_require_all', false ) ) {
			return true;
		}

		if ( get_option( 'agentic_turnstile_require_anonymous', true ) ) {
			return ! is_user_logged_in();
		}

		return false;
	}

	/**
	 * Configured site key (public).
	 *
	 * @return string
	 */
	public static function get_site_key(): string {
		return (string) get_option( 'agentic_turnstile_site_key', '' );
	}

	/**
	 * Configured secret key (private, used server-side only).
	 *
	 * @return string
	 */
	private static function get_secret_key(): string {
		return (string) get_option( 'agentic_turnstile_secret_key', '' );
	}

	/**
	 * Enqueue Cloudflare's Turnstile script.
	 *
	 * @param string $handle Script handle.
	 * @param bool   $render When true, load in explicit-render mode (caller
	 *                       manually calls the global `turnstile.render()`,
	 *                       as the chat widget does). When false, Cloudflare's
	 *                       default automatic rendering picks up any
	 *                       `.cf-turnstile` element on the page (native forms).
	 * @return void
	 */
	public static function enqueue_script( string $handle = 'cf-turnstile', bool $render = true ): void {
		if ( wp_script_is( $handle, 'enqueued' ) || wp_script_is( $handle, 'done' ) ) {
			return;
		}

		$src = $render ? add_query_arg( 'render', 'explicit', self::SCRIPT_URL ) : self::SCRIPT_URL;

		wp_enqueue_script( $handle, $src, array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedScriptVersion.NotInFooter, WordPress.WP.EnqueuedScriptVersion.MissingVersion, WordPress.WP.EnqueuedResourceParameters.MissingVersion -- third-party script, version tracked by Cloudflare, not by this plugin.
	}

	/**
	 * Verify a Turnstile response token against Cloudflare's siteverify API.
	 *
	 * @param string          $token   The `cf-turnstile-response` token from the client.
	 * @param int|string|null $user_id Optional user id, sent as Cloudflare's idempotency-free context only (not required by the API).
	 * @return array|null Null when verification passes; array with 'pass' => false
	 *                     and a human-readable 'reason' when it fails.
	 */
	public static function verify( string $token, $user_id = null ): ?array {
		unset( $user_id ); // Not part of Cloudflare's siteverify contract; accepted for call-site compatibility.

		if ( '' === trim( $token ) ) {
			return array(
				'pass'   => false,
				'reason' => __( 'Please complete the verification challenge and try again.', 'agent-builder' ),
			);
		}

		$secret_key = self::get_secret_key();
		if ( '' === $secret_key ) {
			// Nothing configured to verify against — fail closed rather than
			// silently accept an unverifiable token.
			return array(
				'pass'   => false,
				'reason' => __( 'Bot verification is not fully configured. Please try again later.', 'agent-builder' ),
			);
		}

		$response = wp_remote_post(
			self::VERIFY_URL,
			array(
				'timeout' => 10,
				'body'    => array(
					'secret'   => $secret_key,
					'response' => $token,
					'remoteip' => Chat_Security::get_client_ip(),
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return array(
				'pass'   => false,
				'reason' => __( 'Could not verify your request. Please try again.', 'agent-builder' ),
			);
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || empty( $data['success'] ) ) {
			return array(
				'pass'   => false,
				'reason' => __( 'Security verification failed. Please try again.', 'agent-builder' ),
			);
		}

		return null;
	}
}
