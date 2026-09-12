<?php
/**
 * Agentic Agent Deployment Page
 *
 * Unified management of all agent invocation methods: Scheduled Tasks,
 * Event Listeners, and Shortcodes. Each method is presented as a tab.
 *
 * @package    Agent_Builder
 * @subpackage Admin
 * @author     Agent Builder Team <support@agentic-plugin.com>
 * @license    GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 * @link       https://agentic-plugin.com
 * @since      1.7.0
 *
 * php version 8.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'agentic_manage_agents' ) ) {
	wp_die( esc_html__( 'You do not have permission to access this page.', 'agent-builder' ) );
}

$agentic_deploy_is_advanced = \Agentic\Admin_Menu_Handler::is_advanced_mode( 'deployment' );

// Classic PHP page — react-admin.css is normally only enqueued for the React
// admin surfaces, but this page reuses its .agentic-react-admin__page-head
// and .agentic-screen-mode-toggle classes so the Basic/Advanced switch sits
// in the exact same top-right spot as Tools/Skills/Approvals/Activity.
wp_enqueue_style( 'agentic-react-admin', AGENT_BUILDER_URL . 'assets/css/react-admin.css', array(), AGENT_BUILDER_VERSION );

// Determine active tab (tabs ordered alphabetically by label below).
$agentic_active_tab      = sanitize_text_field( wp_unslash( $_GET['tab'] ?? 'admin-bar' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab switch, not a form submission.
$agentic_deploy_allowed  = array( 'admin-bar', 'admin-ui', 'event-listeners', 'gutenberg-blocks', 'website', 'scheduled-tasks', 'shortcodes' );
if ( ! in_array( $agentic_active_tab, $agentic_deploy_allowed, true ) ) {
	$agentic_active_tab = 'admin-bar';
}
?>
<div class="wrap">
	<div class="agentic-react-admin__page-head">
		<div>
			<h1><?php esc_html_e( 'Publish', 'agent-builder' ); ?></h1>
			<p><?php esc_html_e( 'Manage how agents are invoked: embed them on your site with shortcodes, schedule recurring tasks, or react to WordPress events.', 'agent-builder' ); ?></p>
		</div>
		<span class="agentic-screen-mode-toggle" id="agentic-deployment-mode-toggle">
			<button type="button" class="button button-small<?php echo ! $agentic_deploy_is_advanced ? ' button-primary' : ''; ?>" data-mode="basic">
				<?php esc_html_e( 'Basic', 'agent-builder' ); ?>
			</button>
			<button type="button" class="button button-small<?php echo $agentic_deploy_is_advanced ? ' button-primary' : ''; ?>" data-mode="advanced">
				<?php esc_html_e( 'Advanced', 'agent-builder' ); ?>
			</button>
		</span>
	</div>

	<?php if ( ! $agentic_deploy_is_advanced ) : ?>

		<?php include AGENT_BUILDER_DIR . 'admin/deployment/deployment-basic.php'; ?>

	<?php else : ?>

	<?php
	// Labels A–Z for nav order.
	$agentic_deploy_tabs  = array(
		'admin-bar'        => __( 'Admin', 'agent-builder' ),
		'admin-ui'         => __( 'Editor', 'agent-builder' ),
		'event-listeners'  => __( 'Event Listeners', 'agent-builder' ),
		'website'          => __( 'Frontend Modal', 'agent-builder' ),
		'gutenberg-blocks' => __( 'Gutenberg Blocks', 'agent-builder' ),
		'scheduled-tasks'  => __( 'Scheduled Tasks', 'agent-builder' ),
		'shortcodes'       => __( 'Shortcodes', 'agent-builder' ),
	);
	$agentic_deploy_items = array();
	foreach ( $agentic_deploy_tabs as $agentic_tab_slug => $agentic_tab_label ) {
		$agentic_deploy_items[] = array(
			'slug'  => $agentic_tab_slug,
			'label' => $agentic_tab_label,
			'url'   => admin_url( 'admin.php?page=agentic-deployment&tab=' . $agentic_tab_slug ),
		);
	}
	$agentic_panel_title = $agentic_deploy_tabs[ $agentic_active_tab ] ?? __( 'Deployment', 'agent-builder' );

	\Agentic\Admin_Vnav::open(
		array(
			'active'     => $agentic_active_tab,
			'items'      => $agentic_deploy_items,
			'aria_label' => __( 'Deployment methods', 'agent-builder' ),
			'id'         => 'agentic-deployment-nav',
		)
	);
	?>

	<div class="agentic-settings-panel agentic-deployment-panel">
		<div class="agentic-settings-panel__header">
			<h2 class="agentic-settings-panel__title"><?php echo esc_html( $agentic_panel_title ); ?></h2>
		</div>
		<div class="agentic-settings-panel__body">
	<?php
	switch ( $agentic_active_tab ) {
		case 'scheduled-tasks':
			include AGENT_BUILDER_DIR . 'admin/deployment/deployment-scheduled-tasks.php';
			break;

		case 'event-listeners':
			include AGENT_BUILDER_DIR . 'admin/deployment/deployment-event-listeners.php';
			break;

		case 'gutenberg-blocks':
			include AGENT_BUILDER_DIR . 'admin/deployment/deployment-gutenberg-blocks.php';
			break;

		case 'admin-ui':
			include AGENT_BUILDER_DIR . 'admin/deployment/deployment-admin-ui.php';
			break;

		case 'website':
			include AGENT_BUILDER_DIR . 'admin/deployment/deployment-modal.php';
			break;

		case 'admin-bar':
			include AGENT_BUILDER_DIR . 'admin/deployment/deployment-admin-bar.php';
			break;

		case 'shortcodes':
		default:
			include AGENT_BUILDER_DIR . 'admin/deployment/deployment-shortcodes.php';
			break;
	}
	?>
		</div><!-- .agentic-settings-panel__body -->
	</div><!-- .agentic-settings-panel -->

	<?php
	\Agentic\Admin_Vnav::close();
	?>

	<?php endif; ?>

</div>

<script>
( function () {
	'use strict';
	var toggle = document.getElementById( 'agentic-deployment-mode-toggle' );
	if ( ! toggle ) {
		return;
	}
	Array.prototype.forEach.call( toggle.querySelectorAll( 'button' ), function ( btn ) {
		btn.addEventListener( 'click', function () {
			if ( btn.disabled ) {
				return;
			}
			Array.prototype.forEach.call( toggle.querySelectorAll( 'button' ), function ( b ) {
				b.disabled = true;
			} );
			fetch( <?php echo wp_json_encode( esc_url_raw( rest_url( 'agentic/v1/admin-page' ) ) ); ?>, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>
				},
				body: JSON.stringify( {
					action_name: 'set_screen_mode',
					screen: 'deployment',
					mode: btn.getAttribute( 'data-mode' )
				} )
			} ).then( function () {
				window.location.reload();
			} );
		} );
	} );
} )();
</script>
