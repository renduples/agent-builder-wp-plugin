<?php
/**
 * Tool: generate_system_prompt
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

class Generate_System_Prompt extends Tool_Base {

	public function get_name(): string {
		return 'generate_system_prompt';
	}

	public function get_description(): string {
		return 'Generate a system prompt for an agent given its purpose.';
	}

	public function get_category(): string {
		return 'assistant-trainer';
	}

	public function get_risk_level(): string {
		return 'none';
	}

	public function get_annotations(): array {
		return array(
			'read_only'   => true,
			'destructive' => false,
		);
	}

	public function get_parameters(): array {
		return array(
			'agent_name'  => array(
				'type'        => 'string',
				'description' => 'Agent display name.',
				'required'    => true,
			),
			'purpose'     => array(
				'type'        => 'string',
				'description' => 'What the agent does.',
				'required'    => true,
			),
			'personality' => array(
				'type'        => 'string',
				'description' => 'Personality tone.',
				'required'    => false,
			),
			'constraints' => array(
				'type'        => 'array',
				'description' => 'Things the agent should never do.',
				'required'    => false,
			),
			'tool_names'  => array(
				'type'        => 'array',
				'description' => 'Names of available tools.',
				'required'    => false,
			),
		);
	}

	public function execute( array $args ): array {
		$agent_name  = $args['agent_name'] ?? '';
		$purpose     = trim( $args['purpose'] ?? '' );
		$personality = $args['personality'] ?? 'helpful, precise, and professional';
		$constraints = $args['constraints'] ?? array();
		$tool_names  = $args['tool_names'] ?? array();

		if ( empty( $agent_name ) || empty( $purpose ) ) {
			return array( 'error' => 'agent_name and purpose are required' );
		}

		$constraints_section = '';
		if ( ! empty( $constraints ) ) {
			$constraints_list    = array_map( fn( $c ) => "- {$c}", $constraints );
			$constraints_section = "\n\nYou must NEVER:\n" . implode( "\n", $constraints_list );
		}

		$tools_section = '';
		if ( ! empty( $tool_names ) ) {
			$tools_list    = array_map( fn( $t ) => "- {$t}", $tool_names );
			$tools_section = "\n\nYou have these tools available. Use them proactively and in the right combinations:\n" . implode( "\n", $tools_list );
		}

		// Produce a much higher-quality, opinionated prompt
		$prompt = "You are the {$agent_name} for WordPress.\n\n" .
			"**Your core purpose:** {$purpose}\n\n" .
			"**Your personality:** {$personality}\n\n" .
			"You are a focused specialist. You take pride in delivering excellent, reliable results within your domain.\n\n" .
			"== How You Work ==\n\n" .
			"1. Gather real information using your tools before giving answers.\n" .
			"2. Stay strictly inside your defined expertise. If something is outside your scope, say so clearly and suggest the right specialist.\n" .
			"3. Be clear, structured, and actionable. Use headings, bullets, and examples when they help.\n" .
			"4. When performing write operations, be conservative and confirm intent.\n" .
			'5. Explain what you did and why when it adds value.' .
			$constraints_section .
			$tools_section . "\n\n" .
			'Follow WordPress best practices and the Agent Builder philosophy in everything you do.';

		return array(
			'system_prompt' => $prompt,
			'agent_name'    => $agent_name,
			'char_count'    => strlen( $prompt ),
			'quality_level' => 'high',
		);
	}
}

return new Generate_System_Prompt();
