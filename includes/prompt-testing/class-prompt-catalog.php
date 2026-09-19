<?php
/**
 * Prompt catalog parser for the prompt-testing harness.
 *
 * Reads tests/most_popular_prompts.md — a hand-edited markdown document whose
 * tables are the single source of truth for which prompts run against which
 * bundled agent. The markdown is authoritative on purpose: a sidecar JSON file
 * would drift the first time someone edited only the table.
 *
 * Lives outside includes/cli/ because the WP-CLI command is not its only
 * consumer: the self-improvement tools call the same parser to read and rewrite
 * the catalog from inside a chat turn. Nothing here touches the WP_CLI API.
 *
 * @package    Agent_Builder
 * @subpackage Prompt_Testing
 * @since      3.4.1
 *
 * php version 8.1
 */

declare( strict_types=1 );

namespace Agentic\Prompt_Testing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Parses and filters the prompt catalog.
 */
final class Prompt_Catalog {

	/**
	 * Normalised column names a table must have to be treated as a prompt table.
	 *
	 * Anything else in the document — the ranked index, a legend, a coverage
	 * summary — simply lacks these and is skipped, which is how decorative
	 * tables stay decorative without needing a marker.
	 *
	 * @var string[]
	 */
	public const REQUIRED_COLUMNS = array( 'prompt', 'agent' );

	/**
	 * Load and parse a catalog file.
	 *
	 * @param string $path Absolute path to the markdown catalog.
	 * @return array{rows: array<int, array<string, mixed>>, warnings: string[]}
	 * @throws \RuntimeException If the file is missing or unreadable.
	 */
	public static function load( string $path ): array {
		if ( ! is_readable( $path ) ) {
			throw new \RuntimeException(
				sprintf( 'Prompt catalog not found or unreadable: %s', esc_html( $path ) )
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local catalog file read as data, not a remote request.
		$markdown = file_get_contents( $path );

		if ( false === $markdown ) {
			throw new \RuntimeException( sprintf( 'Could not read prompt catalog: %s', esc_html( $path ) ) );
		}

		return self::parse( $markdown );
	}

	/**
	 * Parse catalog markdown into prompt rows.
	 *
	 * A line-based state machine rather than a regex over the whole document,
	 * so that a table shown as an example inside a fenced code block is not
	 * mistaken for real data.
	 *
	 * @param string $markdown Catalog markdown.
	 * @return array{rows: array<int, array<string, mixed>>, warnings: string[]}
	 */
	public static function parse( string $markdown ): array {
		$lines    = preg_split( '/\R/', str_replace( "\r\n", "\n", $markdown ) );
		$lines    = is_array( $lines ) ? $lines : array();
		$rows     = array();
		$warnings = array();
		$seen_ids = array();

		$fence       = '';
		$column_map  = null;
		$total       = count( $lines );
		$line_number = 0;

		while ( $line_number < $total ) {
			$line    = $lines[ $line_number ];
			$trimmed = trim( $line );
			++$line_number;

			// Fenced code blocks are content, never data.
			$fence_match = array();
			if ( preg_match( '/^(`{3,}|~{3,})/', $trimmed, $fence_match ) ) {
				if ( '' === $fence ) {
					$fence = $fence_match[1][0];
					continue;
				}
				if ( str_starts_with( $fence_match[1], $fence ) ) {
					$fence = '';
				}
				continue;
			}
			if ( '' !== $fence ) {
				continue;
			}

			if ( ! self::is_table_line( $trimmed ) ) {
				$column_map = null;
				continue;
			}

			// A header is only a header if the next line is the delimiter row.
			if ( null === $column_map ) {
				$next = $lines[ $line_number ] ?? '';
				if ( ! self::is_delimiter_row( trim( $next ) ) ) {
					continue;
				}

				$header_cells = self::split_row( $trimmed );
				$map          = array();
				foreach ( $header_cells as $index => $cell ) {
					$key = self::normalise_header( $cell );
					if ( '' !== $key && ! isset( $map[ $key ] ) ) {
						$map[ $key ] = $index;
					}
				}

				foreach ( self::REQUIRED_COLUMNS as $required ) {
					if ( ! isset( $map[ $required ] ) ) {
						$map = null;
						break;
					}
				}

				$column_map = $map;
				++$line_number; // Consume the delimiter row.
				continue;
			}

			// Inside a prompt table — this is a data row.
			$row = self::normalise_row( self::split_row( $trimmed ), $column_map, $line_number, $warnings );

			if ( null === $row ) {
				continue;
			}

			if ( isset( $seen_ids[ $row['id'] ] ) ) {
				$warnings[] = sprintf(
					'Line %d: duplicate ID "%s" (first seen on line %d) — keeping the first.',
					$line_number,
					$row['id'],
					$seen_ids[ $row['id'] ]
				);
				continue;
			}

			$seen_ids[ $row['id'] ] = $line_number;
			$rows[]                 = $row;
		}

		return array(
			'rows'     => $rows,
			'warnings' => $warnings,
		);
	}

	/**
	 * Whether a trimmed line looks like part of a markdown table.
	 *
	 * @param string $line Trimmed line.
	 * @return bool
	 */
	private static function is_table_line( string $line ): bool {
		return str_starts_with( $line, '|' ) && strlen( $line ) > 1;
	}

	/**
	 * Whether a trimmed line is a markdown table delimiter row (|---|:--:|).
	 *
	 * @param string $line Trimmed line.
	 * @return bool
	 */
	private static function is_delimiter_row( string $line ): bool {
		return 1 === preg_match( '/^\|[\s:\|-]+\|?$/', $line ) && str_contains( $line, '-' );
	}

	/**
	 * Split a table row into trimmed cells.
	 *
	 * Splits only on unescaped pipes, so a prompt containing a literal `\|`
	 * survives intact — prompts about shell commands and table syntax do come up.
	 *
	 * @param string $line Trimmed table line.
	 * @return string[]
	 */
	private static function split_row( string $line ): array {
		$line = trim( $line );
		$line = preg_replace( '/^\|/', '', $line );
		$line = preg_replace( '/\|$/', '', (string) $line );

		$parts = preg_split( '/(?<!\\\\)\|/', (string) $line );
		$parts = is_array( $parts ) ? $parts : array();

		return array_map(
			static function ( string $cell ): string {
				$cell = str_replace( array( '\\|', '\\\\' ), array( '|', '\\' ), $cell );
				return trim( $cell );
			},
			$parts
		);
	}

	/**
	 * Reduce a header cell to a stable lookup key.
	 *
	 * "Expect Tools", "expect_tools" and "Expect-Tools" all collapse to
	 * "expecttools", so the catalog can be reformatted without breaking the
	 * parser. Columns are looked up by name, never by position — which is also
	 * what lets new optional columns be added later without a parser change.
	 *
	 * @param string $cell Raw header cell.
	 * @return string
	 */
	private static function normalise_header( string $cell ): string {
		$cell = strtolower( wp_strip_all_tags( $cell ) );
		return (string) preg_replace( '/[^a-z0-9]/', '', $cell );
	}

	/**
	 * Turn a split data row into a validated prompt record.
	 *
	 * Every rejection is a warning plus a skip, never an exception — one broken
	 * row in a 50-row catalog should not stop the other 49 from running.
	 *
	 * @param string[]             $cells       Split cells.
	 * @param array<string, int>   $map         Column name to index map.
	 * @param int                  $line_number 1-based source line.
	 * @param string[]             $warnings    Warning accumulator, by reference.
	 * @return array<string, mixed>|null
	 */
	private static function normalise_row( array $cells, array $map, int $line_number, array &$warnings ): ?array {
		$get = static function ( string $column ) use ( $cells, $map ): string {
			$index = $map[ $column ] ?? null;
			if ( null === $index ) {
				return '';
			}
			return $cells[ $index ] ?? '';
		};

		$prompt = self::clean_prompt( $get( 'prompt' ) );
		$agent  = strtolower( $get( 'agent' ) );
		$agent  = trim( $agent, '`' );

		if ( '' === $prompt ) {
			$warnings[] = sprintf( 'Line %d: row has no prompt — skipped.', $line_number );
			return null;
		}

		if ( '' === $agent ) {
			$warnings[] = sprintf( 'Line %d: row has no agent — skipped.', $line_number );
			return null;
		}

		$id = trim( $get( 'id' ), '`' );
		if ( '' === $id ) {
			$id         = sprintf( 'ROW%d', $line_number );
			$warnings[] = sprintf( 'Line %d: row has no ID — using "%s".', $line_number, $id );
		}

		$rank_raw = trim( $get( 'rank' ) );
		$rank     = is_numeric( $rank_raw ) ? (int) $rank_raw : 0;

		return array(
			'columns'      => array_keys( $map ),
			'id'           => $id,
			'rank'         => $rank,
			'agent'        => $agent,
			'prompt'       => $prompt,
			'expect_tools' => self::split_list( $get( 'expecttools' ) ),
			'coverage'     => strtolower( trim( $get( 'coverage' ) ) ),
			'notes'        => $get( 'notes' ),
			'source'       => $get( 'source' ),
			'source_line'  => $line_number,
		);
	}

	/**
	 * Normalise a prompt cell into the literal string sent to the agent.
	 *
	 * Strips the inline-code backticks authors habitually wrap prompts in, and
	 * turns the two ways a multi-line prompt can be written inside a table cell
	 * (`<br>` and a literal backslash-n) into real newlines.
	 *
	 * @param string $cell Raw cell.
	 * @return string
	 */
	private static function clean_prompt( string $cell ): string {
		$cell = trim( $cell );

		if ( strlen( $cell ) > 1 && str_starts_with( $cell, '`' ) && str_ends_with( $cell, '`' ) ) {
			$cell = trim( $cell, '`' );
		}

		$cell = (string) preg_replace( '#<br\s*/?>#i', "\n", $cell );
		$cell = str_replace( '\\n', "\n", $cell );

		return trim( $cell );
	}

	/**
	 * Split a comma-separated cell into a clean list.
	 *
	 * @param string $cell Raw cell.
	 * @return string[]
	 */
	private static function split_list( string $cell ): array {
		$cell = trim( $cell );

		if ( '' === $cell || '—' === $cell || '-' === $cell ) {
			return array();
		}

		$parts = array_map(
			static fn( string $part ): string => trim( trim( $part ), '`' ),
			explode( ',', $cell )
		);

		return array_values( array_filter( $parts, static fn( string $part ): bool => '' !== $part ) );
	}

	/**
	 * Check every row against the agents and tools that actually exist.
	 *
	 * Runs before the first LLM call so a typo costs a second, not forty
	 * prompts' worth of tokens.
	 *
	 * @param array<int, array<string, mixed>> $rows        Parsed rows.
	 * @param string[]                         $agent_slugs Installed agent slugs.
	 * @param string[]                         $tool_names  Registered tool names. Empty skips tool checks.
	 * @return string[] Human-readable problems; empty means the catalog is sound.
	 */
	public static function validate( array $rows, array $agent_slugs, array $tool_names = array() ): array {
		$problems = array();

		foreach ( $rows as $row ) {
			if ( ! in_array( $row['agent'], $agent_slugs, true ) ) {
				$problems[] = sprintf(
					'%s (line %d): unknown agent "%s".',
					$row['id'],
					$row['source_line'],
					$row['agent']
				);
			}

			if ( empty( $tool_names ) ) {
				continue;
			}

			foreach ( $row['expect_tools'] as $tool ) {
				if ( ! in_array( $tool, $tool_names, true ) ) {
					$problems[] = sprintf(
						'%s (line %d): expected tool "%s" is not a registered tool.',
						$row['id'],
						$row['source_line'],
						$tool
					);
				}
			}
		}

		return $problems;
	}

	/**
	 * Apply the command's selection flags to the parsed rows.
	 *
	 * @param array<int, array<string, mixed>> $rows     Parsed rows.
	 * @param array<string, mixed>             $criteria agent|rank|id|limit|shuffle.
	 * @return array<int, array<string, mixed>>
	 */
	public static function filter( array $rows, array $criteria ): array {
		$agents = self::split_list( (string) ( $criteria['agent'] ?? '' ) );
		if ( ! empty( $agents ) ) {
			$agents = array_map( 'strtolower', $agents );
			$rows   = array_values(
				array_filter( $rows, static fn( array $row ): bool => in_array( $row['agent'], $agents, true ) )
			);
		}

		$rank_spec = trim( (string) ( $criteria['rank'] ?? '' ) );
		if ( '' !== $rank_spec ) {
			$matches = self::parse_range_spec( $rank_spec );
			$rows    = array_values(
				array_filter( $rows, static fn( array $row ): bool => $matches( (int) $row['rank'] ) )
			);
		}

		$id_spec = trim( (string) ( $criteria['id'] ?? '' ) );
		if ( '' !== $id_spec ) {
			$wanted  = array_map( 'strtoupper', self::split_list( $id_spec ) );
			$numeric = self::parse_range_spec( self::ids_to_numeric_spec( $id_spec ) );
			$rows    = array_values(
				array_filter(
					$rows,
					static function ( array $row ) use ( $wanted, $numeric ): bool {
						if ( in_array( strtoupper( (string) $row['id'] ), $wanted, true ) ) {
							return true;
						}
						$digits = preg_replace( '/\D/', '', (string) $row['id'] );
						return '' !== (string) $digits && $numeric( (int) $digits );
					}
				)
			);
		}

		if ( ! empty( $criteria['shuffle'] ) ) {
			shuffle( $rows );
		} else {
			usort(
				$rows,
				static function ( array $a, array $b ): int {
					$rank_a = 0 === $a['rank'] ? PHP_INT_MAX : $a['rank'];
					$rank_b = 0 === $b['rank'] ? PHP_INT_MAX : $b['rank'];

					if ( $rank_a !== $rank_b ) {
						return $rank_a <=> $rank_b;
					}

					return strcmp( (string) $a['id'], (string) $b['id'] );
				}
			);
		}

		$limit = (int) ( $criteria['limit'] ?? 0 );
		if ( $limit > 0 ) {
			$rows = array_slice( $rows, 0, $limit );
		}

		return $rows;
	}

	/**
	 * Turn an ID spec ("P01,P05-P10") into the numeric spec parse_range_spec understands.
	 *
	 * @param string $spec Raw ID spec.
	 * @return string
	 */
	private static function ids_to_numeric_spec( string $spec ): string {
		$numeric = preg_replace( '/[A-Za-z]/', '', $spec );
		$parts   = array_filter(
			array_map( 'trim', explode( ',', (string) $numeric ) ),
			static fn( string $part ): bool => '' !== $part && str_contains( $part, '-' )
		);

		return implode( ',', $parts );
	}

	/**
	 * Compile a range spec into a matcher.
	 *
	 * Accepts "1-10", "1,3,7", "5-" (from 5 up), "-10" (up to 10) and any
	 * comma-separated mix of those.
	 *
	 * @param string $spec Range spec.
	 * @return callable(int): bool
	 */
	public static function parse_range_spec( string $spec ): callable {
		$spec = trim( $spec );

		if ( '' === $spec ) {
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Signature is fixed by the callable contract.
			return static fn( int $value ): bool => false;
		}

		$tests = array();

		foreach ( explode( ',', $spec ) as $part ) {
			$part = trim( $part );

			if ( '' === $part ) {
				continue;
			}

			if ( ! str_contains( $part, '-' ) ) {
				$exact   = (int) $part;
				$tests[] = static fn( int $value ): bool => $value === $exact;
				continue;
			}

			[ $from, $to ] = array_pad( explode( '-', $part, 2 ), 2, '' );

			$min     = '' === trim( $from ) ? PHP_INT_MIN : (int) $from;
			$max     = '' === trim( $to ) ? PHP_INT_MAX : (int) $to;
			$tests[] = static fn( int $value ): bool => $value >= $min && $value <= $max;
		}

		return static function ( int $value ) use ( $tests ): bool {
			foreach ( $tests as $test ) {
				if ( $test( $value ) ) {
					return true;
				}
			}
			return false;
		};
	}

	/**
	 * Group rows by agent slug, for the dry-run breakdown.
	 *
	 * @param array<int, array<string, mixed>> $rows Parsed rows.
	 * @return array<string, int>
	 */
	public static function count_by_agent( array $rows ): array {
		$counts = array();

		foreach ( $rows as $row ) {
			$slug            = (string) $row['agent'];
			$counts[ $slug ] = ( $counts[ $slug ] ?? 0 ) + 1;
		}

		ksort( $counts );

		return $counts;
	}
}
