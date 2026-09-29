<?php
/**
 * Tool: Run Full Audit
 *
 * Run a comprehensive site audit across 8 dimensions. Returns overall score,
 * grade, and per-dimension breakdown. Auto-saves via save_audit_result.
 *
 * @package Agentic\Tools
 */

declare(strict_types=1);

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Run_Full_Audit extends \Agentic\Tool_Base {

	public function get_name(): string {
		return 'run_full_audit';
	}

	public function get_description(): string {
		return 'Run a comprehensive site audit across 8 dimensions (UX, Accessibility, GDPR, Web Standards, SEO, AI Visibility, Content Quality, Commercial). Returns overall score, grade, and per-dimension breakdown.';
	}

	public function get_category(): string {
		return 'site-audit';
	}

	public function get_parameters(): array {
		return array(
			'type'       => 'object',
			'properties' => array(),
			'required'   => array(),
		);
	}

	public function get_annotations(): array {
		return array(
			'read_only'   => true,
			'destructive' => false,
		);
	}

	public function execute( array $args ): array {
		$loader     = \Agentic\Tool_Loader::get_instance();
		$dimensions = array( 'ux', 'accessibility', 'gdpr', 'web_standards', 'seo', 'ai_visibility', 'content_quality', 'commercial' );

		$scores = array();
		foreach ( $dimensions as $dim ) {
			$scores[ $dim ] = $loader->execute( 'audit_dimension', array( 'dimension' => $dim ) );
		}

		$overall = array_sum( array_column( $scores, 'score' ) );
		$grade   = \Agentic\Tool_Helpers::score_to_grade( $overall );

		// Collect all individual checks for roadblock ranking.
		$all_checks = array();
		foreach ( $scores as $dim => $result ) {
			foreach ( $result['checks'] as $check ) {
				$check['dimension'] = $dim;
				$all_checks[]       = $check;
			}
		}

		// Top 3 roadblocks: failed/warning checks ranked by impact desc.
		$roadblocks = array_filter( $all_checks, fn( $c ) => 'fail' === $c['status'] || 'warn' === $c['status'] );
		usort( $roadblocks, fn( $a, $b ) => ( $b['impact'] ?? 0 ) <=> ( $a['impact'] ?? 0 ) );
		$top3 = array_slice( array_values( $roadblocks ), 0, 3 );

		$result = array(
			'scanned_at' => gmdate( 'Y-m-d H:i:s' ),
			'site_url'   => home_url(),
			'overall'    => $overall,
			'grade'      => $grade,
			'label'      => $this->grade_label( $grade ),
			'dimensions' => $scores,
			'roadblocks' => $top3,
		);

		// Auto-save.
		$loader->execute( 'save_audit_result', array( 'result' => $result ) );

		return $result;
	}

	private function grade_label( string $grade ): string {
		return match ( $grade ) {
			'A'     => 'Excellent — well-optimised across all dimensions',
			'B'     => 'Good — solid foundation with minor gaps',
			'C'     => 'Fair — noticeable issues affecting performance',
			'D'     => 'Poor — significant issues limiting performance',
			'D-'    => 'Weak — major roadblocks across multiple dimensions',
			'F'     => 'Critical — fundamental problems that must be addressed',
			default => '',
		};
	}
}

return new Run_Full_Audit();
