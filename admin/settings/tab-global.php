<?php
/**
 * Settings — Global Chat Tab
 *
 * Global chat feature flags (theme lives under Interface).
 * Variables from parent settings.php scope:
 *   $agentic_cache_enabled, $agentic_cache_ttl, $agentic_cache_stats
 *
 * @package    Agent_Builder
 * @subpackage Admin/Settings
 * @since      2.10.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$agentic_ui_audio      = get_option( 'agent_builder_chat_audio', '1' );
$agentic_ui_tts        = get_option( 'agent_builder_chat_tts', '1' );
$agentic_ui_vision     = get_option( 'agent_builder_chat_vision', '1' );
$agentic_ui_costs      = get_option( 'agent_builder_chat_costs', '1' );
$agentic_ui_whitelabel = get_option( 'agent_builder_chat_whitelabel', '1' );
$agentic_ui_whatsapp   = get_option( 'agent_builder_show_whatsapp_cta', '0' );
?>
<p class="description">
	<?php
	printf(
		wp_kses(
			/* translators: %s: URL to Settings → Interface */
			__( 'Chat theme and appearance are managed under <a href="%s"><strong>Interface</strong></a>.', 'agent-builder' ),
			array(
				'a'      => array( 'href' => array() ),
				'strong' => array(),
			)
		),
		esc_url( admin_url( 'admin.php?page=agentic-settings&tab=interface' ) )
	);
	?>
</p>

<h2 class="agentic-settings-h2">Global Chat Settings</h2>
<p>Features globally available to users in the Agent chat interface. Override per agent <a href="<?php echo esc_url( admin_url( 'admin.php?page=agentic-settings&tab=agents' ) ); ?>">here</a>.</p>

<table class="form-table" role="presentation">
	<tr>
		<th scope="row">Voice Input</th>
		<td>
			<label>
				<input type="checkbox" name="agent_builder_chat_audio" value="1" <?php checked( $agentic_ui_audio, '1' ); ?> />
				Enable audio input (microphone button)
			</label>
			<p class="description">Allow users to send messages using their microphone. Uses compatible browsers built-in speech recognition.</p>
		</td>
	</tr>
	<tr>
		<th scope="row">Text-to-Speech</th>
		<td>
			<label>
				<input type="checkbox" name="agent_builder_chat_tts" value="1" <?php checked( $agentic_ui_tts, '1' ); ?> />
				Enable audio output (speaker button)
			</label>
			<p class="description">Allow user to listen to agent responses using neural TTS voices. Requires prepaid credits.</p>
		</td>
	</tr>
	<tr>
		<th scope="row">Vision</th>
		<td>
			<label>
				<input type="checkbox" name="agent_builder_chat_vision" value="1" <?php checked( $agentic_ui_vision, '1' ); ?> />
				Enable image uploads (paperclip button)
			</label>
			<p class="description">Allow users to attach images for the agent to analyze.</p>
		</td>
	</tr>
	<tr>
		<th scope="row">Response Cache</th>
		<td>
			<label>
				<input type="checkbox" name="agent_builder_response_cache_enabled" value="1" <?php checked( $agentic_cache_enabled ); ?> />
				Cache identical messages to avoid repeated LLM calls
			</label>
			<p class="description">Exact-match queries return cached responses. Saves tokens and improves response time.</p>
		</td>
	</tr>
	<tr>
		<th scope="row">Cache TTL</th>
		<td>
			<select name="agent_builder_response_cache_ttl" id="agent_builder_response_cache_ttl">
				<option value="900" <?php selected( $agentic_cache_ttl, 900 ); ?>>15 minutes</option>
				<option value="1800" <?php selected( $agentic_cache_ttl, 1800 ); ?>>30 minutes</option>
				<option value="3600" <?php selected( $agentic_cache_ttl, 3600 ); ?>>1 hour (Recommended)</option>
				<option value="7200" <?php selected( $agentic_cache_ttl, 7200 ); ?>>2 hours</option>
				<option value="21600" <?php selected( $agentic_cache_ttl, 21600 ); ?>>6 hours</option>
				<option value="86400" <?php selected( $agentic_cache_ttl, 86400 ); ?>>24 hours</option>
			</select>
			<p class="description">How long to keep cached responses before they expire.</p>
		</td>
	</tr>
	<tr>
		<th scope="row">Clear Cache</th>
		<td>
			<label>
				<input type="checkbox" name="agentic_clear_cache" value="1" />
				Clear all cached responses on save
			</label>
			<p class="description">
				<strong>Cached entries:</strong> <?php echo esc_html( $agentic_cache_stats['entry_count'] ); ?> —
				<strong>Status:</strong>
				<?php
				if ( $agentic_cache_stats['enabled'] ) {
					echo '<span class="agentic-status-active">Active</span>';
				} else {
					echo '<span class="agentic-text-danger">Disabled</span>';
				}
				?>
			</p>
		</td>
	</tr>
	<tr>
		<th scope="row">Branding</th>
		<td>
			<label>
				<input type="checkbox" name="agent_builder_chat_whitelabel" id="agent_builder_chat_whitelabel" value="1" <?php checked( $agentic_ui_whitelabel, '1' ); ?> />
				<?php esc_html_e( 'Hide “Powered by Agent Builder” branding (default on — credits stay off the site unless you uncheck this)', 'agent-builder' ); ?>
			</label>
			<p class="description"><?php esc_html_e( 'When unchecked, a small credit line appears in the chat footer. WordPress.org requires this to stay hidden unless you opt in.', 'agent-builder' ); ?></p>
			<label class="agentic-mt-8" style="display:block;">
				<input type="checkbox" name="agent_builder_show_whatsapp_cta" id="agent_builder_show_whatsapp_cta" value="1" <?php checked( $agentic_ui_whatsapp, '1' ); ?> />
				<?php esc_html_e( 'Show the WhatsApp Pro promo in admin chat', 'agent-builder' ); ?>
			</label>
		</td>
	</tr>
</table>

<p class="agentic-mt-8"><a href="#" class="agentic-reset-defaults" data-section="styles_chat">Reset chat settings to defaults</a></p>

<!-- Item 3: Tool Reliability for Weaker Models -->
<div class="agentic-card agentic-mt-24">
	<h2>Tool Reliability for Weaker Models</h2>
	<p class="description">These settings improve tool calling reliability when using smaller or local models (Ollama, Gemma, Phi, etc.).</p>

	<table class="form-table">
		<tr>
			<th scope="row">Enhanced Tool Guidance</th>
			<td>
				<label>
					<input type="checkbox" name="agent_builder_enable_weak_model_tool_guidance" value="1" <?php checked( $agentic_weak_guidance, '1' ); ?> />
					Enable extra guidance + examples for weaker models
				</label>
				<p class="description">When enabled, agents using smaller models receive additional instructions to improve tool call accuracy and error recovery.</p>
			</td>
		</tr>
		<tr>
			<th scope="row">Maximum Tool Retries</th>
			<td>
				<input type="number" name="agent_builder_max_tool_retries" min="1" max="10" step="1" value="<?php echo esc_attr( $agentic_max_retries ); ?>" style="width: 80px;" />
				<p class="description">How many times the system will automatically ask a weaker model to retry a failed tool call with corrected arguments.</p>
			</td>
		</tr>
	</table>
</div>
