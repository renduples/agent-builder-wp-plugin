<?php
/**
 * Publish Wizard page (guided "get your agent in front of people" flow).
 *
 * Hidden flow page (no nav entry) reachable at admin.php?page=agentic-deploy-wizard.
 * It enqueues the React island built from src/deploy-wizard and provides the mount
 * node. All form data and submission go through the agentic/v1/deploy-wizard REST
 * routes (see includes/class-deploy-wizard-rest.php).
 *
 * @package Agent_Builder
 *
 * php version 8.1
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'You do not have permission to access this page.', 'agentstructor' ) );
}

$agentic_dw_asset_file = AGENT_BUILDER_DIR . 'build/deploy-wizard.asset.php';
if ( file_exists( $agentic_dw_asset_file ) ) {
	$agentic_dw_asset = require $agentic_dw_asset_file;

	wp_enqueue_script(
		'agentic-deploy-wizard',
		AGENT_BUILDER_URL . 'build/deploy-wizard.js',
		$agentic_dw_asset['dependencies'],
		$agentic_dw_asset['version'],
		true
	);
	wp_enqueue_style( 'wp-components' );
	wp_set_script_translations( 'agentic-deploy-wizard', 'agentstructor', AGENT_BUILDER_DIR . 'languages' );

	wp_localize_script(
		'agentic-deploy-wizard',
		'agenticDeployWizard',
		array(
			'restUrl'      => rest_url( 'agentic/v1/' ),
			'nonce'        => wp_create_nonce( 'wp_rest' ),
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only preselect, no state change.
			'presetAgent'  => isset( $_GET['agent'] ) ? sanitize_key( wp_unslash( $_GET['agent'] ) ) : '',
			'agentsUrl'    => admin_url( 'admin.php?page=agentic-agents' ),
			'dashboardUrl' => admin_url( 'admin.php?page=agent-builder' ),
		)
	);
}
?>
<div class="wrap agentic-deploy-wizard-wrap">
	<h1><?php esc_html_e( 'Publish Your Agent', 'agentstructor' ); ?></h1>
	<p class="agentic-wizard-intro">
		<?php esc_html_e( 'Get an agent in front of people in a few quick steps. You can fine-tune it later from Publish.', 'agentstructor' ); ?>
	</p>

	<div id="agentic-deploy-wizard-root">
		<p><?php esc_html_e( 'Loading the publish wizard…', 'agentstructor' ); ?></p>
	</div>

	<noscript>
		<div class="notice notice-warning">
			<p><?php esc_html_e( 'The publish wizard needs JavaScript enabled. You can also publish agents from the Publish page.', 'agentstructor' ); ?></p>
		</div>
	</noscript>
</div>
