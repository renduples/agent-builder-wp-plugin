<?php
/**
 * Shared approval/tools profile definitions for the Admin Pages surfaces.
 *
 * These definitions are consumed by both the GET payload builders
 * (Approvals_Payload / Tools_Payload) and the POST action handlers in
 * Admin_Pages_REST. They live here behind a single owner so the displayed
 * cards, the profiles accepted by the actions, and the saved preferences can
 * never drift into two independent copies.
 *
 * @package Agent_Builder
 */

declare(strict_types=1);

namespace Agentic\Admin_Pages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Admin_Profiles {

	/**
	 * Approval comfort-profile cards shown on the Approvals page.
	 *
	 * Each card's `auto_max`/`mode`/`needs_ack` fields are the same values
	 * `Admin_Pages_REST::save_approval_prefs()` persists when a profile is
	 * selected, so GET display and POST action stay on one source of truth.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function approval_comfort_profiles(): array {
		$active = sanitize_key( (string) get_option( 'agent_builder_approval_comfort', 'careful' ) );
		$cards  = array(
			array(
				'id'        => 'careful',
				'icon'      => '🛡️',
				'label'     => __( 'Always ask me', 'agent-builder' ),
				'summary'   => __( 'Safest default', 'agent-builder' ),
				'detail'    => __( 'Important or writing actions wait for you. Best when you want full control.', 'agent-builder' ),
				'risk_note' => __( 'No automatic approvals beyond the safest reads.', 'agent-builder' ),
				'auto_max'  => 'none',
				'mode'      => 'supervised',
				'needs_ack' => false,
			),
			array(
				'id'        => 'balanced',
				'icon'      => '⚖️',
				'label'     => __( 'Auto-approve low risk', 'agent-builder' ),
				'summary'   => __( 'Recommended for most sites', 'agent-builder' ),
				'detail'    => __( 'Simple look-ups run freely. Drafts and bigger changes still pause for confirmation or this queue.', 'agent-builder' ),
				'risk_note' => __( 'You accept that low-risk tools may run without a separate approval email.', 'agent-builder' ),
				'auto_max'  => 'low',
				'mode'      => 'supervised',
				'needs_ack' => false,
			),
			array(
				'id'        => 'hands_off',
				'icon'      => '⚡',
				'label'     => __( 'Trust more (higher risk)', 'agent-builder' ),
				'summary'   => __( 'Faster — use with care', 'agent-builder' ),
				'detail'    => __( 'Agents work with less interruption (autonomous mode). You can still review history. Extreme tools stay blocked.', 'agent-builder' ),
				'risk_note' => __( 'I understand agents may change content without waiting in this queue, and I accept that increased risk.', 'agent-builder' ),
				'auto_max'  => 'medium',
				'mode'      => 'autonomous',
				'needs_ack' => true,
			),
		);
		foreach ( $cards as &$c ) {
			$c['active'] = ( $c['id'] === $active );
		}
		unset( $c );
		return $cards;
	}

	/**
	 * Saved approval preferences, read back for the payload and after a save.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_approval_prefs(): array {
		$email = sanitize_email( (string) get_option( 'agent_builder_approval_email_to', '' ) );
		if ( ! is_email( $email ) ) {
			$email = (string) get_option( 'admin_email' );
		}
		return array(
			'email_notify'  => (bool) get_option( 'agent_builder_approval_email_notify', false ),
			'email_to'      => $email,
			'comfort'       => sanitize_key( (string) get_option( 'agent_builder_approval_comfort', 'careful' ) ),
			'auto_max_risk' => sanitize_key( (string) get_option( 'agent_builder_approval_auto_max_risk', 'none' ) ),
			'risk_ack'      => (bool) get_option( 'agent_builder_approval_risk_ack', false ),
			'agent_mode'    => (string) get_option( 'agent_builder_agent_mode', 'supervised' ),
		);
	}

	/**
	 * Tools ability profiles offered on the Tools page.
	 *
	 * Each profile's `max_risk` is the value `Admin_Pages_REST::apply_tools_profile`
	 * hands to `Tools_Registry::apply_max_risk_level()`, so the displayed cards
	 * and the profiles the action accepts share one definition.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function tools_ability_profiles(): array {
		return array(
			'browse' => array(
				'label'    => __( 'Browse & answer', 'agent-builder' ),
				'summary'  => __( 'Safest — read-only help', 'agent-builder' ),
				'detail'   => __( 'Agents can look things up and answer questions. They cannot change posts, settings, or your site.', 'agent-builder' ),
				'max_risk' => 'low',
				'icon'     => '👀',
			),
			'assist' => array(
				'label'    => __( 'Help with drafts', 'agent-builder' ),
				'summary'  => __( 'Balanced — create drafts with care', 'agent-builder' ),
				'detail'   => __( 'Read plus everyday writing (drafts and light edits). Riskier changes still ask for confirmation.', 'agent-builder' ),
				'max_risk' => 'medium',
				'icon'     => '✍️',
			),
			'manage' => array(
				'label'    => __( 'Manage my site', 'agent-builder' ),
				'summary'  => __( 'Full productivity — approvals for big changes', 'agent-builder' ),
				'detail'   => __( 'Most tools on, including significant updates. High-risk actions go through the Approvals queue. Extreme tools stay off.', 'agent-builder' ),
				'max_risk' => 'high',
				'icon'     => '🛠️',
			),
			'custom' => array(
				'label'    => __( 'Custom mix', 'agent-builder' ),
				'summary'  => __( 'You mixed tools manually', 'agent-builder' ),
				'detail'   => __( 'Individual tools were toggled outside a profile.', 'agent-builder' ),
				'max_risk' => 'high',
				'icon'     => '⚙️',
			),
		);
	}
}
