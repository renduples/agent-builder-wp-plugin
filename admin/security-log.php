<?php
/**
 * Security Log — partial included by admin/logs.php.
 *
 * @package    Agent_Builder
 * @subpackage Admin
 * @since      0.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Handle cleanup action.
if ( isset( $_POST['agentic_cleanup_security_log'] ) && check_admin_referer( 'agentic_cleanup_security_log' ) ) {
	$agentic_days    = isset( $_POST['cleanup_days'] ) ? absint( $_POST['cleanup_days'] ) : 30;
	$agentic_deleted = \Agentic\Security_Log::cleanup( $agentic_days );

	echo '<div class="notice notice-success is-dismissible"><p>';
	/* translators: %d: number of deleted log entries */
	printf( esc_html__( 'Deleted %d old security log entries.', 'agent-builder' ), (int) $agentic_deleted );
	echo '</p></div>';
}

// Filter / pagination params.
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filters.
$agentic_event_type    = isset( $_GET['event_type'] ) ? sanitize_text_field( wp_unslash( $_GET['event_type'] ) ) : '';
$agentic_period_filter = sanitize_key( wp_unslash( $_GET['period'] ?? 'week' ) );
$agentic_paged         = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;
// phpcs:enable

if ( ! in_array( $agentic_period_filter, array( 'day', 'week', 'month' ), true ) ) {
	$agentic_period_filter = 'day';
}

$agentic_period_labels = array(
	'day'   => 'Today',
	'week'  => 'Last 7 Days',
	'month' => 'Last 30 Days',
);

$agentic_period_days = match ( $agentic_period_filter ) {
	'week'  => 7,
	'month' => 30,
	default => 1,
};

$agentic_per_page = 50;

$agentic_query_args = array(
	'days'   => $agentic_period_days,
	'limit'  => $agentic_per_page,
	'offset' => ( $agentic_paged - 1 ) * $agentic_per_page,
);
if ( ! empty( $agentic_event_type ) ) {
	$agentic_query_args['event_type'] = $agentic_event_type;
}

$agentic_events       = \Agentic\Security_Log::get_events( $agentic_query_args );
$agentic_total_items  = \Agentic\Security_Log::get_count( $agentic_query_args );
$agentic_total_pages  = (int) ceil( $agentic_total_items / $agentic_per_page );
$agentic_stats        = \Agentic\Security_Log::get_stats( $agentic_period_days );
$agentic_top_ips      = \Agentic\Security_Log::get_top_ips( 5, $agentic_period_days );
$agentic_top_patterns = \Agentic\Security_Log::get_top_patterns( 5, $agentic_period_days );
?>

<p class="agentic-page-desc">
	<?php esc_html_e( 'Captures every request your agents blocked or flagged — malicious input patterns, rate-limit breaches, and PII warnings.', 'agent-builder' ); ?>
	<?php esc_html_e( 'Use this tab to detect abuse, tune your security rules, and confirm your agents are protecting user data.', 'agent-builder' ); ?>
	&middot; <a href="<?php echo esc_url( admin_url( 'admin.php?page=agentic-settings&tab=security' ) ); ?>"><?php esc_html_e( 'Security settings', 'agent-builder' ); ?></a>
</p>

<!-- Period picker -->
<div class="agentic-period-pills">
	<?php foreach ( $agentic_period_labels as $agentic_p_slug => $agentic_p_label ) : ?>
		<a href="
		<?php
		echo esc_url(
			add_query_arg(
				array(
					'page'       => 'agentic-audit-log',
					'tab'        => 'security',
					'period'     => $agentic_p_slug,
					'event_type' => $agentic_event_type,
				),
				admin_url( 'admin.php' )
			)
		);
		?>
					"
			class="button<?php echo $agentic_period_filter === $agentic_p_slug ? ' button-primary' : ''; ?>">
			<?php echo esc_html( $agentic_p_label ); ?>
		</a>
	<?php endforeach; ?>
</div>

<!-- ── Stat cards ─────────────────────────────────────────────────── -->
<div class="agentic-stats-grid">

	<div class="agentic-stat-card agentic-stat-card-red">
		<div class="agentic-stat-card-title"><?php esc_html_e( 'Blocked Messages', 'agent-builder' ); ?></div>
		<div class="agentic-stat-card-value"><?php echo esc_html( number_format_i18n( (int) $agentic_stats['blocked_count'] ) ); ?></div>
	</div>

	<div class="agentic-stat-card agentic-stat-card-amber">
		<div class="agentic-stat-card-title"><?php esc_html_e( 'Rate Limited', 'agent-builder' ); ?></div>
		<div class="agentic-stat-card-value"><?php echo esc_html( number_format_i18n( (int) $agentic_stats['rate_limited_count'] ) ); ?></div>
	</div>

	<div class="agentic-stat-card agentic-stat-card-blue">
		<div class="agentic-stat-card-title"><?php esc_html_e( 'PII Warnings', 'agent-builder' ); ?></div>
		<div class="agentic-stat-card-value"><?php echo esc_html( number_format_i18n( (int) $agentic_stats['pii_warning_count'] ) ); ?></div>
	</div>

	<div class="agentic-stat-card agentic-stat-card-green">
		<div class="agentic-stat-card-title"><?php esc_html_e( 'Unique IPs', 'agent-builder' ); ?></div>
		<div class="agentic-stat-card-value"><?php echo esc_html( number_format_i18n( (int) $agentic_stats['unique_ips'] ) ); ?></div>
	</div>

</div>

<!-- ── Insight cards ──────────────────────────────────────────────── -->
<div class="agentic-grid-2col">

	<div class="agentic-insight-card">
		<h3>
		<?php
		printf(
			/* translators: %s: time period label (e.g. "Last 7 days") */
			esc_html__( 'Top Blocked Patterns (%s)', 'agent-builder' ),
			esc_html( $agentic_period_labels[ $agentic_period_filter ] )
		);
		?>
	</h3>
		<?php if ( ! empty( $agentic_top_patterns ) ) : ?>
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'Pattern', 'agent-builder' ); ?></th>
					<th class="agentic-col-80"><?php esc_html_e( 'Hits', 'agent-builder' ); ?></th>
				</tr></thead>
				<tbody>
					<?php foreach ( $agentic_top_patterns as $agentic_pattern ) : ?>
						<tr>
							<td><code><?php echo esc_html( substr( $agentic_pattern['pattern_matched'], 0, 50 ) ); ?></code></td>
							<td><?php echo esc_html( number_format_i18n( (int) $agentic_pattern['count'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php else : ?>
			<p class="agentic-text-muted"><?php esc_html_e( 'No blocked patterns in the last 7 days.', 'agent-builder' ); ?></p>
		<?php endif; ?>
	</div>

	<div class="agentic-insight-card">
		<h3>
		<?php
		printf(
			/* translators: %s: time period label (e.g. "Last 7 days") */
			esc_html__( 'Top Offending IPs (%s)', 'agent-builder' ),
			esc_html( $agentic_period_labels[ $agentic_period_filter ] )
		);
		?>
	</h3>
		<?php if ( ! empty( $agentic_top_ips ) ) : ?>
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'IP Address', 'agent-builder' ); ?></th>
					<th class="agentic-col-80"><?php esc_html_e( 'Events', 'agent-builder' ); ?></th>
				</tr></thead>
				<tbody>
					<?php foreach ( $agentic_top_ips as $agentic_ip_data ) : ?>
						<tr>
							<td><code><?php echo esc_html( $agentic_ip_data['ip_address'] ); ?></code></td>
							<td><?php echo esc_html( number_format_i18n( (int) $agentic_ip_data['event_count'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php else : ?>
			<p class="agentic-text-muted"><?php esc_html_e( 'No security events in the last 7 days.', 'agent-builder' ); ?></p>
		<?php endif; ?>
	</div>

</div>

<!-- ── Filter + table nav ─────────────────────────────────────────── -->
<form method="get">
	<input type="hidden" name="page" value="agentic-audit-log">
	<input type="hidden" name="tab" value="security">
	<input type="hidden" name="period" value="<?php echo esc_attr( $agentic_period_filter ); ?>">
	<div class="tablenav top">
		<div class="alignleft actions">
			<label for="event-type-filter" class="screen-reader-text"><?php esc_html_e( 'Filter by event type', 'agent-builder' ); ?></label>
			<select name="event_type" id="event-type-filter" onchange="this.form.submit()">
				<option value=""><?php esc_html_e( 'All Events', 'agent-builder' ); ?></option>
				<optgroup label="<?php esc_attr_e( 'Security', 'agent-builder' ); ?>">
					<option value="blocked" <?php selected( $agentic_event_type, 'blocked' ); ?>><?php esc_html_e( 'Blocked', 'agent-builder' ); ?></option>
					<option value="rate_limited" <?php selected( $agentic_event_type, 'rate_limited' ); ?>><?php esc_html_e( 'Rate Limited', 'agent-builder' ); ?></option>
					<option value="pii_warning" <?php selected( $agentic_event_type, 'pii_warning' ); ?>><?php esc_html_e( 'PII Warning', 'agent-builder' ); ?></option>
				</optgroup>
				<optgroup label="<?php esc_attr_e( 'Plugin', 'agent-builder' ); ?>">
					<option value="plugin_activated" <?php selected( $agentic_event_type, 'plugin_activated' ); ?>><?php esc_html_e( 'Plugin Activated', 'agent-builder' ); ?></option>
					<option value="plugin_upgrade_started" <?php selected( $agentic_event_type, 'plugin_upgrade_started' ); ?>><?php esc_html_e( 'Plugin Upgrade Started', 'agent-builder' ); ?></option>
					<option value="plugin_upgraded" <?php selected( $agentic_event_type, 'plugin_upgraded' ); ?>><?php esc_html_e( 'Plugin Upgraded', 'agent-builder' ); ?></option>
					<option value="plugin_deactivated" <?php selected( $agentic_event_type, 'plugin_deactivated' ); ?>><?php esc_html_e( 'Plugin Deactivated', 'agent-builder' ); ?></option>
					<option value="settings_changed" <?php selected( $agentic_event_type, 'settings_changed' ); ?>><?php esc_html_e( 'Settings Changed', 'agent-builder' ); ?></option>
				</optgroup>
				<optgroup label="<?php esc_attr_e( 'Agents', 'agent-builder' ); ?>">
					<option value="agent_activate_started" <?php selected( $agentic_event_type, 'agent_activate_started' ); ?>><?php esc_html_e( 'Agent Activate Started', 'agent-builder' ); ?></option>
					<option value="agent_activated" <?php selected( $agentic_event_type, 'agent_activated' ); ?>><?php esc_html_e( 'Agent Activated', 'agent-builder' ); ?></option>
					<option value="agent_activate_failed" <?php selected( $agentic_event_type, 'agent_activate_failed' ); ?>><?php esc_html_e( 'Agent Activate Failed', 'agent-builder' ); ?></option>
					<option value="agent_deactivate_started" <?php selected( $agentic_event_type, 'agent_deactivate_started' ); ?>><?php esc_html_e( 'Agent Deactivate Started', 'agent-builder' ); ?></option>
					<option value="agent_deactivated" <?php selected( $agentic_event_type, 'agent_deactivated' ); ?>><?php esc_html_e( 'Agent Deactivated', 'agent-builder' ); ?></option>
					<option value="agent_install_started" <?php selected( $agentic_event_type, 'agent_install_started' ); ?>><?php esc_html_e( 'Agent Install Started', 'agent-builder' ); ?></option>
					<option value="agent_installed" <?php selected( $agentic_event_type, 'agent_installed' ); ?>><?php esc_html_e( 'Agent Installed', 'agent-builder' ); ?></option>
					<option value="agent_install_failed" <?php selected( $agentic_event_type, 'agent_install_failed' ); ?>><?php esc_html_e( 'Agent Install Failed', 'agent-builder' ); ?></option>
					<option value="agent_update_started" <?php selected( $agentic_event_type, 'agent_update_started' ); ?>><?php esc_html_e( 'Agent Update Started', 'agent-builder' ); ?></option>
					<option value="agent_update_succeeded" <?php selected( $agentic_event_type, 'agent_update_succeeded' ); ?>><?php esc_html_e( 'Agent Update Succeeded', 'agent-builder' ); ?></option>
					<option value="agent_update_failed" <?php selected( $agentic_event_type, 'agent_update_failed' ); ?>><?php esc_html_e( 'Agent Update Failed', 'agent-builder' ); ?></option>
					<option value="agent_delete_started" <?php selected( $agentic_event_type, 'agent_delete_started' ); ?>><?php esc_html_e( 'Agent Delete Started', 'agent-builder' ); ?></option>
					<option value="agent_deleted" <?php selected( $agentic_event_type, 'agent_deleted' ); ?>><?php esc_html_e( 'Agent Deleted', 'agent-builder' ); ?></option>
					<option value="agent_delete_failed" <?php selected( $agentic_event_type, 'agent_delete_failed' ); ?>><?php esc_html_e( 'Agent Delete Failed', 'agent-builder' ); ?></option>
				</optgroup>
				<optgroup label="<?php esc_attr_e( 'Approvals', 'agent-builder' ); ?>">
					<option value="action_approved" <?php selected( $agentic_event_type, 'action_approved' ); ?>><?php esc_html_e( 'Action Approved', 'agent-builder' ); ?></option>
					<option value="action_approve_failed" <?php selected( $agentic_event_type, 'action_approve_failed' ); ?>><?php esc_html_e( 'Action Approve Failed', 'agent-builder' ); ?></option>
					<option value="action_rejected" <?php selected( $agentic_event_type, 'action_rejected' ); ?>><?php esc_html_e( 'Action Rejected', 'agent-builder' ); ?></option>
					<option value="action_reject_failed" <?php selected( $agentic_event_type, 'action_reject_failed' ); ?>><?php esc_html_e( 'Action Reject Failed', 'agent-builder' ); ?></option>
				</optgroup>
				<optgroup label="<?php esc_attr_e( 'Tools', 'agent-builder' ); ?>">
					<option value="tool_enabled" <?php selected( $agentic_event_type, 'tool_enabled' ); ?>><?php esc_html_e( 'Tool Enabled', 'agent-builder' ); ?></option>
					<option value="tool_disabled" <?php selected( $agentic_event_type, 'tool_disabled' ); ?>><?php esc_html_e( 'Tool Disabled', 'agent-builder' ); ?></option>
				</optgroup>
			</select>
		</div>
		<div class="alignright">
			<button type="button" class="button" onclick="document.getElementById('agentic-cleanup-dialog').classList.add('active')">
				<?php esc_html_e( 'Clean Up Old Logs', 'agent-builder' ); ?>
			</button>
		</div>
		<br class="clear">
	</div>
</form>

<!-- ── Events table ──────────────────────────────────────────────── -->
<table class="wp-list-table widefat fixed striped">
	<thead>
		<tr>
			<th class="agentic-col-160"><?php esc_html_e( 'Date / Time', 'agent-builder' ); ?></th>
			<th class="agentic-col-120"><?php esc_html_e( 'Event', 'agent-builder' ); ?></th>
			<th class="agentic-col-130"><?php esc_html_e( 'User', 'agent-builder' ); ?></th>
			<th class="agentic-col-130"><?php esc_html_e( 'IP Address', 'agent-builder' ); ?></th>
			<th><?php esc_html_e( 'Details', 'agent-builder' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php if ( ! empty( $agentic_events ) ) : ?>
			<?php foreach ( $agentic_events as $agentic_event ) : ?>
				<?php
				$agentic_ts          = strtotime( $agentic_event['created_at'] . ' UTC' );
				$agentic_badge_class = match ( true ) {
					'blocked' === $agentic_event['event_type']                                                                                                                                                                    => 'agentic-event-badge-blocked',
					'rate_limited' === $agentic_event['event_type']                                                                                                                                                               => 'agentic-event-badge-rate-limited',
					'pii_warning' === $agentic_event['event_type']                                                                                                                                                                => 'agentic-event-badge-pii',
					in_array( $agentic_event['event_type'], array( 'agent_activated', 'agent_installed', 'agent_update_succeeded', 'tool_enabled', 'plugin_activated', 'plugin_upgraded', 'action_approved' ), true )              => 'agentic-event-badge-system-green',
					in_array( $agentic_event['event_type'], array( 'agent_deactivated', 'agent_deleted', 'tool_disabled', 'action_rejected', 'plugin_deactivated' ), true )                                                       => 'agentic-event-badge-system-amber',
					in_array( $agentic_event['event_type'], array( 'agent_activate_failed', 'agent_install_failed', 'agent_update_failed', 'agent_delete_failed', 'action_approve_failed', 'action_reject_failed' ), true )        => 'agentic-event-badge-blocked',
					in_array( $agentic_event['event_type'], array( 'agent_activate_started', 'agent_deactivate_started', 'agent_install_started', 'agent_update_started', 'agent_delete_started', 'plugin_upgrade_started' ), true ) => 'agentic-event-badge-default',
					default => 'agentic-event-badge-default',
				};
	?>
				<tr>
					<td class="agentic-td-sm"><?php echo esc_html( wp_date( get_option( 'date_format' ) . ', ' . get_option( 'time_format' ), $agentic_ts ) ); ?></td>
					<td>
						<span class="agentic-event-badge <?php echo esc_attr( $agentic_badge_class ); ?>">
							<?php echo esc_html( strtoupper( str_replace( '_', ' ', $agentic_event['event_type'] ) ) ); ?>
						</span>
					</td>
					<td class="agentic-td-sm">
						<?php
						if ( $agentic_event['user_id'] > 0 ) {
							$agentic_user = get_userdata( $agentic_event['user_id'] );
							echo esc_html( $agentic_user ? $agentic_user->user_login : 'User #' . $agentic_event['user_id'] );
						} else {
							esc_html_e( 'Anonymous', 'agent-builder' );
						}
						?>
					</td>
					<td class="agentic-td-sm"><code><?php echo esc_html( $agentic_event['ip_address'] ); ?></code></td>
					<td>
						<?php
						$agentic_detail_parts = array();
						if ( ! empty( $agentic_event['message'] ) ) {
							$agentic_detail_parts[] = 'message: ' . $agentic_event['message'];
						}
						if ( ! empty( $agentic_event['pattern_matched'] ) ) {
							$agentic_detail_parts[] = 'pattern: ' . $agentic_event['pattern_matched'];
						}
						if ( ! empty( $agentic_event['pii_types'] ) ) {
							$agentic_detail_parts[] = 'pii: ' . $agentic_event['pii_types'];
						}
						if ( ! empty( $agentic_detail_parts ) ) :
							?>
							<details>
								<summary>View</summary>
								<pre class="agentic-code-pre"><?php echo esc_html( implode( "\n", $agentic_detail_parts ) ); ?></pre>
							</details>
						<?php else : ?>
							—
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		<?php else : ?>
			<tr>
				<td colspan="5" class="agentic-td-empty-center">
					<?php esc_html_e( 'No security events found.', 'agent-builder' ); ?>
				</td>
			</tr>
		<?php endif; ?>
	</tbody>
</table>

<!-- Pagination -->
<?php if ( $agentic_total_pages > 1 ) : ?>
	<div class="tablenav bottom">
		<div class="tablenav-pages">
			<?php
			echo wp_kses_post(
				paginate_links(
					array(
						'base'      => add_query_arg(
							array(
								'page'  => 'agentic-audit-log',
								'tab'   => 'security',
								'paged' => '%#%',
							),
							admin_url( 'admin.php' )
						),
						'format'    => '',
						'current'   => $agentic_paged,
						'total'     => $agentic_total_pages,
						'prev_text' => __( '&laquo; Previous', 'agent-builder' ),
						'next_text' => __( 'Next &raquo;', 'agent-builder' ),
					)
				)
			);
			?>
		</div>
	</div>
<?php endif; ?>

<!-- ── Cleanup dialog ─────────────────────────────────────────────── -->
<div id="agentic-cleanup-dialog" class="agentic-dialog-overlay" role="dialog" aria-modal="true" aria-labelledby="agentic-cleanup-title">
	<div class="agentic-dialog-box">
		<h2 id="agentic-cleanup-title"><?php esc_html_e( 'Clean Up Old Logs', 'agent-builder' ); ?></h2>
		<p><?php esc_html_e( 'Permanently delete security log entries older than the specified number of days.', 'agent-builder' ); ?></p>
		<form method="post">
			<?php wp_nonce_field( 'agentic_cleanup_security_log' ); ?>
			<p>
				<label>
					<?php esc_html_e( 'Delete entries older than:', 'agent-builder' ); ?>
					<input type="number" name="cleanup_days" value="30" min="1" max="365" class="agentic-input-num-sm"> <?php esc_html_e( 'days', 'agent-builder' ); ?>
				</label>
			</p>
			<p>
				<button type="submit" name="agentic_cleanup_security_log" class="button button-primary">
					<?php esc_html_e( 'Delete Now', 'agent-builder' ); ?>
				</button>
				<button type="button" class="button" onclick="document.getElementById('agentic-cleanup-dialog').classList.remove('active')">
					<?php esc_html_e( 'Cancel', 'agent-builder' ); ?>
				</button>
			</p>
		</form>
	</div>
</div>

<?php \Agentic\Inline_Assets::script( 'security-log' ); ?>
