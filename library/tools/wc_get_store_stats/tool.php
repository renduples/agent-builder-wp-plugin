<?php
declare(strict_types=1);

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce Store Statistics
 *
 * Returns a high-level store dashboard: revenue, order counts,
 * top-selling products, and stock health indicators.
 *
 * @package Agentic\Tools
 */
class Wc_Get_Store_Stats extends \Agentic\Tool_Base {

	public function get_name(): string {
		return 'wc_get_store_stats';
	}

	public function get_description(): string {
		return 'Get WooCommerce store statistics: revenue, order counts, top-selling products, and stock health for a given period.';
	}

	public function get_category(): string {
		return 'ecommerce';
	}

	public function get_parameters(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'period' => array(
					'type'        => 'string',
					'description' => 'Time period: today, week, month, year. Defaults to month.',
					'enum'        => array( 'today', 'week', 'month', 'year' ),
				),
			),
		);
	}

	public function execute( array $arguments ): array {
		// Discloses store revenue and order-volume figures — the
		// tool-generic edit_posts floor a caller might otherwise only need
		// (e.g. a Contributor over WebMCP) is nowhere near enough; this
		// needs WooCommerce's own store-management capability.
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return array( 'error' => 'You do not have permission to view store statistics.' );
		}

		if ( ! class_exists( 'WooCommerce' ) ) {
			return array( 'error' => 'WooCommerce is not active.' );
		}

		$period = $arguments['period'] ?? 'month';
		$now    = current_time( 'timestamp' );

		switch ( $period ) {
			case 'today':
				$after = gmdate( 'Y-m-d', $now );
				break;
			case 'week':
				$after = gmdate( 'Y-m-d', strtotime( '-7 days', $now ) );
				break;
			case 'year':
				$after = gmdate( 'Y-m-d', strtotime( '-1 year', $now ) );
				break;
			default:
				$after = gmdate( 'Y-m-d', strtotime( '-30 days', $now ) );
		}

		// Revenue & order counts.
		$orders = wc_get_orders(
			array(
				'status'     => array( 'wc-completed', 'wc-processing', 'wc-on-hold' ),
				'date_after' => $after,
				'limit'      => -1,
				'return'     => 'ids',
			)
		);

		$total_revenue  = 0;
		$total_items    = 0;
		$product_counts = array();

		foreach ( $orders as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				continue;
			}
			$total_revenue += (float) $order->get_total();
			foreach ( $order->get_items() as $item ) {
				$pid          = $item->get_product_id();
				$qty          = $item->get_quantity();
				$total_items += $qty;
				if ( ! isset( $product_counts[ $pid ] ) ) {
					$product_counts[ $pid ] = array(
						'name' => $item->get_name(),
						'qty'  => 0,
					);
				}
				$product_counts[ $pid ]['qty'] += $qty;
			}
		}

		// Top 5 products.
		uasort( $product_counts, fn( $a, $b ) => $b['qty'] <=> $a['qty'] );
		$top_products = array();
		$i            = 0;
		foreach ( $product_counts as $pid => $data ) {
			if ( ++$i > 5 ) {
				break;
			}
			$top_products[] = array(
				'product_id' => $pid,
				'name'       => $data['name'],
				'units_sold' => $data['qty'],
			);
		}

		// Stock health.
		$low_stock = (int) get_option( 'woocommerce_notify_low_stock_amount', 2 );
		$out_count = 0;
		$low_count = 0;
		$products  = wc_get_products(
			array(
				'limit'        => -1,
				'return'       => 'ids',
				'manage_stock' => true,
			)
		);
		foreach ( $products as $pid ) {
			$product = wc_get_product( $pid );
			if ( ! $product || ! $product->managing_stock() ) {
				continue;
			}
			$stock = $product->get_stock_quantity();
			if ( $stock <= 0 ) {
				++$out_count;
			} elseif ( $stock <= $low_stock ) {
				++$low_count;
			}
		}

		// Pending orders.
		$pending    = count(
			wc_get_orders(
				array(
					'status' => 'wc-pending',
					'limit'  => -1,
					'return' => 'ids',
				)
			)
		);
		$processing = count(
			wc_get_orders(
				array(
					'status' => 'wc-processing',
					'limit'  => -1,
					'return' => 'ids',
				)
			)
		);
		$on_hold    = count(
			wc_get_orders(
				array(
					'status' => 'wc-on-hold',
					'limit'  => -1,
					'return' => 'ids',
				)
			)
		);

		return array(
			'period'            => $period,
			'date_from'         => $after,
			'total_orders'      => count( $orders ),
			'total_revenue'     => round( $total_revenue, 2 ),
			'currency'          => get_woocommerce_currency(),
			'total_items_sold'  => $total_items,
			'average_order'     => count( $orders ) > 0 ? round( $total_revenue / count( $orders ), 2 ) : 0,
			'top_products'      => $top_products,
			'orders_pending'    => $pending,
			'orders_processing' => $processing,
			'orders_on_hold'    => $on_hold,
			'stock_out'         => $out_count,
			'stock_low'         => $low_count,
		);
	}

	public function get_annotations(): array {
		return array( 'readonly' => true );
	}
}

return new Wc_Get_Store_Stats();
