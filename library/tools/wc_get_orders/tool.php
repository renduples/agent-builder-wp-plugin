<?php
declare(strict_types=1);

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce Order Search
 *
 * Search and filter orders with pagination.
 *
 * @package Agentic\Tools
 */
class Wc_Get_Orders extends \Agentic\Tool_Base {

	public function get_name(): string {
		return 'wc_get_orders';
	}

	public function get_description(): string {
		return 'Search WooCommerce orders by status, date range, or customer. Returns order summaries with pagination.';
	}

	public function get_category(): string {
		return 'ecommerce';
	}

	public function get_parameters(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'status'      => array(
					'type'        => 'string',
					'description' => 'Order status: pending, processing, on-hold, completed, cancelled, refunded, failed. Omit for all.',
				),
				'customer_id' => array(
					'type'        => 'integer',
					'description' => 'Filter by WordPress user ID of the customer.',
				),
				'date_after'  => array(
					'type'        => 'string',
					'description' => 'Only orders after this date (YYYY-MM-DD).',
				),
				'date_before' => array(
					'type'        => 'string',
					'description' => 'Only orders before this date (YYYY-MM-DD).',
				),
				'search'      => array(
					'type'        => 'string',
					'description' => 'Search by order number, customer name, or email.',
				),
				'page'        => array(
					'type'        => 'integer',
					'description' => 'Page number. Defaults to 1.',
				),
				'per_page'    => array(
					'type'        => 'integer',
					'description' => 'Results per page (max 50). Defaults to 20.',
				),
			),
		);
	}

	public function execute( array $arguments ): array {
		// Discloses every matching order's billing/shipping address across
		// every customer, not just the caller's own — see wc_get_customer's
		// identical guard for why the tool-generic edit_posts floor is not
		// remotely enough here.
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return array( 'error' => 'You do not have permission to view order data.' );
		}

		if ( ! class_exists( 'WooCommerce' ) ) {
			return array( 'error' => 'WooCommerce is not active.' );
		}

		$per_page = min( (int) ( $arguments['per_page'] ?? 20 ), 50 );
		$page     = max( (int) ( $arguments['page'] ?? 1 ), 1 );

		$args = array(
			'limit'   => $per_page,
			'page'    => $page,
			'orderby' => 'date',
			'order'   => 'DESC',
		);

		if ( ! empty( $arguments['status'] ) ) {
			$status         = sanitize_key( $arguments['status'] );
			$args['status'] = strpos( $status, 'wc-' ) === 0 ? $status : 'wc-' . $status;
		}
		if ( ! empty( $arguments['customer_id'] ) ) {
			$args['customer_id'] = (int) $arguments['customer_id'];
		}
		if ( ! empty( $arguments['date_after'] ) ) {
			$args['date_after'] = sanitize_text_field( $arguments['date_after'] );
		}
		if ( ! empty( $arguments['date_before'] ) ) {
			$args['date_before'] = sanitize_text_field( $arguments['date_before'] );
		}
		if ( ! empty( $arguments['search'] ) ) {
			$args['s'] = sanitize_text_field( $arguments['search'] );
		}

		$orders  = wc_get_orders( $args );
		$results = array();

		foreach ( $orders as $order ) {
			$results[] = array(
				'id'             => $order->get_id(),
				'number'         => $order->get_order_number(),
				'status'         => $order->get_status(),
				'total'          => $order->get_total(),
				'currency'       => $order->get_currency(),
				'items_count'    => $order->get_item_count(),
				'customer_name'  => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
				'customer_email' => $order->get_billing_email(),
				'payment_method' => $order->get_payment_method_title(),
				'date_created'   => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i:s' ) : null,
			);
		}

		// Total for pagination.
		$count_args           = $args;
		$count_args['limit']  = -1;
		$count_args['return'] = 'ids';
		$total                = count( wc_get_orders( $count_args ) );

		return array(
			'orders'      => $results,
			'total'       => $total,
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => (int) ceil( $total / $per_page ),
		);
	}

	public function get_annotations(): array {
		return array( 'readonly' => true );
	}
}

return new Wc_Get_Orders();
