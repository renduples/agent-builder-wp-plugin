<?php
/**
 * Agentic Approval Queue & Backups
 *
 * @package    Agent_Builder
 * @subpackage Admin
 * @author     AgentStructor Team <support@agentic-plugin.com>
 * @license    GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 * @link       https://agentstructor.com
 * @since      0.1.0
 *
 * php version 8.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Agentic\Approval_Queue;

if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'You do not have permission to access this page.', 'agentstructor' ) );
}

\Agentic\Plugin::get_instance()->load_chat_components();

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab navigation.
$agentic_active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'approvals';
if ( ! in_array( $agentic_active_tab, array( 'approvals', 'backups' ), true ) ) {
	$agentic_active_tab = 'approvals';
}

$agentic_queue      = new Approval_Queue();
$agent_builder_agent_mode = get_option( 'agent_builder_agent_mode', 'supervised' );
?>
<div class="wrap agentic-admin">
	<h1>
		<span class="dashicons dashicons-yes-alt agentic-di-xl agentic-di-mr10"></span>
		<?php esc_html_e( 'Approvals', 'agentstructor' ); ?>
	</h1>

	<?php
	$agentic_pending_count   = $agentic_queue->get_pending_count();
	$agentic_approvals_items = array(
		array(
			'slug'  => 'approvals',
			'label' => __( 'Approvals', 'agentstructor' ),
			'url'   => admin_url( 'admin.php?page=agentic-approvals&tab=approvals' ),
			'badge' => $agentic_pending_count > 0 ? '<span class="agentic-tab-badge">' . esc_html( $agentic_pending_count ) . '</span>' : '',
		),
		array(
			'slug'  => 'backups',
			'label' => __( 'Backups', 'agentstructor' ),
			'url'   => admin_url( 'admin.php?page=agentic-approvals&tab=backups' ),
		),
	);
	\Agentic\Admin_Vnav::open(
		array(
			'active'     => $agentic_active_tab,
			'items'      => $agentic_approvals_items,
			'aria_label' => __( 'Approval sections', 'agentstructor' ),
			'id'         => 'agentic-approvals-nav',
		)
	);
	?>

	<div id="agentic-approvals-notices"></div>

	<?php if ( 'approvals' === $agentic_active_tab ) : ?>
		<?php
		$agentic_pending         = $agentic_queue->get_pending();
		$agentic_show_empty_note = empty( $agentic_pending );

		// Group pending items by agent + time batch (items within 120s of each other).
		$agentic_groups = array();
		foreach ( $agentic_pending as $agentic_item ) {
			$agentic_agent = $agentic_item['agent_id'];
			$agentic_ts    = strtotime( $agentic_item['created_at'] . ' UTC' );
			$agentic_key   = null;

			// Find an existing group for this agent within 120s.
			foreach ( $agentic_groups as $agentic_k => $agentic_g ) {
				if ( $agentic_g['agent_id'] === $agentic_agent && abs( $agentic_g['last_ts'] - $agentic_ts ) < 120 ) {
					$agentic_key = $agentic_k;
					break;
				}
			}

			if ( null === $agentic_key ) {
				$agentic_key                    = count( $agentic_groups );
				$agentic_groups[ $agentic_key ] = array(
					'agent_id' => $agentic_agent,
					'first_ts' => $agentic_ts,
					'last_ts'  => $agentic_ts,
					'items'    => array(),
				);
			}

			$agentic_groups[ $agentic_key ]['items'][] = $agentic_item;
			$agentic_groups[ $agentic_key ]['last_ts'] = $agentic_ts;
		}

		// Within each group, sort so scaffolds/creates come first (dependencies), then by ID asc.
		$agentic_scaffold_actions = array( 'create_plugin_scaffold', 'create_agent_files' );
		foreach ( $agentic_groups as &$agentic_group ) {
			usort(
				$agentic_group['items'],
				function ( $a, $b ) use ( $agentic_scaffold_actions ) {
					$a_scaffold = in_array( $a['action'], $agentic_scaffold_actions, true ) ? 0 : 1;
					$b_scaffold = in_array( $b['action'], $agentic_scaffold_actions, true ) ? 0 : 1;
					if ( $a_scaffold !== $b_scaffold ) {
						return $a_scaffold - $b_scaffold;
					}
					return (int) $a['id'] - (int) $b['id'];
				}
			);
		}
		unset( $agentic_group );
		?>

		<p class="description agentic-mt-16">
			<?php esc_html_e( 'Review and approve actions requested by AI agents before they are executed.', 'agentstructor' ); ?>
			<?php if ( 'supervised' === $agent_builder_agent_mode ) : ?>
				<strong><?php esc_html_e( 'Mode:', 'agentstructor' ); ?></strong> <?php esc_html_e( 'Supervised — high-risk actions require approval.', 'agentstructor' ); ?>
			<?php elseif ( 'autonomous' === $agent_builder_agent_mode ) : ?>
				<strong><?php esc_html_e( 'Mode:', 'agentstructor' ); ?></strong> <?php esc_html_e( 'Autonomous — all actions execute automatically.', 'agentstructor' ); ?>
			<?php else : ?>
				<strong><?php esc_html_e( 'Mode:', 'agentstructor' ); ?></strong> <?php esc_html_e( 'Disabled — agents cannot make file changes.', 'agentstructor' ); ?>
			<?php endif; ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=agentic-settings' ) ); ?>"><?php esc_html_e( 'Change mode', 'agentstructor' ); ?></a>
		</p>

		<?php if ( 'autonomous' === $agent_builder_agent_mode ) : ?>
			<div class="notice notice-warning">
				<p>
					<strong><?php esc_html_e( 'Warning:', 'agentstructor' ); ?></strong>
					<?php esc_html_e( 'Autonomous mode is enabled. Actions are executed immediately without requiring approval. The queue below will remain empty.', 'agentstructor' ); ?>
				</p>
			</div>
		<?php endif; ?>

		<?php if ( $agentic_show_empty_note ) : ?>
			<div class="agentic-card agentic-approval-empty-card">
				<span class="dashicons dashicons-thumbs-up agentic-di-xxl agentic-di-green agentic-di-mb10"></span>
				<h2 class="agentic-approval-h2"><?php esc_html_e( 'All caught up!', 'agentstructor' ); ?></h2>
				<p class="agentic-text-dim"><?php esc_html_e( 'No pending approvals at this time.', 'agentstructor' ); ?></p>
			</div>
		<?php else : ?>
			<div class="agentic-mt-20">
				<?php foreach ( $agentic_groups as $agentic_group_idx => $agentic_group ) : ?>
					<?php
					$agentic_group_items = $agentic_group['items'];
					$agentic_group_count = count( $agentic_group_items );
					$agentic_group_agent = $agentic_group['agent_id'];
					$agentic_group_label = str_replace( '-', ' ', ucwords( $agentic_group_agent, '-' ) );
					$agentic_group_time  = human_time_diff( $agentic_group['first_ts'] );
					$agentic_group_ids   = wp_json_encode( array_column( $agentic_group_items, 'id' ) );
					?>
					<div class="agentic-approval-group">
						<div class="agentic-approval-header"">
							<div>
								<h3 class="agentic-approval-h3">
									<?php echo esc_html( $agentic_group_label ); ?>
									<span class="agentic-approval-count-note">
										&mdash; <?php echo esc_html( $agentic_group_count ); ?> <?php echo esc_html( _n( 'action', 'actions', $agentic_group_count, 'agentstructor' ) ); ?> &bull; <?php echo esc_html( $agentic_group_time ); ?> ago
									</span>
								</h3>
							</div>
							<div class="agentic-flex-gap-8">
								<button class="button button-primary agentic-approve-all-btn" data-ids="<?php echo esc_attr( $agentic_group_ids ); ?>">
									<span class="dashicons dashicons-yes-alt agentic-di-va-m2"></span> <?php esc_html_e( 'Approve All', 'agentstructor' ); ?>
								</button>
								<button class="button agentic-reject-all-btn" data-ids="<?php echo esc_attr( $agentic_group_ids ); ?>">
									<span class="dashicons dashicons-dismiss agentic-di-va-m2"></span> <?php esc_html_e( 'Reject All', 'agentstructor' ); ?>
								</button>
							</div>
						</div>

						<div>
							<?php foreach ( $agentic_group_items as $agentic_step_idx => $agentic_item ) : ?>
								<?php
								$agentic_action_type  = esc_html( $agentic_item['action'] );
								$agentic_action_label = str_replace( '_', ' ', ucwords( $agentic_action_type, '_' ) );
								$agentic_params       = $agentic_item['params'];
								$agentic_file_path    = $agentic_params['file_path'] ?? '';
								$agentic_plugin_slug  = $agentic_params['plugin_slug'] ?? $agentic_params['slug'] ?? '';
								$agentic_step_num     = $agentic_step_idx + 1;
								?>
								<div class="agentic-approval-item" data-id="<?php echo esc_attr( $agentic_item['id'] ); ?>" style="padding: 16px 20px; border-bottom: 1px solid #eee;">
									<div class="agentic-approval-item-row">
										<div class="agentic-approval-item-left">
											<span class="agentic-approval-icon" color: #50575e; font-size: 12px; font-weight: 600; flex-shrink: 0;">
												<?php echo esc_html( $agentic_step_num ); ?>
											</span>
											<div>
												<h4 class="agentic-approval-h4">
													<?php echo esc_html( $agentic_action_label ); ?>
													<?php if ( ! empty( $agentic_file_path ) ) : ?>
														<code class="agentic-code-sm-dim"><?php echo esc_html( $agentic_file_path ); ?></code>
													<?php elseif ( ! empty( $agentic_plugin_slug ) ) : ?>
														<code class="agentic-code-sm-dim"><?php echo esc_html( $agentic_plugin_slug ); ?>/</code>
													<?php endif; ?>
												</h4>
												<?php if ( ! empty( $agentic_item['reasoning'] ) ) : ?>
													<p class="agentic-approval-reasoning"><?php echo esc_html( $agentic_item['reasoning'] ); ?></p>
												<?php endif; ?>
											</div>
										</div>
										<div class="agentic-approval-btns">
										<?php if ( $agentic_group_count > 1 && $agentic_step_idx > 0 ) : ?>
											<span class="button button-small agentic-step-pending" data-id="<?php echo esc_attr( $agentic_item['id'] ); ?>" data-step="<?php echo esc_attr( $agentic_step_num ); ?>" data-depends="<?php echo esc_attr( $agentic_step_idx ); ?>" style="color: #999; border-color: #ddd; cursor: default;">
												<?php
												/* translators: %d: step number this item is waiting for */
												printf( esc_html__( 'Pending %d', 'agentstructor' ), esc_html( $agentic_step_idx ) );
												?>
											</span>
										<?php else : ?>
											<button class="button button-small button-primary agentic-approve-btn" data-id="<?php echo esc_attr( $agentic_item['id'] ); ?>">
												<?php esc_html_e( 'Approve', 'agentstructor' ); ?>
											</button>
											<button class="button button-small agentic-reject-btn" data-id="<?php echo esc_attr( $agentic_item['id'] ); ?>">
												<?php esc_html_e( 'Reject', 'agentstructor' ); ?>
											</button>
										<?php endif; ?>
										</div>
									</div>

									<details class="agentic-approval-details">
										<summary class="agentic-approval-summary"><?php esc_html_e( 'View Details', 'agentstructor' ); ?></summary>
										<div class="agentic-approval-json">
											<pre class="agentic-text-xxs agentic-pre-wrap"><?php echo esc_html( wp_json_encode( $agentic_params, JSON_PRETTY_PRINT ) ); ?></pre>
										</div>
									</details>
								</div>
							<?php endforeach; ?>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

	<?php elseif ( 'backups' === $agentic_active_tab ) : ?>
		<?php
		$agentic_file_backups  = \Agentic\Tool_Helpers::get_backups();
		$agentic_table_backups = \Agentic\Tool_Helpers::get_table_backups();
		$agentic_has_any       = ! empty( $agentic_file_backups ) || ! empty( $agentic_table_backups );
		?>

		<p class="description agentic-mt-16">
			<?php esc_html_e( 'Automatic backups created before AI agents modify files or database tables. Restore with one click.', 'agentstructor' ); ?>
			<a href="https://agentstructor.com/backups/" target="_blank" rel="noopener noreferrer" class="agentic-ml-6"><?php esc_html_e( 'Learn more', 'agentstructor' ); ?> &rarr;</a>
		</p>

		<?php if ( ! $agentic_has_any ) : ?>
			<div class="agentic-card agentic-approval-empty-card">
				<span class="dashicons dashicons-backup agentic-di-xxl agentic-di-grey agentic-di-mb10"></span>
				<h2 class="agentic-approval-h2"><?php esc_html_e( 'No backups yet', 'agentstructor' ); ?></h2>
				<p class="agentic-text-dim"><?php esc_html_e( 'When agents modify files or database tables, backups are created automatically and will appear here.', 'agentstructor' ); ?></p>
			</div>
		<?php else : ?>

			<?php // --- Database table backups --- ?>
			<?php if ( ! empty( $agentic_table_backups ) ) : ?>
				<h3 class="agentic-backup-section-h3">
					<span class="dashicons dashicons-database agentic-di-va-m4"></span>
					<?php esc_html_e( 'Database Tables', 'agentstructor' ); ?>
				</h3>
				<p class="description">
					<?php esc_html_e( 'Full table snapshots taken before write operations. Up to 3 copies kept per table.', 'agentstructor' ); ?>
				</p>
				<table class="wp-list-table widefat fixed striped agentic-mt-10">
					<thead>
						<tr>
							<th class="agentic-col-22"><?php esc_html_e( 'Table', 'agentstructor' ); ?></th>
							<th class="agentic-col-10"><?php esc_html_e( 'Type', 'agentstructor' ); ?></th>
							<th class="agentic-col-20"><?php esc_html_e( 'Backed Up', 'agentstructor' ); ?></th>
							<th class="agentic-col-8"><?php esc_html_e( 'Rows', 'agentstructor' ); ?></th>
							<th class="agentic-col-10"><?php esc_html_e( 'Size', 'agentstructor' ); ?></th>
							<th class="agentic-col-30"><?php esc_html_e( 'Actions', 'agentstructor' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $agentic_table_backups as $agentic_tb ) : ?>
							<tr class="agentic-table-backup-row" data-file="<?php echo esc_attr( $agentic_tb['file'] ); ?>">
								<td>
									<strong><code><?php echo esc_html( $agentic_tb['table'] ); ?></code></strong>
								</td>
								<td>
									<?php if ( 'partial' === ( $agentic_tb['type'] ?? 'full' ) ) : ?>
										<span class="agentic-badge agentic-badge-partial"><?php esc_html_e( 'Partial', 'agentstructor' ); ?></span>
									<?php else : ?>
										<span class="agentic-badge agentic-badge-full"><?php esc_html_e( 'Full', 'agentstructor' ); ?></span>
									<?php endif; ?>
								<td>
									<?php
									if ( ! empty( $agentic_tb['created'] ) ) {
										$agentic_tb_time = strtotime( $agentic_tb['created'] . ' UTC' );
										echo esc_html( human_time_diff( $agentic_tb_time ) ) . ' ago';
										echo '<br><span class="agentic-text-dim agentic-text-xs">' . esc_html( wp_date( 'M j, Y g:i A', $agentic_tb_time ) ) . '</span>';
									}
									?>
								</td>
								<td><?php echo esc_html( number_format_i18n( $agentic_tb['row_count'] ) ); ?></td>
								<td><?php echo esc_html( size_format( $agentic_tb['size'] ) ); ?></td>
								<td>
									<button class="button button-primary button-small agentic-restore-table-btn" data-file="<?php echo esc_attr( $agentic_tb['file'] ); ?>">
										<span class="dashicons dashicons-backup agentic-di-md agentic-di-va-m3"></span> <?php esc_html_e( 'Restore', 'agentstructor' ); ?>
									</button>
									<button class="button button-small agentic-delete-table-backup-btn" data-file="<?php echo esc_attr( $agentic_tb['file'] ); ?>" style="margin-left: 4px; color: #b32d2e;">
										<span class="dashicons dashicons-trash agentic-di-md agentic-di-va-m3"></span>
									</button>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<?php // --- File backups --- ?>
			<?php if ( ! empty( $agentic_file_backups ) ) : ?>
				<h3 class="agentic-backup-section-h3">
					<span class="dashicons dashicons-media-text agentic-di-va-m4"></span>
					<?php esc_html_e( 'Files', 'agentstructor' ); ?>
				</h3>
				<p class="description">
					<?php esc_html_e( 'File snapshots taken before write operations.', 'agentstructor' ); ?>
				</p>
				<table class="wp-list-table widefat fixed striped agentic-mt-10">
					<thead>
						<tr>
							<th class="agentic-col-35"><?php esc_html_e( 'Original File', 'agentstructor' ); ?></th>
							<th class="agentic-col-22"><?php esc_html_e( 'Backed Up', 'agentstructor' ); ?></th>
							<th class="agentic-col-10"><?php esc_html_e( 'Size', 'agentstructor' ); ?></th>
							<th class="agentic-col-12"><?php esc_html_e( 'Status', 'agentstructor' ); ?></th>
							<th class="agentic-col-21"><?php esc_html_e( 'Actions', 'agentstructor' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $agentic_file_backups as $agentic_backup ) : ?>
							<tr class="agentic-backup-row" data-file="<?php echo esc_attr( $agentic_backup['file'] ); ?>">
								<td>
									<strong><code><?php echo esc_html( $agentic_backup['original_rel'] ); ?></code></strong>
								</td>
								<td>
									<?php
									if ( ! empty( $agentic_backup['created'] ) ) {
										$agentic_backup_time = strtotime( $agentic_backup['created'] . ' UTC' );
										echo esc_html( human_time_diff( $agentic_backup_time ) ) . ' ago';
										echo '<br><span class="agentic-text-dim agentic-text-xs">' . esc_html( wp_date( 'M j, Y g:i A', $agentic_backup_time ) ) . '</span>';
									}
									?>
								</td>
								<td><?php echo esc_html( size_format( $agentic_backup['size'] ) ); ?></td>
								<td>
									<?php if ( $agentic_backup['original_exists'] ) : ?>
										<span class="agentic-text-blue">
											<span class="dashicons dashicons-yes-alt agentic-di-md agentic-di-va-m3"></span>
											<?php esc_html_e( 'File exists', 'agentstructor' ); ?>
										</span>
									<?php else : ?>
										<span class="agentic-text-danger">
											<span class="dashicons dashicons-warning agentic-di-md agentic-di-va-m3"></span>
											<?php esc_html_e( 'Deleted', 'agentstructor' ); ?>
										</span>
									<?php endif; ?>
								</td>
								<td>
									<button class="button button-primary button-small agentic-restore-btn" data-file="<?php echo esc_attr( $agentic_backup['file'] ); ?>">
										<span class="dashicons dashicons-backup agentic-di-md agentic-di-va-m3"></span> <?php esc_html_e( 'Restore', 'agentstructor' ); ?>
									</button>
									<button class="button button-small agentic-delete-backup-btn" data-file="<?php echo esc_attr( $agentic_backup['file'] ); ?>" style="margin-left: 4px; color: #b32d2e;">
										<span class="dashicons dashicons-trash agentic-di-md agentic-di-va-m3"></span>
									</button>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<p class="description agentic-mt-16">
				<?php esc_html_e( 'Backups stored in', 'agentstructor' ); ?> <code><?php echo esc_html( str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( \Agentic\File_Manager::backups_dir() ) ) ); ?>/</code>.
				<?php esc_html_e( 'Database tables keep up to 3 snapshots each. File backups are kept until manually deleted.', 'agentstructor' ); ?>
			</p>
		<?php endif; ?>

	<?php endif; ?>
	<?php \Agentic\Admin_Vnav::close(); ?>
</div>

<?php
\Agentic\Inline_Assets::script(
	'approvals',
	array(
		'restBase' => esc_url_raw( rest_url( 'agentic/v1/' ) ),
		'nonce'    => wp_create_nonce( 'wp_rest' ),
	),
	'agenticApprovals',
	array( 'jquery', 'agentic-ui' )
);
?>
