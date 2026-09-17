<?php
/**
 * Settings — Instructions Tab
 *
 * Per-agent persona configuration: welcome message, response style,
 * suggested prompts, persona notes, and knowledge textarea.
 * No parent-scope variables required.
 *
 * @package    Agent_Builder
 * @subpackage Admin/Settings
 * @since      2.10.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$agentic_all_agents = array_intersect_key(
	\Agentic_Agent_Registry::get_instance()->get_installed_agents(),
	array_flip( \Agentic_Agent_Registry::get_instance()->get_active_agents() )
);
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$agentic_pa_selected = isset( $_GET['edit_persona'] ) ? sanitize_key( $_GET['edit_persona'] ) : '';
if ( ! $agentic_pa_selected && ! empty( $agentic_all_agents ) ) {
	$agentic_pa_selected = array_key_first( $agentic_all_agents );
}
?>
<p class="agentic-settings-lead"><?php esc_html_e( 'Customise how each agent greets visitors and add context that shapes its personality. Instructions are appended to the agent’s system prompt — they do not replace it.', 'agent-builder' ); ?></p>

<?php if ( empty( $agentic_all_agents ) ) : ?>
<p><em>No agents are currently installed.</em></p>
<?php else : ?>

<table class="form-table agentic-table-mt-0">
	<tr>
		<th scope="row" class="agentic-th-180">
			<label for="agentic-persona-agent-select">Select Agent</label>
		</th>
		<td>
			<select id="agentic-persona-agent-select" class="agentic-select-min-280">
				<?php foreach ( $agentic_all_agents as $agentic_pa_slug => $agentic_persona_agent ) : ?>
				<option value="<?php echo esc_attr( $agentic_pa_slug ); ?>" <?php selected( $agentic_pa_selected, $agentic_pa_slug ); ?>>
					<?php echo esc_html( $agentic_persona_agent['name'] ?? $agentic_pa_slug ); ?>
				</option>
				<?php endforeach; ?>
			</select>
		</td>
	</tr>
</table>
<script>
document.getElementById('agentic-persona-agent-select').addEventListener('change', function() {
	var url = new URL(window.location.href);
	url.searchParams.set('edit_persona', this.value);
	window.location.href = url.toString();
});
</script>

	<?php
	$agentic_pa_agent = $agentic_all_agents[ $agentic_pa_selected ] ?? null;
	if ( $agentic_pa_agent ) :
		$agentic_pa_name     = $agentic_pa_agent['name'] ?? $agentic_pa_selected;
		$agentic_pa_desc     = $agentic_pa_agent['description'] ?? '';
		$agentic_pa_notes    = \Agentic\Agent_Settings::get( $agentic_pa_selected, 'persona_notes' );
		$agentic_pa_override = \Agentic\Agent_Settings::get( $agentic_pa_selected, 'persona_welcome_message' );
		$agentic_pa_welcome  = '' !== trim( $agentic_pa_override ) ? $agentic_pa_override : '';
		$agentic_pa_inst     = \Agentic_Agent_Registry::get_instance()->get_all_instances()[ $agentic_pa_selected ] ?? null;
		// Only needed as placeholder text when no saved value exists.
		$agentic_pa_default = '';
		if ( '' === $agentic_pa_welcome ) {
			$agentic_pa_default = $agentic_pa_inst
				? $agentic_pa_inst->get_welcome_message()
				: sprintf( "Hello! I'm %s. %s\n\nHow can I help you today?", $agentic_pa_name, $agentic_pa_desc );
		}
		?>
<div class="agentic-persona-card">
	<h3 class="agentic-persona-title"><?php echo esc_html( $agentic_pa_name ); ?></h3>
	<p class="agentic-persona-desc"><?php echo esc_html( $agentic_pa_desc ); ?></p>
	<table class="form-table agentic-table-mt-0">
		<tr>
			<th scope="row" class="agentic-th-180">
				<label for="agentic_persona_welcome_<?php echo esc_attr( $agentic_pa_selected ); ?>">Welcome Message</label>
			</th>
			<td>
				<textarea
					name="agentic_agent_personas[<?php echo esc_attr( $agentic_pa_selected ); ?>][welcome_message]"
					id="agentic_persona_welcome_<?php echo esc_attr( $agentic_pa_selected ); ?>"
					rows="3"
					class="large-text"
					placeholder="<?php echo esc_attr( $agentic_pa_default ); ?>"
				><?php echo esc_textarea( $agentic_pa_welcome ); ?></textarea>
				<p class="description">This is the opening message currently shown when a chat starts. Edit it to customise. Clear the field and save to restore the agent&rsquo;s built-in default.</p>
			</td>
		</tr>
		<tr>
			<th scope="row" class="agentic-th-180">
				<label for="agentic_persona_style_<?php echo esc_attr( $agentic_pa_selected ); ?>">Response Style</label>
			</th>
			<td>
				<?php $agentic_pa_style = \Agentic\Agent_Settings::get( $agentic_pa_selected, 'persona_response_style' ); ?>
				<select name="agentic_agent_personas[<?php echo esc_attr( $agentic_pa_selected ); ?>][response_style]" id="agentic_persona_style_<?php echo esc_attr( $agentic_pa_selected ); ?>">
					<option value="" <?php selected( $agentic_pa_style, '' ); ?>>Balanced (default)</option>
					<option value="concise" <?php selected( $agentic_pa_style, 'concise' ); ?>>Concise</option>
					<option value="detailed" <?php selected( $agentic_pa_style, 'detailed' ); ?>>Detailed</option>
					<option value="technical" <?php selected( $agentic_pa_style, 'technical' ); ?>>Technical</option>
					<option value="friendly" <?php selected( $agentic_pa_style, 'friendly' ); ?>>Friendly</option>
				</select>
				<p class="description">Controls the agent&rsquo;s tone and verbosity. This is appended to the system prompt automatically.</p>
			</td>
		</tr>
		<tr>
			<th scope="row" class="agentic-th-180">
				<label>Suggested Prompts</label>
			</th>
			<td>
				<?php
				$agentic_pa_prompts_raw   = \Agentic\Agent_Settings::get( $agentic_pa_selected, 'persona_suggested_prompts' );
				$agentic_pa_prompts_saved = ! empty( $agentic_pa_prompts_raw ) ? ( json_decode( $agentic_pa_prompts_raw, true ) !== null ? json_decode( $agentic_pa_prompts_raw, true ) : array() ) : array();
				$agentic_pa_has_saved     = ! empty( $agentic_pa_prompts_saved );
				$agentic_pa_placeholders  = array( 'Prompt 1', 'Prompt 2', 'Prompt 3', 'Prompt 4' );
				if ( ! $agentic_pa_has_saved ) {
					$agentic_pa_all_inst = \Agentic_Agent_Registry::get_instance()->get_all_instances();
					$agentic_pa_inst     = $agentic_pa_all_inst[ $agentic_pa_selected ] ?? null;
					if ( $agentic_pa_inst ) {
						$agentic_pa_placeholders = array_pad( array_slice( $agentic_pa_inst->get_suggested_prompts(), 0, 4 ), 4, 'Prompt ' );
					}
				}
				$agentic_pa_prompts_saved = array_pad( array_slice( $agentic_pa_prompts_saved, 0, 4 ), 4, '' );
				?>
				<?php foreach ( $agentic_pa_prompts_saved as $agentic_pi => $agentic_pv ) : ?>
					<div class="agentic-mb-6">
						<input type="text" name="agentic_agent_personas[<?php echo esc_attr( $agentic_pa_selected ); ?>][suggested_prompts][]" value="<?php echo esc_attr( $agentic_pv ); ?>" class="large-text" placeholder="<?php echo esc_attr( $agentic_pa_placeholders[ $agentic_pi ] ?? ( 'Prompt ' . ( $agentic_pi + 1 ) ) ); ?>" />
					</div>
				<?php endforeach; ?>
				<p class="description">Up to 4 clickable prompt pills shown below the welcome message. Leave blank to hide.</p>
			</td>
		</tr>
		<tr>
			<th scope="row" class="agentic-th-180">
				<label for="agentic_persona_notes_<?php echo esc_attr( $agentic_pa_selected ); ?>">Persona Notes</label>
			</th>
			<td>
				<textarea
					name="agentic_agent_personas[<?php echo esc_attr( $agentic_pa_selected ); ?>][persona_notes]"
					id="agentic_persona_notes_<?php echo esc_attr( $agentic_pa_selected ); ?>"
					rows="5"
					class="large-text"
					placeholder="<?php echo esc_attr( "e.g. This agent is deployed on a WooCommerce store selling handmade jewellery in Cape Town, South Africa. Always maintain a warm, personal tone and mention the handcrafted origin when relevant. The store owner's name is Sarah \u2014 use her name occasionally to add a personal touch. When visitors ask about shipping, remind them that all orders are packed by hand and may take 3\u20135 business days." ); ?>"
				><?php echo esc_textarea( $agentic_pa_notes ); ?></textarea>
				<p class="description">Additional context appended to this agent&rsquo;s system prompt on every conversation. Use it to share business context, tone of voice, or specific instructions &mdash; without overwriting the core system prompt.</p>
			</td>
		</tr>
		<tr>
			<th scope="row" class="agentic-th-180">
				<label for="agentic_persona_knowledge_<?php echo esc_attr( $agentic_pa_selected ); ?>">Knowledge</label>
			</th>
			<td>
				<?php
				$agentic_pa_knowledge_file = AGENT_BUILDER_KNOWLEDGE_DIR . '/' . $agentic_pa_selected . '-knowledge.txt';
				$agentic_pa_knowledge      = file_exists( $agentic_pa_knowledge_file )
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local plugin file.
					? file_get_contents( $agentic_pa_knowledge_file )
					: '';
				?>
				<textarea
					name="agentic_agent_personas[<?php echo esc_attr( $agentic_pa_selected ); ?>][knowledge]"
					id="agentic_persona_knowledge_<?php echo esc_attr( $agentic_pa_selected ); ?>"
					rows="10"
					class="large-text code"
					placeholder="<?php echo esc_attr( "e.g. Our business hours are Mon-Fri 9am-5pm SAST. Returns accepted within 30 days with proof of purchase.\n\nProduct categories: Rings, Necklaces, Bracelets, Earrings, Custom Orders.\n\nFAQ:\nQ: Do you ship internationally?\nA: Yes, we ship to 40+ countries via DHL Express (5-7 business days)." ); ?>"
				><?php echo esc_textarea( $agentic_pa_knowledge ); ?></textarea>
				<p class="description">Quick reference for this agent (FAQs, policies, product facts). For a structured, multi-concept wiki, use <a href="<?php echo esc_url( admin_url( 'admin.php?page=agentic-train-data&tab=wiki' ) ); ?>"><strong>Knowledge → Knowledge Wiki (OKF)</strong></a>. Hosted Vector Store / RAG is available in Agent Builder Pro.</p>
			</td>
		</tr>
	</table>
</div>
	<?php endif; ?>

<?php endif; ?>

<p class="agentic-mt-8"><a href="#" class="agentic-reset-defaults" data-section="personas">Reset all instructions to defaults</a></p>

<div class="agentic-callout-blue">
	<strong>Two ways to give agents knowledge</strong>
	Use the free <a href="<?php echo esc_url( admin_url( 'admin.php?page=agentic-train-data&tab=wiki' ) ); ?>"><strong>Knowledge Wiki (OKF)</strong></a> for curated markdown concepts (policies, FAQs, playbooks) that stay on your server. For large PDFs and whole-site semantic search, upgrade to <strong>Agent Builder Pro</strong> and use <a href="<?php echo esc_url( admin_url( 'admin.php?page=agentic-train-data&tab=vector' ) ); ?>"><strong>Knowledge → Vector Store</strong></a>.
</div>
