<?php
declare(strict_types=1);

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce Get Order
 *
 * Retrieves full details for a single order including line items,
 * shipping, billing, and order notes.
 *
 * @package Agentic\Tools
 */
class Wc_Get_Order extends \Agentic\Tool_Base {

	public function get_name(): string {
		return 'wc_get_order';
	}

	public function get_description(): string {
		return 'Get complete details for a single WooCommerce order by ID, including line items, billing/shipping addresses, and order notes.';
	}

	public function get_category(): string {
		return 'ecommerce';
	}

	public function get_parameters(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'order_id' => array(
					'type'        => 'integer',
					'description' => 'The order ID to retrieve.',
				),
			),
			'required'   => array( 'order_id' ),
		);
	}

	public function execute( array $arguments ): array {
		// Discloses one order's billing/shipping address, line items, and
		// notes for any order ID, not just the caller's own — see
		// wc_get_customer's identical guard for why the tool-generic
		// edit_posts floor is not remotely enough here.
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return array( 'error' => 'You do not have permission to view order data.' );
		}

		if ( ! class_exists( 'WooCommerce' ) ) {
			return array( 'error' => 'WooCommerce is not active.' );
		}

		$order = wc_get_order( (int) $arguments['order_id'] );
		if ( ! $order ) {
			return array( 'error' => 'Order not found.' );
		}

		// Line items.
		$items = array();
		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			$items[] = array(
				'product_id'   => $item->get_product_id(),
				'variation_id' => $item->get_variation_id(),
				'name'         => $item->get_name(),
				'quantity'     => $item->get_quantity(),
				'subtotal'     => $item->get_subtotal(),
				'total'        => $item->get_total(),
				'sku'          => $product ? $product->get_sku() : '',
			);
		}

		// Shipping lines.
		$shipping = array();
		foreach ( $order->get_shipping_methods() as $method ) {
			$shipping[] = array(
				'method' => $method->get_method_title(),
				'total'  => $method->get_total(),
			);
		}

		// Fee lines.
		$fees = array();
		foreach ( $order->get_fees() as $fee ) {
			$fees[] = array(
				'name'  => $fee->get_name(),
				'total' => $fee->get_total(),
			);
		}

		// Coupons.
		$coupons = array();
		foreach ( $order->get_coupon_codes() as $code ) {
			$coupons[] = $code;
		}

		// Order notes (last 10).
		$notes_raw = wc_get_order_notes(
			array(
				'order_id' => $order->get_id(),
				'limit'    => 10,
			)
		);
		$notes     = array();
		foreach ( $notes_raw as $note ) {
			$notes[] = array(
				'date'          => $note->date_created->date( 'Y-m-d H:i:s' ),
				'content'       => $note->content,
				'customer_note' => $note->customer_note,
				'added_by'      => $note->added_by,
			);
		}

		// Refunds.
		$refunds     = array();
		$refund_objs = $order->get_refunds();
		foreach ( $refund_objs as $refund ) {
			$refunds[] = array(
				'id'     => $refund->get_id(),
				'amount' => $refund->get_amount(),
				'reason' => $refund->get_reason(),
				'date'   => $refund->get_date_created() ? $refund->get_date_created()->date( 'Y-m-d H:i:s' ) : null,
			);
		}

		return array(
			'id'               => $order->get_id(),
			'number'           => $order->get_order_number(),
			'status'           => $order->get_status(),
			'date_created'     => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i:s' ) : null,
			'date_modified'    => $order->get_date_modified() ? $order->get_date_modified()->date( 'Y-m-d H:i:s' ) : null,
			'subtotal'         => $order->get_subtotal(),
			'discount_total'   => $order->get_discount_total(),
			'shipping_total'   => $order->get_shipping_total(),
			'tax_total'        => $order->get_total_tax(),
			'total'            => $order->get_total(),
			'currency'         => $order->get_currency(),
			'payment_method'   => $order->get_payment_method_title(),
			'transaction_id'   => $order->get_transaction_id(),
			'billing'          => array(
				'first_name' => $order->get_billing_first_name(),
				'last_name'  => $order->get_billing_last_name(),
				'email'      => $order->get_billing_email(),
				'phone'      => $order->get_billing_phone(),
				'address_1'  => $order->get_billing_address_1(),
				'address_2'  => $order->get_billing_address_2(),
				'city'       => $order->get_billing_city(),
				'state'      => $order->get_billing_state(),
				'postcode'   => $order->get_billing_postcode(),
				'country'    => $order->get_billing_country(),
			),
			'shipping_address' => array(
				'first_name' => $order->get_shipping_first_name(),
				'last_name'  => $order->get_shipping_last_name(),
				'address_1'  => $order->get_shipping_address_1(),
				'address_2'  => $order->get_shipping_address_2(),
				'city'       => $order->get_shipping_city(),
				'state'      => $order->get_shipping_state(),
				'postcode'   => $order->get_shipping_postcode(),
				'country'    => $order->get_shipping_country(),
			),
			'customer_id'      => $order->get_customer_id(),
			'customer_note'    => $order->get_customer_note(),
			'items'            => $items,
			'shipping_lines'   => $shipping,
			'fee_lines'        => $fees,
			'coupons_used'     => $coupons,
			'refunds'          => $refunds,
			'notes'            => $notes,
			'edit_link'        => $order->get_edit_order_url(),
		);
	}

	public function get_annotations(): array {
		return array( 'readonly' => true );
	}
}

return new Wc_Get_Order();
