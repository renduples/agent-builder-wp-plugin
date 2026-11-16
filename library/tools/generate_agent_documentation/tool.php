<?php
/**
 * Tool: generate_agent_documentation
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

class Generate_Agent_Documentation extends Tool_Base {

	public function get_name(): string {
		return 'generate_agent_documentation';
	}

	public function get_description(): string {
		return 'Generate a comprehensive README.md for an agent by reading its registry metadata.';
	}

	public function get_category(): string {
		return 'assistant-trainer';
	}

	public function get_risk_level(): string {
		return 'low';
	}

	public function get_parameters(): array {
		return array(
			'agent_slug'       => array(
				'type'        => 'string',
				'description' => 'The slug of the agent.',
				'required'    => true,
			),
			'include_examples' => array(
				'type'        => 'boolean',
				'description' => 'Include example interactions. Default true.',
				'required'    => false,
			),
		);
	}

	public function execute( array $args ): array {
		$slug             = $args['agent_slug'] ?? '';
		$include_examples = $args['include_examples'] ?? true;

		if ( empty( $slug ) ) {
			return array( 'error' => 'agent_slug is required' );
		}

		$registry = \Agentic_Agent_Registry::get_instance();
		$agent    = $registry->get_agent_instance( $slug );

		if ( ! $agent ) {
			return array( 'error' => "Agent '{$slug}' not found" );
		}

		$name         = $agent->get_name();
		$description  = $agent->get_description();
		$category     = $agent->get_category();
		$icon         = $agent->get_icon();
		$version      = method_exists( $agent, 'get_version' ) ? $agent->get_version() : '1.0.0';
		$author       = method_exists( $agent, 'get_author' ) ? $agent->get_author() : 'Siteloom';
		$capabilities = $agent->get_required_capabilities();
		$tool_names   = $agent->get_tool_names();
		$tool_loader  = \Agentic\Tool_Loader::get_instance();
		$tool_loader->load();
		$tools     = $tool_loader->get_definitions_for( $tool_names );
		$tasks     = method_exists( $agent, 'get_scheduled_tasks' ) ? $agent->get_scheduled_tasks() : array();
		$listeners = method_exists( $agent, 'get_event_listeners' ) ? $agent->get_event_listeners() : array();
		$prompts   = method_exists( $agent, 'get_suggested_prompts' ) ? $agent->get_suggested_prompts() : array();
		$welcome   = method_exists( $agent, 'get_welcome_message' ) ? $agent->get_welcome_message() : '';

		$md  = "# {$icon} {$name}\n\n> {$description}\n\n";
		$md .= "| Field | Value |\n|-------|-------|\n| Slug | `{$slug}` |\n| Version | {$version} |\n| Category | {$category} |\n| Author | {$author} |\n";

		if ( ! empty( $capabilities ) ) {
			$md .= '| Required Capabilities | `' . implode( '`, `', $capabilities ) . "` |\n";
		}

		$md .= "\n";

		if ( ! empty( $tools ) ) {
			$md .= "## Tools\n\n| Tool | Description |\n|------|-------------|\n";

			foreach ( $tools as $tool ) {
				$tname = $tool['function']['name'] ?? '';
				$tdesc = $tool['function']['description'] ?? '';
				$md   .= "| `{$tname}` | {$tdesc} |\n";
			}

			$md .= "\n### Tool Details\n\n";

			foreach ( $tools as $tool ) {
				$tname  = $tool['function']['name'] ?? '';
				$tdesc  = $tool['function']['description'] ?? '';
				$params = $tool['function']['parameters']['properties'] ?? array();

				$md .= "#### `{$tname}`\n\n{$tdesc}\n\n";

				if ( ! empty( $params ) && ! ( $params instanceof \stdClass ) ) {
					$required = $tool['function']['parameters']['required'] ?? array();
					$md      .= "| Parameter | Type | Required | Description |\n|-----------|------|----------|-------------|\n";

					foreach ( $params as $pname => $pdef ) {
						$ptype = $pdef['type'] ?? 'string';
						$preq  = in_array( $pname, $required, true ) ? 'Yes' : 'No';
						$pdesc = $pdef['description'] ?? '';
						$md   .= "| `{$pname}` | {$ptype} | {$preq} | {$pdesc} |\n";
					}
				} else {
					$md .= "No parameters required.\n";
				}

				$md .= "\n";
			}
		}

		if ( ! empty( $tasks ) ) {
			$md .= "## Scheduled Tasks\n\n| Task | Schedule | Description |\n|------|----------|-------------|\n";

			foreach ( $tasks as $task ) {
				$tname  = $task['name'] ?? $task['id'] ?? '';
				$tsched = $task['schedule'] ?? '';
				$tdesc  = $task['description'] ?? '';
				$md    .= "| {$tname} | {$tsched} | {$tdesc} |\n";
			}

			$md .= "\n";
		}

		if ( ! empty( $listeners ) ) {
			$md .= "## Event Listeners\n\n| Event | Hook | Description |\n|-------|------|-------------|\n";

			foreach ( $listeners as $listener ) {
				$lname = $listener['name'] ?? $listener['id'] ?? '';
				$lhook = $listener['hook'] ?? '';
				$ldesc = $listener['description'] ?? '';
				$md   .= "| {$lname} | `{$lhook}` | {$ldesc} |\n";
			}

			$md .= "\n";
		}

		$md .= "## Usage\n\n### Chat\n\n";

		if ( ! empty( $welcome ) ) {
			$md .= "When you open a chat with {$name}, you'll see:\n\n> {$welcome}\n\n";
		}

		if ( ! empty( $prompts ) ) {
			$md .= "### Suggested Prompts\n\n";

			foreach ( $prompts as $prompt ) {
				$md .= "- {$prompt}\n";
			}

			$md .= "\n";
		}

		$md .= "### WP-CLI\n\n```bash\nwp agent list\nwp agent info {$slug}\n```\n\n`wp agent prompt`, `wp agent tools`, and `wp agent run-task` are not available in this build.\n\n";

		if ( $include_examples && ! empty( $prompts ) ) {
			$md .= "## Example Interactions\n\n";

			foreach ( array_slice( $prompts, 0, 3 ) as $prompt ) {
				$clean = preg_replace( '/^[^\w]+/', '', $prompt );
				$md   .= "**User:** {$clean}\n\n**{$name}:** _(The agent will use its tools to handle this request)_\n\n";
			}
		}

		$md .= "---\n\n_Generated by Assistant Trainer — Siteloom for WordPress_\n";

		$installed = $registry->get_installed_agents( true );
		$agent_dir = $installed[ $slug ]['directory'] ?? '';
		$written   = false;
		$file_path = '';

		// A user agent's README goes next to its files in the uploads data
		// root. Bundled agents (inside the plugin) and any agent not stored
		// there are read-only: their README is written to the data root's
		// docs folder instead — never into the plugin directory.
		if ( ! empty( $agent_dir ) && is_dir( $agent_dir ) && \Agentic\File_Manager::is_in_data_dir( $agent_dir ) ) {
			$file_path = $agent_dir . '/README.md';
			\Agentic\Tool_Helpers::backup_file( $file_path );
		} elseif ( \Agentic\File_Manager::ensure_data_dir( 'docs/' . sanitize_key( $slug ) ) ) {
			$file_path = \Agentic\File_Manager::data_path( 'docs/' . sanitize_key( $slug ) ) . '/README.md';
		}

		if ( '' !== $file_path ) {
			$written = \Agentic\File_Manager::put_contents( $file_path, $md );
		}

		return array(
			'slug'     => $slug,
			'markdown' => $md,
			'file'     => $written ? $file_path : null,
			'written'  => $written,
			'sections' => array(
				'tools'     => count( $tools ),
				'tasks'     => count( $tasks ),
				'listeners' => count( $listeners ),
				'prompts'   => count( $prompts ),
			),
		);
	}
}

return new Generate_Agent_Documentation();
