<?php
/**
 * Conversations — Admin browser for all user chat sessions.
 *
 * @package    Agent_Builder
 * @subpackage Admin
 * @since      2.9.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;
$agentic_conv_table = $wpdb->prefix . 'agent_builder_conversations';

// ── Handle delete ────────────────────────────────────────────────────────────
$agentic_conv_notice = '';
if ( isset( $_GET['delete_session'], $_GET['_wpnonce'] )
	&& current_user_can( 'manage_options' )
	&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'agentic_conv_delete_' . sanitize_key( wp_unslash( $_GET['delete_session'] ) ) )
) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->delete( $agentic_conv_table, array( 'session_id' => sanitize_key( wp_unslash( $_GET['delete_session'] ) ) ), array( '%s' ) );
	$agentic_conv_notice = __( 'Conversation deleted.', 'siteloom' );
}

// ── Filters ──────────────────────────────────────────────────────────────────
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only.
$agentic_cv_agent  = isset( $_GET['cv_agent'] ) ? sanitize_key( wp_unslash( $_GET['cv_agent'] ) ) : '';
$agentic_cv_user   = isset( $_GET['cv_user'] ) ? absint( $_GET['cv_user'] ) : 0;
$agentic_cv_search = isset( $_GET['cv_search'] ) ? sanitize_text_field( wp_unslash( $_GET['cv_search'] ) ) : '';
$agentic_cv_page   = max( 1, absint( $_GET['cv_paged'] ?? 1 ) );
$agentic_cv_period = sanitize_key( wp_unslash( $_GET['period'] ?? 'week' ) );
// phpcs:enable

if ( ! in_array( $agentic_cv_period, array( 'day', 'week', 'month' ), true ) ) {
	$agentic_cv_period = 'day';
}

$agentic_cv_period_labels = array(
	'day'   => 'Today',
	'week'  => 'Last 7 Days',
	'month' => 'Last 30 Days',
);

$agentic_cv_period_days = match ( $agentic_cv_period ) {
	'week'  => 7,
	'month' => 30,
	default => 1,
};

$agentic_cv_per_page = 30;
$agentic_cv_offset   = ( $agentic_cv_page - 1 ) * $agentic_cv_per_page;

// Build where.
$agentic_cv_where  = 'c.created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)';
$agentic_cv_params = array( $agentic_cv_period_days );

if ( $agentic_cv_agent ) {
	$agentic_cv_where   .= ' AND c.agent_id = %s';
	$agentic_cv_params[] = $agentic_cv_agent;
}
if ( $agentic_cv_user ) {
	$agentic_cv_where   .= ' AND c.user_id = %d';
	$agentic_cv_params[] = $agentic_cv_user;
}
if ( $agentic_cv_search ) {
	$agentic_cv_where   .= ' AND c.content LIKE %s';
	$agentic_cv_params[] = '%' . $wpdb->esc_like( $agentic_cv_search ) . '%';
}

// Session aggregation query.
$agentic_cv_query_params = array_merge( array( $agentic_conv_table, $agentic_conv_table, $agentic_conv_table ), $agentic_cv_params, array( $agentic_cv_per_page, $agentic_cv_offset ) );
// $agentic_cv_where is built only from literal SQL fragments + %s/%d/%i placeholders;
// user values are in $agentic_cv_params and flow through $wpdb->prepare(). Safe to concatenate.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.CodeAnalysis.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter
$agentic_cv_sessions = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT c.session_id,
	c.agent_id,
	c.user_id,
	MIN(c.created_at) AS started,
	MAX(c.created_at) AS last_message,
	COUNT(*) AS message_count,
	(SELECT c2.content FROM %i c2 WHERE c2.session_id = c.session_id AND c2.role = 'user' ORDER BY c2.id ASC LIMIT 1) AS preview,
	GROUP_CONCAT(CASE WHEN c.tools_used != '' THEN c.tools_used ELSE NULL END SEPARATOR ',') AS all_tools,
	(SELECT c3.feedback FROM %i c3 WHERE c3.session_id = c.session_id AND c3.role = 'assistant' ORDER BY c3.id DESC LIMIT 1) AS feedback
FROM %i c
WHERE " . $agentic_cv_where . '
GROUP BY c.session_id, c.agent_id, c.user_id
ORDER BY last_message DESC
LIMIT %d OFFSET %d',
		...$agentic_cv_query_params
	),
	ARRAY_A
);
// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.CodeAnalysis.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter

// Total count.
$agentic_cv_total = ! empty( $agentic_cv_params )
	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.CodeAnalysis.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter
	? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT session_id) FROM %i c WHERE ' . $agentic_cv_where, $agentic_conv_table, ...$agentic_cv_params ) )
	// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.CodeAnalysis.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	: (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT session_id) FROM %i', $agentic_conv_table ) );
$agentic_cv_total_pages = max( 1, (int) ceil( $agentic_cv_total / $agentic_cv_per_page ) );

// Distinct agents for filter.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$agentic_cv_agents = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT agent_id FROM %i ORDER BY agent_id', $agentic_conv_table ) );

// Agent label helper.
$agentic_cv_registry  = \Agentic_Agent_Registry::get_instance();
$agentic_cv_instances = $agentic_cv_registry->get_all_instances();
?>
<p class="agentic-page-desc">
	<?php esc_html_e( 'Every message exchanged between users and your AI agents, grouped by session so you can read each conversation in full.', 'siteloom' ); ?>
	<?php esc_html_e( 'Use this tab to review what was said, spot common questions, and verify agents are responding appropriately.', 'siteloom' ); ?>
	<?php esc_html_e( 'Token usage and action history are in the', 'siteloom' ); ?>
	<a href="<?php echo esc_url( admin_url( 'admin.php?page=agentic-audit-log&tab=audit' ) ); ?>"><?php esc_html_e( 'Audit', 'siteloom' ); ?></a>
	<?php esc_html_e( 'tab.', 'siteloom' ); ?>
</p>

	<?php if ( $agentic_conv_notice ) : ?>
		<div class="notice notice-success"><p><?php echo esc_html( $agentic_conv_notice ); ?></p></div>
	<?php endif; ?>

	<!-- Period picker -->
	<div class="agentic-period-pills">
		<?php foreach ( $agentic_cv_period_labels as $agentic_cv_p_slug => $agentic_cv_p_label ) : ?>
			<a href="
			<?php
			echo esc_url(
				add_query_arg(
					array(
						'page'      => 'agentic-audit-log',
						'tab'       => 'conversations',
						'period'    => $agentic_cv_p_slug,
						'cv_agent'  => $agentic_cv_agent,
						'cv_user'   => $agentic_cv_user ? $agentic_cv_user : '',
						'cv_search' => $agentic_cv_search,
					),
					admin_url( 'admin.php' )
				)
			);
			?>
						"
				class="button<?php echo $agentic_cv_period === $agentic_cv_p_slug ? ' button-primary' : ''; ?>">
				<?php echo esc_html( $agentic_cv_p_label ); ?>
			</a>
		<?php endforeach; ?>
	</div>

	<!-- Filters -->
	<form method="get">
		<input type="hidden" name="page" value="agentic-audit-log">
		<input type="hidden" name="tab" value="conversations">
		<input type="hidden" name="period" value="<?php echo esc_attr( $agentic_cv_period ); ?>">
		<div class="tablenav top">
			<div class="alignleft actions">
				<label for="agentic-cv-agent" class="screen-reader-text"><?php esc_html_e( 'Filter by agent', 'siteloom' ); ?></label>
				<select name="cv_agent" id="agentic-cv-agent">
					<option value=""><?php esc_html_e( 'All Agents', 'siteloom' ); ?></option>
					<?php foreach ( $agentic_cv_agents as $agentic_cv_ag ) : ?>
						<?php
						$agentic_cv_ag_inst  = $agentic_cv_instances[ $agentic_cv_ag ] ?? null;
						$agentic_cv_ag_label = $agentic_cv_ag_inst ? $agentic_cv_ag_inst->get_name() : ucwords( str_replace( '-', ' ', $agentic_cv_ag ) );
						?>
						<option value="<?php echo esc_attr( $agentic_cv_ag ); ?>" <?php selected( $agentic_cv_agent, $agentic_cv_ag ); ?>><?php echo esc_html( $agentic_cv_ag_label ); ?></option>
					<?php endforeach; ?>
				</select>

				<label for="agentic-cv-user" class="screen-reader-text"><?php esc_html_e( 'Filter by user ID', 'siteloom' ); ?></label>
				<input type="number" id="agentic-cv-user" name="cv_user" value="<?php echo $agentic_cv_user ? esc_attr( $agentic_cv_user ) : ''; ?>" placeholder="<?php esc_attr_e( 'User ID', 'siteloom' ); ?>" min="0" class="agentic-input-100">

				<label for="agentic-cv-search" class="screen-reader-text"><?php esc_html_e( 'Search messages', 'siteloom' ); ?></label>
				<input type="text" id="agentic-cv-search" name="cv_search" value="<?php echo esc_attr( $agentic_cv_search ); ?>" placeholder="<?php esc_attr_e( 'Search messages…', 'siteloom' ); ?>" class="agentic-input-220">

				<input type="submit" class="button" value="<?php esc_attr_e( 'Filter', 'siteloom' ); ?>">
				<?php if ( $agentic_cv_agent || $agentic_cv_user || $agentic_cv_search ) : ?>
					<a href="
					<?php
					echo esc_url(
						add_query_arg(
							array(
								'page'   => 'agentic-audit-log',
								'tab'    => 'conversations',
								'period' => $agentic_cv_period,
							),
							admin_url( 'admin.php' )
						)
					);
					?>
								" class="button"><?php esc_html_e( 'Clear', 'siteloom' ); ?></a>
				<?php endif; ?>
			</div>
			<div class="tablenav-pages" style="line-height:30px;color:#646970;font-size:13px;">
				<?php
				printf(
					/* translators: %d: session count */
					esc_html__( '%d conversations', 'siteloom' ),
					absint( $agentic_cv_total )
				);
				?>
			</div>
			<br class="clear">
		</div>
	</form>

	<?php if ( empty( $agentic_cv_sessions ) ) : ?>
		<div class="agentic-empty-state">
			<p><?php esc_html_e( 'No conversations found.', 'siteloom' ); ?></p>
		</div>
	<?php else : ?>
	<table class="widefat striped" id="agentic-conv-table">
			<thead>
				<tr>
					<th class="agentic-col-180"><?php esc_html_e( 'Agent', 'siteloom' ); ?></th>
					<th class="agentic-col-120"><?php esc_html_e( 'User', 'siteloom' ); ?></th>
					<th><?php esc_html_e( 'First Message', 'siteloom' ); ?></th>
					<th class="agentic-col-60 agentic-td-center"><?php esc_html_e( 'Msgs', 'siteloom' ); ?></th>
					<th class="agentic-col-200"><?php esc_html_e( 'Tools', 'siteloom' ); ?></th>
					<th class="agentic-col-60 agentic-td-center"><?php esc_html_e( 'Rating', 'siteloom' ); ?></th>
					<th class="agentic-col-130"><?php esc_html_e( 'Started', 'siteloom' ); ?></th>
					<th class="agentic-col-130"><?php esc_html_e( 'Last Message', 'siteloom' ); ?></th>
					<th class="agentic-col-100"><?php esc_html_e( 'Details', 'siteloom' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $agentic_cv_sessions as $agentic_cv_s ) : ?>
					<?php
					$agentic_cv_s_inst    = $agentic_cv_instances[ $agentic_cv_s['agent_id'] ] ?? null;
					$agentic_cv_s_label   = $agentic_cv_s_inst ? $agentic_cv_s_inst->get_name() : ucwords( str_replace( '-', ' ', $agentic_cv_s['agent_id'] ) );
					$agentic_cv_s_user    = $agentic_cv_s['user_id'] ? get_user_by( 'id', $agentic_cv_s['user_id'] ) : null;
					$agentic_cv_s_name    = $agentic_cv_s_user ? $agentic_cv_s_user->display_name : __( 'Anonymous', 'siteloom' );
					$agentic_cv_s_preview = mb_strimwidth( $agentic_cv_s['preview'] ?? '', 0, 120, '...' );
					// Parse aggregated tools — GROUP_CONCAT produces comma-joined JSON arrays, e.g. '["a","b"],["c"]'.
					$agentic_cv_s_tools = array();
					if ( ! empty( $agentic_cv_s['all_tools'] ) ) {
						// Wrap in array brackets to make valid JSON, then flatten.
						$agentic_cv_s_parsed = json_decode( '[' . $agentic_cv_s['all_tools'] . ']', true );
						if ( is_array( $agentic_cv_s_parsed ) ) {
							foreach ( $agentic_cv_s_parsed as $agentic_cv_s_item ) {
								if ( is_array( $agentic_cv_s_item ) ) {
									$agentic_cv_s_tools = array_merge( $agentic_cv_s_tools, $agentic_cv_s_item );
								} elseif ( is_string( $agentic_cv_s_item ) ) {
									$agentic_cv_s_tools[] = $agentic_cv_s_item;
								}
							}
						}
						$agentic_cv_s_tools = array_unique( $agentic_cv_s_tools );
					}
					?>
				<tr class="agentic-conv-row" data-session="<?php echo esc_attr( $agentic_cv_s['session_id'] ); ?>">
					<td><strong><?php echo esc_html( $agentic_cv_s_label ); ?></strong></td>
					<td>
						<?php if ( $agentic_cv_s['user_id'] ) : ?>
							<a href="<?php echo esc_url( get_edit_user_link( $agentic_cv_s['user_id'] ) ); ?>" onclick="event.stopPropagation();"><?php echo esc_html( $agentic_cv_s_name ); ?></a>
						<?php else : ?>
							<?php echo esc_html( $agentic_cv_s_name ); ?>
						<?php endif; ?>
					</td>
					<td class="agentic-td-dark"><?php echo esc_html( $agentic_cv_s_preview ); ?></td>
					<td class="agentic-td-center"><?php echo esc_html( $agentic_cv_s['message_count'] ); ?></td>
					<td class="agentic-td-sm">
						<?php if ( ! empty( $agentic_cv_s_tools ) ) : ?>
							<?php foreach ( $agentic_cv_s_tools as $agentic_cv_s_tool ) : ?>
								<code class="agentic-tool-tag"><?php echo esc_html( $agentic_cv_s_tool ); ?></code>
							<?php endforeach; ?>
						<?php else : ?>
							<span class="agentic-text-subtle">&mdash;</span>
						<?php endif; ?>
					</td>
					<td class="agentic-td-center" style="font-size:18px;">
						<?php if ( '1' === (string) $agentic_cv_s['feedback'] ) : ?>
							<span title="<?php esc_attr_e( 'Thumbs up', 'siteloom' ); ?>" style="color:#22863a;">&#128077;</span>
						<?php elseif ( '-1' === (string) $agentic_cv_s['feedback'] ) : ?>
							<span title="<?php esc_attr_e( 'Thumbs down', 'siteloom' ); ?>" style="color:#cb2431;">&#128078;</span>
						<?php else : ?>
							<span class="agentic-text-subtle">&mdash;</span>
						<?php endif; ?>
					</td>
					<td class="agentic-td-sm"><?php echo esc_html( wp_date( 'M j, H:i', strtotime( $agentic_cv_s['started'] ) ) ); ?></td>
					<td class="agentic-td-sm"><?php echo esc_html( wp_date( 'M j, H:i', strtotime( $agentic_cv_s['last_message'] ) ) ); ?></td>
					<td onclick="event.stopPropagation();">
						<details class="agentic-conv-details" data-session="<?php echo esc_attr( $agentic_cv_s['session_id'] ); ?>">
							<summary>View</summary>
						</details>
						<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=agentic-audit-log&tab=conversations&delete_session=' . $agentic_cv_s['session_id'] ), 'agentic_conv_delete_' . $agentic_cv_s['session_id'] ) ); ?>" class="agentic-link-danger" style="font-size:12px;display:block;margin-top:4px;" data-agentic-confirm="<?php echo esc_attr( __( 'Delete this conversation?', 'siteloom' ) ); ?>" data-agentic-confirm-danger data-agentic-confirm-ok="<?php echo esc_attr( __( 'Delete', 'siteloom' ) ); ?>"><?php esc_html_e( 'Delete', 'siteloom' ); ?></a>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( $agentic_cv_total_pages > 1 ) : ?>
		<div class="agentic-pagination">
			<?php for ( $agentic_cv_p = 1; $agentic_cv_p <= min( $agentic_cv_total_pages, 20 ); $agentic_cv_p++ ) : ?>
				<?php
				$agentic_cv_pg_url = add_query_arg(
					array(
						'page'      => 'agentic-audit-log',
						'tab'       => 'conversations',
						'cv_agent'  => $agentic_cv_agent,
						'cv_user'   => $agentic_cv_user ? $agentic_cv_user : '',
						'cv_search' => $agentic_cv_search,
						'cv_paged'  => $agentic_cv_p,
					),
					admin_url( 'admin.php' )
				);
				?>
				<a href="<?php echo esc_url( $agentic_cv_pg_url ); ?>" class="button <?php echo $agentic_cv_p === $agentic_cv_page ? 'button-primary' : ''; ?>">
					<?php echo esc_html( $agentic_cv_p ); ?>
				</a>
			<?php endfor; ?>
		</div>
		<?php endif; ?>

	<?php endif; ?>

	<?php
	$agentic_cv_retention = (int) get_option( 'agent_builder_retention_conversations', 30 );
	?>
	<p class="agentic-text-muted agentic-text-sm" style="margin-top:12px;">
		<?php if ( $agentic_cv_retention > 0 ) : ?>
			<?php
			printf(
				/* translators: %d: number of days */
				esc_html__( 'Conversations are automatically deleted after %d days.', 'siteloom' ),
				(int) $agentic_cv_retention
			);
			?>
		<?php else : ?>
			<?php esc_html_e( 'Conversations are kept indefinitely (no retention limit set).', 'siteloom' ); ?>
		<?php endif; ?>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=agentic-settings&tab=security' ) ); ?>"><?php esc_html_e( 'Change retention settings', 'siteloom' ); ?></a>
	</p>

<?php
\Agentic\Inline_Assets::script(
	'conversations',
	array(
		'restUrl' => rest_url( 'agentic/v1/history/' ),
		'nonce'   => wp_create_nonce( 'wp_rest' ),
	),
	'agenticConversations'
);
?>
