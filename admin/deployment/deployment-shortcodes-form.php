<?php
/**
 * Shared form fields for creating and editing a shortcode deployment.
 *
 * Expected variables in scope:
 *   $agentic_form_data   array  Deployment data (label, agent, style, height, show_header, placeholder)
 *   $agentic_all_agents  array  All agent instances from the registry
 *
 * Included by admin/deployment-shortcodes.php — do not load directly.
 *
 * @package    Agent_Builder
 * @subpackage Admin
 * @since      1.9.7
 *
 * php version 8.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$agentic_f_label       = $agentic_form_data['label'] ?? '';
$agentic_f_agent       = $agentic_form_data['agent'] ?? '';
$agentic_f_style       = $agentic_form_data['style'] ?? 'inline';
$agentic_f_height      = $agentic_form_data['height'] ?? '500px';
$agentic_f_show_header = ( $agentic_form_data['show_header'] ?? 'true' ) !== 'false';
$agentic_f_placeholder = $agentic_form_data['placeholder'] ?? '';
?>
<table class="form-table" role="presentation">
	<tr>
		<th scope="row">
			<label for="agentic_sc_label"><?php esc_html_e( 'Label', 'agentstructor' ); ?></label>
		</th>
		<td>
			<input type="text" id="agentic_sc_label" name="agentic_sc_label" class="regular-text" required
				value="<?php echo esc_attr( $agentic_f_label ); ?>"
				placeholder="<?php esc_attr_e( 'e.g., Homepage Support Chat', 'agentstructor' ); ?>">
			<p class="description"><?php esc_html_e( 'A name to identify this deployment (not shown to visitors).', 'agentstructor' ); ?></p>
		</td>
	</tr>
	<tr>
		<th scope="row">
			<label for="agentic_sc_agent"><?php esc_html_e( 'Agent', 'agentstructor' ); ?></label>
		</th>
		<td>
			<select id="agentic_sc_agent" name="agentic_sc_agent" required>
				<option value=""><?php esc_html_e( '— Select Agent —', 'agentstructor' ); ?></option>
				<?php foreach ( $agentic_all_agents as $agentic_a ) : ?>
					<option value="<?php echo esc_attr( $agentic_a->get_id() ); ?>" <?php selected( $agentic_f_agent, $agentic_a->get_id() ); ?>>
						<?php echo esc_html( $agentic_a->get_icon() . ' ' . $agentic_a->get_name() ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<p class="description"><?php esc_html_e( 'The agent visitors will chat with.', 'agentstructor' ); ?></p>
		</td>
	</tr>
	<tr>
		<th scope="row">
			<label for="agentic_sc_style"><?php esc_html_e( 'Display Style', 'agentstructor' ); ?></label>
		</th>
		<td>
			<select id="agentic_sc_style" name="agentic_sc_style">
				<option value="inline" <?php selected( $agentic_f_style, 'inline' ); ?>><?php esc_html_e( 'Inline — embedded in page content', 'agentstructor' ); ?></option>
				<option value="popup" <?php selected( $agentic_f_style, 'popup' ); ?>><?php esc_html_e( 'Popup — floating button that opens a chat window', 'agentstructor' ); ?></option>
				<option value="sidebar" <?php selected( $agentic_f_style, 'sidebar' ); ?>><?php esc_html_e( 'Sidebar — compact widget for sidebars', 'agentstructor' ); ?></option>
			</select>
			<p class="description"><?php esc_html_e( 'How the chat widget appears on the page.', 'agentstructor' ); ?></p>
		</td>
	</tr>
	<tr>
		<th scope="row">
			<label for="agentic_sc_height"><?php esc_html_e( 'Chat Height', 'agentstructor' ); ?></label>
		</th>
		<td>
			<input type="text" id="agentic_sc_height" name="agentic_sc_height" value="<?php echo esc_attr( $agentic_f_height ); ?>" class="small-text" style="width: 100px;">
			<p class="description"><?php esc_html_e( 'CSS height value (e.g. 500px, 60vh). Applies to inline and sidebar styles.', 'agentstructor' ); ?></p>
		</td>
	</tr>
	<tr>
		<th scope="row"><?php esc_html_e( 'Header', 'agentstructor' ); ?></th>
		<td>
			<label>
				<input type="checkbox" id="agentic_sc_show_header" name="agentic_sc_show_header" value="1" <?php checked( $agentic_f_show_header ); ?>>
				<?php esc_html_e( 'Show agent name and icon header', 'agentstructor' ); ?>
			</label>
			<p class="description"><?php esc_html_e( 'Display the agent name and avatar above the chat area.', 'agentstructor' ); ?></p>
		</td>
	</tr>
	<tr>
		<th scope="row">
			<label for="agentic_sc_placeholder"><?php esc_html_e( 'Placeholder Text', 'agentstructor' ); ?></label>
		</th>
		<td>
			<input type="text" id="agentic_sc_placeholder" name="agentic_sc_placeholder" class="regular-text"
				value="<?php echo esc_attr( $agentic_f_placeholder ); ?>"
				placeholder="<?php esc_attr_e( 'Type your message...', 'agentstructor' ); ?>">
			<p class="description"><?php esc_html_e( 'Custom placeholder text in the input field. Leave empty for default.', 'agentstructor' ); ?></p>
		</td>
	</tr>
</table>

<!-- Live Preview -->
<div id="agentic-sc-preview" class="agentic-sc-preview">
	<strong><?php esc_html_e( 'Shortcode preview:', 'agentstructor' ); ?></strong>
	<code id="agentic-sc-preview-code" class="agentic-sc-preview code">[agentic_chat]</code>
</div>
