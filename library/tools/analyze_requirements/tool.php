<?php
/**
 * Tool: analyze_requirements
 *
 * Analyse a natural language description and return a structured agent design specification.
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

/**
 * AI tool that analyses a natural language description and returns a structured
 * agent design specification, including suggested tools, capabilities, and name.
 */
class Analyze_Requirements extends Tool_Base {

	/**
	 * Returns the tool's unique machine name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'analyze_requirements';
	}

	/**
	 * Returns the human-readable description of this tool.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return 'Analyze a natural language description and return a structured agent design specification.';
	}

	/**
	 * Returns the tool category slug.
	 *
	 * @return string
	 */
	public function get_category(): string {
		return 'assistant-trainer';
	}

	/**
	 * Returns the risk level of this tool.
	 *
	 * @return string
	 */
	public function get_risk_level(): string {
		return 'none';
	}

	/**
	 * Returns MCP-style annotations describing tool behaviour.
	 *
	 * @return array<string, bool>
	 */
	public function get_annotations(): array {
		return array(
			'read_only'   => true,
			'destructive' => false,
		);
	}

	/**
	 * Returns the JSON Schema parameter definitions for this tool.
	 *
	 * @return array<string, mixed>
	 */
	public function get_parameters(): array {
		return array(
			'description' => array(
				'type'        => 'string',
				'description' => 'Natural language description of what the agent should do.',
				'required'    => true,
			),
			'category'    => array(
				'type'        => 'string',
				'description' => 'Agent category. Inferred if not provided.',
				'required'    => false,
				'enum'        => array( 'Content', 'Admin', 'E-commerce', 'Frontend', 'Developer', 'Marketing' ),
			),
		);
	}

	/**
	 * Executes the tool and returns a structured agent design specification.
	 *
	 * This is a high-quality analysis tool. It produces rich, actionable output
	 * designed to help the Assistant Trainer create excellent, focused agents.
	 */
	public function execute( array $args ): array {
		$description = trim( $args['description'] ?? '' );
		$category    = $args['category'] ?? null;

		if ( empty( $description ) ) {
			return array( 'error' => 'Description is required' );
		}

		if ( ! $category ) {
			$category = $this->infer_category( $description );
		}

		$keywords = $this->extract_keywords( $description );

		// Core suggestions
		$suggested_tools = $this->suggest_tools( $keywords, $description, $category );
		$capabilities    = $this->suggest_capabilities( $description, $category, $suggested_tools );
		$name            = $this->suggest_name( $description );
		$slug            = $this->suggest_slug( $description, $name );
		$icon            = $this->suggest_icon( $category, $keywords );
		$knowledge_files = $this->suggest_knowledge_files( $description, $category );

		// Rich quality-aware analysis for the high-trust trainer
		$analysis = $this->build_analysis( $description, $category, $keywords, $suggested_tools );

		return array(
			'analysis'    => $analysis,
			'suggestions' => array(
				'name'            => $name,
				'slug'            => $slug,
				'icon'            => $icon,
				'tools'           => $suggested_tools,
				'capabilities'    => $capabilities,
				'knowledge_files' => $knowledge_files,
				'prompt_focus'    => $this->suggest_prompt_focus( $description, $category, $suggested_tools ),
			),
			'quality_notes' => $this->generate_quality_notes( $description, $suggested_tools, $capabilities ),
			'next_steps'    => array(
				'1. Review the tool suggestions and quality notes carefully.',
				'2. Strongly consider calling search_capabilities for deeper discovery in this domain.',
				'3. Call detect_agent_duplicates before committing to a design.',
				'4. Only call generate_agent when you are confident this will produce an excellent, focused agent.',
			),
		);
	}

	/**
	 * Infers the most appropriate agent category from the description text.
	 *
	 * @param string $description Natural language agent description.
	 * @return string Category label.
	 */
	private function infer_category( string $description ): string {
		$desc_lower = strtolower( $description );
		$patterns   = array(
			'Content'    => array( 'content', 'post', 'page', 'seo', 'writing', 'draft', 'media', 'image' ),
			'Admin'      => array( 'security', 'backup', 'performance', 'monitor', 'admin', 'database', 'maintenance' ),
			'E-commerce' => array( 'product', 'woocommerce', 'shop', 'order', 'inventory', 'payment', 'cart' ),
			'Frontend'   => array( 'visitor', 'chat', 'comment', 'form', 'user', 'support', 'contact' ),
			'Developer'  => array( 'code', 'debug', 'theme', 'plugin', 'scaffold', 'generate', 'build' ),
			'Marketing'  => array( 'social', 'campaign', 'email', 'newsletter', 'marketing', 'analytics' ),
		);

		foreach ( $patterns as $category => $terms ) {
			foreach ( $terms as $term ) {
				if ( strpos( $desc_lower, $term ) !== false ) {
					return $category;
				}
			}
		}

		return 'Admin';
	}

	/**
	 * Derives the minimum required WordPress capabilities for the described agent.
	 *
	 * @param string               $description Natural language agent description.
	 * @param string               $category    Inferred agent category.
	 * @param array<string, mixed> $tools       Suggested tools (used to detect write ops).
	 * @return string[] Deduplicated list of WP capability strings.
	 */
	private function suggest_capabilities( string $description, string $category, array $tools = array() ): array {
		$desc_lower = strtolower( $description );

		// Check if any tools perform write operations.
		$has_writes  = false;
		$write_verbs = array( 'create', 'update', 'delete', 'modify', 'edit', 'write', 'remove', 'insert', 'set', 'fix', 'rewrite', 'save', 'manage' );
		foreach ( $tools as $tool ) {
			$tool_name = strtolower( $tool['name'] ?? '' );
			foreach ( $write_verbs as $verb ) {
				if ( str_starts_with( $tool_name, $verb . '_' ) ) {
					$has_writes = true;
					break 2;
				}
			}
		}

		// Default to 'read' for read-only tool sets; escalate only when writes are present.
		if ( ! $has_writes ) {
			$base_caps = array( 'read' );
		} else {
			$base_caps = match ( $category ) {
				'Content'    => array( 'edit_posts' ),
				'Admin'      => array( 'manage_options' ),
				'E-commerce' => array( 'manage_woocommerce' ),
				'Frontend'   => array( 'moderate_comments' ),
				'Developer'  => array( 'edit_themes', 'edit_plugins' ),
				'Marketing'  => array( 'publish_posts' ),
				default      => array( 'read' ),
			};
		}

		if ( strpos( $desc_lower, 'delete' ) !== false ) {
			$base_caps[] = 'delete_posts';
		}
		if ( strpos( $desc_lower, 'user' ) !== false ) {
			$base_caps[] = 'list_users';
		}
		if ( strpos( $desc_lower, 'media' ) !== false || strpos( $desc_lower, 'image' ) !== false ) {
			$base_caps[] = 'upload_files';
		}

		return array_unique( $base_caps );
	}

	/**
	 * Generates a human-friendly display name for the agent.
	 *
	 * @param string $description Natural language agent description.
	 * @return string Suggested agent display name.
	 */
	private function suggest_name( string $description ): string {
		$words     = explode( ' ', $description );
		$key_words = array();

		foreach ( $words as $word ) {
			$word = preg_replace( '/[^a-zA-Z]/', '', $word );
			if ( strlen( $word ) > 3 && ! in_array( strtolower( $word ), array( 'that', 'this', 'with', 'agent', 'creates', 'manages', 'handles' ), true ) ) {
				$key_words[] = ucfirst( strtolower( $word ) );
				if ( count( $key_words ) >= 2 ) {
					break;
				}
			}
		}

		return empty( $key_words ) ? 'Custom Agent' : implode( ' ', $key_words ) . ' Manager';
	}

	/**
	 * Suggests an emoji icon based on category and keywords.
	 *
	 * @param string   $category Inferred agent category.
	 * @param string[] $keywords Extracted keywords.
	 * @return string Emoji character.
	 */
	private function suggest_icon( string $category, array $keywords ): string {
		$keyword_icons = array(
			'backup'      => '💾',
			'security'    => '🔒',
			'image'       => '🖼️',
			'media'       => '📸',
			'email'       => '📧',
			'social'      => '📱',
			'analytics'   => '📊',
			'performance' => '⚡',
			'seo'         => '🔍',
			'content'     => '📝',
			'product'     => '🛒',
			'user'        => '👤',
			'comment'     => '💬',
			'redirect'    => '↩️',
			'form'        => '📋',
			'cache'       => '🗄️',
			'database'    => '🗃️',
		);

		foreach ( $keywords as $keyword ) {
			if ( isset( $keyword_icons[ $keyword ] ) ) {
				return $keyword_icons[ $keyword ];
			}
		}

		return match ( $category ) {
			'Content'    => '📝',
			'Admin'      => '⚙️',
			'E-commerce' => '🛒',
			'Frontend'   => '💬',
			'Developer'  => '🔧',
			'Marketing'  => '📢',
			default      => '🤖',
		};
	}

	/**
	 * Suggest knowledge files based on the agent description.
	 *
	 * Scans agentic-knowledge/ for available files and recommends any
	 * that are relevant to the agent being built.
	 *
	 * @param string $description Agent description.
	 * @param string $category    Inferred category.
	 * @return array List of relative paths to knowledge files.
	 */
	private function suggest_knowledge_files( string $description, string $category ): array {
		$knowledge_dir = AGENT_BUILDER_KNOWLEDGE_DIR . '/';
		if ( ! is_dir( $knowledge_dir ) ) {
			return array();
		}

		$files     = glob( $knowledge_dir . '*.txt' );
		$suggested = array();

		if ( empty( $files ) ) {
			return array();
		}

		$desc_lower = strtolower( $description );

		// Platform knowledge is useful for agents that interact with users about the platform,
		// guide them, or need awareness of Agent Builder features and documentation.
		$platform_triggers = array(
			'guide',
			'help',
			'support',
			'onboard',
			'question',
			'answer',
			'faq',
			'recommend',
			'suggest',
			'navigate',
			'explain',
			'documentation',
			'agent builder',
			'platform',
			'feature',
			'deploy',
			'shortcode',
			'provider',
			'pro',
			'license',
			'pricing',
			'marketplace',
		);

		foreach ( $files as $file ) {
			$basename      = basename( $file );
			$relative_path = $basename;

			if ( 'platform-knowledge.txt' === $basename ) {
				foreach ( $platform_triggers as $trigger ) {
					if ( strpos( $desc_lower, $trigger ) !== false ) {
						$suggested[] = $relative_path;
						break;
					}
				}
			} else {
				// For future knowledge files: match by filename keywords against description.
				$file_stem  = strtolower( pathinfo( $basename, PATHINFO_FILENAME ) );
				$file_words = preg_split( '/[\-_]+/', $file_stem );
				foreach ( $file_words as $word ) {
					if ( strlen( $word ) > 3 && strpos( $desc_lower, $word ) !== false ) {
						$suggested[] = $relative_path;
						break;
					}
				}
			}
		}

		return array_unique( $suggested );
	}

	// =====================================================================
	// IMPROVED / NEW HIGH-QUALITY HELPERS
	// =====================================================================

	/**
	 * Builds rich analysis output with reasoning.
	 */
	private function build_analysis( string $description, string $category, array $keywords, array $tools ): array {
		$tool_count = count( $tools );

		$scope_note = $tool_count > 8
			? 'The description is quite broad. Consider narrowing the agent\'s focus for higher quality.'
			: 'Good scope for a focused specialist agent.';

		return array(
			'original_description' => $description,
			'inferred_category'    => $category,
			'keywords'             => $keywords,
			'reasoning'            => "Inferred category '{$category}' from domain language. Suggested {$tool_count} high-relevance tools from the real catalog. {$scope_note}",
			'design_notes'         => $this->generate_design_notes( $description, $tools ),
		);
	}

	/**
	 * Generates a clean slug.
	 */
	private function suggest_slug( string $description, string $name ): string {
		$base = strtolower( preg_replace( '/[^a-z0-9]+/', '-', $name ) );
		$base = trim( $base, '-' );

		if ( strlen( $base ) < 3 ) {
			$words = preg_split( '/\W+/', strtolower( $description ) );
			$base  = implode( '-', array_filter( array_slice( $words, 0, 4 ) ) );
		}

		return sanitize_key( $base ) ?: 'custom-agent';
	}

	/**
	 * Suggests what the generated system prompt should focus on.
	 */
	private function suggest_prompt_focus( string $description, string $category, array $tools ): string {
		$tool_names = array_map( fn( $t ) => $t['name'] ?? '', $tools );
		$focus      = "You are an expert {$category} specialist. Master the following capabilities: " . implode( ', ', array_slice( $tool_names, 0, 6 ) ) . '.';
		return $focus;
	}

	/**
	 * Generates actionable quality notes for the trainer's self-critique.
	 */
	private function generate_quality_notes( string $description, array $tools, array $capabilities ): array {
		$notes = [];

		if ( count( $tools ) < 2 ) {
			$notes[] = 'Very few tools suggested. The resulting agent may feel underpowered.';
		}
		if ( count( $tools ) > 10 ) {
			$notes[] = 'Large number of tools. Consider trimming to the 5-8 most important ones for a focused, high-quality agent.';
		}
		if ( in_array( 'manage_options', $capabilities, true ) && stripos( $description, 'read' ) !== false ) {
			$notes[] = 'High privileges suggested for a mostly read-oriented task. Reconsider capabilities.';
		}

		return $notes;
	}

	private function generate_design_notes( string $description, array $tools ): string {
		$has_write = false;
		foreach ( $tools as $t ) {
			$name = strtolower( $t['name'] ?? '' );
			if ( str_starts_with( $name, 'db_' ) || str_starts_with( $name, 'create_' ) || str_starts_with( $name, 'delete_' ) ) {
				$has_write = true;
				break;
			}
		}

		if ( $has_write ) {
			return 'This agent will perform write operations. Ensure proper risk levels and that the approval system will protect the user.';
		}

		return 'Primarily read/analyze focused agent — lower risk profile.';
	}

	/**
	 * Significantly improved tool suggestion using multi-factor scoring.
	 */
	private function suggest_tools( array $keywords, string $description, string $category ): array {
		$tool_loader = \Agentic\Tool_Loader::get_instance();
		$tool_loader->load();
		$all_tools = $tool_loader->get_all();

		$catalog = [];
		$desc_lower = strtolower( $description );

		foreach ( $all_tools as $name => $tool_instance ) {
			if ( $tool_instance->get_category() === 'assistant-trainer' ) {
				continue;
			}

			$catalog[ $name ] = [
				'name'        => $name,
				'description' => $tool_instance->get_description(),
				'category'    => $tool_instance->get_category(),
				'risk'        => method_exists( $tool_instance, 'get_risk_level' ) ? $tool_instance->get_risk_level() : 'low',
			];
		}

		$scored = [];
		foreach ( $catalog as $name => $tool ) {
			$score = 0;
			$tool_text = strtolower( $name . ' ' . $tool['description'] . ' ' . $tool['category'] );

			// Keyword matches (stronger weight)
			foreach ( $keywords as $kw ) {
				if ( strpos( $tool_text, $kw ) !== false ) {
					$score += 4;
				}
			}

			// Category affinity
			if ( strtolower( $tool['category'] ) === strtolower( $category ) ) {
				$score += 6;
			}

			// Description word overlap
			$desc_words = preg_split( '/\W+/', $desc_lower );
			foreach ( $desc_words as $word ) {
				if ( strlen( $word ) > 4 && strpos( $tool_text, $word ) !== false ) {
					$score += 2;
				}
			}

			// Boost known high-value utility tools
			$boosts = [ 'get_site_context', 'get_site_overview', 'list_posts', 'analyze_post' ];
			if ( in_array( $name, $boosts, true ) ) {
				$score += 3;
			}

			if ( $score > 0 ) {
				$scored[ $name ] = $score;
			}
		}

		arsort( $scored );

		$suggested = [];
		foreach ( array_slice( array_keys( $scored ), 0, 10 ) as $name ) {
			$suggested[] = [
				'name'        => $name,
				'description' => $catalog[ $name ]['description'],
				'category'    => $catalog[ $name ]['category'],
				'risk'        => $catalog[ $name ]['risk'],
				'match_reason' => 'Strong relevance to described purpose and keywords',
			];
		}

		// Smart fallback
		if ( empty( $suggested ) ) {
			$fallbacks = [ 'get_site_context', 'get_site_overview', 'list_posts' ];
			foreach ( $fallbacks as $fb ) {
				if ( isset( $catalog[ $fb ] ) ) {
					$suggested[] = [
						'name'        => $fb,
						'description' => $catalog[ $fb ]['description'],
						'category'    => $catalog[ $fb ]['category'],
						'risk'        => $catalog[ $fb ]['risk'],
						'match_reason' => 'Safe baseline utility tool',
					];
				}
			}
		}

		return $suggested;
	}

	/**
	 * Improved keyword extraction with better signal.
	 */
	private function extract_keywords( string $description ): array {
		$stop = [ 'the', 'and', 'for', 'with', 'that', 'this', 'from', 'into', 'about', 'agent', 'create', 'build', 'help', 'make', 'need', 'want', 'can', 'should', 'would', 'could' ];

		$words = preg_split( '/\W+/', strtolower( $description ) );
		$keywords = [];

		foreach ( $words as $w ) {
			if ( strlen( $w ) > 3 && ! in_array( $w, $stop, true ) ) {
				$keywords[] = $w;
			}
		}

		// Add some bigrams for better matching
		$parts = preg_split( '/\W+/', strtolower( $description ) );
		for ( $i = 0; $i < count( $parts ) - 1; $i++ ) {
			$bigram = $parts[ $i ] . ' ' . $parts[ $i + 1 ];
			if ( strlen( $bigram ) > 6 ) {
				$keywords[] = $bigram;
			}
		}

		return array_unique( $keywords );
	}
}

return new Analyze_Requirements();
