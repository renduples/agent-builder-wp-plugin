<?php
/**
 * Tool: Save Audit Result
 *
 * Save an audit result to history. Keeps the last 12 results.
 *
 * @package Agentic\Tools
 */

declare(strict_types=1);

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Save_Audit_Result extends \Agentic\Tool_Base {

	public function get_name(): string {
		return 'save_audit_result';
	}

	public function get_description(): string {
		return 'Save an audit result to history. Keeps the last 12 results.';
	}

	public function get_category(): string {
		return 'site-audit';
	}

	public function get_parameters(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'result' => array(
					'type'        => 'object',
					'description' => 'The audit result to save.',
				),
			),
			'required'   => array( 'result' ),
		);
	}

	public function get_annotations(): array {
		return array(
			'read_only'   => false,
			'destructive' => false,
		);
	}

	public function execute( array $args ): array {
		$history   = get_option( 'agent_builder_site_auditor_history', array() );
		$history[] = $args['result'];
		$history   = array_slice( $history, -12 );
		update_option( 'agent_builder_site_auditor_history', $history, false );

		return array(
			'saved'        => true,
			'total_stored' => count( $history ),
		);
	}
}

return new Save_Audit_Result();
