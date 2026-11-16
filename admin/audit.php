<?php
/**
 * Agentic Audit Log Page
 *
 * @package    Agent_Builder
 * @subpackage Admin
 * @author     Siteloom Team <support@agentic-plugin.com>
 * @license    GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 * @link       https://agentic-plugin.com
 * @since      0.1.0
 *
 * @wordpress-plugin
 * php version 8.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Agentic\Audit_Log;

$agentic_audit = new Audit_Log();

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filters.
$agentic_agent_filter  = sanitize_text_field( wp_unslash( $_GET['agent'] ?? '' ) );
$agentic_action_filter = sanitize_text_field( wp_unslash( $_GET['action'] ?? '' ) );
$agentic_period_filter = sanitize_key( wp_unslash( $_GET['period'] ?? 'week' ) );
$agentic_show_chat     = ! empty( $_GET['show_chat'] );
// phpcs:enable

if ( ! in_array( $agentic_period_filter, array( 'day', 'week', 'month' ), true ) ) {
	$agentic_period_filter = 'day';
}

// Chat events are covered by the Conversations tab — exclude them unless explicitly requested.
$agentic_chat_actions = array( 'chat_start', 'chat_complete' );
$agentic_exclude      = ( $agentic_show_chat || $agentic_action_filter ) ? array() : $agentic_chat_actions;

$agentic_period_limit = match ( $agentic_period_filter ) {
	'week'  => 500,
	'month' => 2000,
	default => 200,
};

$agentic_period_labels = array(
	'day'   => 'Today',
	'week'  => 'Last 7 Days',
	'month' => 'Last 30 Days',
);

$agentic_logs = $agentic_audit->get_recent(
	$agentic_period_limit,
	$agentic_agent_filter ? $agentic_agent_filter : null,
	$agentic_action_filter ? $agentic_action_filter : null,
	$agentic_period_filter,
	$agentic_exclude
);
?>
<p class="agentic-page-desc">
	<?php esc_html_e( 'Records every action your AI agents took — tool calls, code proposals, approvals, settings changes, and errors.', 'siteloom' ); ?>
	<?php esc_html_e( 'Use this log to understand what your agents did, how many tokens they consumed, and what it cost.', 'siteloom' ); ?>
	<?php esc_html_e( 'Chat message content lives in the', 'siteloom' ); ?>
	<a href="<?php echo esc_url( admin_url( 'admin.php?page=agentic-audit-log&tab=conversations' ) ); ?>"><?php esc_html_e( 'Conversations', 'siteloom' ); ?></a>
	<?php esc_html_e( 'tab.', 'siteloom' ); ?>
	&middot; <a href="<?php echo esc_url( admin_url( 'admin.php?page=agentic-settings&tab=security' ) ); ?>"><?php esc_html_e( 'Manage retention', 'siteloom' ); ?></a>
</p>

<!-- Period picker -->
<div class="agentic-period-pills">
	<?php foreach ( $agentic_period_labels as $agentic_p_slug => $agentic_p_label ) : ?>
		<?php
		$agentic_p_url    = add_query_arg(
			array(
				'page'      => 'agentic-audit-log',
				'tab'       => 'audit',
				'period'    => $agentic_p_slug,
				'agent'     => $agentic_agent_filter,
				'action'    => $agentic_action_filter,
				'show_chat' => $agentic_show_chat ? 1 : false,
			),
			admin_url( 'admin.php' )
		);
		$agentic_p_active = $agentic_period_filter === $agentic_p_slug;
		?>
		<a href="<?php echo esc_url( $agentic_p_url ); ?>"
			class="button<?php echo $agentic_p_active ? ' button-primary' : ''; ?>">
			<?php echo esc_html( $agentic_p_label ); ?>
		</a>
	<?php endforeach; ?>
</div>

<!-- Filter bar -->
<form method="get" action="">
	<input type="hidden" name="page" value="agentic-audit-log">
	<input type="hidden" name="tab" value="audit">
	<input type="hidden" name="period" value="<?php echo esc_attr( $agentic_period_filter ); ?>">
	<?php if ( $agentic_show_chat ) : ?>
		<input type="hidden" name="show_chat" value="1">
	<?php endif; ?>

	<div class="tablenav top">
		<div class="alignleft actions">
			<label for="agentic-agent-filter" class="screen-reader-text"><?php esc_html_e( 'Filter by agent', 'siteloom' ); ?></label>
			<select name="agent" id="agentic-agent-filter">
				<option value=""><?php esc_html_e( 'All Agents', 'siteloom' ); ?></option>
				<?php foreach ( $agentic_audit->get_agent_ids() as $agentic_aid ) : ?>
					<option value="<?php echo esc_attr( $agentic_aid ); ?>" <?php selected( $agentic_agent_filter, $agentic_aid ); ?>>
						<?php echo esc_html( Audit_Log::human_agent( $agentic_aid ) ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<label for="agentic-action-filter" class="screen-reader-text"><?php esc_html_e( 'Filter by action', 'siteloom' ); ?></label>
			<select name="action" id="agentic-action-filter">
				<option value=""><?php esc_html_e( 'All Actions', 'siteloom' ); ?></option>
				<?php foreach ( $agentic_audit->get_action_types() as $agentic_act ) : ?>
					<option value="<?php echo esc_attr( $agentic_act ); ?>" <?php selected( $agentic_action_filter, $agentic_act ); ?>>
						<?php echo esc_html( Audit_Log::human_action( $agentic_act ) ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<input type="submit" class="button" value="<?php esc_attr_e( 'Filter', 'siteloom' ); ?>">
			<?php if ( $agentic_agent_filter || $agentic_action_filter ) : ?>
				<a href="
				<?php
				echo esc_url(
					add_query_arg(
						array(
							'page'   => 'agentic-audit-log',
							'tab'    => 'audit',
							'period' => $agentic_period_filter,
						),
						admin_url( 'admin.php' )
					)
				);
				?>
							" class="button"><?php esc_html_e( 'Clear', 'siteloom' ); ?></a>
			<?php endif; ?>
		</div>
		<div class="alignright">
			<?php if ( $agentic_show_chat ) : ?>
				<a href="
				<?php
				echo esc_url(
					add_query_arg(
						array(
							'page'      => 'agentic-audit-log',
							'tab'       => 'audit',
							'period'    => $agentic_period_filter,
							'show_chat' => false,
						),
						admin_url( 'admin.php' )
					)
				);
				?>
							" class="button"><?php esc_html_e( 'Hide chat events', 'siteloom' ); ?></a>
			<?php else : ?>
				<a href="
				<?php
				echo esc_url(
					add_query_arg(
						array(
							'page'      => 'agentic-audit-log',
							'tab'       => 'audit',
							'period'    => $agentic_period_filter,
							'show_chat' => 1,
						),
						admin_url( 'admin.php' )
					)
				);
				?>
							" class="button"><?php esc_html_e( 'Show chat events', 'siteloom' ); ?></a>
			<?php endif; ?>
		</div>
		<br class="clear">
	</div>
</form>

	<?php if ( empty( $agentic_logs ) ) : ?>
		<div class="notice notice-info">
			<p>No audit log entries found for <strong><?php echo esc_html( $agentic_period_labels[ $agentic_period_filter ] ); ?></strong>.</p>
		</div>
	<?php else : ?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th>Time</th>
					<th>Agent</th>
					<th>Action</th>
					<th>Target</th>
					<th>Permission</th>
					<th>Cached</th>
					<th>Tokens</th>
					<th>Cost</th>
					<th>User</th>
					<th>Reasoning</th>
					<th>Details</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $agentic_logs as $agentic_entry ) : ?>
				<tr>
					<td>
						<?php
						$agentic_timestamp = strtotime( $agentic_entry['created_at'] . ' UTC' );
						echo esc_html( wp_date( get_option( 'date_format' ) . ', ' . get_option( 'time_format' ), $agentic_timestamp ) );
						?>
					</td>
					<td title="<?php echo esc_attr( $agentic_entry['agent_id'] ); ?>"><?php echo esc_html( Audit_Log::human_agent( $agentic_entry['agent_id'] ) ); ?></td>
					<td title="<?php echo esc_attr( $agentic_entry['action'] ); ?>"><?php echo esc_html( Audit_Log::human_action( $agentic_entry['action'] ) ); ?></td>
					<td>
						<?php if ( $agentic_entry['target_type'] ) : ?>
							<span title="<?php echo esc_attr( $agentic_entry['target_type'] ); ?>"><?php echo esc_html( Audit_Log::human_target( $agentic_entry['target_type'] ) ); ?></span>
							<?php if ( $agentic_entry['target_id'] ) : ?>
								<small>(<?php echo esc_html( $agentic_entry['target_id'] ); ?>)</small>
							<?php endif; ?>
						<?php else : ?>
							—
						<?php endif; ?>
					</td>					<td>
						<?php
						$agentic_mode       = $agentic_entry['mode'] ?? '';
						$agentic_mode_label = Audit_Log::human_mode( $agentic_mode );
						echo esc_html( ! empty( $agentic_mode_label ) ? $agentic_mode_label : '—' );
						?>
					</td>					<td>
						<?php if ( 'cache_hit' === $agentic_entry['action'] ) : ?>
							<span class="agentic-text-success" title="Served from cache">✓ Yes</span>
						<?php elseif ( in_array( $agentic_entry['action'], array( 'chat_complete', 'chat_start', 'chat_error', 'autonomous_chat_complete', 'autonomous_chat_start' ), true ) ) : ?>
							<span class="agentic-text-muted">No</span>
						<?php else : ?>
							—
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( number_format( (int) ( $agentic_entry['tokens_used'] ?? 0 ) ) ); ?></td>
					<td>$<?php echo esc_html( number_format( (float) ( $agentic_entry['cost'] ?? 0 ), 6 ) ); ?></td>
					<td>
						<?php
						if ( $agentic_entry['user_id'] ) {
							$agentic_user = get_user_by( 'id', $agentic_entry['user_id'] );
							echo esc_html( $agentic_user ? $agentic_user->display_name : 'User #' . $agentic_entry['user_id'] );
						} else {
							echo '-';
						}
						?>
					</td>
					<td>
						<?php if ( ! empty( $agentic_entry['reasoning'] ) ) : ?>
							<details class="agentic-reasoning">
								<summary><?php echo esc_html( substr( $agentic_entry['reasoning'], 0, 110 ) ); ?><?php echo strlen( $agentic_entry['reasoning'] ) > 110 ? '…' : ''; ?></summary>
								<pre class="agentic-code-pre"><?php echo esc_html( $agentic_entry['reasoning'] ); ?></pre>
							</details>
						<?php else : ?>
							<span class="agentic-text-muted">—</span>
						<?php endif; ?>
					</td>
					<td>
						<?php if ( $agentic_entry['details'] ) : ?>
							<details>
								<summary>View</summary>
								<pre class="agentic-code-pre"><?php echo esc_html( $agentic_entry['details'] ); ?></pre>
							</details>
						<?php else : ?>
							-
						<?php endif; ?>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
