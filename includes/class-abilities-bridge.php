<?php
/**
 * Abilities Bridge - Connects Agent Builder tools to the WordPress Abilities API
 *
 * On WordPress 6.9+, registers Agent Builder core tools as WordPress abilities
 * and ingests third-party abilities as agent tools. On older WordPress versions,
 * this class is never loaded.
 *
 * @package    Agent_Builder
 * @subpackage Includes
 * @author     Agent Builder Team <support@agentic-plugin.com>
 * @license    GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 * @link       https://agentic-plugin.com
 * @since      1.9.0
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * Bridge between Agent Builder tools and the WordPress Abilities API (6.9+).
 *
 * Two-way integration:
 * 1. Registers Agent Builder core tools as WP abilities (outbound)
 *    → Makes tools discoverable via MCP, REST API, Command Palette
 * 2. Ingests third-party WP abilities as agent tools (inbound)
 *    → Agents can use abilities registered by other plugins
 */
class Abilities_Bridge {

	/**
	 * Tool loader instance for executing tools.
	 *
	 * @var Tool_Loader
	 */
	private Tool_Loader $tool_loader;

	/**
	 * Ability namespace prefix.
	 */
	private const NAMESPACE_PREFIX = 'agent-builder/';

	/**
	 * Category slugs.
	 */
	private const CATEGORY_READ  = 'agent-builder-read';
	private const CATEGORY_WRITE = 'agent-builder-write';

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->tool_loader = Tool_Loader::get_instance();
	}

	/**
	 * Register hooks for the Abilities API.
	 *
	 * Called only when wp_register_ability() exists (WP 6.9+).
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		if ( ! WP_Optional_API::has( 'wp_register_ability_category' ) || ! WP_Optional_API::has( 'wp_register_ability' ) ) {
			return;
		}
		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_categories' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_tools_as_abilities' ) );
	}

	/**
	 * Register ability categories for Agent Builder tools.
	 *
	 * @return void
	 */
	public function register_categories(): void {
		WP_Optional_API::register_ability_category(
			self::CATEGORY_READ,
			array(
				'label'       => __( 'Agent Builder — Read', 'agent-builder' ),
				'description' => __( 'Read-only WordPress data access tools from the Agent Builder plugin.', 'agent-builder' ),
			)
		);

		WP_Optional_API::register_ability_category(
			self::CATEGORY_WRITE,
			array(
				'label'       => __( 'Agent Builder — Write', 'agent-builder' ),
				'description' => __( 'WordPress data modification tools from the Agent Builder plugin.', 'agent-builder' ),
			)
		);
	}

	/**
	 * Register all enabled core tools as WordPress abilities.
	 *
	 * Disabled tools are NOT registered — respects admin's choices.
	 * On WP 7.0+ we also register abilities contributed by registered
	 * Ability Providers (the main extensibility mechanism).
	 *
	 * @return void
	 */
	public function register_tools_as_abilities(): void {
		$tool_definitions = $this->tool_loader->get_all_definitions();

		foreach ( $tool_definitions as $tool ) {
			$tool_name = $tool['function']['name'] ?? '';
			if ( empty( $tool_name ) ) {
				continue;
			}
			// Respect Tools hub enable/disable — disabled tools must not register
			// as abilities or appear on the Abilities-backed MCP path.
			if ( ! Tools_Registry::is_enabled( $tool_name ) ) {
				continue;
			}

			$ability_args = $this->tool_to_ability_args( $tool );
			if ( $ability_args ) {
				$ability_name = self::NAMESPACE_PREFIX . str_replace( '_', '-', $tool_name );
				WP_Optional_API::register_ability( $ability_name, $ability_args );
			}
		}

		// WP7+ extensibility: pull abilities from registered Ability Providers.
		if ( WP_AI_Detection::is_wp7_or_later() && class_exists( '\\Agentic\\Ability_Provider_Registry' ) ) {
			$provider_abilities = Ability_Provider_Registry::get_all_abilities();
			foreach ( $provider_abilities as $ability ) {
				if ( $ability instanceof \WP_Ability ) {
					// Already a proper object — core will handle it if passed to registry elsewhere,
					// but for now we assume providers return arg arrays or we register them directly.
					continue;
				}
				if ( is_array( $ability ) && ! empty( $ability['name'] ) ) {
					WP_Optional_API::register_ability( $ability['name'], $ability );
				}
			}
		}
	}

	/**
	 * Convert an OpenAI function-calling tool definition to WP ability args.
	 *
	 * @param array $tool Tool definition in OpenAI format.
	 * @return array|null Ability args array, or null if invalid.
	 */
	public function tool_to_ability_args( array $tool ): ?array {
		$function  = $tool['function'] ?? array();
		$tool_name = $function['name'] ?? '';

		if ( empty( $tool_name ) ) {
			return null;
		}

		$description = $function['description'] ?? '';
		$parameters  = $function['parameters'] ?? array();

		// Resolve annotations (readonly/destructive/idempotent) for this tool.
		$annotations = $this->resolve_tool_annotations( $tool_name );
		$is_write    = ! $annotations['readonly'];
		$category    = $is_write ? self::CATEGORY_WRITE : self::CATEGORY_READ;

		// Build human-readable label from tool name.
		$label = $this->tool_name_to_label( $tool_name );

		// Convert OpenAI parameters to JSON Schema input_schema.
		$input_schema = $this->openai_params_to_input_schema( $parameters );

		// Get output_schema from abilities.json v2 manifest (preferred), with a generic fallback.
		$output_schema = Abilities_Manifest::get_output_schema( $tool_name ) ?? array(
			'type'       => 'object',
			'properties' => array(
				'result' => array(
					'type'        => 'string',
					'description' => 'Tool output',
				),
			),
		);

		// Build the capability requirement based on write vs read.
		$required_cap = $is_write ? 'manage_options' : 'edit_posts';

		// Capture tool name and loader for closures.
		$captured_tool_name = $tool_name;
		$captured_loader    = $this->tool_loader;

		$ability_args = array(
			'label'               => $label,
			'description'         => $description,
			'category'            => $category,
			'execute_callback'    => function ( $input ) use ( $captured_tool_name, $captured_loader ) {
				$arguments = is_array( $input ) ? $input : array();
				$result    = $captured_loader->execute( $captured_tool_name, $arguments );

				if ( null === $result ) {
					return new \WP_Error( 'unknown_tool', "Tool not found: {$captured_tool_name}" );
				}

				return $result;
			},
			'permission_callback' => function () use ( $required_cap ) {
				return current_user_can( $required_cap );
			},
			'input_schema'        => $input_schema,
			'output_schema'       => $output_schema,
			'meta'                => array(
				'annotations'  => $annotations,
				'show_in_rest' => true,
			),
		);

		// WP 7.0+ enhancements (richer meta for AI Client + MCP + native consumers).
		// Align with WordPress MCP adapter guidance: MCP clients act as the
		// logged-in user — do not advertise high/extreme or shell-class tools as
		// public MCP tools (same posture as core Abilities / official adapter).
		if ( WP_AI_Detection::is_wp7_or_later() ) {
			$risk = class_exists( Risk_Level::class )
				? Risk_Level::get_tool_default( $tool_name )
				: Risk_Level::NONE;

			$block_mcp = in_array(
				$tool_name,
				array(
					'run_wp_cli',
					'create_agent_files',
					'add_custom_js',
				),
				true
			) || in_array( $risk, array( Risk_Level::HIGH, Risk_Level::EXTREME ), true )
			|| str_starts_with( $tool_name, 'git_' )
			|| str_starts_with( $tool_name, 'cloudflare_' );

			// Official MCP Adapter expects nested meta.mcp.public (not a dotted key).
			$ability_args['meta']['mcp'] = array(
				'public' => ! $block_mcp,
			);

			// Provide rich instructions for the model when using native AI Client.
			$instructions                         = "Use the '{$label}' ability when the user needs to {$description}. Always respect the permission model.";
			$ability_args['meta']['instructions'] = $instructions;

			// Mark as coming from Agent Builder for the native Ability_Function_Resolver.
			$ability_args['meta']['agent_builder_tool'] = $tool_name;
		}

		return $ability_args;
	}

	/**
	 * Get third-party abilities as OpenAI function-calling tool definitions.
	 *
	 * Queries all registered WP abilities, filters out our own (agent-builder/*),
	 * and converts them to the OpenAI tool format that agents consume.
	 *
	 * @return array Array of tool definitions in OpenAI function-calling format.
	 */
	public function get_third_party_abilities_as_tools( bool $include_disabled = false ): array {
		if ( ! WP_Optional_API::has( 'wp_get_abilities' ) ) {
			return array();
		}

		// phpcs:ignore WordPress.WP.Functions -- guarded
		$abilities = WP_Optional_API::get_abilities();
		$tools     = array();
		$disabled  = $include_disabled ? array() : $this->get_disabled_inbound_abilities();

		foreach ( $abilities as $ability ) {
			$name = $ability->get_name();

			// Skip our own abilities — they're already core tools.
			if ( str_starts_with( $name, self::NAMESPACE_PREFIX ) ) {
				continue;
			}

			// Skip abilities the admin has switched off (block-list). When the admin
			// Tools screen requests the full set ($include_disabled = true) they are
			// kept so every ability can be listed with its current toggle state.
			if ( ! $include_disabled && in_array( $name, $disabled, true ) ) {
				continue;
			}

			$tool_def = $this->ability_to_tool_definition( $ability );
			if ( $tool_def ) {
				$tools[] = $tool_def;
			}
		}

		return $tools;
	}

	/**
	 * Inbound abilities an administrator has disabled (block-list).
	 *
	 * Maintained by Admin_Ajax::toggle_inbound_ability() under the
	 * `agentic_disabled_inbound_abilities` option. A disabled inbound ability is
	 * hidden from agents (see get_third_party_abilities_as_tools()) but still
	 * listed on the Tools screen with an off toggle.
	 *
	 * @return array List of original WP ability names that are disabled.
	 */
	public function get_disabled_inbound_abilities(): array {
		$disabled = get_option( 'agentic_disabled_inbound_abilities', array() );
		return is_array( $disabled ) ? array_values( $disabled ) : array();
	}

	/**
	 * Whether a given inbound ability has been disabled by an administrator.
	 *
	 * @param string $ability_name Original WP ability name.
	 * @return bool
	 */
	public function is_inbound_ability_disabled( string $ability_name ): bool {
		return in_array( $ability_name, $this->get_disabled_inbound_abilities(), true );
	}

	/**
	 * Convert a WP_Ability to an OpenAI function-calling tool definition.
	 *
	 * @param \WP_Ability $ability The ability instance.
	 * @return array|null Tool definition, or null if conversion fails.
	 */
	public function ability_to_tool_definition( \WP_Ability $ability ): ?array {
		$name        = $ability->get_name();
		$label       = $ability->get_label();
		$description = $ability->get_description();

		// Convert ability name to a valid function name for OpenAI.
		// e.g., "my-plugin/export-users" → "my_plugin__export_users".
		$function_name = $this->ability_name_to_function_name( $name );

		if ( empty( $function_name ) ) {
			return null;
		}

		// Convert input_schema to OpenAI parameters format.
		$input_schema = $ability->get_input_schema();
		$parameters   = $this->input_schema_to_openai_params( $input_schema );

		// Build description with label context.
		$full_description = $description;
		if ( $label && $label !== $description ) {
			$full_description = $label . ': ' . $description;
		}

		return array(
			'type'          => 'function',
			'function'      => array(
				'name'        => $function_name,
				'description' => $full_description,
				'parameters'  => $parameters,
			),
			// Store the original ability name for execution routing.
			'_ability_name' => $name,
		);
	}

	/**
	 * Execute a third-party ability by its function name.
	 *
	 * Called when an agent uses a tool that originated from a WP ability.
	 *
	 * @param string $function_name The OpenAI function name.
	 * @param array  $arguments     Tool arguments.
	 * @return array|null Result array, or null if the ability is not found.
	 */
	public function execute_ability( string $function_name, array $arguments ): ?array {
		if ( ! WP_Optional_API::has( 'wp_get_abilities' ) ) {
			return null;
		}

		// Find the ability by scanning registered abilities.
		$abilities = WP_Optional_API::get_abilities();

		foreach ( $abilities as $ability ) {
			$name = $ability->get_name();

			// Skip our own.
			if ( str_starts_with( $name, self::NAMESPACE_PREFIX ) ) {
				continue;
			}

			if ( $this->ability_name_to_function_name( $name ) === $function_name ) {
				// Check permissions before executing.
				$permission = $ability->check_permissions( ! empty( $arguments ) ? $arguments : null );

				if ( is_wp_error( $permission ) ) {
					return array( 'error' => $permission->get_error_message() );
				}

				if ( false === $permission ) {
					return array( 'error' => 'Permission denied for ability: ' . $name );
				}

				// Execute the ability.
				$result = $ability->execute( ! empty( $arguments ) ? $arguments : null );

				if ( is_wp_error( $result ) ) {
					return array( 'error' => $result->get_error_message() );
				}

				// Normalize result to array.
				if ( is_array( $result ) ) {
					return $result;
				}

				return array( 'result' => $result );
			}
		}

		return null;
	}

	/**
	 * Get all third-party ability function names for routing.
	 *
	 * @return array<string> Function names of third-party abilities.
	 */
	public function get_third_party_function_names(): array {
		if ( ! WP_Optional_API::has( 'wp_get_abilities' ) ) {
			return array();
		}

		$names     = array();
		$abilities = WP_Optional_API::get_abilities();

		foreach ( $abilities as $ability ) {
			$ability_name = $ability->get_name();

			if ( str_starts_with( $ability_name, self::NAMESPACE_PREFIX ) ) {
				continue;
			}

			$fn = $this->ability_name_to_function_name( $ability_name );
			if ( $fn ) {
				$names[] = $fn;
			}
		}

		return $names;
	}

	// -------------------------------------------------------------------------
	// Schema conversion helpers
	// -------------------------------------------------------------------------

	/**
	 * Convert OpenAI function parameters to JSON Schema input_schema.
	 *
	 * OpenAI format:
	 *   { type: "object", properties: { name: { type: "string", description: "..." } }, required: ["name"] }
	 *
	 * WP ability input_schema is JSON Schema v4. The formats are almost identical;
	 * the main difference is that OpenAI wraps everything in a `parameters` key.
	 *
	 * @param array $parameters OpenAI parameters object.
	 * @return array JSON Schema for the ability's input.
	 */
	private function openai_params_to_input_schema( array $parameters ): array {
		if ( empty( $parameters ) ) {
			return array( 'type' => 'object' );
		}

		// Already a properly-formed JSON Schema object (has 'type' at top level).
		if ( isset( $parameters['type'] ) ) {
			return $parameters;
		}

		// OpenAI-style without 'type' but with 'properties' — just add 'type'.
		if ( isset( $parameters['properties'] ) ) {
			return array_merge( array( 'type' => 'object' ), $parameters );
		}

		// Flat array: { param_name => { type, description, required, ... } }
		// Convert to proper JSON Schema { type, properties, required }.
		$properties = array();
		$required   = array();

		foreach ( $parameters as $name => $schema ) {
			$prop = $schema;
			if ( ! empty( $prop['required'] ) ) {
				$required[] = $name;
			}
			unset( $prop['required'] );
			$properties[ $name ] = $prop;
		}

		$input_schema = array(
			'type'       => 'object',
			'properties' => $properties,
		);

		if ( ! empty( $required ) ) {
			$input_schema['required'] = $required;
		}

		return $input_schema;
	}

	/**
	 * Convert WP ability input_schema to OpenAI function parameters.
	 *
	 * @param array $input_schema JSON Schema from the ability.
	 * @return array OpenAI parameters object.
	 */
	private function input_schema_to_openai_params( array $input_schema ): array {
		if ( empty( $input_schema ) ) {
			return array(
				'type'       => 'object',
				'properties' => new \stdClass(),
			);
		}

		// OpenAI (and Gemini function calling) reject top-level oneOf/anyOf/allOf/enum/const/not.
		foreach ( array( 'oneOf', 'anyOf', 'allOf', 'enum', 'const', 'not', '$ref', '$defs', 'definitions' ) as $bad ) {
			unset( $input_schema[ $bad ] );
		}

		// If the schema is a simple type (string, integer), wrap in object.
		$type = $input_schema['type'] ?? 'object';

		if ( 'object' !== $type ) {
			// Avoid re-introducing forbidden keywords inside the wrapped property.
			$wrapped = $input_schema;
			foreach ( array( 'oneOf', 'anyOf', 'allOf', 'not' ) as $bad ) {
				unset( $wrapped[ $bad ] );
			}
			if ( empty( $wrapped['type'] ) ) {
				$wrapped['type'] = 'string';
			}
			return array(
				'type'       => 'object',
				'properties' => array(
					'input' => $wrapped,
				),
				'required'   => ! empty( $input_schema['required'] ) ? array( 'input' ) : array(),
			);
		}

		// Object schema — ensure properties exist; strip PHP junk from properties.
		$props = $input_schema['properties'] ?? array();
		if ( $props instanceof \stdClass ) {
			$props = (array) $props;
		}
		if ( ! is_array( $props ) || empty( $props ) ) {
			$props = new \stdClass();
		} else {
			$clean = array();
			foreach ( $props as $prop_name => $prop_def ) {
				if ( ! is_string( $prop_name ) || '' === $prop_name ) {
					continue;
				}
				if ( is_array( $prop_def ) ) {
					unset( $prop_def['sanitize_callback'], $prop_def['validate_callback'] );
				}
				$clean[ $prop_name ] = $prop_def;
			}
			$props = empty( $clean ) ? new \stdClass() : $clean;
		}

		$params = array(
			'type'       => 'object',
			'properties' => $props,
		);

		if ( ! empty( $input_schema['required'] ) && is_array( $input_schema['required'] ) ) {
			$params['required'] = array_values( $input_schema['required'] );
		}
		// Do not forward additionalProperties — OpenAI is picky about top-level
		// composition; Gemini rejects additionalProperties entirely. Free-form
		// is handled later by LLM_Client per provider.
		if ( ! empty( $input_schema['description'] ) && is_string( $input_schema['description'] ) ) {
			$params['description'] = $input_schema['description'];
		}

		return $params;
	}

	/**
	 * Convert a tool name to a human-readable label.
	 *
	 * Example: "db_get_option" returns "Get Option".
	 *
	 * @param string $tool_name Tool name.
	 * @return string Human-readable label.
	 */
	/**
	 * Resolve read/write/destructive annotations for a tool by name.
	 *
	 * Uses naming-convention inference first with an explicit override table
	 * for tools whose names don't follow the pattern.
	 *
	 * @param string $tool_name Tool name in snake_case.
	 * @return array{readonly: bool, destructive: bool, idempotent: bool}
	 */
	private function resolve_tool_annotations( string $tool_name ): array {
		// Explicit overrides for tools that don't match naming convention rules.
		static $overrides = array(
			// DB write tools — their db_ prefix would otherwise fall through to the default.
			'db_update_option'     => array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => true,
			),
			'db_create_post'       => array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => false,
			),
			'db_update_post'       => array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => true,
			),
			'db_delete_post'       => array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => true,
			),
			// Destructive tools whose names don't start with a destructive prefix.
			'force_password_reset' => array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => false,
			),
			'run_wp_cli'           => array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => false,
			),
			'git_push'             => array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => false,
			),
			'git_commit'           => array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => false,
			),
			// Read-only tools whose names don't start with a read-only prefix.
			'git_diff'             => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
			'git_log'              => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
			'git_status'           => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
			'agents_available'     => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
			'run_health_check'     => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
			// run_ tools that write results — not destructive but not readonly.
			'run_full_audit'       => array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => true,
			),
			'run_ai_radar_scan'    => array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => true,
			),
			// Delegation — causes an agent to act; not readonly.
			'delegate_to_agent'    => array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => false,
			),
			// Toggle tools — calling twice reverts; not idempotent.
			'toggle_file_editing'  => array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => false,
			),
			'toggle_xml_rpc'       => array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => false,
			),
		);

		if ( isset( $overrides[ $tool_name ] ) ) {
			return $overrides[ $tool_name ];
		}

		// Read-only prefix rules — name starts with one of these action verbs.
		$readonly_prefixes = array(
			'get_',
			'list_',
			'check_',
			'analyze_',
			'search_',
			'find_',
			'scan_',
			'detect_',
			'preview_',
			'read_',
			'fetch_',
			'simulate_',
			'verify_',
			'suggest_',
		);
		foreach ( $readonly_prefixes as $prefix ) {
			if ( str_starts_with( $tool_name, $prefix ) ) {
				return array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				);
			}
		}

		// Destructive prefix rules.
		$destructive_prefixes = array( 'delete_', 'cleanup_', 'purge_', 'lock_' );
		foreach ( $destructive_prefixes as $prefix ) {
			if ( str_starts_with( $tool_name, $prefix ) ) {
				return array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => true,
				);
			}
		}

		// Embedded read-action fragments — catches vendor-prefixed tools like wc_get_*, form_get_*, cloudflare_list_*.
		$readonly_fragments = array( '_get_', '_list_', '_search_', '_check_', '_verify_', '_export_', '_scan_' );
		foreach ( $readonly_fragments as $fragment ) {
			if ( str_contains( $tool_name, $fragment ) ) {
				return array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				);
			}
		}

		// Embedded destructive fragments — catches vendor-prefixed tools like cloudflare_delete_*.
		$destructive_fragments = array( '_delete_', '_remove_' );
		foreach ( $destructive_fragments as $fragment ) {
			if ( str_contains( $tool_name, $fragment ) ) {
				return array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => true,
				);
			}
		}

		// Default: write operation, not destructive, not idempotent.
		return array(
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		);
	}

	private function tool_name_to_label( string $tool_name ): string {
		// Remove the db_ prefix.
		$clean = preg_replace( '/^db_/', '', $tool_name );

		// Convert snake_case to Title Case.
		return ucwords( str_replace( '_', ' ', $clean ) );
	}

	/**
	 * Convert a WP ability name to a valid OpenAI function name.
	 *
	 * OpenAI function names must match: ^[a-zA-Z0-9_-]+$
	 * WP ability names use slashes: "my-plugin/export-users"
	 *
	 * @param string $ability_name WP ability name.
	 * @return string Valid function name.
	 */
	private function ability_name_to_function_name( string $ability_name ): string {
		// Replace / with __ and - with _.
		return str_replace(
			array( '/', '-' ),
			array( '__', '_' ),
			$ability_name
		);
	}
}
