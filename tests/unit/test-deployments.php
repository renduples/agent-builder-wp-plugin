<?php
/**
 * Unit tests for the Deployments data-access layer.
 *
 * @package Agentic\Tests
 */

namespace Agentic\Tests;

use Agentic\Deployments;

/**
 * Covers Deployments::update_config() — the safe primitive for writing a single
 * config field without disturbing unrelated stored keys.
 */
class Test_Deployments extends TestCase {

	/**
	 * A narrow config patch must not wipe unrelated existing config keys.
	 */
	public function test_update_config_preserves_unrelated_keys(): void {
		$id = Deployments::save(
			array(
				'type'       => Deployments::TYPE_SCHEDULED_TASK,
				'agent_slug' => 'dep-agent',
				'label'      => 'Dep task',
				'enabled'    => 1,
				'source'     => Deployments::SOURCE_ADMIN,
				'config'     => array(
					'task_id'     => 'us_dep',
					'schedule'    => 'daily',
					'prompt'      => 'Original prompt',
					'source'      => 'user',
					'last_run'    => null,
					'last_status' => null,
				),
			)
		);

		$ok = Deployments::update_config( $id, array( 'last_run' => '2026-09-29 01:00:00' ) );

		$this->assertTrue( $ok );

		$row = Deployments::get( $id );
		$this->assertSame( 'us_dep', $row['config']['task_id'], 'task_id preserved' );
		$this->assertSame( 'daily', $row['config']['schedule'], 'schedule preserved' );
		$this->assertSame( 'Original prompt', $row['config']['prompt'], 'prompt preserved' );
		$this->assertSame( 'user', $row['config']['source'], 'config source preserved' );
		$this->assertNull( $row['config']['last_status'], 'last_status still null' );
		$this->assertSame( '2026-09-29 01:00:00', $row['config']['last_run'], 'patched key applied' );

		// Top-level columns are untouched by a config-only update.
		$this->assertSame( Deployments::TYPE_SCHEDULED_TASK, $row['type'] );
		$this->assertSame( 'dep-agent', $row['agent_slug'] );
	}

	/**
	 * A patch key wins over an existing key of the same name.
	 */
	public function test_update_config_patch_key_wins(): void {
		$id = Deployments::save(
			array(
				'type'       => Deployments::TYPE_SCHEDULED_TASK,
				'agent_slug' => 'dep-agent',
				'label'      => 'Dep task',
				'config'     => array(
					'task_id'  => 'us_dep2',
					'schedule' => 'daily',
					'source'   => 'user',
				),
			)
		);

		Deployments::update_config( $id, array( 'schedule' => 'weekly' ) );

		$row = Deployments::get( $id );
		$this->assertSame( 'weekly', $row['config']['schedule'], 'patch value wins' );
		$this->assertSame( 'us_dep2', $row['config']['task_id'], 'unrelated key preserved' );
	}

	/**
	 * update_config() on a missing row returns false and does not error.
	 */
	public function test_update_config_returns_false_when_missing(): void {
		$this->assertFalse( Deployments::update_config( 999999, array( 'x' => 1 ) ) );
	}
}
