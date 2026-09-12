<?php
/**
 * Deployment Tab: Modal
 *
 * Manage which agents are deployed as floating chat modals on the
 * public-facing frontend. Each enabled agent has its own position,
 * page targeting, and login settings. The chat theme is shared from
 * Settings → Styles.
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

// Handle save.
if (
	isset( $_POST['agentic_save_modal'], $_POST['_wpnonce'] )
	&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'agentic_modal_settings' )
) {
	$agentic_modal_raw = isset( $_POST['agentic_modal'] ) && is_array( $_POST['agentic_modal'] )
		? wp_unslash( $_POST['agentic_modal'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per-field below.
		: array();

	$agentic_modal_config  = array();
	$agentic_modal_enabled = array();

	foreach ( $agentic_modal_raw as $agentic_m_slug => $agentic_m_data ) {
		$agentic_m_slug = sanitize_key( $agentic_m_slug );
		if ( empty( $agentic_m_data['enabled'] ) ) {
			continue;
		}

		$agentic_m_position = sanitize_key( $agentic_m_data['position'] ?? 'bottom-right' );
		if ( ! in_array( $agentic_m_position, array( 'bottom-right', 'bottom-left' ), true ) ) {
			$agentic_m_position = 'bottom-right';
		}

		$agentic_m_pages = sanitize_key( $agentic_m_data['pages'] ?? 'all' );
		if ( ! in_array( $agentic_m_pages, array( 'all', 'front', 'singular', 'homepage' ), true ) ) {
			$agentic_m_pages = 'all';
		}

		$agentic_m_login = ! empty( $agentic_m_data['require_login'] ) ? '1' : '0';

		$agentic_modal_enabled[]                 = $agentic_m_slug;
		$agentic_modal_config[ $agentic_m_slug ] = array(
			'position'      => $agentic_m_position,
			'pages'         => $agentic_m_pages,
			'require_login' => $agentic_m_login,
		);
	}

	// Write to Deployments table.
	if ( class_exists( '\Agentic\Deployments' ) ) {
		$agentic_m_existing = array();
		foreach ( \Agentic\Deployments::all( \Agentic\Deployments::TYPE_MODAL ) as $agentic_m_row ) {
			$agentic_m_existing[ $agentic_m_row['agent_slug'] ] = $agentic_m_row['id'];
		}

		$agentic_m_all_slugs = array_unique( array_merge( $agentic_modal_enabled, array_keys( $agentic_m_existing ) ) );

		foreach ( $agentic_m_all_slugs as $agentic_m_s ) {
			$agentic_m_s     = sanitize_key( $agentic_m_s );
			$agentic_m_on    = in_array( $agentic_m_s, $agentic_modal_enabled, true );
			$agentic_m_cfg_d = $agentic_modal_config[ $agentic_m_s ] ?? array();

			$agentic_m_save = array(
				'type'       => \Agentic\Deployments::TYPE_MODAL,
				'agent_slug' => $agentic_m_s,
				'label'      => ucwords( str_replace( '-', ' ', $agentic_m_s ) ),
				'enabled'    => $agentic_m_on,
				'source'     => \Agentic\Deployments::SOURCE_ADMIN,
				'config'     => array(
					'position'      => $agentic_m_cfg_d['position'] ?? 'bottom-right',
					'pages'         => $agentic_m_cfg_d['pages'] ?? 'all',
					'require_login' => (bool) ( $agentic_m_cfg_d['require_login'] ?? false ),
				),
			);
			if ( isset( $agentic_m_existing[ $agentic_m_s ] ) ) {
				$agentic_m_save['id'] = $agentic_m_existing[ $agentic_m_s ];
			}
			\Agentic\Deployments::save( $agentic_m_save );
		}
	}

	// Keep WP options in sync for feature backends.
	update_option( 'agentic_modal_agents', $agentic_modal_enabled );
	update_option( 'agentic_modal_config', $agentic_modal_config );

	$agentic_modal_audit = new \Agentic\Audit_Log();
	$agentic_modal_audit->log(
		'system',
		'settings_changed',
		'deployment_modal',
		array(
			'setting' => 'modal_agents',
			'changes' => array(
				'agents' => $agentic_modal_enabled,
				'config' => $agentic_modal_config,
			),
		)
	);

	$agentic_notice = __( 'Modal settings saved.', 'agent-builder' );
}

// Load data from Deployments with fallback to WP options.
$agentic_registry   = \Agentic_Agent_Registry::get_instance();
$agentic_all_agents = $agentic_registry->get_all_instances();
$agentic_chat_theme = get_option( 'agentic_chat_theme', 'light' );

$agentic_modal_rows = array();
if ( class_exists( '\Agentic\Deployments' ) ) {
	foreach ( \Agentic\Deployments::all( \Agentic\Deployments::TYPE_MODAL ) as $agentic_m_row ) {
		$agentic_modal_rows[ $agentic_m_row['agent_slug'] ] = $agentic_m_row;
	}
}

if ( empty( $agentic_modal_rows ) ) {
	// Fall back to WP options if table not yet populated (pre-migration).
	foreach ( (array) get_option( 'agentic_modal_agents', array() ) as $agentic_fb_slug ) {
		$agentic_fb_cfg                         = (array) get_option( 'agentic_modal_config', array() );
		$agentic_modal_rows[ $agentic_fb_slug ] = array(
			'enabled' => true,
			'source'  => \Agentic\Deployments::SOURCE_ADMIN,
			'config'  => $agentic_fb_cfg[ $agentic_fb_slug ] ?? array(),
		);
	}
}
?>

<?php if ( isset( $agentic_notice ) ) : ?>
<div class="notice notice-success is-dismissible">
	<p><?php echo esc_html( $agentic_notice ); ?></p>
</div>
<?php endif; ?>

<div class="agentic-tab-section">

	<p>
		<?php esc_html_e( 'Deploy a floating chat button on your public website. Visitors click the button to open a chat modal powered by the selected agent.', 'agent-builder' ); ?>
		<?php
		printf(
			/* translators: %s: link to Styles settings */
			esc_html__( 'Theme: %s.', 'agent-builder' ),
			'<strong>' . esc_html( ucfirst( $agentic_chat_theme ) ) . '</strong> — <a href="' . esc_url( admin_url( 'admin.php?page=agentic-settings&tab=interface' ) ) . '">'
				. esc_html__( 'change', 'agent-builder' )
			. '</a>'
		);
		?>
	</p>

	<?php if ( empty( $agentic_all_agents ) ) : ?>
		<div class="notice notice-warning">
			<p><?php esc_html_e( 'No active agents found. Activate at least one agent first.', 'agent-builder' ); ?></p>
		</div>
	<?php else : ?>
		<form method="post" action="">
			<?php wp_nonce_field( 'agentic_modal_settings' ); ?>

			<div class="agentic-table-scroll">
			<table class="widefat striped agentic-table-min-700">
				<thead>
					<tr>
						<th class="agentic-col-40"><?php esc_html_e( 'Enable', 'agent-builder' ); ?></th>
						<th><?php esc_html_e( 'Agent', 'agent-builder' ); ?></th>
						<th><?php esc_html_e( 'Position', 'agent-builder' ); ?></th>
						<th><?php esc_html_e( 'Show On', 'agent-builder' ); ?></th>
						<th><?php esc_html_e( 'Require Login', 'agent-builder' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $agentic_all_agents as $agentic_slug => $agentic_agent ) : ?>
						<?php
						$agentic_m_row        = $agentic_modal_rows[ $agentic_slug ] ?? array();
						$agentic_m_is_enabled = ! empty( $agentic_m_row['enabled'] );
						$agentic_m_cfg        = $agentic_m_row['config'] ?? array();
						$agentic_m_position   = $agentic_m_cfg['position'] ?? 'bottom-right';
						$agentic_m_pages      = $agentic_m_cfg['pages'] ?? 'all';
						$agentic_m_login      = $agentic_m_cfg['require_login'] ?? '0';
						?>
						<tr>
							<td class="agentic-td-center">
								<input type="checkbox"
									name="agentic_modal[<?php echo esc_attr( $agentic_slug ); ?>][enabled]"
									value="1"
									aria-label="<?php echo esc_attr( sprintf( /* translators: %s: agent name */ __( 'Enable frontend modal for %s', 'agent-builder' ), $agentic_agent->get_name() ) ); ?>"
									<?php checked( $agentic_m_is_enabled ); ?>>
							</td>
							<td>
								<?php echo esc_html( $agentic_agent->get_icon() . ' ' . $agentic_agent->get_name() ); ?>
								<code class="agentic-code-meta"><?php echo esc_html( $agentic_slug ); ?></code>
							</td>
							<td>
								<select name="agentic_modal[<?php echo esc_attr( $agentic_slug ); ?>][position]" class="agentic-select-full">
									<option value="bottom-right" <?php selected( $agentic_m_position, 'bottom-right' ); ?>>
										<?php esc_html_e( 'Bottom Right', 'agent-builder' ); ?>
									</option>
									<option value="bottom-left" <?php selected( $agentic_m_position, 'bottom-left' ); ?>>
										<?php esc_html_e( 'Bottom Left', 'agent-builder' ); ?>
									</option>
								</select>
							</td>
							<td>
								<select name="agentic_modal[<?php echo esc_attr( $agentic_slug ); ?>][pages]" class="agentic-select-full">
									<option value="all" <?php selected( $agentic_m_pages, 'all' ); ?>>
										<?php esc_html_e( 'All Pages', 'agent-builder' ); ?>
									</option>
									<option value="homepage" <?php selected( $agentic_m_pages, 'homepage' ); ?>>
										<?php esc_html_e( 'Homepage Only', 'agent-builder' ); ?>
									</option>
									<option value="singular" <?php selected( $agentic_m_pages, 'singular' ); ?>>
										<?php esc_html_e( 'Posts & Pages', 'agent-builder' ); ?>
									</option>
									<option value="front" <?php selected( $agentic_m_pages, 'front' ); ?>>
										<?php esc_html_e( 'Frontend Only', 'agent-builder' ); ?>
									</option>
								</select>
							</td>
							<td class="agentic-td-center">
								<input type="checkbox"
									name="agentic_modal[<?php echo esc_attr( $agentic_slug ); ?>][require_login]"
									value="1"
									aria-label="<?php echo esc_attr( sprintf( /* translators: %s: agent name */ __( 'Require login for %s', 'agent-builder' ), $agentic_agent->get_name() ) ); ?>"
									<?php checked( $agentic_m_login, '1' ); ?>>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			</div>

			<?php submit_button( __( 'Save Settings', 'agent-builder' ), 'primary', 'agentic_save_modal' ); ?>
		</form>
	<?php endif; ?>

	<div class="agentic-embed-note">
		<h3><?php esc_html_e( 'How the Modal Works', 'agent-builder' ); ?></h3>
		<ul>
			<li><?php esc_html_e( 'A floating button appears on your public website according to each agent\'s display settings.', 'agent-builder' ); ?></li>
			<li><?php esc_html_e( 'If multiple agents are enabled for the same page, visitors see a menu to pick one; a single agent opens directly.', 'agent-builder' ); ?></li>
			<li><?php esc_html_e( 'The modal injects page context automatically — the agent knows the current URL, page title, and post type.', 'agent-builder' ); ?></li>
			<li><?php esc_html_e( 'Chat history is stored per agent in the visitor\'s browser using localStorage.', 'agent-builder' ); ?></li>
			<li>
				<?php
				printf(
					/* translators: %s: link to Interface settings */
					esc_html__( 'The chat theme is controlled by %s.', 'agent-builder' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=agentic-settings&tab=interface' ) ) . '">'
						. esc_html__( 'Settings → Interface', 'agent-builder' )
					. '</a>'
				);
				?>
			</li>
		</ul>
	</div>

</div>
