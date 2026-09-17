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
	&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'agentic_conv_delete_' . sanitize_key( $_GET['delete_session'] ) )
) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->delete( $agentic_conv_table, array( 'session_id' => sanitize_key( $_GET['delete_session'] ) ), array( '%s' ) );
	$agentic_conv_notice = __( 'Conversation deleted.', 'agent-builder' );
}

// ── Filters ──────────────────────────────────────────────────────────────────
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only.
$agentic_cv_agent  = isset( $_GET['cv_agent'] ) ? sanitize_key( $_GET['cv_agent'] ) : '';
$agentic_cv_user   = isset( $_GET['cv_user'] ) ? absint( $_GET['cv_user'] ) : 0;
$agentic_cv_search = isset( $_GET['cv_search'] ) ? sanitize_text_field( wp_unslash( $_GET['cv_search'] ) ) : '';
$agentic_cv_page   = max( 1, absint( $_GET['cv_paged'] ?? 1 ) );
$agentic_cv_period = sanitize_key( $_GET['period'] ?? 'week' );
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
	<?php esc_html_e( 'Every message exchanged between users and your AI agents, grouped by session so you can read each conversation in full.', 'agent-builder' ); ?>
	<?php esc_html_e( 'Use this tab to review what was said, spot common questions, and verify agents are responding appropriately.', 'agent-builder' ); ?>
	<?php esc_html_e( 'Token usage and action history are in the', 'agent-builder' ); ?>
	<a href="<?php echo esc_url( admin_url( 'admin.php?page=agentic-audit-log&tab=audit' ) ); ?>"><?php esc_html_e( 'Audit', 'agent-builder' ); ?></a>
	<?php esc_html_e( 'tab.', 'agent-builder' ); ?>
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
				<label for="agentic-cv-agent" class="screen-reader-text"><?php esc_html_e( 'Filter by agent', 'agent-builder' ); ?></label>
				<select name="cv_agent" id="agentic-cv-agent">
					<option value=""><?php esc_html_e( 'All Agents', 'agent-builder' ); ?></option>
					<?php foreach ( $agentic_cv_agents as $agentic_cv_ag ) : ?>
						<?php
						$agentic_cv_ag_inst  = $agentic_cv_instances[ $agentic_cv_ag ] ?? null;
						$agentic_cv_ag_label = $agentic_cv_ag_inst ? $agentic_cv_ag_inst->get_name() : ucwords( str_replace( '-', ' ', $agentic_cv_ag ) );
						?>
						<option value="<?php echo esc_attr( $agentic_cv_ag ); ?>" <?php selected( $agentic_cv_agent, $agentic_cv_ag ); ?>><?php echo esc_html( $agentic_cv_ag_label ); ?></option>
					<?php endforeach; ?>
				</select>

				<label for="agentic-cv-user" class="screen-reader-text"><?php esc_html_e( 'Filter by user ID', 'agent-builder' ); ?></label>
				<input type="number" id="agentic-cv-user" name="cv_user" value="<?php echo $agentic_cv_user ? esc_attr( $agentic_cv_user ) : ''; ?>" placeholder="<?php esc_attr_e( 'User ID', 'agent-builder' ); ?>" min="0" class="agentic-input-100">

				<label for="agentic-cv-search" class="screen-reader-text"><?php esc_html_e( 'Search messages', 'agent-builder' ); ?></label>
				<input type="text" id="agentic-cv-search" name="cv_search" value="<?php echo esc_attr( $agentic_cv_search ); ?>" placeholder="<?php esc_attr_e( 'Search messages…', 'agent-builder' ); ?>" class="agentic-input-220">

				<input type="submit" class="button" value="<?php esc_attr_e( 'Filter', 'agent-builder' ); ?>">
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
								" class="button"><?php esc_html_e( 'Clear', 'agent-builder' ); ?></a>
				<?php endif; ?>
			</div>
			<div class="tablenav-pages" style="line-height:30px;color:#646970;font-size:13px;">
				<?php
				printf(
					/* translators: %d: session count */
					esc_html__( '%d conversations', 'agent-builder' ),
					absint( $agentic_cv_total )
				);
				?>
			</div>
			<br class="clear">
		</div>
	</form>

	<?php if ( empty( $agentic_cv_sessions ) ) : ?>
		<div class="agentic-empty-state">
			<p><?php esc_html_e( 'No conversations found.', 'agent-builder' ); ?></p>
		</div>
	<?php else : ?>
	<table class="widefat striped" id="agentic-conv-table">
			<thead>
				<tr>
					<th class="agentic-col-180"><?php esc_html_e( 'Agent', 'agent-builder' ); ?></th>
					<th class="agentic-col-120"><?php esc_html_e( 'User', 'agent-builder' ); ?></th>
					<th><?php esc_html_e( 'First Message', 'agent-builder' ); ?></th>
					<th class="agentic-col-60 agentic-td-center"><?php esc_html_e( 'Msgs', 'agent-builder' ); ?></th>
					<th class="agentic-col-200"><?php esc_html_e( 'Tools', 'agent-builder' ); ?></th>
					<th class="agentic-col-60 agentic-td-center"><?php esc_html_e( 'Rating', 'agent-builder' ); ?></th>
					<th class="agentic-col-130"><?php esc_html_e( 'Started', 'agent-builder' ); ?></th>
					<th class="agentic-col-130"><?php esc_html_e( 'Last Message', 'agent-builder' ); ?></th>
					<th class="agentic-col-100"><?php esc_html_e( 'Details', 'agent-builder' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $agentic_cv_sessions as $agentic_cv_s ) : ?>
					<?php
					$agentic_cv_s_inst    = $agentic_cv_instances[ $agentic_cv_s['agent_id'] ] ?? null;
					$agentic_cv_s_label   = $agentic_cv_s_inst ? $agentic_cv_s_inst->get_name() : ucwords( str_replace( '-', ' ', $agentic_cv_s['agent_id'] ) );
					$agentic_cv_s_user    = $agentic_cv_s['user_id'] ? get_user_by( 'id', $agentic_cv_s['user_id'] ) : null;
					$agentic_cv_s_name    = $agentic_cv_s_user ? $agentic_cv_s_user->display_name : __( 'Anonymous', 'agent-builder' );
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
							<span title="<?php esc_attr_e( 'Thumbs up', 'agent-builder' ); ?>" style="color:#22863a;">&#128077;</span>
						<?php elseif ( '-1' === (string) $agentic_cv_s['feedback'] ) : ?>
							<span title="<?php esc_attr_e( 'Thumbs down', 'agent-builder' ); ?>" style="color:#cb2431;">&#128078;</span>
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
						<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=agentic-audit-log&tab=conversations&delete_session=' . $agentic_cv_s['session_id'] ), 'agentic_conv_delete_' . $agentic_cv_s['session_id'] ) ); ?>" class="agentic-link-danger" style="font-size:12px;display:block;margin-top:4px;" data-agentic-confirm="<?php echo esc_attr( __( 'Delete this conversation?', 'agent-builder' ) ); ?>" data-agentic-confirm-danger data-agentic-confirm-ok="<?php echo esc_attr( __( 'Delete', 'agent-builder' ) ); ?>"><?php esc_html_e( 'Delete', 'agent-builder' ); ?></a>
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
				esc_html__( 'Conversations are automatically deleted after %d days.', 'agent-builder' ),
				(int) $agentic_cv_retention
			);
			?>
		<?php else : ?>
			<?php esc_html_e( 'Conversations are kept indefinitely (no retention limit set).', 'agent-builder' ); ?>
		<?php endif; ?>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=agentic-settings&tab=security' ) ); ?>"><?php esc_html_e( 'Change retention settings', 'agent-builder' ); ?></a>
	</p>

<script>
(function() {
	var restUrl = <?php echo wp_json_encode( rest_url( 'agentic/v1/history/' ) ); ?>;
	var nonce   = <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;
	var loaded  = {};

	function escHtml(str) {
		var d = document.createElement('div');
		d.textContent = str || '';
		return d.innerHTML;
	}

	function formatTime(ts) {
		if (!ts) return '';
		try { return new Date(ts).toLocaleString([], { month:'short', day:'numeric', hour:'2-digit', minute:'2-digit' }); }
		catch (e) { return ts; }
	}

	/**
	 * Impressive Conversations UI renderer.
	 * Shows clean transcript + a beautiful reasoning trace timeline powered by
	 * the new observability data (tool_choice + chat_complete with real reasoning).
	 */
	function renderMessages(container, sid) {
		if (loaded[sid]) return;
		loaded[sid] = true;

		container.innerHTML = '<div class="agentic-trace-empty">Loading full trace…</div>';

		fetch(restUrl + encodeURIComponent(sid), { headers: { 'X-WP-Nonce': nonce } })
		.then(function(r) { return r.json(); })
		.then(function(data) {
			var history = data.history || [];
			var trace   = data.trace || [];

			var html = '';

			// ── Premium header ─────────────────────────────────────────────
			html += '<div class="agentic-trace-header">';
			html += '<h4>Conversation Trace</h4>';
			html += '<div class="agentic-trace-meta">';
			html += '<span>' + history.length + ' turns</span>';
			if (trace.length) html += '<span>' + trace.length + ' decisions logged</span>';
			html += '</div>';
			html += '</div>';

			// ── Clean transcript (improved bubbles) ────────────────────────
			if (history.length) {
				html += '<div style="margin-bottom:18px;border-bottom:1px solid #f0f0f1;padding-bottom:12px;">';
				history.forEach(function(msg) {
					var isUser = msg.role === 'user';
					var time   = formatTime(msg.time);
					html += '<div class="agentic-msg-bubble ' + (isUser ? 'agentic-msg-bubble--user' : 'agentic-msg-bubble--agent') + '">';
					html += '<div class="agentic-msg-header"><strong class="agentic-msg-sender">' + (isUser ? 'User' : 'Agent') + '</strong><span class="agentic-msg-time">' + escHtml(time) + '</span></div>';

					if (!isUser && msg.tools_used && msg.tools_used.length) {
						html += '<div class="agentic-msg-tools">';
						msg.tools_used.forEach(function(t){ html += '<code class="agentic-tool-tag">' + escHtml(t) + '</code>'; });
						html += '</div>';
					}
					html += '<div class="agentic-msg-body">' + escHtml(msg.content || '') + '</div>';
					html += '</div>';
				});
				html += '</div>';
			}

			// ── IMPRESSIVE REASONING TRACE TIMELINE (the star) ─────────────
			if (trace.length) {
				html += '<div style="margin-bottom:6px;"><strong style="font-size:13px;color:#1d2327;">Agent Reasoning &amp; Decisions</strong></div>';
				html += '<div class="agentic-trace-timeline">';

				trace.forEach(function(step) {
					var type = 'thinking';
					if (step.action === 'tool_choice') type = 'tool';
					if (step.action && step.action.indexOf('complete') !== -1) type = 'final';

					var icon = type === 'thinking' ? 'dashicons-lightbulb' : (type === 'tool' ? 'dashicons-admin-tools' : 'dashicons-yes-alt');

					html += '<div class="agentic-trace-step agentic-trace-step--' + type + '">';

					// Header
					html += '<div class="agentic-trace-step-header">';
					html += '<span class="dashicons ' + icon + '"></span> ';
					html += '<span>' + (type === 'thinking' ? 'Thinking' : (type === 'tool' ? 'Tool Decision' : 'Final Response')) + '</span>';
					html += '<span class="time">' + escHtml(formatTime(step.time)) + '</span>';
					html += '</div>';

					// Reasoning — prominent and beautiful
					if (step.reasoning) {
						html += '<div class="agentic-trace-reasoning">' + escHtml(step.reasoning) + '</div>';
					}

					// Tools
					if (step.tools && step.tools.length) {
						html += '<div class="agentic-trace-tools">';
						step.tools.forEach(function(t) {
							html += '<code>' + escHtml(t) + '</code>';
						});
						html += '</div>';
					}

					// Meta row
					var metaParts = [];
					if (step.tokens) metaParts.push(step.tokens + ' tokens');
					if (step.cost) metaParts.push('$' + step.cost.toFixed(4));
					if (step.provider) metaParts.push(step.provider);
					if (step.iterations) metaParts.push(step.iterations + ' iterations');

					if (metaParts.length) {
						html += '<div class="agentic-trace-meta-row">' + metaParts.join(' · ') + '</div>';
					}

					html += '</div>';
				});

				html += '</div>';
			} else if (history.length) {
				html += '<div class="agentic-trace-empty">No detailed reasoning trace captured for this older session.</div>';
			}

			if (!history.length && !trace.length) {
				html = '<p class="agentic-text-muted">No messages or trace found for this session.</p>';
			}

			container.innerHTML = html;
		})
		.catch(function() {
			container.innerHTML = '<p class="agentic-status-error" style="padding:12px 0;">Failed to load the full conversation trace.</p>';
		});
	}

	// Wire every <details> — now renders the impressive trace experience.
	document.querySelectorAll('.agentic-conv-details').forEach(function(det) {
		var sid       = det.dataset.session;
		var container = document.createElement('div');
		container.className = 'agentic-conv-messages';
		det.appendChild(container);

		det.addEventListener('toggle', function() {
			if (det.open) renderMessages(container, sid);
		});
	});
})();
</script>
