<?php
/**
 * Site Brief checker: WooCommerce low stock.
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
 * Card for products below the Woo threshold. Does not Approve-run wc_update_stock.
 */
class Checker_Wc_Low_Stock extends Site_Brief_Checker {

	/**
	 * {@inheritdoc}
	 */
	public function get_id(): string {
		return 'wc_low_stock';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_tools(): array {
		return array( 'wc_get_stock_report' );
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
		return 50;
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
			'wc_get_stock_report',
			array( 'filter' => 'lowstock' )
		);
		if ( isset( $result['error'] ) ) {
			return array();
		}

		$count = (int) ( $result['low_stock_count'] ?? 0 );
		if ( $count < 1 ) {
			$products = $result['products']['low_stock'] ?? array();
			$count    = is_array( $products ) ? count( $products ) : 0;
		}
		if ( $count < 1 ) {
			return array();
		}

		$names = array();
		foreach ( array_slice( (array) ( $result['products']['low_stock'] ?? array() ), 0, 6 ) as $product ) {
			$name = (string) ( $product['name'] ?? '' );
			$qty  = $product['stock'] ?? '';
			if ( '' !== $name ) {
				$names[] = $name . ( '' !== $qty && null !== $qty ? ' (' . $qty . ')' : '' );
			}
		}

		return array(
			$this->card(
				array(
					'title'            => sprintf(
						/* translators: %d: low-stock products. */
						_n( '%d product is below the stock threshold', '%d products are below the stock threshold', $count, 'agent-builder' ),
						$count
					),
					'evidence'         => implode( ', ', $names ),
					'evidence_payload' => array( 'count' => $count ),
					'proposed_action'  => __( 'Open Products to restock. wc_update_stock is high-risk and is not run from Site Brief.', 'agent-builder' ),
					'action_risk'      => 'high',
					'approve'          => $this->approve_url( admin_url( 'edit.php?post_type=product' ) ),
					'raw'              => array( 'count' => $count ),
				)
			),
		);
	}
}
