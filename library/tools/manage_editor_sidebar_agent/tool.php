<?php
/**
 * Tool: manage_editor_sidebar_agent
 *
 * Configure the AI panel that appears inside the Gutenberg block editor
 * while writing posts/pages — the same settings the classic Deployment →
 * Editor tab manages, via the shared Deployments data layer.
 *
 * @package    Agent_Builder
 * @subpackage Tools
 * @since      3.3.67
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Agentic\Deployments;
use Agentic\Agent_Settings;

/**
 * Manage the block-editor sidebar agent panel.
 */
class Manage_Editor_Sidebar_Agent extends \Agentic\Tool_Base {

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'manage_editor_sidebar_agent';
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return 'Configure the AI sidebar panel that appears inside the WordPress block editor while writing a post or page — with awareness of the current draft. Use get to see current settings, update to change them (which agents appear, which post types show it, whether draft content is shared automatically, and confirmation mode).';
	}

	/**
	 * Get the tool category.
	 *
	 * @return string
	 */
	public function get_category(): string {
		return 'agent-orchestrator';
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
				'action'          => array(
					'type'        => 'string',
					'enum'        => array( 'get', 'update' ),
					'description' => 'get: read current settings. update: change them.',
				),
				'enabled'         => array(
					'type'        => 'boolean',
					'description' => 'Turn the sidebar panel on or off entirely. Required for update.',
				),
				'agent_slugs'     => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => 'Which agents are available in the sidebar. Required for update.',
				),
				'default_agent'   => array(
					'type'        => 'string',
					'description' => 'Which agent opens by default; must be one of agent_slugs (added automatically if not). Optional — defaults to the first entry in agent_slugs.',
				),
				'post_types'      => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => 'Which post types show the sidebar, e.g. ["post","page"]. Required for update.',
				),
				'agent_mode'      => array(
					'type'        => 'string',
					'enum'        => array( 'autonomous', 'supervised' ),
					'description' => 'autonomous: actions run immediately. supervised: medium-risk actions create a proposal first. Defaults to autonomous.',
				),
				'inject_context'  => array(
					'type'        => 'boolean',
					'description' => 'Automatically share the current draft\'s title and content with the agent on the first message. Defaults to true.',
				),
				'toolbar_enabled' => array(
					'type'        => 'boolean',
					'description' => 'Show the inline "Magic Wand" AI toolbar button in the block toolbar. Defaults to true.',
				),
			),
			'required'   => array( 'action' ),
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Validated arguments from the LLM.
	 * @return array Result data.
	 */
	public function execute( array $arguments ): array {
		$denied = \Agentic\Tool_Helpers::deny_unless_admin_user();
		if ( null !== $denied ) {
			return $denied;
		}

		$action = sanitize_key( (string) ( $arguments['action'] ?? '' ) );

		if ( 'get' === $action ) {
			return $this->get_settings();
		}

		if ( 'update' === $action ) {
			return $this->update_settings( $arguments );
		}

		return array( 'error' => 'Unknown action. Use get or update.' );
	}

	/**
	 * Read current sidebar settings.
	 *
	 * @return array
	 */
	private function get_settings(): array {
		$rows = array();
		foreach ( Deployments::all( Deployments::TYPE_ADMIN_UI ) as $row ) {
			$rows[ $row['agent_slug'] ] = $row;
		}

		if ( empty( $rows ) ) {
			return array(
				'enabled'         => false,
				'agent_slugs'     => array(),
				'default_agent'   => '',
				'post_types'      => array( 'post', 'page' ),
				'agent_mode'      => 'autonomous',
				'inject_context'  => true,
				'toolbar_enabled' => true,
			);
		}

		$enabled_rows = array_filter( $rows, fn( $r ) => $r['enabled'] );
		$first_cfg    = reset( $rows )['config'] ?? array();
		$default      = '';
		foreach ( $rows as $slug => $row ) {
			if ( ! empty( $row['config']['is_default'] ) ) {
				$default = $slug;
				break;
			}
		}

		return array(
			'enabled'         => ! empty( $enabled_rows ),
			'agent_slugs'     => array_keys( $enabled_rows ),
			'default_agent'   => $default,
			'post_types'      => $first_cfg['post_types'] ?? array( 'post', 'page' ),
			'agent_mode'      => $first_cfg['agent_mode'] ?? 'autonomous',
			'inject_context'  => $first_cfg['inject_context'] ?? true,
			'toolbar_enabled' => $first_cfg['toolbar_enabled'] ?? true,
		);
	}

	/**
	 * Update sidebar settings.
	 *
	 * @param array $args Tool arguments.
	 * @return array
	 */
	private function update_settings( array $args ): array {
		if ( ! array_key_exists( 'enabled', $args ) || ! isset( $args['agent_slugs'] ) || ! isset( $args['post_types'] ) ) {
			return array( 'error' => 'enabled, agent_slugs, and post_types are required to update the editor sidebar.' );
		}

		$registry    = \Agentic_Agent_Registry::get_instance();
		$agent_slugs = array_values(
			array_filter(
				array_map( 'sanitize_key', (array) $args['agent_slugs'] ),
				fn( $slug ) => null !== $registry->get_agent_instance( $slug )
			)
		);

		if ( empty( $agent_slugs ) ) {
			return array( 'error' => 'None of the given agent_slugs are active agents.' );
		}

		$default_agent = isset( $args['default_agent'] ) ? sanitize_key( (string) $args['default_agent'] ) : '';
		if ( '' === $default_agent || ! in_array( $default_agent, $agent_slugs, true ) ) {
			$default_agent = $agent_slugs[0];
		}

		$post_types = array_values( array_map( 'sanitize_key', (array) $args['post_types'] ) );
		$agent_mode = sanitize_key( (string) ( $args['agent_mode'] ?? 'autonomous' ) );
		if ( ! in_array( $agent_mode, array( 'autonomous', 'supervised' ), true ) ) {
			$agent_mode = 'autonomous';
		}
		$inject_context  = array_key_exists( 'inject_context', $args ) ? (bool) $args['inject_context'] : true;
		$toolbar_enabled = array_key_exists( 'toolbar_enabled', $args ) ? (bool) $args['toolbar_enabled'] : true;
		$enabled         = ! empty( $args['enabled'] );

		$existing = array();
		foreach ( Deployments::all( Deployments::TYPE_ADMIN_UI ) as $row ) {
			$existing[ $row['agent_slug'] ] = $row['id'];
		}

		$all_slugs = array_unique( array_merge( $agent_slugs, array_keys( $existing ) ) );

		foreach ( $all_slugs as $slug ) {
			$on   = $enabled && in_array( $slug, $agent_slugs, true );
			$save = array(
				'type'       => Deployments::TYPE_ADMIN_UI,
				'agent_slug' => $slug,
				'label'      => ucwords( str_replace( '-', ' ', $slug ) ),
				'enabled'    => $on,
				'source'     => Deployments::SOURCE_ADMIN,
				'config'     => array(
					'post_types'      => $post_types,
					'inject_context'  => $inject_context,
					'agent_mode'      => $agent_mode,
					'is_default'      => $default_agent === $slug,
					'toolbar_enabled' => $toolbar_enabled,
				),
			);
			if ( isset( $existing[ $slug ] ) ) {
				$save['id'] = $existing[ $slug ];
			}
			Deployments::save( $save );
		}

		update_option(
			'agent_builder_editor_sidebar_settings',
			array(
				'enabled'         => $enabled ? '1' : '0',
				'agent_slug'      => $default_agent,
				'agent_slugs'     => $agent_slugs,
				'post_types'      => $post_types,
				'inject_context'  => $inject_context ? '1' : '0',
				'agent_mode'      => $agent_mode,
				'toolbar_enabled' => $toolbar_enabled ? '1' : '0',
			)
		);

		foreach ( $agent_slugs as $slug ) {
			Agent_Settings::update( $slug, 'override_mode', $agent_mode );
		}
		Agent_Settings::bust_cache();

		return array( 'ok' => true ) + $this->get_settings();
	}

	/**
	 * Get tool annotations.
	 *
	 * @return array
	 */
	public function get_annotations(): array {
		return array(
			'readonly'    => false,
			'destructive' => false,
		);
	}
}

return new Manage_Editor_Sidebar_Agent();
