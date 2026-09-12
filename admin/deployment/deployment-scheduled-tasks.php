<?php
/**
 * Deployment Tab: Scheduled Tasks
 *
 * Lists built-in agent tasks and admin-created schedules, with Schedule /
 * Delete / Run Now actions and a form to add new AI-driven cron tasks.
 * Included by admin/deployment.php — do not load directly.
 *
 * @package    Agent_Builder
 * @subpackage Admin
 * @since      1.7.0
 *
 * php version 8.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Handle delete scheduled task action (clears WP-Cron for built-in tasks).
if ( isset( $_POST['agentic_delete_task'] ) && check_admin_referer( 'agentic_delete_task' ) ) {
	$agentic_delete_hook    = sanitize_text_field( wp_unslash( $_POST['agentic_delete_hook'] ?? '' ) );
	$agentic_delete_agent   = sanitize_key( wp_unslash( $_POST['agentic_delete_agent'] ?? '' ) );
	$agentic_delete_task_id = sanitize_key( wp_unslash( $_POST['agentic_delete_task_id'] ?? '' ) );
	if ( $agentic_delete_hook ) {
		wp_clear_scheduled_hook( $agentic_delete_hook );

		// Disable the Deployments row so the tab shows "Not Scheduled".
		if ( class_exists( '\Agentic\Deployments' ) && $agentic_delete_agent && $agentic_delete_task_id ) {
			foreach ( \Agentic\Deployments::all( \Agentic\Deployments::TYPE_SCHEDULED_TASK, $agentic_delete_agent ) as $agentic_st_del ) {
				if ( ( $agentic_st_del['config']['task_id'] ?? '' ) === $agentic_delete_task_id ) {
					\Agentic\Deployments::disable( $agentic_st_del['id'] );
					break;
				}
			}
		}

		\Agentic\Security_Log::log_system(
			'settings_changed',
			'scheduled_tasks',
			array(
				'setting' => 'delete_task',
				'changes' => array( 'hook' => $agentic_delete_hook ),
			)
		);
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Scheduled task removed from the cron queue.', 'agent-builder' ) . '</p></div>';
	}
}

// Handle schedule task action (built-in agent tasks).
if ( isset( $_POST['agentic_schedule_task'] ) && check_admin_referer( 'agentic_schedule_task' ) ) {
	$agentic_sched_hook      = sanitize_text_field( wp_unslash( $_POST['agentic_sched_hook'] ?? '' ) );
	$agentic_sched_recur     = sanitize_text_field( wp_unslash( $_POST['agentic_sched_recurrence'] ?? '' ) );
	$agentic_sched_agent     = sanitize_key( wp_unslash( $_POST['agentic_sched_agent'] ?? '' ) );
	$agentic_sched_task_id   = sanitize_key( wp_unslash( $_POST['agentic_sched_task_id'] ?? '' ) );
	$agentic_valid_schedules = array_keys( wp_get_schedules() );
	if ( $agentic_sched_hook && in_array( $agentic_sched_recur, $agentic_valid_schedules, true ) ) {
		$agentic_next_ts = time();
		if ( ! wp_next_scheduled( $agentic_sched_hook ) ) {
			wp_schedule_event( $agentic_next_ts, $agentic_sched_recur, $agentic_sched_hook );
		} else {
			$agentic_next_ts = (int) wp_next_scheduled( $agentic_sched_hook );
		}

		// Update or create the Deployments row.
		if ( class_exists( '\Agentic\Deployments' ) && $agentic_sched_agent && $agentic_sched_task_id ) {
			$agentic_st_found = null;
			foreach ( \Agentic\Deployments::all( \Agentic\Deployments::TYPE_SCHEDULED_TASK, $agentic_sched_agent ) as $agentic_st_sch ) {
				if ( ( $agentic_st_sch['config']['task_id'] ?? '' ) === $agentic_sched_task_id ) {
					$agentic_st_found = $agentic_st_sch;
					break;
				}
			}

			$agentic_st_cfg = array(
				'task_id'     => $agentic_sched_task_id,
				'schedule'    => $agentic_sched_recur,
				'mode'        => 'autonomous',
				'description' => '',
				'next_run'    => gmdate( 'Y-m-d H:i:s', $agentic_next_ts ),
				'last_run'    => $agentic_st_found['config']['last_run'] ?? null,
				'last_status' => $agentic_st_found['config']['last_status'] ?? null,
			);

			if ( $agentic_st_found ) {
				\Agentic\Deployments::save(
					array_merge(
						array(
							'id'      => $agentic_st_found['id'],
							'enabled' => 1,
						),
						array( 'config' => $agentic_st_cfg )
					)
				);
			} else {
				\Agentic\Deployments::save(
					array(
						'type'       => \Agentic\Deployments::TYPE_SCHEDULED_TASK,
						'agent_slug' => $agentic_sched_agent,
						'label'      => ucwords( str_replace( '-', ' ', $agentic_sched_agent ) ) . ' — ' . $agentic_sched_task_id,
						'enabled'    => 1,
						'source'     => \Agentic\Deployments::SOURCE_ADMIN,
						'config'     => $agentic_st_cfg,
					)
				);
			}
		}

		\Agentic\Security_Log::log_system(
			'settings_changed',
			'scheduled_tasks',
			array(
				'setting' => 'schedule_task',
				'changes' => array(
					'hook'       => $agentic_sched_hook,
					'recurrence' => $agentic_sched_recur,
				),
			)
		);
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Scheduled task registered.', 'agent-builder' ) . '</p></div>';
	}
}

// -------------------------------------------------------------------------
// Collect tasks: built-in agent definitions + admin-created schedules.
// -------------------------------------------------------------------------
$agentic_registry  = \Agentic_Agent_Registry::get_instance();
$agentic_instances = $agentic_registry->get_all_instances();
$agentic_all_tasks = array();
$agentic_schedules = wp_get_schedules();

// Pre-load Deployments rows indexed by agent_slug + task_id.
$agentic_st_rows = array();
if ( class_exists( '\Agentic\Deployments' ) ) {
	foreach ( \Agentic\Deployments::all( \Agentic\Deployments::TYPE_SCHEDULED_TASK ) as $agentic_st_r ) {
		$agentic_st_key                     = $agentic_st_r['agent_slug'] . '|' . ( $agentic_st_r['config']['task_id'] ?? '' );
		$agentic_st_rows[ $agentic_st_key ] = $agentic_st_r;
	}
}

foreach ( $agentic_instances as $agentic_agent ) {
	$agentic_tasks = $agentic_agent->get_scheduled_tasks();
	foreach ( $agentic_tasks as $agentic_task ) {
		$agentic_hook     = $agentic_agent->get_cron_hook( $agentic_task['id'] );
		$agentic_next_run = wp_next_scheduled( $agentic_hook );

		$agentic_st_key = $agentic_agent->get_id() . '|' . $agentic_task['id'];
		$agentic_st_row = $agentic_st_rows[ $agentic_st_key ] ?? null;

		// Auto-register in Deployments on first display.
		if ( class_exists( '\Agentic\Deployments' ) && ! $agentic_st_row ) {
			$agentic_st_new_id = \Agentic\Deployments::save(
				array(
					'type'       => \Agentic\Deployments::TYPE_SCHEDULED_TASK,
					'agent_slug' => $agentic_agent->get_id(),
					'label'      => $agentic_agent->get_name() . ' — ' . $agentic_task['name'],
					'enabled'    => false !== $agentic_next_run ? 1 : 0,
					'source'     => \Agentic\Deployments::SOURCE_AUTO,
					'config'     => array(
						'task_id'     => $agentic_task['id'],
						'schedule'    => $agentic_task['schedule'],
						'mode'        => ! empty( $agentic_task['prompt'] ) ? 'autonomous' : 'direct',
						'description' => $agentic_task['description'] ?? '',
						'next_run'    => $agentic_next_run ? gmdate( 'Y-m-d H:i:s', (int) $agentic_next_run ) : null,
						'last_run'    => null,
						'last_status' => null,
					),
				)
			);
			$agentic_st_row    = \Agentic\Deployments::get( $agentic_st_new_id );
		}

		$agentic_all_tasks[] = array(
			'source'           => 'builtin',
			'agent_id'         => $agentic_agent->get_id(),
			'agent_name'       => $agentic_agent->get_name(),
			'agent_icon'       => $agentic_agent->get_icon(),
			'task_id'          => $agentic_task['id'],
			'task_name'        => $agentic_task['name'],
			'description'      => $agentic_task['description'] ?? '',
			'schedule'         => $agentic_task['schedule'],
			'schedule_display' => $agentic_schedules[ $agentic_task['schedule'] ]['display'] ?? ucfirst( $agentic_task['schedule'] ),
			'hook'             => $agentic_hook,
			'next_run'         => $agentic_next_run,
			'registered'       => false !== $agentic_next_run,
			'mode'             => ! empty( $agentic_task['prompt'] ) ? 'autonomous' : 'direct',
			'last_run'         => $agentic_st_row['config']['last_run'] ?? null,
			'last_status'      => $agentic_st_row['config']['last_status'] ?? null,
		);
	}
}

// Admin-created schedules (any active assistant + prompt).
foreach ( \Agentic\Agent_Lifecycle::get_user_scheduled_tasks() as $agentic_ut ) {
	$agentic_ut_agent = $agentic_registry->get_agent_instance( $agentic_ut['agent_slug'] ?? '' );
	$agentic_hook     = \Agentic\Agent_Lifecycle::user_task_cron_hook(
		(string) ( $agentic_ut['agent_slug'] ?? '' ),
		(string) ( $agentic_ut['id'] ?? '' )
	);
	$agentic_next_run = wp_next_scheduled( $agentic_hook );
	$agentic_st_key   = ( $agentic_ut['agent_slug'] ?? '' ) . '|' . ( $agentic_ut['id'] ?? '' );
	$agentic_st_row   = $agentic_st_rows[ $agentic_st_key ] ?? null;
	$agentic_sched    = $agentic_ut['schedule'] ?? 'daily';

	$agentic_all_tasks[] = array(
		'source'           => 'user',
		'agent_id'         => $agentic_ut['agent_slug'] ?? '',
		'agent_name'       => $agentic_ut_agent ? $agentic_ut_agent->get_name() : ( $agentic_ut['agent_slug'] ?? '' ),
		'agent_icon'       => $agentic_ut_agent ? $agentic_ut_agent->get_icon() : '🤖',
		'task_id'          => $agentic_ut['id'] ?? '',
		'task_name'        => $agentic_ut['name'] ?? '',
		'description'      => $agentic_ut['description'] ?? ( $agentic_ut['prompt'] ?? '' ),
		'schedule'         => $agentic_sched,
		'schedule_display' => $agentic_schedules[ $agentic_sched ]['display'] ?? ucfirst( $agentic_sched ),
		'hook'             => $agentic_hook,
		'next_run'         => $agentic_next_run,
		'registered'       => false !== $agentic_next_run,
		'mode'             => 'autonomous',
		'last_run'         => $agentic_st_row['config']['last_run'] ?? null,
		'last_status'      => $agentic_st_row['config']['last_status'] ?? null,
	);
}

// Agent list + schedule options for the create form.
$agentic_agent_options = array();
foreach ( $agentic_instances as $agentic_agent ) {
	$agentic_agent_options[ $agentic_agent->get_id() ] = $agentic_agent->get_icon() . ' ' . $agentic_agent->get_name();
}

$agentic_schedule_options = array();
foreach ( \Agentic\Agent_Lifecycle::ALLOWED_USER_SCHEDULES as $agentic_sk ) {
	$agentic_schedule_options[ $agentic_sk ] = $agentic_schedules[ $agentic_sk ]['display'] ?? ucfirst( $agentic_sk );
}

$agentic_tasks_nonce = wp_create_nonce( 'agentic_user_scheduled_tasks' );
$agentic_ajax_url    = admin_url( 'admin-ajax.php' );
?>

<!-- =====================================================================
	Add Scheduled Task Form
	===================================================================== -->
<div id="agentic-sched-form-wrap" class="agentic-trigger-form">
	<h2 class="agentic-section-h2b"><?php esc_html_e( 'Add Scheduled Task', 'agent-builder' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Run any active agent on a WP-Cron schedule with an AI prompt. Built-in tasks from agents also appear in the table below.', 'agent-builder' ); ?></p>

	<input type="hidden" id="agentic-sched-editing-id" value="">

	<table class="form-table agentic-table-mt-0">
		<tr>
			<th scope="row" class="agentic-trigger-form-th">
				<label for="agentic-sched-agent"><?php esc_html_e( 'Agent', 'agent-builder' ); ?></label>
			</th>
			<td class="agentic-trigger-form-td">
				<select id="agentic-sched-agent" class="agentic-select-260">
					<option value=""><?php esc_html_e( '— choose an agent —', 'agent-builder' ); ?></option>
					<?php foreach ( $agentic_agent_options as $agentic_slug => $agentic_label ) : ?>
					<option value="<?php echo esc_attr( $agentic_slug ); ?>"><?php echo esc_html( $agentic_label ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php if ( empty( $agentic_agent_options ) ) : ?>
					<p class="description agentic-text-danger"><?php esc_html_e( 'No agents active. Activate an agent first.', 'agent-builder' ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<tr>
			<th scope="row" class="agentic-trigger-form-th">
				<label for="agentic-sched-recurrence"><?php esc_html_e( 'Schedule', 'agent-builder' ); ?></label>
			</th>
			<td class="agentic-trigger-form-td">
				<select id="agentic-sched-recurrence" class="agentic-select-260">
					<?php foreach ( $agentic_schedule_options as $agentic_sk => $agentic_slabel ) : ?>
					<option value="<?php echo esc_attr( $agentic_sk ); ?>" <?php selected( $agentic_sk, 'daily' ); ?>><?php echo esc_html( $agentic_slabel ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
		</tr>
		<tr>
			<th scope="row" class="agentic-trigger-form-th">
				<label for="agentic-sched-prompt"><?php esc_html_e( 'Prompt', 'agent-builder' ); ?></label>
			</th>
			<td class="agentic-trigger-form-td">
				<textarea id="agentic-sched-prompt" rows="3" class="agentic-textarea-full" placeholder="<?php esc_attr_e( 'Describe what the agent should do on each run…', 'agent-builder' ); ?>"></textarea>
			</td>
		</tr>
		<tr>
			<th scope="row" class="agentic-trigger-form-th">
				<label for="agentic-sched-name"><?php esc_html_e( 'Label', 'agent-builder' ); ?></label>
			</th>
			<td class="agentic-trigger-form-td">
				<input type="text" id="agentic-sched-name" class="regular-text" placeholder="<?php esc_attr_e( 'Optional — auto-generated if blank', 'agent-builder' ); ?>">
			</td>
		</tr>
		<tr>
			<th scope="row" class="agentic-trigger-form-th">
				<label for="agentic-sched-description"><?php esc_html_e( 'Description', 'agent-builder' ); ?></label>
			</th>
			<td class="agentic-trigger-form-td">
				<input type="text" id="agentic-sched-description" class="regular-text" placeholder="<?php esc_attr_e( 'Optional short note', 'agent-builder' ); ?>">
			</td>
		</tr>
	</table>

	<div class="agentic-action-row">
		<button type="button" id="agentic-sched-save" class="button button-primary" <?php disabled( empty( $agentic_agent_options ) ); ?>>
			<?php esc_html_e( 'Add & Schedule', 'agent-builder' ); ?>
		</button>
		<button type="button" id="agentic-sched-cancel" class="button" hidden><?php esc_html_e( 'Cancel', 'agent-builder' ); ?></button>
		<span id="agentic-sched-status" class="agentic-trigger-status"></span>
	</div>
</div>

<!-- =====================================================================
	Tasks Table
	===================================================================== -->
<?php if ( empty( $agentic_all_tasks ) ) : ?>
	<div class="notice notice-info inline">
		<p><?php esc_html_e( 'No scheduled tasks yet. Add one above, or activate agents that define built-in scheduled tasks (for example Site Auditor or AI Radar).', 'agent-builder' ); ?></p>
	</div>
<?php else : ?>
	<div class="agentic-table-scroll">
	<table class="widefat striped agentic-table-min-900">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Agent', 'agent-builder' ); ?></th>
				<th><?php esc_html_e( 'Task', 'agent-builder' ); ?></th>
				<th><?php esc_html_e( 'Schedule', 'agent-builder' ); ?></th>
				<th><?php esc_html_e( 'Mode', 'agent-builder' ); ?></th>
				<th><?php esc_html_e( 'Source', 'agent-builder' ); ?></th>
				<th><?php esc_html_e( 'Status', 'agent-builder' ); ?></th>
				<th><?php esc_html_e( 'Next Run', 'agent-builder' ); ?></th>
				<th><?php esc_html_e( 'Last Run', 'agent-builder' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'agent-builder' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $agentic_all_tasks as $agentic_task_row ) : ?>
			<tr id="agentic-sched-row-<?php echo esc_attr( $agentic_task_row['task_id'] ); ?>">
				<td>
					<span class="agentic-agent-icon"><?php echo esc_html( $agentic_task_row['agent_icon'] ); ?></span>
					<?php echo esc_html( $agentic_task_row['agent_name'] ); ?>
				</td>
				<td>
					<strong><?php echo esc_html( $agentic_task_row['task_name'] ); ?></strong>
					<?php if ( $agentic_task_row['description'] ) : ?>
						<br><small class="agentic-text-muted"><?php echo esc_html( wp_trim_words( $agentic_task_row['description'], 16 ) ); ?></small>
					<?php endif; ?>
				</td>
				<td><?php echo esc_html( $agentic_task_row['schedule_display'] ); ?></td>
				<td>
					<?php if ( 'autonomous' === $agentic_task_row['mode'] ) : ?>
						<span title="<?php esc_attr_e( 'Runs through the LLM with AI reasoning and tool calls', 'agent-builder' ); ?>" style="color: #2271b1;">🤖 <?php esc_html_e( 'AI', 'agent-builder' ); ?></span>
					<?php else : ?>
						<span title="<?php esc_attr_e( 'Runs the callback method directly without LLM', 'agent-builder' ); ?>" style="color: #646970;">⚙️ <?php esc_html_e( 'Direct', 'agent-builder' ); ?></span>
					<?php endif; ?>
				</td>
				<td>
					<?php if ( 'user' === $agentic_task_row['source'] ) : ?>
						<span class="agentic-badge-pill-blue"><?php esc_html_e( 'Custom', 'agent-builder' ); ?></span>
					<?php else : ?>
						<span class="agentic-badge-pill-grey"><?php esc_html_e( 'Built-in', 'agent-builder' ); ?></span>
					<?php endif; ?>
				</td>
				<td>
					<?php if ( $agentic_task_row['registered'] ) : ?>
						<span class="agentic-status-active">&#9679; <?php esc_html_e( 'Active', 'agent-builder' ); ?></span>
					<?php else : ?>
						<span class="agentic-status-inactive">&#9679; <?php esc_html_e( 'Not Scheduled', 'agent-builder' ); ?></span>
					<?php endif; ?>
				</td>
				<td>
					<?php if ( $agentic_task_row['next_run'] ) : ?>
						<?php echo esc_html( wp_date( 'Y-m-d H:i', $agentic_task_row['next_run'] ) ); ?>
						<br><small class="agentic-text-muted">
							<?php
							$agentic_diff = $agentic_task_row['next_run'] - time();
							if ( $agentic_diff > 0 ) {
								/* translators: %s: human-readable time difference */
								printf( esc_html__( 'in %s', 'agent-builder' ), esc_html( human_time_diff( time(), $agentic_task_row['next_run'] ) ) );
							} else {
								esc_html_e( 'overdue', 'agent-builder' );
							}
							?>
						</small>
					<?php else : ?>
						&mdash;
					<?php endif; ?>
				</td>
				<td>
					<?php if ( $agentic_task_row['last_run'] ) : ?>
						<?php echo esc_html( wp_date( 'Y-m-d H:i', strtotime( $agentic_task_row['last_run'] ) ) ); ?>
						<?php if ( $agentic_task_row['last_status'] ) : ?>
							<br><small class="<?php echo 'ok' === $agentic_task_row['last_status'] ? 'agentic-text-green' : 'agentic-text-danger'; ?>">
								<?php echo esc_html( $agentic_task_row['last_status'] ); ?>
							</small>
						<?php endif; ?>
					<?php else : ?>
						&mdash;
					<?php endif; ?>
				</td>
				<td>
					<?php if ( $agentic_task_row['registered'] ) : ?>
						<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=agentic-run-task&agent=' . rawurlencode( $agentic_task_row['agent_id'] ) . '&task=' . rawurlencode( $agentic_task_row['task_id'] ) ), 'agentic_run_task', '_wpnonce' ) ); ?>" class="button button-small">
							<?php esc_html_e( 'Run Now', 'agent-builder' ); ?>
						</a>
						<?php if ( 'user' === $agentic_task_row['source'] ) : ?>
						<button type="button"
							class="button button-small agentic-sched-delete agentic-text-danger"
							data-id="<?php echo esc_attr( $agentic_task_row['task_id'] ); ?>">
							<?php esc_html_e( 'Delete', 'agent-builder' ); ?>
						</button>
						<?php else : ?>
						<form method="post" class="agentic-form-inline" data-agentic-confirm="<?php echo esc_attr( __( 'Remove this scheduled task from the cron queue?', 'agent-builder' ) ); ?>" data-agentic-confirm-danger>
							<?php wp_nonce_field( 'agentic_delete_task' ); ?>
							<input type="hidden" name="agentic_delete_hook"    value="<?php echo esc_attr( $agentic_task_row['hook'] ); ?>">
							<input type="hidden" name="agentic_delete_agent"   value="<?php echo esc_attr( $agentic_task_row['agent_id'] ); ?>">
							<input type="hidden" name="agentic_delete_task_id" value="<?php echo esc_attr( $agentic_task_row['task_id'] ); ?>">
							<button type="submit" name="agentic_delete_task" value="1" class="button button-small agentic-text-danger">
								<?php esc_html_e( 'Unschedule', 'agent-builder' ); ?>
							</button>
						</form>
						<?php endif; ?>
					<?php else : ?>
						<?php if ( 'user' === $agentic_task_row['source'] ) : ?>
						<form method="post" class="agentic-form-inline">
							<?php wp_nonce_field( 'agentic_schedule_task' ); ?>
							<input type="hidden" name="agentic_sched_hook"       value="<?php echo esc_attr( $agentic_task_row['hook'] ); ?>">
							<input type="hidden" name="agentic_sched_recurrence" value="<?php echo esc_attr( $agentic_task_row['schedule'] ); ?>">
							<input type="hidden" name="agentic_sched_agent"      value="<?php echo esc_attr( $agentic_task_row['agent_id'] ); ?>">
							<input type="hidden" name="agentic_sched_task_id"    value="<?php echo esc_attr( $agentic_task_row['task_id'] ); ?>">
							<button type="submit" name="agentic_schedule_task" value="1" class="button button-small button-primary">
								<?php esc_html_e( 'Schedule', 'agent-builder' ); ?>
							</button>
						</form>
						<button type="button"
							class="button button-small agentic-sched-delete agentic-text-danger"
							data-id="<?php echo esc_attr( $agentic_task_row['task_id'] ); ?>">
							<?php esc_html_e( 'Delete', 'agent-builder' ); ?>
						</button>
						<?php else : ?>
						<form method="post" class="agentic-form-inline">
							<?php wp_nonce_field( 'agentic_schedule_task' ); ?>
							<input type="hidden" name="agentic_sched_hook"       value="<?php echo esc_attr( $agentic_task_row['hook'] ); ?>">
							<input type="hidden" name="agentic_sched_recurrence" value="<?php echo esc_attr( $agentic_task_row['schedule'] ); ?>">
							<input type="hidden" name="agentic_sched_agent"      value="<?php echo esc_attr( $agentic_task_row['agent_id'] ); ?>">
							<input type="hidden" name="agentic_sched_task_id"    value="<?php echo esc_attr( $agentic_task_row['task_id'] ); ?>">
							<button type="submit" name="agentic_schedule_task" value="1" class="button button-small button-primary">
								<?php esc_html_e( 'Schedule', 'agent-builder' ); ?>
							</button>
						</form>
						<?php endif; ?>
					<?php endif; ?>
				</td>
			</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	</div>
<?php endif; ?>

	<div class="agentic-embed-note">
		<h3><?php esc_html_e( 'How Scheduled Tasks Work', 'agent-builder' ); ?></h3>
		<ul>
			<li><?php esc_html_e( 'Tasks use WordPress cron (WP-Cron), which runs when someone visits your site.', 'agent-builder' ); ?></li>
			<li><?php esc_html_e( 'For reliable scheduling on low-traffic sites, set up a real cron job to hit wp-cron.php.', 'agent-builder' ); ?></li>
			<li><?php esc_html_e( 'Custom tasks you add here are scheduled immediately and run through the LLM with full tool access.', 'agent-builder' ); ?></li>
			<li><?php esc_html_e( 'Built-in tasks ship with some agents; use Schedule to put them on the cron queue, or Unschedule to pause them without deleting the definition.', 'agent-builder' ); ?></li>
			<li><?php esc_html_e( 'Every execution is logged in the Audit Log with timing, mode, and outcome details.', 'agent-builder' ); ?></li>
		</ul>
	</div>

<script>
(function($){
	'use strict';

	const ajaxUrl = <?php echo wp_json_encode( $agentic_ajax_url ); ?>;
	const nonce   = <?php echo wp_json_encode( $agentic_tasks_nonce ); ?>;

	function resetForm() {
		$('#agentic-sched-editing-id').val('');
		$('#agentic-sched-agent').val('');
		$('#agentic-sched-recurrence').val('daily');
		$('#agentic-sched-prompt').val('');
		$('#agentic-sched-name').val('');
		$('#agentic-sched-description').val('');
		$('#agentic-sched-save').text(<?php echo wp_json_encode( __( 'Add & Schedule', 'agent-builder' ) ); ?>);
		$('#agentic-sched-cancel').hide();
		$('#agentic-sched-status').text('');
	}

	$('#agentic-sched-cancel').on('click', resetForm);

	$('#agentic-sched-save').on('click', function(){
		const $btn    = $(this);
		const $status = $('#agentic-sched-status');
		const agent   = $('#agentic-sched-agent').val();
		const prompt  = $('#agentic-sched-prompt').val().trim();

		if ( ! agent ) {
			$status.css('color','#d63638').text(<?php echo wp_json_encode( __( 'Please choose an agent.', 'agent-builder' ) ); ?>);
			return;
		}
		if ( ! prompt ) {
			$status.css('color','#d63638').text(<?php echo wp_json_encode( __( 'Please enter a prompt.', 'agent-builder' ) ); ?>);
			return;
		}

		$btn.prop('disabled', true).text(<?php echo wp_json_encode( __( 'Saving…', 'agent-builder' ) ); ?>);
		$status.text('');

		$.post(ajaxUrl, {
			action      : 'agentic_save_user_scheduled_task',
			_ajax_nonce : nonce,
			task_id     : $('#agentic-sched-editing-id').val(),
			agent_slug  : agent,
			schedule    : $('#agentic-sched-recurrence').val(),
			prompt      : prompt,
			name        : $('#agentic-sched-name').val(),
			description : $('#agentic-sched-description').val(),
		})
		.done(function(resp){
			if (resp.success) {
				window.location.reload();
			} else {
				$status.css('color','#d63638').text(resp.data || <?php echo wp_json_encode( __( 'Error saving task.', 'agent-builder' ) ); ?>);
				$btn.prop('disabled', false).text(<?php echo wp_json_encode( __( 'Add & Schedule', 'agent-builder' ) ); ?>);
			}
		})
		.fail(function(){
			$status.css('color','#d63638').text(<?php echo wp_json_encode( __( 'Request failed.', 'agent-builder' ) ); ?>);
			$btn.prop('disabled', false).text(<?php echo wp_json_encode( __( 'Add & Schedule', 'agent-builder' ) ); ?>);
		});
	});

	$(document).on('click', '.agentic-sched-delete', async function(){
		const confirmMsg = <?php echo wp_json_encode( __( 'Permanently delete this custom scheduled task?', 'agent-builder' ) ); ?>;
		if ( window.agenticUI && typeof agenticUI.confirm === 'function' ) {
			if ( ! await agenticUI.confirm(confirmMsg, { danger: true }) ) return;
		} else if ( ! window.confirm(confirmMsg) ) {
			return;
		}

		const $btn = $(this);
		const id   = $btn.data('id');
		$btn.prop('disabled', true);

		$.post(ajaxUrl, {
			action      : 'agentic_delete_user_scheduled_task',
			_ajax_nonce : nonce,
			task_id     : id,
		})
		.done(function(resp){
			if (resp.success) {
				$('#agentic-sched-row-' + id).fadeOut(300, function(){ $(this).remove(); });
			} else {
				$btn.prop('disabled', false);
				if ( window.agenticUI && agenticUI.toast ) {
					agenticUI.toast(resp.data || <?php echo wp_json_encode( __( 'Could not delete task.', 'agent-builder' ) ); ?>, 'error');
				} else {
					alert(resp.data || <?php echo wp_json_encode( __( 'Could not delete task.', 'agent-builder' ) ); ?>);
				}
			}
		})
		.fail(function(){
			$btn.prop('disabled', false);
		});
	});

})(jQuery);
</script>
