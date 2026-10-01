<?php
/**
 * Unit Tests for Result_Card normalisation and the autonomous run card path.
 *
 * Verifies Result_Card::normalize() against each recognized tool's real
 * return shape (post, file, list), that unrecognized/errored tools map to
 * null, and that a full autonomous run calling a recognized tool surfaces a
 * non-empty cards array + result_summary.
 *
 * @package Agentic\Tests
 */

namespace Agentic\Tests;

use Agentic\Agent_Controller;
use Agentic\Manifest_Agent;
use Agentic\Result_Card;

/**
 * Test case for Result_Card.
 */
class Test_Result_Card extends TestCase {

	/**
	 * Build a minimal Manifest_Agent test double.
	 *
	 * @param string $slug Agent slug.
	 * @return Manifest_Agent
	 */
	private function make_agent( string $slug ): Manifest_Agent {
		return new Manifest_Agent(
			array(
				'slug' => $slug,
				'name' => 'Test Agent',
			),
			''
		);
	}

	/**
	 * Log in as an administrator and create a draft post, returning its id.
	 *
	 * @param string $title Post title.
	 * @return int
	 */
	private function make_editor_post( string $title = 'Test Post' ): int {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		return self::factory()->post->create(
			array(
				'post_title'  => $title,
				'post_status' => 'draft',
			)
		);
	}

	/**
	 * create_post_content returns a full post card (created action), reusing the
	 * tool's own on-site url/edit_url rather than inventing fields.
	 */
	public function test_normalize_create_post_content(): void {
		$post_id = $this->make_editor_post( 'Hello World' );

		$result = array(
			'success'  => true,
			'post_id'  => $post_id,
			'status'   => 'draft',
			'title'    => 'Hello World',
			'url'      => get_permalink( $post_id ),
			'edit_url' => get_edit_post_link( $post_id, 'raw' ),
			'message'  => 'Draft saved (ID: ' . $post_id . ').',
		);

		$card = Result_Card::normalize( 'create_post_content', $result );

		$this->assertIsArray( $card );
		$this->assertSame( 'post', $card['type'] );
		$this->assertSame( 'create_post_content', $card['tool'] );
		$this->assertSame( 'Hello World', $card['title'] );
		$this->assertSame( 'created', $card['action'] );
		$this->assertSame( $post_id, $card['post_id'] );
		$this->assertSame( get_edit_post_link( $post_id, 'raw' ), $card['edit_url'] );
		$this->assertSame( get_permalink( $post_id ), $card['view_url'] );
	}

	/**
	 * db_create_post has edit_link (not edit_url) and no url — normalize must
	 * map edit_link to edit_url and fall view_url back to the permalink.
	 */
	public function test_normalize_db_create_post_maps_edit_link(): void {
		$post_id = $this->make_editor_post( 'Draft Post' );

		$result = array(
			'post_id'   => $post_id,
			'title'     => 'Draft Post',
			'status'    => 'draft',
			'type'      => 'post',
			'edit_link' => get_edit_post_link( $post_id, 'raw' ),
		);

		$card = Result_Card::normalize( 'db_create_post', $result );

		$this->assertIsArray( $card );
		$this->assertSame( 'post', $card['type'] );
		$this->assertSame( 'created', $card['action'] );
		$this->assertSame( $post_id, $card['post_id'] );
		$this->assertSame( get_edit_post_link( $post_id, 'raw' ), $card['edit_url'] );
		$this->assertSame( get_permalink( $post_id ), $card['view_url'] );
	}

	/**
	 * db_update_post and update_post_content normalize to an "updated" action.
	 */
	public function test_normalize_update_tools_report_updated_action(): void {
		$post_id = $this->make_editor_post( 'Updated Title' );

		$db_update = Result_Card::normalize(
			'db_update_post',
			array(
				'post_id'   => $post_id,
				'title'     => 'Updated Title',
				'status'    => 'draft',
				'modified'  => '2026-01-01 00:00:00',
				'edit_link' => get_edit_post_link( $post_id, 'raw' ),
			)
		);
		$this->assertIsArray( $db_update );
		$this->assertSame( 'updated', $db_update['action'] );
		$this->assertSame( $post_id, $db_update['post_id'] );

		$update_content = Result_Card::normalize(
			'update_post_content',
			array(
				'success' => true,
				'post_id' => $post_id,
				'status'  => 'draft',
				'url'     => get_permalink( $post_id ),
				'message' => 'Post ID ' . $post_id . ' updated successfully.',
			)
		);
		$this->assertIsArray( $update_content );
		$this->assertSame( 'updated', $update_content['action'] );
		$this->assertSame( get_permalink( $post_id ), $update_content['view_url'] );
		$this->assertSame( get_edit_post_link( $post_id, 'raw' ), $update_content['edit_url'] );
	}

	/**
	 * An unsafe tool-supplied URL (javascript:, data:, protocol-relative, or an
	 * off-site host) is dropped and the card falls back to the canonical WP URLs.
	 */
	public function test_normalize_post_rejects_unsafe_urls_and_falls_back(): void {
		$post_id = $this->make_editor_post( 'Safe Fallback' );

		$bad = array(
			'javascript:alert(1)',
			'data:text/html,<script>alert(1)</script>',
			'//evil.com/steal',
			'https://evil.com/steal',
			"/\\evil.com",
			"/\t/evil.com",
			"/\n/evil.com",
			"/\r/evil.com",
		);

		foreach ( $bad as $url ) {
			$card = Result_Card::normalize(
				'create_post_content',
				array(
					'post_id'  => $post_id,
					'title'    => 'Safe Fallback',
					'url'      => $url,
					'edit_url' => $url,
				)
			);

			$this->assertIsArray( $card, 'an unsafe URL must still produce a card via fallback' );
			$this->assertSame( get_permalink( $post_id ), $card['view_url'] );
			$this->assertSame( get_edit_post_link( $post_id, 'raw' ), $card['edit_url'] );
		}
	}

	/**
	 * The edit link is only surfaced to a user who can edit the post.
	 */
	public function test_normalize_post_hides_edit_url_for_unprivileged_user(): void {
		wp_set_current_user( 0 );

		$post_id = self::factory()->post->create(
			array(
				'post_title'  => 'Hidden Edit',
				'post_status' => 'draft',
			)
		);

		$card = Result_Card::normalize(
			'create_post_content',
			array(
				'post_id'  => $post_id,
				'title'    => 'Hidden Edit',
				'url'      => get_permalink( $post_id ),
				'edit_url' => get_edit_post_link( $post_id, 'raw' ),
			)
		);

		$this->assertIsArray( $card );
		$this->assertSame( '', $card['edit_url'] );
		$this->assertSame( get_permalink( $post_id ), $card['view_url'] );
	}

	/**
	 * File tools normalize to a file card carrying the on-site download url only —
	 * the raw `path` is no longer part of the contract.
	 */
	public function test_normalize_file_tool(): void {
		$uploads = wp_upload_dir();
		$dir     = trailingslashit( $uploads['basedir'] ) . 'agentic-exports/';
		wp_mkdir_p( $dir );
		file_put_contents( $dir . 'report.docx', 'PK' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$result = array(
			'file_path'    => 'agentic-exports/report.docx',
			'url'          => 'https://evil.test/wp-content/uploads/agentic-exports/report.docx',
			'file_size_kb' => 12.4,
		);

		$card = Result_Card::normalize( 'create_docx', $result );

		$this->assertIsArray( $card );
		$this->assertSame( 'file', $card['type'] );
		$this->assertSame( 'create_docx', $card['tool'] );
		$this->assertSame( 'report.docx', $card['title'] );
		$this->assertArrayNotHasKey( 'path', $card );
		$this->assertSame( trailingslashit( $uploads['baseurl'] ) . 'agentic-exports/report.docx', $card['url'] );
	}

	/**
	 * A file_path that escapes the exports directory (traversal, subdirectory, or
	 * absolute) is rejected outright.
	 */
	public function test_normalize_file_rejects_path_escape(): void {
		$this->assertNull( Result_Card::normalize( 'create_docx', array( 'file_path' => 'agentic-exports/../../wp-config.php' ) ) );
		$this->assertNull( Result_Card::normalize( 'create_docx', array( 'file_path' => 'agentic-exports/sub/report.docx' ) ) );
		$this->assertNull( Result_Card::normalize( 'create_docx', array( 'file_path' => '/etc/passwd' ) ) );
		$this->assertNull( Result_Card::normalize( 'create_docx', array( 'file_path' => '../report.docx' ) ) );
	}

	/**
	 * list_posts normalizes to a list card with a count and sample items.
	 */
	public function test_normalize_list_posts(): void {
		$result = array(
			'total_returned' => 2,
			'offset'         => 0,
			'posts'          => array(
				array( 'id' => 1, 'title' => 'First', 'status' => 'publish', 'date' => '2026-01-01', 'modified' => '', 'word_count' => 10, 'url' => '' ),
				array( 'id' => 2, 'title' => 'Second', 'status' => 'draft', 'date' => '2026-01-02', 'modified' => '', 'word_count' => 20, 'url' => '' ),
			),
		);

		$card = Result_Card::normalize( 'list_posts', $result );

		$this->assertIsArray( $card );
		$this->assertSame( 'list', $card['type'] );
		$this->assertSame( 'list_posts', $card['tool'] );
		$this->assertSame( 2, $card['count'] );
		$this->assertCount( 2, $card['items'] );
		$this->assertSame( 1, $card['items'][0]['id'] );
		$this->assertSame( 'First', $card['items'][0]['title'] );
	}

	/**
	 * list_comments uses its own items key and author label for samples.
	 */
	public function test_normalize_list_comments(): void {
		$result = array(
			'status'         => 'hold',
			'total'          => 7,
			'total_returned' => 2,
			'offset'         => 0,
			'comments'       => array(
				array( 'id' => 10, 'post_id' => 1, 'post_title' => 'Post A', 'author' => 'Alice', 'content' => 'Hi' ),
				array( 'id' => 11, 'post_id' => 2, 'post_title' => 'Post B', 'author' => 'Bob', 'content' => 'Hello' ),
			),
		);

		$card = Result_Card::normalize( 'list_comments', $result );

		$this->assertIsArray( $card );
		$this->assertSame( 'list', $card['type'] );
		$this->assertSame( 2, $card['count'] );
		$this->assertSame( 'Alice', $card['items'][0]['title'] );
		$this->assertSame( 10, $card['items'][0]['id'] );
	}

	/**
	 * Unrecognized tools map to null.
	 */
	public function test_normalize_unknown_tool_returns_null(): void {
		$this->assertNull( Result_Card::normalize( 'get_site_overview', array( 'total_posts' => 5 ) ) );
	}

	/**
	 * A recognized tool whose result is an error maps to null (no card for a
	 * failed action).
	 */
	public function test_normalize_error_result_returns_null(): void {
		$this->assertNull( Result_Card::normalize( 'db_create_post', array( 'error' => 'Title is required.' ) ) );
		$this->assertNull( Result_Card::normalize( 'create_docx', array( 'error' => 'phpoffice/phpword is not installed.' ) ) );
	}

	/**
	 * A post tool result without a valid post_id maps to null.
	 */
	public function test_normalize_post_without_post_id_returns_null(): void {
		$this->assertNull( Result_Card::normalize( 'create_post_content', array( 'success' => false, 'status' => 'draft' ) ) );
	}

	/**
	 * collect() reduces a raw tool_results list to normalized cards only.
	 */
	public function test_collect_skips_unknown_tools(): void {
		$tool_results = array(
			array( 'tool' => 'get_site_overview', 'result' => array( 'total_posts' => 5 ) ),
			array( 'tool' => 'create_post_content', 'result' => array( 'post_id' => 1, 'title' => 'Hi', 'url' => 'https://example.test/?p=1', 'edit_url' => 'https://example.test/wp-admin/post.php?post=1&action=edit' ) ),
		);

		$cards = Result_Card::collect( $tool_results );

		$this->assertCount( 1, $cards );
		$this->assertSame( 'post', $cards[0]['type'] );
	}

	/**
	 * summarize() produces a human-readable sentence from cards.
	 */
	public function test_summarize(): void {
		$cards = array(
			Result_Card::normalize( 'create_post_content', array( 'post_id' => 1, 'title' => 'Hello', 'url' => 'https://example.test/?p=1', 'edit_url' => 'https://example.test/wp-admin/post.php?post=1&action=edit' ) ),
			Result_Card::normalize( 'list_posts', array( 'total_returned' => 5, 'posts' => array() ) ),
		);

		$summary = Result_Card::summarize( $cards );

		$this->assertSame( 'Created a post "Hello" and listed 5 posts.', $summary );

		$this->assertSame( '', Result_Card::summarize( array() ) );
	}

	/**
	 * A full autonomous run that calls a recognized tool (list_comments) surfaces a
	 * non-empty cards array and a result_summary in the success return.
	 */
	public function test_autonomous_run_produces_cards(): void {
		$agent_id = 'test-autonomous-cards';
		$fake     = new Fake_LLM_Client(
			array(
				Fake_LLM_Client::tool_call_response( 'list_comments', array( 'status' => 'approve' ), array( 'prompt_tokens' => 8, 'completion_tokens' => 4, 'total_tokens' => 12 ) ),
				Fake_LLM_Client::text_response( 'Listed comments.', array( 'prompt_tokens' => 6, 'completion_tokens' => 3, 'total_tokens' => 9 ) ),
			)
		);

		$controller = new Agent_Controller( $fake );
		$result     = $controller->run_autonomous_task( $this->make_agent( $agent_id ), 'List the comments.', 'task-cards' );

		$this->assertSame( 'completed', $result['status'] );
		$this->assertNotEmpty( $result['cards'], 'a recognized tool must produce a card' );
		$this->assertSame( 'list', $result['cards'][0]['type'] );
		$this->assertSame( 'list_comments', $result['cards'][0]['tool'] );
		$this->assertNotEmpty( $result['result_summary'] );
	}

	/**
	 * An unrecognized connector tool with no filter registered still maps to
	 * null (the seam adds nothing when nothing hooks it).
	 */
	public function test_external_card_no_filter_returns_null(): void {
		$this->assertNull( Result_Card::normalize( 'mcp:github:list_issues', array( 'issues' => array() ) ) );
	}

	/**
	 * A filter returning a well-formed card is returned, sanitized to only the
	 * keys the built-in cards use, with tags stripped and scalars coerced.
	 */
	public function test_external_card_filter_returns_sanitised_card(): void {
		add_filter(
			'agent_builder_result_card',
			static function () {
				return array(
					'type'     => 'post',
					'tool'     => 'mcp:github:create_issue',
					'title'    => 'Hello <b>World</b>',
					'action'   => 'created',
					'post_id'  => '42',
					'status'   => 'open',
					'edit_url' => '/wp-admin/post.php?post=42&action=edit',
					'view_url' => '/?p=42',
					'junk'     => 'drop me',
				);
			},
			10,
			3
		);

		$card = Result_Card::normalize( 'mcp:github:create_issue', array( 'ok' => true ) );

		$this->assertIsArray( $card );
		$this->assertSame( 'post', $card['type'] );
		$this->assertSame( 'mcp:github:create_issue', $card['tool'] );
		$this->assertSame( 'Hello World', $card['title'] );
		$this->assertSame( 'created', $card['action'] );
		$this->assertSame( 42, $card['post_id'] );
		$this->assertSame( 'open', $card['status'] );
		$this->assertSame( '/wp-admin/post.php?post=42&action=edit', $card['edit_url'] );
		$this->assertSame( '/?p=42', $card['view_url'] );
		$this->assertArrayNotHasKey( 'junk', $card );
	}

	/**
	 * A filter returning an unsupported type (or a non-array) maps to null.
	 */
	public function test_external_card_unknown_type_or_non_array_returns_null(): void {
		add_filter(
			'agent_builder_result_card',
			static function () {
				return array( 'type' => 'text', 'title' => 'Excerpt', 'text' => 'raw' );
			},
			10,
			3
		);

		$this->assertNull( Result_Card::normalize( 'mcp:github:list_issues', array( 'issues' => array() ) ) );

		remove_all_filters( 'agent_builder_result_card' );
		add_filter(
			'agent_builder_result_card',
			static function () {
				return 'not-an-array';
			},
			10,
			3
		);

		$this->assertNull( Result_Card::normalize( 'mcp:github:list_issues', array( 'issues' => array() ) ) );
	}

	/**
	 * Unsafe URLs (javascript:, data:, off-site, or a backslash/whitespace
	 * escape) are dropped from an external card rather than surfaced.
	 */
	public function test_external_card_rejects_unsafe_urls(): void {
		$bad = array(
			'javascript:alert(1)',
			'data:text/html,<script>alert(1)</script>',
			'//evil.com/steal',
			'https://evil.com/steal',
			"/\\evil.com",
			"/\t/evil.com",
		);

		foreach ( $bad as $url ) {
			remove_all_filters( 'agent_builder_result_card' );
			add_filter(
				'agent_builder_result_card',
				static function () use ( $url ) {
					return array(
						'type'     => 'post',
						'title'    => 'Unsafe',
						'edit_url' => $url,
						'view_url' => $url,
					);
				},
				10,
				3
			);

			$card = Result_Card::normalize( 'mcp:github:create_issue', array( 'ok' => true ) );
			$this->assertIsArray( $card, 'an unsafe URL must still produce a card without the URL' );
			$this->assertArrayNotHasKey( 'edit_url', $card );
			$this->assertArrayNotHasKey( 'view_url', $card );
		}

		remove_all_filters( 'agent_builder_result_card' );
		add_filter(
			'agent_builder_result_card',
			static function () {
				return array( 'type' => 'file', 'title' => 'report.docx', 'url' => 'javascript:alert(1)' );
			},
			10,
			3
		);

		$file = Result_Card::normalize( 'mcp:drive:export', array( 'ok' => true ) );
		$this->assertIsArray( $file );
		$this->assertArrayNotHasKey( 'url', $file );
	}

	/**
	 * HTML in an external card's string fields is stripped.
	 */
	public function test_external_card_strips_html(): void {
		add_filter(
			'agent_builder_result_card',
			static function () {
				return array(
					'type'   => 'post',
					'title'  => '<script>alert(1)</script>Safe Title',
					'status' => '<em>draft</em>',
				);
			},
			10,
			3
		);

		$card = Result_Card::normalize( 'mcp:github:create_issue', array( 'ok' => true ) );

		$this->assertIsArray( $card );
		$this->assertSame( 'Safe Title', $card['title'] );
		$this->assertSame( 'draft', $card['status'] );
	}

	/**
	 * Oversized titles are capped and oversized item lists are trimmed to the
	 * sample limit.
	 */
	public function test_external_card_caps_oversized_strings_and_lists(): void {
		add_filter(
			'agent_builder_result_card',
			static function () {
				$items = array();
				for ( $i = 1; $i <= 30; $i++ ) {
					$items[] = array( 'id' => $i, 'title' => 'Item ' . $i );
				}

				return array(
					'type'  => 'list',
					'title' => str_repeat( 'x', 300 ),
					'count' => 30,
					'items' => $items,
				);
			},
			10,
			3
		);

		$card = Result_Card::normalize( 'mcp:github:list_issues', array( 'ok' => true ) );

		$this->assertIsArray( $card );
		$this->assertSame( 200, strlen( $card['title'] ) );
		$this->assertCount( 20, $card['items'] );
		$this->assertSame( 1, $card['items'][0]['id'] );
		$this->assertSame( 'Item 1', $card['items'][0]['title'] );
	}

	/**
	 * A file and a list external card are accepted and sanitized through their
	 * own type-specific fields.
	 */
	public function test_external_card_file_and_list_types(): void {
		add_filter(
			'agent_builder_result_card',
			static function () {
				return array(
					'type'  => 'file',
					'title' => 'report.docx',
					'url'   => '/wp-content/uploads/agentic-exports/report.docx',
				);
			},
			10,
			3
		);

		$file = Result_Card::normalize( 'mcp:drive:export', array( 'ok' => true ) );
		$this->assertIsArray( $file );
		$this->assertSame( 'file', $file['type'] );
		$this->assertSame( 'report.docx', $file['title'] );
		$this->assertSame( '/wp-content/uploads/agentic-exports/report.docx', $file['url'] );

		remove_all_filters( 'agent_builder_result_card' );
		add_filter(
			'agent_builder_result_card',
			static function () {
				return array(
					'type'  => 'list',
					'title' => 'Issues',
					'count' => 2,
					'items' => array(
						array( 'id' => 10, 'title' => 'First' ),
						array( 'id' => 11, 'title' => 'Second' ),
					),
				);
			},
			10,
			3
		);

		$list = Result_Card::normalize( 'mcp:github:list_issues', array( 'ok' => true ) );
		$this->assertIsArray( $list );
		$this->assertSame( 'list', $list['type'] );
		$this->assertSame( 'Issues', $list['title'] );
		$this->assertSame( 2, $list['count'] );
		$this->assertCount( 2, $list['items'] );
	}

	/**
	 * An error result short-circuits before the filter runs, so a connector can
	 * never turn a failed tool call into a card.
	 */
	public function test_external_card_error_result_never_reaches_filter(): void {
		$called = false;
		add_filter(
			'agent_builder_result_card',
			static function () use ( &$called ) {
				$called = true;
				return array( 'type' => 'post', 'title' => 'Should not appear' );
			},
			10,
			3
		);

		$card = Result_Card::normalize( 'mcp:github:create_issue', array( 'error' => 'rate limited' ) );

		$this->assertNull( $card );
		$this->assertFalse( $called, 'an error result must not reach the filter' );
	}

	/**
	 * The filter receives the tool name and the raw, unmodified result.
	 */
	public function test_external_card_filter_receives_tool_and_raw_result(): void {
		$seen_tool   = null;
		$seen_result = null;
		add_filter(
			'agent_builder_result_card',
			static function ( $card, $tool, $result ) use ( &$seen_tool, &$seen_result ) {
				$seen_tool   = $tool;
				$seen_result = $result;
				return null;
			},
			10,
			3
		);

		$raw = array( 'content' => 'raw payload', 'nested' => array( 'a' => 1 ) );
		Result_Card::normalize( 'mcp:github:list_issues', $raw );

		$this->assertSame( 'mcp:github:list_issues', $seen_tool );
		$this->assertSame( $raw, $seen_result );
	}

	/**
	 * summarize() copes with a card type it does not know by falling back to the
	 * card title, so an unrecognized card still yields a readable clause.
	 */
	public function test_summarize_unknown_card_type_falls_back_to_title(): void {
		$summary = Result_Card::summarize( array( array( 'type' => 'connector', 'title' => 'Synced 3 issues' ) ) );

		$this->assertSame( 'Synced 3 issues.', $summary );
	}
}
