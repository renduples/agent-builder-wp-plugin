<?php
declare(strict_types=1);

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce Stock Report
 *
 * Returns products that are low stock, out of stock, or on backorder.
 *
 * @package Agentic\Tools
 */
class Wc_Get_Stock_Report extends \Agentic\Tool_Base {

	public function get_name(): string {
		return 'wc_get_stock_report';
	}

	public function get_description(): string {
		return 'Get WooCommerce stock report: products that are out of stock, low stock, or on backorder, with current quantities.';
	}

	public function get_category(): string {
		return 'ecommerce';
	}

	public function get_parameters(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'filter' => array(
					'type'        => 'string',
					'description' => 'Filter: all, outofstock, lowstock, onbackorder. Defaults to all.',
					'enum'        => array( 'all', 'outofstock', 'lowstock', 'onbackorder' ),
				),
			),
		);
	}

	public function execute( array $arguments ): array {
		// Discloses internal stock levels across the catalog — the
		// tool-generic edit_posts floor a caller might otherwise only need
		// (e.g. a Contributor over WebMCP) is nowhere near enough; this
		// needs WooCommerce's own store-management capability.
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return array( 'error' => 'You do not have permission to view stock data.' );
		}

		if ( ! class_exists( 'WooCommerce' ) ) {
			return array( 'error' => 'WooCommerce is not active.' );
		}

		$filter    = $arguments['filter'] ?? 'all';
		$low_stock = (int) get_option( 'woocommerce_notify_low_stock_amount', 2 );
		$results   = array(
			'out_of_stock' => array(),
			'low_stock'    => array(),
			'on_backorder' => array(),
		);

		// Out of stock.
		if ( 'all' === $filter || 'outofstock' === $filter ) {
			$products = wc_get_products(
				array(
					'stock_status' => 'outofstock',
					'limit'        => 100,
					'orderby'      => 'title',
					'order'        => 'ASC',
				)
			);
			foreach ( $products as $p ) {
				$results['out_of_stock'][] = array(
					'id'    => $p->get_id(),
					'name'  => $p->get_name(),
					'sku'   => $p->get_sku(),
					'stock' => $p->get_stock_quantity(),
					'price' => $p->get_price(),
				);
			}
		}

		// Low stock.
		if ( 'all' === $filter || 'lowstock' === $filter ) {
			$products = wc_get_products(
				array(
					'stock_status' => 'instock',
					'manage_stock' => true,
					'limit'        => 100,
					'orderby'      => 'title',
					'order'        => 'ASC',
				)
			);
			foreach ( $products as $p ) {
				$qty = $p->get_stock_quantity();
				if ( null !== $qty && $qty <= $low_stock && $qty > 0 ) {
					$results['low_stock'][] = array(
						'id'    => $p->get_id(),
						'name'  => $p->get_name(),
						'sku'   => $p->get_sku(),
						'stock' => $qty,
						'price' => $p->get_price(),
					);
				}
			}
		}

		// On backorder.
		if ( 'all' === $filter || 'onbackorder' === $filter ) {
			$products = wc_get_products(
				array(
					'stock_status' => 'onbackorder',
					'limit'        => 100,
					'orderby'      => 'title',
					'order'        => 'ASC',
				)
			);
			foreach ( $products as $p ) {
				$results['on_backorder'][] = array(
					'id'    => $p->get_id(),
					'name'  => $p->get_name(),
					'sku'   => $p->get_sku(),
					'stock' => $p->get_stock_quantity(),
					'price' => $p->get_price(),
				);
			}
		}

		return array(
			'low_stock_threshold' => $low_stock,
			'out_of_stock_count'  => count( $results['out_of_stock'] ),
			'low_stock_count'     => count( $results['low_stock'] ),
			'on_backorder_count'  => count( $results['on_backorder'] ),
			'products'            => $results,
		);
	}

	public function get_annotations(): array {
		return array( 'readonly' => true );
	}
}

return new Wc_Get_Stock_Report();
