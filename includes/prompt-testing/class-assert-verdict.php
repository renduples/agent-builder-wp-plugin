<?php
/**
 * Structural assertion verdict — the prompt-testing default.
 *
 * Asserts on everything about a run that is stable across repeats, and nothing
 * about the wording of the answer, which is not.
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
 * Structural assertions — the default.
 *
 * Deliberately says nothing about the wording of an answer. Two runs of the
 * same prompt against the same model produce different prose, so anything that
 * asserted on text would fail constantly and get switched off. What it does
 * assert is everything that *is* stable: the call did not error, it produced
 * something, it did not spin out in the tool loop, and the tools the catalog
 * says should have been reached were reached.
 */
final class Assert_Verdict implements Verdict_Strategy {

	/**
	 * Responses the controller substitutes when its tool loop gives up.
	 *
	 * These come back with no error flag set and an iteration count that can sit
	 * under the ceiling, so without matching them a bailed-out run looks like a
	 * perfectly good answer. Matched on a distinctive fragment rather than the
	 * whole string so a copy edit upstream does not silently disable the check.
	 *
	 * @var string[]
	 */
	private const BAILOUT_FRAGMENTS = array(
		'maximum number of tool iterations',
		'kept encountering errors',
	);

	/**
	 * Evaluate one run.
	 *
	 * @param array<string, mixed> $row     Catalog row.
	 * @param array<string, mixed> $result  Controller result.
	 * @param array<string, mixed> $context Run context.
	 * @return array{verdict: string, reasons: string[]}
	 */
	public function evaluate( array $row, array $result, array $context ): array {
		$reasons  = array();
		$response = is_string( $result['response'] ?? null ) ? $result['response'] : '';

		if ( ! empty( $result['error'] ) ) {
			$reasons[] = sprintf( 'controller returned an error: %s', $this->one_line( $response ) );
		}

		if ( '' === trim( $response ) ) {
			$reasons[] = 'response was empty';
		}

		$max_iterations = (int) ( $context['max_iterations'] ?? 10 );
		$iterations     = (int) ( $result['iterations'] ?? 0 );

		if ( $iterations >= $max_iterations && $max_iterations > 0 ) {
			$reasons[] = sprintf( 'hit the tool-iteration ceiling (%d)', $max_iterations );
		}

		foreach ( self::BAILOUT_FRAGMENTS as $fragment ) {
			if ( str_contains( $response, $fragment ) ) {
				$reasons[] = 'the tool loop bailed out instead of answering';
				break;
			}
		}

		$expected = isset( $row['expect_tools'] ) && is_array( $row['expect_tools'] ) ? $row['expect_tools'] : array();

		if ( ! empty( $expected ) ) {
			// tools_used includes calls that were gated rather than executed, which
			// is what we want: the agent reaching for the right tool is the thing
			// under test, not whether the risk gate happened to let it through.
			$called  = array_map( 'strtolower', (array) ( $result['tools_used'] ?? array() ) );
			$missing = array();

			foreach ( $expected as $tool ) {
				if ( ! in_array( strtolower( (string) $tool ), $called, true ) ) {
					$missing[] = (string) $tool;
				}
			}

			if ( ! empty( $missing ) ) {
				$reasons[] = sprintf(
					'expected tool%s %s never called (called: %s)',
					count( $missing ) > 1 ? 's' : '',
					implode( ', ', $missing ),
					empty( $called ) ? 'none' : implode( ', ', $called )
				);
			}
		}

		return array(
			'verdict' => empty( $reasons ) ? Prompt_Test_Runner::VERDICT_PASS : Prompt_Test_Runner::VERDICT_FAIL,
			'reasons' => $reasons,
		);
	}

	/**
	 * Short name for the run header.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'assert';
	}

	/**
	 * Flatten a message for a one-line reason.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private function one_line( string $text ): string {
		$text = trim( (string) preg_replace( '/\s+/', ' ', $text ) );
		return mb_strlen( $text ) > 160 ? mb_substr( $text, 0, 160 ) . '…' : $text;
	}
}
