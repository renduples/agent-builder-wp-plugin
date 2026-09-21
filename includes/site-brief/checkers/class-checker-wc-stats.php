<?php
/**
 * Site Brief checker: WooCommerce store stats footer.
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
 * Informational store footer. Not a ranked job card.
 */
class Checker_Wc_Stats extends Site_Brief_Checker {

	/**
	 * {@inheritdoc}
	 */
	public function get_id(): string {
		return 'wc_stats';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_tools(): array {
		return array( 'wc_get_store_stats' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_agent(): string {
		return 'woocommerce-assistant';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_severity(): int {
		return 5;
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
			'wc_get_store_stats',
			array( 'period' => 'month' )
		);
		if ( isset( $result['error'] ) ) {
			return array();
		}

		return array(
			'store_stats' => array(
				'period'        => (string) ( $result['period'] ?? 'month' ),
				'total_orders'  => (int) ( $result['total_orders'] ?? 0 ),
				'total_revenue' => (float) ( $result['total_revenue'] ?? 0 ),
				'currency'      => (string) ( $result['currency'] ?? '' ),
				'stock_low'     => (int) ( $result['stock_low'] ?? 0 ),
				'stock_out'     => (int) ( $result['stock_out'] ?? 0 ),
			),
		);
	}
}
