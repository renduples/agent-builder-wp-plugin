<?php
/**
 * Tool: bulk_reassign_term
 *
 * @package    Agent_Builder
 * @subpackage Tools
 * @since      2.0.0
 *
 * php version 8.1
 */

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Agentic\Tool_Base;

class Bulk_Reassign_Term extends Tool_Base {
	public function get_name(): string {
		return 'bulk_reassign_term';
	}

	public function get_description(): string {
		return 'Move all posts from one taxonomy term to another. Removes the source term assignment and adds the target term assignment for all posts in the taxonomy.';
	}

	public function get_category(): string {
		return 'content';
	}

	public function get_parameters(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'taxonomy'       => array(
					'type'        => 'string',
					'description' => 'The taxonomy slug, e.g. "category" or "post_tag".',
				),
				'source_term_id' => array(
					'type'        => 'integer',
					'description' => 'ID of the term to reassign posts away from.',
				),
				'target_term_id' => array(
					'type'        => 'integer',
					'description' => 'ID of the term to assign posts to.',
				),
				'dry_run'        => array(
					'type'        => 'boolean',
					'description' => 'If true, count posts that would be reassigned without making changes. Defaults to false.',
				),
			),
			'required'   => array( 'taxonomy', 'source_term_id', 'target_term_id' ),
		);
	}

	public function get_annotations(): array {
		return array(
			'read_only'   => false,
			'destructive' => false,
		);
	}

	public function execute( array $args ): array {
		$taxonomy       = sanitize_key( $args['taxonomy'] ?? '' );
		$source_term_id = (int) ( $args['source_term_id'] ?? 0 );
		$target_term_id = (int) ( $args['target_term_id'] ?? 0 );
		$dry_run        = (bool) ( $args['dry_run'] ?? false );

		if ( ! $taxonomy || ! $source_term_id || ! $target_term_id ) {
			return array( 'error' => 'taxonomy, source_term_id, and target_term_id are all required.' );
		}

		if ( ! taxonomy_exists( $taxonomy ) ) {
			return array( 'error' => "Taxonomy '{$taxonomy}' does not exist." );
		}

		$source_term = get_term( $source_term_id, $taxonomy );
		if ( is_wp_error( $source_term ) || ! $source_term ) {
			return array( 'error' => "Source term {$source_term_id} not found in taxonomy '{$taxonomy}'." );
		}

		$target_term = get_term( $target_term_id, $taxonomy );
		if ( is_wp_error( $target_term ) || ! $target_term ) {
			return array( 'error' => "Target term {$target_term_id} not found in taxonomy '{$taxonomy}'." );
		}

		$query = new \WP_Query(
			array(
				'post_type'      => 'any',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				'tax_query'      => array(
					array(
						'taxonomy' => $taxonomy,
						'field'    => 'term_id',
						'terms'    => $source_term_id,
					),
				),
			)
		);

		$reassigned_count = 0;

		foreach ( $query->posts as $post_id ) {
			if ( ! $dry_run ) {
				wp_remove_object_terms( (int) $post_id, $source_term_id, $taxonomy );
				wp_set_object_terms( (int) $post_id, array( $target_term_id ), $taxonomy, true );
			}
			++$reassigned_count;
		}

		return array(
			'reassigned_count' => $reassigned_count,
			'dry_run'          => $dry_run,
			'source_term_name' => $source_term->name,
			'target_term_name' => $target_term->name,
			'taxonomy'         => $taxonomy,
		);
	}
}

return new Bulk_Reassign_Term();
