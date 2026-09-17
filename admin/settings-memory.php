<?php
/**
 * Settings — Memory Tab
 *
 * View, filter, and manage persistent agent memories stored in wp_agent_builder_memory.
 *
 * @package    Agent_Builder
 * @subpackage Admin
 * @since      2.9.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'You do not have permission to access this page.', 'agent-builder' ) );
}

global $wpdb;
$agentic_mem_table = $wpdb->prefix . 'agent_builder_memory';

// ── Handle actions ───────────────────────────────────────────────────────────

$agentic_mem_notice = '';

// Delete single memory.
if ( isset( $_GET['delete_memory'], $_GET['_wpnonce'] )
	&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'agentic_mem_delete_' . absint( $_GET['delete_memory'] ) )
) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->delete( $agentic_mem_table, array( 'id' => absint( $_GET['delete_memory'] ) ), array( '%d' ) );
	$agentic_mem_notice = __( 'Memory entry deleted.', 'agent-builder' );
}

// Bulk clear.
if ( isset( $_POST['agentic_mem_clear_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['agentic_mem_clear_nonce'] ) ), 'agentic_mem_clear' ) ) {
	$agentic_clear_type   = sanitize_key( wp_unslash( $_POST['agentic_mem_clear_type'] ?? '' ) );
	$agentic_clear_entity = sanitize_text_field( wp_unslash( $_POST['agentic_mem_clear_entity'] ?? '' ) );

	$agentic_where = array();
	$agentic_fmt   = array();
	if ( $agentic_clear_type ) {
		$agentic_where['memory_type'] = $agentic_clear_type;
		$agentic_fmt[]                = '%s';
	}
	if ( $agentic_clear_entity ) {
		$agentic_where['entity_id'] = $agentic_clear_entity;
		$agentic_fmt[]              = '%s';
	}

	if ( ! empty( $agentic_where ) ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$agentic_deleted    = $wpdb->delete( $agentic_mem_table, $agentic_where, $agentic_fmt );
		$agentic_mem_notice = sprintf(
			/* translators: %d: number of deleted rows */
			__( '%d memory entries cleared.', 'agent-builder' ),
			$agentic_deleted
		);
	}
}

// Save TTL setting.
if ( isset( $_POST['agentic_mem_ttl_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['agentic_mem_ttl_nonce'] ) ), 'agentic_mem_ttl_save' ) ) {
	$agentic_ttl = absint( $_POST['agent_builder_memory_ttl_days'] ?? 0 );
	update_option( 'agent_builder_memory_ttl_days', $agentic_ttl );
	$agentic_mem_notice = __( 'Memory settings saved.', 'agent-builder' );
}

// ── Filters ──────────────────────────────────────────────────────────────────
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filter params.
$agentic_mem_filter_type   = isset( $_GET['mem_type'] ) ? sanitize_key( $_GET['mem_type'] ) : '';
$agentic_mem_filter_entity = isset( $_GET['mem_entity'] ) ? sanitize_text_field( wp_unslash( $_GET['mem_entity'] ) ) : '';
$agentic_mem_page_num      = max( 1, absint( $_GET['mem_paged'] ?? 1 ) );
// phpcs:enable

$agentic_per_page = 50;
$agentic_offset   = ( $agentic_mem_page_num - 1 ) * $agentic_per_page;

// Build query.
$agentic_where_sql = '1=1';
$agentic_params    = array();

if ( $agentic_mem_filter_type ) {
	$agentic_where_sql .= ' AND memory_type = %s';
	$agentic_params[]   = $agentic_mem_filter_type;
}
if ( $agentic_mem_filter_entity ) {
	$agentic_where_sql .= ' AND entity_id = %s';
	$agentic_params[]   = $agentic_mem_filter_entity;
}

// Count.
if ( ! empty( $agentic_params ) ) {
	$agentic_total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE ' . $agentic_where_sql, $agentic_mem_table, ...$agentic_params ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
} else {
	$agentic_total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $agentic_mem_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
}

// Fetch rows.
$agentic_query_params = array_merge( array( $agentic_mem_table ), $agentic_params, array( $agentic_per_page, $agentic_offset ) );
$agentic_rows         = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE ' . $agentic_where_sql . ' ORDER BY updated_at DESC LIMIT %d OFFSET %d', ...$agentic_query_params ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

// Distinct types and entities for filter dropdowns.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$agentic_types = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT memory_type FROM %i ORDER BY memory_type', $agentic_mem_table ) );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$agentic_entities = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT entity_id FROM %i ORDER BY entity_id', $agentic_mem_table ) );

$agentic_total_pages = max( 1, (int) ceil( $agentic_total / $agentic_per_page ) );
$agentic_ttl_days    = (int) get_option( 'agent_builder_memory_ttl_days', 0 );
?>

<?php if ( $agentic_mem_notice ) : ?>
<div class="notice notice-success"><p><?php echo esc_html( $agentic_mem_notice ); ?></p></div>
<?php endif; ?>

<p class="agentic-settings-lead"><?php esc_html_e( 'Persistent key-value memory stored by agents across conversations — user preferences, prior decisions, and accumulated context.', 'agent-builder' ); ?></p>

<!-- TTL Setting -->
<div class="agentic-memory-box">
	<form method="post">
		<?php wp_nonce_field( 'agentic_mem_ttl_save', 'agentic_mem_ttl_nonce' ); ?>
		<label for="agent_builder_memory_ttl_days"><strong>Default Memory TTL</strong></label>
		<div class="agentic-flex-gap-8 agentic-mt-6">
			<input type="number" name="agent_builder_memory_ttl_days" id="agent_builder_memory_ttl_days" value="<?php echo esc_attr( $agentic_ttl_days ); ?>" min="0" style="width:80px;" />
			<span>days (0 = never expires)</span>
			<input type="submit" class="button" value="<?php esc_attr_e( 'Save', 'agent-builder' ); ?>" />
		</div>
		<p class="description agentic-mt-6">Memories without an explicit TTL will be auto-cleaned after this many days. Set to 0 to keep memories indefinitely.</p>
	</form>
</div>

<!-- Filters -->
<div class="agentic-memory-filter">
	<form method="get" class="agentic-memory-filter-form">
		<input type="hidden" name="page" value="agentic-settings" />
		<input type="hidden" name="tab" value="memory" />

		<select name="mem_type">
			<option value="">All Types</option>
			<?php foreach ( $agentic_types as $agentic_t ) : ?>
				<option value="<?php echo esc_attr( $agentic_t ); ?>" <?php selected( $agentic_mem_filter_type, $agentic_t ); ?>><?php echo esc_html( $agentic_t ); ?></option>
			<?php endforeach; ?>
		</select>

		<select name="mem_entity">
			<option value="">All Entities</option>
			<?php foreach ( $agentic_entities as $agentic_e ) : ?>
				<option value="<?php echo esc_attr( $agentic_e ); ?>" <?php selected( $agentic_mem_filter_entity, $agentic_e ); ?>><?php echo esc_html( $agentic_e ); ?></option>
			<?php endforeach; ?>
		</select>

		<input type="submit" class="button" value="<?php esc_attr_e( 'Filter', 'agent-builder' ); ?>" />
	</form>

	<!-- Bulk clear -->
	<?php if ( $agentic_total > 0 ) : ?>
	<form method="post" class="agentic-ml-auto" data-agentic-confirm="<?php echo esc_attr( __( 'Clear matching memories? This cannot be undone.', 'agent-builder' ) ); ?>" data-agentic-confirm-danger>
		<?php wp_nonce_field( 'agentic_mem_clear', 'agentic_mem_clear_nonce' ); ?>
		<input type="hidden" name="agentic_mem_clear_type" value="<?php echo esc_attr( $agentic_mem_filter_type ); ?>" />
		<input type="hidden" name="agentic_mem_clear_entity" value="<?php echo esc_attr( $agentic_mem_filter_entity ); ?>" />
		<button type="submit" class="button agentic-text-danger">
			<?php
			if ( $agentic_mem_filter_type || $agentic_mem_filter_entity ) {
				/* translators: %d: number of memory entries */
				printf( esc_html__( 'Clear Filtered (%d)', 'agent-builder' ), absint( $agentic_total ) );
			} else {
				esc_html_e( 'Clear All Memories', 'agent-builder' );
			}
			?>
		</button>
	</form>
	<?php endif; ?>
</div>

<!-- Memory count -->
<p class="agentic-text-muted agentic-text-sm agentic-mb-8">
	<?php
	printf(
		/* translators: %d: memory count */
		esc_html__( '%d memory entries', 'agent-builder' ),
		absint( $agentic_total )
	);
	?>
</p>

<?php if ( empty( $agentic_rows ) ) : ?>
	<div class="agentic-memory-empty">
		<p class="agentic-text-sm agentic-mt-0"><?php esc_html_e( 'No memory entries found.', 'agent-builder' ); ?></p>
		<p class="agentic-mt-8"><?php esc_html_e( 'Agent memories will appear here as your agents accumulate context across conversations.', 'agent-builder' ); ?></p>
	</div>
<?php else : ?>
	<table class="widefat striped agentic-table-1200">
		<thead>
			<tr>
				<th class="agentic-col-110"><?php esc_html_e( 'Type', 'agent-builder' ); ?></th>
				<th class="agentic-col-120"><?php esc_html_e( 'Entity', 'agent-builder' ); ?></th>
				<th class="agentic-col-180"><?php esc_html_e( 'Key', 'agent-builder' ); ?></th>
				<th><?php esc_html_e( 'Value', 'agent-builder' ); ?></th>
				<th class="agentic-col-100"><?php esc_html_e( 'Expires', 'agent-builder' ); ?></th>
				<th class="agentic-col-130"><?php esc_html_e( 'Updated', 'agent-builder' ); ?></th>
				<th class="agentic-col-60"></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $agentic_rows as $agentic_row ) : ?>
			<tr>
				<td><code><?php echo esc_html( $agentic_row['memory_type'] ); ?></code></td>
				<td><?php echo esc_html( $agentic_row['entity_id'] ); ?></td>
				<td><code><?php echo esc_html( $agentic_row['memory_key'] ); ?></code></td>
				<td class="agentic-td-overflow">
					<span title="<?php echo esc_attr( $agentic_row['memory_value'] ); ?>"><?php echo esc_html( mb_strimwidth( $agentic_row['memory_value'], 0, 120, '...' ) ); ?></span>
				</td>
				<td class="agentic-td-meta">
					<?php
					if ( $agentic_row['expires_at'] ) {
						echo esc_html( wp_date( 'M j, Y', strtotime( $agentic_row['expires_at'] ) ) );
					} else {
						echo '&mdash;';
					}
					?>
				</td>
				<td class="agentic-td-meta">
					<?php echo esc_html( wp_date( 'M j, Y H:i', strtotime( $agentic_row['updated_at'] ) ) ); ?>
				</td>
				<td>
					<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=agentic-settings&tab=memory&delete_memory=' . $agentic_row['id'] ), 'agentic_mem_delete_' . $agentic_row['id'] ) ); ?>" class="button button-small agentic-link-danger" data-agentic-confirm="<?php echo esc_attr( __( 'Delete this memory?', 'agent-builder' ) ); ?>" data-agentic-confirm-danger>&times;</a>
				</td>
			</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<?php if ( $agentic_total_pages > 1 ) : ?>
	<div class="agentic-mt-12 agentic-flex-gap-6">
		<?php for ( $agentic_p = 1; $agentic_p <= $agentic_total_pages; $agentic_p++ ) : ?>
			<?php
			$agentic_pg_url = add_query_arg(
				array(
					'page'       => 'agentic-settings',
					'tab'        => 'memory',
					'mem_type'   => $agentic_mem_filter_type,
					'mem_entity' => $agentic_mem_filter_entity,
					'mem_paged'  => $agentic_p,
				),
				admin_url( 'admin.php' )
			);
			?>
			<a href="<?php echo esc_url( $agentic_pg_url ); ?>" class="button <?php echo $agentic_p === $agentic_mem_page_num ? 'button-primary' : ''; ?>">
				<?php echo esc_html( $agentic_p ); ?>
			</a>
		<?php endfor; ?>
	</div>
	<?php endif; ?>
<?php endif; ?>
