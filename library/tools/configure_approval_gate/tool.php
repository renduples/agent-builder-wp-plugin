<?php
/**
 * Tool: configure_approval_gate
 *
 * Free fix for the Agent-Ready Score's approval_gate_configured check: turns
 * off webmcp_expose on any ability whose declared risk is above MEDIUM.
 *
 * Deliberately never changes a risk value itself — silently lowering an
 * ability's own declared risk to make it "safe to expose" would defeat the
 * entire point of the risk system. Turning off exposure instead is the only
 * fix that cannot itself introduce a new problem.
 *
 * @package    Agent_Builder
 * @subpackage Tools
 * @since      3.3.90
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Un-exposes any WebMCP ability whose risk is above the safe ceiling.
 */
class Configure_Approval_Gate extends \Agentic\Tool_Base {

	public function get_name(): string {
		return 'configure_approval_gate';
	}

	public function get_description(): string {
		return 'Turn off WebMCP exposure (webmcp_expose) for any tool an active agent has exposed at a risk tier above MEDIUM. Never changes a declared risk value — only whether the tool is reachable from WebMCP.';
	}

	public function get_category(): string {
		return 'diagnostics';
	}

	public function get_risk_level(): string {
		return 'low';
	}

	public function get_parameters(): array {
		return array(
			'type'       => 'object',
			'properties' => new \stdClass(),
		);
	}

	public function execute( array $arguments ): array {
		$unsafe = array();
		foreach ( \Agentic\Abilities_Manifest::get_webmcp_exposed() as $exposure ) {
			if ( ! in_array( $exposure['risk'], array( \Agentic\Risk_Level::NONE, \Agentic\Risk_Level::LOW, \Agentic\Risk_Level::MEDIUM ), true ) ) {
				$unsafe[] = $exposure;
			}
		}

		if ( empty( $unsafe ) ) {
			return $this->success( array( 'fixed' => array() ) );
		}

		$fixed    = array();
		$by_agent = array();
		foreach ( $unsafe as $exposure ) {
			$by_agent[ $exposure['agent_slug'] ][] = $exposure['tool_name'];
		}

		foreach ( $by_agent as $slug => $tool_names ) {
			$manifest = \Agentic\Abilities_Manifest::load( $slug );
			if ( ! $manifest ) {
				continue;
			}

			$agent_fixed = array();
			foreach ( $tool_names as $tool_name ) {
				if ( isset( $manifest['abilities'][ $tool_name ] ) ) {
					$manifest['abilities'][ $tool_name ]['webmcp_expose'] = false;

					$agent_fixed[] = "{$slug}:{$tool_name}";
				}
			}

			// Bundled manifests are never rewritten: persist() stores the
			// change as a database override for them.
			if ( ! empty( $agent_fixed ) && \Agentic\Abilities_Manifest::persist( $slug, $manifest ) ) {
				$fixed = array_merge( $fixed, $agent_fixed );
			}
		}

		return $this->success( array( 'fixed' => $fixed ) );
	}

	public function get_annotations(): array {
		return array(
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => true,
		);
	}
}

return new Configure_Approval_Gate();
