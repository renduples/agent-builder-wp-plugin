<?php
/**
 * Tool: Get Audit History
 *
 * Return the last 12 saved audit results with scores and grades.
 *
 * @package Agentic\Tools
 */

declare(strict_types=1);

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Get_Audit_History extends \Agentic\Tool_Base {

	public function get_name(): string {
		return 'get_audit_history';
	}

	public function get_description(): string {
		return 'Return the last 12 saved audit results with scores and grades.';
	}

	public function get_category(): string {
		return 'site-audit';
	}

	public function get_parameters(): array {
		return array(
			'type'       => 'object',
			'properties' => array(),
			'required'   => array(),
		);
	}

	public function get_annotations(): array {
		return array(
			'read_only'   => true,
			'destructive' => false,
		);
	}

	public function execute( array $args ): array {
		$history = get_option( 'agent_builder_site_auditor_history', array() );
		if ( ! is_array( $history ) ) {
			$history = array();
		}

		return array(
			'audits' => array_slice( array_reverse( $history ), 0, 12 ),
			'total'  => count( $history ),
		);
	}
}

return new Get_Audit_History();
