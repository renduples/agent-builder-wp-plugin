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

		$prefs = self::get_approval_prefs();

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
			'comfort_profiles' => self::approval_comfort_profiles(),
			'docs_url'         => 'https://agentic-plugin.com/documentation/approvals/',
			'footer_policy'    => __(
				'Approvals keep high-risk agent actions under human control. Email alerts use your admin address and never include passwords. See Privacy Policy for how providers process chat.',
				'agent-builder'
			),
		);
	}


	private static function approval_comfort_profiles(): array {
		$active = sanitize_key( (string) get_option( 'agent_builder_approval_comfort', 'careful' ) );
		$cards  = array(
			array(
				'id'        => 'careful',
				'icon'      => '🛡️',
				'label'     => __( 'Always ask me', 'agent-builder' ),
				'summary'   => __( 'Safest default', 'agent-builder' ),
				'detail'    => __( 'Important or writing actions wait for you. Best when you want full control.', 'agent-builder' ),
				'risk_note' => __( 'No automatic approvals beyond the safest reads.', 'agent-builder' ),
				'auto_max'  => 'none',
				'mode'      => 'supervised',
				'needs_ack' => false,
			),
			array(
				'id'        => 'balanced',
				'icon'      => '⚖️',
				'label'     => __( 'Auto-approve low risk', 'agent-builder' ),
				'summary'   => __( 'Recommended for most sites', 'agent-builder' ),
				'detail'    => __( 'Simple look-ups run freely. Drafts and bigger changes still pause for confirmation or this queue.', 'agent-builder' ),
				'risk_note' => __( 'You accept that low-risk tools may run without a separate approval email.', 'agent-builder' ),
				'auto_max'  => 'low',
				'mode'      => 'supervised',
				'needs_ack' => false,
			),
			array(
				'id'        => 'hands_off',
				'icon'      => '⚡',
				'label'     => __( 'Trust more (higher risk)', 'agent-builder' ),
				'summary'   => __( 'Faster — use with care', 'agent-builder' ),
				'detail'    => __( 'Agents work with less interruption (autonomous mode). You can still review history. Extreme tools stay blocked.', 'agent-builder' ),
				'risk_note' => __( 'I understand agents may change content without waiting in this queue, and I accept that increased risk.', 'agent-builder' ),
				'auto_max'  => 'medium',
				'mode'      => 'autonomous',
				'needs_ack' => true,
			),
		);
		foreach ( $cards as &$c ) {
			$c['active'] = ( $c['id'] === $active );
		}
		unset( $c );
		return $cards;
	}


	private static function get_approval_prefs(): array {
		$email = sanitize_email( (string) get_option( 'agent_builder_approval_email_to', '' ) );
		if ( ! is_email( $email ) ) {
			$email = (string) get_option( 'admin_email' );
		}
		return array(
			'email_notify'  => (bool) get_option( 'agent_builder_approval_email_notify', false ),
			'email_to'      => $email,
			'comfort'       => sanitize_key( (string) get_option( 'agent_builder_approval_comfort', 'careful' ) ),
			'auto_max_risk' => sanitize_key( (string) get_option( 'agent_builder_approval_auto_max_risk', 'none' ) ),
			'risk_ack'      => (bool) get_option( 'agent_builder_approval_risk_ack', false ),
			'agent_mode'    => (string) get_option( 'agent_builder_agent_mode', 'supervised' ),
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
