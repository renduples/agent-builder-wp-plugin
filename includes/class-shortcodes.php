<?php
/**
 * Agentic Shortcodes
 *
 * Provides shortcodes for embedding agents on the frontend:
 * - [agentic_chat] - Full chat interface
 * - [agentic_ask] - One-shot query (displays response inline)
 *
 * @package    Agent_Builder
 * @subpackage Includes
 * @author     Agent Builder Team <support@agentic-plugin.com>
 * @license    GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 * @link       https://agentic-plugin.com
 * @since      0.1.0
 *
 * php version 8.1
 */

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shortcode Handler
 */
class Shortcodes {

	/**
	 * Registered shortcode styles loaded flag.
	 *
	 * @var bool
	 */
	private static bool $assets_enqueued = false;

	/**
	 * Initialize shortcodes.
	 */
	public function __construct() {
		add_shortcode( 'agentic_chat', array( $this, 'render_chat' ) );
		add_shortcode( 'agentic_ask', array( $this, 'render_ask' ) );

		// Register frontend assets.
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
	}

	/**
	 * Register frontend assets (but don't enqueue until needed).
	 */
	public function register_assets(): void {
		wp_register_style(
			'agentic-chat-frontend',
			AGENT_BUILDER_URL . 'assets/css/chat-frontend.css',
			array(),
			(string) filemtime( AGENT_BUILDER_DIR . 'assets/css/chat-frontend.css' )
		);

		wp_register_script(
			'agentic-chat-frontend',
			AGENT_BUILDER_URL . 'assets/js/chat.js',
			array( 'agentic-ui' ),
			(string) filemtime( AGENT_BUILDER_DIR . 'assets/js/chat.js' ),
			true
		);
	}

	/**
	 * Enqueue assets when shortcode is used.
	 *
	 * @param string $agent_slug Optional agent slug for per-agent chat feature overrides.
	 */
	private function enqueue_assets( string $agent_slug = '' ): void {
		if ( self::$assets_enqueued ) {
			return;
		}

		// Ensure chat components (Chat_Security, etc.) are available.
		\Agentic\Plugin::get_instance()->load_chat_components();

		wp_enqueue_style( 'agentic-chat-frontend' );
		\Agentic\Chat_Assets::register_ui_library();
		wp_enqueue_style( 'agentic-ui' );
		wp_enqueue_script( 'agentic-chat-frontend' );

		// Apply the selected chat theme CSS variable overrides to the frontend chat.
		\Agentic\Chat_Assets::apply_frontend_chat_theme();

		$features      = \agent_builder_get_effective_chat_features( $agent_slug );
		$localize_data = array(
			'restUrl'        => esc_url_raw( rest_url( 'agentic/v1/' ) ),
			'nonce'          => wp_create_nonce( 'wp_rest' ),
			'audio'          => $features['audio'],
			'vision'         => $features['vision'],
			'costs'          => '0',
			'tts'            => $features['tts'],
			'ttsVoice'       => get_option( 'agent_builder_tts_voice', 'journey-f' ),
			'consentEnabled' => get_option( 'agent_builder_chat_consent_enabled', false ) ? '1' : '0',
			'consentText'    => \Agentic\GDPR::get_consent_text(),
			'isAdmin'        => current_user_can( 'manage_options' ) ? '1' : '0',
			'adminAgentsUrl' => admin_url( 'admin.php?page=agentic-agents' ),
			'slashCommands'  => \Agentic\Chat_Assets::get_slash_commands_for_js(),
			'i18n'           => agent_builder_chat_i18n(),
		);

		// Turnstile configuration for frontend, when configured and required.
		if ( Turnstile::is_required() ) {
			$localize_data['turnstileSiteKey'] = Turnstile::get_site_key();
			Turnstile::enqueue_script();
		}

		wp_localize_script( 'agentic-chat-frontend', 'agenticChat', $localize_data );

		self::$assets_enqueued = true;
	}

	/**
	 * Render chat shortcode.
	 *
	 * Usage:
	 * [agentic_chat]
	 * [agentic_chat agent="content-assistant"]
	 * [agentic_chat agent="content-assistant" style="popup"]
	 * [agentic_chat agent="content-assistant" height="400px"]
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public function render_chat( $atts ): string {
		// Check if user can access.
		if ( ! $this->can_access_frontend_chat() ) {
			return $this->render_login_prompt();
		}

		$atts = shortcode_atts(
			array(
				'agent'       => '',           // Agent slug (empty = first available).
				'style'       => get_option( 'agent_builder_sc_default_style', 'inline' ),
				'height'      => get_option( 'agent_builder_sc_default_height', '500px' ),
				'placeholder' => 'Type your message...',
				'show_header' => get_option( 'agent_builder_sc_default_show_header', '1' ) ? 'true' : 'false',
				'context'     => '',           // Optional context (e.g., product_id:123).
			),
			$atts,
			'agentic_chat'
		);

		// Enqueue assets with per-agent chat feature overrides.
		$this->enqueue_assets( $atts['agent'] );

		// Get the agent.
		$registry = \Agentic_Agent_Registry::get_instance();
		// When anonymous chat is allowed, logged-out users lack the 'read' cap,
		// so fall back to all instances rather than returning an empty set.
		$agents = is_user_logged_in()
			? $registry->get_accessible_instances()
			: $registry->get_all_instances();

		if ( empty( $agents ) ) {
			return '<p class="agentic-error">No agents available.</p>';
		}

		// Find requested agent or use first available.
		$agent    = null;
		$agent_id = '';

		if ( ! empty( $atts['agent'] ) && isset( $agents[ $atts['agent'] ] ) ) {
			$agent    = $agents[ $atts['agent'] ];
			$agent_id = $atts['agent'];
		} else {
			$agent    = reset( $agents );
			$agent_id = $agent->get_id();
		}

		// Self-register so the deployments tab shows hardcoded shortcodes.
		if ( class_exists( '\Agentic\Deployments' ) ) {
			\Agentic\Deployments::auto_register(
				\Agentic\Deployments::TYPE_SHORTCODE,
				$agent_id,
				array(
					'style'       => $atts['style'],
					'height'      => $atts['height'],
					'show_header' => $atts['show_header'],
					'placeholder' => $atts['placeholder'],
				),
				\Agentic\Deployments::SOURCE_AUTO,
				get_the_ID() ? get_the_ID() : null
			);
		}

		// Build unique container ID for multiple shortcodes on same page.
		$container_id = 'agentic-chat-' . wp_unique_id();

		// Get styling.
		$style_class = 'agentic-chat-' . sanitize_html_class( $atts['style'] );
		$height      = sanitize_text_field( $atts['height'] );
		$show_header = filter_var( $atts['show_header'], FILTER_VALIDATE_BOOLEAN );

		ob_start();
		?>
		<div id="<?php echo esc_attr( $container_id ); ?>" 
			class="agentic-chat-container agentic-chat-frontend <?php echo esc_attr( $style_class ); ?>"
			data-agentic-chat-root="1"
			data-agent-id="<?php echo esc_attr( $agent_id ); ?>"
			data-context="<?php echo esc_attr( $atts['context'] ); ?>"
			style="--agentic-chat-height: <?php echo esc_attr( $height ); ?>">
			
			<?php if ( $show_header ) : ?>
			<div class="agentic-chat-header">
				<div class="agentic-agent-info">
					<span class="agentic-agent-avatar"><?php echo esc_html( $agent->get_icon() ); ?></span>
					<div class="agentic-agent-details">
						<h4 id="agentic-agent-name"><?php echo esc_html( $agent->get_name() ); ?></h4>
					</div>
				</div>
				<div class="agentic-header-actions">
					<button type="button" class="agentic-header-btn agentic-new-chat-btn" title="<?php esc_attr_e( 'New Chat', 'agent-builder' ); ?>">
						<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
					</button>
					<button type="button" class="agentic-header-btn agentic-minimize-btn" title="<?php esc_attr_e( 'Minimize', 'agent-builder' ); ?>">
						<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
					</button>
				</div>
			</div>
			<?php endif; ?>

			<div id="agentic-messages" class="agentic-chat-messages">
				<div class="agentic-message agentic-message-agent">
					<div class="agentic-message-content">
						<?php echo wp_kses_post( $this->render_markdown( $agent->get_welcome_message() ) ); ?>
						<?php
						// Starter chips — honours the admin's Settings > Instructions
						// suggested_prompts override, falling back to the manifest.
						$agentic_sc_prompts = $agent->get_suggested_prompts();
						if ( ! empty( $agentic_sc_prompts ) ) :
							?>
						<p class="agentic-starter-label"><?php esc_html_e( 'Try asking', 'agent-builder' ); ?></p>
						<div class="agentic-suggested-prompts">
							<?php foreach ( $agentic_sc_prompts as $agentic_sc_prompt ) : ?>
							<button class="agentic-prompt-btn" data-prompt="<?php echo esc_attr( $agentic_sc_prompt ); ?>"><?php echo esc_html( $agentic_sc_prompt ); ?></button>
							<?php endforeach; ?>
						</div>
							<?php
						endif;
						?>
					</div>
				</div>
			</div>

			<div id="agentic-typing" class="agentic-typing-indicator" style="display: none;">
				<span></span><span></span><span></span>
				<span id="agentic-typing-text"><?php esc_html_e( 'Agent is thinking...', 'agent-builder' ); ?></span>
			</div>

			<div id="agentic-image-preview" class="agentic-image-preview" style="display:none;">
				<img id="agentic-preview-img" src="" alt="Preview" />
				<button type="button" id="agentic-remove-image" class="agentic-remove-image" title="<?php esc_attr_e( 'Remove image', 'agent-builder' ); ?>">&times;</button>
			</div>

			<?php if ( Turnstile::is_required() ) : ?>
			<div id="agentic-turnstile" style="display:none"></div>
			<?php endif; ?>

			<form id="agentic-chat-form" class="agentic-chat-form">
				<div class="agentic-input-wrapper">
					<input type="file" id="agentic-file-input" accept="image/jpeg,image/png,image/gif,image/webp" style="display:none;" />
					<button type="button" id="agentic-attach-btn" class="agentic-attach-btn" title="<?php esc_attr_e( 'Attach image', 'agent-builder' ); ?>">
						<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.44 11.05-9.19 9.19a6 6 0 0 1-8.49-8.49l8.57-8.57A4 4 0 1 1 18 8.84l-8.59 8.57a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
					</button>
					<textarea 
						id="agentic-input" 
						placeholder="<?php echo esc_attr( $atts['placeholder'] ); ?>"
						rows="1"
					></textarea>
					<button type="button" id="agentic-voice-btn" class="agentic-voice-btn" title="<?php esc_attr_e( 'Voice input', 'agent-builder' ); ?>" style="display:none;">
						<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3Z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" x2="12" y1="19" y2="22"/></svg>
					</button>
					<button type="submit" id="agentic-send" class="agentic-send-btn">
						<span class="dashicons dashicons-arrow-right-alt"></span>
					</button>
				</div>
			</form>

			<div class="agentic-chat-footer" style="display:none;">
				<span id="agentic-stats" class="agentic-stats"></span>
			</div>
		</div>

		<?php if ( 'popup' === $atts['style'] ) : ?>
		<button class="agentic-chat-trigger" data-target="<?php echo esc_attr( $container_id ); ?>">
			<?php echo esc_html( $agent->get_icon() ); ?> Chat
		</button>
		<script>
		(function() {
			const trigger = document.querySelector('[data-target="<?php echo esc_js( $container_id ); ?>"]');
			const container = document.getElementById('<?php echo esc_js( $container_id ); ?>');
			if (trigger && container) {
				container.classList.add('agentic-chat-hidden');
				trigger.addEventListener('click', function() {
					container.classList.toggle('agentic-chat-hidden');
				});
			}
		})();
		</script>
		<?php endif; ?>

		<?php
		return ob_get_clean();
	}

	/**
	 * Render ask shortcode (one-shot query).
	 *
	 * Usage:
	 * [agentic_ask agent="content-assistant" question="Summarize this post"]
	 * [agentic_ask agent="content-assistant" question="Draft a summary" context="post_id:123"]
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public function render_ask( $atts ): string {
		// Ensure chat components (Agent_Controller, LLM_Client, etc.) are available.
		\Agentic\Plugin::get_instance()->load_chat_components();

		$atts = shortcode_atts(
			array(
				'agent'    => '',
				'question' => '',
				'context'  => '',
				'cache'    => 'true',
			),
			$atts,
			'agentic_ask'
		);

		if ( empty( $atts['question'] ) ) {
			return '<p class="agentic-error">Missing question attribute.</p>';
		}

		// Get the agent.
		$registry = \Agentic_Agent_Registry::get_instance();
		$agents   = $registry->get_accessible_instances();

		if ( empty( $agents ) ) {
			return '<p class="agentic-error">No agents available.</p>';
		}

		$agent    = null;
		$agent_id = '';

		if ( ! empty( $atts['agent'] ) && isset( $agents[ $atts['agent'] ] ) ) {
			$agent    = $agents[ $atts['agent'] ];
			$agent_id = $atts['agent'];
		} else {
			$agent    = reset( $agents );
			$agent_id = $agent->get_id();
		}

		// Build the message with context.
		$message = $atts['question'];
		if ( ! empty( $atts['context'] ) ) {
			$message .= "\n\nContext: " . $atts['context'];
		}

		$user_id = get_current_user_id();

		// Call the agent (caching is handled by Agent_Controller).
		$controller = new Agent_Controller();
		$response   = $controller->chat( $message, array(), $user_id, '', $agent_id, null, '', 'shortcode' );

		if ( ! empty( $response['error'] ) ) {
			return '<p class="agentic-error">' . esc_html( $response['response'] ) . '</p>';
		}

		return $this->render_ask_response( $response['response'], $agent, ! empty( $response['cached'] ) );
	}

	/**
	 * Render the ask response.
	 *
	 * @param string              $response Response text.
	 * @param \Agentic\Agent_Base $agent Agent instance.
	 * @param bool                $cached   Whether response was cached.
	 * @return string HTML output.
	 */
	private function render_ask_response( string $response, $agent, bool $cached ): string {
		ob_start();
		?>
		<div class="agentic-ask-response">
			<div class="agentic-ask-header">
				<span class="agentic-agent-avatar"><?php echo esc_html( $agent->get_icon() ); ?></span>
				<span class="agentic-agent-name"><?php echo esc_html( $agent->get_name() ); ?></span>
				<?php if ( $cached ) : ?>
				<span class="agentic-cached-badge" title="Cached response">⚡</span>
				<?php endif; ?>
			</div>
			<div class="agentic-ask-content">
				<?php echo wp_kses_post( $this->render_markdown( $response ) ); ?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Simple markdown to HTML conversion.
	 *
	 * @param string $text Markdown text.
	 * @return string HTML.
	 */
	private function render_markdown( string $text ): string {
		// Basic markdown conversion.
		$html = esc_html( $text );

		// Code blocks.
		$html = preg_replace( '/```(\w*)\n([\s\S]*?)```/m', '<pre><code>$2</code></pre>', $html );

		// Inline code.
		$html = preg_replace( '/`([^`]+)`/', '<code>$1</code>', $html );

		// Bold.
		$html = preg_replace( '/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $html );

		// Italic.
		$html = preg_replace( '/\*([^*]+)\*/', '<em>$1</em>', $html );

		// Links.
		$html = preg_replace( '/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2" target="_blank" rel="noopener">$1</a>', $html );

		// Line breaks.
		$html = nl2br( $html );

		return $html;
	}

	/**
	 * Check if current user can access frontend chat.
	 *
	 * @return bool
	 */
	private function can_access_frontend_chat(): bool {
		// Allow if logged in.
		if ( is_user_logged_in() ) {
			return true;
		}

		// Check if anonymous access is allowed.
		return (bool) get_option( 'agent_builder_allow_anonymous_chat', false );
	}

	/**
	 * Render login prompt.
	 *
	 * @return string HTML.
	 */
	private function render_login_prompt(): string {
		$login_url = wp_login_url( get_permalink() );

		return sprintf(
			'<div class="agentic-login-prompt"><p>%s</p><a href="%s" class="button">%s</a></div>',
			esc_html__( 'Please log in to chat with our AI agent.', 'agent-builder' ),
			esc_url( $login_url ),
			esc_html__( 'Log In', 'agent-builder' )
		);
	}
}
