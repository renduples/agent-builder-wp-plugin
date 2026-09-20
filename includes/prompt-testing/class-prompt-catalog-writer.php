<?php
/**
 * Surgical editor for the prompt catalog.
 *
 * The catalog is half data and half hand-written research prose, so it is never
 * regenerated. Every edit is a line-level operation against the exact source
 * line the parser recorded for a row, which means the ranking rationale, the
 * coverage-gap analysis and the column legend all survive an agent editing a
 * row in the middle of the file.
 *
 * Writes are copy-on-write: the first edit copies the shipped catalog into
 * wp-content/agentic-knowledge/ and every edit after that lands there. The
 * shipped copy inside the plugin is never modified, so a plugin update cannot
 * clobber an owner's additions — and nothing here needs permission to write
 * inside the plugin directory.
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
 * Adds, updates and removes catalog rows in place.
 */
final class Prompt_Catalog_Writer {

	/**
	 * Fields a caller may set on a row.
	 *
	 * @var string[]
	 */
	public const EDITABLE = array( 'rank', 'agent', 'prompt', 'expecttools', 'coverage', 'notes', 'source' );

	/**
	 * Update one row.
	 *
	 * @param string               $path   Catalog path.
	 * @param string               $id     Row ID.
	 * @param array<string, mixed> $fields Fields to set.
	 * @return array{success: bool, message: string, row?: array<string, mixed>}
	 */
	public static function update_row( string $path, string $id, array $fields ): array {
		$path  = self::writable_copy( $path );
		$found = self::find_row( $path, $id );

		if ( null === $found ) {
			return self::fail( sprintf( 'No prompt with ID "%s" in the catalog.', $id ) );
		}

		$merged = self::merge( $found['row'], $fields );
		$lines  = self::read_lines( $path );

		$lines[ $found['row']['source_line'] - 1 ] = self::render_row( $merged, (array) $found['row']['columns'] );

		if ( ! self::write_lines( $path, $lines ) ) {
			return self::fail( 'Could not write the catalog file.' );
		}

		return array(
			'success' => true,
			'message' => sprintf( 'Updated %s.', $id ),
			'row'     => $merged,
		);
	}

	/**
	 * Remove one row.
	 *
	 * @param string $path Catalog path.
	 * @param string $id   Row ID.
	 * @return array{success: bool, message: string}
	 */
	public static function remove_row( string $path, string $id ): array {
		$path  = self::writable_copy( $path );
		$found = self::find_row( $path, $id );

		if ( null === $found ) {
			return self::fail( sprintf( 'No prompt with ID "%s" in the catalog.', $id ) );
		}

		$lines = self::read_lines( $path );
		array_splice( $lines, $found['row']['source_line'] - 1, 1 );

		if ( ! self::write_lines( $path, $lines ) ) {
			return self::fail( 'Could not write the catalog file.' );
		}

		return array(
			'success' => true,
			'message' => sprintf( 'Removed %s.', $id ),
		);
	}

	/**
	 * Add a row, into the table of the agent it is assigned to.
	 *
	 * Appending to the right agent's section keeps the file organised the way a
	 * human reads it. If that agent has no section yet the row cannot be placed
	 * automatically — say so rather than inventing a section in the wrong place.
	 *
	 * @param string               $path   Catalog path.
	 * @param array<string, mixed> $fields Row fields; `agent` and `prompt` required.
	 * @return array{success: bool, message: string, row?: array<string, mixed>}
	 */
	public static function add_row( string $path, array $fields ): array {
		$agent  = strtolower( trim( (string) ( $fields['agent'] ?? '' ) ) );
		$prompt = trim( (string) ( $fields['prompt'] ?? '' ) );

		if ( '' === $agent || '' === $prompt ) {
			return self::fail( 'Both agent and prompt are required to add a row.' );
		}

		$path    = self::writable_copy( $path );
		$catalog = Prompt_Catalog::load( $path );
		$rows    = $catalog['rows'];

		$same_agent = array_values( array_filter( $rows, static fn( array $r ): bool => $r['agent'] === $agent ) );

		if ( empty( $same_agent ) ) {
			return self::fail(
				sprintf(
					'The catalog has no table for "%s" yet. Add a "## <Name> (%s)" section with a header row first, then add prompts to it.',
					$agent,
					$agent
				)
			);
		}

		$anchor       = $same_agent[ count( $same_agent ) - 1 ];
		$fields['id'] = self::next_id( $rows );

		$row   = self::merge( self::blank_row(), $fields );
		$lines = self::read_lines( $path );

		array_splice( $lines, $anchor['source_line'], 0, array( self::render_row( $row, (array) $anchor['columns'] ) ) );

		if ( ! self::write_lines( $path, $lines ) ) {
			return self::fail( 'Could not write the catalog file.' );
		}

		return array(
			'success' => true,
			'message' => sprintf( 'Added %s to the %s table.', $row['id'], $agent ),
			'row'     => $row,
		);
	}

	/**
	 * Resolve the path an edit should actually be written to.
	 *
	 * Anything already outside the plugin directory is edited in place — that
	 * covers the site's own copy and a --prompts path a developer chose. A path
	 * inside the plugin is the shipped, read-only catalog, so it is copied to
	 * the site-local location first and the copy is edited instead.
	 *
	 * @param string $path Requested path.
	 * @return string Path to edit.
	 */
	private static function writable_copy( string $path ): string {
		$plugin_dir  = defined( 'AGENT_BUILDER_DIR' ) ? trailingslashit( AGENT_BUILDER_DIR ) : '';
		$real        = realpath( $path );
		$real        = false === $real ? $path : $real;
		$plugin_real = '';
		if ( '' !== $plugin_dir ) {
			$resolved    = realpath( $plugin_dir );
			$plugin_real = trailingslashit( false === $resolved ? $plugin_dir : $resolved );
		}

		$inside_plugin = '' !== $plugin_real && str_starts_with( $real, $plugin_real );

		if ( ! $inside_plugin ) {
			return $path;
		}

		$local = Prompt_Catalog::site_local_path();

		if ( is_readable( $local ) ) {
			return $local;
		}

		\Agentic\File_Manager::ensure_protected_dir( dirname( $local ) );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local catalog file read as data.
		$contents = (string) file_get_contents( $path );

		if ( \Agentic\File_Manager::put_contents( $local, $contents ) ) {
			return $local;
		}

		// The copy failed — fall back to the original so the caller gets a
		// clear write error rather than silently editing the wrong file.
		return $path;
	}

	/**
	 * Locate a row by ID.
	 *
	 * @param string $path Catalog path.
	 * @param string $id   Row ID.
	 * @return array{row: array<string, mixed>}|null
	 */
	private static function find_row( string $path, string $id ): ?array {
		$catalog = Prompt_Catalog::load( $path );
		$id      = strtoupper( trim( $id ) );

		foreach ( $catalog['rows'] as $row ) {
			if ( strtoupper( (string) $row['id'] ) === $id ) {
				return array( 'row' => $row );
			}
		}

		return null;
	}

	/**
	 * Next free sequential ID.
	 *
	 * @param array<int, array<string, mixed>> $rows Existing rows.
	 * @return string
	 */
	private static function next_id( array $rows ): string {
		$highest = 0;

		foreach ( $rows as $row ) {
			$digits  = (int) preg_replace( '/\D/', '', (string) $row['id'] );
			$highest = max( $highest, $digits );
		}

		return sprintf( 'P%02d', $highest + 1 );
	}

	/**
	 * A row with every field empty.
	 *
	 * @return array<string, mixed>
	 */
	private static function blank_row(): array {
		return array(
			'id'           => '',
			'rank'         => 0,
			'agent'        => '',
			'prompt'       => '',
			'expect_tools' => array(),
			'coverage'     => '',
			'notes'        => '',
			'source'       => '',
		);
	}

	/**
	 * Apply caller-supplied fields over an existing row.
	 *
	 * @param array<string, mixed> $row    Existing row.
	 * @param array<string, mixed> $fields Fields to set.
	 * @return array<string, mixed>
	 */
	private static function merge( array $row, array $fields ): array {
		foreach ( $fields as $key => $value ) {
			$key = str_replace( '_', '', strtolower( (string) $key ) );

			switch ( $key ) {
				case 'id':
					$row['id'] = strtoupper( trim( (string) $value ) );
					break;
				case 'rank':
					$row['rank'] = (int) $value;
					break;
				case 'agent':
					$row['agent'] = strtolower( trim( (string) $value ) );
					break;
				case 'prompt':
					$row['prompt'] = trim( (string) $value );
					break;
				case 'expecttools':
					$row['expect_tools'] = is_array( $value )
						? array_values( array_filter( array_map( 'trim', $value ) ) )
						: array_values( array_filter( array_map( 'trim', explode( ',', (string) $value ) ) ) );
					break;
				case 'coverage':
					$row['coverage'] = strtolower( trim( (string) $value ) );
					break;
				case 'notes':
					$row['notes'] = trim( (string) $value );
					break;
				case 'source':
					$row['source'] = trim( (string) $value );
					break;
			}
		}

		return $row;
	}

	/**
	 * Render a row as a markdown table line, in the target table's column order.
	 *
	 * @param array<string, mixed> $row     Row data.
	 * @param string[]             $columns Normalised column names, in order.
	 * @return string
	 */
	private static function render_row( array $row, array $columns ): string {
		$cells = array();

		foreach ( $columns as $column ) {
			$cells[] = self::escape(
				match ( $column ) {
					'id'          => (string) $row['id'],
					'rank'        => $row['rank'] > 0 ? (string) $row['rank'] : '',
					'agent'       => (string) $row['agent'],
					'prompt'      => (string) $row['prompt'],
					'expecttools' => implode( ', ', (array) $row['expect_tools'] ),
					'coverage'    => (string) $row['coverage'],
					'notes'       => (string) $row['notes'],
					'source'      => (string) $row['source'],
					default       => '',
				}
			);
		}

		return '| ' . implode( ' | ', $cells ) . ' |';
	}

	/**
	 * Make a value safe inside a table cell.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private static function escape( string $value ): string {
		$value = str_replace( '|', '\\|', $value );
		$value = (string) preg_replace( '/\R/', '<br>', $value );

		return trim( $value );
	}

	/**
	 * Read the catalog as an array of lines.
	 *
	 * @param string $path Catalog path.
	 * @return string[]
	 */
	private static function read_lines( string $path ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local catalog file read as data.
		$contents = (string) file_get_contents( $path );
		$lines    = preg_split( '/\R/', str_replace( "\r\n", "\n", $contents ) );

		return is_array( $lines ) ? $lines : array();
	}

	/**
	 * Write the catalog back.
	 *
	 * Goes through File_Manager so the write is subject to the same allowed-root
	 * and extension policy as every other file this plugin touches — the catalog
	 * path is a single named exception there, not a new open door.
	 *
	 * @param string   $path  Catalog path.
	 * @param string[] $lines Lines to write.
	 * @return bool
	 */
	private static function write_lines( string $path, array $lines ): bool {
		return \Agentic\File_Manager::put_contents( $path, implode( "\n", $lines ) );
	}

	/**
	 * Build a failure result.
	 *
	 * @param string $message Message.
	 * @return array{success: bool, message: string}
	 */
	private static function fail( string $message ): array {
		return array(
			'success' => false,
			'message' => $message,
		);
	}
}
