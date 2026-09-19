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
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=agentic-audit' ) ); ?>" class="button agentic-ml-8">
				<?php esc_html_e( 'View Audit Log', 'agent-builder' ); ?>
			</a>
		</p>
	</div>
</div>

<script>
(function () {
	'use strict';

	/**
	 * Lightweight Markdown-to-HTML renderer.
	 * Handles: ### h3, #### h4, **bold**, `code`, ordered/unordered lists, paragraphs.
	 * Escapes HTML first to prevent XSS, then applies formatting.
	 */
	function renderMarkdown(text) {
		// Convert literal \n sequences to actual newlines.
		text = text.replace(/\\n/g, '\n');

		// Escape HTML entities.
		var esc = text
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');

		var lines = esc.split('\n');
		var html = [];
		var inUl = false;
		var inOl = false;

		function closeLists() {
			if (inUl) { html.push('</ul>'); inUl = false; }
			if (inOl) { html.push('</ol>'); inOl = false; }
		}

		function inlineFormat(s) {
			// Bold: **text**
			s = s.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
			// Inline code: `text`
			s = s.replace(/`([^`]+)`/g, '<code>$1</code>');
			// Severity color hints.
			s = s.replace(/<strong>(Critical[^<]*)<\/strong>/gi, '<strong class="agentic-severity-critical">$1</strong>');
			s = s.replace(/<strong>(High[^<]*)<\/strong>/gi, '<strong class="agentic-severity-high">$1</strong>');
			s = s.replace(/<strong>(Medium[^<]*)<\/strong>/gi, '<strong class="agentic-severity-medium">$1</strong>');
			s = s.replace(/<strong>(Low[^<]*)<\/strong>/gi, '<strong class="agentic-severity-low">$1</strong>');
			return s;
		}

		for (var i = 0; i < lines.length; i++) {
			var line = lines[i];

			// Headers.
			var h4 = line.match(/^####\s+(.+)$/);
			if (h4) { closeLists(); html.push('<h4>' + inlineFormat(h4[1]) + '</h4>'); continue; }

			var h3 = line.match(/^###\s+(.+)$/);
			if (h3) { closeLists(); html.push('<h3>' + inlineFormat(h3[1]) + '</h3>'); continue; }

			// Unordered list: - item or * item.
			var ul = line.match(/^\s*[-*]\s+(.+)$/);
			if (ul) {
				if (inOl) { html.push('</ol>'); inOl = false; }
				if (!inUl) { html.push('<ul>'); inUl = true; }
				html.push('<li>' + inlineFormat(ul[1]) + '</li>');
				continue;
			}

			// Ordered list: 1. item.
			var ol = line.match(/^\s*\d+\.\s+(.+)$/);
			if (ol) {
				if (inUl) { html.push('</ul>'); inUl = false; }
				if (!inOl) { html.push('<ol>'); inOl = true; }
				html.push('<li>' + inlineFormat(ol[1]) + '</li>');
				continue;
			}

			// Blank line.
			if (line.trim() === '') {
				closeLists();
				continue;
			}

			// Regular paragraph.
			closeLists();
			html.push('<p>' + inlineFormat(line) + '</p>');
		}

		closeLists();
		return html.join('\n');
	}

	var btn      = document.getElementById('agentic-execute-btn');
	var preRun   = document.getElementById('agentic-pre-run');
	var running  = document.getElementById('agentic-running');
	var timer    = document.getElementById('agentic-timer');
	var results  = document.getElementById('agentic-results');

	btn.addEventListener('click', function () {
		// Hide button, show spinner + timer.
		preRun.style.display  = 'none';
		running.style.display = 'block';

		// Live timer.
		var start = Date.now();
		var tick = setInterval(function () {
			timer.textContent = Math.floor((Date.now() - start) / 1000) + 's';
		}, 250);

		// AJAX request.
		var body = new FormData();
		body.append('action', 'agentic_run_task');
		body.append('_ajax_nonce', <?php echo wp_json_encode( wp_create_nonce( 'agentic_run_task' ) ); ?>);
		body.append('agent', <?php echo wp_json_encode( $agentic_run_agent_id ); ?>);
		body.append('task', <?php echo wp_json_encode( $agentic_run_task_id ); ?>);

		fetch(ajaxurl, { method: 'POST', body: body, credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (json) {
				clearInterval(tick);
				running.style.display = 'none';
				results.style.display = 'block';

				var notice = document.getElementById('agentic-result-notice');
				var dur    = document.getElementById('agentic-result-duration');
				var outW   = document.getElementById('agentic-result-output-wrap');
				var outT   = document.getElementById('agentic-result-output');

				if (json.success) {
					notice.innerHTML = '<div class="notice notice-success agentic-js-notice-success">' +
						'<p class="agentic-js-notice-success p"><strong>&#9989; <?php echo esc_js( __( 'Task Completed Successfully', 'agent-builder' ) ); ?></strong></p></div>';
					dur.textContent = json.data.duration + ' <?php echo esc_js( __( 'seconds', 'agent-builder' ) ); ?>';
					if (json.data.response) {
						outT.innerHTML = renderMarkdown(json.data.response);
						outW.style.display = 'block';
					}
				} else {
					var msg = (json.data && json.data.message) ? json.data.message : '<?php echo esc_js( __( 'Unknown error', 'agent-builder' ) ); ?>';
					notice.innerHTML = '<div class="notice notice-error agentic-js-notice-error">' +
						'<p class="agentic-js-notice-error p"><strong>&#10060; <?php echo esc_js( __( 'Task Failed', 'agent-builder' ) ); ?></strong></p>' +
						'<p class="agentic-js-notice-error p+p">' + msg.replace(/</g, '&lt;') + '</p></div>';
					if (json.data && json.data.duration) {
						dur.textContent = json.data.duration + ' <?php echo esc_js( __( 'seconds', 'agent-builder' ) ); ?>';
					} else {
						dur.textContent = Math.floor((Date.now() - start) / 1000) + ' <?php echo esc_js( __( 'seconds', 'agent-builder' ) ); ?>';
					}
				}
			})
			.catch(function (err) {
				clearInterval(tick);
				running.style.display = 'none';
				results.style.display = 'block';
				document.getElementById('agentic-result-notice').innerHTML =
					'<div class="notice notice-error agentic-js-notice-error">' +
					'<p class="agentic-js-notice-error p"><strong>&#10060; <?php echo esc_js( __( 'Request Failed', 'agent-builder' ) ); ?></strong></p>' +
					'<p class="agentic-js-notice-error p+p">' + (err.message || '').replace(/</g, '&lt;') + '</p></div>';
				document.getElementById('agentic-result-duration').textContent =
					Math.floor((Date.now() - start) / 1000) + ' <?php echo esc_js( __( 'seconds', 'agent-builder' ) ); ?>';
			});
	});
})();
</script>
