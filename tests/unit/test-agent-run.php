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

use Agentic\Activator;
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
	 * A genuinely ambiguous legacy row — a flat scratchpad that happens to hold
	 * BOTH a "scratchpad" key (an array) AND a "messages" key that is a list of
	 * role-bearing message objects (the exact shape the old heuristic would
	 * misread as the current wrapper) — is still recovered as the whole legacy
	 * scratchpad. The current-shape signal is now an explicit
	 * agent_builder_state_version marker written by encode_state(), not a shape
	 * heuristic, so a row that merely *looks* like the {scratchpad, messages}
	 * wrapper but lacks the marker can no longer be misclassified, which would
	 * otherwise silently discard its other legacy keys.
	 */
	public function test_load_recovers_ambiguous_legacy_state_without_version_marker(): void {
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
						'messages'      => array(
							array( 'role' => 'user', 'content' => 'a coincidental role-bearing list' ),
						),
						'delegated_key' => 'delegated_value',
					)
				),
			),
			array( 'run_id' => $run_id )
		);

		$reloaded = Agent_Run::load( $run_id );

		// The whole decoded value is the legacy scratchpad, including the two
		// coincidental keys — not just whatever sat under "scratchpad".
		$this->assertSame( 'delegated_value', $reloaded->scratch_get( 'delegated_key' ) );
		$this->assertSame( array( 'legacy' => 'nested' ), $reloaded->scratch_get( 'scratchpad' ) );
		$this->assertSame(
			array( array( 'role' => 'user', 'content' => 'a coincidental role-bearing list' ) ),
			$reloaded->scratch_get( 'messages' )
		);
		$this->assertSame( array(), $reloaded->resume_state()['messages'] );

		$run->finish( 'completed' );
	}

	/**
	 * A write deferred while the schema is stale must not leave the run
	 * looking done in-memory (to_array()['persisted']/is_persisted()) while
	 * silently missing from the DB forever: once the schema is current again,
	 * the very next state-transition call flushes the whole accumulated row,
	 * not just its own fields.
	 */
	public function test_deferred_write_is_flushed_once_schema_is_current_again(): void {
		$previous_schema = get_option( 'agent_builder_db_schema_version', false );

		try {
			update_option( 'agent_builder_db_schema_version', '2.14.2' );
			$this->assertTrue( Activator::schema_is_stale() );

			$run = Agent_Run::begin( 'content-writer' );

			// persist_start()'s insert was deferred: nothing in the DB yet, and
			// the instance must admit it, not silently claim success.
			$this->assertFalse( $run->is_persisted() );
			$this->assertFalse( $run->to_array()['persisted'] );

			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test assertion.
			$row_before = $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}agent_builder_runs WHERE run_id = %s", $run->get_run_id() ),
				ARRAY_A
			);
			$this->assertNull( $row_before, 'the deferred insert must not have landed while the schema was stale' );

			update_option( 'agent_builder_db_schema_version', AGENT_BUILDER_DB_VERSION );
			$this->assertFalse( Activator::schema_is_stale() );

			$run->finish( 'completed', array( 'text' => 'done' ) );

			// finish() flips in-memory status immediately either way; the
			// point of this test is that the DB row now agrees with it.
			$this->assertTrue( $run->is_persisted() );
			$this->assertTrue( $run->to_array()['persisted'] );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test assertion.
			$row_after = $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}agent_builder_runs WHERE run_id = %s", $run->get_run_id() ),
				ARRAY_A
			);
			$this->assertIsArray( $row_after, 'the whole accumulated row (begin() + finish()) must land in one flush' );
			$this->assertSame( 'completed', $row_after['status'] );
			$this->assertSame( 'content-writer', $row_after['root_agent'] );
		} finally {
			if ( false === $previous_schema ) {
				delete_option( 'agent_builder_db_schema_version' );
			} else {
				update_option( 'agent_builder_db_schema_version', $previous_schema );
			}
			Agent_Run::reset_current_for_tests();
		}
	}

	/**
	 * A run that never hits a stale schema stays reported as fully persisted
	 * throughout its lifecycle — the dirty-tracking added above must not
	 * regress the common (schema-current) path.
	 */
	public function test_healthy_path_stays_marked_persisted(): void {
		$run = Agent_Run::begin( 'content-writer' );
		$this->assertTrue( $run->is_persisted() );

		$run->record_iteration( array( 'list_posts' ), 10, 0.001 );
		$this->assertTrue( $run->is_persisted() );

		$run->finish( 'completed' );
		$this->assertTrue( $run->is_persisted() );
		$this->assertTrue( $run->to_array()['persisted'] );
	}

	/**
	 * A run whose row vanishes after insert (a concurrent delete, or a load()
	 * whose row was removed before a later write) must not keep reporting
	 * itself as persisted: $wpdb->update() returns 0 both for "values already
	 * correct" (benign) and "no row matched" (the write didn't land). Only the
	 * latter should leave the instance dirty, so is_persisted() stays honest.
	 */
	public function test_update_that_matches_no_row_stays_dirty(): void {
		$run = Agent_Run::begin( 'content-writer' );
		$this->assertTrue( $run->is_persisted() );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test setup: simulate the row being deleted out from under the run.
		$wpdb->delete( $wpdb->prefix . 'agent_builder_runs', array( 'run_id' => $run->get_run_id() ), array( '%s' ) );

		// record_iteration() flushes an UPDATE against the now-missing row.
		$run->record_iteration( array( 'list_posts' ), 10, 0.001 );

		$this->assertFalse( $run->is_persisted(), 'a run whose row is gone must not report persisted after a 0-row update' );
		$this->assertFalse( $run->to_array()['persisted'] );

		$run->finish( 'completed' );
	}

	/**
	 * flush_pending()'s INSERT branch must treat a "0 rows affected" result the
	 * same as a write error: $wpdb->insert() can report 0 (not just false) in
	 * edge cases, and either way the run has still never landed — so it must
	 * stay dirty rather than falsely report persisted via is_persisted().
	 */
	public function test_insert_reporting_zero_rows_is_treated_as_failed_flush(): void {
		$previous_schema = get_option( 'agent_builder_db_schema_version', false );
		$original_wpdb   = $GLOBALS['wpdb'];
		// Short-circuit schema_is_stale()'s get_option() so the flush below never
		// hits the stubbed $wpdb (which deliberately has no read methods).
		$short_circuit   = static function () {
			return AGENT_BUILDER_DB_VERSION;
		};

		try {
			// Defer the initial insert so the run stays in INSERT mode: its row
			// has never landed, so flush_pending() must INSERT, not UPDATE.
			update_option( 'agent_builder_db_schema_version', '2.14.2' );
			$run = Agent_Run::begin( 'content-writer' );
			$this->assertFalse( $run->is_persisted() );

			$stub          = new class {
				public $prefix;

				/**
				 * Simulate a "no row created" insert result.
				 *
				 * @return int Always 0.
				 */
				public function insert() {
					return 0;
				}
			};
			$stub->prefix = $original_wpdb->prefix;

			add_filter( 'pre_option_agent_builder_db_schema_version', $short_circuit );
			$GLOBALS['wpdb'] = $stub;

			$ref = new \ReflectionMethod( Agent_Run::class, 'flush_pending' );
			$ref->invoke( $run );

			$this->assertFalse( $run->is_persisted(), 'a 0-row insert must leave the run dirty, not report it persisted' );
		} finally {
			$GLOBALS['wpdb'] = $original_wpdb;
			remove_filter( 'pre_option_agent_builder_db_schema_version', $short_circuit );
			if ( false === $previous_schema ) {
				delete_option( 'agent_builder_db_schema_version' );
			} else {
				update_option( 'agent_builder_db_schema_version', $previous_schema );
			}
			Agent_Run::reset_current_for_tests();
		}
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
