<?php
/**
 * Tool: manage_admin_bar_launcher
 *
 * Configure the two WordPress-admin chat launchers the classic
 * Deployment → Admin tab manages: a per-agent slide-in chat button in the
 * admin bar, and the site-wide contextual "Ask AI" launcher shown on
 * specific admin screens.
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

use Agentic\Agent_Settings;
use Agentic\Admin_Surfaces;

/**
 * Manage wp-admin chat launchers.
 */
class Manage_Admin_Bar_Launcher extends \Agentic\Tool_Base {

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'manage_admin_bar_launcher';
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return 'Configure chat access inside wp-admin itself: (1) a per-agent slide-in chat button in the WordPress admin bar (target=agent_bar_button), and (2) the site-wide contextual "Ask AI" launcher shown on specific admin screens like Users or Plugins (target=contextual_launcher). Use get to see current settings before changing them.';
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
				'action'     => array(
					'type'        => 'string',
					'enum'        => array( 'get', 'update' ),
					'description' => 'get: read current settings. update: change them.',
				),
				'target'     => array(
					'type'        => 'string',
					'enum'        => array( 'agent_bar_button', 'contextual_launcher' ),
					'description' => 'Which launcher to read/change. Required.',
				),
				'agent_slug' => array(
					'type'        => 'string',
					'description' => 'For target=agent_bar_button: which agent\'s admin-bar button to configure. For target=contextual_launcher (update): which agent the launcher opens (defaults to the current setting if omitted).',
				),
				'enabled'    => array(
					'type'        => 'boolean',
					'description' => 'Turn this launcher on or off. Required for update.',
				),
				'position'   => array(
					'type'        => 'string',
					'enum'        => array( 'bottom-right', 'bottom-left' ),
					'description' => 'target=agent_bar_button only. Where the slide-in chat panel opens from. Defaults to bottom-right.',
				),
				'pages'      => array(
					'type'        => 'string',
					'enum'        => array( 'all', 'admin', 'front' ),
					'description' => 'target=agent_bar_button only. Which pages show this agent\'s admin-bar button. Defaults to all.',
				),
				'screens'    => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => 'target=contextual_launcher (update) only. Which wp-admin screens show the launcher. Call get first to see the full list of available screen keys.',
				),
			),
			'required'   => array( 'action', 'target' ),
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
		$target = sanitize_key( (string) ( $arguments['target'] ?? '' ) );

		if ( 'agent_bar_button' === $target ) {
			return 'get' === $action ? $this->get_agent_bar_buttons() : $this->update_agent_bar_button( $arguments );
		}

		if ( 'contextual_launcher' === $target ) {
			return 'get' === $action ? $this->get_contextual_launcher() : $this->update_contextual_launcher( $arguments );
		}

		return array( 'error' => 'target must be agent_bar_button or contextual_launcher.' );
	}

	/**
	 * Read per-agent admin-bar button settings.
	 *
	 * @return array
	 */
	private function get_agent_bar_buttons(): array {
		$registry = \Agentic_Agent_Registry::get_instance();
		$agents   = array();
		foreach ( $registry->get_all_instances() as $slug => $agent ) {
			$agents[] = array(
				'agent_slug' => $slug,
				'name'       => $agent->get_name(),
				'enabled'    => '1' === Agent_Settings::get( $slug, 'admin_bar_display', '' ),
				'position'   => Agent_Settings::get( $slug, 'admin_bar_position', 'bottom-right' ),
				'pages'      => Agent_Settings::get( $slug, 'admin_bar_pages', 'all' ),
			);
		}
		return array( 'agent_bar_buttons' => $agents );
	}

	/**
	 * Update one agent's admin-bar button.
	 *
	 * @param array $args Tool arguments.
	 * @return array
	 */
	private function update_agent_bar_button( array $args ): array {
		$slug = sanitize_key( (string) ( $args['agent_slug'] ?? '' ) );
		if ( '' === $slug ) {
			return array( 'error' => 'agent_slug is required to update an admin-bar button.' );
		}
		if ( ! array_key_exists( 'enabled', $args ) ) {
			return array( 'error' => 'enabled is required to update an admin-bar button.' );
		}

		$registry = \Agentic_Agent_Registry::get_instance();
		if ( ! $registry->get_agent_instance( $slug ) ) {
			return array( 'error' => "Agent \"{$slug}\" was not found or is not active." );
		}

		// Preserve position/pages when the caller only flips enabled.
		$position = array_key_exists( 'position', $args )
			? sanitize_key( (string) $args['position'] )
			: sanitize_key( (string) Agent_Settings::get( $slug, 'admin_bar_position', 'bottom-right' ) );
		if ( ! in_array( $position, array( 'bottom-right', 'bottom-left' ), true ) ) {
			$position = 'bottom-right';
		}
		$pages = array_key_exists( 'pages', $args )
			? sanitize_key( (string) $args['pages'] )
			: sanitize_key( (string) Agent_Settings::get( $slug, 'admin_bar_pages', 'all' ) );
		if ( ! in_array( $pages, array( 'all', 'admin', 'front' ), true ) ) {
			$pages = 'all';
		}

		Agent_Settings::update( $slug, 'admin_bar_display', ! empty( $args['enabled'] ) ? '1' : '' );
		Agent_Settings::update( $slug, 'admin_bar_position', $position );
		Agent_Settings::update( $slug, 'admin_bar_pages', $pages );

		return array(
			'ok'         => true,
			'agent_slug' => $slug,
			'enabled'    => ! empty( $args['enabled'] ),
			'position'   => $position,
			'pages'      => $pages,
		);
	}

	/**
	 * Read the site-wide contextual "Ask AI" launcher settings.
	 *
	 * @return array
	 */
	private function get_contextual_launcher(): array {
		$screens = get_option( 'agent_builder_admin_launcher_screens', null );
		if ( ! is_array( $screens ) ) {
			$screens = class_exists( Admin_Surfaces::class ) ? array_keys( Admin_Surfaces::available_screens() ) : array();
		}

		return array(
			'enabled'          => '1' === (string) get_option( 'agent_builder_admin_launchers_enabled', '1' ),
			'agent_slug'       => (string) get_option( 'agent_builder_admin_launcher_agent', 'wordpress-assistant' ),
			'screens'          => $screens,
			'available_screens' => class_exists( Admin_Surfaces::class ) ? array_keys( Admin_Surfaces::available_screens() ) : array(),
		);
	}

	/**
	 * Update the site-wide contextual launcher settings.
	 *
	 * @param array $args Tool arguments.
	 * @return array
	 */
	private function update_contextual_launcher( array $args ): array {
		if ( ! array_key_exists( 'enabled', $args ) ) {
			return array( 'error' => 'enabled is required to update the contextual launcher.' );
		}

		update_option( 'agent_builder_admin_launchers_enabled', ! empty( $args['enabled'] ) ? '1' : '0' );

		if ( isset( $args['agent_slug'] ) ) {
			$slug     = sanitize_key( (string) $args['agent_slug'] );
			$registry = \Agentic_Agent_Registry::get_instance();
			if ( '' !== $slug && ! $registry->get_agent_instance( $slug ) ) {
				return array( 'error' => "Agent \"{$slug}\" was not found or is not active." );
			}
			if ( '' !== $slug ) {
				update_option( 'agent_builder_admin_launcher_agent', $slug, false );
			}
		}

		if ( isset( $args['screens'] ) && is_array( $args['screens'] ) ) {
			$allowed = class_exists( Admin_Surfaces::class ) ? array_keys( Admin_Surfaces::available_screens() ) : array();
			$screens = array_values(
				array_intersect(
					array_map( 'sanitize_key', $args['screens'] ),
					$allowed
				)
			);
			update_option( 'agent_builder_admin_launcher_screens', $screens, false );
		}

		return array( 'ok' => true ) + $this->get_contextual_launcher();
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

return new Manage_Admin_Bar_Launcher();
