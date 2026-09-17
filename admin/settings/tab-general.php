<?php
/**
 * Settings — General Tab
 *
 * Global personalization: how agents address administrators and frontend
 * visitors. Site knowledge / standing instructions live in Knowledge Wiki (OKF).
 * Appearance lives under Interface. Per-agent persona is on the Instructions tab.
 *
 * @package    Agent_Builder
 * @subpackage Admin/Settings
 * @since      2.15.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$agent_builder_admin_address    = (string) get_option( 'agent_builder_admin_address', '' );
$agent_builder_frontend_address = (string) get_option( 'agent_builder_frontend_address', '' );
$agentic_wiki_url         = admin_url( 'admin.php?page=agentic-train-data&tab=wiki' );
?>

<p class="agentic-settings-lead"><?php esc_html_e( 'How agents address people. Site facts and standing guidance belong in Knowledge Wiki.', 'agent-builder' ); ?></p>
<h3 class="agentic-settings-section-title"><?php esc_html_e( 'Personalization', 'agent-builder' ); ?></h3>

<table class="form-table" role="presentation">
	<tr>
		<th scope="row">
			<label for="agent_builder_admin_address"><?php esc_html_e( 'What should agents call administrators?', 'agent-builder' ); ?></label>
		</th>
		<td>
			<input type="text" id="agent_builder_admin_address" name="agent_builder_admin_address" class="regular-text" maxlength="60" value="<?php echo esc_attr( $agent_builder_admin_address ); ?>" placeholder="<?php esc_attr_e( 'e.g. Sam, or “the site owner”', 'agent-builder' ); ?>" />
			<p class="description"><?php esc_html_e( 'How agents address signed-in administrators in conversation. Leave blank to use the WordPress display name.', 'agent-builder' ); ?></p>
		</td>
	</tr>
	<tr>
		<th scope="row">
			<label for="agent_builder_frontend_address"><?php esc_html_e( 'What should agents call frontend visitors?', 'agent-builder' ); ?></label>
		</th>
		<td>
			<input type="text" id="agent_builder_frontend_address" name="agent_builder_frontend_address" class="regular-text" maxlength="60" value="<?php echo esc_attr( $agent_builder_frontend_address ); ?>" placeholder="<?php esc_attr_e( 'e.g. there, or “valued customer”', 'agent-builder' ); ?>" />
			<p class="description"><?php esc_html_e( 'How agents address visitors using a chat shortcode or block on the public site. Leave blank for a neutral greeting.', 'agent-builder' ); ?></p>
		</td>
	</tr>
	<tr>
		<th scope="row"><?php esc_html_e( 'Site knowledge', 'agent-builder' ); ?></th>
		<td>
			<p class="description" style="margin-top:0">
				<?php
				printf(
					wp_kses(
						/* translators: %s: Knowledge admin URL */
						__( 'Standing facts, tone, and “always keep in mind” guidance are managed under <a href="%s"><strong>Knowledge</strong></a>. Mark a concept <strong>Always include in prompts</strong> so every agent receives it automatically (for example Site overview).', 'agent-builder' ),
						array(
							'a'      => array( 'href' => array() ),
							'strong' => array(),
						)
					),
					esc_url( $agentic_wiki_url )
				);
				?>
			</p>
			<p>
				<a class="button button-secondary" href="<?php echo esc_url( $agentic_wiki_url ); ?>">
					<?php esc_html_e( 'Open Knowledge', 'agent-builder' ); ?>
				</a>
			</p>
		</td>
	</tr>
</table>
