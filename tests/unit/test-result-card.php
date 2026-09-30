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
	 * create_post_content returns a full post card (created action), reusing the
	 * tool's own url/edit_url rather than inventing fields.
	 */
	public function test_normalize_create_post_content(): void {
		$result = array(
			'success'  => true,
			'post_id'  => 123,
			'status'   => 'draft',
			'title'    => 'Hello World',
			'url'      => 'https://example.test/?p=123',
			'edit_url' => 'https://example.test/wp-admin/post.php?post=123&action=edit',
			'message'  => 'Draft saved (ID: 123).',
		);

		$card = Result_Card::normalize( 'create_post_content', $result );

		$this->assertIsArray( $card );
		$this->assertSame( 'post', $card['type'] );
		$this->assertSame( 'create_post_content', $card['tool'] );
		$this->assertSame( 'Hello World', $card['title'] );
		$this->assertSame( 'created', $card['action'] );
		$this->assertSame( 123, $card['post_id'] );
		$this->assertSame( 'https://example.test/wp-admin/post.php?post=123&action=edit', $card['edit_url'] );
		$this->assertSame( 'https://example.test/?p=123', $card['view_url'] );
	}

	/**
	 * db_create_post has edit_link (not edit_url) and no url — normalize must
	 * map edit_link to edit_url and leave view_url a string.
	 */
	public function test_normalize_db_create_post_maps_edit_link(): void {
		$result = array(
			'post_id'   => 456,
			'title'     => 'Draft Post',
			'status'    => 'draft',
			'type'      => 'post',
			'edit_link' => 'https://example.test/wp-admin/post.php?post=456&action=edit',
		);

		$card = Result_Card::normalize( 'db_create_post', $result );

		$this->assertIsArray( $card );
		$this->assertSame( 'post', $card['type'] );
		$this->assertSame( 'created', $card['action'] );
		$this->assertSame( 456, $card['post_id'] );
		$this->assertSame( 'https://example.test/wp-admin/post.php?post=456&action=edit', $card['edit_url'] );
		$this->assertIsString( $card['view_url'] );
	}

	/**
	 * db_update_post and update_post_content normalize to an "updated" action.
	 */
	public function test_normalize_update_tools_report_updated_action(): void {
		$db_update = Result_Card::normalize(
			'db_update_post',
			array(
				'post_id'   => 789,
				'title'     => 'Updated Title',
				'status'    => 'draft',
				'modified'  => '2026-01-01 00:00:00',
				'edit_link' => 'https://example.test/wp-admin/post.php?post=789&action=edit',
			)
		);
		$this->assertIsArray( $db_update );
		$this->assertSame( 'updated', $db_update['action'] );
		$this->assertSame( 789, $db_update['post_id'] );

		$update_content = Result_Card::normalize(
			'update_post_content',
			array(
				'success' => true,
				'post_id' => 789,
				'status'  => 'draft',
				'url'     => 'https://example.test/?p=789',
				'message' => 'Post ID 789 updated successfully.',
			)
		);
		$this->assertIsArray( $update_content );
		$this->assertSame( 'updated', $update_content['action'] );
		$this->assertSame( 'https://example.test/?p=789', $update_content['view_url'] );
		$this->assertIsString( $update_content['edit_url'] );
	}

	/**
	 * File tools normalize to a file card carrying the path + public url.
	 */
	public function test_normalize_file_tool(): void {
		$result = array(
			'file_path'    => 'agentic-exports/report.docx',
			'url'          => 'https://example.test/wp-content/uploads/agentic-exports/report.docx',
			'file_size_kb' => 12.4,
		);

		$card = Result_Card::normalize( 'create_docx', $result );

		$this->assertIsArray( $card );
		$this->assertSame( 'file', $card['type'] );
		$this->assertSame( 'create_docx', $card['tool'] );
		$this->assertSame( 'report.docx', $card['title'] );
		$this->assertSame( 'agentic-exports/report.docx', $card['path'] );
		$this->assertSame( 'https://example.test/wp-content/uploads/agentic-exports/report.docx', $card['url'] );
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
