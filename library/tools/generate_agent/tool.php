<?php
/**
 * Tool: generate_agent
 *
 * @package    Agent_Builder
 * @subpackage Tools
 * @since      2.0.0
 *
 * php version 8.1
 */

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Agentic\Tool_Base;

class Generate_Agent extends Tool_Base {

	public function get_name(): string {
		return 'generate_agent';
	}

	public function get_description(): string {
		return 'Generate a declarative agent.json spec (not PHP) from a job description. Follow by calling create_agent_files.';
	}

	public function get_category(): string {
		return 'assistant-trainer';
	}

	public function get_risk_level(): string {
		return 'none';
	}

	public function get_parameters(): array {
		return array(
			'name'                => array(
				'type'        => 'string',
				'description' => 'Agent display name.',
				'required'    => true,
			),
			'slug'                => array(
				'type'        => 'string',
				'description' => 'Agent slug/ID.',
				'required'    => true,
			),
			'description'         => array(
				'type'        => 'string',
				'description' => 'Brief description.',
				'required'    => true,
			),
			'category'            => array(
				'type'        => 'string',
				'description' => 'Agent category.',
				'required'    => true,
				'enum'        => array( 'Content', 'Admin', 'E-commerce', 'Frontend', 'Developer', 'Marketing' ),
			),
			'icon'                => array(
				'type'        => 'string',
				'description' => 'Emoji icon.',
				'required'    => false,
			),
			'capabilities'        => array(
				'type'        => 'array',
				'description' => 'Required WordPress capabilities.',
				'items'       => array( 'type' => 'string' ),
				'required'    => false,
			),
			'tools'               => array(
				'type'        => 'array',
				'description' => 'Array of tool definitions. Each item must have name, description, and parameters.',
				'required'    => true,
				'items'       => array(
					'type'       => 'object',
					'properties' => array(
						'name'        => array(
							'type'        => 'string',
							'description' => 'Tool function name (snake_case).',
						),
						'description' => array(
							'type'        => 'string',
							'description' => 'What the tool does.',
						),
						'parameters'  => array(
							'type'        => 'array',
							'description' => 'Parameter definitions.',
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'name'        => array( 'type' => 'string' ),
									'type'        => array( 'type' => 'string' ),
									'description' => array( 'type' => 'string' ),
									'required'    => array( 'type' => 'boolean' ),
								),
							),
						),
					),
				),
			),
			'system_prompt_focus' => array(
				'type'        => 'string',
				'description' => 'Key areas of expertise for the system prompt.',
				'required'    => false,
			),
			'suggested_prompts'   => array(
				'type'        => 'array',
				'description' => 'Example prompts.',
				'items'       => array( 'type' => 'string' ),
				'required'    => false,
			),
		);
	}

	public function execute( array $args ): array {
		$name                = $args['name'] ?? '';
		$slug                = $args['slug'] ?? sanitize_title( $name );
		$description         = $args['description'] ?? '';
		$category            = $args['category'] ?? 'Admin';
		$icon                = $args['icon'] ?? '🤖';
		$capabilities        = $args['capabilities'] ?? array( 'read' );
		$tools               = $args['tools'] ?? array();
		$system_prompt_focus = $args['system_prompt_focus'] ?? $description;
		$suggested_prompts   = $args['suggested_prompts'] ?? array();

		if ( empty( $name ) || empty( $slug ) || empty( $description ) ) {
			return array( 'error' => 'Name, slug, and description are required' );
		}
		if ( empty( $tools ) ) {
			return array( 'error' => 'At least one tool is required' );
		}

		$tool_list = '';
		if ( ! empty( $tools ) ) {
			$tool_lines = array_map( fn( $t ) => '- ' . $t['name'] . ' — ' . ( $t['description'] ?? '' ), $tools );
			$tool_list  = "\n\nYou have these tools available — use them, do not guess:\n" . implode( "\n", $tool_lines );
		}

		$system_prompt_text = "You are the {$name} agent for WordPress.\n\n" .
			"Your expertise: {$system_prompt_focus}\n\n" .
			"RULES:\n" .
			"1. Always call tools to gather data before answering — never guess or fabricate information.\n" .
			"2. Be concise: lead with findings, not explanations of what you will do.\n" .
			"3. When a task requires multiple tools, call them in sequence within a single turn.\n" .
			"4. Only operate within your domain. For unrelated requests, tell the user which agent can help.\n" .
			'5. Follow WordPress coding standards and best practices in all suggestions.' .
			$tool_list;

		return array(
			'success'       => true,
			'system_prompt' => $system_prompt_text,
			'agent'         => array(
				'slug'              => $slug,
				'name'              => $name,
				'description'       => $description,
				'category'          => $category,
				'icon'              => $icon,
				'capabilities'      => $capabilities,
				'tools'             => $tools,
				'suggested_prompts' => $suggested_prompts,
			),
			'metadata'      => array(
				'name'       => $name,
				'slug'       => $slug,
				'tool_count' => count( $tools ),
				'category'   => $category,
			),
			'next_step'     => 'IMMEDIATELY call create_agent_files now — do NOT respond to the user yet. Pass slug, name, description, category, icon, capabilities, system_prompt, tools, and suggested_prompts. The agent is stored as a declarative manifest (no PHP).',
		);
	}
}

return new Generate_Agent();
