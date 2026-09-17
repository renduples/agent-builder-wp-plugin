<?php
/**
 * Tool: get_onboarding_status
 *
 * Get the site's setup-checklist progress (provider connected, an agent
 * active, a conversation started, knowledge added) so an agent can weave a
 * relevant next-step suggestion into its replies instead of guessing.
 *
 * @package    Agent_Builder
 * @subpackage Tools
 * @since      3.3.17
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only lookup of the same four-step onboarding checklist shown on the
 * Dashboard, so a chatting agent can suggest an unfinished next step.
 */
class Get_Onboarding_Status extends \Agentic\Tool_Base {

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'get_onboarding_status';
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return 'Get the site\'s setup-checklist progress: whether an AI provider is connected, an agent is active, a conversation has started, and knowledge has been added. Use this to suggest a relevant next step for a user who is still getting set up — do not call it once onboarding_complete is true for a given user unless asked.';
	}

	/**
	 * Get the tool category.
	 *
	 * @return string
	 */
	public function get_category(): string {
		return 'onboarding';
	}

	/**
	 * Get the parameter schema.
	 *
	 * @return array
	 */
	public function get_parameters(): array {
		return array(
			'type'       => 'object',
			'properties' => new \stdClass(),
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Validated arguments from the LLM (none used).
	 * @return array Result data.
	 */
	public function execute( array $arguments ): array {
		$llm           = new \Agentic\LLM_Client();
		$is_configured = $llm->is_configured()
			&& ! ( class_exists( '\Agentic\Emergency_Stop' ) && \Agentic\Emergency_Stop::is_active() );

		$active_agents = 0;
		if ( class_exists( '\Agentic_Agent_Registry' ) ) {
			$registry  = \Agentic_Agent_Registry::get_instance();
			$installed = $registry->get_installed_agents();
			foreach ( (array) $registry->get_active_agents() as $slug ) {
				$slug = sanitize_key( (string) $slug );
				if ( isset( $installed[ $slug ] ) ) {
					++$active_agents;
				}
			}
		}

		$stats       = ( new \Agentic\Audit_Log() )->get_stats( 'week' );
		$has_chatted = (int) ( $stats['total_actions'] ?? 0 ) > 0;

		$has_knowledge = class_exists( '\Agentic\Okf_Store' )
			? \Agentic\Okf_Store::has_active_knowledge()
			: (bool) get_option( 'agent_builder_has_knowledge', false );

		$steps = array(
			array(
				'id'    => 'provider',
				'label' => 'Connect an AI provider',
				'done'  => $is_configured,
			),
			array(
				'id'    => 'agent',
				'label' => 'Activate your first agent',
				'done'  => $active_agents > 0,
			),
			array(
				'id'    => 'chat',
				'label' => 'Start a conversation',
				'done'  => $has_chatted,
			),
			array(
				'id'    => 'knowledge',
				'label' => 'Add knowledge',
				'done'  => $has_knowledge,
			),
		);

		$remaining = array_values(
			array_filter(
				$steps,
				static fn( array $s ): bool => ! $s['done']
			)
		);

		return array(
			'steps'                => $steps,
			'completed_count'      => count( $steps ) - count( $remaining ),
			'total_count'          => count( $steps ),
			'next_incomplete_step' => $remaining[0]['id'] ?? null,
			'onboarding_complete'  => empty( $remaining ),
		);
	}

	/**
	 * Get tool annotations.
	 *
	 * @return array
	 */
	public function get_annotations(): array {
		return array(
			'readonly' => true,
		);
	}
}

return new Get_Onboarding_Status();
