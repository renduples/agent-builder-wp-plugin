<?php
/**
 * Approvals_Payload builder for admin page REST payload.
 *
 * @package Agent_Builder
 */

declare(strict_types=1);

namespace Agentic\Admin_Pages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Approvals_Payload {

	public static function build( string $tab ): array {
		$queue   = new \Agentic\Approval_Queue();
		$pending = $queue->get_pending();
		$rows    = array();
		foreach ( $pending as $item ) {
			$action = (string) ( $item['tool_name'] ?? $item['action'] ?? 'action' );
			$params = $item['params'] ?? $item['data'] ?? array();
			if ( is_string( $params ) ) {
				$decoded = json_decode( $params, true );
				$params  = is_array( $decoded ) ? $decoded : array();
			}
			$summary = '';
			if ( ! empty( $item['reasoning'] ) ) {
				$summary = (string) $item['reasoning'];
			} elseif ( ! empty( $params['file_path'] ) ) {
				$summary = (string) $params['file_path'];
			} elseif ( ! empty( $params['title'] ) ) {
				$summary = (string) $params['title'];
			} else {
				$summary = wp_json_encode( $params );
			}

			$rows[] = array(
				'id'         => (string) ( $item['id'] ?? '' ),
				'title'      => str_replace( '_', ' ', $action ),
				'action'     => $action,
				'subtitle'   => (string) ( $item['agent_id'] ?? '' ),
				'risk_level' => (string) ( $item['risk_level'] ?? 'high' ),
				'created_at' => (string) ( $item['created_at'] ?? '' ),
				'summary'    => $summary,
			);
		}

		$is_advanced = class_exists( \Agentic\Admin_Menu_Handler::class )
			? \Agentic\Admin_Menu_Handler::is_advanced_mode( 'approvals' )
			: ( 'advanced' === get_option( 'agent_builder_ui_mode', 'basic' ) );

		$prefs = Admin_Profiles::get_approval_prefs();

		return array(
			'page'             => 'approvals',
			'tab'              => $tab,
			'title'            => __( 'Approvals', 'agent-builder' ),
			'panel_title'      => __( 'Things waiting for your OK', 'agent-builder' ),
			'description'      => __( 'When an agent wants to change something important, it waits here until you approve or reject it.', 'agent-builder' ),
			'pending_count'    => count( $rows ),
			'rows'             => $rows,
			'groups'           => self::group_pending_for_bulk( $rows ),
			'tabs'             => array(
				array(
					'id'    => 'approvals',
					'label' => __( 'Approvals', 'agent-builder' ),
					'url'   => admin_url( 'admin.php?page=agentic-approvals&tab=approvals' ),
				),
				array(
					'id'    => 'backups',
					'label' => __( 'Backups', 'agent-builder' ),
					'url'   => admin_url( 'admin.php?page=agentic-approvals&tab=backups' ),
				),
			),
			'agent_mode'       => (string) get_option( 'agent_builder_agent_mode', 'supervised' ),
			'is_advanced'      => $is_advanced,
			'interface_url'    => admin_url( 'admin.php?page=agentic-settings&tab=interface' ),
			'prefs'            => $prefs,
			'comfort_profiles' => Admin_Profiles::approval_comfort_profiles(),
			'docs_url'         => 'https://agentic-plugin.com/documentation/approvals/',
			'footer_policy'    => __(
				'Approvals keep high-risk agent actions under human control. Email alerts use your admin address and never include passwords. See Privacy Policy for how providers process chat.',
				'agent-builder'
			),
		);
	}


	private static function group_pending_for_bulk( array $rows ): array {
		$scaffold_actions = array( 'create_plugin_scaffold', 'create_agent_files' );
		$groups           = array();

		foreach ( $rows as $row ) {
			$agent = (string) ( $row['subtitle'] ?? '' );
			$ts    = strtotime( ( (string) ( $row['created_at'] ?? '' ) ) . ' UTC' );
			$ts    = false !== $ts ? $ts : time();
			$key   = null;

			foreach ( $groups as $k => $g ) {
				if ( $g['agent_id'] === $agent && abs( $g['last_ts'] - $ts ) < 120 ) {
					$key = $k;
					break;
				}
			}

			if ( null === $key ) {
				$key            = count( $groups );
				$groups[ $key ] = array(
					'agent_id' => $agent,
					'first_ts' => $ts,
					'last_ts'  => $ts,
					'items'    => array(),
				);
			}

			$groups[ $key ]['items'][] = $row;
			$groups[ $key ]['last_ts'] = $ts;
		}

		$out = array();
		foreach ( $groups as $g ) {
			usort(
				$g['items'],
				function ( $a, $b ) use ( $scaffold_actions ) {
					$a_scaffold = in_array( $a['action'], $scaffold_actions, true ) ? 0 : 1;
					$b_scaffold = in_array( $b['action'], $scaffold_actions, true ) ? 0 : 1;
					if ( $a_scaffold !== $b_scaffold ) {
						return $a_scaffold - $b_scaffold;
					}
					return (int) $a['id'] - (int) $b['id'];
				}
			);

			$out[] = array(
				'agent_id' => $g['agent_id'],
				'count'    => count( $g['items'] ),
				'time_ago' => human_time_diff( $g['first_ts'] ),
				'ids'      => array_map( fn( $r ) => (string) $r['id'], $g['items'] ),
			);
		}

		return $out;
	}
}
