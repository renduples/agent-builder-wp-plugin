<?php
/**
 * Skills_Payload builder for admin page REST payload.
 *
 * @package Agent_Builder
 */

declare(strict_types=1);

namespace Agentic\Admin_Pages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Skills_Payload {

	public static function build(): array {
		$is_advanced = class_exists( \Agentic\Admin_Menu_Handler::class )
			? \Agentic\Admin_Menu_Handler::is_advanced_mode( 'skills' )
			: ( 'advanced' === get_option( 'agent_builder_ui_mode', 'basic' ) );

		$source_labels = array(
			'core'      => __( 'Core', 'agent-builder' ),
			'agentic'   => __( 'Agentic', 'agent-builder' ),
			'clawhub'   => __( 'ClawHub', 'agent-builder' ),
			'wordpress' => __( 'WordPress.org', 'agent-builder' ),
			'anthropic' => __( 'Anthropic', 'agent-builder' ),
			'draft'     => __( 'Draft', 'agent-builder' ),
		);

		// Map agent slugs to their display names for the Agent column, same
		// as the classic admin/skills.php list did, so an assigned skill
		// shows "Content Writer" rather than the raw "content-writer" slug.
		$agent_instances = class_exists( '\Agentic_Agent_Registry' )
			? \Agentic_Agent_Registry::get_instance()->get_all_instances()
			: array();

		$skills = class_exists( \Agentic\Skills_Registry::class ) ? \Agentic\Skills_Registry::get_all() : array();
		$rows   = array();
		foreach ( $skills as $skill ) {
			$id          = (int) ( $skill['id'] ?? 0 );
			$source      = (string) ( $skill['source'] ?? 'local' );
			$agent_slugs = \Agentic\Skills_Registry::decode_agent_slugs( (string) ( $skill['agent_slug'] ?? '' ) );
			$agent_names = array_map(
				static function ( $slug ) use ( $agent_instances ) {
					$agent = $agent_instances[ $slug ] ?? null;
					return $agent ? $agent->get_name() : ucwords( str_replace( '-', ' ', $slug ) );
				},
				$agent_slugs
			);
			$row         = array(
				'id'        => (string) $id,
				'title'     => (string) ( $skill['name'] ?? '' ),
				'subtitle'  => (string) ( $skill['description'] ?? '' ),
				'agent'     => implode( ', ', $agent_names ),
				'enabled'   => ! empty( $skill['enabled'] ),
				'version'   => (string) ( $skill['version'] ?? '' ),
				'source'    => $source,
				'edit_url'  => admin_url( 'admin.php?page=agentic-skills&skill_view=edit&skill_id=' . $id ),
				'delete_id' => $id,
			);
			// Source label and export are Advanced-only, matching the classic
			// Skills admin page's Basic/Advanced split. `source` itself is always
			// present so the React view can split draft rows out into the Drafts
			// section regardless of mode.
			if ( $is_advanced ) {
				$row['source_label'] = $source_labels[ $source ] ?? __( 'Local', 'agent-builder' );
				$row['export_url']   = wp_nonce_url( admin_url( 'admin-post.php?action=agentic_export_skill&skill_id=' . $id ), 'agentic_export_skill' );
			}
			$rows[] = $row;
		}

		// In Basic mode the React view swaps to a chat with the bundled Skills
		// Assistant instead of the table — resolve its display details here so
		// the client doesn't need a second round-trip just to render a header.
		$assistant = null;
		if ( ! $is_advanced ) {
			$instance  = class_exists( '\Agentic_Agent_Registry' )
				? \Agentic_Agent_Registry::get_instance()->get_agent_instance( 'skills-assistant' )
				: null;
			$assistant = $instance
				? array(
					'active'            => true,
					'id'                => $instance->get_id(),
					'name'              => $instance->get_name(),
					'icon'              => $instance->get_icon(),
					'welcome_message'   => $instance->get_welcome_message(),
					'suggested_prompts' => $instance->get_suggested_prompts(),
				)
				: array( 'active' => false );
		}

		return array(
			'page'        => 'skills',
			'title'       => __( 'Skills', 'agent-builder' ),
			'description' => __( 'Instructions that teach agents when and how to use tools.', 'agent-builder' ),
			'rows'        => $rows,
			'is_advanced' => $is_advanced,
			'assistant'   => $assistant,
			'actions'     => array(
				array(
					'label'   => __( 'Create Skill', 'agent-builder' ),
					'url'     => admin_url( 'admin.php?page=agentic-skills&skill_view=new' ),
					'primary' => true,
				),
				array(
					'label' => __( 'Import SKILL.md', 'agent-builder' ),
					'url'   => admin_url( 'admin.php?page=agentic-skills&skill_view=new' ),
				),
				array(
					'label' => __( 'Teach a task', 'agent-builder' ),
					'url'   => admin_url( 'admin.php?page=agentic-chat&teach_task=1' ),
				),
				array(
					'label' => __( 'Browse Community', 'agent-builder' ),
					'url'   => admin_url( 'admin.php?page=agentic-skills&skill_view=hub' ),
				),
			),
		);
	}
}
