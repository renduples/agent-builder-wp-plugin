<?php
/**
 * Logs — unified Audit / Conversations / Security tab page.
 *
 * @package    Agent_Builder
 * @subpackage Admin
 * @since      2.10.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'You do not have permission to access this page.', 'siteloom' ) );
}

use Agentic\Audit_Log;

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab selection.
$agentic_logs_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'audit';
if ( ! in_array( $agentic_logs_tab, array( 'audit', 'conversations', 'security' ), true ) ) {
	$agentic_logs_tab = 'audit';
}

$agentic_logs_tabs = array(
	'audit'         => __( 'Audit', 'siteloom' ),
	'conversations' => __( 'Conversations', 'siteloom' ),
	'security'      => __( 'Security', 'siteloom' ),
);
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Activity', 'siteloom' ); ?></h1>

	<?php
	$agentic_logs_items = array();
	foreach ( $agentic_logs_tabs as $agentic_logs_slug => $agentic_logs_label ) {
		$agentic_logs_items[] = array(
			'slug'  => $agentic_logs_slug,
			'label' => $agentic_logs_label,
			'url'   => admin_url( 'admin.php?page=agentic-audit-log&tab=' . $agentic_logs_slug ),
		);
	}
	\Agentic\Admin_Vnav::open(
		array(
			'active'     => $agentic_logs_tab,
			'items'      => $agentic_logs_items,
			'aria_label' => __( 'Activity sections', 'siteloom' ),
			'id'         => 'agentic-logs-nav',
		)
	);

	if ( 'audit' === $agentic_logs_tab ) {
		include __DIR__ . '/audit.php';
	} elseif ( 'conversations' === $agentic_logs_tab ) {
		include __DIR__ . '/conversations.php';
	} else {
		include __DIR__ . '/security-log.php';
	}

	\Agentic\Admin_Vnav::close();
	?>
</div>
