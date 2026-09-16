<?php
/**
 * Tool: wc_get_customer
 *
 * Get full profile and order history for a single WooCommerce customer.
 *
 * @package    Agent_Builder
 * @subpackage Tools
 * @since      2.7.0
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get a single WooCommerce customer with their order history.
 */
class Wc_Get_Customer extends \Agentic\Tool_Base {

	public function get_name(): string {
		return 'wc_get_customer';
	}

	public function get_description(): string {
		return 'Get the full profile, billing/shipping address, and recent order history for a single WooCommerce customer by their WordPress user ID. Use wc_get_customers to search for a customer ID first.';
	}

	public function get_category(): string {
		return 'ecommerce';
	}

	public function get_parameters(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'customer_id'  => array(
					'type'        => 'integer',
					'description' => 'WordPress user ID of the customer.',
				),
				'orders_limit' => array(
					'type'        => 'integer',
					'description' => 'Number of recent orders to include (default 5, max 20).',
				),
			),
			'required'   => array( 'customer_id' ),
		);
	}

	public function execute( array $arguments ): array {
		// Discloses a customer's full profile, billing/shipping address, and
		// order history — the tool-generic edit_posts floor a caller might
		// otherwise only need (e.g. a Contributor over WebMCP) is nowhere
		// near enough; this needs WooCommerce's own store-management
		// capability, the same bar wc-admin's own customer screens require.
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return array( 'error' => 'You do not have permission to view customer data.' );
		}

		if ( ! class_exists( 'WooCommerce' ) ) {
			return array( 'error' => 'WooCommerce is not active.' );
		}

		$customer_id  = (int) ( $arguments['customer_id'] ?? 0 );
		$orders_limit = min( max( (int) ( $arguments['orders_limit'] ?? 5 ), 1 ), 20 );

		if ( $customer_id <= 0 ) {
			return array( 'error' => 'customer_id is required.' );
		}

		$customer = new \WC_Customer( $customer_id );
		if ( ! $customer->get_id() ) {
			return array( 'error' => "Customer {$customer_id} not found." );
		}

		// Recent orders.
		$order_ids = wc_get_orders(
			array(
				'customer_id' => $customer_id,
				'limit'       => $orders_limit,
				'orderby'     => 'date',
				'order'       => 'DESC',
				'return'      => 'ids',
			)
		);

		$orders = array();
		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				continue;
			}
			$orders[] = array(
				'id'           => $order->get_id(),
				'number'       => $order->get_order_number(),
				'status'       => $order->get_status(),
				'total'        => $order->get_total(),
				'currency'     => $order->get_currency(),
				'items_count'  => $order->get_item_count(),
				'date_created' => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i:s' ) : null,
				'edit_link'    => $order->get_edit_order_url(),
			);
		}

		return array(
			'id'            => $customer->get_id(),
			'email'         => $customer->get_email(),
			'first_name'    => $customer->get_first_name(),
			'last_name'     => $customer->get_last_name(),
			'display_name'  => $customer->get_display_name(),
			'username'      => $customer->get_username(),
			'registered'    => $customer->get_date_created() ? $customer->get_date_created()->date( 'Y-m-d H:i:s' ) : null,
			'order_count'   => $customer->get_order_count(),
			'total_spend'   => (float) $customer->get_total_spent(),
			'currency'      => get_woocommerce_currency(),
			'average_order' => $customer->get_order_count() > 0
				? round( (float) $customer->get_total_spent() / $customer->get_order_count(), 2 )
				: 0,
			'billing'       => array(
				'first_name' => $customer->get_billing_first_name(),
				'last_name'  => $customer->get_billing_last_name(),
				'company'    => $customer->get_billing_company(),
				'email'      => $customer->get_billing_email(),
				'phone'      => $customer->get_billing_phone(),
				'address_1'  => $customer->get_billing_address_1(),
				'address_2'  => $customer->get_billing_address_2(),
				'city'       => $customer->get_billing_city(),
				'state'      => $customer->get_billing_state(),
				'postcode'   => $customer->get_billing_postcode(),
				'country'    => $customer->get_billing_country(),
			),
			'shipping'      => array(
				'first_name' => $customer->get_shipping_first_name(),
				'last_name'  => $customer->get_shipping_last_name(),
				'company'    => $customer->get_shipping_company(),
				'address_1'  => $customer->get_shipping_address_1(),
				'address_2'  => $customer->get_shipping_address_2(),
				'city'       => $customer->get_shipping_city(),
				'state'      => $customer->get_shipping_state(),
				'postcode'   => $customer->get_shipping_postcode(),
				'country'    => $customer->get_shipping_country(),
			),
			'recent_orders' => $orders,
		);
	}

	public function get_annotations(): array {
		return array(
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		);
	}
}

return new Wc_Get_Customer();
