<?php
/**
 * Installed Agents Admin Page
 *
 * Manage AI agents just like WordPress Plugins
 *
 * @package    Agent_Builder
 * @subpackage Admin
 * @author     AgentStructor Team <support@agentic-plugin.com>
 * @license    GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 * @link       https://agentic-plugin.com
 * @since      0.2.0
 *
 * php version 8.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'agent_builder_manage_agents' ) ) {
	wp_die( esc_html__( 'You do not have permission to access this page.', 'agentstructor' ) );
}

// Pro-only: optional consent wall for in-dashboard agent update checks.
// This build never shows it: there is no phone-home for update checks.
if ( class_exists( 'Agentic\Agent_Updates' ) && \Agentic\Agent_Updates::needs_consent() ) {
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'AI Agents', 'agentstructor' ); ?></h1>

		<div style="max-width:680px;margin:48px auto;background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:40px 48px;box-shadow:0 1px 3px rgba(0,0,0,.07);">
			<div style="text-align:center;margin-bottom:32px;">
				<span style="font-size:48px;line-height:1;" aria-hidden="true">🔄</span>
				<h2 style="font-size:22px;font-weight:600;margin:16px 0 8px;"><?php esc_html_e( 'Enable Automatic Agent Updates?', 'agentstructor' ); ?></h2>
				<p style="color:#646970;font-size:14px;margin:0;"><?php esc_html_e( 'AgentStructor can check agentic-plugin.com for newer versions of your installed AI agents.', 'agentstructor' ); ?></p>
			</div>

			<ul style="margin:0 0 28px 0;padding:0;list-style:none;">
				<li style="display:flex;gap:12px;align-items:flex-start;margin-bottom:16px;">
					<span style="color:#00a32a;font-size:18px;flex-shrink:0;line-height:1.4;" aria-hidden="true">✓</span>
					<span><strong><?php esc_html_e( 'Security patches delivered fast', 'agentstructor' ); ?></strong><br>
					<span style="color:#646970;font-size:13px;"><?php esc_html_e( 'Agent vulnerabilities can be exploited silently. Updates close those gaps before bad actors find them.', 'agentstructor' ); ?></span></span>
				</li>
				<li style="display:flex;gap:12px;align-items:flex-start;margin-bottom:16px;">
					<span style="color:#00a32a;font-size:18px;flex-shrink:0;line-height:1.4;" aria-hidden="true">✓</span>
					<span><strong><?php esc_html_e( 'Better agent performance', 'agentstructor' ); ?></strong><br>
					<span style="color:#646970;font-size:13px;"><?php esc_html_e( 'Agent logic, prompts, and tool integrations are continuously improved. Updates mean your agents work smarter.', 'agentstructor' ); ?></span></span>
				</li>
				<li style="display:flex;gap:12px;align-items:flex-start;">
					<span style="color:#00a32a;font-size:18px;flex-shrink:0;line-height:1.4;" aria-hidden="true">✓</span>
					<span><strong><?php esc_html_e( 'You stay in control', 'agentstructor' ); ?></strong><br>
					<span style="color:#646970;font-size:13px;"><?php esc_html_e( 'Updates are never applied automatically — you review and install each one yourself, exactly like WordPress plugins.', 'agentstructor' ); ?></span></span>
				</li>
			</ul>

			<div style="background:#f6f7f7;border-radius:3px;padding:14px 18px;margin-bottom:28px;font-size:13px;color:#646970;">
				<?php
				printf(
					wp_kses(
						/* translators: %1$s: agentic-plugin.com URL, %2$s: Privacy Policy URL */
						__( 'When enabled, your installed agent slugs and version numbers are sent to <a href="%1$s" target="_blank" rel="noopener">agentic-plugin.com</a> to check for updates. No personal data, passwords, or site content are included. See our <a href="%2$s" target="_blank" rel="noopener">Privacy Policy</a>.', 'agentstructor' ),
						array(
							'a' => array(
								'href'   => array(),
								'target' => array(),
								'rel'    => array(),
							),
						)
					),
					'https://agentic-plugin.com/',
					'https://agentic-plugin.com/privacy-policy/'
				);
				?>
			</div>

			<form method="post" style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap;">
				<?php wp_nonce_field( 'agentic_updates_consent', '_wpnonce_updates_consent' ); ?>
				<button type="submit" name="agentic_updates_consent" value="enable" class="button button-primary button-large">
					<?php esc_html_e( 'Enable Agent Updates', 'agentstructor' ); ?>
				</button>
				<button type="submit" name="agentic_updates_consent" value="disable" class="button button-large">
					<?php esc_html_e( 'No Thanks', 'agentstructor' ); ?>
				</button>
			</form>

			<p style="text-align:center;margin:20px 0 0;font-size:12px;color:#a7aaad;">
				<?php esc_html_e( 'This preference resets if you deactivate and reactivate the plugin.', 'agentstructor' ); ?>
			</p>
		</div>
	</div>
	<?php
	return;
}

$agentic_agent_action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : '';
$agentic_slug         = isset( $_GET['agent'] ) ? sanitize_text_field( wp_unslash( $_GET['agent'] ) ) : '';
$agentic_message      = '';
$agentic_agent_error  = '';

if ( $agentic_agent_action && $agentic_slug && isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'agentic_agent_action' ) ) {
	$agentic_registry = Agentic_Agent_Registry::get_instance();

	switch ( $agentic_agent_action ) {
		case 'activate':
			$agentic_result = $agentic_registry->activate_agent( $agentic_slug );
			if ( is_wp_error( $agentic_result ) ) {
					$agentic_agent_error = $agentic_result->get_error_message();
			} else {
				$agentic_agents_data = $agentic_registry->get_installed_agents( true );
				$agentic_agent_name  = $agentic_agents_data[ $agentic_slug ]['name'] ?? $agentic_slug;
				$agentic_page_slug   = 'assistant-trainer' === $agentic_slug ? 'agent-builder' : 'agentic-chat';
				$agentic_chat_url    = admin_url( 'admin.php?page=' . $agentic_page_slug . '&agent=' . $agentic_slug );
				$agentic_message     = sprintf(
				/* translators: 1: agent name, 2: chat URL */
					__( '%1$s activated. <a href="%2$s">Chat with this agent now →</a>', 'agentstructor' ),
					esc_html( $agentic_agent_name ),
					esc_url( $agentic_chat_url )
				);
			}
			break;

		case 'deactivate':
			$agentic_result = $agentic_registry->deactivate_agent( $agentic_slug );
			if ( is_wp_error( $agentic_result ) ) {
				$agentic_agent_error = $agentic_result->get_error_message();
			} else {
				$agentic_message = __( 'Agent deactivated.', 'agentstructor' );
			}
			break;

		case 'delete':
			$agentic_result = $agentic_registry->delete_agent( $agentic_slug );
			if ( is_wp_error( $agentic_result ) ) {
				$agentic_agent_error = $agentic_result->get_error_message();
			} else {
				$agentic_message = __( 'Agent deleted.', 'agentstructor' );
			}
			break;
	}
}

// Handle bulk actions.
if (
	isset( $_POST['bulk_action'], $_POST['checked'], $_POST['_wpnonce_bulk'] ) &&
	wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce_bulk'] ) ), 'agentic_bulk_action' ) &&
	current_user_can( 'manage_options' )
) {
	$agentic_bulk_action   = sanitize_text_field( wp_unslash( $_POST['bulk_action'] ) );
	$agentic_bulk_slugs    = array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['checked'] ) );
	$agentic_bulk_registry = Agentic_Agent_Registry::get_instance();
	$agentic_bulk_done     = 0;

	foreach ( $agentic_bulk_slugs as $agentic_bulk_slug ) {
		switch ( $agentic_bulk_action ) {
			case 'activate':
				if ( ! is_wp_error( $agentic_bulk_registry->activate_agent( $agentic_bulk_slug ) ) ) {
					++$agentic_bulk_done;
				}
				break;
			case 'deactivate':
				if ( ! is_wp_error( $agentic_bulk_registry->deactivate_agent( $agentic_bulk_slug ) ) ) {
					++$agentic_bulk_done;
				}
				break;
			case 'delete':
				if ( ! is_wp_error( $agentic_bulk_registry->delete_agent( $agentic_bulk_slug ) ) ) {
					++$agentic_bulk_done;
				}
				break;
		}
	}

	if ( $agentic_bulk_done ) {
		$agentic_message = sprintf(
			/* translators: 1: number of agents, 2: action label */
			_n( '%1$d agent %2$s.', '%1$d agents %2$s.', $agentic_bulk_done, 'agentstructor' ),
			$agentic_bulk_done,
			$agentic_bulk_action . 'd'
		);
	}
}


$agentic_registry = Agentic_Agent_Registry::get_instance();
$agentic_agents   = $agentic_registry->get_installed_agents( true );

// Fetch cached update data (populated by Agent_Updates::check() on page load).
$agentic_available_updates = class_exists( '\Agentic\Agent_Updates' ) ? \Agentic\Agent_Updates::get() : array();

// Connector reachability, computed once for the whole list rather than
// per row — WebMCP exposure in particular scans every active agent's own
// manifest internally, so calling it once and indexing by slug avoids
// redoing that scan for every row.
$agent_builder_webmcp_enabled = class_exists( '\Agentic\Webmcp_Bridge' ) && \Agentic\Webmcp_Bridge::is_enabled();
$agentic_webmcp_exposed_slugs = $agent_builder_webmcp_enabled && class_exists( '\Agentic\Abilities_Manifest' )
	? array_unique( array_column( \Agentic\Abilities_Manifest::get_webmcp_exposed(), 'agent_slug' ) )
	: array();

// Filter by status.
$agentic_filter = isset( $_GET['agent_status'] ) ? sanitize_text_field( wp_unslash( $_GET['agent_status'] ) ) : 'all';

$agentic_all_count      = count( $agentic_agents );
$agentic_active_count   = count( array_filter( $agentic_agents, fn( $a ) => $a['active'] ) );
$agentic_inactive_count = $agentic_all_count - $agentic_active_count;

if ( 'active' === $agentic_filter ) {
	$agentic_agents = array_filter( $agentic_agents, fn( $a ) => $a['active'] );
} elseif ( 'inactive' === $agentic_filter ) {
	$agentic_agents = array_filter( $agentic_agents, fn( $a ) => ! $a['active'] );
}

// Basic/Advanced split (M1 Phase 4). Basic replaces the plugins-style table
// with a card per agent (name, one-line description, Chat). Advanced keeps
// today's dense table unchanged. Toggle reuses set_screen_mode, screen key
// 'agents' -- same pattern as admin/providers.php (Phase 6).
$agentic_agents_advanced = \Agentic\Admin_Menu_Handler::is_advanced_mode( 'agents' );

// Classic PHP page — reuse react-admin.css purely for the shared
// .agentic-screen-mode-toggle styling, same pattern as admin/providers.php.
wp_enqueue_style( 'agentic-react-admin', AGENT_BUILDER_URL . 'assets/css/react-admin.css', array(), AGENT_BUILDER_VERSION );

?>

<div class="wrap agentic-agents-page">
	<div class="agentic-react-admin__page-head">
		<div>
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Agents', 'agentstructor' ); ?></h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=agentic-agent-wizard' ) ); ?>" class="page-title-action">
				<?php esc_html_e( 'Add an agent', 'agentstructor' ); ?>
			</a>
		</div>
		<span class="agentic-page-mode-toggle" role="group" aria-label="<?php esc_attr_e( 'Interface mode for the Agents screen only', 'agentstructor' ); ?>">
			<span class="agentic-page-mode-toggle__label"><?php esc_html_e( 'This screen', 'agentstructor' ); ?></span>
			<span class="agentic-screen-mode-toggle" id="agentic-agents-mode-toggle">
				<button type="button" class="button button-small<?php echo ! $agentic_agents_advanced ? ' button-primary' : ''; ?>" data-mode="basic">
					<?php esc_html_e( 'Basic', 'agentstructor' ); ?>
				</button>
				<button type="button" class="button button-small<?php echo $agentic_agents_advanced ? ' button-primary' : ''; ?>" data-mode="advanced">
					<?php esc_html_e( 'Advanced', 'agentstructor' ); ?>
				</button>
			</span>
		</span>
	</div>
	<hr class="wp-header-end">

<?php if ( ! $agentic_agents_advanced ) : ?>

	<?php if ( $agentic_message ) : ?>
		<div class="notice notice-success is-dismissible">
			<p><?php echo wp_kses( $agentic_message, array( 'a' => array( 'href' => array() ) ) ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( $agentic_agent_error ) : ?>
		<div class="notice notice-error is-dismissible">
			<p><?php echo esc_html( $agentic_agent_error ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( empty( $agentic_agents ) ) : ?>
		<p><?php esc_html_e( 'No agents installed yet.', 'agentstructor' ); ?></p>
	<?php else : ?>
		<div class="agentic-agents-basic">
			<?php foreach ( $agentic_agents as $agentic_slug => $agentic_agent ) : ?>
				<?php
				$agentic_page_slug = 'assistant-trainer' === $agentic_slug ? 'agent-builder' : 'agentic-chat';
				$agentic_chat_url  = admin_url( 'admin.php?page=' . $agentic_page_slug . '&agent=' . $agentic_slug );
				$agentic_desc      = trim( wp_strip_all_tags( (string) ( $agentic_agent['description'] ?? '' ) ) );
				if ( '' === $agentic_desc ) {
					// Bundled agents always ship a description in agent.json;
					// this fallback is for a custom/user agent with none set.
					$agentic_desc = __( 'An AI agent for this site.', 'agentstructor' );
				}
				?>
				<div class="agentic-agents-basic__card">
					<h2 class="agentic-agents-basic__name"><?php echo esc_html( $agentic_agent['name'] ); ?></h2>
					<p class="agentic-agents-basic__desc"><?php echo esc_html( $agentic_desc ); ?></p>
					<p class="agentic-agents-basic__actions">
						<a href="<?php echo esc_url( $agentic_chat_url ); ?>" class="button button-primary">
							<?php esc_html_e( 'Chat', 'agentstructor' ); ?>
						</a>
					</p>
				</div>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

<?php else : ?>

	<div class="agentic-card agentic-card-wide">

	<?php if ( $agentic_message ) : ?>
		<div class="notice notice-success is-dismissible">
			<p><?php echo wp_kses( $agentic_message, array( 'a' => array( 'href' => array() ) ) ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( $agentic_agent_error ) : ?>
		<div class="notice notice-error is-dismissible">
			<p><?php echo esc_html( $agentic_agent_error ); ?></p>
		</div>
	<?php endif; ?>

	<!-- Filter Links -->
	<ul class="subsubsub">
		<li class="all">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=agentic-agents' ) ); ?>"
		class="<?php echo 'all' === $agentic_filter ? 'current' : ''; ?>">
				<?php esc_html_e( 'All', 'agentstructor' ); ?>
				<span class="count">(<?php echo esc_html( $agentic_all_count ); ?>)</span>
			</a> |
		</li>
		<li class="active">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=agentic-agents&agent_status=active' ) ); ?>"
				class="<?php echo 'active' === $agentic_filter ? 'current' : ''; ?>">
				<?php esc_html_e( 'Active', 'agentstructor' ); ?>
				<span class="count">(<?php echo esc_html( $agentic_active_count ); ?>)</span>
			</a> |
		</li>
		<li class="inactive">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=agentic-agents&agent_status=inactive' ) ); ?>"
				class="<?php echo 'inactive' === $agentic_filter ? 'current' : ''; ?>">
				<?php esc_html_e( 'Inactive', 'agentstructor' ); ?>
				<span class="count">(<?php echo esc_html( $agentic_inactive_count ); ?>)</span>
			</a>
		</li>
	</ul>

	<form method="post" id="bulk-action-form">
	<?php wp_nonce_field( 'agentic_bulk_action', '_wpnonce_bulk' ); ?>

	<!-- Tablenav Top -->
	<div class="tablenav top">
		<div class="alignleft actions bulkactions">
			<label for="bulk-action-selector-top" class="screen-reader-text"><?php esc_html_e( 'Select bulk action', 'agentstructor' ); ?></label>
			<select name="bulk_action" id="bulk-action-selector-top">
				<option value="-1"><?php esc_html_e( 'Bulk actions', 'agentstructor' ); ?></option>
				<option value="activate"><?php esc_html_e( 'Activate', 'agentstructor' ); ?></option>
				<option value="deactivate"><?php esc_html_e( 'Deactivate', 'agentstructor' ); ?></option>
				<option value="delete"><?php esc_html_e( 'Delete', 'agentstructor' ); ?></option>
			</select>
			<input type="submit" id="doaction" class="button action" value="<?php esc_attr_e( 'Apply', 'agentstructor' ); ?>" onclick="return agentic_confirm_bulk(this);">
		</div>
		<br class="clear">
	</div>

	<!-- Agents Table -->
	<table class="wp-list-table widefat plugins">
		<thead>
			<tr>
				<td id="cb" class="manage-column column-cb check-column">
					<input type="checkbox" id="cb-select-all-1">
				</td>
				<th scope="col" class="manage-column column-name column-primary">
					<?php esc_html_e( 'Agent', 'agentstructor' ); ?>
				</th>
				<th scope="col" class="manage-column column-description">
					<?php esc_html_e( 'Description', 'agentstructor' ); ?>
				</th>
				<th scope="col" class="manage-column column-mcp agentic-connector-col">
					<?php esc_html_e( 'MCP', 'agentstructor' ); ?>
				</th>
				<th scope="col" class="manage-column column-webmcp agentic-connector-col">
					<?php esc_html_e( 'WebMCP', 'agentstructor' ); ?>
				</th>
				<th scope="col" class="manage-column column-whatsapp agentic-connector-col">
					<?php esc_html_e( 'WhatsApp', 'agentstructor' ); ?>
				</th>
			</tr>
		</thead>
		<tbody id="the-list">
			<?php if ( empty( $agentic_agents ) ) : ?>
				<tr class="no-items">
					<td class="colspanchange" colspan="6">
						<?php esc_html_e( 'No agents installed yet.', 'agentstructor' ); ?>
					</td>
				</tr>
			<?php else : ?>
				<?php foreach ( $agentic_agents as $agentic_slug => $agentic_agent ) : ?>
					<?php
					$agentic_row_class = $agentic_agent['active'] ? 'active' : 'inactive';
					$agentic_nonce     = wp_create_nonce( 'agentic_agent_action' );

					$agentic_mcp         = class_exists( '\Agentic_MCP_Server' ) ? \Agentic_MCP_Server::mcp_readiness( $agentic_slug ) : array(
						'ready'  => false,
						'reason' => null,
					);
					$agentic_mcp_ok      = ! empty( $agentic_mcp['ready'] );
					$agentic_webmcp_ok   = in_array( $agentic_slug, $agentic_webmcp_exposed_slugs, true );
					$agentic_whatsapp_ok = '1' === \Agentic\Agent_Settings::get( $agentic_slug, 'whatsapp_enabled' );
					?>
					<tr class="<?php echo esc_attr( $agentic_row_class ); ?>" data-slug="<?php echo esc_attr( $agentic_slug ); ?>">
						<th scope="row" class="check-column">
							<input type="checkbox" name="checked[]" value="<?php echo esc_attr( $agentic_slug ); ?>">
						</th>
						<td class="plugin-title column-primary">
							<strong><?php echo esc_html( $agentic_agent['name'] ); ?></strong>

							<div class="row-actions visible">

								<?php if ( $agentic_agent['active'] ) : ?>
									<?php
									$agentic_page_slug = 'assistant-trainer' === $agentic_slug ? 'agent-builder' : 'agentic-chat';
									?>
						<span class="chat">
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $agentic_page_slug . '&agent=' . $agentic_slug ) ); ?>" class="agentic-text-fw600">
											<?php esc_html_e( 'Chat', 'agentstructor' ); ?>
										</a> |
									</span>
									<span class="deactivate">
										<a href="<?php echo esc_url( admin_url( 'admin.php?page=agentic-agents&action=deactivate&agent=' . $agentic_slug . '&_wpnonce=' . $agentic_nonce ) ); ?>">
											<?php esc_html_e( 'Deactivate', 'agentstructor' ); ?>
										</a>
									</span>
								<?php else : ?>
									<span class="activate">
										<a href="<?php echo esc_url( admin_url( 'admin.php?page=agentic-agents&action=activate&agent=' . $agentic_slug . '&_wpnonce=' . $agentic_nonce ) ); ?>">
											<?php esc_html_e( 'Activate', 'agentstructor' ); ?>
										</a> |
									</span>
									<span class="delete">
										<a href="<?php echo esc_url( admin_url( 'admin.php?page=agentic-agents&action=delete&agent=' . $agentic_slug . '&_wpnonce=' . $agentic_nonce ) ); ?>"
											class="delete"
											data-agentic-confirm="<?php echo esc_attr( __( 'Are you sure you want to delete this agent?', 'agentstructor' ) ); ?>" data-agentic-confirm-danger data-agentic-confirm-ok="<?php echo esc_attr( __( 'Delete', 'agentstructor' ) ); ?>">
											<?php esc_html_e( 'Delete', 'agentstructor' ); ?>
										</a>
									</span>
								<?php endif; ?>
							</div>
						</td>
						<td class="column-description desc">
							<div class="plugin-description">
								<p><?php echo esc_html( $agentic_agent['description'] ); ?></p>
							</div>
							<div class="plugin-meta">
								<?php if ( ! empty( $agentic_agent['version'] ) ) : ?>
									<span class="agent-version">
									<?php
									/* translators: %s: agent version number */
									printf( esc_html__( 'Version %s', 'agentstructor' ), esc_html( $agentic_agent['version'] ) );
									?>
									</span>
									<span class="separator">|</span>
								<?php endif; ?>

								<?php if ( ! empty( $agentic_agent['author'] ) ) : ?>
									<span class="agent-author">
										<?php esc_html_e( 'By', 'agentstructor' ); ?>
										<?php if ( ! empty( $agentic_agent['author_uri'] ) ) : ?>
											<a href="<?php echo esc_url( $agentic_agent['author_uri'] ); ?>" target="_blank">
												<?php echo esc_html( $agentic_agent['author'] ); ?>
											</a>
										<?php else : ?>
											<?php echo esc_html( $agentic_agent['author'] ); ?>
										<?php endif; ?>
									</span>
									<span class="separator">|</span>
								<?php endif; ?>

								<?php if ( null !== \Agentic\Abilities_Manifest::load( $agentic_slug ) ) : ?>
									<?php $agentic_sig_ok = \Agentic\Abilities_Manifest::verify_integrity( $agentic_slug ); ?>
									<span class="agent-signature">
										<?php esc_html_e( 'Signature:', 'agentstructor' ); ?>
										<span class="agentic-signature-badge <?php echo $agentic_sig_ok ? 'pass' : 'mismatch'; ?>">
											<?php echo $agentic_sig_ok ? esc_html__( 'Pass', 'agentstructor' ) : esc_html__( 'Mismatch', 'agentstructor' ); ?>
										</span>
									</span>
									<span class="separator">|</span>
								<?php endif; ?>

								<?php if ( ! empty( $agentic_agent['category'] ) ) : ?>
									<span class="agent-category">
										<?php echo esc_html( $agentic_agent['category'] ); ?>
									</span>
									<span class="separator">|</span>
								<?php endif; ?>

								<?php if ( ! empty( $agentic_agent['capabilities'] ) ) : ?>
									<span class="agent-capabilities">
										<?php
										/* translators: %s: comma-separated list of agent capabilities */                                       printf(
											esc_html__( 'Capabilities: %s', 'agentstructor' ),
											esc_html( implode( ', ', $agentic_agent['capabilities'] ) )
										);
										?>
									</span>
								<?php endif; ?>
							</div>
						</td>
						<td class="column-mcp agentic-connector-cell" data-colname="<?php esc_attr_e( 'MCP', 'agentstructor' ); ?>">
							<?php if ( $agentic_mcp_ok ) : ?>
								<span class="dashicons dashicons-yes-alt agentic-di-green" aria-hidden="true" title="<?php esc_attr_e( 'Reachable via MCP', 'agentstructor' ); ?>"></span>
								<span class="screen-reader-text"><?php esc_html_e( 'Reachable via MCP', 'agentstructor' ); ?></span>
							<?php else : ?>
								<?php $agentic_mcp_reason = $agentic_mcp['reason'] ?? __( 'Not reachable via MCP', 'agentstructor' ); ?>
								<span class="dashicons dashicons-no agentic-di-red" aria-hidden="true" title="<?php echo esc_attr( $agentic_mcp_reason ); ?>"></span>
								<span class="screen-reader-text"><?php echo esc_html( $agentic_mcp_reason ); ?></span>
							<?php endif; ?>
						</td>
						<td class="column-webmcp agentic-connector-cell" data-colname="<?php esc_attr_e( 'WebMCP', 'agentstructor' ); ?>">
							<?php if ( $agentic_webmcp_ok ) : ?>
								<span class="dashicons dashicons-yes-alt agentic-di-green" aria-hidden="true" title="<?php esc_attr_e( 'Reachable via WebMCP', 'agentstructor' ); ?>"></span>
								<span class="screen-reader-text"><?php esc_html_e( 'Reachable via WebMCP', 'agentstructor' ); ?></span>
							<?php else : ?>
								<span class="dashicons dashicons-no agentic-di-red" aria-hidden="true" title="<?php esc_attr_e( 'Not reachable via WebMCP', 'agentstructor' ); ?>"></span>
								<span class="screen-reader-text"><?php esc_html_e( 'Not reachable via WebMCP', 'agentstructor' ); ?></span>
							<?php endif; ?>
						</td>
						<td class="column-whatsapp agentic-connector-cell" data-colname="<?php esc_attr_e( 'WhatsApp', 'agentstructor' ); ?>">
							<?php if ( $agentic_whatsapp_ok ) : ?>
								<span class="dashicons dashicons-yes-alt agentic-di-green" aria-hidden="true" title="<?php esc_attr_e( 'Reachable via WhatsApp', 'agentstructor' ); ?>"></span>
								<span class="screen-reader-text"><?php esc_html_e( 'Reachable via WhatsApp', 'agentstructor' ); ?></span>
							<?php else : ?>
								<span class="dashicons dashicons-no agentic-di-red" aria-hidden="true" title="<?php esc_attr_e( 'Not reachable via WhatsApp', 'agentstructor' ); ?>"></span>
								<span class="screen-reader-text"><?php esc_html_e( 'Not reachable via WhatsApp', 'agentstructor' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>

					<?php if ( isset( $agentic_available_updates[ $agentic_slug ] ) ) : ?>
						<?php $agentic_upd = $agentic_available_updates[ $agentic_slug ]; ?>
						<tr class="plugin-update-tr <?php echo esc_attr( $agentic_row_class ); ?>" id="<?php echo esc_attr( $agentic_slug ); ?>-update" data-slug="<?php echo esc_attr( $agentic_slug ); ?>">
							<td colspan="6" class="plugin-update colspanchange">
								<div class="update-message notice inline notice-warning notice-alt">
									<p>
										<?php if ( ! empty( $agentic_upd['package'] ) ) : ?>
											<?php
											printf(
												wp_kses(
													/* translators: 1: assistant name 2: new version number 3: details URL 4: zip download URL 5: slug 6: nonce */
													__( 'There is a new version of <strong>%1$s</strong> available. <a href="%3$s" target="_blank">View version %2$s details</a> or <a href="#" class="agentic-update-now" data-slug="%5$s" data-nonce="%6$s" data-url="%4$s">update now</a>.', 'agentstructor' ),
													array(
														'strong' => array(),
														'a'      => array(
															'href' => array(),
															'target' => array(),
															'class' => array(),
															'data-slug' => array(),
															'data-nonce' => array(),
															'data-url' => array(),
														),
													)
												),
												esc_html( $agentic_upd['name'] ),
												esc_html( $agentic_upd['version'] ),
												esc_url( $agentic_upd['url'] ),
												esc_url( $agentic_upd['package'] ),
												esc_attr( $agentic_slug ),
												esc_attr( wp_create_nonce( 'agentic_agent_update_' . $agentic_slug ) )
											);
											?>
										<?php else : ?>
											<?php
											printf(
												wp_kses(
													/* translators: 1: assistant name 2: new version number 3: details URL */
													__( 'There is a new version of <strong>%1$s</strong> available. <a href="%3$s" target="_blank">View version %2$s details</a>.', 'agentstructor' ),
													array(
														'strong' => array(),
														'a'      => array(
															'href' => array(),
															'target' => array(),
														),
													)
												),
												esc_html( $agentic_upd['name'] ),
												esc_html( $agentic_upd['version'] ),
												esc_url( $agentic_upd['url'] )
											);
											?>
										<?php endif; ?>
									</p>
								</div>
							</td>
						</tr>
					<?php endif; ?>

				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
		<tfoot>
			<tr>
				<td class="manage-column column-cb check-column">
					<input type="checkbox" id="cb-select-all-2">
				</td>
				<th scope="col" class="manage-column column-name column-primary">
					<?php esc_html_e( 'Agent', 'agentstructor' ); ?>
				</th>
				<th scope="col" class="manage-column column-description">
					<?php esc_html_e( 'Description', 'agentstructor' ); ?>
				</th>
				<th scope="col" class="manage-column column-mcp agentic-connector-col">
					<?php esc_html_e( 'MCP', 'agentstructor' ); ?>
				</th>
				<th scope="col" class="manage-column column-webmcp agentic-connector-col">
					<?php esc_html_e( 'WebMCP', 'agentstructor' ); ?>
				</th>
				<th scope="col" class="manage-column column-whatsapp agentic-connector-col">
					<?php esc_html_e( 'WhatsApp', 'agentstructor' ); ?>
				</th>
			</tr>
		</tfoot>
	</table>
	</div><!-- /.agentic-card -->

	<!-- Tablenav Bottom -->
	<div class="tablenav bottom">
		<div class="alignleft actions bulkactions">
			<label for="bulk-action-selector-bottom" class="screen-reader-text"><?php esc_html_e( 'Select bulk action', 'agentstructor' ); ?></label>
			<select name="bulk_action" id="bulk-action-selector-bottom">
				<option value="-1"><?php esc_html_e( 'Bulk actions', 'agentstructor' ); ?></option>
				<option value="activate"><?php esc_html_e( 'Activate', 'agentstructor' ); ?></option>
				<option value="deactivate"><?php esc_html_e( 'Deactivate', 'agentstructor' ); ?></option>
				<option value="delete"><?php esc_html_e( 'Delete', 'agentstructor' ); ?></option>
			</select>
			<input type="submit" id="doaction2" class="button action" value="<?php esc_attr_e( 'Apply', 'agentstructor' ); ?>" onclick="return agentic_confirm_bulk(this);">
		</div>
		<br class="clear">
	</div>

	</form><!-- end bulk-action-form -->

<?php endif; ?>
</div>

<?php
\Agentic\Inline_Assets::script(
	'screen-mode-toggle',
	array(
		'restUrl' => esc_url_raw( rest_url( 'agentic/v1/admin-page' ) ),
		'nonce'   => wp_create_nonce( 'wp_rest' ),
		'toggles' => array(
			array(
				'id'     => 'agentic-agents-mode-toggle',
				'screen' => 'agents',
			),
		),
	),
	'agenticScreenMode'
);

if ( $agentic_agents_advanced ) {
	\Agentic\Inline_Assets::script(
		'agents-list',
		array(
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			'hasUpdates' => class_exists( '\Agentic\Agent_Updates' ),
			'i18n'       => array(
				'selectAction'  => __( 'Please select a bulk action.', 'agentstructor' ),
				'selectAgent'   => __( 'Please select at least one agent.', 'agentstructor' ),
				'confirmDelete' => __( 'Are you sure you want to delete the selected agents?', 'agentstructor' ),
				'delete'        => __( 'Delete', 'agentstructor' ),
				'updating'      => __( 'Updating…', 'agentstructor' ),
				'updateFailed'  => __( 'Update failed: ', 'agentstructor' ),
				'unknownError'  => __( 'Unknown error.', 'agentstructor' ),
				'updateNow'     => __( 'update now', 'agentstructor' ),
				'networkError'  => __( 'Update failed due to a network error.', 'agentstructor' ),
			),
		),
		'agenticAgentsList',
		array( 'agentic-ui' )
	);
}
?>
