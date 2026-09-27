<?php
/**
 * Unit Tests for Agent_Run.
 *
 * Covers the general run context introduced in M10: begin()/load() round
 * trips, mark_waiting()/resume_state(), record_iteration(), cooperative
 * cancellation, finish() statuses, and the query()/counts() read paths.
 *
 * @package Agentic\Tests
 */

namespace Agentic\Tests;

use Agentic\Agent_Run;

/**
 * Test case for Agent_Run.
 */
class Test_Agent_Run extends TestCase {

	/**
	 * Reset the in-process run between tests.
	 */
	public function setUp(): void {
		parent::setUp();
		Agent_Run::reset_current_for_tests();
	}

	/**
	 * Clear the in-process run after each test too, on top of the base
	 * TestCase's own reset, so a run left "current" by a failed assertion
	 * mid-test never survives into the next test.
	 */
	public function tearDown(): void {
		Agent_Run::reset_current_for_tests();
		parent::tearDown();
	}

	/**
	 * begin() with opts persists a row carrying every option field.
	 */
	public function test_begin_with_opts_persists_row(): void {
		$run = Agent_Run::begin(
			'wordpress-assistant',
			array(
				'kind'          => 'routine',
				'user_id'       => 7,
				'task_text'     => 'Summarise the newest posts',
				'parent_run_id' => 'parent-run-id',
				'job_id'        => 'job-123',
				'session_id'    => 'session-abc',
				'invocation'    => 'cron',
				'source_ref'    => 'routine:12',
			)
		);

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test assertion against the persisted row.
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}agent_builder_runs WHERE run_id = %s", $run->get_run_id() ),
			ARRAY_A
		);

		$this->assertIsArray( $row );
		$this->assertSame( 'wordpress-assistant', $row['root_agent'] );
		$this->assertSame( 'routine', $row['kind'] );
		$this->assertSame( 'running', $row['status'] );
		$this->assertSame( '7', $row['user_id'] );
		$this->assertSame( 'Summarise the newest posts', $row['task_text'] );
		$this->assertSame( 'parent-run-id', $row['parent_run_id'] );
		$this->assertSame( 'job-123', $row['job_id'] );
		$this->assertSame( 'session-abc', $row['session_id'] );
		$this->assertSame( 'cron', $row['invocation'] );
		$this->assertSame( 'routine:12', $row['source_ref'] );
	}

	/**
	 * A run started without opts defaults kind to 'task'.
	 */
	public function test_begin_without_opts_defaults_kind_to_task(): void {
		$run = Agent_Run::begin( 'content-writer' );

		$this->assertSame( 'task', $run->to_array()['kind'] );
	}

	/**
	 * load() round-trips every field written by begin().
	 */
	public function test_load_round_trips_begin(): void {
		$run = Agent_Run::begin(
			'seo-optimizer',
			array(
				'kind'       => 'delegation',
				'user_id'    => 3,
				'task_text'  => 'Audit the sitemap',
				'invocation' => 'chat',
			)
		);
		$run_id = $run->get_run_id();

		$loaded = Agent_Run::load( $run_id );

		$this->assertInstanceOf( Agent_Run::class, $loaded );
		$data = $loaded->to_array();
		$this->assertSame( $run_id, $data['run_id'] );
		$this->assertSame( 'seo-optimizer', $data['root_agent'] );
		$this->assertSame( 'delegation', $data['kind'] );
		$this->assertSame( 3, $data['user_id'] );
		$this->assertSame( 'Audit the sitemap', $data['task_text'] );
		$this->assertSame( 'chat', $data['invocation'] );
		$this->assertSame( 'running', $data['status'] );
	}

	/**
	 * load() returns null for an unknown run id.
	 */
	public function test_load_returns_null_for_unknown_run_id(): void {
		$this->assertNull( Agent_Run::load( 'does-not-exist' ) );
	}

	/**
	 * A nested begin() call (one made while a run is already current) returns
	 * the same instance and ignores the opts it was given — this is what
	 * keeps delegate_to_agent's delegation semantics unchanged.
	 */
	public function test_nested_begin_returns_the_active_run(): void {
		$outer = Agent_Run::begin( 'content-writer', array( 'kind' => 'task' ) );
		$inner = Agent_Run::begin( 'seo-optimizer', array( 'kind' => 'delegation' ) );

		$this->assertSame( $outer, $inner );
		$this->assertSame( $outer->get_run_id(), $inner->get_run_id() );
		$this->assertSame( 'task', $inner->to_array()['kind'] );
	}

	/**
	 * mark_waiting() flips status to 'waiting' and persists the awaiting
	 * pointer + a transcript that resume_state() then returns.
	 */
	public function test_mark_waiting_sets_status_and_resume_state_returns_transcript(): void {
		$run = Agent_Run::begin( 'content-writer' );

		$transcript = array(
			array(
				'role'    => 'user',
				'content' => 'Publish the draft.',
			),
			array(
				'role'    => 'assistant',
				'content' => 'I need approval to publish.',
			),
		);

		$run->mark_waiting( 'approval', '42', $transcript );

		$this->assertSame( $transcript, $run->resume_state()['messages'] );
		$this->assertSame( 'approval', $run->resume_state()['awaiting_type'] );
		$this->assertSame( '42', $run->resume_state()['awaiting_id'] );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test assertion against the persisted row.
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}agent_builder_runs WHERE run_id = %s", $run->get_run_id() ),
			ARRAY_A
		);
		$this->assertSame( 'waiting', $row['status'] );
		$this->assertSame( 'approval', $row['awaiting_type'] );
		$this->assertSame( '42', $row['awaiting_id'] );

		// A freshly loaded instance of the same row also resumes the transcript.
		$reloaded = Agent_Run::load( $run->get_run_id() );
		$this->assertSame( $transcript, $reloaded->resume_state()['messages'] );
	}

	/**
	 * mark_waiting() strips image parts out of the transcript before persisting.
	 */
	public function test_mark_waiting_strips_image_payloads(): void {
		$run = Agent_Run::begin( 'content-writer' );

		$transcript = array(
			array(
				'role'    => 'user',
				'content' => array(
					array(
						'type' => 'text',
						'text' => 'Look at this screenshot.',
					),
					array(
						'type'      => 'image_url',
						'image_url' => array( 'url' => 'data:image/png;base64,AAAA' ),
					),
				),
			),
		);

		$run->mark_waiting( 'proposal', 'p-1', $transcript );

		$stored = $run->resume_state()['messages'];
		$this->assertArrayNotHasKey( 'image_url', $stored[0]['content'][1] );
		$this->assertTrue( $stored[0]['content'][1]['omitted'] );
	}

	/**
	 * A single message that alone exceeds the 200KB transcript cap is dropped
	 * rather than left in the persisted transcript over the declared bound.
	 */
	public function test_mark_waiting_drops_a_single_oversized_message(): void {
		$run = Agent_Run::begin( 'content-writer' );

		$oversized_transcript = array(
			array(
				'role'    => 'assistant',
				'content' => str_repeat( 'x', 250 * 1024 ),
			),
		);

		$run->mark_waiting( 'approval', '42', $oversized_transcript );

		$this->assertSame( array(), $run->resume_state()['messages'] );
	}

	/**
	 * mark_waiting() clears Agent_Run::current() the same way finish() does,
	 * so a later begin() in the same process starts a fresh run rather than
	 * getting back the already-settled waiting instance.
	 */
	public function test_mark_waiting_clears_current_run(): void {
		$run = Agent_Run::begin( 'content-writer' );
		$this->assertSame( $run, Agent_Run::current() );

		$run->mark_waiting( 'approval', '42', array() );

		$this->assertNull( Agent_Run::current() );

		$next = Agent_Run::begin( 'seo-optimizer' );
		$this->assertNotSame( $run, $next );
		$this->assertNotSame( $run->get_run_id(), $next->get_run_id() );

		$next->finish( 'completed' );
	}

	/**
	 * A run whose state column was written before 2.15.0 (the bare
	 * scratchpad object, with no {scratchpad, messages} wrapper) still loads
	 * its scratchpad correctly instead of it being silently dropped.
	 */
	public function test_load_recovers_scratchpad_from_legacy_flat_state_shape(): void {
		$run    = Agent_Run::begin( 'content-writer' );
		$run_id = $run->get_run_id();

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Simulating a pre-2.15.0 row shape for the test.
		$wpdb->update(
			$wpdb->prefix . 'agent_builder_runs',
			array( 'state' => wp_json_encode( array( 'delegated_key' => 'delegated_value' ) ) ),
			array( 'run_id' => $run_id )
		);

		$reloaded = Agent_Run::load( $run_id );

		$this->assertSame( 'delegated_value', $reloaded->scratch_get( 'delegated_key' ) );
		$this->assertSame( array(), $reloaded->resume_state()['messages'] );

		$run->finish( 'completed' );
	}

	/**
	 * A legacy flat state shape that happens to contain a key literally named
	 * "scratchpad" (but not also "messages") must still be recovered as the
	 * whole legacy scratchpad, not misdetected as the current {scratchpad,
	 * messages} wrapper — which would otherwise discard the real data and
	 * keep only whatever sat under that one key.
	 */
	public function test_load_recovers_legacy_state_that_contains_a_scratchpad_shaped_key(): void {
		$run    = Agent_Run::begin( 'content-writer' );
		$run_id = $run->get_run_id();

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Simulating a pre-2.15.0 row shape for the test.
		$wpdb->update(
			$wpdb->prefix . 'agent_builder_runs',
			array(
				'state' => wp_json_encode(
					array(
						'scratchpad'    => 'not-an-array-just-a-legacy-field',
						'delegated_key' => 'delegated_value',
					)
				),
			),
			array( 'run_id' => $run_id )
		);

		$reloaded = Agent_Run::load( $run_id );

		$this->assertSame( 'delegated_value', $reloaded->scratch_get( 'delegated_key' ) );
		$this->assertSame( 'not-an-array-just-a-legacy-field', $reloaded->scratch_get( 'scratchpad' ) );
		$this->assertSame( array(), $reloaded->resume_state()['messages'] );

		$run->finish( 'completed' );
	}

	/**
	 * record_iteration() sums iterations, tokens, cost, and unions tool names
	 * across multiple calls.
	 */
	public function test_record_iteration_sums_across_calls(): void {
		$run = Agent_Run::begin( 'content-writer' );

		$run->record_iteration( array( 'list_posts' ), 100, 0.01 );
		$run->record_iteration( array( 'list_posts', 'get_post_content' ), 50, 0.02 );

		$data = $run->to_array();
		$this->assertSame( 2, $data['iterations'] );
		$this->assertSame( 150, $data['tokens_used'] );
		$this->assertEqualsWithDelta( 0.03, $data['cost'], 0.000001 );
		sort( $data['tools_used'] );
		$this->assertSame( array( 'get_post_content', 'list_posts' ), $data['tools_used'] );
	}

	/**
	 * request_cancel() on one instance is visible through cancel_requested()
	 * on a second instance loaded from the same row — the flag must be read
	 * from storage, not the caller's own stale copy.
	 */
	public function test_request_cancel_is_visible_through_a_second_instance(): void {
		$run = Agent_Run::begin( 'content-writer' );

		$other = Agent_Run::load( $run->get_run_id() );
		$this->assertFalse( $other->cancel_requested() );

		$run->request_cancel();

		$this->assertTrue( $other->cancel_requested() );
	}

	/**
	 * finish() accepts every M10 terminal status and is idempotent.
	 */
	public function test_finish_accepts_each_status_and_is_idempotent(): void {
		foreach ( array( 'completed', 'failed', 'aborted', 'cancelled' ) as $status ) {
			Agent_Run::reset_current_for_tests();
			$run = Agent_Run::begin( 'content-writer' );

			$run->finish( $status, array( 'text' => 'done: ' . $status ) );
			$run->finish( 'completed' ); // Second call must be a no-op.

			$data = $run->to_array();
			$this->assertSame( $status, $data['status'] );
			$this->assertSame( 'done: ' . $status, $data['result_summary']['text'] );
		}
	}

	/**
	 * finish() clears Agent_Run::current() when it belongs to the run being
	 * finished.
	 */
	public function test_finish_clears_current_run(): void {
		$run = Agent_Run::begin( 'content-writer' );
		$this->assertSame( $run, Agent_Run::current() );

		$run->finish( 'completed' );

		$this->assertNull( Agent_Run::current() );
	}

	/**
	 * query() filters by status, kind, agent, user, source_ref and
	 * parent_run_id, newest first, and paginates.
	 */
	public function test_query_filters_and_paginates(): void {
		$run_a = Agent_Run::begin( 'content-writer', array( 'kind' => 'task', 'user_id' => 11, 'source_ref' => 'routine:1' ) );
		$run_a->finish( 'completed' );
		Agent_Run::reset_current_for_tests();

		$run_b = Agent_Run::begin( 'seo-optimizer', array( 'kind' => 'routine', 'user_id' => 11, 'parent_run_id' => $run_a->get_run_id() ) );
		Agent_Run::reset_current_for_tests();

		$run_c = Agent_Run::begin( 'content-writer', array( 'kind' => 'task', 'user_id' => 99 ) );
		Agent_Run::reset_current_for_tests();

		$by_user = Agent_Run::query( array( 'user_id' => 11 ) );
		$this->assertCount( 2, $by_user );
		// Newest first.
		$this->assertSame( $run_b->get_run_id(), $by_user[0]['run_id'] );
		$this->assertSame( $run_a->get_run_id(), $by_user[1]['run_id'] );

		$by_status = Agent_Run::query( array( 'status' => 'completed' ) );
		$ids       = array_column( $by_status, 'run_id' );
		$this->assertContains( $run_a->get_run_id(), $ids );
		$this->assertNotContains( $run_b->get_run_id(), $ids );

		$by_kind = Agent_Run::query( array( 'kind' => 'routine' ) );
		$this->assertSame( array( $run_b->get_run_id() ), array_column( $by_kind, 'run_id' ) );

		$by_agent = Agent_Run::query( array( 'agent' => 'seo-optimizer' ) );
		$this->assertSame( array( $run_b->get_run_id() ), array_column( $by_agent, 'run_id' ) );

		$by_source_ref = Agent_Run::query( array( 'source_ref' => 'routine:1' ) );
		$this->assertSame( array( $run_a->get_run_id() ), array_column( $by_source_ref, 'run_id' ) );

		$by_parent = Agent_Run::query( array( 'parent_run_id' => $run_a->get_run_id() ) );
		$this->assertSame( array( $run_b->get_run_id() ), array_column( $by_parent, 'run_id' ) );

		$page_1 = Agent_Run::query( array( 'user_id' => 11, 'per_page' => 1, 'page' => 1 ) );
		$page_2 = Agent_Run::query( array( 'user_id' => 11, 'per_page' => 1, 'page' => 2 ) );
		$this->assertCount( 1, $page_1 );
		$this->assertCount( 1, $page_2 );
		$this->assertNotSame( $page_1[0]['run_id'], $page_2[0]['run_id'] );

		$run_c->finish( 'completed' );
	}

	/**
	 * counts() returns per-status totals scoped to one user.
	 */
	public function test_counts_scopes_by_user_and_groups_by_status(): void {
		$run_a = Agent_Run::begin( 'content-writer', array( 'user_id' => 21 ) );
		$run_a->finish( 'completed' );
		Agent_Run::reset_current_for_tests();

		$run_b = Agent_Run::begin( 'content-writer', array( 'user_id' => 21 ) );
		Agent_Run::reset_current_for_tests();

		$run_c = Agent_Run::begin( 'content-writer', array( 'user_id' => 22 ) );

		$counts = Agent_Run::counts( 21 );

		$this->assertSame( 1, $counts['completed'] );
		$this->assertSame( 1, $counts['running'] );
		$this->assertArrayNotHasKey( 'aborted', $counts );

		$run_c->finish( 'completed' );
	}
}
