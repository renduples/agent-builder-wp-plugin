<?php
/**
 * Agent Creation Wizard page (the "Train an Agent" guided flow).
 *
 * Hidden flow page (no nav entry) reachable at admin.php?page=agentic-agent-wizard.
 * It enqueues the React island built from src/agent-wizard and provides the mount
 * node. All form data and submission go through the agentic/v1/agent-wizard REST
 * routes (see includes/class-agent-wizard-rest.php).
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

$agentic_aw_asset_file = AGENT_BUILDER_DIR . 'build/agent-wizard.asset.php';
if ( file_exists( $agentic_aw_asset_file ) ) {
	$agentic_aw_asset = require $agentic_aw_asset_file;

	wp_enqueue_script(
		'agentic-agent-wizard',
		AGENT_BUILDER_URL . 'build/agent-wizard.js',
		$agentic_aw_asset['dependencies'],
		$agentic_aw_asset['version'],
		true
	);
	wp_enqueue_style( 'wp-components' );
	wp_set_script_translations( 'agentic-agent-wizard', 'agentstructor', AGENT_BUILDER_DIR . 'languages' );

	wp_localize_script(
		'agentic-agent-wizard',
		'agenticWizardRag',
		array(
			'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
			'nonce'        => wp_create_nonce( 'agentic_train_data' ),
			'hasLicense'   => false,
			'knowledgeUrl' => admin_url( 'admin.php?page=agentic-train-data' ),
		)
	);
}
?>
<div class="wrap agentic-agent-wizard-wrap">
	<h1><?php esc_html_e( 'Train an Agent', 'agentstructor' ); ?></h1>
	<p class="agentic-wizard-intro">
		<?php esc_html_e( 'Create a new AI agent in a few quick steps. You can refine everything later from the Agents and Settings pages.', 'agentstructor' ); ?>
	</p>

	<div id="agentic-agent-wizard-root">
		<p><?php esc_html_e( 'Loading the agent wizard…', 'agentstructor' ); ?></p>
	</div>

	<noscript>
		<div class="notice notice-warning">
			<p><?php esc_html_e( 'The agent wizard needs JavaScript enabled. You can also create agents from the Agents page.', 'agentstructor' ); ?></p>
		</div>
	</noscript>
</div>
