<?php
/**
 * Gutenberg Blocks
 *
 * Dynamically registers Gutenberg blocks for agents that have been
 * enabled on the Deploy Agents > Gutenberg Blocks tab.
 *
 * Each enabled agent gets its own block (agentic/{agent-slug}) placed
 * under the "Agentic AI" block category. Blocks are rendered server-side
 * using the same shortcode renderer used by [agentic_chat].
 *
 * @package    Agent_Builder
 * @subpackage Includes
 * @since      2.8.0
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dynamic Gutenberg block registration for agents.
 */
class Gutenberg_Blocks {

	/**
	 * Initialize hooks.
	 */
	public function __construct() {
		add_filter( 'block_categories_all', array( $this, 'register_block_category' ), 10, 2 );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor_assets' ) );

		// Register blocks immediately — this constructor is called during `init`
		// (via Plugin::load_components), so deferring to `init` would be too late.
		$this->register_blocks();
	}

	/**
	 * Register the "Agentic AI" block category.
	 *
	 * @param array                    $categories  Existing block categories.
	 * @param \WP_Block_Editor_Context $_context    Block editor context.
	 * @return array Modified categories.
	 */
	public function register_block_category( array $categories, $_context ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $context is required by the filter signature.
		// Prepend our category so it appears early in the inserter.
		array_unshift(
			$categories,
			array(
				'slug'  => 'agentic-ai',
				'title' => __( 'Agentic AI', 'agent-builder' ),
				'icon'  => 'format-chat',
			)
		);

		return $categories;
	}

	/**
	 * Register dynamic blocks for each enabled agent.
	 */
	public function register_blocks(): void {
		$enabled_slugs = (array) get_option( 'agent_builder_gutenberg_block_agents', array() );
		if ( empty( $enabled_slugs ) ) {
			return;
		}

		$registry = \Agentic_Agent_Registry::get_instance();
		$agents   = $registry->get_all_instances();

		foreach ( $enabled_slugs as $slug ) {
			if ( ! isset( $agents[ $slug ] ) ) {
				continue;
			}

			$agent = $agents[ $slug ];

			register_block_type(
				'agentic/' . $slug,
				array(
					'api_version'     => 3,
					'title'           => $agent->get_name(),
					'description'     => sprintf(
						/* translators: %s: agent name */
						__( 'Embed the %s AI agent as an interactive chat.', 'agent-builder' ),
						$agent->get_name()
					),
					'category'        => 'agentic-ai',
					'icon'            => 'format-chat',
					'keywords'        => array( 'chat', 'ai', 'gpt', 'bot', 'agent', 'assistant', strtolower( $agent->get_name() ) ),
					'supports'        => array(
						'html'     => false,
						'align'    => array( 'wide', 'full' ),
						'multiple' => true,
					),
					'attributes'      => array(
						'height'      => array(
							'type'    => 'string',
							'default' => '500px',
						),
						'style'       => array(
							'type'    => 'string',
							'default' => 'inline',
						),
						'theme'       => array(
							'type'    => 'string',
							'default' => '',
						),
						'provider'    => array(
							'type'    => 'string',
							'default' => '',
						),
						'model'       => array(
							'type'    => 'string',
							'default' => '',
						),
						'showHeader'  => array(
							'type'    => 'boolean',
							'default' => true,
						),
						'placeholder' => array(
							'type'    => 'string',
							'default' => '',
						),
						'floating'    => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
					'render_callback' => function ( array $attributes ) use ( $slug ): string {
						return $this->render_block( $slug, $attributes );
					},
				)
			);
		}
	}

	/**
	 * Server-side render callback for agent blocks.
	 *
	 * @param string $agent_slug Agent slug.
	 * @param array  $attributes Block attributes.
	 * @return string Rendered HTML.
	 */
	private function render_block( string $agent_slug, array $attributes ): string {
		// Apply per-block provider/model overrides if set.
		$this->maybe_apply_overrides( $agent_slug, $attributes );

		// Apply per-block theme if set — temporarily override the option for this render.
		if ( ! empty( $attributes['theme'] ) ) {
			add_filter(
				'pre_option_agent_builder_chat_theme',
				function () use ( $attributes ) {
					return $attributes['theme'];
				},
				20
			);
		}

		// Build shortcode attributes.
		$sc_atts = array(
			'agent'       => $agent_slug,
			'style'       => $attributes['style'] ?? 'inline',
			'height'      => $attributes['height'] ?? '500px',
			'show_header' => ! empty( $attributes['showHeader'] ) ? 'true' : 'false',
			'placeholder' => $attributes['placeholder'] ?? '',
		);

		// If floating, override style to popup.
		if ( ! empty( $attributes['floating'] ) ) {
			$sc_atts['style'] = 'popup';
		}

		// Build the shortcode string and render via the already-registered handler.
		$sc_parts = array();
		foreach ( $sc_atts as $key => $value ) {
			if ( '' !== $value ) {
				$sc_parts[] = $key . '="' . esc_attr( $value ) . '"';
			}
		}
		$shortcode = '[agentic_chat ' . implode( ' ', $sc_parts ) . ']';
		$output    = do_shortcode( $shortcode );

		// Wrap in a block wrapper for proper alignment support.
		$wrapper_attributes = \get_block_wrapper_attributes(
			array(
				'class' => 'wp-block-agentic-chat',
			)
		);

		return sprintf( '<div %s>%s</div>', $wrapper_attributes, $output );
	}

	/**
	 * Apply per-block LLM provider/model overrides via the agent overrides option.
	 *
	 * @param string $agent_slug Agent slug.
	 * @param array  $attributes Block attributes.
	 */
	private function maybe_apply_overrides( string $agent_slug, array $attributes ): void {
		if ( empty( $attributes['provider'] ) && empty( $attributes['model'] ) ) {
			return;
		}

		if ( ! empty( $attributes['provider'] ) ) {
			Agent_Settings::put_cache( $agent_slug, 'override_provider', $attributes['provider'] );
		}

		if ( ! empty( $attributes['model'] ) ) {
			Agent_Settings::put_cache( $agent_slug, 'override_model', $attributes['model'] );
		}
	}

	/**
	 * Enqueue editor assets (block registration JS + configuration data).
	 */
	public function enqueue_editor_assets(): void {
		$enabled_slugs = (array) get_option( 'agent_builder_gutenberg_block_agents', array() );
		if ( empty( $enabled_slugs ) ) {
			return;
		}

		$registry = \Agentic_Agent_Registry::get_instance();
		$agents   = $registry->get_all_instances();

		// Build agent data for the editor.
		$agent_blocks = array();
		foreach ( $enabled_slugs as $slug ) {
			if ( ! isset( $agents[ $slug ] ) ) {
				continue;
			}
			$agent          = $agents[ $slug ];
			$agent_blocks[] = array(
				'slug'        => $slug,
				'name'        => $agent->get_name(),
				'icon'        => $agent->get_icon(),
				'description' => sprintf(
					/* translators: %s: agent name */
					__( 'Embed the %s AI agent as an interactive chat.', 'agent-builder' ),
					$agent->get_name()
				),
			);
		}

		if ( empty( $agent_blocks ) ) {
			return;
		}

		// Provider/model data for the sidebar controls.
		$providers       = Provider_Registry::get_slug_name_map();
		$provider_models = array();
		$all_providers   = Provider_Registry::get_all();
		foreach ( $all_providers as $p_slug => $p_data ) {
			$provider_models[ $p_slug ] = $p_data['models'] ?? array();
		}

		$themes = array(
			''         => __( 'Default (from Settings)', 'agent-builder' ),
			'dark'     => __( 'Dark', 'agent-builder' ),
			'light'    => __( 'Light', 'agent-builder' ),
			'midnight' => __( 'Midnight', 'agent-builder' ),
			'ocean'    => __( 'Ocean', 'agent-builder' ),
		);

		wp_enqueue_script(
			'agentic-gutenberg-blocks',
			AGENT_BUILDER_URL . 'assets/js/gutenberg-blocks.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-server-side-render' ),
			(string) filemtime( AGENT_BUILDER_DIR . 'assets/js/gutenberg-blocks.js' ),
			false
		);
		wp_set_script_translations( 'agentic-gutenberg-blocks', 'agent-builder', AGENT_BUILDER_DIR . 'languages' );

		wp_localize_script(
			'agentic-gutenberg-blocks',
			'agenticBlocks',
			array(
				'agents'         => $agent_blocks,
				'themes'         => $themes,
				'providers'      => $providers,
				'providerModels' => $provider_models,
			)
		);
	}
}
