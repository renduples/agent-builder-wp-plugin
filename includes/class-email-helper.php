<?php
/**
 * Branded HTML email helper.
 *
 * Provides a reusable HTML email wrapper with Agentic branding
 * for all outgoing plugin emails (cost alerts, notifications, etc.).
 *
 * @package    Agent_Builder
 * @subpackage Includes
 * @since      2.8.9
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Branded HTML email helper.
 *
 * @package Agent_Builder
 */
class Email_Helper {

	/**
	 * Send a branded HTML email.
	 *
	 * @param string $to      Recipient email address.
	 * @param string $subject Email subject line.
	 * @param array  $args    Complex args array (see below for shape).
	 *     @type string $heading Heading text shown in the header bar.
	 *     @type string $body    HTML body content (rendered inside the card).
	 *     @type string $footer  Optional footer text. Defaults to site name.
	 * }
	 * @return bool Whether wp_mail() reported success.
	 */
	public static function send( string $to, string $subject, array $args ): bool {
		$heading = $args['heading'] ?? $subject;
		$body    = $args['body'] ?? '';
		$footer  = $args['footer'] ?? get_bloginfo( 'name' );

		$html = self::wrap( $heading, $body, $footer );

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
		);

		// A replacement wp_mail() (SMTP plugins) may return null; never let that
		// become a TypeError that kills the notification cron.
		return (bool) wp_mail( $to, $subject, $html, $headers );
	}

	/**
	 * Generate a CTA button for use inside email body HTML.
	 *
	 * @param string $url   Button link URL.
	 * @param string $label Button text.
	 * @return string HTML string.
	 */
	public static function button( string $url, string $label ): string {
		return '<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:24px 0;">'
			. '<tr><td style="border-radius:6px;background:#0073aa;">'
			. '<a href="' . esc_url( $url ) . '" target="_blank" '
			. 'style="display:inline-block;padding:12px 28px;font-size:14px;font-weight:600;'
			. 'color:#ffffff;text-decoration:none;border-radius:6px;">'
			. esc_html( $label ) . '</a>'
			. '</td></tr></table>';
	}

	/**
	 * Wrap body content in the branded HTML email template.
	 *
	 * @param string $heading Header bar text.
	 * @param string $body    Inner HTML content.
	 * @param string $footer  Footer text.
	 * @return string Complete HTML document.
	 */
	private static function wrap( string $heading, string $body, string $footer ): string {
		$logo_url = defined( 'AGENT_BUILDER_URL' ) ? AGENT_BUILDER_URL . 'assets/icon.svg' : '';

		$html  = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">';
		$html .= '<meta name="viewport" content="width=device-width,initial-scale=1.0">';
		$html .= '</head><body style="margin:0;padding:0;background:#f1f1f1;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Oxygen,Ubuntu,sans-serif;">';

		// Outer table for centering.
		$html .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#f1f1f1;padding:32px 0;">';
		$html .= '<tr><td align="center">';

		// Inner card.
		$html .= '<table role="presentation" width="600" cellspacing="0" cellpadding="0" border="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.1);">';

		// Header bar.
		$html .= '<tr><td style="background:#1d2327;padding:20px 32px;">';
		$html .= '<table role="presentation" cellspacing="0" cellpadding="0" border="0"><tr>';
		if ( $logo_url ) {
			$html .= '<td style="padding-right:12px;vertical-align:middle;"><img src="' . esc_url( $logo_url ) . '" width="28" height="28" alt="" style="display:block;"></td>';
		}
		$html .= '<td style="vertical-align:middle;"><span style="font-size:16px;font-weight:600;color:#ffffff;">' . esc_html( $heading ) . '</span></td>';
		$html .= '</tr></table>';
		$html .= '</td></tr>';

		// Body content.
		$html .= '<tr><td style="padding:28px 32px;font-size:14px;line-height:1.6;color:#1d2327;">';
		$html .= $body;
		$html .= '</td></tr>';

		// Footer.
		$html .= '<tr><td style="padding:16px 32px;border-top:1px solid #e0e0e0;font-size:12px;color:#787c82;text-align:center;">';
		$html .= esc_html( $footer );
		$html .= '</td></tr>';

		// Close card.
		$html .= '</table>';

		// Close outer.
		$html .= '</td></tr></table>';
		$html .= '</body></html>';

		return $html;
	}
}
