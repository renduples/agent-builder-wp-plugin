<?php
/**
 * Record-only verdict for the prompt-testing harness.
 *
 * For exploratory runs where the point is to read what the agents actually
 * said rather than to gate on it.
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
 * Record-only — never fails a prompt.
 *
 * For exploratory runs where the point is to read what the agents actually said,
 * not to gate on it.
 */
final class Null_Verdict implements Verdict_Strategy {

	/**
	 * Evaluate one run.
	 *
	 * @param array<string, mixed> $row     Catalog row.
	 * @param array<string, mixed> $result  Controller result.
	 * @param array<string, mixed> $context Run context.
	 * @return array{verdict: string, reasons: string[]}
	 */
	public function evaluate( array $row, array $result, array $context ): array {
		unset( $row, $result, $context );

		return array(
			'verdict' => Prompt_Test_Runner::VERDICT_INFO,
			'reasons' => array(),
		);
	}

	/**
	 * Short name for the run header.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'none';
	}
}
