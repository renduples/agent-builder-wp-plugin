<?php
/**
 * Tool: get_revision_diff
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

class Get_Revision_Diff extends Tool_Base {
	public function get_name(): string {
		return 'get_revision_diff';
	}

	public function get_description(): string {
		return 'Compare two post revisions and return a visual diff of the content changes, including counts of lines added and removed.';
	}

	public function get_category(): string {
		return 'content';
	}

	public function get_parameters(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'revision_id_a' => array(
					'type'        => 'integer',
					'description' => 'ID of the first (older) revision.',
				),
				'revision_id_b' => array(
					'type'        => 'integer',
					'description' => 'ID of the second (newer) revision.',
				),
			),
			'required'   => array( 'revision_id_a', 'revision_id_b' ),
		);
	}

	public function get_annotations(): array {
		return array(
			'read_only'   => true,
			'destructive' => false,
		);
	}

	public function execute( array $args ): array {
		$revision_id_a = (int) ( $args['revision_id_a'] ?? 0 );
		$revision_id_b = (int) ( $args['revision_id_b'] ?? 0 );

		if ( ! $revision_id_a || ! $revision_id_b ) {
			return array( 'error' => 'Both revision_id_a and revision_id_b are required.' );
		}

		$rev_a = get_post( $revision_id_a );
		$rev_b = get_post( $revision_id_b );

		if ( ! $rev_a ) {
			return array( 'error' => "Revision {$revision_id_a} not found." );
		}
		if ( ! $rev_b ) {
			return array( 'error' => "Revision {$revision_id_b} not found." );
		}

		$content_a = $rev_a->post_content;
		$content_b = $rev_b->post_content;

		$diff_html = wp_text_diff( $content_a, $content_b, array( 'title' => 'Content' ) );

		// Count lines added / removed from the diff HTML.
		$lines_added   = substr_count( $diff_html, '<ins>' );
		$lines_removed = substr_count( $diff_html, '<del>' );

		return array(
			'diff_html'       => $diff_html ? $diff_html : '<p>No content differences found.</p>',
			'lines_added'     => $lines_added,
			'lines_removed'   => $lines_removed,
			'revision_a_date' => $rev_a->post_modified,
			'revision_b_date' => $rev_b->post_modified,
			'revision_a_id'   => $revision_id_a,
			'revision_b_id'   => $revision_id_b,
		);
	}
}

return new Get_Revision_Diff();
