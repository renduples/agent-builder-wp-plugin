<?php
/**
 * Agents_Payload builder for the Agents admin React surface.
 *
 * Enumerates every registered agent (the same registry walk the classic
 * admin/agents.php and Admin_Profiles use), merges each agent's manifest
 * identity with its Agent_Profile overrides, and annotates each row with its
 * activation state and source (bundled vs user-created) so the card grid and
 * the Advanced table can share one row shape.
 *
 * @package Agent_Builder
 */

declare(strict_types=1);

namespace Agentic\Admin_Pages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Agents_Payload {

	/**
	 * Build the Agents page payload.
	 *
	 * @return array<string, mixed>
	 */
	public static function build(): array {
		$installed = array();
		if ( class_exists( '\Agentic_Agent_Registry' ) ) {
			$installed = \Agentic_Agent_Registry::get_instance()->get_installed_agents( true );
		}

		$agents = array();
		foreach ( $installed as $slug => $info ) {
			if ( ! is_array( $info ) ) {
				continue;
			}

			$profile = \Agentic\Agent_Profile::get( (string) $slug );

			$agents[] = array_merge(
				$profile,
				array(
					'version' => (string) ( $info['version'] ?? '' ),
					'author'  => (string) ( $info['author'] ?? '' ),
					'active'  => (bool) ( $info['active'] ?? false ),
					'source'  => ! empty( $info['bundled'] ) ? 'bundled' : 'user',
				)
			);
		}

		// Pinned first, then by order; hidden agents last within their
		// pinned/unpinned group. The React view partitions hidden rows into a
		// collapsed group at the end, so this ordering only ever governs the
		// visible cards (pinned before the rest) and the Advanced table.
		usort(
			$agents,
			static function ( array $a, array $b ): int {
				$a_pinned = $a['pinned'] ? 1 : 0;
				$b_pinned = $b['pinned'] ? 1 : 0;
				if ( $a_pinned !== $b_pinned ) {
					return $b_pinned - $a_pinned;
				}

				$a_hidden = $a['hidden'] ? 1 : 0;
				$b_hidden = $b['hidden'] ? 1 : 0;
				if ( $a_hidden !== $b_hidden ) {
					return $a_hidden - $b_hidden;
				}

				return ( (int) $a['order'] ) - ( (int) $b['order'] );
			}
		);

		$is_advanced = class_exists( '\Agentic\Admin_Menu_Handler'::class )
			? \Agentic\Admin_Menu_Handler::is_advanced_mode( 'agents' )
			: ( 'advanced' === get_option( 'agent_builder_ui_mode', 'basic' ) );

		return array(
			'page'         => 'agents',
			'title'        => __( 'Agents', 'agent-builder' ),
			'description'  => __( 'Your agents at a glance. Pin the ones you reach for, hide the ones you do not, and create, duplicate, or import more.', 'agent-builder' ),
			'agents'       => $agents,
			'is_advanced'  => $is_advanced,
			'ui_mode'      => $is_advanced ? 'advanced' : 'basic',
			'is_admin'     => current_user_can( 'manage_options' ),
			'import_nonce' => wp_create_nonce( 'agentic_import_agent' ),
			'import_url'   => admin_url( 'admin-post.php' ),
		);
	}
}
