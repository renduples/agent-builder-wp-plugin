<?php
/**
 * Tool: report_issue
 *
 * Diagnoses recent errors on this site (AI connectivity, credits, tool failures,
 * runtime errors) and — with the user's go-ahead — reports them to
 * agentic-plugin.com support over the marketplace API, returning a ticket ref.
 *
 * Available to every agent so any assistant can self-diagnose and offer to file
 * a report when something goes wrong.
 *
 * @package    Agent_Builder
 * @subpackage Tools
 * @since      3.0.0
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Diagnose recent errors and optionally report them to support.
 */
class Report_Issue extends \Agentic\Tool_Base {

	/**
	 * Tool name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'report_issue';
	}

	/**
	 * Tool description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return 'Use this whenever the user says the AI, an agent, a tool, or Agent Builder itself is broken, erroring, or misbehaving and wants it reported to Agent Builder support at agentic-plugin.com. It diagnoses recent errors (AI connectivity, credit balance, tool failures, runtime errors) and files a support report. Prefer this over request_human_help for any AI/plugin/technical problem — request_human_help is only for pulling in a human on THIS site to help with a content or support task, not for reporting bugs. Admins only: call send=false first to show the diagnosis, then send=true to file it and return a ticket reference. For a non-admin / public visitor it returns only a friendly notice with no internal details.';
	}

	/**
	 * Category.
	 *
	 * @return string
	 */
	public function get_category(): string {
		return 'wordpress';
	}

	/**
	 * Parameter schema.
	 *
	 * @return array
	 */
	public function get_parameters(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'send'        => array(
					'type'        => 'boolean',
					'description' => 'false (default) = diagnose only and return findings for the user to review. true = send the report to agentic-plugin.com support and return a ticket reference.',
				),
				'description' => array(
					'type'        => 'string',
					'description' => 'Optional: what the user was doing when the problem happened.',
				),
			),
		);
	}

	/**
	 * Annotations — this tool can transmit data externally (when send=true).
	 *
	 * @return array
	 */
	public function get_annotations(): array {
		return array( 'readonly' => false );
	}

	/**
	 * Execute.
	 *
	 * @param array $arguments Validated args.
	 * @return array
	 */
	public function execute( array $arguments ): array {
		// Diagnostics expose internal site details (error messages, versions, config)
		// and file reports to the vendor. Restrict to site admins. A public / front-end
		// visitor (anonymous or non-privileged) gets a friendly, non-technical message
		// instead — never internal diagnostics and never the ability to send a report.
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
			return array(
				'public'  => true,
				'message' => __( "Sorry — something went wrong on this site. I've flagged it so the site's team can look into it. Please try again shortly, or contact the site owner if it keeps happening.", 'agent-builder' ),
			);
		}

		$send        = ! empty( $arguments['send'] );
		$description = isset( $arguments['description'] ) ? (string) $arguments['description'] : '';

		$diag                     = $this->collect_diagnostics();
		$diag['user_description'] = $description;

		if ( ! $send ) {
			return array(
				'diagnosis'  => $diag['summary'],
				'error_type' => $diag['error_type'],
				'severity'   => $diag['severity'],
				'details'    => $diag,
				'next_step'  => 'To send this to agentic-plugin.com support, call report_issue again with send=true. Confirm with the user first.',
			);
		}

		return $this->send_report( $diag );
	}

	/**
	 * Collect a diagnostic snapshot: recent errors, classification, connectivity, env.
	 *
	 * @return array
	 */
	private function collect_diagnostics(): array {
		global $wpdb;

		$errors = array();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- fixed prefix table name.
		$rows = $wpdb->get_results(
			"SELECT agent_id, action, details, created_at FROM {$wpdb->prefix}agent_builder_audit_log
			 WHERE action IN ( 'chat_error', 'tool_error_feedback', 'tool_blocked' )
			 ORDER BY id DESC LIMIT 10",
			ARRAY_A
		);
		foreach ( (array) $rows as $r ) {
			$d        = json_decode( (string) $r['details'], true );
			$errors[] = array(
				'when'   => $r['created_at'],
				'agent'  => $r['agent_id'],
				'action' => $r['action'],
				'error'  => $d['error_message'] ?? ( $d['error'] ?? ( $d['reason'] ?? '' ) ),
			);
		}

		$last = $errors[0]['error'] ?? '';

		$error_type = 'none';
		$severity   = 'info';
		if ( '' !== $last ) {
			if ( false !== stripos( $last, 'insufficient credits' ) || false !== stripos( $last, 'balance' ) ) {
				$error_type = 'insufficient_credits';
				$severity   = 'warning';
			} elseif ( false !== stripos( $last, 'invalid api key' ) || false !== stripos( $last, 'api key' ) ) {
				$error_type = 'invalid_api_key';
				$severity   = 'error';
			} elseif ( false !== stripos( $last, 'communicating with ai' ) || false !== stripos( $last, 'timeout' ) || false !== stripos( $last, 'could not' ) || false !== stripos( $last, 'connect' ) ) {
				$error_type = 'connectivity';
				$severity   = 'error';
			} else {
				$error_type = 'tool_or_runtime';
				$severity   = 'error';
			}
		}

		// Live connectivity probe to the inference proxy.
		$connectivity = 'unknown';
		if ( class_exists( '\Agentic\Service_Registry' ) ) {
			$health = wp_remote_get(
				\Agentic\Service_Registry::url( 'agentic-chat', '/health' ),
				array( 'timeout' => 5 )
			);
			$connectivity = ( ! is_wp_error( $health ) && 200 === (int) wp_remote_retrieve_response_code( $health ) )
				? 'ok'
				: 'unreachable';
		}

		$provider = get_option( 'agent_builder_llm_provider', 'agentic' );

		$summary = $this->summarize( $error_type, $last, $connectivity, (string) $provider );

		return array(
			'summary'        => $summary,
			'error_type'     => $error_type,
			'severity'       => $severity,
			'last_error'     => $last,
			'recent_errors'  => $errors,
			'connectivity'   => $connectivity,
			'provider'       => $provider,
			'plugin_version' => defined( 'AGENT_BUILDER_VERSION' ) ? AGENT_BUILDER_VERSION : '',
			'wp_version'     => get_bloginfo( 'version' ),
			'php_version'    => PHP_VERSION,
		);
	}

	/**
	 * Build a plain-English diagnosis.
	 *
	 * @param string $error_type   Classified type.
	 * @param string $last         Last error text.
	 * @param string $connectivity ok|unreachable|unknown.
	 * @param string $provider     Active provider slug.
	 * @return string
	 */
	private function summarize( string $error_type, string $last, string $connectivity, string $provider ): string {
		switch ( $error_type ) {
			case 'none':
				return 'unreachable' === $connectivity
					? 'No recent agent errors were logged, but the AI service looks unreachable from this site. This is usually a network/firewall issue or the service being temporarily down.'
					: 'No recent errors detected. The AI service is reachable and things look healthy.';
			case 'insufficient_credits':
				return 'You have run out of AI credits (the hosted AI service reports a zero balance). Add credits at agentic-plugin.com, or switch to your own API key in Settings.';
			case 'invalid_api_key':
				return 'The AI API key was rejected. Check the key in Settings > Agentic, or re-register for a new free key.';
			case 'connectivity':
				return sprintf(
					'The site could not reach the AI service (%s). This is usually a network, firewall, or timeout issue. Recent error: %s',
					'unreachable' === $connectivity ? 'connectivity probe also failed' : 'proxy reachable but the request failed',
					$last
				);
			default:
				return sprintf( 'A recent error occurred using the "%s" provider: %s', $provider, $last );
		}
	}

	/**
	 * Send the diagnostic report to agentic-plugin.com support.
	 *
	 * @param array $diag Diagnostics snapshot.
	 * @return array
	 */
	private function send_report( array $diag ): array {
		if ( ! class_exists( '\Agentic\Service_Registry' ) ) {
			return array( 'error' => 'Reporting is unavailable on this install.' );
		}

		$endpoint = \Agentic\Service_Registry::url( 'agentic-api', '/wp-json/agentic/v1/report-issue' );
		if ( '' === $endpoint ) {
			return array( 'error' => 'Support endpoint is not configured.' );
		}

		// The site is identified by its URL (and license key if present). We do NOT
		// transmit the provider API key.
		$license = \Agentic\Provider_Registry::get_license_key();

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 12,
				'body'    => array(
					'error_type'  => $diag['error_type'],
					'severity'    => $diag['severity'],
					'message'     => '' !== $diag['last_error'] ? $diag['last_error'] : $diag['summary'],
					'site_url'    => home_url(),
					'agent_id'    => '',
					'license_key' => $license,
					'context'     => wp_json_encode( $diag ),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'error'     => 'Could not reach support: ' . $response->get_error_message(),
				'diagnosis' => $diag['summary'],
			);
		}

		$data   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$ticket = is_array( $data ) ? ( $data['ticket'] ?? '' ) : '';

		if ( '' === $ticket ) {
			return array(
				'sent'      => false,
				'error'     => 'The report could not be filed (HTTP ' . (int) wp_remote_retrieve_response_code( $response ) . ').',
				'diagnosis' => $diag['summary'],
			);
		}

		return array(
			'sent'      => true,
			'ticket'    => $ticket,
			'message'   => sprintf( 'Reported to agentic-plugin.com support. Your reference is %s.', $ticket ),
			'diagnosis' => $diag['summary'],
		);
	}
}

return new Report_Issue();
