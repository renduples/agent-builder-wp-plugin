<?php
/**
 * Verdict strategy contract for the prompt-testing harness.
 *
 * Deciding whether a prompt "passed" is the one genuinely debatable part of
 * this harness, so it lives behind an interface rather than an `if` inside the
 * runner. An LLM judge can be added later as another implementation without
 * touching the runner or the record format.
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
 * Decides a verdict for one completed prompt run.
 */
interface Verdict_Strategy {

	/**
	 * Evaluate one run.
	 *
	 * @param array<string, mixed> $row     Catalog row.
	 * @param array<string, mixed> $result  Agent_Controller result array.
	 * @param array<string, mixed> $context Run context (max_iterations, gated, ...).
	 * @return array{verdict: string, reasons: string[]}
	 */
	public function evaluate( array $row, array $result, array $context ): array;

	/**
	 * Short name for the run header.
	 *
	 * @return string
	 */
	public function get_name(): string;
}
