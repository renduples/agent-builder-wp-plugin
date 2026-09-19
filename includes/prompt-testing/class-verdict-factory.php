<?php
/**
 * Verdict strategy factory for the prompt-testing harness.
 *
 * Resolves the --verdict flag to a strategy.
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
 * Builds verdict strategies by name.
 */
final class Verdict_Factory {

	/**
	 * Make a strategy.
	 *
	 * @param string $mode Strategy name.
	 * @return Verdict_Strategy
	 */
	public static function make( string $mode ): Verdict_Strategy {
		return match ( strtolower( trim( $mode ) ) ) {
			'none'  => new Null_Verdict(),
			default => new Assert_Verdict(),
		};
	}

	/**
	 * Names accepted by make().
	 *
	 * @return string[]
	 */
	public static function available(): array {
		return array( 'assert', 'none' );
	}
}
