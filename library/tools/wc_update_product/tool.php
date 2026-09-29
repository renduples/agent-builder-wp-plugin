<?php
declare(strict_types=1);

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce Update Product
 *
 * Updates fields on an existing WooCommerce product.
 *
 * @package Agentic\Tools
 */
class Wc_Update_Product extends \Agentic\Tool_Base {

	public function get_name(): string {
		return 'wc_update_product';
	}

	public function get_description(): string {
		return 'Update an existing WooCommerce product — change name, description, price, stock, status, categories, or SEO metadata.';
	}

	public function get_category(): string {
		return 'ecommerce';
	}

	public function get_parameters(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'product_id'        => array(
					'type'        => 'integer',
					'description' => 'The product ID to update.',
				),
				'name'              => array(
					'type'        => 'string',
					'description' => 'New product name.',
				),
				'description'       => array(
					'type'        => 'string',
					'description' => 'New full description (HTML).',
				),
				'short_description' => array(
					'type'        => 'string',
					'description' => 'New short description.',
				),
				'regular_price'     => array(
					'type'        => 'string',
					'description' => 'New regular price.',
				),
				'sale_price'        => array(
					'type'        => 'string',
					'description' => 'New sale price (set empty string to remove sale).',
				),
				'sku'               => array(
					'type'        => 'string',
					'description' => 'New SKU.',
				),
				'status'            => array(
					'type'        => 'string',
					'description' => 'New status: draft, publish, pending, private.',
				),
				'manage_stock'      => array(
					'type'        => 'boolean',
					'description' => 'Enable/disable stock management.',
				),
				'stock_quantity'    => array(
					'type'        => 'integer',
					'description' => 'New stock quantity.',
				),
				'weight'            => array(
					'type'        => 'string',
					'description' => 'New weight.',
				),
				'categories'        => array(
					'type'        => 'array',
					'description' => 'Replace category assignments (names or IDs).',
					'items'       => array( 'type' => 'string' ),
				),
				'tags'              => array(
					'type'        => 'array',
					'description' => 'Replace tag assignments (names).',
					'items'       => array( 'type' => 'string' ),
				),
				'image_id'          => array(
					'type'        => 'integer',
					'description' => 'Set main product image by attachment ID.',
				),
			),
			'required'   => array( 'product_id' ),
		);
	}

	public function execute( array $arguments ): array {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return array( 'error' => 'WooCommerce is not active.' );
		}

		$product = wc_get_product( (int) $arguments['product_id'] );
		if ( ! $product ) {
			return array( 'error' => 'Product not found.' );
		}

		$changes = array();

		if ( isset( $arguments['name'] ) ) {
			$product->set_name( sanitize_text_field( $arguments['name'] ) );
			$changes[] = 'name';
		}
		if ( isset( $arguments['description'] ) ) {
			$product->set_description( wp_kses_post( $arguments['description'] ) );
			$changes[] = 'description';
		}
		if ( isset( $arguments['short_description'] ) ) {
			$product->set_short_description( wp_kses_post( $arguments['short_description'] ) );
			$changes[] = 'short_description';
		}
		if ( isset( $arguments['regular_price'] ) && ! $product->is_type( 'variable' ) ) {
			$product->set_regular_price( sanitize_text_field( $arguments['regular_price'] ) );
			$changes[] = 'regular_price';
		}
		if ( isset( $arguments['sale_price'] ) && ! $product->is_type( 'variable' ) ) {
			$product->set_sale_price( sanitize_text_field( $arguments['sale_price'] ) );
			$changes[] = 'sale_price';
		}
		if ( isset( $arguments['sku'] ) ) {
			$product->set_sku( sanitize_text_field( $arguments['sku'] ) );
			$changes[] = 'sku';
		}
		if ( isset( $arguments['status'] ) ) {
			$product->set_status( sanitize_key( $arguments['status'] ) );
			$changes[] = 'status';
		}
		if ( isset( $arguments['manage_stock'] ) ) {
			$product->set_manage_stock( (bool) $arguments['manage_stock'] );
			$changes[] = 'manage_stock';
		}
		if ( isset( $arguments['stock_quantity'] ) ) {
			$product->set_stock_quantity( (int) $arguments['stock_quantity'] );
			$changes[] = 'stock_quantity';
		}
		if ( isset( $arguments['weight'] ) ) {
			$product->set_weight( sanitize_text_field( $arguments['weight'] ) );
			$changes[] = 'weight';
		}
		if ( isset( $arguments['image_id'] ) ) {
			$product->set_image_id( (int) $arguments['image_id'] );
			$changes[] = 'image';
		}

		// Categories.
		if ( isset( $arguments['categories'] ) ) {
			$cat_ids = array();
			foreach ( $arguments['categories'] as $cat ) {
				if ( is_numeric( $cat ) ) {
					$cat_ids[] = (int) $cat;
				} else {
					$term = get_term_by( 'name', $cat, 'product_cat' );
					if ( ! $term ) {
						$term = get_term_by( 'slug', sanitize_title( $cat ), 'product_cat' );
					}
					if ( $term ) {
						$cat_ids[] = $term->term_id;
					}
				}
			}
			$product->set_category_ids( $cat_ids );
			$changes[] = 'categories';
		}

		// Tags.
		if ( isset( $arguments['tags'] ) ) {
			$tag_ids = array();
			foreach ( $arguments['tags'] as $tag ) {
				$term = get_term_by( 'name', $tag, 'product_tag' );
				if ( ! $term ) {
					$result = wp_insert_term( sanitize_text_field( $tag ), 'product_tag' );
					if ( ! is_wp_error( $result ) ) {
						$tag_ids[] = $result['term_id'];
					}
				} else {
					$tag_ids[] = $term->term_id;
				}
			}
			$product->set_tag_ids( $tag_ids );
			$changes[] = 'tags';
		}

		if ( empty( $changes ) ) {
			return array( 'error' => 'No fields to update were provided.' );
		}

		$product->save();

		return array(
			'product_id' => $product->get_id(),
			'name'       => $product->get_name(),
			'status'     => $product->get_status(),
			'changes'    => $changes,
			'edit_link'  => get_edit_post_link( $product->get_id(), 'raw' ),
		);
	}

	public function get_annotations(): array {
		return array( 'destructive' => false );
	}
}

return new Wc_Update_Product();
