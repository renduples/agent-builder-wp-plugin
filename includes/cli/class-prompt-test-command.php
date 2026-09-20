<?php
/**
 * WP-CLI command: wp agent prompt-test
 *
 * PHPUnit for the agents rather than for the code. Replays a ranked catalog of
 * real-world prompts against the bundled agents on a live install and writes a
 * markdown report.
 *
 * Unlike `composer test` this makes real, paid LLM calls, and prompts that write
 * really write — so there is no default selection: a bare invocation prints the
 * selectors and exits rather than quietly spending money.
 *
 * Examples:
 *   wp agent prompt-test --dry-run
 *   wp agent prompt-test --user=admin --agent=seo-optimizer
 *   wp agent prompt-test --user=admin --rank=1-10 --max-cost=0.50
 *   wp agent prompt-test --user=admin --all --judge --yes
 *
 * @package    Agent_Builder
 * @subpackage CLI
 * @since      3.4.1
 *
 * php version 8.1
 */

declare( strict_types=1 );

namespace Agentic\CLI;

use Agentic\Prompt_Testing\Prompt_Catalog;
use Agentic\Prompt_Testing\Prompt_Test_Reporter;
use Agentic\Prompt_Testing\Prompt_Test_Runner;
use Agentic\Prompt_Testing\Verdict_Factory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Runs catalog prompts against the bundled agents.
 */
class Prompt_Test_Command extends \WP_CLI_Command {

	/**
	 * Exit code when the run was aborted rather than completed.
	 */
	private const EXIT_ABORTED = 2;

	/**
	 * Replay catalog prompts against the bundled agents.
	 *
	 * ## OPTIONS
	 *
	 * [<catalog>]
	 * : Path to a prompt catalog. Defaults to the site's own copy if it has one,
	 * otherwise the one bundled with the plugin.
	 *
	 * [--all]
	 * : Run every prompt in the catalog.
	 *
	 * [--agent=<slugs>]
	 * : Only prompts assigned to these agents. Comma-separated.
	 *
	 * [--rank=<spec>]
	 * : Only these ranks. Accepts 1-10, 1,3,7, 5- or -10.
	 *
	 * [--id=<spec>]
	 * : Only these prompt IDs. Accepts P01, P01,P05 or P01-P10.
	 *
	 * [--limit=<n>]
	 * : Cap the number of prompts after all other filters.
	 *
	 * [--shuffle]
	 * : Randomise order, so --limit samples across the catalog instead of the top N.
	 *
	 * [--dry-run]
	 * : Resolve, validate and print the plan without calling the LLM. Costs nothing.
	 *
	 * [--judge]
	 * : Also grade each answer 1-5 with the configured provider. Roughly doubles cost.
	 *
	 * [--verdict=<mode>]
	 * : How to decide pass/fail.
	 * ---
	 * default: assert
	 * options:
	 *   - assert
	 *   - none
	 * ---
	 *
	 * [--mode=<mode>]
	 * : Execution path. "chat" is what a real user gets; "autonomous" is the
	 * scheduled-task path and skips capability checks.
	 * ---
	 * default: chat
	 * options:
	 *   - chat
	 *   - autonomous
	 * ---
	 *
	 * [--max-cost=<usd>]
	 * : Stop the run once this much has been spent. 0 disables the cap.
	 * ---
	 * default: 2.00
	 * ---
	 *
	 * [--max-iterations=<n>]
	 * : Tool-loop ceiling per prompt.
	 * ---
	 * default: 10
	 * ---
	 *
	 * [--timeout=<seconds>]
	 * : Per-request LLM timeout. Defaults to the provider's own.
	 *
	 * [--delay=<seconds>]
	 * : Sleep between prompts, to stay under a provider's rate limit.
	 *
	 * [--prompts=<path>]
	 * : Path to a prompt catalog. Same as the positional argument.
	 *
	 * [--output=<path>]
	 * : Where to write the report. Use - for stdout.
	 *
	 * [--format=<format>]
	 * : Console output format. Anything but md suppresses the report file.
	 * ---
	 * default: md
	 * options:
	 *   - md
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 * ---
	 *
	 * [--max-response-chars=<n>]
	 * : Truncate responses in the report. 0 keeps them whole.
	 * ---
	 * default: 2000
	 * ---
	 *
	 * [--allow-skips]
	 * : Exit 0 even when prompts were skipped for a missing capability.
	 *
	 * [--yes]
	 * : Skip the "this spends real money" confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     # Validate the catalog without spending anything.
	 *     $ wp agent prompt-test --dry-run
	 *
	 *     # Run one agent's prompts.
	 *     $ wp agent prompt-test --user=admin --agent=seo-optimizer
	 *
	 *     # Run the ten most common complaints, capped at 50 cents.
	 *     $ wp agent prompt-test --user=admin --rank=1-10 --max-cost=0.50
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Named args.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$catalog_path = $this->resolve_catalog_path( $args, $assoc_args );
		$dry_run      = ! empty( $assoc_args['dry-run'] );

		if ( ! $this->has_selection( $assoc_args ) ) {
			$this->print_usage( $catalog_path );
			\WP_CLI::halt( 0 );
		}

		// Parse and validate before anything expensive happens: a typo in the
		// catalog should cost a second, not forty prompts' worth of tokens.
		try {
			$catalog = Prompt_Catalog::load( $catalog_path );
		} catch ( \RuntimeException $e ) {
			\WP_CLI::error( $this->catalog_help( $catalog_path, $e->getMessage() ), self::EXIT_ABORTED );
			return;
		}

		foreach ( $catalog['warnings'] as $warning ) {
			\WP_CLI::warning( $warning );
		}

		$registry    = \Agentic_Agent_Registry::get_instance();
		$agent_slugs = array_keys( (array) $registry->get_installed_agents() );
		$problems    = Prompt_Catalog::validate( $catalog['rows'], $agent_slugs, $this->tool_names() );

		if ( ! empty( $problems ) ) {
			foreach ( $problems as $problem ) {
				\WP_CLI::warning( $problem );
			}
			\WP_CLI::error( 'The catalog references agents or tools that do not exist. Fix it before running.', self::EXIT_ABORTED );
			return;
		}

		$unknown = array_diff( $this->requested_agents( $assoc_args ), $agent_slugs );

		if ( ! empty( $unknown ) ) {
			\WP_CLI::error(
				sprintf(
					"Unknown agent %s.\nInstalled agents: %s",
					implode( ', ', $unknown ),
					implode( ', ', $agent_slugs )
				),
				self::EXIT_ABORTED
			);
			return;
		}

		$rows = Prompt_Catalog::filter( $catalog['rows'], $this->build_criteria( $assoc_args ) );

		if ( empty( $rows ) ) {
			\WP_CLI::error( 'No prompts matched that selection.', self::EXIT_ABORTED );
			return;
		}

		$options = $this->build_options( $assoc_args );
		$verdict = Verdict_Factory::make( (string) ( $assoc_args['verdict'] ?? 'assert' ) );
		$runner  = new Prompt_Test_Runner( $verdict, $options );

		$preflight = $runner->preflight();

		if ( $dry_run ) {
			$this->print_dry_run( $rows, $preflight, $catalog_path );
			return;
		}

		if ( ! $preflight['ok'] ) {
			foreach ( $preflight['errors'] as $error ) {
				\WP_CLI::warning( $error );
			}
			\WP_CLI::error( 'Cannot run prompts on this site yet.', self::EXIT_ABORTED );
			return;
		}

		$user_id = get_current_user_id();

		if ( 0 === $user_id && 'autonomous' !== $options['mode'] ) {
			\WP_CLI::error(
				"No user context. Re-run with --user=<admin-login>.\n"
				. 'Agents check capabilities via Agent_Base::current_user_can_access(), so running as nobody '
				. 'would skip every prompt.',
				self::EXIT_ABORTED
			);
			return;
		}

		$this->confirm_cost( count( $rows ), $assoc_args );

		$format      = (string) ( $assoc_args['format'] ?? 'md' );
		$output_path = $this->resolve_output_path( $assoc_args );
		$user        = get_userdata( $user_id );

		$reporter = new Prompt_Test_Reporter(
			array_merge(
				$preflight['context'],
				array(
					'user'    => $user ? sprintf( '%s (ID %d)', $user->user_login, $user_id ) : 'none',
					'mode'    => $options['mode'],
					'verdict' => $verdict->get_name(),
					'judge'   => $options['judge'],
					'catalog' => $this->relative_path( $catalog_path ),
					'command' => $this->reconstruct_command( $assoc_args ),
				)
			),
			array( 'max_response_chars' => (int) $options['max_response_chars'] )
		);

		$progress = \WP_CLI\Utils\make_progress_bar( sprintf( 'Running %d prompts', count( $rows ) ), count( $rows ) );
		$write    = 'md' === $format && '-' !== $output_path;

		// Rewrite the report after every prompt. A fatal or a ctrl-C halfway
		// through a paid run should still leave a readable report of what did run.
		$records = $runner->run(
			$rows,
			function ( array $record, array $records ) use ( $reporter, $runner, $output_path, $write, $progress ): void {
				if ( $write ) {
					$reporter->flush( $records, $runner->get_totals(), $output_path );
				}
				$progress->tick();
				$this->log_record( $record );
			}
		);

		$progress->finish();

		$totals = $runner->get_totals();

		if ( 'md' === $format ) {
			if ( $write ) {
				$reporter->flush( $records, $totals, $output_path );
				\WP_CLI::log( sprintf( 'Report written to %s', $this->relative_path( $output_path ) ) );
			} else {
				\WP_CLI::print_value( $reporter->render( $records, $totals ) );
			}
		} else {
			\WP_CLI\Utils\format_items(
				$format,
				$reporter->to_rows( $records ),
				array( 'id', 'rank', 'agent', 'verdict', 'tools', 'tokens', 'cost', 'ms', 'judge', 'reasons' )
			);
		}

		$this->print_totals( $totals );

		$exit = $this->exit_code_for( $totals, $assoc_args );

		if ( 0 !== $exit ) {
			\WP_CLI::halt( $exit );
		}

		\WP_CLI::success( 'All selected prompts passed.' );
	}

	/**
	 * Whether the caller asked for anything to run.
	 *
	 * @param array $assoc_args Named args.
	 * @return bool
	 */
	private function has_selection( array $assoc_args ): bool {
		foreach ( array( 'all', 'agent', 'rank', 'id', 'limit' ) as $flag ) {
			if ( isset( $assoc_args[ $flag ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Print the selectors and exit, rather than defaulting to a paid full run.
	 *
	 * @param string $catalog_path Catalog path.
	 */
	private function print_usage( string $catalog_path ): void {
		$count = '';

		try {
			$catalog = Prompt_Catalog::load( $catalog_path );
			$count   = sprintf( ' (%d available)', count( $catalog['rows'] ) );
		} catch ( \RuntimeException $e ) {
			unset( $e );
		}

		\WP_CLI::log( 'Nothing selected, so nothing was run — these prompts make real, paid LLM calls.' );
		\WP_CLI::log( '' );
		\WP_CLI::log( 'Choose what to run:' );
		\WP_CLI::log( sprintf( '  --all              every prompt in the catalog%s', $count ) );
		\WP_CLI::log( '  --agent=<slug>     one agent\'s prompts' );
		\WP_CLI::log( '  --rank=1-10        the ten most common complaints' );
		\WP_CLI::log( '  --id=P01,P07       specific prompts' );
		\WP_CLI::log( '  --limit=<n>        cap after the other filters' );
		\WP_CLI::log( '' );
		\WP_CLI::log( 'Add --dry-run to resolve and validate without spending anything.' );
		\WP_CLI::log( 'Full docs: tests/PROMPT-TESTING.md' );
	}

	/**
	 * Resolve the catalog path.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Named args.
	 * @return string
	 */
	private function resolve_catalog_path( array $args, array $assoc_args ): string {
		$path = (string) ( $assoc_args['prompts'] ?? $args[0] ?? '' );

		if ( '' !== $path ) {
			return $path;
		}

		return Prompt_Catalog::resolve_path();
	}

	/**
	 * Explain a missing catalog rather than just failing to open a file.
	 *
	 * tests/ is stripped from the WordPress.org zip, so on a zip install the
	 * default catalog genuinely is not there — that is expected, not a bug.
	 *
	 * @param string $path    Attempted path.
	 * @param string $message Underlying error.
	 * @return string
	 */
	private function catalog_help( string $path, string $message ): string {
		return $message . "\n"
			. sprintf( "Expected at: %s\n", $path )
			. sprintf( "The catalog normally ships with the plugin at %s.\n", 'library/prompt-tests/most_popular_prompts.md' )
			. 'Pass --prompts=<path> to use a catalog of your own.';
	}

	/**
	 * Where to write the report.
	 *
	 * @param array $assoc_args Named args.
	 * @return string
	 */
	private function resolve_output_path( array $assoc_args ): string {
		$path = (string) ( $assoc_args['output'] ?? '' );

		if ( '' !== $path ) {
			return $path;
		}

		// Beside the site's own catalog, not inside the plugin: on a
		// WordPress.org install the plugin directory is not writable.
		return Prompt_Catalog::default_report_path();
	}

	/**
	 * Agent slugs the caller asked for.
	 *
	 * @param array $assoc_args Named args.
	 * @return string[]
	 */
	private function requested_agents( array $assoc_args ): array {
		$raw = trim( (string) ( $assoc_args['agent'] ?? '' ) );

		if ( '' === $raw ) {
			return array();
		}

		return array_values(
			array_filter( array_map( 'trim', explode( ',', strtolower( $raw ) ) ) )
		);
	}

	/**
	 * Selection criteria from the flags.
	 *
	 * @param array $assoc_args Named args.
	 * @return array<string, mixed>
	 */
	private function build_criteria( array $assoc_args ): array {
		return array(
			'agent'   => (string) ( $assoc_args['agent'] ?? '' ),
			'rank'    => (string) ( $assoc_args['rank'] ?? '' ),
			'id'      => (string) ( $assoc_args['id'] ?? '' ),
			'limit'   => (int) ( $assoc_args['limit'] ?? 0 ),
			'shuffle' => ! empty( $assoc_args['shuffle'] ),
		);
	}

	/**
	 * Runner options from the flags.
	 *
	 * @param array $assoc_args Named args.
	 * @return array<string, mixed>
	 */
	private function build_options( array $assoc_args ): array {
		return array(
			'mode'               => (string) ( $assoc_args['mode'] ?? 'chat' ),
			'max_cost'           => (float) ( $assoc_args['max-cost'] ?? 2.0 ),
			'max_iterations'     => (int) ( $assoc_args['max-iterations'] ?? 10 ),
			'timeout'            => (int) ( $assoc_args['timeout'] ?? 0 ),
			'delay'              => (int) ( $assoc_args['delay'] ?? 0 ),
			'judge'              => ! empty( $assoc_args['judge'] ),
			'allow_skips'        => ! empty( $assoc_args['allow-skips'] ),
			'max_response_chars' => (int) ( $assoc_args['max-response-chars'] ?? 2000 ),
		);
	}

	/**
	 * Every registered tool directory name, for catalog validation.
	 *
	 * @return string[]
	 */
	private function tool_names(): array {
		$dirs = glob( AGENT_BUILDER_DIR . 'library/tools/*', GLOB_ONLYDIR );

		return array_map( 'basename', is_array( $dirs ) ? $dirs : array() );
	}

	/**
	 * Confirm before spending money.
	 *
	 * @param int   $count      Prompts selected.
	 * @param array $assoc_args Named args.
	 */
	private function confirm_cost( int $count, array $assoc_args ): void {
		if ( ! empty( $assoc_args['yes'] ) ) {
			return;
		}

		\WP_CLI::confirm(
			sprintf(
				'This will make %d real LLM call%s against your configured provider, costing real money. '
				. 'Prompts that write will really write. Continue?',
				$count,
				1 === $count ? '' : 's'
			),
			$assoc_args
		);
	}

	/**
	 * Print the resolved plan for a dry run.
	 *
	 * @param array<int, array<string, mixed>> $rows         Selected rows.
	 * @param array<string, mixed>             $preflight    Preflight result.
	 * @param string                           $catalog_path Catalog path.
	 */
	private function print_dry_run( array $rows, array $preflight, string $catalog_path ): void {
		\WP_CLI::log( sprintf( 'Catalog: %s', $this->relative_path( $catalog_path ) ) );
		\WP_CLI::log( sprintf( 'Selected: %d prompt(s)', count( $rows ) ) );
		\WP_CLI::log( '' );

		\WP_CLI\Utils\format_items(
			'table',
			array_map(
				static fn( array $row ): array => array(
					'id'       => $row['id'],
					'rank'     => $row['rank'],
					'agent'    => $row['agent'],
					'coverage' => $row['coverage'],
					'expect'   => implode( ' ', (array) $row['expect_tools'] ),
					'prompt'   => mb_substr( (string) $row['prompt'], 0, 60 ),
				),
				$rows
			),
			array( 'id', 'rank', 'agent', 'coverage', 'expect', 'prompt' )
		);

		\WP_CLI::log( '' );

		foreach ( Prompt_Catalog::count_by_agent( $rows ) as $slug => $count ) {
			\WP_CLI::log( sprintf( '  %-24s %d', $slug, $count ) );
		}

		\WP_CLI::log( '' );

		foreach ( $preflight['errors'] as $error ) {
			\WP_CLI::warning( $error );
		}

		\WP_CLI::log(
			sprintf(
				'Provider: %s / %s · agent mode: %s',
				$preflight['context']['provider'],
				$preflight['context']['model'],
				$preflight['context']['agent_mode']
			)
		);

		if ( 'supervised' === $preflight['context']['agent_mode'] ) {
			\WP_CLI::log( 'Note: in supervised mode medium- and high-risk tools stop for approval. That is recorded as a gated pass, not a failure.' );
		}

		\WP_CLI::success( 'Dry run complete — no LLM calls were made.' );
	}

	/**
	 * One console line per finished prompt.
	 *
	 * @param array<string, mixed> $record Result record.
	 */
	private function log_record( array $record ): void {
		$line = sprintf(
			'%s %s (%s) — %s',
			$record['verdict'],
			$record['id'],
			$record['assigned_agent'],
			empty( $record['verdict_reasons'] ) ? 'ok' : implode( '; ', (array) $record['verdict_reasons'] )
		);

		if ( in_array( $record['verdict'], array( Prompt_Test_Runner::VERDICT_FAIL, Prompt_Test_Runner::VERDICT_ERROR ), true ) ) {
			\WP_CLI::warning( $line );
			return;
		}

		\WP_CLI::debug( $line, 'prompt-test' );
	}

	/**
	 * Print the run totals.
	 *
	 * @param array<string, float|int> $totals Totals.
	 */
	private function print_totals( array $totals ): void {
		\WP_CLI::log( '' );
		\WP_CLI::log(
			sprintf(
				'%d run · %d passed · %d failed · %d skipped · %d errored · %d gated',
				(int) $totals['run'],
				(int) $totals['pass'],
				(int) $totals['fail'],
				(int) $totals['skip'],
				(int) $totals['error'],
				(int) $totals['gated']
			)
		);
		\WP_CLI::log(
			sprintf(
				'%s tokens · $%s · %.1fs',
				number_format( (int) $totals['tokens'] ),
				number_format( (float) $totals['cost'], 4 ),
				( (float) $totals['duration_ms'] ) / 1000
			)
		);
	}

	/**
	 * Decide the process exit code.
	 *
	 * @param array<string, float|int> $totals     Totals.
	 * @param array                    $assoc_args Named args.
	 * @return int
	 */
	private function exit_code_for( array $totals, array $assoc_args ): int {
		if ( (int) $totals['not_run'] > 0 ) {
			return self::EXIT_ABORTED;
		}

		if ( (int) $totals['fail'] > 0 || (int) $totals['error'] > 0 ) {
			return 1;
		}

		if ( (int) $totals['skip'] > 0 && empty( $assoc_args['allow-skips'] ) ) {
			return 1;
		}

		return 0;
	}

	/**
	 * Rebuild the invocation for the report header.
	 *
	 * @param array $assoc_args Named args.
	 * @return string
	 */
	private function reconstruct_command( array $assoc_args ): string {
		$parts = array( 'wp agent prompt-test' );

		foreach ( $assoc_args as $key => $value ) {
			$parts[] = true === $value ? sprintf( '--%s', $key ) : sprintf( '--%s=%s', $key, $value );
		}

		return implode( ' ', $parts );
	}

	/**
	 * Shorten a path for display.
	 *
	 * @param string $path Absolute path.
	 * @return string
	 */
	private function relative_path( string $path ): string {
		if ( defined( 'AGENT_BUILDER_DIR' ) && str_starts_with( $path, AGENT_BUILDER_DIR ) ) {
			return substr( $path, strlen( AGENT_BUILDER_DIR ) );
		}

		return $path;
	}
}
