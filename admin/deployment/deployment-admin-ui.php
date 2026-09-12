<?php
/**
 * Deployment Tab: Admin UI
 *
 * Configure admin-context deployment surfaces — currently the Gutenberg
 * editor sidebar, which adds a context-aware AI assistant panel while
 * editing posts or pages.
 *
 * Included by admin/deployment.php — do not load directly.
 *
 * @package    Agent_Builder
 * @subpackage Admin
 * @author     Agent Builder Team <support@agentic-plugin.com>
 * @license    GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 * @link       https://agentic-plugin.com
 * @since      2.3.0
 *
 * php version 8.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Handle save.
if (
	isset( $_POST['agentic_save_admin_ui'], $_POST['_wpnonce'] )
	&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'agentic_admin_ui_settings' )
) {
	$agentic_post_types_raw = isset( $_POST['agentic_sidebar_post_types'] ) && is_array( $_POST['agentic_sidebar_post_types'] )
		? array_map( 'sanitize_key', wp_unslash( $_POST['agentic_sidebar_post_types'] ) )
		: array();

	$agentic_sidebar_mode_raw = sanitize_key( wp_unslash( $_POST['agentic_sidebar_agent_mode'] ?? '' ) );

	// Agents checked for inclusion in the sidebar.
	$agentic_agents_raw = isset( $_POST['agentic_sidebar_agents'] ) && is_array( $_POST['agentic_sidebar_agents'] )
		? array_values( array_map( 'sanitize_key', wp_unslash( $_POST['agentic_sidebar_agents'] ) ) )
		: array();

	// The pre-selected (default) agent — must be in the list; auto-add if not.
	$agentic_default_agent_raw = sanitize_key( wp_unslash( $_POST['agentic_sidebar_default_agent'] ?? '' ) );
	if ( $agentic_default_agent_raw && ! in_array( $agentic_default_agent_raw, $agentic_agents_raw, true ) ) {
		$agentic_agents_raw[] = $agentic_default_agent_raw;
	}

	$agentic_new_settings = array(
		'enabled'         => ! empty( $_POST['agentic_sidebar_enabled'] ) ? '1' : '0',
		'agent_slug'      => $agentic_default_agent_raw,
		'agent_slugs'     => $agentic_agents_raw,
		'post_types'      => $agentic_post_types_raw,
		'inject_context'  => ! empty( $_POST['agentic_sidebar_inject_context'] ) ? '1' : '0',
		'agent_mode'      => in_array( $agentic_sidebar_mode_raw, array( 'supervised', 'autonomous' ), true ) ? $agentic_sidebar_mode_raw : 'autonomous',
		'toolbar_enabled' => ! empty( $_POST['agentic_toolbar_enabled'] ) ? '1' : '0',
	);

	// Write to Deployments table — one row per agent in the sidebar.
	if ( class_exists( '\Agentic\Deployments' ) ) {
		$agentic_ui_existing = array();
		foreach ( \Agentic\Deployments::all( \Agentic\Deployments::TYPE_ADMIN_UI ) as $agentic_ui_row ) {
			$agentic_ui_existing[ $agentic_ui_row['agent_slug'] ] = $agentic_ui_row['id'];
		}

		// All slugs we need to touch = newly configured + previously stored.
		$agentic_ui_all_slugs = array_unique(
			array_merge( $agentic_agents_raw, array_keys( $agentic_ui_existing ) )
		);

		foreach ( $agentic_ui_all_slugs as $agentic_ui_s ) {
			$agentic_ui_s  = sanitize_key( $agentic_ui_s );
			$agentic_ui_on = in_array( $agentic_ui_s, $agentic_agents_raw, true )
				&& '1' === $agentic_new_settings['enabled'];

			$agentic_ui_save = array(
				'type'       => \Agentic\Deployments::TYPE_ADMIN_UI,
				'agent_slug' => $agentic_ui_s,
				'label'      => ucwords( str_replace( '-', ' ', $agentic_ui_s ) ),
				'enabled'    => $agentic_ui_on,
				'source'     => \Agentic\Deployments::SOURCE_ADMIN,
				'config'     => array(
					'post_types'      => $agentic_new_settings['post_types'],
					'inject_context'  => '1' === $agentic_new_settings['inject_context'],
					'agent_mode'      => $agentic_new_settings['agent_mode'],
					'is_default'      => $agentic_default_agent_raw === $agentic_ui_s,
					'toolbar_enabled' => '1' === $agentic_new_settings['toolbar_enabled'],
				),
			);
			if ( isset( $agentic_ui_existing[ $agentic_ui_s ] ) ) {
				$agentic_ui_save['id'] = $agentic_ui_existing[ $agentic_ui_s ];
			}
			\Agentic\Deployments::save( $agentic_ui_save );
		}
	}

	// Keep WP option in sync for feature backends.
	update_option( 'agentic_editor_sidebar_settings', $agentic_new_settings );

	// Push the mode into agent_settings for every sidebar agent.
	if ( ! empty( $agentic_agents_raw ) ) {
		foreach ( $agentic_agents_raw as $agentic_override_slug ) {
			$agentic_override_slug = sanitize_key( (string) $agentic_override_slug );
			if ( ! empty( $agentic_override_slug ) ) {
				\Agentic\Agent_Settings::update( $agentic_override_slug, 'override_mode', $agentic_new_settings['agent_mode'] );
			}
		}
		\Agentic\Agent_Settings::bust_cache();
	}

	$agentic_sidebar_audit = new \Agentic\Audit_Log();
	$agentic_sidebar_audit->log(
		'system',
		'settings_changed',
		'deployment_admin_ui',
		array(
			'setting' => 'editor_sidebar',
			'changes' => array(
				'enabled'     => $agentic_new_settings['enabled'],
				'agent_mode'  => $agentic_new_settings['agent_mode'],
				'post_types'  => $agentic_new_settings['post_types'],
				'agent_slugs' => $agentic_new_settings['agent_slugs'],
			),
		)
	);

	$agentic_notice = __( 'Editor sidebar settings saved.', 'agent-builder' );
}

// Load agents and registry.
$agentic_registry   = \Agentic_Agent_Registry::get_instance();
$agentic_all_agents = $agentic_registry->get_all_instances();

// Load settings from Deployments rows with fallback to WP options.
$agentic_ui_rows = array();
if ( class_exists( '\Agentic\Deployments' ) ) {
	foreach ( \Agentic\Deployments::all( \Agentic\Deployments::TYPE_ADMIN_UI ) as $agentic_ui_row ) {
		$agentic_ui_rows[ $agentic_ui_row['agent_slug'] ] = $agentic_ui_row;
	}
}

if ( ! empty( $agentic_ui_rows ) ) {
	// Reconstruct sidebar settings from Deployments rows.
	$agentic_ui_enabled_rows = array_filter( $agentic_ui_rows, fn( $r ) => $r['enabled'] );
	$agentic_ui_any_enabled  = ! empty( $agentic_ui_enabled_rows );
	$agentic_ui_first_cfg    = reset( $agentic_ui_rows )['config'] ?? array();

	$agentic_sidebar_settings = array(
		'enabled'         => $agentic_ui_any_enabled ? '1' : '0',
		'agent_slug'      => '',
		'agent_slugs'     => array_keys( $agentic_ui_enabled_rows ),
		'post_types'      => $agentic_ui_first_cfg['post_types'] ?? array( 'post', 'page' ),
		'inject_context'  => ( $agentic_ui_first_cfg['inject_context'] ?? true ) ? '1' : '0',
		'agent_mode'      => $agentic_ui_first_cfg['agent_mode'] ?? 'autonomous',
		'toolbar_enabled' => ( $agentic_ui_first_cfg['toolbar_enabled'] ?? true ) ? '1' : '0',
	);

	// Find the default agent.
	foreach ( $agentic_ui_rows as $agentic_ui_s => $agentic_ui_r ) {
		if ( ! empty( $agentic_ui_r['config']['is_default'] ) ) {
			$agentic_sidebar_settings['agent_slug'] = $agentic_ui_s;
			break;
		}
	}
	if ( empty( $agentic_sidebar_settings['agent_slug'] ) && ! empty( $agentic_sidebar_settings['agent_slugs'] ) ) {
		$agentic_sidebar_settings['agent_slug'] = $agentic_sidebar_settings['agent_slugs'][0];
	}
} else {
	// Fall back to WP options (pre-migration).
	$agentic_sidebar_settings = wp_parse_args(
		(array) get_option( 'agentic_editor_sidebar_settings', array() ),
		array(
			'enabled'         => '0',
			'agent_slug'      => '',
			'agent_slugs'     => array(),
			'post_types'      => array( 'post', 'page' ),
			'inject_context'  => '1',
			'agent_mode'      => 'autonomous',
			'toolbar_enabled' => '1',
		)
	);
}

// Default agent slug fallback — prefer content-writer, then first available.
if ( empty( $agentic_sidebar_settings['agent_slug'] ) ) {
	if ( isset( $agentic_all_agents['content-writer'] ) ) {
		$agentic_sidebar_settings['agent_slug'] = 'content-writer';
	} elseif ( ! empty( $agentic_all_agents ) ) {
		$agentic_sidebar_settings['agent_slug'] = array_key_first( $agentic_all_agents );
	}
	if ( ! in_array( $agentic_sidebar_settings['agent_slug'], $agentic_sidebar_settings['agent_slugs'], true ) ) {
		$agentic_sidebar_settings['agent_slugs'][] = $agentic_sidebar_settings['agent_slug'];
	}
}

// Public post types that have a block editor UI (exclude attachment).
$agentic_all_post_types = get_post_types( array( 'show_ui' => true ), 'objects' );
unset( $agentic_all_post_types['attachment'] );

// Contextual admin-screen launchers — default on.

?>

<?php if ( isset( $agentic_notice ) ) : ?>
<div class="notice notice-success is-dismissible">
	<p><?php echo esc_html( $agentic_notice ); ?></p>
</div>
<?php endif; ?>

<div class="agentic-tab-section">

	<h2><?php esc_html_e( 'Sidebar Agent', 'agent-builder' ); ?></h2>
	<p>
		<?php esc_html_e( 'Add an AI agent panel inside the block editor. When enabled, a sidebar panel appears while your team edits posts or pages — with full awareness of the current draft (title, content, post type, and status).', 'agent-builder' ); ?>
	</p>

	<form method="post" action="">
		<?php wp_nonce_field( 'agentic_admin_ui_settings' ); ?>

		<table class="form-table" role="presentation">

			<tr>
				<th scope="row"><?php esc_html_e( 'Enable Sidebar Agent', 'agent-builder' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="agentic_sidebar_enabled" value="1"
							<?php checked( '1', $agentic_sidebar_settings['enabled'] ); ?>>
						<?php esc_html_e( 'Show an AI agent panel in the Gutenberg block editor', 'agent-builder' ); ?>
					</label>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Agents', 'agent-builder' ); ?></th>
				<td>
					<?php if ( empty( $agentic_all_agents ) ) : ?>
						<p class="description agentic-status-error">
							<?php esc_html_e( 'No active agents found. Activate at least one agent first.', 'agent-builder' ); ?>
						</p>
					<?php else : ?>
						<table class="agentic-role-table">
							<thead>
								<tr>
									<th class="agentic-th-sm-dim-l"><?php esc_html_e( 'Show in sidebar', 'agent-builder' ); ?></th>
									<th class="agentic-th-sm-dim"><?php esc_html_e( 'Pre-selected', 'agent-builder' ); ?></th>
								</tr>
							</thead>
							<tbody>
							<?php foreach ( $agentic_all_agents as $agentic_slug => $agentic_agent ) : ?>
								<tr>
									<td class="agentic-role-cell-th">
										<label class="agentic-role-label">
											<input type="checkbox"
												name="agentic_sidebar_agents[]"
												value="<?php echo esc_attr( $agentic_slug ); ?>"
												<?php checked( in_array( $agentic_slug, (array) $agentic_sidebar_settings['agent_slugs'], true ) ); ?>>
											<?php echo esc_html( $agentic_agent->get_name() ); ?>
											<code class="agentic-code-xs"><?php echo esc_attr( $agentic_slug ); ?></code>
										</label>
									</td>
									<td class="agentic-role-cell-td">
										<input type="radio"
											name="agentic_sidebar_default_agent"
											value="<?php echo esc_attr( $agentic_slug ); ?>"
											aria-label="<?php echo esc_attr( sprintf( /* translators: %s: agent name */ __( 'Pre-select %s', 'agent-builder' ), $agentic_agent->get_name() ) ); ?>"
											<?php checked( $agentic_slug, $agentic_sidebar_settings['agent_slug'] ); ?>>
									</td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
						<p class="description agentic-mt-6">
							<?php esc_html_e( 'Tick the agents that appear in the editor sidebar. The pre-selected agent opens by default; switching is available in-sidebar when more than one is ticked.', 'agent-builder' ); ?>
						</p>
					<?php endif; ?>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Post Types', 'agent-builder' ); ?></th>
				<td>
					<?php foreach ( $agentic_all_post_types as $agentic_pt_slug => $agentic_pt ) : ?>
						<label class="agentic-field-label">
							<input type="checkbox"
								name="agentic_sidebar_post_types[]"
								value="<?php echo esc_attr( $agentic_pt_slug ); ?>"
								<?php checked( in_array( $agentic_pt_slug, (array) $agentic_sidebar_settings['post_types'], true ) ); ?>>
							<?php echo esc_html( $agentic_pt->label ); ?>
							<code class="agentic-code-xs agentic-ml-4"><?php echo esc_html( $agentic_pt_slug ); ?></code>
						</label>
					<?php endforeach; ?>
					<p class="description">
						<?php esc_html_e( 'Show the sidebar panel when editing these post types. Leave all unchecked to hide the sidebar everywhere.', 'agent-builder' ); ?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="agentic_sidebar_agent_mode"><?php esc_html_e( 'Confirmation Mode', 'agent-builder' ); ?></label>
				</th>
				<td>
					<select id="agentic_sidebar_agent_mode" name="agentic_sidebar_agent_mode">
						<option value="autonomous" <?php selected( 'autonomous', $agentic_sidebar_settings['agent_mode'] ); ?>>
							<?php esc_html_e( 'Autonomous — execute actions immediately (recommended)', 'agent-builder' ); ?>
						</option>
						<option value="supervised" <?php selected( 'supervised', $agentic_sidebar_settings['agent_mode'] ); ?>>
							<?php esc_html_e( 'Supervised — medium-risk actions create a proposal before executing', 'agent-builder' ); ?>
						</option>
					</select>
					<p class="description">
						<?php esc_html_e( 'Use Autonomous mode unless you need strict oversight.', 'agent-builder' ); ?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Context Injection', 'agent-builder' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="agentic_sidebar_inject_context" value="1"
							<?php checked( '1', $agentic_sidebar_settings['inject_context'] ); ?>>
						<?php esc_html_e( 'Automatically share the current draft title and content with the agent on the first message', 'agent-builder' ); ?>
					</label>
					<p class="description">
						<?php esc_html_e( 'The agent will be aware of what you are writing. Content is sent only when you open the chat — nothing is shared automatically.', 'agent-builder' ); ?>
					</p>
				</td>
			</tr>

			<tr>
			<th scope="row"><?php esc_html_e( 'Content Writer Toolbar', 'agent-builder' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="agentic_toolbar_enabled" value="1"
						<?php checked( '1', $agentic_sidebar_settings['toolbar_enabled'] ); ?>>
						<?php esc_html_e( 'Enable the inline AI toolbar (Magic Wand)', 'agent-builder' ); ?>
					</label>
					<p class="description">
						<?php esc_html_e( 'When enabled, shows a Magic Wand in the editor Block Toolbar. Click on it to open or close the Sidebar Agent.', 'agent-builder' ); ?>
					</p>
				</td>
			</tr>

		</table>

		<?php submit_button( __( 'Save Settings', 'agent-builder' ), 'primary', 'agentic_save_admin_ui' ); ?>

	</form>

</div>


