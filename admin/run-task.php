<?php
/**
 * Run Scheduled Task — Dedicated Execution Page
 *
 * Two-step flow, single page, no navigation:
 * 1. Page loads instantly showing task details + "Execute Task" button.
 * 2. Button click fires AJAX — spinner + timer animate while task runs.
 * 3. Results render in-place when AJAX completes.
 *
 * URL: admin.php?page=agentic-run-task&agent={id}&task={id}&_wpnonce={nonce}
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

if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'agent_builder_run_tasks_manually' ) ) {
	wp_die( esc_html__( 'You do not have permission to access this page.', 'agent-builder' ) );
}

// Validate nonce.
if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'agentic_run_task' ) ) {
	wp_die( esc_html__( 'Invalid security token. Please go back and try again.', 'agent-builder' ) );
}

// Get parameters.
$agentic_run_agent_id = sanitize_text_field( wp_unslash( $_GET['agent'] ?? '' ) );
$agentic_run_task_id  = sanitize_text_field( wp_unslash( $_GET['task'] ?? '' ) );

if ( empty( $agentic_run_agent_id ) || empty( $agentic_run_task_id ) ) {
	wp_die( esc_html__( 'Missing agent or task parameter.', 'agent-builder' ) );
}

// Resolve agent and task.
$agentic_registry  = \Agentic_Agent_Registry::get_instance();
$agentic_agent_obj = $agentic_registry->get_agent_instance( $agentic_run_agent_id );

if ( ! $agentic_agent_obj ) {
	wp_die(
		sprintf(
			/* translators: %s: agent ID */
			esc_html__( 'Agent "%s" not found or not active.', 'agent-builder' ),
			esc_html( $agentic_run_agent_id )
		)
	);
}

$agentic_resolved = \Agentic\Agent_Lifecycle::resolve_task( $agentic_run_agent_id, $agentic_run_task_id );
$agentic_task_def = $agentic_resolved['task'] ?? null;

if ( ! $agentic_task_def ) {
	wp_die(
		sprintf(
			/* translators: %s: task ID */
			esc_html__( 'Task "%s" not found on this agent.', 'agent-builder' ),
			esc_html( $agentic_run_task_id )
		)
	);
}

$agentic_mode           = ! empty( $agentic_task_def['prompt'] ) ? 'autonomous' : 'direct';
$agentic_back_url       = admin_url( 'admin.php?page=agentic-deployment&tab=scheduled-tasks' );
$agentic_schedules      = wp_get_schedules();
$agentic_schedule_label = $agentic_schedules[ $agentic_task_def['schedule'] ]['display'] ?? ucfirst( $agentic_task_def['schedule'] );
?>
<div class="wrap">
	<h1>
		<?php
		printf(
			/* translators: %s: task name */
			esc_html__( 'Run Task: %s', 'agent-builder' ),
			esc_html( $agentic_task_def['name'] )
		);
		?>
	</h1>

	<!-- Task details table (always visible) -->
	<table class="widefat agentic-table-mt agentic-table-600">
		<tbody>
			<tr>
				<th class="agentic-col-120"><?php esc_html_e( 'Agent', 'agent-builder' ); ?></th>
				<td>
					<span class="agentic-agent-icon"><?php echo esc_html( $agentic_agent_obj->get_icon() ); ?></span>
					<?php echo esc_html( $agentic_agent_obj->get_name() ); ?>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Task', 'agent-builder' ); ?></th>
				<td><?php echo esc_html( $agentic_task_def['name'] ); ?></td>
			</tr>
			<?php if ( ! empty( $agentic_task_def['description'] ) ) : ?>
			<tr>
				<th><?php esc_html_e( 'Description', 'agent-builder' ); ?></th>
				<td><?php echo esc_html( $agentic_task_def['description'] ); ?></td>
			</tr>
			<?php endif; ?>
			<tr>
				<th><?php esc_html_e( 'Schedule', 'agent-builder' ); ?></th>
				<td><?php echo esc_html( $agentic_schedule_label ); ?></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Mode', 'agent-builder' ); ?></th>
				<td>
					<?php if ( 'autonomous' === $agentic_mode ) : ?>
						&#129302; <?php esc_html_e( 'AI (Autonomous) — sends a prompt to the LLM', 'agent-builder' ); ?>
					<?php else : ?>
						&#9881;&#65039; <?php esc_html_e( 'Direct (PHP) — calls the callback method', 'agent-builder' ); ?>
					<?php endif; ?>
				</td>
			</tr>
		</tbody>
	</table>

	<!-- Step 1: Execute button (hidden after click) -->
	<div id="agentic-pre-run">
		<?php if ( 'autonomous' === $agentic_mode ) : ?>
			<div class="notice notice-info agentic-mt-16 agentic-notice-600">
				<p><?php esc_html_e( 'This is an AI task. It will send a prompt to the configured LLM and typically takes 10–30 seconds.', 'agent-builder' ); ?></p>
			</div>
		<?php endif; ?>

		<p class="agentic-mt-20">
			<button type="button" id="agentic-execute-btn" class="button button-primary button-hero">
				&#9654; <?php esc_html_e( 'Execute Task', 'agent-builder' ); ?>
			</button>
			<a href="<?php echo esc_url( $agentic_back_url ); ?>" class="button button-hero agentic-ml-8">
				<?php esc_html_e( 'Cancel', 'agent-builder' ); ?>
			</a>
		</p>
	</div>

	<!-- Step 2: Running state (shown during execution) -->
	<div id="agentic-running" style="display: none; margin-top: 24px;">
		<div class="agentic-task-running-box">
			<div class="agentic-spinner"></div>
			<div>
				<div class="agentic-task-title">
					<?php
					if ( 'autonomous' === $agentic_mode ) {
						esc_html_e( 'Sending to LLM…', 'agent-builder' );
					} else {
						esc_html_e( 'Executing…', 'agent-builder' );
					}
					?>
				</div>
				<div class="agentic-task-meta">
					<?php esc_html_e( 'Elapsed:', 'agent-builder' ); ?>
					<span id="agentic-timer" class="agentic-timer">0s</span>
					<span class="agentic-ml-8">
						<?php
						if ( 'autonomous' === $agentic_mode ) {
							esc_html_e( '(AI tasks typically take 10–30 seconds)', 'agent-builder' );
						} else {
							esc_html_e( '(usually a few seconds)', 'agent-builder' );
						}
						?>
					</span>
				</div>
			</div>
		</div>
	</div>

	<!-- Step 3: Results (populated by JS after AJAX completes) -->
	<div id="agentic-results" style="display: none; margin-top: 20px;">
		<div id="agentic-result-notice"></div>

		<table class="widefat agentic-mt-16 agentic-table-600">
			<tbody>
				<tr>
					<th class="agentic-col-120"><?php esc_html_e( 'Duration', 'agent-builder' ); ?></th>
					<td id="agentic-result-duration"></td>
				</tr>
			</tbody>
		</table>

		<div id="agentic-result-output-wrap" style="display: none; margin-top: 24px;">
			<h2 class="agentic-mt-0"><?php esc_html_e( 'Task Output', 'agent-builder' ); ?></h2>
			<div id="agentic-result-output" class="agentic-task-output"></div>
		</div>

		<p class="agentic-mt-24">
			<a href="<?php echo esc_url( $agentic_back_url ); ?>" class="button button-primary">
				&#8592; <?php esc_html_e( 'Back to Scheduled Tasks', 'agent-builder' ); ?>
			</a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=agentic-audit-log' ) ); ?>" class="button agentic-ml-8">
				<?php esc_html_e( 'View Audit Log', 'agent-builder' ); ?>
			</a>
		</p>
	</div>
</div>

<?php
\Agentic\Inline_Assets::script(
	'run-task',
	array(
		'nonce' => wp_create_nonce( 'agentic_run_task' ),
		'agent' => $agentic_run_agent_id,
		'task'  => $agentic_run_task_id,
		'i18n'  => array(
			'completed'     => __( 'Task Completed Successfully', 'agent-builder' ),
			'failed'        => __( 'Task Failed', 'agent-builder' ),
			'requestFailed' => __( 'Request Failed', 'agent-builder' ),
			'seconds'       => __( 'seconds', 'agent-builder' ),
			'unknownError'  => __( 'Unknown error', 'agent-builder' ),
		),
	),
	'agenticRunTask'
);
?>
