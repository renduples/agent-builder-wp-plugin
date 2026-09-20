<?php
/**
 * Tool: analyze_prompt_results
 *
 * Turns prompt-test outcomes into concrete, checkable proposals: which agent is
 * missing which tool, which prompts have no honest answer, which implemented
 * tools no agent can reach. This is the half of the self-improvement loop that
 * decides what to change.
 *
 * It is read-only and makes no LLM calls of its own — it correlates the catalog,
 * the agent manifests and the tool registry, which is enough to name most gaps
 * precisely. Acting on a proposal stays a separate, risk-gated step.
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

/**
 * Propose tool and skill improvements from prompt-test coverage.
 */
class Analyze_Prompt_Results extends \Agentic\Tool_Base {

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'analyze_prompt_results';
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return 'Analyse how well the bundled agents cover the prompt-test catalog and propose specific improvements: tools an agent should be granted, prompts with no honest answer, and implemented tools that no agent can currently reach. Read-only — it proposes, it does not change anything. Run it after run_prompt_tests, or on its own to audit coverage without spending anything.';
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
				'agent'          => array(
					'type'        => 'string',
					'description' => 'Restrict the analysis to one agent slug.',
				),
				'include_unused' => array(
					'type'        => 'boolean',
					'description' => 'Include the full list of implemented tools that no bundled agent declares. Long, but it is where most of the missed capability is. Defaults to a summary count.',
				),
			),
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

		if ( ! $this->is_available() ) {
			return $this->tool_error( 'catalog_missing', $this->get_unavailable_reason() );
		}

		$path = Manage_Prompt_Catalog::catalog_path();

		try {
			$catalog = Prompt_Catalog::load( $path );
		} catch ( \RuntimeException $e ) {
			return array( 'error' => $e->getMessage() );
		}

		$only  = strtolower( sanitize_text_field( (string) ( $arguments['agent'] ?? '' ) ) );
		$rows  = '' === $only ? $catalog['rows'] : Prompt_Catalog::filter( $catalog['rows'], array( 'agent' => $only ) );
		$tools = $this->agent_tool_map();

		$proposals = array_merge(
			$this->missing_expectations( $rows, $tools ),
			$this->weak_coverage( $rows )
		);

		$unused = $this->unreachable_tools( $tools );

		return array(
			'success'      => true,
			'prompts'      => count( $rows ),
			'coverage'     => $this->coverage_summary( $rows ),
			'proposals'    => $proposals,
			'unreachable'  => array(
				'count'   => count( $unused ),
				'of'      => count( $this->all_tool_names() ),
				'summary' => sprintf(
					'%d of %d implemented tools are declared by no bundled agent, so no prompt can reach them.',
					count( $unused ),
					count( $this->all_tool_names() )
				),
				'tools'   => empty( $arguments['include_unused'] ) ? array() : $unused,
			),
			'last_results' => $this->last_run_summary(),
			'how_to_act'   => array(
				'grant_tool'  => "Granting an agent a tool means adding it to library/agents/<slug>/agent.json and abilities.json (with a risk level), then re-signing with \\Agentic\\Abilities_Manifest::save_integrity_hash('<slug>') — the agent is blocked outright until the signature matches. Bump AGENT_BUILDER_DB_VERSION too, or the cached tool-path list will not pick the file up on existing sites.",
				'teach_skill' => 'If the tools already exist and the agent simply sequences them badly, a skill is the cheaper fix than a new tool — use manage_skill.',
				'verify'      => 'After any change, re-run run_prompt_tests on the affected prompts to confirm the tool is actually reached.',
			),
		);
	}

	/**
	 * Prompts whose expected tools the assigned agent cannot call.
	 *
	 * This is the highest-confidence proposal there is: the catalog says the run
	 * should reach a tool, and the manifest says the agent has no way to.
	 *
	 * @param array<int, array<string, mixed>> $rows  Catalog rows.
	 * @param array<string, string[]>          $tools Agent slug to declared tools.
	 * @return array<int, array<string, mixed>>
	 */
	private function missing_expectations( array $rows, array $tools ): array {
		$proposals = array();

		foreach ( $rows as $row ) {
			$declared = $tools[ $row['agent'] ] ?? array();
			$missing  = array_values( array_diff( (array) $row['expect_tools'], $declared ) );

			if ( empty( $missing ) ) {
				continue;
			}

			$proposals[] = array(
				'kind'       => 'grant_tool',
				'confidence' => 'high',
				'prompt'     => $row['id'],
				'agent'      => $row['agent'],
				'tools'      => $missing,
				'why'        => sprintf(
					'%s expects %s, but %s does not declare %s. The prompt cannot pass as written.',
					$row['id'],
					implode( ', ', $missing ),
					$row['agent'],
					count( $missing ) > 1 ? 'them' : 'it'
				),
			);
		}

		return $proposals;
	}

	/**
	 * Prompts the catalog itself admits are not well covered.
	 *
	 * @param array<int, array<string, mixed>> $rows Catalog rows.
	 * @return array<int, array<string, mixed>>
	 */
	private function weak_coverage( array $rows ): array {
		$proposals = array();

		foreach ( $rows as $row ) {
			if ( ! in_array( $row['coverage'], array( 'partial', 'none' ), true ) ) {
				continue;
			}

			$proposals[] = array(
				'kind'       => 'none' === $row['coverage'] ? 'capability_gap' : 'partial_coverage',
				'confidence' => 'medium',
				'prompt'     => $row['id'],
				'agent'      => $row['agent'],
				'tools'      => array(),
				'why'        => sprintf(
					'%s is marked "%s": %s',
					$row['id'],
					$row['coverage'],
					'' === (string) $row['notes'] ? 'no note recorded — read the prompt and decide what is missing.' : $row['notes']
				),
			);
		}

		return $proposals;
	}

	/**
	 * Coverage counts.
	 *
	 * @param array<int, array<string, mixed>> $rows Catalog rows.
	 * @return array<string, int>
	 */
	private function coverage_summary( array $rows ): array {
		$summary = array(
			'full'        => 0,
			'partial'     => 0,
			'none'        => 0,
			'unspecified' => 0,
		);

		foreach ( $rows as $row ) {
			$key = (string) $row['coverage'];
			if ( ! isset( $summary[ $key ] ) ) {
				$key = 'unspecified';
			}
			++$summary[ $key ];
		}

		return $summary;
	}

	/**
	 * Which tools each bundled agent declares.
	 *
	 * @return array<string, string[]>
	 */
	private function agent_tool_map(): array {
		$map = array();

		foreach ( (array) glob( AGENT_BUILDER_DIR . 'library/agents/*/agent.json' ) as $file ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Bundled manifest read as data.
			$manifest = json_decode( (string) file_get_contents( $file ), true );

			if ( ! is_array( $manifest ) ) {
				continue;
			}

			$map[ basename( dirname( $file ) ) ] = array_map( 'strval', (array) ( $manifest['tools'] ?? array() ) );
		}

		return $map;
	}

	/**
	 * Every implemented tool name.
	 *
	 * @return string[]
	 */
	private function all_tool_names(): array {
		$dirs = glob( AGENT_BUILDER_DIR . 'library/tools/*', GLOB_ONLYDIR );

		return array_map( 'basename', is_array( $dirs ) ? $dirs : array() );
	}

	/**
	 * Implemented tools no bundled agent declares.
	 *
	 * @param array<string, string[]> $tools Agent tool map.
	 * @return string[]
	 */
	private function unreachable_tools( array $tools ): array {
		$declared = array();

		foreach ( $tools as $list ) {
			$declared = array_merge( $declared, $list );
		}

		$unused = array_values( array_diff( $this->all_tool_names(), array_unique( $declared ) ) );
		sort( $unused );

		return $unused;
	}

	/**
	 * Headline numbers from the last CLI run, if one is on disk.
	 *
	 * @return array<string, mixed>
	 */
	private function last_run_summary(): array {
		$path = Prompt_Catalog::default_report_path();

		if ( ! is_readable( $path ) ) {
			return array( 'available' => false );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local report file read as data.
		$report  = (string) file_get_contents( $path );
		$matches = array();

		preg_match( '/^\*\*(.+?)\*\*$/m', $report, $matches );

		$failures = array();
		if ( preg_match( '/## Needs attention\n\n((?:- .+\n)+)/', $report, $matches2 ) ) {
			$failures = array_values(
				array_filter( array_map( 'trim', explode( "\n", $matches2[1] ) ) )
			);
		}

		return array(
			'available' => true,
			'modified'  => gmdate( 'Y-m-d H:i:s', (int) filemtime( $path ) ) . ' UTC',
			'totals'    => $matches[1] ?? '',
			'failures'  => array_slice( $failures, 0, 20 ),
		);
	}


	/**
	 * Whether a prompt catalog can be found on this install.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return Prompt_Catalog::is_available();
	}

	/**
	 * Explain why the tool cannot run.
	 *
	 * @return string
	 */
	public function get_unavailable_reason(): string {
		return __( 'No prompt-test catalog was found on this site. The catalog normally ships with the plugin at library/prompt-tests/most_popular_prompts.md.', 'agent-builder' );
	}

	/**
	 * MCP/Abilities annotations.
	 *
	 * Correlates the catalog, the manifests and the tool registry and writes
	 * nothing — the proposals it returns are for a human or a later, gated tool
	 * call to act on.
	 *
	 * @return array
	 */
	public function get_annotations(): array {
		return array(
			'readonly'    => true,
			'destructive' => false,
		);
	}
}

return new Analyze_Prompt_Results();
