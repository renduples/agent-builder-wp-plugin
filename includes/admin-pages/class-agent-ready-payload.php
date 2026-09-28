<?php
/**
 * Agent_Ready_Payload builder for admin page REST payload.
 *
 * @package Agent_Builder
 */

declare(strict_types=1);

namespace Agentic\Admin_Pages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Agent_Ready_Payload {

	public static function build(): array {
		$is_advanced = class_exists( \Agentic\Admin_Menu_Handler::class )
			? \Agentic\Admin_Menu_Handler::is_advanced_mode( 'agent-ready' )
			: ( 'advanced' === get_option( 'agent_builder_ui_mode', 'basic' ) );

		$payload = array(
			'page'           => 'agent-ready',
			'title'          => __( 'Site Passport', 'agent-builder' ),
			'panel_title'    => __( 'Score & fixes', 'agent-builder' ),
			'description'    => __( 'Your site\'s passport for AI agents — what they can discover, and what they can access.', 'agent-builder' ),
			'is_advanced'    => $is_advanced,
			'score'          => class_exists( \Agentic\Agent_Ready_Score::class ) ? \Agentic\Agent_Ready_Score::get_latest() : array(),
			'webmcp_enabled' => class_exists( \Agentic\Webmcp_Bridge::class ) && \Agentic\Webmcp_Bridge::is_enabled(),
		);

		if ( $is_advanced ) {
			$payload['webmcp_matrix']    = class_exists( \Agentic\Abilities_Manifest::class ) ? \Agentic\Abilities_Manifest::get_webmcp_exposed() : array();
			$payload['directory_status'] = get_option( 'agent_builder_directory_submission', array() );
		}

		return $payload;
	}
}
