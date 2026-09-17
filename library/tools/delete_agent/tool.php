<?php
/**
 * Tool: delete_agent
 *
 * @package    Agent_Builder
 * @subpackage Tools
 * @since      2.0.0
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Agentic\Tool_Base;

class Delete_Agent extends Tool_Base {

	public function get_name(): string {
		return 'delete_agent';
	}

	public function get_description(): string {
		return 'Delete an agent from the library. Use with caution.';
	}

	public function get_category(): string {
		return 'assistant-trainer';
	}

	public function get_risk_level(): string {
		return 'medium';
	}

	public function get_annotations(): array {
		return array(
			'read_only'   => false,
			'destructive' => true,
		);
	}

	public function get_parameters(): array {
		return array(
			'slug'    => array(
				'type'        => 'string',
				'description' => 'Agent slug to delete.',
				'required'    => true,
			),
			'confirm' => array(
				'type'        => 'boolean',
				'description' => 'Must be true to confirm deletion.',
				'required'    => true,
			),
		);
	}

	public function execute( array $args ): array {
		$slug    = $args['slug'] ?? '';
		$confirm = $args['confirm'] ?? false;

		if ( empty( $slug ) ) {
			return array( 'error' => 'Agent slug is required' );
		}

		if ( ! $confirm ) {
			return array( 'error' => 'Deletion must be confirmed by setting confirm=true' );
		}

		// All 11 bundled agents ship inside the plugin itself (library/agents/),
		// never inside AGENT_BUILDER_AGENTS_DIR below — so this list is a defense-in-
		// depth guard, not the only thing standing between this tool and the
		// plugin's own files.
		$protected = array(
			'content-writer',
			'wordpress-assistant',
			'assistant-trainer',
			'editorial-director',
			'seo-optimizer',
			'site-health-sentinel',
			'support-triage',
			'user-assistant',
			'agent-orchestrator',
			'skills-assistant',
			'storefront-assistant',
		);

		if ( in_array( $slug, $protected, true ) ) {
			return array( 'error' => "Cannot delete protected agent: {$slug}" );
		}

		// User-created agents (via the Agent Wizard or Assistant Trainer's
		// create_agent_files) live in AGENT_BUILDER_AGENTS_DIR, not the plugin's own
		// library/agents/ — this previously pointed at the latter via
		// Tool_Helpers::get_library_path(), so it could never find (and
		// therefore never delete) an actual user-created agent.
		$agent_dir = AGENT_BUILDER_AGENTS_DIR . '/' . $slug;

		if ( ! is_dir( $agent_dir ) ) {
			return array( 'error' => "Agent '{$slug}' not found" );
		}

		// Deactivate first (fires deactivation hooks, clears the registry
		// cache, and removes the slug from the active-agents option) so a
		// deleted agent doesn't linger as a dangling reference that other
		// code iterating active agents would then fail to load. A "not
		// active" error just means it wasn't active to begin with — not a
		// reason to abort deleting the files.
		if ( class_exists( '\Agentic_Agent_Registry' ) ) {
			\Agentic_Agent_Registry::get_instance()->deactivate_agent( $slug );
		}

		\Agentic\File_Manager::rmdir( $agent_dir, true );

		return array(
			'success' => true,
			'message' => "Agent '{$slug}' deleted",
			'slug'    => $slug,
		);
	}
}

return new Delete_Agent();
