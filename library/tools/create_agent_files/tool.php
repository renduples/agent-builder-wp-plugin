<?php
/**
 * Tool: create_agent_files
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

class Create_Agent_Files extends Tool_Base {

	public function get_name(): string {
		return 'create_agent_files';
	}

	public function get_description(): string {
		return 'Create agent files in the user agents directory (wp-content/agentic-agents/).';
	}

	public function get_category(): string {
		return 'assistant-trainer';
	}

	public function get_risk_level(): string {
		return 'high';
	}

	/**
	 * Build a friendly default welcome message when the model didn't supply one.
	 *
	 * @param string $name Agent name.
	 * @param string $desc Agent description.
	 * @return string
	 */
	private function default_welcome_message( string $name, string $desc ): string {
		$name = '' !== trim( $name ) ? trim( $name ) : 'your agent';
		$desc = trim( rtrim( trim( $desc ), '.' ) );
		if ( '' !== $desc ) {
			return sprintf( "Hi! I'm %s — %s.\n\nTell me what you need and I'll help you get it done.", $name, lcfirst( $desc ) );
		}
		return sprintf( "Hi! I'm %s. How can I help you today?", $name );
	}

	/**
	 * Sensible starter prompts when the model didn't supply any.
	 *
	 * @param string $desc Agent description.
	 * @return string[]
	 */
	private function default_suggested_prompts( string $desc ): array {
		$prompts = array( 'What can you help me with?' );
		$desc    = trim( rtrim( trim( $desc ), '.' ) );
		if ( '' !== $desc ) {
			$prompts[] = ucfirst( $desc ) . ' — where should I start?';
		}
		$prompts[] = 'Show me what you can do on this site.';
		return $prompts;
	}

	public function get_parameters(): array {
		return array(
			'slug'              => array(
				'type'        => 'string',
				'description' => 'Agent slug (lowercase, hyphenated).',
				'required'    => true,
			),
			'name'              => array(
				'type'        => 'string',
				'description' => 'Human-readable agent name.',
				'required'    => true,
			),
			'description'       => array(
				'type'        => 'string',
				'description' => 'Short description of what the agent does.',
				'required'    => true,
			),
			'category'          => array(
				'type'        => 'string',
				'description' => 'Agent category (content, admin, ecommerce, frontend, developer, seo, security, media, support).',
				'required'    => false,
			),
			'icon'              => array(
				'type'        => 'string',
				'description' => 'Emoji or dashicons-* icon for the agent.',
				'required'    => false,
			),
			'capabilities'      => array(
				'type'        => 'array',
				'description' => 'Required WordPress capabilities (e.g. ["edit_posts"]).',
				'required'    => false,
				'items'       => array( 'type' => 'string' ),
			),
			'readme_content'    => array(
				'type'        => 'string',
				'description' => 'Content for README.md.',
				'required'    => false,
			),
			'overwrite'         => array(
				'type'        => 'boolean',
				'description' => 'Overwrite if exists.',
				'required'    => false,
			),
			'system_prompt'   => array(
				'type'        => 'string',
				'description' => 'System prompt text to write to templates/system-prompt.txt.',
				'required'    => false,
			),
			'tools'           => array(
				'type'        => 'array',
				'description' => 'Tool definitions used to generate abilities.json. Each item: {name, risk?, reason?}.',
				'required'    => false,
				'items'       => array(
					'type'       => 'object',
					'properties' => array(
						'name'   => array( 'type' => 'string' ),
						'risk'   => array( 'type' => 'string' ),
						'reason' => array( 'type' => 'string' ),
					),
				),
			),
			'knowledge_files' => array(
				'type'        => 'array',
				'description' => 'Knowledge files to inject into the system prompt. Filenames from wp-content/agentic-knowledge/ (e.g. "platform-knowledge.txt").',
				'required'    => false,
				'items'       => array( 'type' => 'string' ),
			),
			'suggested_prompts' => array(
				'type'        => 'array',
				'description' => 'ALWAYS provide 3-4 specific, useful starter prompts a user could click to begin — phrased in the user\'s voice and tailored to what this agent does.',
				'required'    => false,
				'items'       => array( 'type' => 'string' ),
			),
			'welcome_message' => array(
				'type'        => 'string',
				'description' => 'ALWAYS provide a short, friendly first message shown when the chat opens: a greeting, one line on what this agent does, and an invitation to start.',
				'required'    => false,
			),
		);
	}

	public function execute( array $args ): array {
		$slug           = sanitize_file_name( $args['slug'] ?? '' );
		$readme_content = $args['readme_content'] ?? '';
		$overwrite      = $args['overwrite'] ?? false;

		if ( empty( $slug ) || empty( $args['name'] ?? '' ) ) {
			return array( 'error' => 'Slug and name are required' );
		}

		// Build and validate a declarative manifest. The agent is stored as
		// plain-data agent.json (never executable PHP) and interpreted at load
		// time by the shipped Manifest_Agent class.
		// Guarantee a polished first-run experience: every created agent gets a
		// welcome message and starter prompts, generated here if the model omitted
		// them, so a new agent never opens bare.
		$agent_name = (string) ( $args['name'] ?? '' );
		$agent_desc = (string) ( $args['description'] ?? '' );

		$welcome = trim( (string) ( $args['welcome_message'] ?? '' ) );
		if ( '' === $welcome ) {
			$welcome = $this->default_welcome_message( $agent_name, $agent_desc );
		}

		$prompts = array_values( array_filter( (array) ( $args['suggested_prompts'] ?? array() ), 'is_string' ) );
		if ( empty( $prompts ) ) {
			$prompts = $this->default_suggested_prompts( $agent_desc );
		}

		// Custom agents have no shipped publisher the way bundled agents do
		// ("By Agent Builder", "By Agentic Community") — attribute to whichever
		// admin created it instead of leaving the Agents list with no author at
		// all, linked to their profile edit screen the same way bundled agents'
		// author_uri links out to the publisher.
		$author     = '';
		$author_uri = '';
		$current_user = wp_get_current_user();
		if ( $current_user && $current_user->exists() ) {
			$author     = $current_user->display_name ?: $current_user->user_login;
			$author_uri = get_edit_user_link( $current_user->ID );
		}

		$manifest = \Agentic\Agent_Manifest_Validator::validate(
			array(
				'slug'              => $slug,
				'name'              => $agent_name,
				'description'       => $agent_desc,
				'category'          => $args['category'] ?? 'admin',
				'icon'              => $args['icon'] ?? '🤖',
				'author'            => $author,
				'author_uri'        => $author_uri,
				'capabilities'      => $args['capabilities'] ?? array( 'read' ),
				'tools'             => $args['tools'] ?? array(),
				'suggested_prompts' => $prompts,
				'welcome_message'   => $welcome,
			)
		);

		if ( is_wp_error( $manifest ) ) {
			return array( 'error' => $manifest->get_error_message() );
		}

		// Writing here would shadow the bundled agent of the same name: the
		// registry loads agentic-agents/ before any plugin library, so the new
		// agent would permanently displace it and plugin updates would stop
		// reaching it. Overwrite does not authorise this — the files being
		// displaced are not in this directory.
		if ( \Agentic_Agent_Registry::get_instance()->is_bundled_slug( $slug ) ) {
			return array(
				'error' => "The slug '{$slug}' belongs to an agent bundled with the plugin. Choose a different slug — creating this agent would hide the bundled one and stop it receiving updates.",
			);
		}

		$agent_dir = AGENT_BUILDER_AGENTS_DIR . '/' . $slug;

		if ( is_dir( $agent_dir ) && ! $overwrite ) {
			return array( 'error' => "Agent directory '{$slug}' already exists. Set overwrite=true to replace." );
		}

		// Back up existing agent files before overwriting.
		$backups = array();
		if ( is_dir( $agent_dir ) && $overwrite ) {
			foreach ( array( 'agent.json', 'agent.php', 'README.md', 'templates/system-prompt.txt', 'abilities.json' ) as $rel ) {
				$existing = $agent_dir . '/' . $rel;
				$backed   = \Agentic\Tool_Helpers::backup_file( $existing );
				if ( $backed ) {
					$backups[ $rel ] = $backed;
				}
			}
		}

		if ( ! wp_mkdir_p( $agent_dir ) ) {
			return array( 'error' => 'Failed to create agent directory' );
		}

		// Remove any legacy agent.php so the declarative manifest is authoritative.
		if ( file_exists( $agent_dir . '/agent.php' ) ) {
			wp_delete_file( $agent_dir . '/agent.php' );
		}

		$manifest_json = wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$result        = file_put_contents( $agent_dir . '/agent.json', $manifest_json );

		if ( $result === false ) {
			return array( 'error' => 'Failed to write agent.json — check directory permissions for ' . $agent_dir );
		}

		$files_created = array(
			'agent.json' => strlen( $manifest_json ) . ' bytes',
		);

		if ( ! empty( $readme_content ) ) {
			file_put_contents( $agent_dir . '/README.md', $readme_content );
			$files_created['README.md'] = strlen( $readme_content ) . ' bytes';
		}

		// Write system prompt to templates/system-prompt.txt.
		$system_prompt = $args['system_prompt'] ?? '';
		if ( ! empty( $system_prompt ) && strpos( $system_prompt, "\n" ) === false && strpos( $system_prompt, '\n' ) !== false ) {
			$system_prompt = str_replace( array( '\n', '\t', '\/' ), array( "\n", "\t", '/' ), $system_prompt );
		}
		if ( ! empty( $system_prompt ) ) {
			wp_mkdir_p( $agent_dir . '/templates' );
			file_put_contents( $agent_dir . '/templates/system-prompt.txt', $system_prompt );
			$files_created['templates/system-prompt.txt'] = strlen( $system_prompt ) . ' bytes';
		}

		// Generate abilities.json and integrity signature from the tools array.
		// Validate that each tool actually exists in the plugin's tool catalog.
		$tools_for_manifest = $args['tools'] ?? array();
		$tool_loader        = \Agentic\Tool_Loader::get_instance();
		$tool_loader->load();
		$skipped_tools = array();

		if ( ! empty( $tools_for_manifest ) ) {
			$abilities = array();
			foreach ( $tools_for_manifest as $tool ) {
				$tool_name = $tool['name'] ?? '';
				if ( empty( $tool_name ) ) {
					continue;
				}
				// Skip tools that don't exist — they would be dead references.
				if ( ! $tool_loader->get( $tool_name ) ) {
					$skipped_tools[] = $tool_name;
					continue;
				}
				$risk  = $tool['risk'] ?? $this->infer_risk( $tool_name );
				$risk  = $this->normalize_risk( $risk );
				$entry = array( 'risk' => $risk );
				if ( in_array( $risk, array( 'medium', 'high' ), true ) ) {
					$entry['reason'] = $tool['reason'] ?? 'Write operation';
				}
				$abilities[ $tool_name ] = $entry;
			}

			$manifest = array(
				'$schema'     => 'https://agentic-plugin.com/schemas/abilities/v1.json',
				'version'     => '1.0',
				'agent'       => $slug,
				'description' => "Tools required by the {$slug} agent",
			);

			// Include knowledge files if specified.
			$knowledge_files = $args['knowledge_files'] ?? array();
			if ( ! empty( $knowledge_files ) ) {
				$manifest['knowledge_files'] = array_values( array_filter( $knowledge_files, 'is_string' ) );
			}

			$manifest['abilities'] = (object) $abilities;

			// write_manifest() writes abilities.json and its integrity signature
			// as one atomic step — see its docblock for why this can't be two
			// separate calls.
			if ( \Agentic\Abilities_Manifest::write_manifest( $agent_dir, $slug, $manifest ) ) {
				$manifest_json                        = wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
				$files_created['abilities.json']      = strlen( (string) $manifest_json ) . ' bytes';
				$files_created['abilities-signature'] = 'generated';
			}
		}

		// Auto-activate the new agent so it's immediately usable.
		$registry = \Agentic_Agent_Registry::get_instance();
		$registry->get_installed_agents( true ); // Force refresh to pick up new files.
		$activated = $registry->activate_agent( $slug );

		$response = array(
			'success'   => true,
			'slug'      => $slug,
			'directory' => $agent_dir,
			'files'     => $files_created,
			'activated' => ! is_wp_error( $activated ),
		);

		if ( ! empty( $backups ) ) {
			$response['backups'] = $backups;
		}

		if ( ! empty( $skipped_tools ) ) {
			$response['skipped_tools'] = $skipped_tools;
			$response['warning']       = 'Some tools were skipped because they do not exist in the tool catalog: ' . implode( ', ', $skipped_tools );
		}

		return $response;
	}

	private function infer_risk( string $tool_name ): string {
		// First, check the actual tool's declared risk level if it exists.
		$tool_loader = \Agentic\Tool_Loader::get_instance();
		$tool_loader->load();
		$tool_instance = $tool_loader->get( $tool_name );
		if ( $tool_instance && method_exists( $tool_instance, 'get_risk_level' ) ) {
			return $this->normalize_risk( $tool_instance->get_risk_level() );
		}

		// Fallback: infer from naming convention.
		$read_prefixes = array( 'get_', 'list_', 'search_', 'analyze_', 'analyse_', 'check_', 'read_', 'count_', 'fetch_', 'find_', 'is_', 'has_', 'detect_', 'audit_', 'validate_', 'preview_', 'score_', 'simulate_', 'suggest_', 'run_' );
		foreach ( $read_prefixes as $pfx ) {
			if ( str_starts_with( $tool_name, $pfx ) ) {
				return 'none';
			}
		}
		$high_prefixes = array( 'delete_', 'remove_', 'bulk_', 'purge_', 'reset_', 'clear_', 'drop_', 'destroy_', 'uninstall_' );
		foreach ( $high_prefixes as $pfx ) {
			if ( str_starts_with( $tool_name, $pfx ) ) {
				return 'high';
			}
		}
		return 'low';
	}

	/**
	 * Normalize LLM-provided risk values to the 5-tier system.
	 *
	 * Models sometimes invent risk names like "read", "write", "safe", "dangerous".
	 * Map them to valid values: none, low, medium, high, extreme.
	 */
	private function normalize_risk( string $risk ): string {
		$valid = array( 'none', 'low', 'medium', 'high', 'extreme' );
		if ( in_array( $risk, $valid, true ) ) {
			return $risk;
		}
		// Map common LLM-invented risk names.
		return match ( strtolower( $risk ) ) {
			'read', 'safe', 'readonly', 'read-only', 'info' => 'none',
			'write', 'modify', 'update', 'create'           => 'low',
			'destructive', 'dangerous', 'delete'             => 'high',
			'critical'                                       => 'extreme',
			default                                          => 'low',
		};
	}
}

return new Create_Agent_Files();
