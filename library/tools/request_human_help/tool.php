<?php
/**
 * Tool: request_human_help
 *
 * Escalate a conversation to a human being.
 *
 * @package    Agent_Builder
 * @subpackage Tools
 * @since      2.11.0
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hand the conversation to a person.
 *
 * The free plugin owns the event; delivery is somebody else's job. This tool
 * builds the payload, records it in the audit log, and passes it through the
 * `agentic_human_escalation` filter. Siteloom Pro subscribes to that
 * filter and fans the escalation out to Slack and to a configured recipient.
 * With no subscriber, we fall back to emailing the site administrator so the
 * free plugin still does something useful on its own.
 *
 * Note on naming: an "escalation" hands the conversation to a *person*. It is
 * unrelated to a delegation handoff, which hands the conversation to another
 * *agent* (see Agent_Prompt_Builder's $handoff_from / $handoff_context).
 */
class Request_Human_Help extends \Agentic\Tool_Base {

	/**
	 * How long one user must wait between escalations, in seconds.
	 *
	 * An agent that cannot solve a problem will happily ask for help on every
	 * turn. Without this, a stuck conversation pages the team once a second.
	 */
	private const THROTTLE_SECONDS = 900;

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'request_human_help';
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return 'Escalate the current conversation to a human on the site team. Use this when the user explicitly asks to speak to a person, when you have tried and cannot resolve their problem, or when the request needs a decision you are not allowed to make. Notifies the team on whichever channels the site has configured. Write the summary yourself — the person reading it will not see the rest of the conversation.';
	}

	/**
	 * Get the tool category.
	 *
	 * @return string
	 */
	public function get_category(): string {
		return 'agents';
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
				'reason'  => array(
					'type'        => 'string',
					'description' => 'Why a human is needed, in one sentence. E.g. "User asked to speak to a person" or "Refund request needs approval".',
				),
				'summary' => array(
					'type'        => 'string',
					'description' => 'What the person needs to know to pick up where you left off: what the user wants, what you already tried, and what is blocking you. The recipient cannot see the conversation.',
				),
				'urgency' => array(
					'type'        => 'string',
					'enum'        => array( 'low', 'normal', 'high' ),
					'description' => 'How quickly a person should respond. Default normal. Use high only when the user is blocked or money or security is involved.',
				),
			),
			'required'   => array( 'reason', 'summary' ),
		);
	}

	/**
	 * Read-only from WordPress's point of view; it only sends a notification.
	 *
	 * @return array{readonly: bool, destructive: bool, idempotent: bool}
	 */
	public function get_annotations(): array {
		return array(
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		);
	}

	/**
	 * Notify a human.
	 *
	 * @param array $arguments Validated arguments from the LLM.
	 * @return array
	 */
	public function execute( array $arguments ): array {
		$reason  = trim( (string) ( $arguments['reason'] ?? '' ) );
		$summary = trim( (string) ( $arguments['summary'] ?? '' ) );
		$urgency = (string) ( $arguments['urgency'] ?? 'normal' );

		if ( '' === $reason || '' === $summary ) {
			return $this->tool_error( 'missing_context', 'Both reason and summary are required — the person receiving this cannot see the conversation.' );
		}

		if ( ! in_array( $urgency, array( 'low', 'normal', 'high' ), true ) ) {
			$urgency = 'normal';
		}

		$user_id = get_current_user_id();

		$throttle_key = 'agentic_escalation_' . md5( $user_id . '|' . $this->get_calling_agent_slug() );
		if ( get_transient( $throttle_key ) ) {
			return $this->tool_error(
				'already_escalated',
				'A human was already notified about this conversation recently. Tell the user someone will get back to them, and do not escalate again.'
			);
		}

		// Empty outside an agent turn — direct REST or CLI invocation.
		$agent = $this->get_calling_agent_slug();
		if ( '' === $agent ) {
			$agent = 'an agent';
		}

		$payload = array(
			'agent'     => $agent,
			'reason'    => $reason,
			'summary'   => $summary,
			'urgency'   => $urgency,
			'user_id'   => $user_id,
			'user_name' => $user_id ? ( wp_get_current_user()->display_name ?? '' ) : '',
			'site_url'  => home_url(),
			'timestamp' => current_time( 'mysql' ),
		);

		/**
		 * Filters the escalation payload before it is delivered.
		 *
		 * @param array $payload Escalation details.
		 */
		$payload = apply_filters( 'agentic_human_escalation_payload', $payload );

		/**
		 * Delivers an escalation to a human.
		 *
		 * Subscribers append one result each:
		 *   [ 'channel' => 'slack', 'success' => true ]
		 *   [ 'channel' => 'email', 'success' => false, 'error' => 'no_recipient_configured' ]
		 *
		 * Siteloom Pro attaches its Slack and email channels here.
		 *
		 * @param array $results Delivery results, one per channel.
		 * @param array $payload Escalation details.
		 */
		$results = (array) apply_filters( 'agentic_human_escalation', array(), $payload );

		if ( ! $this->any_delivered( $results ) ) {
			$fallback = $this->deliver_by_email( $payload );
			if ( $fallback ) {
				$results[] = $fallback;
			}
		}

		$delivered = $this->any_delivered( $results );

		( new \Agentic\Audit_Log() )->log(
			$payload['agent'],
			'human_escalation',
			'conversation',
			array(
				'urgency'   => $urgency,
				'delivered' => $delivered,
				'channels'  => array_values( wp_list_pluck( $results, 'channel' ) ),
			),
			$reason
		);

		if ( ! $delivered ) {
			return $this->tool_error(
				'no_channel_configured',
				'This site has no way to reach a human: no notification channel is configured and the fallback email could not be sent. Tell the user plainly that you cannot pass this on, and suggest they contact the site owner directly.'
			);
		}

		set_transient( $throttle_key, 1, self::THROTTLE_SECONDS );

		return $this->success(
			array(
				'delivered' => true,
				'channels'  => array_values( wp_list_pluck( array_filter( $results, static fn( $r ) => ! empty( $r['success'] ) ), 'channel' ) ),
				'message'   => 'A human has been notified. Tell the user someone will follow up, and do not call this tool again for this conversation.',
			)
		);
	}

	/**
	 * Whether any channel accepted the escalation.
	 *
	 * @param array $results Delivery results.
	 * @return bool
	 */
	private function any_delivered( array $results ): bool {
		foreach ( $results as $result ) {
			if ( ! empty( $result['success'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Last resort: email the site administrator.
	 *
	 * Keeps the free plugin honest — an agent that promises to fetch a human
	 * has to actually reach one. Return an empty string from the filter to
	 * disable the fallback entirely.
	 *
	 * @param array $payload Escalation details.
	 * @return array|null Delivery result, or null when the fallback is disabled.
	 */
	private function deliver_by_email( array $payload ): ?array {
		/**
		 * Filters the fallback escalation recipient.
		 *
		 * @param string $email   Defaults to the site administrator.
		 * @param array  $payload Escalation details.
		 */
		$to = (string) apply_filters( 'agentic_escalation_fallback_email', get_option( 'admin_email', '' ), $payload );

		if ( '' === $to || ! is_email( $to ) ) {
			return null;
		}

		$subject = sprintf(
			/* translators: 1: urgency, 2: agent slug */
			__( '[%1$s] %2$s needs a human', 'siteloom' ),
			strtoupper( $payload['urgency'] ),
			$payload['agent']
		);

		$body = sprintf(
			/* translators: 1: reason, 2: summary, 3: user name, 4: site url, 5: timestamp */
			__(
				"An agent asked for a person to take over.\n\nReason: %1\$s\n\nSummary:\n%2\$s\n\nUser: %3\$s\nSite: %4\$s\nTime: %5\$s\n",
				'siteloom'
			),
			$payload['reason'],
			$payload['summary'],
			'' !== $payload['user_name'] ? $payload['user_name'] : __( 'not signed in', 'siteloom' ),
			$payload['site_url'],
			$payload['timestamp']
		);

		$sent = wp_mail( $to, $subject, $body );

		return array(
			'channel' => 'admin_email',
			'success' => (bool) $sent,
			'error'   => $sent ? '' : 'wp_mail_failed',
		);
	}
}

return new Request_Human_Help();
