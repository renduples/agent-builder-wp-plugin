<?php
/**
 * Deployment Tab: Event Listeners
 *
 * Displays all agent event listeners and provides a form for adding
 * user-defined triggers (hook → agent + prompt associations).
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

// -------------------------------------------------------------------------
// Data — agent instances, built-in listeners, user triggers.
// -------------------------------------------------------------------------
$agentic_registry  = \Agentic_Agent_Registry::get_instance();
$agentic_instances = $agentic_registry->get_all_instances();

$agentic_all_events = array();

// Built-in listeners defined in agent code.
foreach ( $agentic_instances as $agentic_agent ) {
	foreach ( $agentic_agent->get_event_listeners() as $agentic_listener ) {
		$agentic_all_events[] = array(
			'source'      => 'builtin',
			'agent_id'    => $agentic_agent->get_id(),
			'agent_name'  => $agentic_agent->get_name(),
			'agent_icon'  => $agentic_agent->get_icon(),
			'listener_id' => $agentic_listener['id'],
			'name'        => $agentic_listener['name'],
			'hook'        => $agentic_listener['hook'],
			'description' => $agentic_listener['description'] ?? '',
			'priority'    => $agentic_listener['priority'] ?? 10,
			'mode'        => ! empty( $agentic_listener['prompt'] ) ? 'autonomous' : 'direct',
		);
	}
}

// User-defined triggers — read from Deployments with WP option fallback.
$agentic_user_triggers = array();
if ( class_exists( '\Agentic\Deployments' ) ) {
	foreach ( \Agentic\Deployments::all( \Agentic\Deployments::TYPE_EVENT_LISTENER ) as $agentic_et_row ) {
		if ( ( $agentic_et_row['config']['source'] ?? '' ) !== 'user' ) {
			continue;
		}
		$agentic_user_triggers[] = array(
			'id'         => $agentic_et_row['config']['trigger_id'] ?? (string) $agentic_et_row['id'],
			'agent_slug' => $agentic_et_row['agent_slug'],
			'hook'       => $agentic_et_row['config']['hook'] ?? '',
			'name'       => $agentic_et_row['label'],
			'prompt'     => $agentic_et_row['config']['prompt'] ?? '',
			'priority'   => $agentic_et_row['config']['priority'] ?? 10,
		);
	}
}

if ( empty( $agentic_user_triggers ) ) {
	// Fall back to WP option if table not yet populated (pre-migration).
	$agentic_user_triggers = (array) get_option( 'agentic_user_event_triggers', array() );
}

foreach ( $agentic_user_triggers as $agentic_ut ) {
	$agentic_ut_agent     = $agentic_registry->get_agent_instance( $agentic_ut['agent_slug'] ?? '' );
	$agentic_all_events[] = array(
		'source'      => 'user',
		'trigger_id'  => $agentic_ut['id'],
		'agent_id'    => $agentic_ut['agent_slug'],
		'agent_name'  => $agentic_ut_agent ? $agentic_ut_agent->get_name() : $agentic_ut['agent_slug'],
		'agent_icon'  => $agentic_ut_agent ? $agentic_ut_agent->get_icon() : '🤖',
		'listener_id' => $agentic_ut['id'],
		'name'        => $agentic_ut['name'],
		'hook'        => $agentic_ut['hook'],
		'description' => $agentic_ut['prompt'] ?? '',
		'priority'    => $agentic_ut['priority'] ?? 10,
		'mode'        => 'autonomous',
	);
}

// Agent list for the form drop-down.
$agentic_agent_options = array();
foreach ( $agentic_instances as $agentic_agent ) {
	$agentic_agent_options[ $agentic_agent->get_id() ] = $agentic_agent->get_icon() . ' ' . $agentic_agent->get_name();
}

// Nonce for AJAX.
$agentic_triggers_nonce = wp_create_nonce( 'agentic_user_triggers' );
$agentic_ajax_url       = admin_url( 'admin-ajax.php' );

// Common WordPress hook groups for the drop-down.
$agentic_hook_groups = array(
	__( 'Content', 'agent-builder' )          => array(
		'save_post'          => 'save_post — post saved or updated',
		'publish_post'       => 'publish_post — post published',
		'draft_to_publish'   => 'draft_to_publish — draft published',
		'future_to_publish'  => 'future_to_publish — scheduled post goes live',
		'post_updated'       => 'post_updated — post updated',
		'before_delete_post' => 'before_delete_post — post permanently deleted',
		'wp_trash_post'      => 'wp_trash_post — post moved to trash',
		'untrash_post'       => 'untrash_post — post restored from trash',
	),
	__( 'Comments', 'agent-builder' )         => array(
		'wp_insert_comment' => 'wp_insert_comment — new comment inserted',
		'comment_post'      => 'comment_post — comment submitted',
		'edit_comment'      => 'edit_comment — comment edited',
		'delete_comment'    => 'delete_comment — comment deleted',
		'spam_comment'      => 'spam_comment — comment marked spam',
	),
	__( 'Users', 'agent-builder' )            => array(
		'user_register'  => 'user_register — new user registered',
		'profile_update' => 'profile_update — user profile updated',
		'wp_login'       => 'wp_login — user logged in',
		'wp_logout'      => 'wp_logout — user logged out',
		'delete_user'    => 'delete_user — user deleted',
		'password_reset' => 'password_reset — password reset',
	),
	__( 'Media', 'agent-builder' )            => array(
		'add_attachment'    => 'add_attachment — file uploaded',
		'edit_attachment'   => 'edit_attachment — attachment updated',
		'delete_attachment' => 'delete_attachment — attachment deleted',
	),
	__( 'WooCommerce', 'agent-builder' )      => array(
		'woocommerce_new_order'            => 'woocommerce_new_order — new order created',
		'woocommerce_order_status_changed' => 'woocommerce_order_status_changed — order status changed',
		'woocommerce_payment_complete'     => 'woocommerce_payment_complete — payment completed',
		'woocommerce_low_stock'            => 'woocommerce_low_stock — product low stock',
		'woocommerce_no_stock'             => 'woocommerce_no_stock — product out of stock',
	),
	__( 'Plugins & Themes', 'agent-builder' ) => array(
		'activated_plugin'          => 'activated_plugin — plugin activated',
		'deactivated_plugin'        => 'deactivated_plugin — plugin deactivated',
		'upgrader_process_complete' => 'upgrader_process_complete — plugin/theme updated',
		'switch_theme'              => 'switch_theme — theme switched',
	),
);
?>

<!-- =====================================================================
Add / Edit Trigger Form
===================================================================== -->
<div id="agentic-trigger-form-wrap" class="agentic-trigger-form">
	<h2 class="agentic-section-h2b"><?php esc_html_e( 'Add New Trigger', 'agent-builder' ); ?></h2>
	<p class="description"><?php esc_html_e( 'When the chosen WordPress event fires, the selected agent will be invoked asynchronously with the prompt you provide plus the event context.', 'agent-builder' ); ?></p>

	<input type="hidden" id="agentic-trigger-editing-id" value="">

	<table class="form-table agentic-table-mt-0">
		<tr>
			<th scope="row" class="agentic-trigger-form-th">
				<label for="agentic-trigger-agent"><?php esc_html_e( 'Agent', 'agent-builder' ); ?></label>
			</th>
			<td class="agentic-trigger-form-td">
				<select id="agentic-trigger-agent" class="agentic-select-260">
					<option value=""><?php esc_html_e( '— choose an agent —', 'agent-builder' ); ?></option>
					<?php foreach ( $agentic_agent_options as $agentic_slug => $agentic_label ) : ?>
					<option value="<?php echo esc_attr( $agentic_slug ); ?>"><?php echo esc_html( $agentic_label ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php if ( empty( $agentic_agent_options ) ) : ?>
					<p class="description agentic-text-danger"><?php esc_html_e( 'No agents installed. Install an agent first.', 'agent-builder' ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<tr>
			<th scope="row" class="agentic-trigger-form-th">
				<label for="agentic-trigger-hook"><?php esc_html_e( 'WordPress Event', 'agent-builder' ); ?></label>
			</th>
			<td class="agentic-trigger-form-td">
				<select id="agentic-trigger-hook" class="agentic-select-320" onchange="document.getElementById('agentic-trigger-custom').style.display=this.value==='_custom'?'block':'none';">
					<option value=""><?php esc_html_e( '— choose an event —', 'agent-builder' ); ?></option>
					<?php foreach ( $agentic_hook_groups as $agentic_group_label => $agentic_hooks ) : ?>
					<optgroup label="<?php echo esc_attr( $agentic_group_label ); ?>">
						<?php foreach ( $agentic_hooks as $agentic_hook_value => $agentic_hook_label ) : ?>
						<option value="<?php echo esc_attr( $agentic_hook_value ); ?>"><?php echo esc_html( $agentic_hook_label ); ?></option>
						<?php endforeach; ?>
					</optgroup>
					<?php endforeach; ?>
					<optgroup label="<?php esc_attr_e( 'Custom', 'agent-builder' ); ?>">
						<option value="_custom"><?php esc_html_e( 'Custom hook name…', 'agent-builder' ); ?></option>
					</optgroup>
				</select>
				<div id="agentic-trigger-custom" class="agentic-mt-6" style="display:none;">
					<input type="text" id="agentic-trigger-custom-hook" class="regular-text agentic-input-mono" placeholder="my_plugin_action">
					<p class="description"><?php esc_html_e( 'Enter any WordPress action hook name.', 'agent-builder' ); ?></p>
				</div>
			</td>
		</tr>
		<tr>
			<th scope="row" class="agentic-trigger-form-th">
				<label for="agentic-trigger-prompt"><?php esc_html_e( 'Prompt', 'agent-builder' ); ?></label>
			</th>
			<td class="agentic-trigger-form-td">
				<textarea id="agentic-trigger-prompt" rows="3" class="agentic-textarea-full" placeholder="<?php esc_attr_e( 'Describe what the agent should do when this event fires. Event context is appended automatically.', 'agent-builder' ); ?>"></textarea>
			</td>
		</tr>
		<tr>
			<th scope="row" class="agentic-trigger-form-th">
				<label for="agentic-trigger-name"><?php esc_html_e( 'Label', 'agent-builder' ); ?></label>
			</th>
			<td class="agentic-trigger-form-td">
				<input type="text" id="agentic-trigger-name" class="regular-text" placeholder="<?php esc_attr_e( 'Optional — auto-generated if blank', 'agent-builder' ); ?>">
			</td>
		</tr>
		<tr>
			<th scope="row" class="agentic-trigger-form-th">
				<label for="agentic-trigger-priority"><?php esc_html_e( 'Priority', 'agent-builder' ); ?></label>
			</th>
			<td class="agentic-trigger-form-td">
				<input type="number" id="agentic-trigger-priority" value="10" min="1" max="999" class="agentic-input-num-sm">
				<p class="description"><?php esc_html_e( 'Lower numbers run first. Default is 10.', 'agent-builder' ); ?></p>
			</td>
		</tr>
	</table>

	<div class="agentic-action-row">
		<button type="button" id="agentic-trigger-save" class="button button-primary"><?php esc_html_e( 'Add Trigger', 'agent-builder' ); ?></button>
		<button type="button" id="agentic-trigger-cancel" class="button" hidden><?php esc_html_e( 'Cancel', 'agent-builder' ); ?></button>
		<span id="agentic-trigger-status" class="agentic-trigger-status"></span>
	</div>
</div>

<!-- =====================================================================
	Active Triggers Table
	===================================================================== -->
<?php if ( empty( $agentic_all_events ) ) : ?>
<div class="notice notice-info inline">
	<p><?php esc_html_e( 'No event listeners active. Add a trigger above or activate agents that define built-in listeners.', 'agent-builder' ); ?></p>
</div>
<?php else : ?>
<div class="agentic-table-scroll">
<table class="widefat striped agentic-table-min-720">
	<thead>
		<tr>
			<th><?php esc_html_e( 'Agent', 'agent-builder' ); ?></th>
			<th><?php esc_html_e( 'Trigger / Label', 'agent-builder' ); ?></th>
			<th><?php esc_html_e( 'WordPress Hook', 'agent-builder' ); ?></th>
			<th class="agentic-col-60"><?php esc_html_e( 'Priority', 'agent-builder' ); ?></th>
			<th class="agentic-col-110"><?php esc_html_e( 'Mode', 'agent-builder' ); ?></th>
			<th class="agentic-col-90"><?php esc_html_e( 'Source', 'agent-builder' ); ?></th>
			<th class="agentic-col-80"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'agent-builder' ); ?></span></th>
		</tr>
	</thead>
	<tbody>
		<?php foreach ( $agentic_all_events as $agentic_event_row ) : ?>
		<tr id="agentic-trigger-row-<?php echo esc_attr( $agentic_event_row['trigger_id'] ?? '' ); ?>">
			<td>
				<span class="agentic-agent-icon"><?php echo esc_html( $agentic_event_row['agent_icon'] ); ?></span>
				<?php echo esc_html( $agentic_event_row['agent_name'] ); ?>
			</td>
			<td>
				<strong><?php echo esc_html( $agentic_event_row['name'] ); ?></strong>
				<?php if ( $agentic_event_row['description'] ) : ?>
					<br><small class="agentic-text-muted agentic-text-xxs"><?php echo esc_html( wp_trim_words( $agentic_event_row['description'], 12 ) ); ?></small>
				<?php endif; ?>
			</td>
			<td><code><?php echo esc_html( $agentic_event_row['hook'] ); ?></code></td>
			<td><?php echo esc_html( $agentic_event_row['priority'] ); ?></td>
			<td>
				<?php if ( 'autonomous' === $agentic_event_row['mode'] ) : ?>
					<span class="agentic-text-blue" title="<?php esc_attr_e( 'Queues an async AI task — does not block page load', 'agent-builder' ); ?>">🤖 <?php esc_html_e( 'AI Async', 'agent-builder' ); ?></span>
				<?php else : ?>
					<span class="agentic-text-muted" title="<?php esc_attr_e( 'Calls the PHP callback directly (synchronous)', 'agent-builder' ); ?>">⚙️ <?php esc_html_e( 'Direct', 'agent-builder' ); ?></span>
				<?php endif; ?>
			</td>
			<td>
				<?php if ( 'user' === $agentic_event_row['source'] ) : ?>
					<span class="agentic-badge-pill-blue"><?php esc_html_e( 'Custom', 'agent-builder' ); ?></span>
				<?php else : ?>
					<span class="agentic-badge-pill-grey"><?php esc_html_e( 'Built-in', 'agent-builder' ); ?></span>
				<?php endif; ?>
			</td>
			<td>
				<?php if ( 'user' === $agentic_event_row['source'] ) : ?>
				<button type="button"
					class="button button-small agentic-trigger-delete agentic-btn-danger-outline"
					data-id="<?php echo esc_attr( $agentic_event_row['trigger_id'] ); ?>">
					<?php esc_html_e( 'Remove', 'agent-builder' ); ?>
				</button>
				<?php endif; ?>
			</td>
		</tr>
		<?php endforeach; ?>
	</tbody>
</table>
</div>
<?php endif; ?>

<div class="agentic-info-note">
	<strong><?php esc_html_e( 'How it works:', 'agent-builder' ); ?></strong>
	<?php esc_html_e( 'When a WordPress event fires, the matched agent is queued as an async AI task — it never blocks the current page load. WordPress hook arguments are serialised into the prompt as context.', 'agent-builder' ); ?>
</div>

<!-- =====================================================================
	Inline JS
	===================================================================== -->
<script>
(function($){
	'use strict';

	const ajaxUrl = <?php echo wp_json_encode( $agentic_ajax_url ); ?>;
	const nonce   = <?php echo wp_json_encode( $agentic_triggers_nonce ); ?>;

	function getHook() {
		const sel = $('#agentic-trigger-hook').val();
		return sel === '_custom'
			? $('#agentic-trigger-custom-hook').val().trim()
			: sel;
	}

	function resetForm() {
		$('#agentic-trigger-editing-id').val('');
		$('#agentic-trigger-agent').val('');
		$('#agentic-trigger-hook').val('');
		$('#agentic-trigger-custom').hide();
		$('#agentic-trigger-custom-hook').val('');
		$('#agentic-trigger-prompt').val('');
		$('#agentic-trigger-name').val('');
		$('#agentic-trigger-priority').val('10');
		$('#agentic-trigger-save').text(<?php echo wp_json_encode( __( 'Add Trigger', 'agent-builder' ) ); ?>);
		$('#agentic-trigger-cancel').hide();
		$('#agentic-trigger-status').text('');
	}

	$('#agentic-trigger-cancel').on('click', resetForm);

	$('#agentic-trigger-save').on('click', function(){
		const $btn    = $(this);
		const $status = $('#agentic-trigger-status');
		const hook    = getHook();
		const agent   = $('#agentic-trigger-agent').val();

		if ( ! agent ) { $status.css('color','#d63638').text(<?php echo wp_json_encode( __( 'Please choose an agent.', 'agent-builder' ) ); ?>); return; }
		if ( ! hook )  { $status.css('color','#d63638').text(<?php echo wp_json_encode( __( 'Please choose or enter a hook name.', 'agent-builder' ) ); ?>); return; }

		$btn.prop('disabled', true).text(<?php echo wp_json_encode( __( 'Saving…', 'agent-builder' ) ); ?>);
		$status.text('');

		$.post(ajaxUrl, {
			action      : 'agentic_save_user_trigger',
			_ajax_nonce : nonce,
			trigger_id  : $('#agentic-trigger-editing-id').val(),
			agent_slug  : agent,
			hook        : hook,
			prompt      : $('#agentic-trigger-prompt').val(),
			name        : $('#agentic-trigger-name').val(),
			priority    : $('#agentic-trigger-priority').val(),
		})
		.done(function(resp){
			if (resp.success) {
				// Reload page to show new/updated row.
				window.location.reload();
			} else {
				$status.css('color','#d63638').text(resp.data || <?php echo wp_json_encode( __( 'Error saving trigger.', 'agent-builder' ) ); ?>);
				$btn.prop('disabled', false).text(<?php echo wp_json_encode( __( 'Add Trigger', 'agent-builder' ) ); ?>);
			}
		})
		.fail(function(){
			$status.css('color','#d63638').text(<?php echo wp_json_encode( __( 'Request failed.', 'agent-builder' ) ); ?>);
			$btn.prop('disabled', false).text(<?php echo wp_json_encode( __( 'Add Trigger', 'agent-builder' ) ); ?>);
		});
	});

	$(document).on('click', '.agentic-trigger-delete', async function(){
		if ( ! await agenticUI.confirm(<?php echo wp_json_encode( __( 'Remove this trigger?', 'agent-builder' ) ); ?>, { danger: true }) ) return;

		const $btn = $(this);
		const id   = $btn.data('id');
		$btn.prop('disabled', true);

		$.post(ajaxUrl, {
			action      : 'agentic_delete_user_trigger',
			_ajax_nonce : nonce,
			trigger_id  : id,
		})
		.done(function(resp){
			if (resp.success) {
				$('#agentic-trigger-row-' + id).fadeOut(300, function(){ $(this).remove(); });
			} else {
				$btn.prop('disabled', false);
				agenticUI.toast(resp.data || <?php echo wp_json_encode( __( 'Could not delete trigger.', 'agent-builder' ) ); ?>, 'error');
			}
		})
		.fail(function(){
			$btn.prop('disabled', false);
		});
	});

})(jQuery);
</script>
