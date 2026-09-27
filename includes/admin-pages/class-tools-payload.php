<?php
/**
 * Tools_Payload builder for admin page REST payload.
 *
 * @package Agent_Builder
 */

declare(strict_types=1);

namespace Agentic\Admin_Pages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Tools_Payload {

	private static function tool_category_labels(): array {
		return array(
			'all'                     => __( 'All', 'agent-builder' ),
			'agents'                  => __( 'Agents', 'agent-builder' ),
			'ai-visibility'           => __( 'AI Visibility', 'agent-builder' ),
			'analytics'               => __( 'Analytics', 'agent-builder' ),
			'assistant-trainer'       => __( 'Agents', 'agent-builder' ),
			'caching'                 => __( 'Caching', 'agent-builder' ),
			'cli'                     => __( 'CLI', 'agent-builder' ),
			'communication'           => __( 'Communication', 'agent-builder' ),
			'content'                 => __( 'Content', 'agent-builder' ),
			'crm'                     => __( 'CRM', 'agent-builder' ),
			'database'                => __( 'Database', 'agent-builder' ),
			'dataforseo'              => __( 'DataForSEO', 'agent-builder' ),
			'ecommerce'               => __( 'Ecommerce', 'agent-builder' ),
			'email'                   => __( 'Email', 'agent-builder' ),
			'files'                   => __( 'Files', 'agent-builder' ),
			'forms'                   => __( 'Forms', 'agent-builder' ),
			'gbp'                     => __( 'Google Business', 'agent-builder' ),
			'git'                     => __( 'Git', 'agent-builder' ),
			'google-search-marketing' => __( 'Search Marketing', 'agent-builder' ),
			'google-workspace'        => __( 'Google Workspace', 'agent-builder' ),
			'maintenance'             => __( 'Maintenance', 'agent-builder' ),
			'media'                   => __( 'Media', 'agent-builder' ),
			'orchestration'           => __( 'Orchestration', 'agent-builder' ),
			'plugins'                 => __( 'Plugins', 'agent-builder' ),
			'security'                => __( 'Security', 'agent-builder' ),
			'seo'                     => __( 'SEO', 'agent-builder' ),
			'site-audit'              => __( 'Site Audit', 'agent-builder' ),
			'site-health'             => __( 'Site Health', 'agent-builder' ),
			'themes'                  => __( 'Themes', 'agent-builder' ),
			'users'                   => __( 'Users', 'agent-builder' ),
			'utility'                 => __( 'Utility', 'agent-builder' ),
			'web'                     => __( 'Web', 'agent-builder' ),
			'wordpress'               => __( 'WordPress', 'agent-builder' ),
		);
	}


	private static function tool_category_label( string $slug ): string {
		$slug  = strtolower( $slug );
		$known = self::tool_category_labels();
		if ( isset( $known[ $slug ] ) ) {
			return $known[ $slug ];
		}

		return ucwords( str_replace( array( '-', '_' ), ' ', $slug ) );
	}


	private static function normalize_tool_category( string $category ): string {
		$category = strtolower( sanitize_key( $category ) );
		if ( '' === $category ) {
			return 'general';
		}
		// Collapse synonym.
		if ( 'agents' === $category ) {
			return 'assistant-trainer';
		}

		return $category;
	}


	public static function build( string $tab = 'all' ): array {
		// Keep registry in sync (same as tools.php).
		if ( class_exists( \Agentic\Tool_Loader::class ) ) {
			\Agentic\Tool_Loader::get_instance()->sync_to_registry();
			\Agentic\Tool_Loader::get_instance()->load();
		}
		if ( class_exists( \Agentic\Tools_Registry::class ) ) {
			\Agentic\Tools_Registry::seed_core_tools(
				array(
					'db_update_option' => 'database',
					'db_create_post'   => 'database',
					'db_update_post'   => 'database',
					'db_delete_post'   => 'database',
					'run_wp_cli'       => 'cli',
				)
			);
		}

		// Also sync tools claimed by active agents (category + presence).
		if ( class_exists( '\Agentic_Agent_Registry' ) && class_exists( \Agentic\Tool_Loader::class ) && class_exists( \Agentic\Tools_Registry::class ) ) {
			$loader    = \Agentic\Tool_Loader::get_instance();
			$instances = \Agentic_Agent_Registry::get_instance()->get_all_instances();
			foreach ( $instances as $agent ) {
				$names = $agent->get_tool_names();
				$defs  = $loader->get_definitions_for( $names );
				\Agentic\Tools_Registry::sync_agent_tools( $agent->get_id(), $defs );
			}
		}

		$tools = class_exists( \Agentic\Tools_Registry::class ) ? \Agentic\Tools_Registry::get_all() : array();
		$tab   = self::normalize_tool_category( $tab );
		if ( 'general' === $tab ) {
			$tab = 'all';
		}

		// Counts per normalized category.
		$counts   = array( 'all' => 0 );
		$rows_all = array();
		foreach ( $tools as $tool ) {
			$name = (string) ( $tool['name'] ?? '' );
			if ( '' === $name ) {
				continue;
			}
			$cat = self::normalize_tool_category( (string) ( $tool['category'] ?? 'general' ) );
			if ( ! isset( $counts[ $cat ] ) ) {
				$counts[ $cat ] = 0;
			}
			++$counts[ $cat ];
			++$counts['all'];

			$rows_all[] = array(
				'id'             => $name,
				'title'          => $name,
				'subtitle'       => (string) ( $tool['description'] ?? '' ),
				'category'       => $cat,
				'category_label' => self::tool_category_label( $cat ),
				'enabled'        => ! empty( $tool['enabled'] ),
				'source'         => (string) ( $tool['source'] ?? 'core' ),
				'risk_level'     => (string) ( $tool['risk_level'] ?? '' ),
			);
		}

		// Build tabs: All first, then categories A–Z.
		$cat_slugs = array_keys( $counts );
		$cat_slugs = array_values(
			array_filter(
				$cat_slugs,
				static function ( $slug ) use ( $counts ) {
					return 'all' !== $slug && ( $counts[ $slug ] ?? 0 ) > 0;
				}
			)
		);
		usort(
			$cat_slugs,
			static function ( $a, $b ) {
				return strcasecmp( self::tool_category_label( $a ), self::tool_category_label( $b ) );
			}
		);

		if ( 'all' !== $tab && ! in_array( $tab, $cat_slugs, true ) ) {
			$tab = 'all';
		}

		$tabs   = array();
		$tabs[] = array(
			'id'    => 'all',
			'label' => sprintf(
				/* translators: %d: tool count */
				__( 'All (%d)', 'agent-builder' ),
				(int) ( $counts['all'] ?? 0 )
			),
			'url'   => admin_url( 'admin.php?page=agentic-tools&tab=all' ),
		);
		foreach ( $cat_slugs as $slug ) {
			$tabs[] = array(
				'id'    => $slug,
				'label' => sprintf(
					'%s (%d)',
					self::tool_category_label( $slug ),
					(int) $counts[ $slug ]
				),
				'url'   => admin_url( 'admin.php?page=agentic-tools&tab=' . rawurlencode( $slug ) ),
			);
		}

		$rows = $rows_all;
		if ( 'all' !== $tab ) {
			$rows = array_values(
				array_filter(
					$rows_all,
					static function ( $row ) use ( $tab ) {
						return ( $row['category'] ?? '' ) === $tab;
					}
				)
			);
		}

		$panel_title = 'all' === $tab
			? __( 'All tools', 'agent-builder' )
			: self::tool_category_label( $tab );

		$is_advanced = class_exists( \Agentic\Admin_Menu_Handler::class )
			? \Agentic\Admin_Menu_Handler::is_advanced_mode( 'tools' )
			: ( 'advanced' === get_option( 'agent_builder_ui_mode', 'basic' ) );

		$enabled_count  = 0;
		$disabled_count = 0;
		foreach ( $rows_all as $row ) {
			if ( ! empty( $row['enabled'] ) ) {
				++$enabled_count;
			} else {
				++$disabled_count;
			}
		}

		$active_profile = self::resolve_active_tools_profile();
		$profile_cards  = array();
		foreach ( self::tools_ability_profiles() as $id => $p ) {
			if ( 'custom' === $id ) {
				continue;
			}
			$profile_cards[] = array(
				'id'       => $id,
				'label'    => $p['label'],
				'summary'  => $p['summary'],
				'detail'   => $p['detail'],
				'max_risk' => $p['max_risk'],
				'icon'     => $p['icon'],
				'active'   => $id === $active_profile,
			);
		}

		return array(
			'page'             => 'tools',
			'tab'              => $tab,
			'title'            => __( 'Tools', 'agent-builder' ),
			'panel_title'      => $is_advanced
				? $panel_title
				: __( 'What may agents do?', 'agent-builder' ),
			'description'      => $is_advanced
				? __( 'Enable or disable tools agents can use. Group by category using the tabs.', 'agent-builder' )
				: __( 'Pick a simple safety profile. We turn tools on or off to match — no need to manage hundreds of tools one by one.', 'agent-builder' ),
			'rows'             => $rows,
			'tabs'             => $tabs,
			'counts'           => $counts,
			'is_advanced'      => $is_advanced,
			'ui_mode'          => $is_advanced ? 'advanced' : 'basic',
			'interface_url'    => admin_url( 'admin.php?page=agentic-settings&tab=interface' ),
			'active_profile'   => $active_profile,
			'profiles'         => $profile_cards,
			'enabled_count'    => $enabled_count,
			'disabled_count'   => $disabled_count,
			'enabled_max_risk' => class_exists( \Agentic\Tools_Registry::class )
				? \Agentic\Tools_Registry::enabled_max_risk_level()
				: 'none',
			'docs_url'         => 'https://agentic-plugin.com/documentation/tools/',
			'footer_policy'    => __(
				'Agents only use the tools you allow. Higher-risk actions still follow Approvals and your safety settings. Provider processing of chat content is covered by our Privacy Policy.',
				'agent-builder'
			),
		);
	}


	public static function tools_ability_profiles(): array {
		return array(
			'browse' => array(
				'label'    => __( 'Browse & answer', 'agent-builder' ),
				'summary'  => __( 'Safest — read-only help', 'agent-builder' ),
				'detail'   => __( 'Agents can look things up and answer questions. They cannot change posts, settings, or your site.', 'agent-builder' ),
				'max_risk' => 'low',
				'icon'     => '👀',
			),
			'assist' => array(
				'label'    => __( 'Help with drafts', 'agent-builder' ),
				'summary'  => __( 'Balanced — create drafts with care', 'agent-builder' ),
				'detail'   => __( 'Read plus everyday writing (drafts and light edits). Riskier changes still ask for confirmation.', 'agent-builder' ),
				'max_risk' => 'medium',
				'icon'     => '✍️',
			),
			'manage' => array(
				'label'    => __( 'Manage my site', 'agent-builder' ),
				'summary'  => __( 'Full productivity — approvals for big changes', 'agent-builder' ),
				'detail'   => __( 'Most tools on, including significant updates. High-risk actions go through the Approvals queue. Extreme tools stay off.', 'agent-builder' ),
				'max_risk' => 'high',
				'icon'     => '🛠️',
			),
			'custom' => array(
				'label'    => __( 'Custom mix', 'agent-builder' ),
				'summary'  => __( 'You mixed tools manually', 'agent-builder' ),
				'detail'   => __( 'Individual tools were toggled outside a profile.', 'agent-builder' ),
				'max_risk' => 'high',
				'icon'     => '⚙️',
			),
		);
	}


	private static function resolve_active_tools_profile(): string {
		$stored   = sanitize_key( (string) get_option( 'agent_builder_tools_ability_profile', '' ) );
		$profiles = self::tools_ability_profiles();
		if ( $stored && isset( $profiles[ $stored ] ) && 'custom' !== $stored ) {
			return $stored;
		}
		if ( 'custom' === $stored ) {
			return 'custom';
		}
		// Infer from current max enabled risk.
		if ( ! class_exists( \Agentic\Tools_Registry::class ) ) {
			return 'browse';
		}
		$max = \Agentic\Tools_Registry::enabled_max_risk_level();
		$w   = \Agentic\Risk_Level::weight( $max );
		if ( $w <= \Agentic\Risk_Level::weight( \Agentic\Risk_Level::LOW ) ) {
			return 'browse';
		}
		if ( $w <= \Agentic\Risk_Level::weight( \Agentic\Risk_Level::MEDIUM ) ) {
			return 'assist';
		}
		return 'manage';
	}

}
