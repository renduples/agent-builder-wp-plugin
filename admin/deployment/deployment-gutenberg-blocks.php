<?php
/**
 * Deployment Tab: Gutenberg Blocks
 *
 * Manage which agents are available as dynamic Gutenberg blocks.
 * When enabled, agents appear in the Block Inserter and can be
 * placed into posts, pages, and templates (including the Site Editor).
 *
 * Included by admin/deployment.php — do not load directly.
 *
 * @package    Agent_Builder
 * @subpackage Admin
 * @since      2.8.0
 *
 * php version 8.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Handle save.
if (
	isset( $_POST['agentic_save_gutenberg_blocks'], $_POST['_wpnonce'] )
	&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'agentic_gutenberg_blocks_settings' )
) {
	$agentic_gb_agents = isset( $_POST['agentic_gb_agents'] ) && is_array( $_POST['agentic_gb_agents'] )
		? array_values( array_map( 'sanitize_key', wp_unslash( $_POST['agentic_gb_agents'] ) ) )
		: array();

	// Write to Deployments table.
	if ( class_exists( '\Agentic\Deployments' ) ) {
		$agentic_gb_existing = array();
		foreach ( \Agentic\Deployments::all( \Agentic\Deployments::TYPE_GUTENBERG ) as $agentic_gb_row ) {
			$agentic_gb_existing[ $agentic_gb_row['agent_slug'] ] = $agentic_gb_row['id'];
		}

		$agentic_gb_save_registry = \Agentic_Agent_Registry::get_instance();
		$agentic_gb_all_slugs     = array_unique(
			array_merge(
				array_keys( $agentic_gb_save_registry->get_all_instances() ),
				array_keys( $agentic_gb_existing )
			)
		);

		foreach ( $agentic_gb_all_slugs as $agentic_gb_s ) {
			$agentic_gb_s  = sanitize_key( $agentic_gb_s );
			$agentic_gb_on = in_array( $agentic_gb_s, $agentic_gb_agents, true );

			if ( ! $agentic_gb_on && ! isset( $agentic_gb_existing[ $agentic_gb_s ] ) ) {
				continue;
			}

			$agentic_gb_save = array(
				'type'       => \Agentic\Deployments::TYPE_GUTENBERG,
				'agent_slug' => $agentic_gb_s,
				'label'      => ucwords( str_replace( '-', ' ', $agentic_gb_s ) ),
				'enabled'    => $agentic_gb_on,
				'source'     => \Agentic\Deployments::SOURCE_ADMIN,
				'config'     => array(),
			);
			if ( isset( $agentic_gb_existing[ $agentic_gb_s ] ) ) {
				$agentic_gb_save['id'] = $agentic_gb_existing[ $agentic_gb_s ];
			}
			\Agentic\Deployments::save( $agentic_gb_save );
		}
	}

	// Keep WP option in sync for feature backends.
	update_option( 'agentic_gutenberg_block_agents', $agentic_gb_agents );

	$agentic_gb_audit = new \Agentic\Audit_Log();
	$agentic_gb_audit->log(
		'system',
		'settings_changed',
		'deployment_gutenberg_blocks',
		array(
			'setting' => 'gutenberg_block_agents',
			'changes' => array( 'agents' => $agentic_gb_agents ),
		)
	);

	$agentic_notice = __( 'Gutenberg block settings saved.', 'agent-builder' );
}

// Load data from Deployments with fallback to WP options.
$agentic_registry   = \Agentic_Agent_Registry::get_instance();
$agentic_all_agents = $agentic_registry->get_all_instances();

$agentic_gb_rows = array();
if ( class_exists( '\Agentic\Deployments' ) ) {
	foreach ( \Agentic\Deployments::all( \Agentic\Deployments::TYPE_GUTENBERG ) as $agentic_gb_row ) {
		$agentic_gb_rows[ $agentic_gb_row['agent_slug'] ] = $agentic_gb_row;
	}
}

if ( empty( $agentic_gb_rows ) ) {
	// Fall back to WP option if table not yet populated (pre-migration).
	foreach ( (array) get_option( 'agentic_gutenberg_block_agents', array() ) as $agentic_fb_slug ) {
		$agentic_gb_rows[ $agentic_fb_slug ] = array(
			'enabled' => true,
			'config'  => array(),
		);
	}
}

// Load available themes.
$agentic_gb_themes = array(
	''         => __( 'Default (from Settings)', 'agent-builder' ),
	'dark'     => __( 'Dark', 'agent-builder' ),
	'light'    => __( 'Light', 'agent-builder' ),
	'midnight' => __( 'Midnight', 'agent-builder' ),
	'ocean'    => __( 'Ocean', 'agent-builder' ),
);

// Load available providers.
$agentic_gb_providers = \Agentic\Provider_Registry::get_slug_name_map();
?>

<?php if ( isset( $agentic_notice ) ) : ?>
<div class="notice notice-success is-dismissible">
	<p><?php echo esc_html( $agentic_notice ); ?></p>
</div>
<?php endif; ?>

<div class="agentic-tab-section">

	<p>
		<?php esc_html_e( 'Select which agents should appear as blocks in the Gutenberg Block Inserter. Enabled agents can be placed into any post, page, or template — including the Site Editor.', 'agent-builder' ); ?>
	</p>

	<?php if ( empty( $agentic_all_agents ) ) : ?>
		<div class="notice notice-warning">
			<p><?php esc_html_e( 'No active agents found. Activate at least one agent first.', 'agent-builder' ); ?></p>
		</div>
	<?php else : ?>
		<form method="post" action="">
			<?php wp_nonce_field( 'agentic_gutenberg_blocks_settings' ); ?>

			<div class="agentic-table-scroll">
			<table class="widefat striped agentic-table-700 agentic-table-min-560">
				<thead>
					<tr>
						<th class="agentic-col-40"><?php esc_html_e( 'Enable', 'agent-builder' ); ?></th>
						<th><?php esc_html_e( 'Agent', 'agent-builder' ); ?></th>
						<th><?php esc_html_e( 'Block Name', 'agent-builder' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $agentic_all_agents as $agentic_slug => $agentic_agent ) : ?>
						<?php
						$agentic_gb_is_enabled = ! empty( $agentic_gb_rows[ $agentic_slug ]['enabled'] );
						?>
						<tr>
							<td class="agentic-td-center">
								<input type="checkbox"
									name="agentic_gb_agents[]"
									value="<?php echo esc_attr( $agentic_slug ); ?>"
									aria-label="<?php echo esc_attr( sprintf( /* translators: %s: agent name */ __( 'Enable Gutenberg block for %s', 'agent-builder' ), $agentic_agent->get_name() ) ); ?>"
									<?php checked( $agentic_gb_is_enabled ); ?>>
							</td>
							<td>
								<?php echo esc_html( $agentic_agent->get_icon() . ' ' . $agentic_agent->get_name() ); ?>
								<code class="agentic-code-meta"><?php echo esc_html( $agentic_slug ); ?></code>
							</td>
							<td>
								<code>agentic/<?php echo esc_html( $agentic_slug ); ?></code>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			</div>

			<?php submit_button( __( 'Save Settings', 'agent-builder' ), 'primary', 'agentic_save_gutenberg_blocks' ); ?>
		</form>
	<?php endif; ?>

	<div class="agentic-embed-note">
		<h3><?php esc_html_e( 'How Gutenberg Blocks Work', 'agent-builder' ); ?></h3>
		<ul>
			<li><?php esc_html_e( 'Enabled agents appear under the "Agentic AI" category in the Block Inserter (+).', 'agent-builder' ); ?></li>
			<li><?php esc_html_e( 'Each block can be customized via the block settings sidebar: height, theme, display style, LLM provider, and more.', 'agent-builder' ); ?></li>
			<li><?php esc_html_e( 'Blocks work in the Post Editor, Page Editor, Site Editor, and Template Editor.', 'agent-builder' ); ?></li>
			<li><?php esc_html_e( 'The block renders the same chat interface as shortcodes — all features (audio, vision, TTS) are supported.', 'agent-builder' ); ?></li>
			<li><?php esc_html_e( 'Users must be logged in to chat unless anonymous access is enabled in Settings.', 'agent-builder' ); ?></li>
		</ul>
	</div>

</div>
