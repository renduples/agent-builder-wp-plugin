<?php
/**
 * Tool: detect_agent_duplicates
 *
 * @package    Agent_Builder
 * @subpackage Tools
 * @since      2.0.0
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Agentic\Tool_Base;

class Detect_Agent_Duplicates extends Tool_Base {

	public function get_name(): string {
		return 'detect_agent_duplicates';
	}

	public function get_description(): string {
		return 'Compare a proposed agent specification against existing agents to detect duplicates.';
	}

	public function get_category(): string {
		return 'assistant-trainer';
	}

	public function get_risk_level(): string {
		return 'none';
	}

	public function get_annotations(): array {
		return array(
			'read_only'   => true,
			'destructive' => false,
		);
	}

	public function get_parameters(): array {
		return array(
			'name'        => array(
				'type'        => 'string',
				'description' => 'Proposed agent name.',
				'required'    => true,
			),
			'slug'        => array(
				'type'        => 'string',
				'description' => 'Proposed slug.',
				'required'    => false,
			),
			'description' => array(
				'type'        => 'string',
				'description' => 'Agent description.',
				'required'    => true,
			),
			'category'    => array(
				'type'        => 'string',
				'description' => 'Agent category.',
				'required'    => false,
			),
			'tool_names'  => array(
				'type'        => 'array',
				'description' => 'Proposed tool names.',
				'items'       => array( 'type' => 'string' ),
				'required'    => false,
			),
			'threshold'   => array(
				'type'        => 'integer',
				'description' => 'Similarity threshold 0-100. Default 50.',
				'required'    => false,
			),
		);
	}

	public function execute( array $args ): array {
		$proposed_name  = $args['name'] ?? '';
		$proposed_slug  = $args['slug'] ?? '';
		$proposed_desc  = $args['description'] ?? '';
		$proposed_cat   = $args['category'] ?? '';
		$proposed_tools = $args['tool_names'] ?? array();
		$threshold      = (int) ( $args['threshold'] ?? 50 );

		if ( empty( $proposed_name ) && empty( $proposed_desc ) ) {
			return array( 'error' => 'At least name or description is required' );
		}

		$registry       = \Agentic_Agent_Registry::get_instance();
		$instances      = $registry->get_all_instances();
		$matches        = array();
		$proposed_words = $this->extract_words( $proposed_name . ' ' . $proposed_desc );

		foreach ( $instances as $agent ) {
			$agent_slug = $agent->get_id();
			$scores     = array();

			if ( ! empty( $proposed_slug ) ) {
				if ( $proposed_slug === $agent_slug ) {
					$scores['slug'] = 100;
				} elseif ( str_contains( $agent_slug, $proposed_slug ) || str_contains( $proposed_slug, $agent_slug ) ) {
					$scores['slug'] = 60;
				} else {
					$scores['slug'] = 0;
				}
			}

			$agent_name_words = $this->extract_words( $agent->get_name() );
			$name_proposed    = $this->extract_words( $proposed_name );

			if ( ! empty( $name_proposed ) && ! empty( $agent_name_words ) ) {
				$name_overlap   = count( array_intersect( $name_proposed, $agent_name_words ) );
				$name_max       = max( count( $name_proposed ), count( $agent_name_words ) );
				$scores['name'] = $name_max > 0 ? (int) round( ( $name_overlap / $name_max ) * 100 ) : 0;
			}

			$agent_desc_words = $this->extract_words( $agent->get_description() );

			if ( ! empty( $proposed_words ) && ! empty( $agent_desc_words ) ) {
				$desc_overlap          = count( array_intersect( $proposed_words, $agent_desc_words ) );
				$desc_max              = max( count( $proposed_words ), count( $agent_desc_words ) );
				$scores['description'] = $desc_max > 0 ? (int) round( ( $desc_overlap / $desc_max ) * 100 ) : 0;
			}

			if ( ! empty( $proposed_cat ) ) {
				$scores['category'] = strtolower( $proposed_cat ) === strtolower( $agent->get_category() ) ? 100 : 0;
			}

			if ( ! empty( $proposed_tools ) ) {
				$agent_tool_names = $agent->get_tool_names();

				$tool_overlap    = count( array_intersect( $proposed_tools, $agent_tool_names ) );
				$tool_max        = max( count( $proposed_tools ), count( $agent_tool_names ) );
				$scores['tools'] = $tool_max > 0 ? (int) round( ( $tool_overlap / $tool_max ) * 100 ) : 0;
			}

			$weights      = array(
				'slug'        => 25,
				'name'        => 30,
				'description' => 20,
				'category'    => 10,
				'tools'       => 15,
			);
			$total_weight = 0;
			$weighted_sum = 0;

			foreach ( $weights as $key => $weight ) {
				if ( isset( $scores[ $key ] ) ) {
					$weighted_sum += $scores[ $key ] * $weight;
					$total_weight += $weight;
				}
			}

			$overall = $total_weight > 0 ? (int) round( $weighted_sum / $total_weight ) : 0;

			if ( $overall >= $threshold ) {
				$matches[] = array(
					'slug'        => $agent_slug,
					'name'        => $agent->get_name(),
					'category'    => $agent->get_category(),
					'similarity'  => $overall,
					'scores'      => $scores,
					'description' => $agent->get_description(),
				);
			}
		}

		usort( $matches, fn( $a, $b ) => $b['similarity'] <=> $a['similarity'] );

		$has_duplicates = ! empty( $matches );
		$recommendation = 'No similar agents found — safe to create.';

		if ( $has_duplicates ) {
			$top = $matches[0];

			if ( $top['similarity'] >= 80 ) {
				$recommendation = "High overlap with '{$top['name']}' ({$top['similarity']}%). Consider extending it instead.";
			} elseif ( $top['similarity'] >= 50 ) {
				$recommendation = "Moderate overlap with '{$top['name']}' ({$top['similarity']}%). Review before creating.";
			} else {
				$recommendation = 'Minor overlap detected — proceed with creation but ensure differentiation.';
			}
		}

		return array(
			'has_duplicates' => $has_duplicates,
			'matches'        => $matches,
			'total_agents'   => count( $instances ),
			'threshold'      => $threshold,
			'recommendation' => $recommendation,
		);
	}

	private function extract_words( string $text ): array {
		$text  = strtolower( $text );
		$words = preg_split( '/[\s\-_,.:;!?()\[\]]+/', $text, -1, PREG_SPLIT_NO_EMPTY );
		$stop  = array(
			'a',
			'an',
			'the',
			'and',
			'or',
			'for',
			'to',
			'in',
			'on',
			'of',
			'is',
			'it',
			'with',
			'as',
			'by',
			'at',
			'from',
			'that',
			'this',
			'your',
			'you',
			'are',
			'be',
			'was',
			'can',
			'will',
			'all',
			'each',
			'has',
			'have',
		);
		$words = array_diff( $words, $stop );

		return array_values( array_unique( $words ) );
	}
}

return new Detect_Agent_Duplicates();
