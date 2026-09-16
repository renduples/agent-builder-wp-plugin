<?php
declare(strict_types=1);

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce Customer Search
 *
 * Search and list customers with order history summaries.
 *
 * @package Agentic\Tools
 */
class Wc_Get_Customers extends \Agentic\Tool_Base {

	public function get_name(): string {
		return 'wc_get_customers';
	}

	public function get_description(): string {
		return 'Search WooCommerce customers by name or email. Returns customer details with order count and total spend. When sorting by order_count or total_spend, all matching customers are loaded and sorted before pagination is applied.';
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
					'description' => 'Search by name or email address.',
				),
				'orderby'  => array(
					'type'        => 'string',
					'description' => 'Sort by: registered, order_count, total_spend. Defaults to registered.',
					'enum'        => array( 'registered', 'order_count', 'total_spend' ),
				),
				'page'     => array(
					'type'        => 'integer',
					'description' => 'Page number. Defaults to 1.',
				),
				'per_page' => array(
					'type'        => 'integer',
					'description' => 'Results per page (max 50). Defaults to 20.',
				),
			),
		);
	}

	public function execute( array $arguments ): array {
		// Discloses every matching customer's email, order count, and total
		// spend — see wc_get_customer's identical guard for why the
		// tool-generic edit_posts floor is not remotely enough here.
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return array( 'error' => 'You do not have permission to view customer data.' );
		}

		if ( ! class_exists( 'WooCommerce' ) ) {
			return array( 'error' => 'WooCommerce is not active.' );
		}

		$per_page = min( (int) ( $arguments['per_page'] ?? 20 ), 50 );
		$page     = max( (int) ( $arguments['page'] ?? 1 ), 1 );
		$orderby  = $arguments['orderby'] ?? 'registered';

		$needs_full_load = in_array( $orderby, array( 'order_count', 'total_spend' ), true );

		$user_args = array(
			'role__in' => array( 'customer', 'subscriber' ),
			// Load all records for non-default sort so we can sort before paginating.
			'number'   => $needs_full_load ? -1 : $per_page,
			'paged'    => $needs_full_load ? 1 : $page,
			'orderby'  => 'registered',
			'order'    => 'DESC',
		);

		if ( ! empty( $arguments['search'] ) ) {
			$user_args['search']         = '*' . sanitize_text_field( $arguments['search'] ) . '*';
			$user_args['search_columns'] = array( 'user_login', 'user_email', 'user_nicename', 'display_name' );
		}

		$query     = new \WP_User_Query( $user_args );
		$users     = $query->get_results();
		$customers = array();

		foreach ( $users as $user ) {
			$customer    = new \WC_Customer( $user->ID );
			$last_order  = $customer->get_last_order();
			$customers[] = array(
				'id'           => $user->ID,
				'email'        => $customer->get_email(),
				'first_name'   => $customer->get_first_name(),
				'last_name'    => $customer->get_last_name(),
				'display_name' => $customer->get_display_name(),
				'registered'   => $user->user_registered,
				'order_count'  => $customer->get_order_count(),
				'total_spend'  => (float) $customer->get_total_spent(),
				'city'         => $customer->get_billing_city(),
				'state'        => $customer->get_billing_state(),
				'country'      => $customer->get_billing_country(),
				'last_order'   => $last_order && $last_order->get_date_created() ? $last_order->get_date_created()->date( 'Y-m-d' ) : null,
			);
		}

		// Sort entire result set before paginating — ensures correct ranking across pages.
		if ( 'order_count' === $orderby ) {
			usort( $customers, fn( $a, $b ) => $b['order_count'] <=> $a['order_count'] );
		} elseif ( 'total_spend' === $orderby ) {
			usort( $customers, fn( $a, $b ) => $b['total_spend'] <=> $a['total_spend'] );
		}

		$total = $needs_full_load ? count( $customers ) : (int) $query->get_total();

		// Apply pagination manually when we loaded everything.
		if ( $needs_full_load ) {
			$offset    = ( $page - 1 ) * $per_page;
			$customers = array_slice( $customers, $offset, $per_page );
		}

		return array(
			'customers'   => $customers,
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

return new Wc_Get_Customers();
