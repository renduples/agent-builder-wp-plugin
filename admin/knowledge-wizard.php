<?php
/**
 * Knowledge Wizard page (guided "Add knowledge" flow).
 *
 * Hidden flow page (no nav entry) reachable at admin.php?page=agentic-knowledge-wizard.
 * It enqueues the React island built from src/knowledge-wizard and provides the mount
 * node. All form data and submission go through the agentic/v1/knowledge-wizard REST
 * routes (see includes/class-knowledge-wizard-rest.php).
 *
 * @package Agent_Builder
 *
 * php version 8.1
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'agent_builder_manage_settings' ) && ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'You do not have permission to access this page.', 'agent-builder' ) );
}

$agentic_kw_asset_file = AGENT_BUILDER_DIR . 'build/knowledge-wizard.asset.php';
if ( file_exists( $agentic_kw_asset_file ) ) {
	$agentic_kw_asset = require $agentic_kw_asset_file;

	wp_enqueue_script(
		'agentic-knowledge-wizard',
		AGENT_BUILDER_URL . 'build/knowledge-wizard.js',
		$agentic_kw_asset['dependencies'],
		$agentic_kw_asset['version'],
		true
	);
	wp_enqueue_style( 'wp-components' );
	wp_set_script_translations( 'agentic-knowledge-wizard', 'agent-builder', AGENT_BUILDER_DIR . 'languages' );

	wp_localize_script(
		'agentic-knowledge-wizard',
		'agenticKnowledgeWizard',
		array(
			'restUrl'      => rest_url( 'agentic/v1/' ),
			'nonce'        => wp_create_nonce( 'wp_rest' ),
			'knowledgeUrl' => admin_url( 'admin.php?page=agentic-train-data' ),
			'dashboardUrl' => admin_url( 'admin.php?page=agent-builder' ),
		)
	);
}
?>
<div class="wrap agentic-knowledge-wizard-wrap">
	<h1><?php esc_html_e( 'Add Knowledge', 'agent-builder' ); ?></h1>
	<p class="agentic-wizard-intro">
		<?php esc_html_e( 'Teach your agents a fact, policy, or FAQ in a few quick steps. You can add more or fine-tune it later from the Knowledge page.', 'agent-builder' ); ?>
	</p>

	<div id="agentic-knowledge-wizard-root">
		<p><?php esc_html_e( 'Loading the knowledge wizard…', 'agent-builder' ); ?></p>
	</div>

	<noscript>
		<div class="notice notice-warning">
			<p><?php esc_html_e( 'The knowledge wizard needs JavaScript enabled. You can also add knowledge from the Knowledge page.', 'agent-builder' ); ?></p>
		</div>
	</noscript>
</div>
