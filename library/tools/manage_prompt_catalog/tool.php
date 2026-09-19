<?php
/**
 * Tool: manage_prompt_catalog
 *
 * Read and edit tests/most_popular_prompts.md — the ranked catalog of
 * real-world prompts the prompt-test harness replays against the bundled
 * agents. Lets an agent keep the suite that measures it current: re-rank a
 * prompt whose frustration has changed, correct an expectation that no longer
 * matches an agent's manifest, add a prompt for a complaint nobody had thought
 * of yet.
 *
 * Edits are line-level against the row's own source line, so the file's
 * hand-written research sections survive intact.
 *
 * @package    Agent_Builder
 * @subpackage Tools
 * @since      3.4.1
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Agentic\Prompt_Testing\Prompt_Catalog;
use Agentic\Prompt_Testing\Prompt_Catalog_Writer;

/**
 * Maintain the prompt-test catalog.
 */
class Manage_Prompt_Catalog extends \Agentic\Tool_Base {

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'manage_prompt_catalog';
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return 'Read or edit the prompt-test catalog — the ranked list of real-world prompts used to test the bundled agents. Use list/get to see what is covered, add to cover a new real-world complaint, update to re-rank a prompt or correct its expected tools, and remove to drop one that is no longer representative. Each row names the agent that should handle the prompt and the tools the run should reach for.';
	}

	/**
	 * Get the tool category.
	 *
	 * @return string
	 */
	public function get_category(): string {
		return 'assistant-trainer';
	}

	/**
	 * Get the parameter schema.
	 *
	 * @return array
	 */
	public function get_parameters(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'action'       => array(
					'type'        => 'string',
					'enum'        => array( 'list', 'get', 'add', 'update', 'remove' ),
					'description' => 'list: every prompt, optionally filtered by agent. get: one prompt in full. add: append a new prompt to an agent\'s section. update: change fields on an existing prompt. remove: delete a prompt.',
				),
				'id'           => array(
					'type'        => 'string',
					'description' => 'Prompt ID such as P07. Required for get, update and remove.',
				),
				'agent'        => array(
					'type'        => 'string',
					'description' => 'Bundled agent slug. Filters list; required for add; reassigns on update.',
				),
				'prompt'       => array(
					'type'        => 'string',
					'description' => 'The prompt text, written the way a non-technical site owner would actually type it. Must be self-contained — no reference to earlier conversation. Required for add.',
				),
				'rank'         => array(
					'type'        => 'integer',
					'description' => 'Frustration rank, 1 = most frustrating. Ranks should stay unique across the catalog.',
				),
				'expect_tools' => array(
					'type'        => 'string',
					'description' => 'Comma-separated tool names the run should reach for. Every name must be a real tool the assigned agent declares, or the catalog will not validate. Leave empty for conversational prompts where any sound answer is acceptable.',
				),
				'coverage'     => array(
					'type'        => 'string',
					'enum'        => array( 'full', 'partial', 'none' ),
					'description' => 'How well the assigned agent can actually answer. Be honest: "partial" and "none" rows are what surface product gaps.',
				),
				'notes'        => array(
					'type'        => 'string',
					'description' => 'Human commentary — what the prompt is really testing, whether it needs site content, whether it is expected to stop for approval.',
				),
				'source'       => array(
					'type'        => 'string',
					'description' => 'Short tag for where the ranking came from: common, security, faq, woo, seo, maint, content, editor or ai.',
				),
			),
			'required'   => array( 'action' ),
		);
	}

	/**
	 * Execute.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array
	 */
	public function execute( array $arguments ): array {
		$denied = \Agentic\Tool_Helpers::deny_unless_admin_user();
		if ( null !== $denied ) {
			return $denied;
		}

		$path = self::catalog_path();

		if ( ! is_readable( $path ) ) {
			return array(
				'error' => 'The prompt catalog is not present on this install. It ships in the GitHub repo at tests/most_popular_prompts.md but is excluded from the WordPress.org build, so prompt testing is only available on a source checkout.',
			);
		}

		$action = sanitize_key( (string) ( $arguments['action'] ?? '' ) );

		try {
			return match ( $action ) {
				'list'   => $this->do_list( $path, $arguments ),
				'get'    => $this->do_get( $path, $arguments ),
				'add'    => $this->do_write( $path, $arguments, 'add' ),
				'update' => $this->do_write( $path, $arguments, 'update' ),
				'remove' => $this->do_write( $path, $arguments, 'remove' ),
				default  => array( 'error' => 'Unknown action. Use list, get, add, update or remove.' ),
			};
		} catch ( \RuntimeException $e ) {
			return array( 'error' => $e->getMessage() );
		}
	}

	/**
	 * The catalog path.
	 *
	 * @return string
	 */
	public static function catalog_path(): string {
		return AGENT_BUILDER_DIR . 'tests/most_popular_prompts.md';
	}

	/**
	 * List prompts.
	 *
	 * @param string $path      Catalog path.
	 * @param array  $arguments Tool arguments.
	 * @return array
	 */
	private function do_list( string $path, array $arguments ): array {
		$catalog = Prompt_Catalog::load( $path );
		$rows    = $catalog['rows'];

		$agent = sanitize_text_field( (string) ( $arguments['agent'] ?? '' ) );
		if ( '' !== $agent ) {
			$rows = Prompt_Catalog::filter( $rows, array( 'agent' => $agent ) );
		}

		return array(
			'success'  => true,
			'total'    => count( $rows ),
			'by_agent' => Prompt_Catalog::count_by_agent( $rows ),
			'warnings' => $catalog['warnings'],
			'prompts'  => array_map(
				static fn( array $row ): array => array(
					'id'           => $row['id'],
					'rank'         => $row['rank'],
					'agent'        => $row['agent'],
					'prompt'       => $row['prompt'],
					'expect_tools' => $row['expect_tools'],
					'coverage'     => $row['coverage'],
				),
				$rows
			),
		);
	}

	/**
	 * Get one prompt.
	 *
	 * @param string $path      Catalog path.
	 * @param array  $arguments Tool arguments.
	 * @return array
	 */
	private function do_get( string $path, array $arguments ): array {
		$id = strtoupper( sanitize_text_field( (string) ( $arguments['id'] ?? '' ) ) );

		if ( '' === $id ) {
			return array( 'error' => 'An id is required, for example P07.' );
		}

		foreach ( Prompt_Catalog::load( $path )['rows'] as $row ) {
			if ( strtoupper( (string) $row['id'] ) === $id ) {
				unset( $row['columns'] );
				return array(
					'success' => true,
					'prompt'  => $row,
				);
			}
		}

		return array( 'error' => sprintf( 'No prompt with ID "%s".', $id ) );
	}

	/**
	 * Add, update or remove a row.
	 *
	 * Validates the result against the real agent and tool registries before
	 * reporting success, so a bad edit is caught here rather than several
	 * dollars into the next run.
	 *
	 * @param string $path      Catalog path.
	 * @param array  $arguments Tool arguments.
	 * @param string $action    add|update|remove.
	 * @return array
	 */
	private function do_write( string $path, array $arguments, string $action ): array {
		$id = strtoupper( sanitize_text_field( (string) ( $arguments['id'] ?? '' ) ) );

		if ( 'add' !== $action && '' === $id ) {
			return array( 'error' => 'An id is required, for example P07.' );
		}

		$fields = array();
		foreach ( array( 'agent', 'prompt', 'rank', 'expect_tools', 'coverage', 'notes', 'source' ) as $field ) {
			if ( isset( $arguments[ $field ] ) ) {
				$fields[ $field ] = $arguments[ $field ];
			}
		}

		$result = match ( $action ) {
			'add'    => Prompt_Catalog_Writer::add_row( $path, $fields ),
			'update' => Prompt_Catalog_Writer::update_row( $path, $id, $fields ),
			default  => Prompt_Catalog_Writer::remove_row( $path, $id ),
		};

		if ( empty( $result['success'] ) ) {
			return array( 'error' => $result['message'] );
		}

		$catalog  = Prompt_Catalog::load( $path );
		$problems = Prompt_Catalog::validate( $catalog['rows'], $this->agent_slugs(), $this->tool_names() );

		return array(
			'success'  => true,
			'message'  => $result['message'],
			'row'      => $result['row'] ?? null,
			'total'    => count( $catalog['rows'] ),
			'problems' => $problems,
			'note'     => empty( $problems )
				? 'The catalog still validates.'
				: 'The catalog now has validation problems — fix them before the next run, or the harness will refuse to start.',
		);
	}

	/**
	 * Installed agent slugs.
	 *
	 * @return string[]
	 */
	private function agent_slugs(): array {
		$registry = \Agentic_Agent_Registry::get_instance();

		return array_keys( (array) $registry->get_installed_agents() );
	}

	/**
	 * Every tool directory name.
	 *
	 * @return string[]
	 */
	private function tool_names(): array {
		$dirs = glob( AGENT_BUILDER_DIR . 'library/tools/*', GLOB_ONLYDIR );

		return array_map( 'basename', is_array( $dirs ) ? $dirs : array() );
	}

	/**
	 * Intrinsic risk floor.
	 *
	 * Deliberately NONE rather than a flat MEDIUM. Risk_Level::get_tool_default()
	 * takes no action argument, so a floor above NONE applies to every action of
	 * the tool and would override the per-action map in abilities.json — making
	 * an agent ask permission merely to *read* the catalog before proposing
	 * anything. Real risk is declared per action in the manifest instead:
	 * list/get are none, add/update/remove are medium.
	 *
	 * @return string
	 */
	public function get_risk_level(): string {
		return \Agentic\Risk_Level::NONE;
	}

	/**
	 * MCP/Abilities annotations.
	 *
	 * @return array
	 */
	public function get_annotations(): array {
		return array(
			'readonly'    => false,
			'destructive' => false,
		);
	}
}

return new Manage_Prompt_Catalog();
