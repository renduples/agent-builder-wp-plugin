<?php
/**
 * Result Card
 *
 * Normalizes raw tool results into a small, uniform card contract so the
 * renderer can surface what a run actually did without knowing each tool's
 * bespoke return shape. Most tools have no card — only post, file, and list
 * results are represented here.
 *
 * @package    Agent_Builder
 * @subpackage Includes
 * @since      4.3.0
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static contract mapping recognized tool results to normalized result cards.
 *
 * Every card carries `{type, tool, title}` plus type-specific fields:
 *   - post: post_id, action, status, edit_url, view_url
 *   - file: url
 *   - list: count, items (a few sample rows)
 *
 * Unrecognized tools, and tools whose result carries an error or no usable
 * payload, normalize to null — a run that only read data produces no cards.
 */
class Result_Card {

	/**
	 * Post tools mapped to their card action verb.
	 *
	 * @var array<string,string>
	 */
	private const POST_TOOLS = array(
		'db_create_post'      => 'created',
		'create_post_content' => 'created',
		'db_update_post'      => 'updated',
		'update_post_content' => 'updated',
	);

	/**
	 * File tools whose result carries a file_path + public url.
	 *
	 * @var array<int,string>
	 */
	private const FILE_TOOLS = array(
		'create_docx',
		'create_pdf',
		'create_spreadsheet',
	);

	/**
	 * List tools mapped to their result items key and card label.
	 *
	 * @var array<string,array{items:string,title:string}>
	 */
	private const LIST_TOOLS = array(
		'list_posts'    => array(
			'items' => 'posts',
			'title' => 'Posts',
		),
		'list_comments' => array(
			'items' => 'comments',
			'title' => 'Comments',
		),
	);

	/**
	 * How many list items to surface as samples in a list card.
	 *
	 * @var int
	 */
	private const LIST_SAMPLE_LIMIT = 5;

	/**
	 * Card types the client renderer understands. An externally supplied card
	 * must be one of these or it is dropped.
	 *
	 * @var array<int,string>
	 */
	private const CARD_TYPES = array( 'post', 'file', 'list' );

	/**
	 * Hard cap (in characters) for an external card's title and short string
	 * fields, so a connector can never surface an unbounded string.
	 *
	 * @var int
	 */
	private const STRING_MAX_LENGTH = 200;

	/**
	 * Hard cap on the number of sample items an external list card may surface.
	 *
	 * @var int
	 */
	private const LIST_MAX_ITEMS = 20;

	/**
	 * Normalize one tool result into a card, or null when the tool has no
	 * card representation (or its result carries only an error).
	 *
	 * Unrecognized tools get one last chance through the
	 * `agent_builder_result_card` filter, which connectors (Pro MCP tools, for
	 * example) hook to supply their own card. With nothing hooked, the default
	 * null passes through unchanged.
	 *
	 * @param string $tool   Tool name, as recorded in a tool_results entry.
	 * @param array  $result The tool's raw return value.
	 * @return array<string,mixed>|null
	 */
	public static function normalize( string $tool, array $result ): ?array {
		if ( ! empty( $result['error'] ) ) {
			return null;
		}

		if ( isset( self::POST_TOOLS[ $tool ] ) ) {
			return self::normalize_post( $tool, $result, self::POST_TOOLS[ $tool ] );
		}

		if ( in_array( $tool, self::FILE_TOOLS, true ) ) {
			return self::normalize_file( $tool, $result );
		}

		if ( isset( self::LIST_TOOLS[ $tool ] ) ) {
			return self::normalize_list( $tool, $result, self::LIST_TOOLS[ $tool ] );
		}

		/**
		 * Filter the result card for a tool this class does not recognize, so a
		 * connector can surface its own card. Return null to keep the tool
		 * card-less (the default).
		 *
		 * @param array|null $card   Card to show, or null.
		 * @param string     $tool   Tool name.
		 * @param array      $result Raw tool result.
		 */
		$card = apply_filters( 'agent_builder_result_card', null, $tool, $result );

		return self::sanitize_external_card( $card );
	}

	/**
	 * Sanitize a card supplied through the agent_builder_result_card filter.
	 *
	 * Accepts only an array whose `type` is one the renderer knows; keeps only
	 * the keys the built-in cards use; strips tags from every string and caps
	 * its length; runs every URL through safe_card_url(); and discards
	 * everything else. Returns null for anything empty or invalid, so hostile
	 * or malformed filter output can never surface an unsanitized card.
	 *
	 * @param mixed $card Raw filter return value.
	 * @return array<string,mixed>|null
	 */
	private static function sanitize_external_card( $card ): ?array {
		if ( ! is_array( $card ) ) {
			return null;
		}

		$type = isset( $card['type'] ) && is_string( $card['type'] ) ? $card['type'] : '';
		if ( ! in_array( $type, self::CARD_TYPES, true ) ) {
			return null;
		}

		$clean = array( 'type' => $type );

		$tool = self::clean_card_text( $card['tool'] ?? '' );
		if ( '' !== $tool ) {
			$clean['tool'] = $tool;
		}

		// Every card carries a title; without one there is nothing to show.
		$title = self::clean_card_text( $card['title'] ?? '' );
		if ( '' === $title ) {
			return null;
		}
		$clean['title'] = $title;

		if ( 'post' === $type ) {
			$post_id = (int) ( $card['post_id'] ?? 0 );
			if ( $post_id > 0 ) {
				$clean['post_id'] = $post_id;
			}

			$action = self::clean_card_text( $card['action'] ?? '' );
			if ( '' !== $action ) {
				$clean['action'] = $action;
			}

			$status = self::clean_card_text( $card['status'] ?? '' );
			if ( '' !== $status ) {
				$clean['status'] = $status;
			}

			$edit_url = self::clean_card_url( $card['edit_url'] ?? '' );
			if ( '' !== $edit_url ) {
				$clean['edit_url'] = $edit_url;
			}

			$view_url = self::clean_card_url( $card['view_url'] ?? '' );
			if ( '' !== $view_url ) {
				$clean['view_url'] = $view_url;
			}
		} elseif ( 'file' === $type ) {
			$url = self::clean_card_url( $card['url'] ?? '' );
			if ( '' !== $url ) {
				$clean['url'] = $url;
			}
		} elseif ( 'list' === $type ) {
			$clean['count'] = isset( $card['count'] ) ? max( 0, (int) $card['count'] ) : 0;

			$items   = isset( $card['items'] ) && is_array( $card['items'] ) ? $card['items'] : array();
			$samples = array();
			foreach ( array_slice( $items, 0, self::LIST_MAX_ITEMS ) as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}

				$item_title = self::clean_card_text( $item['title'] ?? '' );
				if ( '' === $item_title ) {
					continue;
				}

				$samples[] = array(
					'id'    => (int) ( $item['id'] ?? $item['ID'] ?? $item['post_id'] ?? 0 ),
					'title' => $item_title,
				);
			}
			if ( ! empty( $samples ) ) {
				$clean['items'] = $samples;
			}
		}

		return $clean;
	}

	/**
	 * Strip tags and cap a single card string field.
	 *
	 * @param mixed $value Raw value.
	 * @return string Stripped, trimmed, capped string ('' when absent/empty).
	 */
	private static function clean_card_text( $value ): string {
		$text = is_string( $value ) ? $value : ( is_scalar( $value ) ? (string) $value : '' );
		$text = trim( wp_strip_all_tags( $text ) );
		if ( mb_strlen( $text ) > self::STRING_MAX_LENGTH ) {
			$text = mb_substr( $text, 0, self::STRING_MAX_LENGTH );
		}

		return $text;
	}

	/**
	 * Validate a single card URL against the site allowlist.
	 *
	 * @param mixed $value Raw value.
	 * @return string The URL when safe, '' otherwise.
	 */
	private static function clean_card_url( $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}

		return self::safe_card_url( $value );
	}

	/**
	 * Collect cards from a run's raw tool_results entries.
	 *
	 * @param array<int,array{tool:string,result:array}> $tool_results Raw tool results.
	 * @return array<int,array<string,mixed>> Normalized cards, in tool order.
	 */
	public static function collect( array $tool_results ): array {
		$cards = array();

		foreach ( $tool_results as $tool_result ) {
			if ( ! is_array( $tool_result ) || empty( $tool_result['tool'] ) ) {
				continue;
			}

			$raw = $tool_result['result'] ?? array();
			if ( ! is_array( $raw ) ) {
				continue;
			}

			$card = self::normalize( (string) $tool_result['tool'], $raw );
			if ( null !== $card ) {
				$cards[] = $card;
			}
		}

		return $cards;
	}

	/**
	 * Build a short, human-readable summary of what a run did, from its cards.
	 *
	 * Synthesizes the actions rather than concatenating card titles: "Created a
	 * post \"Hello\" and listed 5 posts.", for example. Returns an empty string
	 * when the run produced no cards.
	 *
	 * @param array<int,array<string,mixed>> $cards Normalized cards.
	 * @return string
	 */
	public static function summarize( array $cards ): string {
		$clauses = array();

		foreach ( $cards as $card ) {
			$clause = self::clause( $card );
			if ( '' !== $clause ) {
				$clauses[] = $clause;
			}
		}

		if ( empty( $clauses ) ) {
			return '';
		}

		return self::join_clauses( $clauses ) . '.';
	}

	/**
	 * Normalize a post create/update result into a post card.
	 *
	 * @param string $tool   Tool name.
	 * @param array  $result Tool result.
	 * @param string $action 'created' or 'updated'.
	 * @return array<string,mixed>|null
	 */
	private static function normalize_post( string $tool, array $result, string $action ): ?array {
		$post_id = (int) ( $result['post_id'] ?? 0 );
		if ( $post_id < 1 ) {
			return null;
		}

		$raw_edit = isset( $result['edit_url'] ) ? (string) $result['edit_url'] : ( isset( $result['edit_link'] ) ? (string) $result['edit_link'] : '' );
		$edit_url = self::safe_card_url( $raw_edit );
		$view_url = self::safe_card_url( isset( $result['url'] ) ? (string) $result['url'] : '' );

		// Fall back to the canonical WordPress URLs when the tool's own URL was
		// absent or failed the allowlist (javascript:, data:, off-site, …).
		if ( '' === $edit_url ) {
			$link     = get_edit_post_link( $post_id, 'raw' );
			$edit_url = is_string( $link ) ? $link : '';
		}
		if ( '' === $view_url ) {
			$link     = get_permalink( $post_id );
			$view_url = is_string( $link ) ? $link : '';
		}

		// The edit link is only meaningful — and only surfaced — for someone who
		// can actually edit the post.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			$edit_url = '';
		}

		return array(
			'type'     => 'post',
			'tool'     => $tool,
			'title'    => (string) ( $result['title'] ?? '' ),
			'action'   => $action,
			'post_id'  => $post_id,
			'status'   => (string) ( $result['status'] ?? '' ),
			'edit_url' => $edit_url,
			'view_url' => $view_url,
		);
	}

	/**
	 * Normalize a file-generation result into a file card.
	 *
	 * @param string $tool   Tool name.
	 * @param array  $result Tool result.
	 * @return array<string,mixed>|null
	 */
	private static function normalize_file( string $tool, array $result ): ?array {
		$path = (string) ( $result['file_path'] ?? '' );
		if ( '' === $path ) {
			return null;
		}

		// The exporter always writes `<name>` directly under uploads/agentic-exports/,
		// so a valid relative path is exactly `agentic-exports/<basename>`. Anything
		// else — a subdirectory, an absolute path, or a `..` escape — is rejected.
		$name = basename( $path );
		if ( 'agentic-exports/' . $name !== $path ) {
			return null;
		}

		$uploads  = wp_upload_dir();
		$exports  = wp_normalize_path( trailingslashit( $uploads['basedir'] ) . 'agentic-exports' );
		$absolute = wp_normalize_path( $exports . '/' . $name );

		// Resolve symlinks and any residual `..` segments, then insist the real
		// file still lives inside the exports directory.
		$real = realpath( $absolute );
		if ( false === $real ) {
			return null;
		}

		$real_exports = realpath( $exports );
		if ( false === $real_exports || 0 !== strpos( wp_normalize_path( $real ), trailingslashit( wp_normalize_path( $real_exports ) ) ) ) {
			return null;
		}

		return array(
			'type'  => 'file',
			'tool'  => $tool,
			'title' => $name,
			'url'   => trailingslashit( $uploads['baseurl'] ) . 'agentic-exports/' . $name,
		);
	}

	/**
	 * Validate a tool-supplied card URL, so a stored result can never smuggle a
	 * javascript:/data:/off-site link into a card.
	 *
	 * Accepts root-relative paths and http(s) URLs whose host matches home_url();
	 * everything else maps to '' so the caller can fall back to a canonical
	 * WordPress URL.
	 *
	 * @param string $url Raw URL from a tool result.
	 * @return string The URL when safe, '' otherwise.
	 */
	private static function safe_card_url( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}

		// A root-relative path ("/wp-admin/…") is fine; a protocol-relative URL
		// ("//evil.com") is not — it falls through and the missing scheme rejects it.
		// Browsers turn "\" into "/" and strip tab/CR/LF, so "/\evil.com" or
		// "/\t/evil.com" would become the off-site "//evil.com". Reject any
		// backslash, control character or whitespace outright.
		if ( preg_match( '/[\\\\\x00-\x20\x7f]/', $url ) ) {
			return '';
		}
		if ( str_starts_with( $url, '/' ) && ! str_starts_with( $url, '//' ) ) {
			return $url;
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}

		$scheme = strtolower( (string) $parts['scheme'] );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return '';
		}

		$home = wp_parse_url( home_url() );
		if ( ! is_array( $home ) || empty( $home['host'] ) ) {
			return '';
		}

		if ( strtolower( (string) $parts['host'] ) !== strtolower( (string) $home['host'] ) ) {
			return '';
		}

		return $url;
	}

	/**
	 * Normalize a list result into a list card (count + a few sample items).
	 *
	 * @param string                          $tool   Tool name.
	 * @param array                           $result Tool result.
	 * @param array{items:string,title:string} $spec   Items key and card label.
	 * @return array<string,mixed>|null
	 */
	private static function normalize_list( string $tool, array $result, array $spec ): ?array {
		$items = (array) ( $result[ $spec['items'] ] ?? array() );
		$count = isset( $result['total_returned'] ) ? (int) $result['total_returned'] : ( isset( $result['total'] ) ? (int) $result['total'] : count( $items ) );

		$samples = array();
		foreach ( array_slice( $items, 0, self::LIST_SAMPLE_LIMIT ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$samples[] = array(
				'id'    => (int) ( $item['id'] ?? $item['ID'] ?? $item['post_id'] ?? 0 ),
				'title' => self::list_item_title( $tool, $item ),
			);
		}

		return array(
			'type'  => 'list',
			'tool'  => $tool,
			'title' => $spec['title'],
			'count' => $count,
			'items' => $samples,
		);
	}

	/**
	 * Pick the representative label for a single list item.
	 *
	 * @param string $tool Tool name.
	 * @param array  $item One list item.
	 * @return string
	 */
	private static function list_item_title( string $tool, array $item ): string {
		if ( 'list_comments' === $tool ) {
			return (string) ( $item['author'] ?? '' );
		}

		return (string) ( $item['title'] ?? '' );
	}

	/**
	 * Render one card as a single human-readable clause.
	 *
	 * @param array<string,mixed> $card Normalized card.
	 * @return string
	 */
	private static function clause( array $card ): string {
		switch ( $card['type'] ?? '' ) {
			case 'post':
				$title = '' !== (string) ( $card['title'] ?? '' ) ? ' "' . $card['title'] . '"' : '';
				return ( 'created' === ( $card['action'] ?? 'created' ) ? 'Created' : 'Updated' ) . ' a post' . $title;

			case 'file':
				$name = '' !== (string) ( $card['title'] ?? '' ) ? ' "' . $card['title'] . '"' : '';
				return 'Generated a file' . $name;

			case 'list':
				return 'Listed ' . (int) ( $card['count'] ?? 0 ) . ' ' . strtolower( (string) ( $card['title'] ?? 'items' ) );

			default:
				// Unknown card types fall back to the title so a future type still
				// yields a readable summary clause.
				return (string) ( $card['title'] ?? '' );
		}
	}

	/**
	 * Join clauses into a single sentence with an Oxford-style "and".
	 *
	 * @param array<int,string> $clauses Non-empty clauses.
	 * @return string
	 */
	private static function join_clauses( array $clauses ): string {
		if ( 1 === count( $clauses ) ) {
			return $clauses[0];
		}

		$first = array_shift( $clauses );
		$rest  = array_map( 'lcfirst', $clauses );
		$last  = array_pop( $rest );

		$lead = array_merge( array( $first ), $rest );

		return implode( ', ', $lead ) . ' and ' . $last;
	}
}
