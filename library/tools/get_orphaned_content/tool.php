<?php
/**
 * Tool: get_orphaned_content
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

class Get_Orphaned_Content extends Tool_Base {
	public function get_name(): string {
		return 'get_orphaned_content';
	}

	public function get_description(): string {
		return 'Find orphaned content: auto-drafts, revisions, trashed posts, pending reviews, and spam comments. Returns counts and optional details.';
	}

	public function get_category(): string {
		return 'site-health';
	}

	public function get_parameters(): array {
		return array(
			'include_list' => array(
				'type'        => 'boolean',
				'description' => 'Include sample items for each orphaned content type.',
				'required'    => false,
			),
		);
	}

	public function get_annotations(): array {
		return array(
			'read_only'   => true,
			'destructive' => false,
		);
	}

	public function execute( array $args ): array {
		global $wpdb;
		$include_list = ! empty( $args['include_list'] );

		$counts = array(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table.
			'drafts'              => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'draft'" ),
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table.
			'pending'             => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'pending'" ),
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table.
			'auto_drafts'         => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'auto-draft'" ),
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table.
			'trash'               => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'trash'" ),
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table.
			'revisions'           => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'" ),
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table.
			'spam_comments'       => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'spam'" ),
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table.
			'unapproved_comments' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = '0'" ),
		);

		$result = array( 'counts' => $counts );

		if ( $include_list ) {
			$sample_limit = 10;
			$samples      = array();

			if ( $counts['auto_drafts'] > 0 ) {
				$posts                  = get_posts(
					array(
						'post_status'    => 'auto-draft',
						'posts_per_page' => $sample_limit,
						'post_type'      => 'any',
					)
				);
				$samples['auto_drafts'] = array_map(
					fn( $p ) => array(
						'ID'    => $p->ID,
						'title' => $p->post_title ? $p->post_title : '(no title)',
						'date'  => $p->post_date,
					),
					$posts
				);
			}
			if ( $counts['trash'] > 0 ) {
				$posts            = get_posts(
					array(
						'post_status'    => 'trash',
						'posts_per_page' => $sample_limit,
						'post_type'      => 'any',
					)
				);
				$samples['trash'] = array_map(
					fn( $p ) => array(
						'ID'    => $p->ID,
						'title' => $p->post_title,
						'type'  => $p->post_type,
					),
					$posts
				);
			}

			$result['samples'] = $samples;
		}

		return $result;
	}
}

return new Get_Orphaned_Content();
