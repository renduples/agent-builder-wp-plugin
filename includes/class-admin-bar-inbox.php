<?php
/**
 * Admin-bar inbox node.
 *
 * Adds a "Tasks" node to the admin bar showing the current user's unread
 * notification count (badge only when > 0), linking to the Tasks screen.
 *
 * @package    Agent_Builder
 * @subpackage Includes
 * @since      4.1.0
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin-bar inbox node.
 */
class Admin_Bar_Inbox {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_bar_menu', array( __CLASS__, 'add_node' ), 90 );
	}

	/**
	 * Add the inbox node to the admin bar.
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar Admin bar instance.
	 * @return void
	 */
	public static function add_node( \WP_Admin_Bar $wp_admin_bar ): void {
		if ( ! current_user_can( 'agent_builder_view_dashboard' ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}

		$count = Notifications::unread_count( $user_id );

		$title  = '<span class="ab-icon dashicons dashicons-bell" style="font-size: 18px; line-height: 1.3;"></span>';
		$title .= '<span class="ab-label">' . esc_html__( 'Tasks', 'agent-builder' ) . '</span>';
		if ( $count > 0 ) {
			$title .= '<span class="agentic-inbox-badge" style="display:inline-block;margin-left:5px;background:#d63638;color:#fff;border-radius:9px;padding:0 6px;font-size:11px;line-height:18px;font-weight:600;">' . number_format_i18n( $count ) . '</span>';
		}

		$wp_admin_bar->add_node(
			array(
				'id'    => 'agentic-inbox',
				'title' => $title,
				'href'  => admin_url( 'admin.php?page=agentic-tasks' ),
				'meta'  => array(
					'title' => __( 'Tasks and notifications', 'agent-builder' ),
				),
			)
		);
	}
}
