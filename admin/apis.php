<?php
/**
 * APIs Tab — manage third-party API keys used by agent tools.
 *
 * Included by admin/settings.php when the active tab is 'apis'.
 * The parent page has already verified manage_options capability.
 *
 * @package    Agent_Builder
 * @subpackage Admin
 * @since      2.2.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$agentic_api_saved = isset( $_GET['saved'] ) && '1' === $_GET['saved']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

// Registry of supported third-party APIs.
// Each entry describes the service, the option key that stores the API key,
// a validation pattern, and help links.  New APIs are added here.
$agentic_third_party_apis = array(
	'google_psi' => array(
		'name'        => 'Google PageSpeed Insights',
		'description' => 'Performance, accessibility, and SEO audits for any URL. Used by the Site Auditor and Site Doctor agents.',
		'option_key'  => 'agent_builder_psi_api_key',
		'pattern'     => '/^AIza[0-9A-Za-z_-]{30,}$/',
		'placeholder' => 'AIzaSy...',
		'endpoint'    => 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed',
		'key_url'     => 'https://console.cloud.google.com/apis/credentials',
		'docs_url'    => 'https://agentic-plugin.com/pagespeed-insights-api-key/',
		'free_tier'   => '25,000 queries / day (free)',
		'icon'        => 'dashicons-performance',
	),
);

?>

<p class="agentic-settings-lead"><?php esc_html_e( 'Some agent tools connect to external services that require their own API keys. Keys are stored in your WordPress database and are only sent to the service they belong to.', 'agent-builder' ); ?></p>

<?php if ( $agentic_api_saved ) : ?>
<div class="notice notice-success is-dismissible agentic-mt-12"><p>API key saved successfully.</p></div>
<?php endif; ?>

<table class="widefat striped agentic-mt-16 agentic-table-960">
	<thead>
		<tr>
			<th class="agentic-col-25">Service</th>
			<th>API Key</th>
			<th class="agentic-col-14">Free Tier</th>
			<th class="agentic-col-14">Used By</th>
			<th class="agentic-col-18">Actions</th>
		</tr>
	</thead>
	<tbody>
		<?php
		foreach ( $agentic_third_party_apis as $agentic_api_slug => $agentic_api_def ) :
			$agentic_stored_key = get_option( $agentic_api_def['option_key'], '' );
			$agentic_has_key    = ! empty( $agentic_stored_key );
			$agentic_masked_key = $agentic_has_key
				? substr( $agentic_stored_key, 0, 8 ) . str_repeat( '•', 20 ) . substr( $agentic_stored_key, -4 )
				: '';
			?>
		<tr>
			<td>
				<span class="dashicons <?php echo esc_attr( $agentic_api_def['icon'] ); ?>" style="vertical-align: middle; margin-right: 4px; color: #2271b1;"></span>
				<strong><?php echo esc_html( $agentic_api_def['name'] ); ?></strong>
				<br><small class="agentic-text-muted"><?php echo esc_html( $agentic_api_def['description'] ); ?></small>
			</td>
			<td>
				<?php if ( $agentic_has_key ) : ?>
					<code><?php echo esc_html( $agentic_masked_key ); ?></code>
					<br><span class="dashicons dashicons-yes-alt agentic-di-green agentic-di-md-va"></span>
					<small class="agentic-text-green">Connected</small>
				<?php else : ?>
					<span class="dashicons dashicons-warning agentic-di-amber agentic-di-md-va"></span>
					<small class="agentic-text-muted">Not configured — Core Web Vitals checks won't run until you add a key</small>
				<?php endif; ?>
			</td>
			<td><small><?php echo esc_html( $agentic_api_def['free_tier'] ); ?></small></td>
			<td><small>Site Auditor, Site Doctor</small></td>
			<td>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=agentic-settings&tab=apis&edit_api=' . rawurlencode( $agentic_api_slug ) ) ); ?>" class="button button-small">
					<?php echo esc_html( $agentic_has_key ? __( 'Edit Key', 'agent-builder' ) : __( 'Add Key', 'agent-builder' ) ); ?>
				</a>
				<?php if ( $agentic_has_key ) : ?>
				<form method="post" class="agentic-form-inline agentic-ml-8" data-agentic-confirm="<?php echo esc_attr( __( 'Remove this API key? Core Web Vitals checks will stop working until you add a new one.', 'agent-builder' ) ); ?>" data-agentic-confirm-danger>
					<?php wp_nonce_field( 'agentic_api_key_nonce' ); ?>
					<input type="hidden" name="agentic_api_action" value="delete">
					<input type="hidden" name="agentic_api_slug" value="<?php echo esc_attr( $agentic_api_slug ); ?>">
					<button type="submit" class="button button-small button-link-delete">Remove</button>
				</form>
				<?php endif; ?>
				<br>
				<small>
					<a href="<?php echo esc_url( $agentic_api_def['key_url'] ); ?>" target="_blank" rel="noopener">Get key &rarr;</a>
					<?php if ( ! empty( $agentic_api_def['docs_url'] ) ) : ?>
					&nbsp;|&nbsp;
					<a href="<?php echo esc_url( $agentic_api_def['docs_url'] ); ?>" target="_blank" rel="noopener">Setup guide</a>
					<?php endif; ?>
				</small>
			</td>
		</tr>
		<?php endforeach; ?>
	</tbody>
</table>

<?php
// Edit / Add form.
$agentic_edit_api_slug = isset( $_GET['edit_api'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	? sanitize_key( wp_unslash( $_GET['edit_api'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	: '';

if ( $agentic_edit_api_slug && isset( $agentic_third_party_apis[ $agentic_edit_api_slug ] ) ) :
	$agentic_edit_api = $agentic_third_party_apis[ $agentic_edit_api_slug ];
	$agentic_edit_key = get_option( $agentic_edit_api['option_key'], '' );
	?>
<div class="agentic-form-card-640">
	<div class="agentic-form-card-head">
		<h3 class="agentic-form-card-head h3">
			<span class="dashicons <?php echo esc_attr( $agentic_edit_api['icon'] ); ?>" style="vertical-align: middle; margin-right: 4px;"></span>
			<?php echo esc_html( $agentic_edit_api['name'] ); ?> — API Key
		</h3>
	</div>
	<div class="agentic-form-card-body">
		<form method="post" action="">
			<?php wp_nonce_field( 'agentic_api_key_nonce' ); ?>
			<input type="hidden" name="agentic_api_action" value="save">
			<input type="hidden" name="agentic_api_slug" value="<?php echo esc_attr( $agentic_edit_api_slug ); ?>">

			<table class="form-table agentic-table-mt-0">
				<tr>
					<th scope="row" class="agentic-col-140"><label for="agentic_api_key_input">API Key</label></th>
					<td>
						<input type="text" id="agentic_api_key_input" name="agentic_api_key_value"
							class="regular-text agentic-input-mono agentic-select-full"
							placeholder="<?php echo esc_attr( $agentic_edit_api['placeholder'] ); ?>"
							value="<?php echo esc_attr( $agentic_edit_key ); ?>" required>
						<p class="description">
							Paste your API key from
							<a href="<?php echo esc_url( $agentic_edit_api['key_url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $agentic_edit_api['name'] ); ?></a>.
							<?php if ( ! empty( $agentic_edit_api['docs_url'] ) ) : ?>
							Need help? <a href="<?php echo esc_url( $agentic_edit_api['docs_url'] ); ?>" target="_blank" rel="noopener">Step-by-step guide &rarr;</a>
							<?php endif; ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Endpoint</th>
					<td>
						<code><?php echo esc_html( $agentic_edit_api['endpoint'] ); ?></code>
						<p class="description">This is the external URL that agent tools connect to.</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Free Tier</th>
					<td><?php echo esc_html( $agentic_edit_api['free_tier'] ); ?></td>
				</tr>
			</table>

			<p class="submit agentic-mt-0">
				<button type="submit" class="button button-primary">Save API Key</button>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=agentic-settings&tab=apis' ) ); ?>" class="button agentic-ml-8">Cancel</a>
			</p>
		</form>
	</div>
</div>
<?php endif; ?>

<div class="agentic-api-info">
	<p>
		<?php esc_html_e( 'API keys you add here are available to all of your agents. As you enable tool integrations that need their own credentials, configure their keys on this page and your agents will use them automatically.', 'agent-builder' ); ?>
	</p>
</div>
