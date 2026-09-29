<?php
declare(strict_types=1);

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce Get Product
 *
 * Retrieves full details for a single product including variations.
 *
 * @package Agentic\Tools
 */
class Wc_Get_Product extends \Agentic\Tool_Base {

	public function get_name(): string {
		return 'wc_get_product';
	}

	public function get_description(): string {
		return 'Get complete details for a single WooCommerce product by ID, including variations, images, attributes, and SEO metadata.';
	}

	public function get_category(): string {
		return 'ecommerce';
	}

	public function get_parameters(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'product_id' => array(
					'type'        => 'integer',
					'description' => 'The product ID to retrieve.',
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

		$data = array(
			'id'                => $product->get_id(),
			'name'              => $product->get_name(),
			'slug'              => $product->get_slug(),
			'type'              => $product->get_type(),
			'status'            => $product->get_status(),
			'description'       => $product->get_description(),
			'short_description' => $product->get_short_description(),
			'sku'               => $product->get_sku(),
			'price'             => $product->get_price(),
			'regular_price'     => $product->get_regular_price(),
			'sale_price'        => $product->get_sale_price(),
			'on_sale'           => $product->is_on_sale(),
			'stock_status'      => $product->get_stock_status(),
			'stock_quantity'    => $product->get_stock_quantity(),
			'manage_stock'      => $product->managing_stock(),
			'weight'            => $product->get_weight(),
			'length'            => $product->get_length(),
			'width'             => $product->get_width(),
			'height'            => $product->get_height(),
			'categories'        => array(),
			'tags'              => array(),
			'attributes'        => array(),
			'images'            => array(),
			'permalink'         => $product->get_permalink(),
			'edit_link'         => get_edit_post_link( $product->get_id(), 'raw' ),
			'date_created'      => $product->get_date_created() ? $product->get_date_created()->date( 'Y-m-d H:i:s' ) : null,
			'total_sales'       => $product->get_total_sales(),
			'average_rating'    => $product->get_average_rating(),
			'review_count'      => $product->get_review_count(),
		);

		// Categories.
		foreach ( $product->get_category_ids() as $cat_id ) {
			$term = get_term( $cat_id, 'product_cat' );
			if ( $term && ! is_wp_error( $term ) ) {
				$data['categories'][] = array(
					'id'   => $term->term_id,
					'name' => $term->name,
					'slug' => $term->slug,
				);
			}
		}

		// Tags.
		foreach ( $product->get_tag_ids() as $tag_id ) {
			$term = get_term( $tag_id, 'product_tag' );
			if ( $term && ! is_wp_error( $term ) ) {
				$data['tags'][] = array(
					'id'   => $term->term_id,
					'name' => $term->name,
				);
			}
		}

		// Attributes.
		foreach ( $product->get_attributes() as $attr ) {
			$data['attributes'][] = array(
				'name'    => $attr->get_name(),
				'options' => $attr->get_options(),
				'visible' => $attr->get_visible(),
			);
		}

		// Images.
		$image_id = $product->get_image_id();
		if ( $image_id ) {
			$data['images'][] = array(
				'id'  => $image_id,
				'src' => wp_get_attachment_url( $image_id ),
				'alt' => get_post_meta( $image_id, '_wp_attachment_image_alt', true ),
			);
		}
		foreach ( $product->get_gallery_image_ids() as $gal_id ) {
			$data['images'][] = array(
				'id'  => $gal_id,
				'src' => wp_get_attachment_url( $gal_id ),
				'alt' => get_post_meta( $gal_id, '_wp_attachment_image_alt', true ),
			);
		}

		// Variations for variable products.
		if ( $product->is_type( 'variable' ) ) {
			$data['variations'] = array();
			foreach ( $product->get_children() as $var_id ) {
				$variation = wc_get_product( $var_id );
				if ( ! $variation ) {
					continue;
				}
				$data['variations'][] = array(
					'id'            => $variation->get_id(),
					'sku'           => $variation->get_sku(),
					'price'         => $variation->get_price(),
					'regular_price' => $variation->get_regular_price(),
					'sale_price'    => $variation->get_sale_price(),
					'stock_status'  => $variation->get_stock_status(),
					'stock_qty'     => $variation->get_stock_quantity(),
					'attributes'    => $variation->get_attributes(),
				);
			}
		}

		// SEO metadata (Yoast / RankMath / AIOSEO).
		$seo        = array();
		$meta_title = get_post_meta( $product->get_id(), '_yoast_wpseo_title', true );
		if ( ! $meta_title ) {
			$meta_title = get_post_meta( $product->get_id(), 'rank_math_title', true );
		}
		$meta_desc = get_post_meta( $product->get_id(), '_yoast_wpseo_metadesc', true );
		if ( ! $meta_desc ) {
			$meta_desc = get_post_meta( $product->get_id(), 'rank_math_description', true );
		}
		if ( $meta_title ) {
			$seo['title'] = $meta_title;
		}
		if ( $meta_desc ) {
			$seo['description'] = $meta_desc;
		}
		if ( ! empty( $seo ) ) {
			$data['seo'] = $seo;
		}

		return $data;
	}

	public function get_annotations(): array {
		return array( 'readonly' => true );
	}
}

return new Wc_Get_Product();
