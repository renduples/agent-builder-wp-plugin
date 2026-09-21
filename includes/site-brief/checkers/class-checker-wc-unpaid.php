<?php
/**
 * Site Brief checker: unpaid WooCommerce orders.
 *
 * @package    Agent_Builder
 * @subpackage Site_Brief
 * @since      3.4.2
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic\Site_Brief;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Card for pending/unpaid orders. No PII on the card; no write in v1.
 */
class Checker_Wc_Unpaid extends Site_Brief_Checker {

	/**
	 * {@inheritdoc}
	 */
	public function get_id(): string {
		return 'wc_unpaid';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_tools(): array {
		return array( 'wc_get_orders' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_agent(): string {
		return 'storefront-assistant';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_severity(): int {
		return 55;
	}

	/**
	 * WooCommerce family — skip the whole checker when Woo is inactive.
	 *
	 * @return bool
	 */
	public function is_applicable(): bool {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function run( Site_Brief_Runner $runner ): array {
		$result = $runner->observe_tool(
			'wc_get_orders',
			array(
				'status'   => 'pending',
				'per_page' => 20,
				'page'     => 1,
			)
		);
		if ( isset( $result['error'] ) ) {
			return array();
		}

		$total = (int) ( $result['total'] ?? 0 );
		if ( $total < 1 ) {
			return array();
		}

		$ids = array();
		foreach ( array_slice( (array) ( $result['orders'] ?? array() ), 0, 8 ) as $order ) {
			$ids[] = (string) ( $order['number'] ?? $order['id'] ?? '' );
		}
		$ids = array_values( array_filter( $ids ) );

		$orders_url = admin_url( 'edit.php?post_type=shop_order&post_status=wc-pending' );
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$orders_url = admin_url( 'admin.php?page=wc-orders&status=pending' );
		}

		return array(
			$this->card(
				array(
					'title'            => sprintf(
						/* translators: %d: unpaid order count. */
						_n( '%d unpaid order', '%d unpaid orders', $total, 'agent-builder' ),
						$total
					),
					'evidence'         => sprintf(
						/* translators: %s: order numbers. */
						__( 'Order numbers: %s. Customer emails are not shown.', 'agent-builder' ),
						implode( ', ', $ids )
					),
					'evidence_payload' => array(
						'total'  => $total,
						'orders' => $ids,
					),
					'proposed_action'  => __( 'Open WooCommerce orders. Site Brief will not change order status or expose customer email.', 'agent-builder' ),
					'action_risk'      => 'none',
					'raw'              => array( 'total' => $total ),
				)
			),
		);
	}
}
