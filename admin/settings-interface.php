<?php
/**
 * Interface settings tab — mounts the React panel.
 *
 * Enqueues the @wordpress/scripts build output (build/interface-settings.js)
 * using its generated dependency/version manifest, wires JS translations,
 * and renders the mount point the React app attaches to.
 *
 * Appearance (font/accent) is part of the same React card and saves via REST.
 *
 * @package    Agent_Builder
 * @subpackage Admin
 * @since      2.11.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'agent_builder_manage_settings' ) && ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'You do not have permission to access this page.', 'agent-builder' ) );
}

$agentic_if_asset_file = AGENT_BUILDER_DIR . 'build/interface-settings.asset.php';

if ( ! file_exists( $agentic_if_asset_file ) ) {
	echo '<div class="notice notice-error inline"><p>'
		. esc_html__( 'The Interface panel assets are missing. Run "npm install && npm run build" to generate them.', 'agent-builder' )
		. '</p></div>';
	return;
}

$agentic_if_asset = require $agentic_if_asset_file;

wp_enqueue_script(
	'agentic-interface-settings',
	AGENT_BUILDER_URL . 'build/interface-settings.js',
	$agentic_if_asset['dependencies'],
	$agentic_if_asset['version'],
	true
);

wp_enqueue_style( 'wp-components' );

wp_set_script_translations(
	'agentic-interface-settings',
	'agent-builder',
	AGENT_BUILDER_DIR . 'languages'
);
?>
<div id="agentic-interface-settings-root"></div>
