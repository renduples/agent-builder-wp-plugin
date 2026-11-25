<?php
/**
 * Settings — Endpoints Tab
 *
 * Displays all Agentic service base URLs and allows administrators to
 * override any endpoint (e.g. point to a private proxy or staging server).
 * Saving an empty value or the default URL resets the entry to its built-in default.
 *
 * @package    Agent_Builder
 * @subpackage Admin
 * @license    GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 * @since      2.9.109
 *
 * php version 8.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'You do not have permission to access this page.', 'agentstructor' ) );
}

// ── Handle form submission ────────────────────────────────────────────────────

$agentic_ep_saved  = false;
$agentic_ep_errors = array();

if (
	isset( $_POST['agentic_save_endpoints'] ) &&
	check_admin_referer( 'agentic_endpoints_nonce' )
) {
	$agentic_ep_slugs = \Agentic\Service_Registry::get_slugs();

	foreach ( $agentic_ep_slugs as $agentic_ep_slug ) {
		$agentic_ep_field = 'endpoint_' . str_replace( '-', '_', $agentic_ep_slug );
		$agentic_ep_url   = sanitize_text_field( wp_unslash( $_POST[ $agentic_ep_field ] ?? '' ) );

		if ( ! empty( $agentic_ep_url ) && ! filter_var( $agentic_ep_url, FILTER_VALIDATE_URL ) ) {
			$agentic_ep_errors[] = sprintf(
				/* translators: %s: service slug */
				esc_html__( 'Invalid URL for %s — changes not saved.', 'agentstructor' ),
				esc_html( $agentic_ep_slug )
			);
			continue;
		}

		\Agentic\Service_Registry::update( $agentic_ep_slug, $agentic_ep_url );
	}

	if ( empty( $agentic_ep_errors ) ) {
		$agentic_ep_saved = true;
		\Agentic\Security_Log::log_system(
			'settings_changed',
			'endpoints',
			array( 'action' => 'endpoints_saved' )
		);
	}

	wp_safe_redirect(
		add_query_arg(
			array(
				'page'  => 'agentic-settings',
				'tab'   => 'endpoints',
				'saved' => '1',
			),
			admin_url( 'admin.php' )
		)
	);
	exit;
}

// ── Handle individual reset ───────────────────────────────────────────────────

if (
	isset( $_GET['reset_endpoint'] ) &&
	check_admin_referer( 'agentic_reset_endpoint' )
) {
	$agentic_reset_slug = sanitize_key( wp_unslash( $_GET['reset_endpoint'] ) );
	\Agentic\Service_Registry::reset( $agentic_reset_slug );
	wp_safe_redirect(
		add_query_arg(
			array(
				'page'  => 'agentic-settings',
				'tab'   => 'endpoints',
				'reset' => '1',
			),
			admin_url( 'admin.php' )
		)
	);
	exit;
}

// ── Render ────────────────────────────────────────────────────────────────────

$agentic_ep_services = \Agentic\Service_Registry::get_all();

if ( isset( $_GET['saved'] ) ) : ?>
	<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Endpoints saved.', 'agentstructor' ); ?></p></div>
<?php endif; ?>

<?php if ( isset( $_GET['reset'] ) ) : ?>
	<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Endpoint reset to default.', 'agentstructor' ); ?></p></div>
<?php endif; ?>

<?php foreach ( $agentic_ep_errors as $agentic_ep_error ) : ?>
	<div class="notice notice-error is-dismissible"><p><?php echo esc_html( $agentic_ep_error ); ?></p></div>
<?php endforeach; ?>

<div class="agentic-endpoints-intro" style="max-width:680px;margin:16px 0 24px;">
	<p>
		<?php esc_html_e( 'These are the base URLs for all Agentic remote services. By default they point to the official Agentic infrastructure. You can override any URL to route traffic through a proxy, use a staging server, or self-host a compatible service.', 'agentstructor' ); ?>
	</p>
	<p>
		<?php esc_html_e( 'Clearing an overridden URL (or saving an empty field) resets it to the built-in default. The Reset link next to each row also restores the default immediately.', 'agentstructor' ); ?>
	</p>
</div>

<form method="post" action="">
	<?php wp_nonce_field( 'agentic_endpoints_nonce' ); ?>

	<table class="wp-list-table widefat fixed striped" style="max-width:960px;">
		<thead>
			<tr>
				<th style="width:200px;"><?php esc_html_e( 'Service', 'agentstructor' ); ?></th>
				<th><?php esc_html_e( 'Base URL', 'agentstructor' ); ?></th>
				<th style="width:80px;"><?php esc_html_e( 'Actions', 'agentstructor' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( $agentic_ep_services as $agentic_ep_svc ) : ?>
			<?php
			$agentic_ep_field  = 'endpoint_' . str_replace( '-', '_', $agentic_ep_svc['slug'] );
			$agentic_ep_is_mod = $agentic_ep_svc['is_custom'];
			$agentic_reset_url = wp_nonce_url(
				add_query_arg(
					array(
						'page'           => 'agentic-settings',
						'tab'            => 'endpoints',
						'reset_endpoint' => $agentic_ep_svc['slug'],
					),
					admin_url( 'admin.php' )
				),
				'agentic_reset_endpoint'
			);
			?>
			<tr>
				<td>
					<strong><?php echo esc_html( $agentic_ep_svc['name'] ); ?></strong>
					<?php if ( $agentic_ep_is_mod ) : ?>
						<span style="display:inline-block;background:#2271b1;color:#fff;font-size:10px;padding:1px 5px;border-radius:3px;margin-left:4px;"><?php esc_html_e( 'Custom', 'agentstructor' ); ?></span>
					<?php endif; ?>
					<br><small style="color:#646970;"><?php echo esc_html( $agentic_ep_svc['description'] ); ?></small>
					<?php if ( $agentic_ep_is_mod ) : ?>
						<br><small style="color:#646970;">
							<?php esc_html_e( 'Default:', 'agentstructor' ); ?>
							<code><?php echo esc_html( $agentic_ep_svc['default_url'] ); ?></code>
						</small>
					<?php endif; ?>
				</td>
				<td>
					<input
						type="url"
						name="<?php echo esc_attr( $agentic_ep_field ); ?>"
						value="<?php echo esc_attr( $agentic_ep_svc['url'] ); ?>"
						placeholder="<?php echo esc_attr( $agentic_ep_svc['default_url'] ); ?>"
						class="regular-text"
						style="width:100%;"
					/>
				</td>
				<td>
					<?php if ( $agentic_ep_is_mod ) : ?>
						<a href="<?php echo esc_url( $agentic_reset_url ); ?>"
							data-agentic-confirm="<?php echo esc_attr( __( 'Reset this endpoint to its default URL?', 'agentstructor' ) ); ?>"
							style="color:#b32d2e;">
							<?php esc_html_e( 'Reset', 'agentstructor' ); ?>
						</a>
					<?php else : ?>
						<span style="color:#c3c4c7;"><?php esc_html_e( 'Default', 'agentstructor' ); ?></span>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	<p class="submit" style="margin-top:16px;">
		<input type="submit" name="agentic_save_endpoints" class="button-primary" value="<?php esc_attr_e( 'Save Endpoints', 'agentstructor' ); ?>" />
	</p>
</form>
