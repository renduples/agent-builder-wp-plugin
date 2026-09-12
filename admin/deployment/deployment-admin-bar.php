<?php
/**
 * Deployment Tab: Admin
 *
 * WordPress admin-surface deployments:
 * 1) Admin bar "AI Agents" menu + slide-in chat
 * 2) Contextual "Ask AI" launchers on core screens (Users, Plugins, etc.)
 *
 * Included by admin/deployment.php — do not load directly.
 *
 * @package    Agent_Builder
 * @subpackage Admin
 * @since      2.8.3
 *
 * php version 8.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Save (admin bar agents + contextual launchers) ─────────────────────────
if (
	isset( $_POST['agentic_save_admin_bar'], $_POST['_wpnonce'] )
	&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'agentic_admin_bar_settings' )
) {
	$agentic_ab_raw = isset( $_POST['agentic_ab'] ) && is_array( $_POST['agentic_ab'] )
		? wp_unslash( $_POST['agentic_ab'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per-field below.
		: array();

	$agentic_ab_registry   = \Agentic_Agent_Registry::get_instance();
	$agentic_ab_all_slugs  = array_keys( $agentic_ab_registry->get_all_instances() );
	$agentic_ab_submitted  = array();
	$agentic_ab_config_log = array();

	foreach ( $agentic_ab_raw as $agentic_ab_slug => $agentic_ab_data ) {
		$agentic_ab_slug = sanitize_key( $agentic_ab_slug );
		if ( ! $agentic_ab_slug ) {
			continue;
		}

		$agentic_ab_position = sanitize_key( $agentic_ab_data['position'] ?? 'bottom-right' );
		if ( ! in_array( $agentic_ab_position, array( 'bottom-right', 'bottom-left' ), true ) ) {
			$agentic_ab_position = 'bottom-right';
		}

		$agentic_ab_pages = sanitize_key( $agentic_ab_data['pages'] ?? 'all' );
		if ( ! in_array( $agentic_ab_pages, array( 'all', 'admin', 'front' ), true ) ) {
			$agentic_ab_pages = 'all';
		}

		$agentic_ab_display = ! empty( $agentic_ab_data['enabled'] ) ? '1' : '';

		\Agentic\Agent_Settings::update( $agentic_ab_slug, 'admin_bar_display', $agentic_ab_display );
		\Agentic\Agent_Settings::update( $agentic_ab_slug, 'admin_bar_position', $agentic_ab_position );
		\Agentic\Agent_Settings::update( $agentic_ab_slug, 'admin_bar_pages', $agentic_ab_pages );

		$agentic_ab_submitted[] = $agentic_ab_slug;
		if ( '1' === $agentic_ab_display ) {
			$agentic_ab_config_log[ $agentic_ab_slug ] = array(
				'position' => $agentic_ab_position,
				'pages'    => $agentic_ab_pages,
			);
		}
	}

	foreach ( $agentic_ab_all_slugs as $agentic_ab_missing ) {
		if ( ! in_array( $agentic_ab_missing, $agentic_ab_submitted, true ) ) {
			\Agentic\Agent_Settings::update( $agentic_ab_missing, 'admin_bar_display', '' );
		}
	}

	// Contextual Ask AI launchers.
	update_option(
		'agentic_admin_launchers_enabled',
		! empty( $_POST['agentic_admin_launchers_enabled'] ) ? '1' : '0'
	);

	$agentic_launcher_screens_raw = isset( $_POST['agentic_admin_launcher_screens'] ) && is_array( $_POST['agentic_admin_launcher_screens'] )
		? array_map( 'sanitize_key', wp_unslash( $_POST['agentic_admin_launcher_screens'] ) )
		: array();
	$agentic_allowed_screens      = array_keys( \Agentic\Admin_Surfaces::available_screens() );
	$agentic_launcher_screens     = array_values(
		array_intersect( $agentic_launcher_screens_raw, $agentic_allowed_screens )
	);
	// Empty selection with feature ON is allowed (shows nowhere until screens ticked).
	update_option( 'agentic_admin_launcher_screens', $agentic_launcher_screens, false );

	$agentic_launcher_agent = sanitize_key( wp_unslash( $_POST['agentic_admin_launcher_agent'] ?? 'wordpress-assistant' ) );
	// Must be an active agent when possible; still store slug so inactive preference is remembered.
	if ( '' === $agentic_launcher_agent ) {
		$agentic_launcher_agent = 'wordpress-assistant';
	}
	update_option( 'agentic_admin_launcher_agent', $agentic_launcher_agent, false );

	$agentic_ab_audit = new \Agentic\Audit_Log();
	$agentic_ab_audit->log(
		'system',
		'settings_changed',
		'deployment_admin',
		array(
			'setting' => 'admin_surfaces',
			'changes' => array(
				'admin_bar_agents'  => $agentic_ab_config_log,
				'launchers_enabled' => get_option( 'agentic_admin_launchers_enabled', '1' ),
				'launcher_screens'  => $agentic_launcher_screens,
				'launcher_agent'    => $agentic_launcher_agent,
			),
		)
	);

	$agentic_notice = __( 'Admin settings saved.', 'agent-builder' );
}

// Load per-agent admin-bar settings.
$agentic_registry   = \Agentic_Agent_Registry::get_instance();
$agentic_all_agents = $agentic_registry->get_all_instances();

$agentic_ab_rows = array();
foreach ( $agentic_all_agents as $agentic_ab_slug => $agentic_ab_inst ) {
	$agentic_ab_display = \Agentic\Agent_Settings::get( $agentic_ab_slug, 'admin_bar_display' );

	if ( '' === $agentic_ab_display && class_exists( '\Agentic\Deployments' ) ) {
		$agentic_dep_rows = \Agentic\Deployments::all( \Agentic\Deployments::TYPE_ADMIN_BAR, $agentic_ab_slug );
		if ( ! empty( $agentic_dep_rows[0] ) && ! empty( $agentic_dep_rows[0]['enabled'] ) ) {
			$agentic_dep_cfg    = $agentic_dep_rows[0]['config'] ?? array();
			$agentic_ab_display = '1';
			\Agentic\Agent_Settings::update( $agentic_ab_slug, 'admin_bar_display', '1' );
			\Agentic\Agent_Settings::update( $agentic_ab_slug, 'admin_bar_position', $agentic_dep_cfg['position'] ?? 'bottom-right' );
			\Agentic\Agent_Settings::update( $agentic_ab_slug, 'admin_bar_pages', $agentic_dep_cfg['pages'] ?? 'all' );
		}
	}

	$agentic_ab_rows[ $agentic_ab_slug ] = array(
		'enabled' => '1' === $agentic_ab_display,
		'config'  => array(
			'position' => \Agentic\Agent_Settings::get( $agentic_ab_slug, 'admin_bar_position', 'bottom-right' ),
			'pages'    => \Agentic\Agent_Settings::get( $agentic_ab_slug, 'admin_bar_pages', 'all' ),
		),
	);
}

// Launcher settings (defaults: enabled, all screens, wordpress-assistant).
$agentic_launchers_enabled = get_option( 'agentic_admin_launchers_enabled', '1' );
$agentic_launcher_screens  = get_option( 'agentic_admin_launcher_screens', null );
if ( ! is_array( $agentic_launcher_screens ) ) {
	// First visit / unset → all available screens (backwards compatible).
	$agentic_launcher_screens = array_keys( \Agentic\Admin_Surfaces::available_screens() );
}
$agentic_launcher_agent = (string) get_option( 'agentic_admin_launcher_agent', 'wordpress-assistant' );
if ( '' === $agentic_launcher_agent ) {
	$agentic_launcher_agent = 'wordpress-assistant';
}

// Agent picker: active agents preferred; include inactive selected so preference isn't lost.
$agentic_agent_choices = array();
foreach ( $agentic_all_agents as $agentic_slug => $agentic_agent ) {
	$agentic_agent_choices[ $agentic_slug ] = $agentic_agent->get_name();
}
// Also include installed-but-inactive if needed for the select default.
if ( ! isset( $agentic_agent_choices[ $agentic_launcher_agent ] ) ) {
	$agentic_installed = $agentic_registry->get_installed_agents();
	if ( isset( $agentic_installed[ $agentic_launcher_agent ]['name'] ) ) {
		$agentic_agent_choices[ $agentic_launcher_agent ] = $agentic_installed[ $agentic_launcher_agent ]['name']
			. ' (' . __( 'inactive', 'agent-builder' ) . ')';
	} else {
		$agentic_agent_choices[ $agentic_launcher_agent ] = $agentic_launcher_agent
			. ' (' . __( 'not installed', 'agent-builder' ) . ')';
	}
}
?>

<?php if ( isset( $agentic_notice ) ) : ?>
<div class="notice notice-success is-dismissible">
	<p><?php echo esc_html( $agentic_notice ); ?></p>
</div>
<?php endif; ?>

<div class="agentic-tab-section">

	<form method="post" action="">
		<?php wp_nonce_field( 'agentic_admin_bar_settings' ); ?>

		<h2 class="agentic-settings-section-title" style="margin-top:0;"><?php esc_html_e( 'Admin bar chat', 'agent-builder' ); ?></h2>
		<p class="agentic-settings-lead">
			<?php esc_html_e( 'Choose which agents appear under “AI Agents” in the WordPress toolbar. Clicking an agent opens the slide-in chat overlay.', 'agent-builder' ); ?>
		</p>

		<?php if ( empty( $agentic_all_agents ) ) : ?>
			<div class="notice notice-warning inline">
				<p><?php esc_html_e( 'No active agents found. Activate at least one agent first.', 'agent-builder' ); ?></p>
			</div>
		<?php else : ?>
			<div class="agentic-table-scroll">
			<table class="widefat striped agentic-table-min-560">
				<thead>
					<tr>
						<th class="agentic-col-40"><?php esc_html_e( 'Enable', 'agent-builder' ); ?></th>
						<th><?php esc_html_e( 'Agent', 'agent-builder' ); ?></th>
						<th><?php esc_html_e( 'Position', 'agent-builder' ); ?></th>
						<th><?php esc_html_e( 'Show On', 'agent-builder' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $agentic_all_agents as $agentic_slug => $agentic_agent ) : ?>
						<?php
						$agentic_ab_row        = $agentic_ab_rows[ $agentic_slug ] ?? array();
						$agentic_ab_is_enabled = ! empty( $agentic_ab_row['enabled'] );
						$agentic_ab_cfg        = $agentic_ab_row['config'] ?? array();
						$agentic_ab_position   = $agentic_ab_cfg['position'] ?? 'bottom-right';
						$agentic_ab_pages      = $agentic_ab_cfg['pages'] ?? 'all';
						?>
						<tr>
							<td class="agentic-td-center">
								<input type="checkbox"
									name="agentic_ab[<?php echo esc_attr( $agentic_slug ); ?>][enabled]"
									value="1"
									aria-label="<?php echo esc_attr( sprintf( /* translators: %s: agent name */ __( 'Enable admin bar chat for %s', 'agent-builder' ), $agentic_agent->get_name() ) ); ?>"
									<?php checked( $agentic_ab_is_enabled ); ?>>
							</td>
							<td>
								<?php echo esc_html( $agentic_agent->get_icon() . ' ' . $agentic_agent->get_name() ); ?>
								<code class="agentic-code-meta"><?php echo esc_html( $agentic_slug ); ?></code>
							</td>
							<td>
								<select name="agentic_ab[<?php echo esc_attr( $agentic_slug ); ?>][position]" class="agentic-select-full">
									<option value="bottom-right" <?php selected( $agentic_ab_position, 'bottom-right' ); ?>>
										<?php esc_html_e( 'Bottom Right', 'agent-builder' ); ?>
									</option>
									<option value="bottom-left" <?php selected( $agentic_ab_position, 'bottom-left' ); ?>>
										<?php esc_html_e( 'Bottom Left', 'agent-builder' ); ?>
									</option>
								</select>
							</td>
							<td>
								<select name="agentic_ab[<?php echo esc_attr( $agentic_slug ); ?>][pages]" class="agentic-select-full">
									<option value="all" <?php selected( $agentic_ab_pages, 'all' ); ?>>
										<?php esc_html_e( 'Everywhere', 'agent-builder' ); ?>
									</option>
									<option value="admin" <?php selected( $agentic_ab_pages, 'admin' ); ?>>
										<?php esc_html_e( 'Admin Only', 'agent-builder' ); ?>
									</option>
									<option value="front" <?php selected( $agentic_ab_pages, 'front' ); ?>>
										<?php esc_html_e( 'Frontend Only', 'agent-builder' ); ?>
									</option>
								</select>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			</div>
		<?php endif; ?>

		<hr class="agentic-section-divider" style="margin:24px 0;border:0;border-top:1px solid #e0e0e0;" />

		<h2 class="agentic-settings-section-title"><?php esc_html_e( 'Ask AI launchers', 'agent-builder' ); ?></h2>
		<p class="agentic-settings-lead">
			<?php esc_html_e( 'Show a compact “Ask AI” control on selected core admin screens. Launchers open the chat overlay with a screen-relevant starter prompt — nothing is sent until you press Send. Administrators only; each user can dismiss a launcher on the screen itself.', 'agent-builder' ); ?>
		</p>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Enable launchers', 'agent-builder' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="agentic_admin_launchers_enabled" value="1"
							<?php checked( '1', $agentic_launchers_enabled ); ?>>
						<?php esc_html_e( 'Show contextual Ask AI launchers on selected admin screens', 'agent-builder' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Screens', 'agent-builder' ); ?></th>
				<td>
					<fieldset>
						<legend class="screen-reader-text"><?php esc_html_e( 'Screens', 'agent-builder' ); ?></legend>
						<?php foreach ( \Agentic\Admin_Surfaces::available_screens() as $agentic_screen_key => $agentic_screen_meta ) : ?>
							<label style="display:block;margin:0 0 6px;">
								<input type="checkbox"
									name="agentic_admin_launcher_screens[]"
									value="<?php echo esc_attr( $agentic_screen_key ); ?>"
									<?php checked( in_array( $agentic_screen_key, $agentic_launcher_screens, true ) ); ?>>
								<?php echo esc_html( $agentic_screen_meta['label'] ); ?>
								<span class="description" style="margin-left:4px;"><?php echo esc_html( $agentic_screen_meta['description'] ?? '' ); ?></span>
							</label>
						<?php endforeach; ?>
					</fieldset>
					<p class="description">
						<?php esc_html_e( 'Choose where the launcher may appear. Uncheck all to hide launchers without turning the feature off globally.', 'agent-builder' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="agentic_admin_launcher_agent"><?php esc_html_e( 'Default agent', 'agent-builder' ); ?></label>
				</th>
				<td>
					<select name="agentic_admin_launcher_agent" id="agentic_admin_launcher_agent">
						<?php foreach ( $agentic_agent_choices as $agentic_choice_slug => $agentic_choice_name ) : ?>
							<option value="<?php echo esc_attr( $agentic_choice_slug ); ?>" <?php selected( $agentic_launcher_agent, $agentic_choice_slug ); ?>>
								<?php echo esc_html( $agentic_choice_name ); ?>
								<?php if ( 'wordpress-assistant' === $agentic_choice_slug ) : ?>
									— <?php esc_html_e( 'recommended default', 'agent-builder' ); ?>
								<?php endif; ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description">
						<?php esc_html_e( 'Used for Ask AI launchers when that agent is active. Defaults to WordPress Assistant; pick a custom agent you trained if you prefer.', 'agent-builder' ); ?>
					</p>
				</td>
			</tr>
		</table>

		<?php submit_button( __( 'Save Settings', 'agent-builder' ), 'primary', 'agentic_save_admin_bar' ); ?>
	</form>

	<div class="agentic-embed-note" style="margin-top:20px;">
		<h3><?php esc_html_e( 'How admin chat works', 'agent-builder' ); ?></h3>
		<ul>
			<li><?php esc_html_e( 'Admin bar: enabled agents appear under “AI Agents” in the toolbar and open the slide-in chat.', 'agent-builder' ); ?></li>
			<li><?php esc_html_e( 'Launchers: a pill control on selected screens opens the same overlay with a starter prompt for the chosen agent.', 'agent-builder' ); ?></li>
			<li><?php esc_html_e( 'Nothing is sent to your AI provider until you review the prompt and press Send.', 'agent-builder' ); ?></li>
		</ul>
	</div>

</div>
