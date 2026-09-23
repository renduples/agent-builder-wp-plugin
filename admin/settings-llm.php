<?php
/**
 * Agentic Settings — LLM Tab
 *
 * Cost alert thresholds and per-model token pricing.
 *
 * @package    Agent_Builder
 * @subpackage Admin
 * @since      2.8.8
 *
 * php version 8.1
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Agentic\LLM_Client;
use Agentic\Provider_Registry;

// ── Form handlers (before output) ───────────────────────────────────────────

$agentic_llm_notice = '';

if ( class_exists( '\Agentic\Costs_Manager' ) && isset( $_POST['agentic_save_alerts_nonce'] ) ) {
	if ( ! wp_verify_nonce( sanitize_key( $_POST['agentic_save_alerts_nonce'] ), 'agentic_save_alerts' ) ) {
		wp_die( esc_html__( 'Security check failed.', 'agent-builder' ) );
	}
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized inside save_alerts().
	Costs_Manager::save_alerts( wp_unslash( $_POST ) );
	$agentic_llm_notice = __( 'Alert settings saved.', 'agent-builder' );
	\Agentic\Security_Log::log_system(
		'settings_changed',
		'costs',
		array(
			'setting' => 'alert_settings',
			'changes' => array( 'updated' => true ),
		)
	);
}

// ── Load data ───────────────────────────────────────────────────────────────

$agentic_alerts                = class_exists( '\Agentic\Costs_Manager' ) ? \Agentic\Costs_Manager::get_alerts() : array();
$agentic_all_providers         = Provider_Registry::get_all();
$agentic_active_slugs          = array_map( static fn( $p ) => $p['slug'], Provider_Registry::get_active() );
$agent_builder_pricing_version = get_option( 'agent_builder_pricing_version', '' );
$agentic_hosted_credits        = ( 'agentic' === get_option( 'agent_builder_llm_provider', '' ) )
	? LLM_Client::get_last_hosted_credits()
	: array();

?>

<p class="agentic-llm-desc">
	<?php esc_html_e( 'Pricing on this page are for information purposes only and provided to assist you with cost management and reports.', 'agent-builder' ); ?>
	<br><?php esc_html_e( 'They do not affect your billing with any LLM provider. Your actual charges are determined by each provider based on their own pricing and your usage.', 'agent-builder' ); ?>
</p>

<?php if ( ! empty( $agentic_llm_notice ) ) : ?>
<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $agentic_llm_notice ); ?></p></div>
<?php endif; ?>
<div id="agentic-llm-update-notice" style="display:none;" class="notice notice-success is-dismissible"><p></p></div>

<div class="agentic-llm-grid">

<?php if ( class_exists( '\Agentic\Costs_Manager' ) ) : ?>
	<!-- Cost Alerts -->
	<div class="agentic-llm-section">
		<h2><?php esc_html_e( 'Cost Alerts', 'agent-builder' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Get email alerts when costs exceed thresholds. Set to 0 to disable.', 'agent-builder' ); ?>
		</p>

		<form method="post" action="">
			<?php wp_nonce_field( 'agentic_save_alerts', 'agentic_save_alerts_nonce' ); ?>
			<input type="hidden" name="enabled" value="1">

			<div class="agentic-llm-alert-field">
				<label for="agentic-alert-email"><?php esc_html_e( 'Alert email', 'agent-builder' ); ?></label>
				<input type="email" id="agentic-alert-email" name="email"
					value="<?php echo esc_attr( $agentic_alerts['email'] ); ?>">
			</div>

			<div class="agentic-llm-alert-field">
				<label><?php esc_html_e( 'Thresholds (USD)', 'agent-builder' ); ?></label>

				<div class="agentic-llm-threshold-row">
					<span class="label"><?php esc_html_e( 'Daily', 'agent-builder' ); ?></span>
					<span>$</span>
					<input type="number" name="daily_threshold" min="0" step="0.01"
						value="<?php echo esc_attr( number_format( $agentic_alerts['daily_threshold'], 2, '.', '' ) ); ?>">
				</div>
				<div class="agentic-llm-threshold-row">
					<span class="label"><?php esc_html_e( 'Weekly', 'agent-builder' ); ?></span>
					<span>$</span>
					<input type="number" name="weekly_threshold" min="0" step="0.01"
						value="<?php echo esc_attr( number_format( $agentic_alerts['weekly_threshold'], 2, '.', '' ) ); ?>">
				</div>
				<div class="agentic-llm-threshold-row">
					<span class="label"><?php esc_html_e( 'Monthly', 'agent-builder' ); ?></span>
					<span>$</span>
					<input type="number" name="monthly_threshold" min="0" step="0.01"
						value="<?php echo esc_attr( number_format( $agentic_alerts['monthly_threshold'], 2, '.', '' ) ); ?>">
				</div>
			</div>

			<button type="submit" class="button button-primary">
				<?php esc_html_e( 'Save Alert Settings', 'agent-builder' ); ?>
			</button>
		</form>
	</div>
<?php endif; ?>

<?php if ( isset( $agentic_hosted_credits['remaining'] ) ) : ?>
	<!-- Hosted Credits -->
	<div class="agentic-llm-section">
		<h2><?php esc_html_e( 'Hosted Credits', 'agent-builder' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Balance reported by the Agentic AI hosted connection as of your last chat request.', 'agent-builder' ); ?>
		</p>
		<p>
			<strong><?php esc_html_e( 'Remaining:', 'agent-builder' ); ?></strong>
			<?php echo esc_html( number_format( (float) $agentic_hosted_credits['remaining'], 2 ) ); ?>
			<?php if ( isset( $agentic_hosted_credits['used'] ) && null !== $agentic_hosted_credits['used'] ) : ?>
				&nbsp;·&nbsp;<strong><?php esc_html_e( 'Last call used:', 'agent-builder' ); ?></strong>
				<?php echo esc_html( number_format( (float) $agentic_hosted_credits['used'], 2 ) ); ?>
			<?php endif; ?>
		</p>
		<?php if ( ! empty( $agentic_hosted_credits['checked_at'] ) ) : ?>
			<p class="description">
				<?php
				printf(
					/* translators: %s: human-readable time difference, e.g. "5 minutes" */
					esc_html__( 'Last updated %s ago.', 'agent-builder' ),
					esc_html( human_time_diff( (int) $agentic_hosted_credits['checked_at'] ) )
				);
				?>
			</p>
		<?php endif; ?>
	</div>
<?php endif; ?>

	<!-- LLM Model Pricing -->
	<div class="agentic-llm-section">
		<h2><?php esc_html_e( 'LLM Model Pricing', 'agent-builder' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Token rates (USD per 1,000,000 tokens) used to estimate costs. Rates are per model.', 'agent-builder' ); ?>
			<?php if ( ! empty( $agent_builder_pricing_version ) ) : ?>
				<br><?php /* translators: %s: date when pricing was last updated */ printf( esc_html__( 'Pricing last updated: %s', 'agent-builder' ), esc_html( $agent_builder_pricing_version ) ); ?>
			<?php endif; ?>
		</p>

		<div class="agentic-mb-12">
			<button type="button" id="agentic-update-pricing-btn" class="button">
				<?php esc_html_e( 'Get Latest Pricing', 'agent-builder' ); ?>
			</button>
		</div>

		<table>
			<thead>
				<tr>
					<th><?php esc_html_e( 'Model', 'agent-builder' ); ?></th>
					<th class="col-right"><?php esc_html_e( 'Input $/1M', 'agent-builder' ); ?></th>
					<th class="col-right"><?php esc_html_e( 'Output $/1M', 'agent-builder' ); ?></th>
					<th class="agentic-td-center"><?php esc_html_e( 'Status', 'agent-builder' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $agentic_all_providers as $agentic_prov ) : ?>
					<?php
					$agentic_prov_slug    = $agentic_prov['slug'];
					$agentic_prov_pricing = $agentic_prov['model_pricing'] ?? array();
					$agentic_prov_active  = in_array( $agentic_prov_slug, $agentic_active_slugs, true );
					// Use the models list if available, otherwise fall back to model_pricing keys.
					$agentic_prov_models = ! empty( $agentic_prov['models'] ) ? $agentic_prov['models'] : array_keys( $agentic_prov_pricing );
					if ( empty( $agentic_prov_models ) ) {
						continue;
					}
					$agentic_prov_icon = agent_builder_setup_provider_icon( $agentic_prov['icon'] ?? $agentic_prov_slug, 16 );
					?>
					<tr class="provider-header">
						<td colspan="4">
							<?php
							echo wp_kses(
								$agentic_prov_icon,
								array(
									'svg'    => array(
										'xmlns'       => array(),
										'width'       => array(),
										'height'      => array(),
										'viewBox'     => array(),
										'fill'        => array(),
										'role'        => array(),
										'aria-label'  => array(),
										'xmlns:xlink' => array(),
									),
									'path'   => array(
										'd'               => array(),
										'fill'            => array(),
										'stroke'          => array(),
										'stroke-width'    => array(),
										'stroke-linecap'  => array(),
										'stroke-linejoin' => array(),
									),
									'rect'   => array(
										'width'  => array(),
										'height' => array(),
										'rx'     => array(),
										'fill'   => array(),
										'x'      => array(),
										'y'      => array(),
									),
									'circle' => array(
										'cx'           => array(),
										'cy'           => array(),
										'r'            => array(),
										'fill'         => array(),
										'stroke'       => array(),
										'stroke-width' => array(),
									),
									'text'   => array(
										'x'           => array(),
										'y'           => array(),
										'font-size'   => array(),
										'font-weight' => array(),
										'fill'        => array(),
										'font-family' => array(),
										'dominant-baseline' => array(),
										'text-anchor' => array(),
									),
									'img'    => array(
										'src'    => array(),
										'width'  => array(),
										'height' => array(),
										'style'  => array(),
										'alt'    => array(),
									),
								)
							);
							?>
							&nbsp;<?php echo esc_html( $agentic_prov['name'] ); ?>
						</td>
					</tr>
					<?php foreach ( $agentic_prov_models as $agent_builder_model ) : ?>
						<?php
						$agentic_m_in       = (float) ( $agentic_prov_pricing[ $agent_builder_model ]['in'] ?? 0 );
						$agentic_m_out      = (float) ( $agentic_prov_pricing[ $agent_builder_model ]['out'] ?? 0 );
						$agentic_no_pricing = empty( $agentic_prov_pricing );
						?>
						<tr class="model-row">
							<td><?php echo esc_html( $agent_builder_model ); ?></td>
							<td class="col-right"><?php echo $agentic_no_pricing ? '<span class="agentic-text-dim">—</span>' : '$' . esc_html( number_format( $agentic_m_in, 4 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
							<td class="col-right"><?php echo $agentic_no_pricing ? '<span class="agentic-text-dim">—</span>' : '$' . esc_html( number_format( $agentic_m_out, 4 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
							<td class="agentic-td-center">
								<?php if ( $agentic_prov_active ) : ?>
									<span class="agentic-status-active agentic-text-xxs">&#9679; <?php esc_html_e( 'Active', 'agent-builder' ); ?></span>
								<?php else : ?>
									<span class="agentic-text-dim agentic-text-xxs">&#9675; <?php esc_html_e( 'Inactive', 'agent-builder' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>

</div>

<p class="agentic-llm-disclaimer"><?php /* translators: %s: link to Terms of Service */ printf( esc_html__( 'Agent Builder is not responsible for charges on your AI provider accounts. By using this plugin you agree to the %s.', 'agent-builder' ), '<a href="https://agentic-plugin.com/terms-of-service/" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Terms of Service', 'agent-builder' ) . '</a>' ); ?></p>

<script>
(function() {
	var btn = document.getElementById('agentic-update-pricing-btn');
	if (!btn) return;

	btn.addEventListener('click', function() {
		btn.disabled = true;
		btn.textContent = <?php echo wp_json_encode( __( 'Updating…', 'agent-builder' ) ); ?>;

		var fd = new FormData();
		fd.append('action', 'agentic_update_model_pricing');
		fd.append('nonce', <?php echo wp_json_encode( wp_create_nonce( 'agentic_update_model_pricing' ) ); ?>);

		fetch(<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, {
			method: 'POST',
			body: fd,
			credentials: 'same-origin'
		})
		.then(function(r) { return r.json(); })
		.then(function(res) {
			var notice = document.getElementById('agentic-llm-update-notice');
			if (res.success) {
				notice.className = 'notice notice-success is-dismissible';
				notice.querySelector('p').textContent = res.data.message || 'Pricing updated.';
				notice.style.display = '';
				setTimeout(function() { location.reload(); }, 1200);
			} else {
				notice.className = 'notice notice-error is-dismissible';
				notice.querySelector('p').textContent = res.data || 'Failed to update pricing.';
				notice.style.display = '';
				btn.disabled = false;
				btn.textContent = <?php echo wp_json_encode( __( 'Get Latest Pricing', 'agent-builder' ) ); ?>;
			}
		})
		.catch(function() {
			btn.disabled = false;
			btn.textContent = <?php echo wp_json_encode( __( 'Get Latest Pricing', 'agent-builder' ) ); ?>;
		});
	});
})();
</script>
