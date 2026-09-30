<?php
/**
 * Unit tests for the Routines data-access layer.
 *
 * @package Agentic\Tests
 */

namespace Agentic\Tests;

use Agentic\Agent_Lifecycle;
use Agentic\Deployments;
use Agentic\Manifest_Agent;
use Agentic\Routines;

/**
 * Covers Routines::list/pause/resume/history/next_run over user-defined
 * scheduled tasks and event listeners.
 */
class Test_Routines extends TestCase {

	/**
	 * Seed a user-created scheduled-task Deployments row plus its WP-Cron event,
	 * mirroring Agent_Lifecycle::save_user_scheduled_task()'s dual-write.
	 *
	 * @param string $task_id  Task id.
	 * @param string $schedule Recurrence key.
	 * @return int Deployments row ID.
	 */
	private function seed_scheduled_task( string $task_id, string $schedule = 'daily' ): int {
		$id = Deployments::save(
			array(
				'type'       => Deployments::TYPE_SCHEDULED_TASK,
				'agent_slug' => 'routine-agent',
				'label'      => 'Routine task',
				'enabled'    => 1,
				'source'     => Deployments::SOURCE_ADMIN,
				'config'     => array(
					'task_id'     => $task_id,
					'schedule'    => $schedule,
					'prompt'      => 'Do the thing',
					'source'      => 'user',
					'last_run'    => null,
					'last_status' => null,
				),
			)
		);

		$hook = Agent_Lifecycle::user_task_cron_hook( 'routine-agent', $task_id );
		wp_clear_scheduled_hook( $hook );
		wp_schedule_event( time(), $schedule, $hook );

		return $id;
	}

	/**
	 * Count pending proposal transients (minted by the tool branch of
	 * execute_event_listener()), mirroring test-event-listener-guards.php.
	 *
	 * @return int
	 */
	private function count_proposal_transients(): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
				'_transient_agentic_proposal_%'
			)
		);
	}

	/**
	 * pause() on a scheduled task clears its WP-Cron event — a real stop, not a
	 * cosmetic enabled-flag flip.
	 */
	public function test_pause_clears_scheduled_task_cron(): void {
		$id   = $this->seed_scheduled_task( 'us_pause' );
		$hook = Agent_Lifecycle::user_task_cron_hook( 'routine-agent', 'us_pause' );

		$this->assertNotFalse( wp_next_scheduled( $hook ), 'cron registered before pause' );

		$result = Routines::pause( $id );

		$this->assertArrayHasKey( 'ok', $result );
		$this->assertTrue( $result['ok'] );
		$this->assertFalse( wp_next_scheduled( $hook ), 'pause clears the cron event' );

		$row = Deployments::get( $id );
		$this->assertFalse( $row['enabled'], 'row flipped to disabled' );
		$this->assertNotNull( $row['config']['paused_at'], 'paused_at stamped' );
	}

	/**
	 * resume() re-registers the cron event at the stored recurrence.
	 */
	public function test_resume_reschedules_at_stored_schedule(): void {
		$id   = $this->seed_scheduled_task( 'us_resume', 'twicedaily' );
		$hook = Agent_Lifecycle::user_task_cron_hook( 'routine-agent', 'us_resume' );

		Routines::pause( $id );
		$this->assertFalse( wp_next_scheduled( $hook ), 'paused first' );

		$result = Routines::resume( $id );

		$this->assertTrue( $result['ok'] );
		$this->assertNotFalse( wp_next_scheduled( $hook ), 'resume re-registers the cron event' );
		$this->assertSame( 'twicedaily', wp_get_schedule( $hook ), 'resumed at the stored schedule' );

		$row = Deployments::get( $id );
		$this->assertTrue( $row['enabled'], 'row re-enabled' );
		$this->assertNull( $row['config']['paused_at'], 'paused_at cleared' );
	}

	/**
	 * next_run() reflects a live schedule and returns null once paused.
	 */
	public function test_next_run_reflects_schedule_and_pause(): void {
		update_option( 'date_format', 'Y-m-d' );
		update_option( 'time_format', 'H:i' );

		$id = $this->seed_scheduled_task( 'us_next_run' );

		$this->assertIsString( Routines::next_run( $id ), 'scheduled task has a next-run string' );

		Routines::pause( $id );
		$this->assertNull( Routines::next_run( $id ), 'paused task has no next run' );
	}

	/**
	 * Event-triggered routines have no schedule: next_run() is null.
	 */
	public function test_next_run_is_null_for_event_listener(): void {
		$id = Deployments::save(
			array(
				'type'       => Deployments::TYPE_EVENT_LISTENER,
				'agent_slug' => 'routine-agent',
				'label'      => 'Routine listener',
				'enabled'    => 1,
				'source'     => Deployments::SOURCE_ADMIN,
				'config'     => array(
					'trigger_id' => 'ut_next_run',
					'hook'       => 'updated_option',
					'source'     => 'user',
				),
			)
		);

		$this->assertNull( Routines::next_run( $id ) );
	}

	/**
	 * history() is correct today but empty: no M14 task writes the routine: source_ref yet.
	 */
	public function test_history_returns_empty_today(): void {
		$id = $this->seed_scheduled_task( 'us_history' );

		$this->assertSame( array(), Routines::history( $id ) );
	}

	/**
	 * pause() on an event listener is a real stop: execute_event_listener() gates on
	 * the Deployments row's enabled flag and skips the fire; resume() lets it fire.
	 */
	public function test_pause_event_listener_blocks_execution(): void {
		$agent = new Manifest_Agent( array( 'slug' => 'routine-listener-agent' ), __DIR__ );

		$id = Deployments::save(
			array(
				'type'       => Deployments::TYPE_EVENT_LISTENER,
				'agent_slug' => 'routine-listener-agent',
				'label'      => 'Routine listener',
				'enabled'    => 1,
				'source'     => Deployments::SOURCE_ADMIN,
				'config'     => array(
					'trigger_id' => 'ut_pause_listener',
					'hook'       => 'updated_option',
					'source'     => 'user',
				),
			)
		);

		$listener = array(
			'id'           => 'ut_pause_listener',
			'source'       => 'user',
			'name'         => 'Paused listener',
			'hook'         => 'updated_option',
			'tool'         => 'add_custom_css',
			'min_interval' => 0,
		);

		Routines::pause( $id );
		Agent_Lifecycle::execute_event_listener( $agent, $listener, array( 'some_option', 'old', 'new' ) );
		$this->assertSame( 0, $this->count_proposal_transients(), 'paused listener does not fire' );

		Routines::resume( $id );
		Agent_Lifecycle::execute_event_listener( $agent, $listener, array( 'some_option', 'old', 'new' ) );
		$this->assertSame( 1, $this->count_proposal_transients(), 'resumed listener fires once' );
	}

	/**
	 * pause()/resume() on a missing or non-routine row fails cleanly.
	 */
	public function test_pause_missing_row_errors(): void {
		$this->assertSame( false, Routines::pause( 999999 )['ok'] );
	}

	/**
	 * save() with enabled=false is a real stop, not a cosmetic flag: the cron
	 * event the underlying save just registered is cleared and the Deployments
	 * mirror row is disabled, so a routine created from the editor with the
	 * "enabled" toggle off won't fire.
	 */
	public function test_save_disabled_scheduled_task_clears_cron(): void {
		$agent = new Manifest_Agent(
			array(
				'slug' => 'routine-agent',
				'name' => 'Routine Agent',
			),
			''
		);
		\Agentic_Agent_Registry::get_instance()->register( $agent );

		try {
			$result = Routines::save(
				array(
					'kind'       => 'scheduled_task',
					'agent_slug' => 'routine-agent',
					'prompt'     => 'Do the thing',
					'schedule'   => 'daily',
					'enabled'    => false,
				)
			);

			$this->assertArrayHasKey( 'ok', $result );
			$this->assertTrue( $result['ok'], 'save succeeds' );
			$this->assertArrayHasKey( 'id', $result, 'save returns the Deployments row id' );

			$row     = Deployments::get( (int) $result['id'] );
			$task_id = (string) ( $row['config']['task_id'] ?? '' );
			$this->assertNotSame( '', $task_id, 'saved task carries a task id' );

			$hook = Agent_Lifecycle::user_task_cron_hook( 'routine-agent', $task_id );
			$this->assertFalse( wp_next_scheduled( $hook ), 'disabled save clears the cron event' );
			$this->assertFalse( $row['enabled'], 'disabled save disables the mirror row' );
		} finally {
			\Agentic_Agent_Registry::get_instance()->unregister( 'routine-agent' );
			delete_option( Agent_Lifecycle::USER_SCHEDULED_TASKS_OPTION );
		}
	}

	/**
	 * list() returns only user-sourced scheduled tasks and event listeners,
	 * decorated with next_run, and excludes code-sourced deployments.
	 */
	public function test_list_filters_to_user_routines(): void {
		$task_id = $this->seed_scheduled_task( 'us_list' );

		// A code-sourced scheduled task is not a routine and must be excluded.
		Deployments::save(
			array(
				'type'       => Deployments::TYPE_SCHEDULED_TASK,
				'agent_slug' => 'routine-agent',
				'label'      => 'Built-in task',
				'enabled'    => 1,
				'source'     => Deployments::SOURCE_CODE,
				'config'     => array(
					'task_id' => 'builtin_task',
					'schedule' => 'daily',
					'source'  => 'code',
				),
			)
		);

		$routines = Routines::list( 'routine-agent' );

		$ids = array();
		foreach ( $routines as $routine ) {
			$ids[] = $routine['config']['task_id'];
			$this->assertArrayHasKey( 'next_run', $routine, 'each routine is decorated with next_run' );
		}

		$this->assertContains( 'us_list', $ids, 'user-sourced task is listed' );
		$this->assertNotContains( 'builtin_task', $ids, 'code-sourced deployment is excluded' );
		$this->assertSame( $task_id, $routines[0]['id'], 'listed routine carries the correct row id' );
	}

	/**
	 * A partial save() that omits `enabled` must preserve a routine's paused state:
	 * the underlying save rewrites the config blob and re-enables the row, so
	 * save() has to re-apply the pause it observed before the save.
	 */
	public function test_save_omitted_enabled_preserves_pause(): void {
		$this->register_routine_agent();
		try {
			$created = Routines::save(
				array(
					'kind'       => 'scheduled_task',
					'agent_slug' => 'routine-agent',
					'prompt'     => 'Do the thing',
					'schedule'   => 'daily',
				)
			);
			$this->assertTrue( $created['ok'] );

			Routines::pause( $created['id'] );

			$task_id = (string) Deployments::get( $created['id'] )['config']['task_id'];
			$hook    = Agent_Lifecycle::user_task_cron_hook( 'routine-agent', $task_id );

			$updated = Routines::save(
				array(
					'kind'       => 'scheduled_task',
					'id'         => $task_id,
					'agent_slug' => 'routine-agent',
					'prompt'     => 'Do the thing, edited',
					'schedule'   => 'daily',
				)
			);

			$this->assertTrue( $updated['ok'] );
			$row = Deployments::get( $created['id'] );
			$this->assertNotNull( $row['config']['paused_at'], 'paused_at survives an enabled-less update' );
			$this->assertFalse( $row['enabled'], 'row stays disabled after an enabled-less update' );
			$this->assertFalse( wp_next_scheduled( $hook ), 'cron stays cleared after an enabled-less update' );
		} finally {
			$this->unregister_routine_agent();
			delete_option( Agent_Lifecycle::USER_SCHEDULED_TASKS_OPTION );
		}
	}

	/**
	 * An explicit enabled=true save on a paused routine resumes it.
	 */
	public function test_save_enabled_true_resumes_paused(): void {
		$this->register_routine_agent();
		try {
			$created = Routines::save(
				array(
					'kind'       => 'scheduled_task',
					'agent_slug' => 'routine-agent',
					'prompt'     => 'Do the thing',
					'schedule'   => 'daily',
				)
			);
			$this->assertTrue( $created['ok'] );

			Routines::pause( $created['id'] );

			$task_id = (string) Deployments::get( $created['id'] )['config']['task_id'];
			$hook    = Agent_Lifecycle::user_task_cron_hook( 'routine-agent', $task_id );

			$updated = Routines::save(
				array(
					'kind'       => 'scheduled_task',
					'id'         => $task_id,
					'agent_slug' => 'routine-agent',
					'prompt'     => 'Do the thing',
					'schedule'   => 'daily',
					'enabled'    => true,
				)
			);

			$this->assertTrue( $updated['ok'] );
			$row = Deployments::get( $created['id'] );
			$this->assertNull( $row['config']['paused_at'], 'paused_at cleared on an enabled save' );
			$this->assertTrue( $row['enabled'], 'row re-enabled on an enabled save' );
			$this->assertNotFalse( wp_next_scheduled( $hook ), 'cron re-registered on an enabled save' );
		} finally {
			$this->unregister_routine_agent();
			delete_option( Agent_Lifecycle::USER_SCHEDULED_TASKS_OPTION );
		}
	}

	/**
	 * resume() on a routine whose schedule is unknown restores the paused state
	 * rather than leaving the row enabled with no scheduled event.
	 */
	public function test_resume_unknown_schedule_restores_paused_state(): void {
		$id = Deployments::save(
			array(
				'type'       => Deployments::TYPE_SCHEDULED_TASK,
				'agent_slug' => 'routine-agent',
				'label'      => 'Bad schedule',
				'enabled'    => 1,
				'source'     => Deployments::SOURCE_ADMIN,
				'config'     => array(
					'task_id'  => 'us_bad_resume',
					'schedule' => 'not_a_real_schedule',
					'source'   => 'user',
				),
			)
		);

		$result = Routines::resume( $id );

		$this->assertFalse( $result['ok'], 'unknown schedule makes resume fail' );

		$row = Deployments::get( $id );
		$this->assertFalse( $row['enabled'], 'a failed resume restores the paused state instead of leaving enabled=1' );
		$this->assertNotNull( $row['config']['paused_at'], 'a failed resume stamps paused_at' );
	}

	/**
	 * A schedule registered with a zero interval is treated as unknown, so resume()
	 * cannot schedule the first occurrence "immediately" and instead fails cleanly.
	 */
	public function test_resume_zero_interval_schedule_is_unknown(): void {
		$filter = static function ( $schedules ) {
			$schedules['zero_interval'] = array(
				'interval' => 0,
				'display'  => 'Zero',
			);
			return $schedules;
		};
		add_filter( 'cron_schedules', $filter );

		try {
			$id = Deployments::save(
				array(
					'type'       => Deployments::TYPE_SCHEDULED_TASK,
					'agent_slug' => 'routine-agent',
					'label'      => 'Zero schedule',
					'enabled'    => 0,
					'source'     => Deployments::SOURCE_ADMIN,
					'config'     => array(
						'task_id'   => 'us_zero_interval',
						'schedule'  => 'zero_interval',
						'source'    => 'user',
						'paused_at' => current_time( 'mysql' ),
					),
				)
			);

			$result = Routines::resume( $id );

			$this->assertFalse( $result['ok'], 'a zero-interval schedule is treated as unknown' );

			$row = Deployments::get( $id );
			$this->assertFalse( $row['enabled'], 'failed resume leaves the routine disabled' );
			$this->assertNotNull( $row['config']['paused_at'], 'failed resume keeps the paused stamp' );
		} finally {
			remove_filter( 'cron_schedules', $filter );
		}
	}

	/**
	 * save() with enabled=true on a paused routine surfaces a failed resume and
	 * re-applies the pause, instead of ignoring the failure and leaving the routine
	 * enabled with no scheduled event.
	 */
	public function test_save_enabled_true_resume_failure_restores_pause(): void {
		$this->register_routine_agent();
		try {
			$created = Routines::save(
				array(
					'kind'       => 'scheduled_task',
					'agent_slug' => 'routine-agent',
					'prompt'     => 'Do the thing',
					'schedule'   => 'daily',
				)
			);
			$this->assertTrue( $created['ok'] );

			Routines::pause( $created['id'] );

			$task_id = (string) Deployments::get( $created['id'] )['config']['task_id'];
			$hook    = Agent_Lifecycle::user_task_cron_hook( 'routine-agent', $task_id );

			// Force wp_schedule_event() to fail so resume() cannot re-register cron.
			$block = static function () {
				return false;
			};
			add_filter( 'pre_schedule_event', $block );

			try {
				$updated = Routines::save(
					array(
						'kind'       => 'scheduled_task',
						'id'         => $task_id,
						'agent_slug' => 'routine-agent',
						'prompt'     => 'Do the thing',
						'schedule'   => 'daily',
						'enabled'    => true,
					)
				);
			} finally {
				remove_filter( 'pre_schedule_event', $block );
			}

			$this->assertFalse( $updated['ok'], 'save reports failure when re-enable cannot reschedule' );
			$this->assertArrayHasKey( 'error', $updated );

			$row = Deployments::get( $created['id'] );
			$this->assertFalse( $row['enabled'], 'failed re-enable leaves the routine paused, not enabled' );
			$this->assertNotNull( $row['config']['paused_at'], 'failed re-enable keeps the paused stamp' );
			$this->assertFalse( wp_next_scheduled( $hook ), 'failed re-enable leaves no cron event' );
		} finally {
			$this->unregister_routine_agent();
			delete_option( Agent_Lifecycle::USER_SCHEDULED_TASKS_OPTION );
		}
	}

	/**
	 * resume() schedules the first occurrence one interval out — resuming a routine
	 * must not fire it the instant it is re-enabled.
	 */
	public function test_resume_schedules_one_interval_out(): void {
		$id   = $this->seed_scheduled_task( 'us_resume_interval', 'daily' );
		$hook = Agent_Lifecycle::user_task_cron_hook( 'routine-agent', 'us_resume_interval' );

		Routines::pause( $id );
		$this->assertFalse( wp_next_scheduled( $hook ), 'paused first' );

		$result = Routines::resume( $id );
		$this->assertTrue( $result['ok'] );

		$next     = wp_next_scheduled( $hook );
		$interval = (int) wp_get_schedules()['daily']['interval'];
		$this->assertNotFalse( $next, 'resume re-registers the cron event' );
		$this->assertGreaterThanOrEqual( time() + $interval - 10, $next, 'first occurrence is one interval out, not immediate' );
		$this->assertLessThanOrEqual( time() + $interval + 10, $next, 'first occurrence is not pushed past one interval' );
	}

	/**
	 * history() clamps its limit into 1..20.
	 */
	public function test_history_clamps_limit(): void {
		global $wpdb;

		$id = $this->seed_scheduled_task( 'us_history_clamp' );

		$wpdb->query( "DELETE FROM {$wpdb->prefix}agent_builder_runs WHERE run_id LIKE 'us_clamp_%'" );

		for ( $i = 1; $i <= 25; $i++ ) {
			$wpdb->insert(
				$wpdb->prefix . 'agent_builder_runs',
				array(
					'run_id'     => sprintf( 'us_clamp_%02d', $i ),
					'source_ref' => 'routine:' . $id,
				)
			);
		}

		try {
			$this->assertCount( 20, Routines::history( $id, 100 ), 'limit above 20 is clamped to 20' );
			$this->assertCount( 5, Routines::history( $id, 5 ), 'limit in range is honoured' );
			$this->assertCount( 1, Routines::history( $id, 0 ), 'limit below 1 is clamped to 1' );
		} finally {
			$wpdb->query( "DELETE FROM {$wpdb->prefix}agent_builder_runs WHERE run_id LIKE 'us_clamp_%'" );
		}
	}

	/**
	 * Register the shared routine-agent the save() tests resolve by slug.
	 */
	private function register_routine_agent(): void {
		\Agentic_Agent_Registry::get_instance()->register(
			new Manifest_Agent( array( 'slug' => 'routine-agent', 'name' => 'Routine Agent' ), '' )
		);
	}

	/**
	 * Unregister the shared routine-agent.
	 */
	private function unregister_routine_agent(): void {
		\Agentic_Agent_Registry::get_instance()->unregister( 'routine-agent' );
	}

	/**
	 * Clean up proposal/pending/rate-limit state and any scheduled-task cron this
	 * file creates, so a re-run of the suite is not blocked by a prior run.
	 */
	public function tearDown(): void {
		global $wpdb;

		$wpdb->query( "DELETE FROM {$wpdb->prefix}agent_builder_deployments" );

		$wpdb->query(
			"DELETE FROM {$wpdb->options}
			WHERE option_name LIKE 'agentic_listener_rate_%'
			   OR option_name LIKE 'agentic_listener_skips_%'
			   OR option_name LIKE '_transient_agentic_proposal_%'
			   OR option_name LIKE '_transient_timeout_agentic_proposal_%'
			   OR option_name LIKE '_transient_agentic_listener_pending_%'
			   OR option_name LIKE '_transient_timeout_agentic_listener_pending_%'"
		);

		$cron = _get_cron_array();
		if ( is_array( $cron ) ) {
			foreach ( $cron as $events ) {
				foreach ( $events as $hook => $args ) {
					if ( 0 === strpos( (string) $hook, 'agentic_task_routine-agent_' ) ) {
						wp_clear_scheduled_hook( $hook );
					}
				}
			}
		}

		parent::tearDown();
	}
}
