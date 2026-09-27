<?php
/**
 * Safety_Center_Payload builder for admin page REST payload.
 *
 * @package Agent_Builder
 */

declare(strict_types=1);

namespace Agentic\Admin_Pages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Safety_Center_Payload {

	public static function build(): array {
		$risk_labels = array(
			'none'    => __( 'No Risk', 'agent-builder' ),
			'low'     => __( 'Low Risk', 'agent-builder' ),
			'medium'  => __( 'Medium Risk', 'agent-builder' ),
			'high'    => __( 'High Risk', 'agent-builder' ),
			'extreme' => __( 'Extreme Risk', 'agent-builder' ),
		);

		$all_tools      = class_exists( \Agentic\Tools_Registry::class ) ? \Agentic\Tools_Registry::get_all() : array();
		$enabled_count  = 0;
		$disabled_count = 0;
		$max_risk       = class_exists( \Agentic\Risk_Level::class ) ? \Agentic\Risk_Level::NONE : 'none';
		$inventory      = self::safety_center_risk_inventory( $all_tools, $risk_labels );
		foreach ( $all_tools as $tool ) {
			if ( ! empty( $tool['enabled'] ) ) {
				++$enabled_count;
			} else {
				++$disabled_count;
			}
		}
		if ( ! empty( $inventory['enabled_max_risk'] ) ) {
			$max_risk = (string) $inventory['enabled_max_risk'];
		}

		$pending_count = 0;
		if ( class_exists( \Agentic\Approval_Queue::class ) ) {
			$pending_count = ( new \Agentic\Approval_Queue() )->get_pending_count();
		}
		$agent_mode = (string) get_option( 'agent_builder_agent_mode', 'supervised' );
		if ( ! in_array( $agent_mode, array( 'disabled', 'supervised', 'autonomous' ), true ) ) {
			$agent_mode = 'supervised';
		}
		$comfort = sanitize_key( (string) get_option( 'agent_builder_approval_comfort', 'careful' ) );

		$integrity = array(
			'valid'          => true,
			'checked'        => 0,
			'broken_at_id'   => null,
			'chain_start_id' => null,
		);
		if ( class_exists( \Agentic\Audit_Log_Integrity::class ) ) {
			$integrity = \Agentic\Audit_Log_Integrity::verify_chain();
		}

		$emergency_active = class_exists( \Agentic\Emergency_Stop::class ) && \Agentic\Emergency_Stop::is_active();

		$inventory_agents = array();
		if ( class_exists( \Agentic\Inventory_REST::class ) ) {
			$inventory_data   = \Agentic\Inventory_REST::get_inventory()->get_data();
			$inventory_agents = is_array( $inventory_data['agents'] ?? null ) ? $inventory_data['agents'] : array();
		}
		$agent_scopes     = self::safety_center_agent_scopes( $inventory_agents, $risk_labels );
		$active_agents    = count( $agent_scopes );
		$high_risk_agents = 0;
		$mcp_agents       = 0;
		$high_w           = class_exists( \Agentic\Risk_Level::class ) ? \Agentic\Risk_Level::weight( \Agentic\Risk_Level::HIGH ) : 3;
		foreach ( $agent_scopes as $agent ) {
			if ( ! empty( $agent['mcp_enabled'] ) ) {
				++$mcp_agents;
			}
			$highest = (string) ( $agent['highest_risk'] ?? 'none' );
			$w       = class_exists( \Agentic\Risk_Level::class ) ? \Agentic\Risk_Level::weight( $highest ) : 0;
			if ( $w >= $high_w ) {
				++$high_risk_agents;
			}
		}

		$mode_labels = array(
			'disabled'   => __( 'Disabled', 'agent-builder' ),
			'supervised' => __( 'Supervised', 'agent-builder' ),
			'autonomous' => __( 'Autonomous', 'agent-builder' ),
		);

		$comfort_labels = array(
			'careful'   => __( 'Always ask me', 'agent-builder' ),
			'balanced'  => __( 'Auto-approve low risk', 'agent-builder' ),
			'hands_off' => __( 'Trust more', 'agent-builder' ),
		);

		return array(
			'page'           => 'safety-center',
			'title'          => __( 'Safety Center', 'agent-builder' ),
			'panel_title'    => __( 'Safety overview', 'agent-builder' ),
			'description'    => __( 'Is this site set up safely for AI agents right now? These cards summarize the controls you already have — they do not change how those controls work.', 'agent-builder' ),
			'is_advanced'    => class_exists( \Agentic\Admin_Menu_Handler::class )
				? \Agentic\Admin_Menu_Handler::is_advanced_mode( 'safety-center' )
				: ( 'advanced' === get_option( 'agent_builder_ui_mode', 'basic' ) ),
			'tools'          => array(
				'enabled_count'    => $enabled_count,
				'disabled_count'   => $disabled_count,
				'enabled_max_risk' => $max_risk,
				'max_risk_label'   => $risk_labels[ $max_risk ] ?? $max_risk,
			),
			'risk_inventory' => $inventory,
			'approvals'      => array(
				'pending_count'    => $pending_count,
				'agent_mode'       => $agent_mode,
				'agent_mode_label' => $mode_labels[ $agent_mode ] ?? $agent_mode,
				'comfort'          => $comfort,
				'comfort_label'    => $comfort_labels[ $comfort ] ?? $comfort_labels['careful'],
			),
			'integrity'      => array(
				'valid'          => ! empty( $integrity['valid'] ),
				'checked'        => (int) ( $integrity['checked'] ?? 0 ),
				'broken_at_id'   => $integrity['broken_at_id'] ?? null,
				'chain_start_id' => $integrity['chain_start_id'] ?? null,
			),
			'emergency_stop' => array(
				'active' => $emergency_active,
			),
			'agents'         => array(
				'active_count'    => $active_agents,
				'high_risk_count' => $high_risk_agents,
				'mcp_count'       => $mcp_agents,
				'items'           => $agent_scopes,
				'integrity_note'  => __( 'Tool list blocked if manifest signature fails.', 'agent-builder' ),
			),
			'urls'           => array(
				'tools'     => admin_url( 'admin.php?page=agentic-tools' ),
				'approvals' => admin_url( 'admin.php?page=agentic-approvals' ),
				'activity'  => admin_url( 'admin.php?page=agentic-audit-log' ),
				'passport'  => admin_url( 'admin.php?page=agentic-agent-ready' ),
				'agents'    => admin_url( 'admin.php?page=agentic-agents' ),
				// Same construction as logs_payload(): wp_nonce_url() would
				// entity-escape "&" and break the React href.
				'export'    => admin_url(
					'admin-post.php?action=agentic_export_logs&tab=audit'
					. '&period=week'
					. '&_wpnonce=' . wp_create_nonce( 'agentic_export_logs' )
				),
			),
			'docs_url'       => 'https://agentic-plugin.com/documentation/safety-center/',
			'footer_policy'  => __(
				'Safety Center summarizes existing operator controls. It does not change how tools, approvals, or Emergency Stop work.',
				'agent-builder'
			),
		);
	}


	private static function safety_center_risk_inventory( array $all_tools, array $risk_labels ): array {
		$tiers_meta = self::safety_center_tier_copy();
		$examples   = self::safety_center_tier_examples();

		$counts = array();
		foreach ( array_keys( $tiers_meta ) as $tier ) {
			$counts[ $tier ] = array(
				'enabled'  => 0,
				'disabled' => 0,
			);
		}

		$max_risk          = class_exists( \Agentic\Risk_Level::class ) ? \Agentic\Risk_Level::NONE : 'none';
		$highest_enabled   = array();
		$high_w            = class_exists( \Agentic\Risk_Level::class ) ? \Agentic\Risk_Level::weight( \Agentic\Risk_Level::HIGH ) : 3;

		foreach ( $all_tools as $name => $tool ) {
			$name = (string) ( is_string( $name ) && '' !== $name ? $name : ( $tool['name'] ?? '' ) );
			if ( '' === $name ) {
				continue;
			}
			$risk = class_exists( \Agentic\Risk_Level::class )
				? \Agentic\Risk_Level::get_tool_default( $name )
				: (string) ( $tool['risk_level'] ?? 'none' );
			if ( ! isset( $counts[ $risk ] ) ) {
				$risk = 'none';
			}
			if ( ! empty( $tool['enabled'] ) ) {
				++$counts[ $risk ]['enabled'];
				if ( class_exists( \Agentic\Risk_Level::class ) ) {
					$max_risk = \Agentic\Risk_Level::max( $max_risk, $risk );
				}
				$w = class_exists( \Agentic\Risk_Level::class ) ? \Agentic\Risk_Level::weight( $risk ) : 0;
				if ( $w >= $high_w ) {
					$highest_enabled[] = array(
						'name'        => $name,
						'label'       => self::safety_center_tool_label( $name ),
						'risk'        => $risk,
						'risk_label'  => $risk_labels[ $risk ] ?? $risk,
						'description' => (string) ( $tool['description'] ?? '' ),
					);
				}
			} else {
				++$counts[ $risk ]['disabled'];
			}
		}

		usort(
			$highest_enabled,
			static function ( $a, $b ) {
				$wa = class_exists( \Agentic\Risk_Level::class ) ? \Agentic\Risk_Level::weight( (string) $a['risk'] ) : 0;
				$wb = class_exists( \Agentic\Risk_Level::class ) ? \Agentic\Risk_Level::weight( (string) $b['risk'] ) : 0;
				if ( $wa !== $wb ) {
					return $wb <=> $wa;
				}
				return strcasecmp( (string) $a['name'], (string) $b['name'] );
			}
		);

		$tiers = array();
		foreach ( $tiers_meta as $id => $meta ) {
			$tiers[] = array(
				'id'          => $id,
				'label'       => $risk_labels[ $id ] ?? $id,
				'short_label' => $meta['short_label'],
				'enabled'     => (int) $counts[ $id ]['enabled'],
				'disabled'    => (int) $counts[ $id ]['disabled'],
				'explanation' => $meta['explanation'],
				'examples'    => $examples[ $id ] ?? array(),
			);
		}

		return array(
			'tiers'            => $tiers,
			'highest_enabled'  => $highest_enabled,
			'enabled_max_risk' => $max_risk,
		);
	}


	private static function safety_center_tier_copy(): array {
		return array(
			'none'    => array(
				'short_label' => __( 'None', 'agent-builder' ),
				'explanation' => __( 'Read-only. The agent can look things up, but it cannot change your site.', 'agent-builder' ),
			),
			'low'     => array(
				'short_label' => __( 'Low', 'agent-builder' ),
				'explanation' => __( 'Usually safe to run automatically. These tools may read information that can include personal data, but they do not change your site.', 'agent-builder' ),
			),
			'medium'  => array(
				'short_label' => __( 'Medium', 'agent-builder' ),
				'explanation' => __( 'Changes something. The agent should pause and ask before using these tools.', 'agent-builder' ),
			),
			'high'    => array(
				'short_label' => __( 'High', 'agent-builder' ),
				'explanation' => __( 'Significant, bulk, account-sensitive, or money-moving actions. These do not run immediately — they wait for a human decision in the Approvals queue.', 'agent-builder' ),
			),
			'extreme' => array(
				'short_label' => __( 'Extreme', 'agent-builder' ),
				'explanation' => __( 'Too risky to allow. These tools are hidden from agents entirely and should not be enabled for normal use.', 'agent-builder' ),
			),
		);
	}


	private static function safety_center_tier_examples(): array {
		$baseline = class_exists( \Agentic\Risk_Level::class ) ? \Agentic\Risk_Level::get_baseline_risks() : array();
		$preferred = array(
			'none'    => array(),
			'low'     => array( 'request_human_help', 'wc_add_to_cart', 'manage_agent_shortcode' ),
			'medium'  => array( 'send_email', 'add_custom_css', 'cleanup_auto_drafts' ),
			'high'    => array( 'add_custom_js', 'force_password_reset', 'wc_create_refund' ),
			'extreme' => array( 'run_wp_cli' ),
		);

		$by_tier = array(
			'none'    => array(),
			'low'     => array(),
			'medium'  => array(),
			'high'    => array(),
			'extreme' => array(),
		);
		foreach ( $baseline as $tool_name => $risk ) {
			if ( isset( $by_tier[ $risk ] ) ) {
				$by_tier[ $risk ][] = (string) $tool_name;
			}
		}

		$out = array();
		foreach ( $preferred as $tier => $names ) {
			$picked = array();
			foreach ( $names as $name ) {
				if ( isset( $baseline[ $name ] ) && $baseline[ $name ] === $tier ) {
					$picked[] = $name;
				}
			}
			foreach ( $by_tier[ $tier ] as $name ) {
				if ( count( $picked ) >= 3 ) {
					break;
				}
				if ( ! in_array( $name, $picked, true ) ) {
					$picked[] = $name;
				}
			}
			$examples = array();
			foreach ( array_slice( $picked, 0, 3 ) as $name ) {
				$examples[] = array(
					'name'  => $name,
					'label' => self::safety_center_tool_label( $name ),
				);
			}
			$out[ $tier ] = $examples;
		}

		return $out;
	}


	private static function safety_center_agent_scopes( array $agents, array $risk_labels ): array {
		$items  = array();
		$high_w = class_exists( \Agentic\Risk_Level::class ) ? \Agentic\Risk_Level::weight( \Agentic\Risk_Level::HIGH ) : 3;

		foreach ( $agents as $agent ) {
			if ( ! is_array( $agent ) ) {
				continue;
			}
			$slug = (string) ( $agent['slug'] ?? '' );
			if ( '' === $slug ) {
				continue;
			}

			$risk_counts = array(
				'none'    => 0,
				'low'     => 0,
				'medium'  => 0,
				'high'    => 0,
				'extreme' => 0,
			);
			$highest     = class_exists( \Agentic\Risk_Level::class ) ? \Agentic\Risk_Level::NONE : 'none';
			$high_tools  = array();
			$other_tools = array();

			foreach ( (array) ( $agent['tools'] ?? array() ) as $tool ) {
				$name = (string) ( $tool['name'] ?? '' );
				if ( '' === $name ) {
					continue;
				}
				$risk = (string) ( $tool['risk'] ?? 'none' );
				if ( ! isset( $risk_counts[ $risk ] ) ) {
					$risk = 'none';
				}
				++$risk_counts[ $risk ];
				if ( class_exists( \Agentic\Risk_Level::class ) ) {
					$highest = \Agentic\Risk_Level::max( $highest, $risk );
				}
				$row = array(
					'name'       => $name,
					'label'      => self::safety_center_tool_label( $name ),
					'risk'       => $risk,
					'risk_label' => $risk_labels[ $risk ] ?? $risk,
				);
				$w = class_exists( \Agentic\Risk_Level::class ) ? \Agentic\Risk_Level::weight( $risk ) : 0;
				if ( $w >= $high_w ) {
					$high_tools[] = $row;
				} else {
					$other_tools[] = $row;
				}
			}

			$sort_tools = static function ( $a, $b ) {
				$wa = class_exists( \Agentic\Risk_Level::class ) ? \Agentic\Risk_Level::weight( (string) $a['risk'] ) : 0;
				$wb = class_exists( \Agentic\Risk_Level::class ) ? \Agentic\Risk_Level::weight( (string) $b['risk'] ) : 0;
				if ( $wa !== $wb ) {
					return $wb <=> $wa;
				}
				return strcasecmp( (string) $a['name'], (string) $b['name'] );
			};
			usort( $high_tools, $sort_tools );
			usort( $other_tools, $sort_tools );

			$items[] = array(
				'slug'               => $slug,
				'name'               => (string) ( $agent['name'] ?? $slug ),
				'version'            => (string) ( $agent['version'] ?? '' ),
				'author'             => (string) ( $agent['author'] ?? '' ),
				'mcp_enabled'        => ! empty( $agent['mcp_enabled'] ),
				'risk_counts'        => $risk_counts,
				'highest_risk'       => $highest,
				'highest_risk_label' => $risk_labels[ $highest ] ?? $highest,
				'high_tools'         => $high_tools,
				'other_tools'        => $other_tools,
			);
		}

		usort(
			$items,
			static function ( $a, $b ) {
				$wa = class_exists( \Agentic\Risk_Level::class ) ? \Agentic\Risk_Level::weight( (string) $a['highest_risk'] ) : 0;
				$wb = class_exists( \Agentic\Risk_Level::class ) ? \Agentic\Risk_Level::weight( (string) $b['highest_risk'] ) : 0;
				if ( $wa !== $wb ) {
					return $wb <=> $wa;
				}
				return strcasecmp( (string) $a['name'], (string) $b['name'] );
			}
		);

		return $items;
	}


	private static function safety_center_tool_label( string $name ): string {
		return str_replace( array( '_', '-' ), ' ', $name );
	}

}
