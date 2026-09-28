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
	 * mark_waiting()'s optional tool_call_id is tracked separately from the
	 * approval/proposal business id, and both round-trip through
	 * resume_state() and a freshly loaded instance.
	 */
	public function test_mark_waiting_tracks_tool_call_id_separately_from_business_id(): void {
		$run = Agent_Run::begin( 'content-writer' );

		$run->mark_waiting( 'proposal', 'proposal-uuid-1', array(), 'call_abc123' );

		$state = $run->resume_state();
		$this->assertSame( 'proposal-uuid-1', $state['awaiting_id'] );
		$this->assertSame( 'call_abc123', $state['awaiting_tool_call_id'] );
		$this->assertNotSame( $state['awaiting_id'], $state['awaiting_tool_call_id'] );

		$reloaded = Agent_Run::load( $run->get_run_id() );
		$this->assertSame( 'call_abc123', $reloaded->resume_state()['awaiting_tool_call_id'] );
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
	 * mark_waiting() must still transition the run to 'waiting' (and persist the
	 * awaiting pointer + transcript) when the awaiting_tool_call_id column does
	 * not exist yet — a stale pre-migration schema on a cron/REST/frontend
	 * request. Including the unknown column in the UPDATE would fail the whole
	 * write and silently leave the run 'running'.
	 */
	public function test_mark_waiting_persists_waiting_without_awaiting_tool_call_id_column(): void {
		$run = Agent_Run::begin( 'content-writer' );

		global $wpdb;
		$table = $wpdb->prefix . 'agent_builder_runs';

		// Simulate the stale schema: drop the column the migration hasn't added
		// yet. DDL isn't rolled back by the per-test transaction, so restore it
		// in the finally block below.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- Test-only schema change on a trusted internal table name.
		$wpdb->query( "ALTER TABLE {$table} DROP COLUMN awaiting_tool_call_id" );

		try {
			$transcript = array(
				array( 'role' => 'user', 'content' => 'Publish the draft.' ),
				array( 'role' => 'assistant', 'content' => 'I need approval to publish.' ),
			);

			$run->mark_waiting( 'proposal', 'proposal-uuid-1', $transcript, 'call_abc123' );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test assertion against the persisted row.
			$row = $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE run_id = %s", $run->get_run_id() ),
				ARRAY_A
			);

			$this->assertSame( 'waiting', $row['status'], 'the run must transition to waiting even without the awaiting_tool_call_id column' );
			$this->assertSame( 'proposal', $row['awaiting_type'] );
			$this->assertSame( 'proposal-uuid-1', $row['awaiting_id'] );

			// The transcript must still be persisted (so a resume can derive the
			// original tool-call id from it when the column value is empty).
			$reloaded = Agent_Run::load( $run->get_run_id() );
			$this->assertSame( $transcript, $reloaded->resume_state()['messages'] );
			$this->assertSame( '', $reloaded->resume_state()['awaiting_tool_call_id'] );
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- Restore the column so later tests see a full schema.
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN awaiting_tool_call_id varchar(64) DEFAULT NULL AFTER awaiting_id" );
		}
	}

	/**
	 * mark_waiting() must not settle the in-process instance when its DB write
	 * fails: a transient error leaves the row still 'running', so the shutdown
	 * safety net must still be able to mark it 'aborted' rather than strand it
	 * forever (mirrors test_mark_continuing_write_failure_leaves_run_unsettled).
	 */
	public function test_mark_waiting_write_failure_leaves_run_unsettled(): void {
		global $wpdb;
		$run   = Agent_Run::begin( 'content-writer' );
		$table = $wpdb->prefix . 'agent_builder_runs';

		// Force mark_waiting()'s UPDATE to fail (as a transient DB error would),
		// without touching any other query the request makes.
		$mangle = static function ( $query ) {
			if ( is_string( $query ) && false !== stripos( $query, "'waiting'" ) ) {
				return false;
			}
			return $query;
		};
		add_filter( 'query', $mangle );

		try {
			$run->mark_waiting( 'approval', '42', array() );
		} finally {
			remove_filter( 'query', $mangle );
		}

		// The write never landed: the row is still 'running', not 'waiting'.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test assertion against the persisted row.
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT status FROM {$table} WHERE run_id = %s", $run->get_run_id() ),
			ARRAY_A
		);
		$this->assertSame( 'running', $row['status'] );

		// Because the instance stayed unsettled, a later finish() — standing in
		// for the shutdown guard — is NOT a no-op and can still abort the run.
		$run->finish( 'aborted' );
		$this->assertSame( 'aborted', $run->to_array()['status'] );
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
	 * mark_continuing() moves the run to a non-terminal 'continuing' status
	 * and settles the in-process instance (mirroring mark_waiting()) so a
	 * later finish() call — standing in for the shutdown safety net firing
	 * at request end — is a no-op and never overwrites the hand-off with
	 * 'aborted'.
	 */
	public function test_mark_continuing_is_non_terminal_and_blocks_later_finish(): void {
		$run = Agent_Run::begin( 'content-writer' );

		$run->mark_continuing();

		$this->assertSame( 'continuing', $run->to_array()['status'] );

		// Stand-in for register_shutdown_guard() firing at request end.
		$run->finish( 'aborted' );

		$this->assertSame( 'continuing', $run->to_array()['status'] );

		$reloaded = Agent_Run::load( $run->get_run_id() );
		$this->assertSame( 'continuing', $reloaded->to_array()['status'] );
	}

	/**
	 * mark_continuing() must not settle the in-process instance when its DB
	 * write fails: a transient error leaves the row still 'running', so the
	 * shutdown safety net must still be able to mark it 'aborted' rather than
	 * strand it forever.
	 */
	public function test_mark_continuing_write_failure_leaves_run_unsettled(): void {
		global $wpdb;
		$run   = Agent_Run::begin( 'content-writer' );
		$table = $wpdb->prefix . 'agent_builder_runs';

		// Force mark_continuing()'s UPDATE to fail (as a transient DB error
		// would), without touching any other query the request makes.
		$mangle = static function ( $query ) {
			if ( is_string( $query ) && false !== stripos( $query, "'continuing'" ) ) {
				return false;
			}
			return $query;
		};
		add_filter( 'query', $mangle );

		try {
			$run->mark_continuing();
		} finally {
			remove_filter( 'query', $mangle );
		}

		// The write never landed: the row is still 'running', not 'continuing'.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test assertion against the persisted row.
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT status FROM {$table} WHERE run_id = %s", $run->get_run_id() ),
			ARRAY_A
		);
		$this->assertSame( 'running', $row['status'] );

		// Because the instance stayed unsettled, a later finish() — standing in
		// for the shutdown guard — is NOT a no-op and can still abort the run.
		$run->finish( 'aborted' );
		$this->assertSame( 'aborted', $run->to_array()['status'] );
	}

	/**
	 * mark_waiting() settles the run and releases the current-run pointer, so a
	 * later begin() in the same request starts a genuinely new run instead of
	 * returning the already-waiting one.
	 */
	public function test_begin_after_mark_waiting_returns_a_fresh_run(): void {
		$run    = Agent_Run::begin( 'content-writer' );
		$run_id = $run->get_run_id();

		$run->mark_waiting( 'approval', '1', array() );

		$this->assertNull( Agent_Run::current() );

		$fresh = Agent_Run::begin( 'seo-optimizer' );
		$this->assertNotSame( $run, $fresh );
		$this->assertNotSame( $run_id, $fresh->get_run_id() );
		$this->assertSame( 'seo-optimizer', $fresh->get_root_agent() );
	}

	/**
	 * mark_continuing() likewise settles the run and releases the current-run
	 * pointer, so a later begin() starts a fresh run rather than returning the
	 * handed-off one.
	 */
	public function test_begin_after_mark_continuing_returns_a_fresh_run(): void {
		$run    = Agent_Run::begin( 'content-writer' );
		$run_id = $run->get_run_id();

		$run->mark_continuing();

		$this->assertNull( Agent_Run::current() );

		$fresh = Agent_Run::begin( 'seo-optimizer' );
		$this->assertNotSame( $run, $fresh );
		$this->assertNotSame( $run_id, $fresh->get_run_id() );
	}

	/**
	 * get_status() and get_root_agent() expose the fields resume validation
	 * needs to reject replaying a terminal or mismatched-agent run.
	 */
	public function test_get_status_and_get_root_agent_accessors(): void {
		$run = Agent_Run::begin( 'seo-optimizer', array( 'kind' => 'task' ) );

		$this->assertSame( 'running', $run->get_status() );
		$this->assertSame( 'seo-optimizer', $run->get_root_agent() );

		$run->mark_waiting( 'approval', '1', array() );
		$this->assertSame( 'waiting', $run->get_status() );

		$reloaded = Agent_Run::load( $run->get_run_id() );
		$this->assertSame( 'waiting', $reloaded->get_status() );
		$this->assertSame( 'seo-optimizer', $reloaded->get_root_agent() );
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

	/**
	 * claim_resume() atomically flips a 'waiting'/'continuing' run to
	 * 'running' and reports success — the normal, uncontested path.
	 */
	public function test_claim_resume_succeeds_from_waiting_or_continuing(): void {
		$waiting = Agent_Run::begin( 'content-writer' );
		$waiting->mark_waiting( 'approval', '1', array() );
		$this->assertTrue( $waiting->claim_resume() );
		$this->assertSame( 'running', $waiting->get_status() );
		$this->assertSame( 'running', Agent_Run::load( $waiting->get_run_id() )->get_status() );

		Agent_Run::reset_current_for_tests();

		$continuing = Agent_Run::begin( 'content-writer' );
		$continuing->mark_continuing();
		$this->assertTrue( $continuing->claim_resume() );
		$this->assertSame( 'running', $continuing->get_status() );
	}

	/**
	 * Two concurrent resume attempts for the same run_id must not both win:
	 * once one caller's claim_resume() has flipped the row to 'running',
	 * a second, separately-loaded instance racing it must fail the claim
	 * even though its own in-memory get_status() still reads 'waiting'.
	 */
	public function test_claim_resume_rejects_a_second_concurrent_claimant(): void {
		$run = Agent_Run::begin( 'content-writer' );
		$run->mark_waiting( 'approval', '1', array() );

		$first_claimant  = Agent_Run::load( $run->get_run_id() );
		$second_claimant = Agent_Run::load( $run->get_run_id() );

		$this->assertSame( 'waiting', $first_claimant->get_status() );
		$this->assertSame( 'waiting', $second_claimant->get_status() );

		$this->assertTrue( $first_claimant->claim_resume(), 'the first claimant must win the race' );
		$this->assertFalse( $second_claimant->claim_resume(), 'a second concurrent claimant must lose the race' );
	}

	/**
	 * A run that is not in 'waiting'/'continuing' (e.g. still 'running', or
	 * already terminal) can never be claimed.
	 */
	public function test_claim_resume_rejects_a_non_resumable_status(): void {
		$run = Agent_Run::begin( 'content-writer' );
		$this->assertFalse( $run->claim_resume(), 'a plain running run has nothing to claim' );

		$run->finish( 'completed' );
		$this->assertFalse( $run->claim_resume(), 'a terminal run must never be claimed for resume' );
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
	 * The current-shape signal is a "messages" value that is a list of
	 * role-bearing message objects, not merely an array. A legacy flat
	 * scratchpad that happens to hold BOTH a "scratchpad" key (an array) AND a
	 * "messages" key (an array that is not a transcript, e.g. a list of plain
	 * strings) must still be recovered as the whole legacy scratchpad — not
	 * misdetected as the current {scratchpad, messages} wrapper, which would
	 * discard everything under the other keys.
	 */
	public function test_load_recovers_legacy_state_with_both_keys_but_no_transcript(): void {
		$run    = Agent_Run::begin( 'content-writer' );
		$run_id = $run->get_run_id();

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Simulating a pre-2.15.0 row shape for the test.
		$wpdb->update(
			$wpdb->prefix . 'agent_builder_runs',
			array(
				'state' => wp_json_encode(
					array(
						'scratchpad'    => array( 'legacy' => 'nested' ),
						'messages'      => array( 'not a transcript list' ),
						'delegated_key' => 'delegated_value',
					)
				),
			),
			array( 'run_id' => $run_id )
		);

		$reloaded = Agent_Run::load( $run_id );

		$this->assertSame( 'delegated_value', $reloaded->scratch_get( 'delegated_key' ) );
		$this->assertSame( array( 'legacy' => 'nested' ), $reloaded->scratch_get( 'scratchpad' ) );
		$this->assertSame( array( 'not a transcript list' ), $reloaded->scratch_get( 'messages' ) );
		$this->assertSame( array(), $reloaded->resume_state()['messages'] );

		$run->finish( 'completed' );
	}

	/**
	 * A legacy flat scratchpad that happens to hold a "scratchpad" array AND
	 * an empty "messages" array AND a third key must still be recovered as the
	 * whole legacy scratchpad. The current wrapper is always exactly two keys
	 * ({scratchpad, messages}), so a third key rules it out even though
	 * is_transcript_list() would accept the empty messages list on its own.
	 */
	public function test_load_recovers_legacy_state_with_extra_keys(): void {
		$run    = Agent_Run::begin( 'content-writer' );
		$run_id = $run->get_run_id();

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Simulating a pre-2.15.0 row shape for the test.
		$wpdb->update(
			$wpdb->prefix . 'agent_builder_runs',
			array(
				'state' => wp_json_encode(
					array(
						'scratchpad'    => array( 'a' => 1 ),
						'messages'      => array(),
						'delegated_key' => 'x',
					)
				),
			),
			array( 'run_id' => $run_id )
		);

		$reloaded = Agent_Run::load( $run_id );

		$this->assertSame( 'x', $reloaded->scratch_get( 'delegated_key' ) );
		$this->assertSame( array( 'a' => 1 ), $reloaded->scratch_get( 'scratchpad' ) );
		$this->assertSame( array(), $reloaded->scratch_get( 'messages' ) );
		$this->assertSame( array(), $reloaded->resume_state()['messages'] );

		$run->finish( 'completed' );
	}

	/**
	 * A run whose state column holds the current {scratchpad, messages} wrapper
	 * loads both its scratchpad and its resume transcript — the shape-based
	 * signal from_row() uses to tell the wrapper apart from a legacy flat
	 * scratchpad.
	 */
	public function test_load_recovers_current_wrapper_shape(): void {
		$run    = Agent_Run::begin( 'content-writer' );
		$run_id = $run->get_run_id();

		$transcript = array(
			array( 'role' => 'user', 'content' => 'resume me' ),
			array( 'role' => 'assistant', 'content' => 'working on it' ),
		);

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Simulating the current wrapper row shape for the test.
		$wpdb->update(
			$wpdb->prefix . 'agent_builder_runs',
			array(
				'state' => wp_json_encode(
					array(
						'scratchpad' => array( 'delegated_key' => 'delegated_value' ),
						'messages'   => $transcript,
					)
				),
			),
			array( 'run_id' => $run_id )
		);

		$reloaded = Agent_Run::load( $run_id );

		$this->assertSame( 'delegated_value', $reloaded->scratch_get( 'delegated_key' ) );
		$this->assertSame( $transcript, $reloaded->resume_state()['messages'] );

		$run->finish( 'completed' );
	}

	/**
	 * sanitize_transcript() must never drop the system message, even when the
	 * rest of the transcript is oversized and every other message is dropped.
	 */
	public function test_sanitize_transcript_never_drops_system_message(): void {
		$transcript = array(
			array( 'role' => 'system', 'content' => 'System prompt.' ),
			array( 'role' => 'assistant', 'content' => str_repeat( 'x', 250 * 1024 ) ),
		);

		$result = $this->sanitize( $transcript );

		$this->assertCount( 1, $result );
		$this->assertSame( 'system', $result[0]['role'] );
	}

	/**
	 * When capping drops an oversized assistant tool-call turn, its tool-result
	 * messages are dropped together with it — a tool message must never survive
	 * without the assistant call it belongs to, which a provider rejects on
	 * resume.
	 */
	public function test_sanitize_transcript_never_orphans_a_tool_message(): void {
		$transcript = array(
			array( 'role' => 'system', 'content' => 'System prompt.' ),
			array( 'role' => 'user', 'content' => 'Go.' ),
			array(
				'role'       => 'assistant',
				'content'    => '',
				'tool_calls' => array(
					array(
						'id'       => 'call_1',
						'type'     => 'function',
						'function' => array( 'name' => 'list_posts', 'arguments' => str_repeat( 'x', 205 * 1024 ) ),
					),
				),
			),
			array( 'role' => 'tool', 'tool_call_id' => 'call_1', 'name' => 'list_posts', 'content' => '[]' ),
		);

		$result = $this->sanitize( $transcript );

		$this->assertCount( 1, $result, 'the whole assistant turn (assistant + tool result) must be dropped together' );
		$this->assertSame( 'system', $result[0]['role'] );
		$this->assert_no_orphaned_tool_messages( $result );
	}

	/**
	 * A leading orphan tool message (no preceding assistant call) is dropped
	 * even when the transcript is small enough to fit the cap — the capped
	 * transcript must never start with a tool message.
	 */
	public function test_sanitize_transcript_sweeps_a_leading_orphan_tool_message(): void {
		$transcript = array(
			array( 'role' => 'system', 'content' => 'System prompt.' ),
			array( 'role' => 'tool', 'tool_call_id' => 'orphan', 'name' => 'list_posts', 'content' => '[]' ),
			array( 'role' => 'user', 'content' => 'Go.' ),
		);

		$result = $this->sanitize( $transcript );

		$this->assertSame( 'user', $result[1]['role'] );
		$this->assert_no_orphaned_tool_messages( $result );
	}

	/**
	 * Invoke the private sanitize_transcript() for a direct cap test.
	 *
	 * @param array $messages Raw transcript.
	 * @return array Sanitized, capped transcript.
	 */
	private function sanitize( array $messages ): array {
		$method = new \ReflectionMethod( Agent_Run::class, 'sanitize_transcript' );
		return $method->invoke( null, $messages );
	}

	/**
	 * Assert no tool message appears without the assistant tool_calls it belongs
	 * to: after an assistant-with-tool_calls, tool messages are valid; a system,
	 * user or plain-assistant message closes the turn.
	 *
	 * @param array $messages Transcript to check.
	 * @return void
	 */
	private function assert_no_orphaned_tool_messages( array $messages ): void {
		$pending_tool_call = false;
		foreach ( $messages as $message ) {
			$role = $message['role'] ?? '';
			if ( 'tool' === $role ) {
				$this->assertTrue( $pending_tool_call, 'a tool message must never appear without its assistant call' );
				continue;
			}
			$pending_tool_call = 'assistant' === $role && ! empty( $message['tool_calls'] );
		}
	}
}
