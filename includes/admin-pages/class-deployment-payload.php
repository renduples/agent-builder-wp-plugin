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
			'title'       => __( 'Publish', 'siteloom' ),
			'description' => __( 'Deploy agents to shortcodes, blocks, and channels.', 'siteloom' ),
			'links'       => array(
				array(
					'label' => __( 'Shortcodes', 'siteloom' ),
					'url'   => admin_url( 'admin.php?page=agentic-deployment' ),
					'hint'  => __( 'Embed chat on any page', 'siteloom' ),
				),
				array(
					'label' => __( 'Open Agent Chat', 'siteloom' ),
					'url'   => admin_url( 'admin.php?page=agentic-chat' ),
					'hint'  => __( 'Test agents in wp-admin', 'siteloom' ),
				),
				array(
					'label' => __( 'Train an Agent', 'siteloom' ),
					'url'   => admin_url( 'admin.php?page=agentic-agent-wizard' ),
					'hint'  => __( 'Wizard to create a new agent', 'siteloom' ),
				),
			),
			'legacy_note' => __( 'Full deployment editor (shortcode builder, CLI, modals) remains available when you open a deployment action from here.', 'siteloom' ),
		);
	}
}
