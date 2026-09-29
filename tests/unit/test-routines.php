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
