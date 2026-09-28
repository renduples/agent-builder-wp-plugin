<?php
/**
 * Deployment_Payload builder for admin page REST payload.
 *
 * @package Agent_Builder
 */

declare(strict_types=1);

namespace Agentic\Admin_Pages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Deployment_Payload {

	public static function build(): array {
		return array(
			'page'        => 'deployment',
			'title'       => __( 'Publish', 'agent-builder' ),
			'description' => __( 'Deploy agents to shortcodes, blocks, and channels.', 'agent-builder' ),
			'links'       => array(
				array(
					'label' => __( 'Shortcodes', 'agent-builder' ),
					'url'   => admin_url( 'admin.php?page=agentic-deployment' ),
					'hint'  => __( 'Embed chat on any page', 'agent-builder' ),
				),
				array(
					'label' => __( 'Open Agent Chat', 'agent-builder' ),
					'url'   => admin_url( 'admin.php?page=agentic-chat' ),
					'hint'  => __( 'Test agents in wp-admin', 'agent-builder' ),
				),
				array(
					'label' => __( 'Train an Agent', 'agent-builder' ),
					'url'   => admin_url( 'admin.php?page=agentic-agent-wizard' ),
					'hint'  => __( 'Wizard to create a new agent', 'agent-builder' ),
				),
			),
			'legacy_note' => __( 'Full deployment editor (shortcode builder, CLI, modals) remains available when you open a deployment action from here.', 'agent-builder' ),
		);
	}
}
