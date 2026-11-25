<?php
/**
 * Tool: generate_tool_schema
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

class Generate_Tool_Schema extends Tool_Base {

	public function get_name(): string {
		return 'generate_tool_schema';
	}

	public function get_description(): string {
		return 'Generate OpenAI function-calling schema for a tool from a structured specification.';
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
			'tool_name'        => array(
				'type'        => 'string',
				'description' => 'Tool function name.',
				'required'    => true,
			),
			'tool_description' => array(
				'type'        => 'string',
				'description' => 'Tool description.',
				'required'    => true,
			),
			'parameters'       => array(
				'type'        => 'array',
				'description' => 'Array of parameter definitions.',
				'items'       => array(
					'type'       => 'object',
					'properties' => array(
						'name'        => array( 'type' => 'string' ),
						'type'        => array( 'type' => 'string' ),
						'description' => array( 'type' => 'string' ),
						'required'    => array( 'type' => 'boolean' ),
					),
				),
				'required'    => false,
			),
		);
	}

	public function execute( array $args ): array {
		$tool_name        = $args['tool_name'] ?? '';
		$tool_description = $args['tool_description'] ?? '';
		$parameters       = $args['parameters'] ?? array();

		if ( empty( $tool_name ) || empty( $tool_description ) ) {
			return array( 'error' => 'tool_name and tool_description are required' );
		}

		$properties = array();
		$required   = array();

		foreach ( $parameters as $param ) {
			$prop = array(
				'type'        => $param['type'] ?? 'string',
				'description' => $param['description'] ?? '',
			);

			if ( isset( $param['enum'] ) ) {
				$prop['enum'] = $param['enum'];
			}

			$properties[ $param['name'] ] = $prop;

			if ( ! empty( $param['required'] ) ) {
				$required[] = $param['name'];
			}
		}

		$schema = array(
			'type'     => 'function',
			'function' => array(
				'name'        => $tool_name,
				'description' => $tool_description,
				'parameters'  => array(
					'type'       => 'object',
					'properties' => $properties,
					'required'   => $required,
				),
			),
		);

		// Add a quality note for the trainer
		$quality_note = empty( $properties )
			? 'This tool has no parameters — consider whether it needs input for high-quality behavior.'
			: '';

		$php_code = $this->schema_to_php( $schema );

		return array(
			'schema'   => $schema,
			'php_code' => $php_code,
		);
	}

	private function schema_to_php( array $schema ): string {
		$name     = $schema['function']['name'];
		$desc     = $schema['function']['description'];
		$props    = $schema['function']['parameters']['properties'];
		$required = $schema['function']['parameters']['required'];

		$props_code = array();

		foreach ( $props as $key => $prop ) {
			$prop_lines = array(
				"                        '{$key}' => [",
				"                            'type' => '{$prop['type']}',",
				"                            'description' => '{$prop['description']}',",
			);

			if ( isset( $prop['enum'] ) ) {
				$enum         = "[ '" . implode( "', '", $prop['enum'] ) . "' ]";
				$prop_lines[] = "                            'enum' => {$enum},";
			}

			$prop_lines[] = '                        ],';
			$props_code[] = implode( "\n", $prop_lines );
		}

		$props_str    = implode( "\n", $props_code );
		$required_str = empty( $required ) ? '[]' : "[ '" . implode( "', '", $required ) . "' ]";

		return "[\n    'type' => 'function',\n    'function' => [\n        'name' => '{$name}',\n        'description' => '" . addslashes( $desc ) . "',\n        'parameters' => [\n            'type' => 'object',\n            'properties' => [\n{$props_str}\n            ],\n            'required' => {$required_str},\n        ],\n    ],\n],";
	}
}

return new Generate_Tool_Schema();
