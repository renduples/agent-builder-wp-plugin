<?php
/**
 * Logs_Payload builder for admin page REST payload.
 *
 * @package Agent_Builder
 */

declare(strict_types=1);

namespace Agentic\Admin_Pages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Logs_Payload {

	public static function build( string $tab, string $period = 'week' ): array {
		$rows          = array();
		$stats         = array(
			'total'     => 0,
			'chats'     => 0,
			'tools'     => 0,
			'approvals' => 0,
			'security'  => 0,
			'tokens'    => 0,
		);
		$is_advanced   = class_exists( \Agentic\Admin_Menu_Handler::class )
			? \Agentic\Admin_Menu_Handler::is_advanced_mode( 'logs' )
			: ( 'advanced' === get_option( 'agent_builder_ui_mode', 'basic' ) );
		$period_limits = array(
			'day'   => 200,
			'week'  => 500,
			'month' => 1500,
		);
		$limit         = $period_limits[ $period ] ?? 500;

		$integrity = null;
		if ( 'audit' === $tab && class_exists( \Agentic\Audit_Log_Integrity::class ) ) {
			// Only computed for the tab that actually shows this table — walks
			// every row with no chunking, so it's deliberately not run on every
			// Activity page load regardless of which tab is open.
			$integrity = \Agentic\Audit_Log_Integrity::verify_chain();
		}

		if ( 'audit' === $tab && class_exists( \Agentic\Audit_Log::class ) ) {
			$log = new \Agentic\Audit_Log();
			// Hide noisy chat_start/complete by default (same as classic audit page).
			$exclude = array( 'chat_start', 'chat_complete' );
			$items   = $log->get_recent( $limit, null, null, $period, $exclude );
			foreach ( (array) $items as $item ) {
				$row    = self::humanize_audit_row( is_array( $item ) ? $item : (array) $item );
				$rows[] = $row;
				++$stats['total'];
				$kind = $row['kind'] ?? 'other';
				if ( 'chat' === $kind ) {
					++$stats['chats'];
				} elseif ( 'tool' === $kind ) {
					++$stats['tools'];
				} elseif ( 'approval' === $kind ) {
					++$stats['approvals'];
				} elseif ( 'security' === $kind ) {
					++$stats['security'];
				}
				$stats['tokens'] += (int) ( $row['tokens'] ?? 0 );
			}
		} elseif ( 'conversations' === $tab ) {
			global $wpdb;
			$table = $wpdb->prefix . 'agent_builder_conversations';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( $exists ) {
				$days = match ( $period ) {
					'week'  => 7,
					'month' => 30,
					default => 1,
				};
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; %i quotes table name.
				$items = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT id, agent_id, user_id, updated_at, created_at FROM %i
						WHERE updated_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
						ORDER BY updated_at DESC LIMIT %d',
						$table,
						$days,
						$limit
					),
					ARRAY_A
				);
				foreach ( (array) $items as $item ) {
					$agent  = (string) ( $item['agent_id'] ?? '' );
					$user   = absint( $item['user_id'] ?? 0 );
					$uname  = $user ? ( get_userdata( $user )->display_name ?? ( 'User #' . $user ) ) : __( 'Guest', 'agent-builder' );
					$rows[] = array(
						'id'         => (string) ( $item['id'] ?? '' ),
						'title'      => sprintf(
							/* translators: 1: agent name, 2: conversation id */
							__( 'Chat with %1$s (#%2$s)', 'agent-builder' ),
							self::friendly_agent_name( $agent ),
							(string) ( $item['id'] ?? '' )
						),
						'subtitle'   => $uname,
						'when'       => (string) ( $item['updated_at'] ?? $item['created_at'] ?? '' ),
						'when_human' => self::human_time_label( (string) ( $item['updated_at'] ?? $item['created_at'] ?? '' ) ),
						'detail'     => '',
						'kind'       => 'chat',
						'icon'       => '💬',
						'raw_action' => 'conversation',
						'tokens'     => 0,
					);
					++$stats['total'];
					++$stats['chats'];
				}
			}
		} elseif ( 'security' === $tab && class_exists( \Agentic\Security_Log::class ) ) {
			global $wpdb;
			$table = $wpdb->prefix . 'agent_builder_security_log';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( $exists ) {
				$days = match ( $period ) {
					'week'  => 7,
					'month' => 30,
					default => 1,
				};
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; %i quotes table name.
				$items = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT * FROM %i
						WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
						ORDER BY id DESC LIMIT %d',
						$table,
						$days,
						$limit
					),
					ARRAY_A
				);
				foreach ( (array) $items as $item ) {
					$event  = (string) ( $item['event'] ?? $item['action'] ?? 'security' );
					$rows[] = array(
						'id'         => (string) ( $item['id'] ?? '' ),
						'title'      => self::friendly_security_title( $event ),
						'subtitle'   => (string) ( $item['context'] ?? $item['source'] ?? '' ),
						'when'       => (string) ( $item['created_at'] ?? '' ),
						'when_human' => self::human_time_label( (string) ( $item['created_at'] ?? '' ) ),
						'detail'     => self::friendly_detail_string( (string) ( $item['message'] ?? $item['details'] ?? '' ) ),
						'kind'       => 'security',
						'icon'       => '🔒',
						'raw_action' => $event,
						'tokens'     => 0,
					);
					++$stats['total'];
					++$stats['security'];
				}
			}
		}

		$period_labels = array(
			'day'   => __( 'Today', 'agent-builder' ),
			'week'  => __( 'Last 7 days', 'agent-builder' ),
			'month' => __( 'Last 30 days', 'agent-builder' ),
		);

		return array(
			'page'           => 'logs',
			'tab'            => $tab,
			'title'          => __( 'Activity', 'agent-builder' ),
			'panel_title'    => match ( $tab ) {
				'conversations' => __( 'Recent conversations', 'agent-builder' ),
				'security'      => __( 'Security events', 'agent-builder' ),
				default         => __( 'What your agents have been doing', 'agent-builder' ),
			},
			'description'    => match ( $tab ) {
				'conversations' => __( 'Chats between people and agents on this site.', 'agent-builder' ),
				'security'      => __( 'Security-related events. Most sites stay quiet here.', 'agent-builder' ),
				default         => __( 'A simple timeline of agent work — tools used, approvals, and important system events. No technical jargon required.', 'agent-builder' ),
			},
			'rows'           => $rows,
			'stats'          => $stats,
			'period'         => $period,
			'period_options' => array(
				array(
					'id'    => 'day',
					'label' => $period_labels['day'],
				),
				array(
					'id'    => 'week',
					'label' => $period_labels['week'],
				),
				array(
					'id'    => 'month',
					'label' => $period_labels['month'],
				),
			),
			'kind_filters'   => array(
				array(
					'id'    => 'all',
					'label' => __( 'All', 'agent-builder' ),
				),
				array(
					'id'    => 'tool',
					'label' => __( 'Tools used', 'agent-builder' ),
				),
				array(
					'id'    => 'approval',
					'label' => __( 'Approvals', 'agent-builder' ),
				),
				array(
					'id'    => 'chat',
					'label' => __( 'Chats', 'agent-builder' ),
				),
				array(
					'id'    => 'settings',
					'label' => __( 'Settings', 'agent-builder' ),
				),
				array(
					'id'    => 'other',
					'label' => __( 'Other', 'agent-builder' ),
				),
			),
			'is_advanced'    => $is_advanced,
			'integrity'      => $integrity,
			'interface_url'  => admin_url( 'admin.php?page=agentic-settings&tab=interface' ),
			// Built by hand (not wp_nonce_url()): that helper HTML-entity-escapes
			// the "&" separators for embedding in server-rendered HTML, but this
			// URL is consumed as-is by React as a real href — entity-escaped
			// "&amp;" would reach the browser literally, mangling every param
			// after the first (including _wpnonce, which would then 403).
			'export_url'     => admin_url(
				'admin-post.php?action=agentic_export_logs&tab=' . rawurlencode( $tab )
				. '&period=' . rawurlencode( $period )
				. '&_wpnonce=' . wp_create_nonce( 'agentic_export_logs' )
			),
			'docs_url'       => 'https://agentic-plugin.com/documentation/activity/',
			'footer_policy'  => __(
				'Activity helps you understand what agents did on your site. Logs are stored locally and purged according to your retention settings. Chat content lives under Conversations.',
				'agent-builder'
			),
			'tabs'           => array(
				array(
					'id'    => 'audit',
					'label' => __( 'Timeline', 'agent-builder' ),
					'url'   => admin_url( 'admin.php?page=agentic-audit-log&tab=audit&period=' . rawurlencode( $period ) ),
				),
				array(
					'id'    => 'conversations',
					'label' => __( 'Conversations', 'agent-builder' ),
					'url'   => admin_url( 'admin.php?page=agentic-audit-log&tab=conversations&period=' . rawurlencode( $period ) ),
				),
				array(
					'id'    => 'security',
					'label' => __( 'Security', 'agent-builder' ),
					'url'   => admin_url( 'admin.php?page=agentic-audit-log&tab=security&period=' . rawurlencode( $period ) ),
				),
			),
		);
	}


	private static function humanize_audit_row( array $item ): array {
		$action  = (string) ( $item['action'] ?? 'event' );
		$agent   = (string) ( $item['agent_id'] ?? '' );
		$target  = (string) ( $item['target_type'] ?? '' );
		$when    = (string) ( $item['created_at'] ?? '' );
		$details = $item['details'] ?? '';
		if ( is_string( $details ) ) {
			$decoded = json_decode( $details, true );
			$details = is_array( $decoded ) ? $decoded : array( 'raw' => $details );
		}
		if ( ! is_array( $details ) ) {
			$details = array();
		}

		$kind  = 'other';
		$icon  = '•';
		$title = self::friendly_action_title( $action, $target, $details );

		if ( str_starts_with( $action, 'chat_' ) || 'conversation' === $target ) {
			$kind = 'chat';
			$icon = '💬';
		} elseif ( str_contains( $action, 'tool' ) || 'tool_call' === $action || 'tool_choice' === $action || 'tool_executed_on_approval' === $action ) {
			$kind = 'tool';
			$icon = '🔧';
			if ( $target && 'conversation' !== $target && 'tool_call' === $action ) {
				$title = sprintf(
					/* translators: %s: tool name */
					__( 'Used tool: %s', 'agent-builder' ),
					str_replace( '_', ' ', $target )
				);
			}
		} elseif ( str_contains( $action, 'approval' ) || str_contains( $action, 'approve' ) || str_contains( $action, 'reject' ) ) {
			$kind = 'approval';
			$icon = '✅';
		} elseif (
			str_contains( $action, 'settings' )
			|| str_contains( $action, 'mode' )
			|| str_contains( $action, 'profile' )
			|| str_contains( $action, 'deployment' )
			|| str_contains( $action, 'tool_enabled' )
			|| str_contains( $action, 'tool_disabled' )
			|| str_contains( $action, 'site_tool' )
			|| str_contains( $action, 'prefs' )
		) {
			$kind = 'settings';
			$icon = '⚙️';
		}

		$detail_bits = array();
		if ( ! empty( $item['reasoning'] ) ) {
			$detail_bits[] = (string) $item['reasoning'];
		}
		if ( 'endpoint_url_changed' === $action && ! empty( $details['previous'] ) && ! empty( $details['new'] ) ) {
			$detail_bits[] = sprintf( '%s → %s', $details['previous'], $details['new'] );
		}
		if ( 'knowledge_added' === $action && ! empty( $details['title'] ) ) {
			$detail_bits[] = sprintf( '"%s" (%s)', $details['title'], $details['source'] ?? 'wizard' );
		}
		if ( 'agent_installed' === $action && ! empty( $details['name'] ) ) {
			$detail_bits[] = sprintf( '%s (%s)', $details['name'], $details['category'] ?? 'admin' );
		}
		if ( ! empty( $details['message'] ) && is_string( $details['message'] ) ) {
			$detail_bits[] = $details['message'];
		}
		if ( ! empty( $details['error'] ) && is_string( $details['error'] ) ) {
			$detail_bits[] = $details['error'];
		}
		$tokens = (int) ( $item['tokens_used'] ?? 0 );
		if ( $tokens > 0 ) {
			$detail_bits[] = sprintf(
				/* translators: %s: token count */
				__( '%s tokens', 'agent-builder' ),
				number_format_i18n( $tokens )
			);
		}

		return array(
			'id'         => (string) ( $item['id'] ?? '' ),
			'title'      => $title,
			'subtitle'   => self::friendly_agent_name( $agent ),
			'when'       => $when,
			'when_human' => self::human_time_label( $when ),
			'detail'     => implode( ' · ', array_filter( $detail_bits ) ),
			'kind'       => $kind,
			'icon'       => $icon,
			'raw_action' => $action,
			'tokens'     => $tokens,
			'cost'       => (float) ( $item['cost'] ?? 0 ),
		);
	}


	private static function friendly_action_title( string $action, string $target, array $details ): string {
		$map = array(
			'chat_start'                 => __( 'Started a chat', 'agent-builder' ),
			'chat_complete'              => __( 'Finished a chat', 'agent-builder' ),
			'tool_call'                  => __( 'Used a tool', 'agent-builder' ),
			'tool_choice'                => __( 'Chose tools for a reply', 'agent-builder' ),
			'tool_executed_on_approval'  => __( 'Ran an approved action', 'agent-builder' ),
			'tool_enabled'               => __( 'Tool turned on', 'agent-builder' ),
			'tool_disabled'              => __( 'Tool turned off', 'agent-builder' ),
			'tools_profile_applied'      => __( 'Tools safety profile applied', 'agent-builder' ),
			'site_tool_created'          => __( 'Site-local tool created', 'agent-builder' ),
			'site_tool_updated'          => __( 'Site-local tool updated', 'agent-builder' ),
			'site_tool_deleted'          => __( 'Site-local tool deleted', 'agent-builder' ),
			'approval_queued'            => __( 'Action waiting for approval', 'agent-builder' ),
			'approval_approved'          => __( 'You approved an action', 'agent-builder' ),
			'approval_rejected'          => __( 'You rejected an action', 'agent-builder' ),
			'action_approved'            => __( 'You approved an action', 'agent-builder' ),
			'action_rejected'            => __( 'You rejected an action', 'agent-builder' ),
			'approval_prefs_saved'       => __( 'Approval preferences saved', 'agent-builder' ),
			'ui_mode_changed'            => __( 'Interface mode changed', 'agent-builder' ),
			'default_agent_mode_changed' => __( 'Default agent mode changed', 'agent-builder' ),
			'deployment_created'         => __( 'Deployment created', 'agent-builder' ),
			'deployment_updated'         => __( 'Deployment updated', 'agent-builder' ),
			'deployment_enabled'         => __( 'Deployment enabled', 'agent-builder' ),
			'deployment_disabled'        => __( 'Deployment disabled', 'agent-builder' ),
			'deployment_deleted'         => __( 'Deployment deleted', 'agent-builder' ),
			'settings_changed'           => __( 'Settings changed', 'agent-builder' ),
			'endpoint_url_changed'       => __( 'Changed a service endpoint URL', 'agent-builder' ),
			'knowledge_added'            => __( 'Added knowledge', 'agent-builder' ),
			'agent_activated'            => __( 'Agent activated', 'agent-builder' ),
			'agent_deactivated'          => __( 'Agent deactivated', 'agent-builder' ),
			'agent_installed'            => __( 'Agent created', 'agent-builder' ),
		);
		if ( isset( $map[ $action ] ) ) {
			return $map[ $action ];
		}
		return ucwords( str_replace( array( '_', '-' ), ' ', $action ) );
	}


	private static function friendly_agent_name( string $slug ): string {
		if ( '' === $slug || 'human' === $slug || 'system' === $slug ) {
			return __( 'You / system', 'agent-builder' );
		}
		if ( class_exists( '\Agentic_Agent_Registry' ) ) {
			$agent = \Agentic_Agent_Registry::get_instance()->get_agent_instance( $slug );
			if ( $agent && method_exists( $agent, 'get_name' ) ) {
				return $agent->get_name();
			}
		}
		return ucwords( str_replace( array( '-', '_' ), ' ', $slug ) );
	}


	private static function friendly_security_title( string $event ): string {
		$map = array(
			'approval_execution_exception' => __( 'Approved action failed while running', 'agent-builder' ),
			'chat_exception'               => __( 'Chat hit an error', 'agent-builder' ),
			'chat_error'                   => __( 'Chat error recorded', 'agent-builder' ),
		);
		return $map[ $event ] ?? ucwords( str_replace( array( '_', '-' ), ' ', $event ) );
	}


	private static function friendly_detail_string( string $raw ): string {
		$raw = trim( wp_strip_all_tags( $raw ) );
		if ( '' === $raw ) {
			return '';
		}
		if ( strlen( $raw ) > 160 ) {
			return substr( $raw, 0, 160 ) . '…';
		}
		return $raw;
	}


	private static function human_time_label( string $mysql_utc ): string {
		if ( '' === $mysql_utc ) {
			return '';
		}
		$ts = strtotime( $mysql_utc . ' UTC' );
		if ( ! $ts ) {
			$ts = strtotime( $mysql_utc );
		}
		if ( ! $ts ) {
			return $mysql_utc;
		}
		return sprintf(
			/* translators: %s: human time diff */
			__( '%s ago', 'agent-builder' ),
			human_time_diff( $ts, time() )
		);
	}

}
