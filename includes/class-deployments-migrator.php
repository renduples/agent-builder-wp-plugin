<?php
/**
 * One-run migration: ingests legacy WP option deployments into wp_agent_builder_deployments.
 *
 * Idempotent — checks MIGRATED_OPTION before running and marks it complete
 * afterwards. Safe to call on every activation.
 *
 * @package Agentic
 */

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One-run migration from legacy option-based deployments to the unified
 * wp_agent_builder_deployments table.
 *
 * @package Agent_Builder
 */
class Deployments_Migrator {

	/**
	 * Run the migration. Does nothing if already completed.
	 */
	public static function run(): void {
		if ( get_option( Deployments::MIGRATED_OPTION ) ) {
			return;
		}

		self::migrate_shortcodes();
		self::migrate_modal();
		self::migrate_admin_bar();
		self::migrate_gutenberg();
		self::migrate_admin_ui();
		self::migrate_event_listeners();
		self::migrate_scheduled_tasks();
		self::migrate_cli_whitelist();

		update_option( Deployments::MIGRATED_OPTION, '1', false );
	}

	// -------------------------------------------------------------------------
	// Shortcodes — agent_builder_shortcode_deployments
	// -------------------------------------------------------------------------

	/**
	 * Migrate legacy shortcode deployments.
	 *
	 * @return void
	 */
	private static function migrate_shortcodes(): void {
		$rows = get_option( 'agent_builder_shortcode_deployments', array() );
		if ( ! is_array( $rows ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			$agent = sanitize_key( $row['agent'] ?? '' );
			if ( ! $agent ) {
				continue;
			}

			Deployments::save(
				array(
					'type'       => Deployments::TYPE_SHORTCODE,
					'agent_slug' => $agent,
					'label'      => sanitize_text_field( $row['label'] ?? $agent ),
					'enabled'    => 1,
					'source'     => Deployments::SOURCE_ADMIN,
					'config'     => array(
						'style'       => $row['style'] ?? 'inline',
						'height'      => $row['height'] ?? '',
						'show_header' => $row['show_header'] ?? true,
						'placeholder' => $row['placeholder'] ?? '',
					),
					'created_at' => $row['created_at'] ?? current_time( 'mysql' ),
				)
			);
		}
	}

	// -------------------------------------------------------------------------
	// Modal — agent_builder_modal_agents + agent_builder_modal_config
	// -------------------------------------------------------------------------

	/**
	 * Migrate legacy modal agent deployments.
	 *
	 * @return void
	 */
	private static function migrate_modal(): void {
		$enabled = get_option( 'agent_builder_modal_agents', array() );
		$configs = get_option( 'agent_builder_modal_config', array() );

		if ( ! is_array( $enabled ) ) {
			return;
		}

		foreach ( $enabled as $agent_slug ) {
			$agent_slug = sanitize_key( $agent_slug );
			if ( ! $agent_slug ) {
				continue;
			}

			$cfg = is_array( $configs[ $agent_slug ] ?? null ) ? $configs[ $agent_slug ] : array();

			Deployments::save(
				array(
					'type'       => Deployments::TYPE_MODAL,
					'agent_slug' => $agent_slug,
					'label'      => ucwords( str_replace( '-', ' ', $agent_slug ) ),
					'enabled'    => 1,
					'source'     => Deployments::SOURCE_ADMIN,
					'config'     => array(
						'position'      => $cfg['position'] ?? 'bottom-right',
						'pages'         => $cfg['pages'] ?? 'all',
						'require_login' => (bool) ( $cfg['require_login'] ?? false ),
					),
				)
			);
		}
	}

	// -------------------------------------------------------------------------
	// Admin bar — agent_builder_admin_bar_agents + agent_builder_admin_bar_config
	// -------------------------------------------------------------------------

	/**
	 * Migrate legacy admin bar deployments.
	 *
	 * @return void
	 */
	private static function migrate_admin_bar(): void {
		$enabled = get_option( 'agent_builder_admin_bar_agents', array() );
		$configs = get_option( 'agent_builder_admin_bar_config', array() );

		if ( ! is_array( $enabled ) ) {
			return;
		}

		foreach ( $enabled as $agent_slug ) {
			$agent_slug = sanitize_key( $agent_slug );
			if ( ! $agent_slug ) {
				continue;
			}

			$cfg = is_array( $configs[ $agent_slug ] ?? null ) ? $configs[ $agent_slug ] : array();

			Deployments::save(
				array(
					'type'       => Deployments::TYPE_ADMIN_BAR,
					'agent_slug' => $agent_slug,
					'label'      => ucwords( str_replace( '-', ' ', $agent_slug ) ),
					'enabled'    => 1,
					'source'     => Deployments::SOURCE_ADMIN,
					'config'     => array(
						'position' => $cfg['position'] ?? 'bottom-right',
						'pages'    => $cfg['pages'] ?? 'all',
					),
				)
			);
		}
	}

	// -------------------------------------------------------------------------
	// Gutenberg — agent_builder_gutenberg_block_agents
	// -------------------------------------------------------------------------

	/**
	 * Migrate legacy Gutenberg block agent deployments.
	 *
	 * @return void
	 */
	private static function migrate_gutenberg(): void {
		$slugs = get_option( 'agent_builder_gutenberg_block_agents', array() );

		if ( ! is_array( $slugs ) ) {
			return;
		}

		foreach ( $slugs as $agent_slug ) {
			$agent_slug = sanitize_key( $agent_slug );
			if ( ! $agent_slug ) {
				continue;
			}

			Deployments::save(
				array(
					'type'       => Deployments::TYPE_GUTENBERG,
					'agent_slug' => $agent_slug,
					'label'      => ucwords( str_replace( '-', ' ', $agent_slug ) ),
					'enabled'    => 1,
					'source'     => Deployments::SOURCE_ADMIN,
					'config'     => array(),
				)
			);
		}
	}

	// -------------------------------------------------------------------------
	// Admin UI — agent_builder_editor_sidebar_settings
	// -------------------------------------------------------------------------

	/**
	 * Migrate legacy editor sidebar / admin UI agent settings.
	 *
	 * @return void
	 */
	private static function migrate_admin_ui(): void {
		$settings = get_option( 'agent_builder_editor_sidebar_settings', array() );

		if ( ! is_array( $settings ) || empty( $settings['agent_slugs'] ) ) {
			return;
		}

		$slugs   = (array) $settings['agent_slugs'];
		$default = sanitize_key( $settings['agent_slug'] ?? ( $slugs[0] ?? '' ) );

		foreach ( $slugs as $agent_slug ) {
			$agent_slug = sanitize_key( $agent_slug );
			if ( ! $agent_slug ) {
				continue;
			}

			Deployments::save(
				array(
					'type'       => Deployments::TYPE_ADMIN_UI,
					'agent_slug' => $agent_slug,
					'label'      => ucwords( str_replace( '-', ' ', $agent_slug ) ),
					'enabled'    => (bool) ( $settings['enabled'] ?? true ),
					'source'     => Deployments::SOURCE_ADMIN,
					'config'     => array(
						'post_types'      => (array) ( $settings['post_types'] ?? array( 'post', 'page' ) ),
						'inject_context'  => (bool) ( $settings['inject_context'] ?? true ),
						'agent_mode'      => sanitize_key( $settings['agent_mode'] ?? 'supervised' ),
						'is_default'      => $agent_slug === $default,
						'toolbar_enabled' => (bool) ( $settings['toolbar_enabled'] ?? true ),
					),
				)
			);
		}
	}

	// -------------------------------------------------------------------------
	// Event listeners — agent_builder_user_event_triggers
	// -------------------------------------------------------------------------

	/**
	 * Migrate legacy user event trigger (event listener) deployments.
	 *
	 * @return void
	 */
	private static function migrate_event_listeners(): void {
		$triggers = get_option( 'agent_builder_user_event_triggers', array() );

		if ( ! is_array( $triggers ) ) {
			return;
		}

		foreach ( $triggers as $trigger ) {
			$agent_slug = sanitize_key( $trigger['agent_slug'] ?? '' );
			if ( ! $agent_slug ) {
				continue;
			}

			Deployments::save(
				array(
					'type'       => Deployments::TYPE_EVENT_LISTENER,
					'agent_slug' => $agent_slug,
					'label'      => sanitize_text_field( $trigger['name'] ?? $agent_slug ),
					'enabled'    => 1,
					'source'     => Deployments::SOURCE_ADMIN,
					'config'     => array(
						'hook'     => sanitize_text_field( $trigger['hook'] ?? '' ),
						'prompt'   => sanitize_textarea_field( $trigger['prompt'] ?? '' ),
						'priority' => (int) ( $trigger['priority'] ?? 10 ),
						'source'   => 'user',
					),
				)
			);
		}
	}

	// -------------------------------------------------------------------------
	// Scheduled tasks — WP-Cron
	// -------------------------------------------------------------------------

	/**
	 * Migrate legacy scheduled task (WP-Cron) deployments.
	 *
	 * @return void
	 */
	private static function migrate_scheduled_tasks(): void {
		$crons = _get_cron_array();
		if ( ! is_array( $crons ) ) {
			return;
		}

		foreach ( $crons as $timestamp => $hooks ) {
			foreach ( $hooks as $hook => $events ) {
				if ( ! str_starts_with( $hook, 'agentic_scheduled_' ) ) {
					continue;
				}

				// Hook pattern: agentic_scheduled_{agent}_{task_id}.
				$parts      = explode( '_', substr( $hook, strlen( 'agentic_scheduled_' ) ) );
				$agent_slug = sanitize_key( $parts[0] ?? '' );
				$task_id    = sanitize_key( $parts[1] ?? '' );

				if ( ! $agent_slug ) {
					continue;
				}

				foreach ( $events as $event ) {
					$schedule = $event['schedule'] ?? 'daily';

					Deployments::save(
						array(
							'type'       => Deployments::TYPE_SCHEDULED_TASK,
							'agent_slug' => $agent_slug,
							'label'      => ucwords( str_replace( '-', ' ', $agent_slug ) ) . ' — ' . $task_id,
							'enabled'    => 1,
							'source'     => Deployments::SOURCE_ADMIN,
							'config'     => array(
								'task_id'     => $task_id,
								'schedule'    => $schedule,
								'mode'        => 'autonomous',
								'description' => '',
								'next_run'    => gmdate( 'Y-m-d H:i:s', (int) $timestamp ),
								'last_run'    => null,
								'last_status' => null,
							),
						)
					);

					// Remove the per-task cron hook — dispatcher heartbeat takes over.
					wp_clear_scheduled_hook( $hook );
				}
			}
		}
	}

	// -------------------------------------------------------------------------
	// CLI whitelist — wp_agent_builder_tools run_wp_cli _allowed_commands
	// -------------------------------------------------------------------------

	/**
	 * Migrate legacy WP-CLI whitelist into TYPE_CLI deployments.
	 *
	 * @return void
	 */
	private static function migrate_cli_whitelist(): void {
		if ( ! class_exists( '\Agentic\Tools_Registry' ) ) {
			return;
		}

		$tool_row = \Agentic\Tools_Registry::get( 'run_wp_cli' );
		if ( ! $tool_row ) {
			return;
		}

		$params = $tool_row['parameters'] ?? array();
		if ( is_string( $params ) ) {
			$params = json_decode( $params, true ) ?? array();
		}

		$commands = $params['_allowed_commands'] ?? array();
		if ( ! is_array( $commands ) ) {
			return;
		}

		foreach ( $commands as $cmd ) {
			$pattern = sanitize_text_field( $cmd['pattern'] ?? '' );
			if ( ! $pattern ) {
				continue;
			}

			Deployments::save(
				array(
					'type'       => Deployments::TYPE_CLI,
					'agent_slug' => '',
					'label'      => sanitize_text_field( $cmd['label'] ?? $pattern ),
					'enabled'    => isset( $cmd['enabled'] ) ? (int) (bool) $cmd['enabled'] : 1,
					'source'     => Deployments::SOURCE_ADMIN,
					'config'     => array(
						'pattern' => $pattern,
						'group'   => sanitize_key( $cmd['group'] ?? 'read' ),
					),
				)
			);
		}
	}
}
