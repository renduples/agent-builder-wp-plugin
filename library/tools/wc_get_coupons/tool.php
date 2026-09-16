<?php
declare(strict_types=1);

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce Coupon List
 *
 * Lists coupons with usage stats.
 *
 * @package Agentic\Tools
 */
class Wc_Get_Coupons extends \Agentic\Tool_Base {

	public function get_name(): string {
		return 'wc_get_coupons';
	}

	public function get_description(): string {
		return 'List WooCommerce coupons with discount details, usage counts, and expiry dates.';
	}

	public function get_category(): string {
		return 'ecommerce';
	}

	public function get_parameters(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'search'   => array(
					'type'        => 'string',
					'description' => 'Search coupon codes.',
				),
				'per_page' => array(
					'type'        => 'integer',
					'description' => 'Results per page (max 50). Defaults to 20.',
				),
			),
		);
	}

	public function execute( array $arguments ): array {
		// Discloses live coupon codes and their discount terms — a caller
		// could redeem them directly, so the tool-generic edit_posts floor
		// (e.g. a Contributor over WebMCP) is nowhere near enough; this
		// needs WooCommerce's own store-management capability.
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return array( 'error' => 'You do not have permission to view coupon data.' );
		}

		if ( ! class_exists( 'WooCommerce' ) ) {
			return array( 'error' => 'WooCommerce is not active.' );
		}

		$per_page = min( (int) ( $arguments['per_page'] ?? 20 ), 50 );

		$args = array(
			'posts_per_page' => $per_page,
			'post_type'      => 'shop_coupon',
			'post_status'    => 'publish',
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		if ( ! empty( $arguments['search'] ) ) {
			$args['s'] = sanitize_text_field( $arguments['search'] );
		}

		$query   = new \WP_Query( $args );
		$results = array();

		foreach ( $query->posts as $post ) {
			$coupon    = new \WC_Coupon( $post->ID );
			$results[] = array(
				'id'             => $coupon->get_id(),
				'code'           => $coupon->get_code(),
				'discount_type'  => $coupon->get_discount_type(),
				'amount'         => $coupon->get_amount(),
				'usage_count'    => $coupon->get_usage_count(),
				'usage_limit'    => $coupon->get_usage_limit(),
				'expiry_date'    => $coupon->get_date_expires() ? $coupon->get_date_expires()->date( 'Y-m-d' ) : null,
				'minimum_amount' => $coupon->get_minimum_amount(),
				'maximum_amount' => $coupon->get_maximum_amount(),
				'free_shipping'  => $coupon->get_free_shipping(),
				'description'    => $coupon->get_description(),
			);
		}

		return array(
			'coupons' => $results,
			'total'   => (int) $query->found_posts,
		);
	}

	public function get_annotations(): array {
		return array( 'readonly' => true );
	}
}

return new Wc_Get_Coupons();
