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
}
