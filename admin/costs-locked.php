<?php
/**
 * Locked Usage & Costs page (free plugin).
 *
 * Honest explanation of the Pro feature — not a stub of the real costs UI.
 *
 * @package Agent_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$agentic_promo_url   = 'https://agentic-plugin.com/pricing/';
$agentic_promo_label = __( 'Upgrade to Pro', 'agent-builder' );
?>
<div class="wrap agentic-admin">
	<h1>
		<?php esc_html_e( 'Usage & Costs', 'agent-builder' ); ?>
		<span class="agentic-badge-pill-grey"><?php esc_html_e( 'Pro', 'agent-builder' ); ?></span>
	</h1>
	<p class="agentic-subtitle">
		<?php esc_html_e( 'Token usage, estimated spend, and per-provider cost history are available in Agent Builder Pro.', 'agent-builder' ); ?>
	</p>
	<p>
		<?php esc_html_e( 'This free plugin does not include a usage meter. There is nothing to load here until Pro is installed — this page is only an explanation.', 'agent-builder' ); ?>
	</p>
	<p>
		<a href="<?php echo esc_url( $agentic_promo_url ); ?>" target="_blank" rel="noopener noreferrer">
			<?php echo esc_html( $agentic_promo_label ); ?>
		</a>
	</p>
</div>
