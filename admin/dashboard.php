<?php
/**
 * Agentic Admin Dashboard — React mount.
 *
 * Full dashboard UI is rendered by build/dashboard-app.js (WordPress components).
 * Data and mutations: REST agentic/v1/dashboard.
 *
 * @package    Agent_Builder
 * @subpackage Admin
 * @since      0.1.0
 *
 * php version 8.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'agent_builder_view_dashboard' ) ) {
	wp_die( esc_html__( 'You do not have permission to access this page.', 'agent-builder' ) );
}

\Agentic\Plugin::get_instance()->load_chat_components();

if ( class_exists( '\Agentic\React_Admin' ) && \Agentic\React_Admin::enqueue( 'dashboard-app' ) ) {
	\Agentic\React_Admin::mount( 'agentic-dashboard-app-root' );
	return;
}

// Fallback if React build is missing.
if ( class_exists( '\Agentic\React_Admin' ) ) {
	\Agentic\React_Admin::missing_build_notice( 'dashboard-app' );
} else {
	echo '<div class="wrap"><p>' . esc_html__( 'Dashboard assets unavailable.', 'agent-builder' ) . '</p></div>';
}
