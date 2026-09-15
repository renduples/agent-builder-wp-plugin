<?php
/**
 * Test Data Factory
 *
 * @package Agent_Builder
 * @subpackage Tests
 */

namespace Agentic\Tests;

/**
 * Factory for generating test data.
 */
class TestDataFactory {

	/**
	 * Create test audit log entry data, matching Audit_Log::log()'s argument
	 * shape (see includes/class-audit-log.php).
	 *
	 * @param array $overrides Optional overrides.
	 * @return array
	 */
	public static function audit_log( array $overrides = array() ): array {
		$defaults = array(
			'agent_id'  => 'test-agent',
			'action'    => 'test_action',
			'target'    => 'test_target',
			'details'   => array( 'test' => 'data' ),
			'reasoning' => '',
		);

		return array_merge( $defaults, $overrides );
	}

	/**
	 * Create test approval queue item arguments, matching Approval_Queue::add().
	 *
	 * @param array $overrides Optional overrides.
	 * @return array
	 */
	public static function approval_item( array $overrides = array() ): array {
		$defaults = array(
			'agent_id'   => 'test-agent',
			'action'     => 'test_action',
			'params'     => array( 'test' => 'data' ),
			'reasoning'  => 'Test reasoning',
			'expires'    => 7,
			'risk_level' => 'high',
			'mode'       => 'supervised',
			'invocation' => 'chat',
		);

		return array_merge( $defaults, $overrides );
	}
}
