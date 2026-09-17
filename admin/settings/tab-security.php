<?php
/**
 * Settings — Security Tab
 *
 * Variables from parent settings.php scope:
 *   $agentic_agent_mode_val, $agent_builder_security_enabled,
 *   $agent_builder_turnstile_site_key, $agent_builder_turnstile_secret_key,
 *   $agentic_turnstile_require_anon, $agent_builder_turnstile_require_all,
 *   $agent_builder_ip_anonymize, $agent_builder_retention_conversations,
 *   $agent_builder_retention_audit_log, $agent_builder_chat_consent_enabled,
 *   $agent_builder_chat_consent_text
 *
 * @package    Agent_Builder
 * @subpackage Admin/Settings
 * @since      2.10.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;
?>
<!-- Agent Mode -->
<h3 class="agentic-settings-section-h3">Agent Mode</h3>
<p>Controls whether AI agents can execute actions autonomously and whether they have access to tools and abilities.</p>
<table class="form-table">
	<tr>
		<th scope="row"><label for="agent_builder_agent_mode">Security Mode</label></th>
		<td>
			<select name="agent_builder_agent_mode" id="agent_builder_agent_mode">
				<option value="disabled" <?php selected( $agentic_agent_mode_val, 'disabled' ); ?>>Disabled</option>
				<option value="supervised" <?php selected( $agentic_agent_mode_val, 'supervised' ); ?>>Supervised</option>
				<option value="autonomous" <?php selected( $agentic_agent_mode_val, 'autonomous' ); ?>>Autonomous</option>
			</select>
			<span id="agentic-mode-status" class="agentic-ml-8 agentic-text-sm"></span>
			<p class="description" id="agentic-mode-description"></p>
			<script>
			(function(){
				var sel = document.getElementById('agent_builder_agent_mode');
				var status = document.getElementById('agentic-mode-status');
				var desc = document.getElementById('agentic-mode-description');
				var descriptions = {
					disabled: 'Agents can chat with users but cannot execute any actions on your site. Tools and abilities are completely disabled.',
					supervised: 'Agents propose actions which are queued for your review. Nothing is modified until you approve it. This is the recommended setting for most sites.',
					autonomous: 'Agents execute actions immediately without waiting for approval. Supervised is the recommended global setting — override Security Mode for Agents you trust <a href="<?php echo esc_url( admin_url( 'admin.php?page=agentic-settings&tab=agents' ) ); ?>">here</a>.'
				};
				function updateDesc() { desc.innerHTML = descriptions[sel.value] || ''; }
				updateDesc();
				if (!sel) return;
				sel.addEventListener('change', function(){
					updateDesc();
					status.textContent = 'Saving…';
					status.style.color = '#787c82';
					var fd = new FormData();
					fd.append('action', 'agentic_save_agent_mode');
					fd.append('nonce', <?php echo wp_json_encode( wp_create_nonce( 'agentic_save_agent_mode' ) ); ?>);
					fd.append('mode', sel.value);
					fetch(<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, {
						method: 'POST', body: fd, credentials: 'same-origin'
					}).then(function(r){ return r.json(); }).then(function(res){
						if (res.success) {
							status.textContent = 'Saved.';
							status.style.color = '#00a32a';
						} else {
							status.textContent = res.data || 'Error saving.';
							status.style.color = '#b32d2e';
						}
						setTimeout(function(){ status.textContent = ''; }, 3000);
					}).catch(function(){
						status.textContent = 'Network error.';
						status.style.color = '#b32d2e';
					});
				});
			})();
			</script>
		</td>
	</tr>
</table>

<!-- Message Scanning -->
<h3 class="agentic-settings-section-h3">Message Scanning</h3>
<table class="form-table">
	<tr>
		<th scope="row">
			<label for="agent_builder_security_enabled">Enable Security Filter</label>
		</th>
		<td>
			<label>
				<input 
					type="checkbox" 
					name="agent_builder_security_enabled" 
					id="agent_builder_security_enabled" 
					value="1"
					<?php checked( $agent_builder_security_enabled ); ?>
				/>
				Scan messages for prompt injection and malicious content
			</label>
			<p class="description">
				Blocks common injection patterns and flags PII. Adds &lt;1ms overhead. Rate limiting and usage quotas are configured in the <a href="?page=agentic-settings&tab=users">Users tab</a>.
			</p>
		</td>
	</tr>
</table>

<!-- Cloudflare Turnstile -->
<h3 class="agentic-settings-section-h3">
	Cloudflare Turnstile
	<span class="agentic-section-subtitle">Bot Protection</span>
</h3>
<p class="agentic-text-muted agentic-mt-n4">
	Cloudflare Turnstile provides invisible bot verification without traditional CAPTCHAs.
	<?php // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Plain link to Cloudflare's own dashboard, not resource loading. ?>
	<a href="https://dash.cloudflare.com/?to=/:account/turnstile" target="_blank">Get your keys →</a>
</p>
<table class="form-table">
	<tr>
		<th scope="row">
			<label for="agent_builder_turnstile_site_key">Site Key</label>
		</th>
		<td>
			<input 
				type="text" 
				name="agent_builder_turnstile_site_key" 
				id="agent_builder_turnstile_site_key" 
				value="<?php echo esc_attr( $agent_builder_turnstile_site_key ); ?>" 
				class="regular-text"
				placeholder="0x4AAAAAAA..."
			/>
		</td>
	</tr>

	<tr>
		<th scope="row">
			<label for="agent_builder_turnstile_secret_key">Secret Key</label>
		</th>
		<td>
			<input 
				type="password" 
				name="agent_builder_turnstile_secret_key" 
				id="agent_builder_turnstile_secret_key" 
				value="<?php echo esc_attr( $agent_builder_turnstile_secret_key ); ?>" 
				class="regular-text"
				placeholder="0x4AAAAAAA..."
				autocomplete="off"
			/>
		</td>
	</tr>

	<tr>
		<th scope="row">Require Turnstile For</th>
		<td>
			<fieldset>
				<label class="agentic-label-block agentic-mb-6">
					<input 
						type="checkbox" 
						name="agent_builder_turnstile_require_anonymous" 
						value="1"
						<?php checked( $agentic_turnstile_require_anon ); ?>
					/>
					Anonymous users (recommended)
				</label>
				<label class="agentic-label-block">
					<input 
						type="checkbox" 
						name="agent_builder_turnstile_require_all" 
						value="1"
						<?php checked( $agent_builder_turnstile_require_all ); ?>
					/>
					All users (including logged-in)
				</label>
				<p class="description">
					When keys are configured and a box is checked, the Turnstile widget verifies the user before their first chat message.
				</p>
			</fieldset>
		</td>
	</tr>
</table>

	<?php
	$agentic_turnstile_configured = ! empty( $agent_builder_turnstile_site_key ) && ! empty( $agent_builder_turnstile_secret_key );
	?>

<div style="margin-top: 12px; padding: 12px 16px; border-radius: 4px; <?php echo esc_attr( $agentic_turnstile_configured ? 'background: #edf7ed; border-left: 4px solid #22c55e;' : 'background: #fef3cd; border-left: 4px solid #f0ad4e;' ); ?>">
	<?php if ( $agentic_turnstile_configured ) : ?>
		<strong class="agentic-text-success">✓ Turnstile is configured.</strong>
		<span class="agentic-text-success-dk">Bot verification is active for <?php echo esc_html( $agent_builder_turnstile_require_all ? 'all users' : 'anonymous users' ); ?>.</span>
	<?php else : ?>
		<strong class="agentic-text-amber-warn">⚠ Turnstile is not configured.</strong>
		<?php // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Plain link to Cloudflare's own dashboard, not resource loading. ?>
		<span class="agentic-text-amber-warn">Public-facing chats are not protected against bots. <a href="https://dash.cloudflare.com/?to=/:account/turnstile" target="_blank">Set up Turnstile</a></span>
	<?php endif; ?>
</div>

<!-- Server-Level Security Guide Link -->
<div class="agentic-callout-blue-flex" data-cont="gap: 12px;">
	<span class="agentic-text-xl">📖</span>
	<div>
		<strong class="agentic-text-sm">Server-level protection (Cloudflare, Nginx, Apache)</strong>
		<p class="agentic-mt-4 agentic-text-muted agentic-text-xs">
			For maximum security, add rate limiting at the edge — before requests reach WordPress. Our comprehensive guide covers Cloudflare WAF, Turnstile setup, Nginx rate limiting, Apache mod_evasive, and recommended settings for every scenario.
		</p>
		<p class="agentic-mt-8 agentic-mb-0">
			<a href="https://agentic-plugin.com/important-security-settings/" target="_blank" class="agentic-text-xs agentic-fw600" class="agentic-no-underline">Read the Important Security Settings guide →</a>
		</p>
	</div>
</div>

<!-- GDPR / Data & Privacy -->
<h3 class="agentic-settings-section-h3">Data &amp; Privacy (GDPR)</h3>
<p class="agentic-text-muted agentic-mb-16">
	Control how user data is stored and processed. These settings help you meet GDPR and similar privacy regulation requirements.
	Plugin data (chat history and security log) is also integrated with WordPress's built-in
	<a href="<?php echo esc_url( admin_url( 'export-personal-data.php' ) ); ?>">Personal Data Export</a>
	and <a href="<?php echo esc_url( admin_url( 'erase-personal-data.php' ) ); ?>">Personal Data Erasure</a> tools.
</p>

<table class="form-table">
	<tr>
		<th scope="row"><label for="agent_builder_ip_anonymize">IP Anonymisation</label></th>
		<td>
			<label>
				<input type="checkbox" name="agent_builder_ip_anonymize" id="agent_builder_ip_anonymize" value="1" <?php checked( $agent_builder_ip_anonymize ); ?> />
				Hash IP addresses before storing in the security log
			</label>
			<p class="description">
				Stores a SHA-256 hash of the IP instead of the raw address. Rate-limit keys already use hashed IPs — this extends the same protection to the stored log. Recommended for EU visitors.
			</p>
		</td>
	</tr>

	<tr>
		<th scope="row"><label for="agent_builder_retention_conversations">Chat History Retention</label></th>
		<td>
			<input type="number" name="agent_builder_retention_conversations" id="agent_builder_retention_conversations"
				value="<?php echo esc_attr( $agent_builder_retention_conversations ); ?>" min="0" step="1" class="small-text" />
			days &nbsp;<span class="agentic-text-muted">(0 = keep indefinitely)</span>
			<p class="description">
				Conversation records older than this many days are deleted automatically. Includes all messages stored in the <code><?php echo esc_html( $wpdb->prefix ); ?>agent_builder_conversations</code> table.
			</p>
		</td>
	</tr>

	<tr>
		<th scope="row"><label for="agent_builder_local_memory_enabled">Local Memory</label></th>
		<td>
			<label>
				<input type="checkbox" name="agent_builder_local_memory_enabled" id="agent_builder_local_memory_enabled" value="1" <?php checked( $agent_builder_local_memory_enabled ); ?> />
				Let agents remember recent conversations with each user
			</label>
			<p class="description">
				When enabled, a short, relevant excerpt of each user's previous messages is stored locally in the <code><?php echo esc_html( $wpdb->prefix ); ?>agent_builder_memory</code> table and added to the agent's context on later turns, so it can recall prior details. This data never leaves your server. Disabled by default; cleared by the Chat History Retention setting and on user-data erasure.
			</p>
		</td>
	</tr>

	<tr>
		<th scope="row"><label for="agent_builder_retention_audit_log">Audit Log Retention</label></th>
		<td>
			<input type="number" name="agent_builder_retention_audit_log" id="agent_builder_retention_audit_log"
				value="<?php echo esc_attr( $agent_builder_retention_audit_log ); ?>" min="0" step="1" class="small-text" />
			days &nbsp;<span class="agentic-text-muted">(0 = keep indefinitely)</span>
			<p class="description">
				Audit and security log entries older than this are deleted automatically. Shorter retention reduces personal-data exposure; 90 days is a common GDPR-friendly value.
			</p>
		</td>
	</tr>

	<tr>
		<th scope="row"><label for="agent_builder_chat_consent_enabled">Consent Notice</label></th>
		<td>
			<label>
				<input type="checkbox" name="agent_builder_chat_consent_enabled" id="agent_builder_chat_consent_enabled" value="1" <?php checked( $agent_builder_chat_consent_enabled ); ?> />
				Show a consent notice above the chat input on first load
			</label>
			<p class="description">
				Displays a small, dismissible notice before the user sends their first message. Required for GDPR Article 13 transparency in many EU contexts.
			</p>
		</td>
	</tr>

	<tr>
		<th scope="row"><label for="agent_builder_allow_platform_sync"><?php esc_html_e( 'Model catalog sync', 'agent-builder' ); ?></label></th>
		<td>
			<label>
				<input type="checkbox" name="agent_builder_allow_platform_sync" id="agent_builder_allow_platform_sync" value="1" <?php checked( $agent_builder_allow_platform_sync ); ?> />
				<?php esc_html_e( 'Refresh the LLM model catalog from agentic-plugin.com once a day', 'agent-builder' ); ?>
			</label>
			<p class="description">
				<?php esc_html_e( 'Off by default. Bring-your-own-key providers work without this. Enable only if you want curated model names and list prices fetched from Agentic.', 'agent-builder' ); ?>
			</p>
		</td>
	</tr>

	<tr id="agentic_consent_text_row" <?php echo $agent_builder_chat_consent_enabled ? '' : 'style="display:none;"'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML attribute. ?>>
		<th scope="row"><label for="agent_builder_chat_consent_text">Consent Notice Text</label></th>
		<td>
			<textarea name="agent_builder_chat_consent_text" id="agent_builder_chat_consent_text" rows="3" class="large-text"><?php echo esc_textarea( $agent_builder_chat_consent_text ); ?></textarea>
			<p class="description">
				Plain text only. Shown as a small banner above the message input. Keep it brief — one or two sentences.
			</p>
		</td>
	</tr>
</table>

<script>
(function() {
	var cb   = document.getElementById('agent_builder_chat_consent_enabled');
	var row  = document.getElementById('agentic_consent_text_row');
	if (!cb || !row) return;
	cb.addEventListener('change', function() { row.style.display = this.checked ? '' : 'none'; });
})();
</script>

<p class="agentic-mt-8"><a href="#" class="agentic-reset-defaults" data-section="security">Reset security settings to defaults</a></p>

<!-- Tool Permission Grants -->
<h3 class="agentic-settings-section-h3">Tool Permission Grants</h3>
<p class="agentic-text-muted">
	Tools you have chosen to <strong>Always Allow</strong> in the chat will bypass the confirmation prompt. Revoke any grant here to restore the default supervised behaviour.
</p>
<?php
$agentic_current_user_id    = get_current_user_id();
$agentic_always_grants      = get_user_meta( $agentic_current_user_id, 'agentic_tool_grants_always', true );
$agentic_always_grants      = is_array( $agentic_always_grants ) ? $agentic_always_grants : array();
$agentic_grants_nonce       = wp_create_nonce( 'wp_rest' );
$agentic_grants_revoke_base = rest_url( 'agentic/v1/tool-grants/' );
?>
<?php if ( empty( $agentic_always_grants ) ) : ?>
	<p class="description">No always-allow grants have been set. When you click <em>Always Allow</em> on a tool proposal, it will appear here.</p>
<?php else : ?>
	<table class="widefat striped" id="agentic-tool-grants-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Tool', 'agent-builder' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'agent-builder' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( $agentic_always_grants as $agentic_grant_tool ) : ?>
			<tr id="agentic-grant-row-<?php echo esc_attr( $agentic_grant_tool ); ?>">
				<td><code><?php echo esc_html( $agentic_grant_tool ); ?></code></td>
				<td>
					<button
						type="button"
						class="button button-small agentic-revoke-grant"
						data-tool="<?php echo esc_attr( $agentic_grant_tool ); ?>"
						data-nonce="<?php echo esc_attr( $agentic_grants_nonce ); ?>"
						data-url="<?php echo esc_url( $agentic_grants_revoke_base . rawurlencode( $agentic_grant_tool ) ); ?>"
					>
						<?php esc_html_e( 'Revoke', 'agent-builder' ); ?>
					</button>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<script>
	(function() {
		document.querySelectorAll('.agentic-revoke-grant').forEach(function(btn) {
			btn.addEventListener('click', function() {
				var tool   = btn.dataset.tool;
				var url    = btn.dataset.url;
				var nonce  = btn.dataset.nonce;
				btn.disabled = true;
				btn.textContent = 'Revoking…';
				fetch(url, {
					method: 'DELETE',
					headers: { 'X-WP-Nonce': nonce },
					credentials: 'same-origin'
				}).then(function(r) { return r.json(); }).then(function(res) {
					if (res.success) {
						var row = document.getElementById('agentic-grant-row-' + tool);
						if (row) row.remove();
						var tbody = document.querySelector('#agentic-tool-grants-table tbody');
						if (tbody && tbody.children.length === 0) {
							document.getElementById('agentic-tool-grants-table').outerHTML =
								'<p class="description">No always-allow grants have been set.</p>';
						}
					} else {
						btn.disabled = false;
						btn.textContent = 'Revoke';
						agenticUI.toast('Failed to revoke grant.', 'error');
					}
				}).catch(function() {
					btn.disabled = false;
					btn.textContent = 'Revoke';
					agenticUI.toast('Network error. Please try again.', 'error');
				});
			});
		});
	})();
	</script>
<?php endif; ?>
