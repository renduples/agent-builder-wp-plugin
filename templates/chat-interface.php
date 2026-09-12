<?php
/**
 * Chat interface template
 *
 * Supports dynamic agent selection - users can chat with any active agent.
 *
 * @package    Agent_Builder
 * @subpackage Templates
 * @author     Agent Builder Team <support@agentic-plugin.com>
 * @license    GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 * @link       https://agentic-plugin.com
 * @since      0.1.0
 *
 * php version 8.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$agentic_user = wp_get_current_user();

// Get accessible agents.
$agentic_registry = Agentic_Agent_Registry::get_instance();
$agentic_agents   = $agentic_registry->get_accessible_instances();

// Default to first available agent or passed agent_id.
// Priority: $agentic_force_agent (set by the caller to lock the chat to one
// specific agent, e.g. the Publish page's Basic view) → URL parameter →
// cookie preference → WordPress Assistant (for a user who has never chatted
// with anything yet) → first agent.
if ( ! empty( $agentic_force_agent ) ) {
	$agentic_default_agent_id = sanitize_key( (string) $agentic_force_agent );
} else {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- No form submission, just URL parameter for agent selection.
	$agentic_default_agent_id = isset( $_GET['agent'] ) ? sanitize_key( $_GET['agent'] ) : '';

	if ( ! $agentic_default_agent_id && isset( $_COOKIE['agentic_last_agent'] ) ) {
		$agentic_default_agent_id = sanitize_key( $_COOKIE['agentic_last_agent'] );
	}

	if ( ! $agentic_default_agent_id && isset( $agentic_agents['wordpress-assistant'] ) ) {
		$agentic_default_agent_id = 'wordpress-assistant';
	}
}

$agentic_current_agent    = null;
$agentic_current_agent_id = '';

if ( $agentic_default_agent_id && isset( $agentic_agents[ $agentic_default_agent_id ] ) ) {
	$agentic_current_agent    = $agentic_agents[ $agentic_default_agent_id ];
	$agentic_current_agent_id = $agentic_default_agent_id;
} elseif ( ! empty( $agentic_agents ) ) {
	$agentic_current_agent    = reset( $agentic_agents );
	$agentic_current_agent_id = $agentic_current_agent->get_id();
}
?>
<style>.agentic-chat-container{opacity:0;transition:opacity .15s ease-in}</style>
<div id="agentic-chat" class="agentic-chat-container" data-agentic-chat-root="1" data-agent-id="<?php echo esc_attr( $agentic_current_agent_id ); ?>">
	<div class="agentic-chat-header">
		<div class="agentic-agent-info">
			<?php if ( empty( $agentic_force_agent ) && count( $agentic_agents ) > 1 ) : ?>
			<div class="agentic-agent-selector">
				<select id="agentic-agent-select" class="agentic-agent-dropdown">
				<?php
				// Sort agents by name (case-insensitive).
				$agentic_sorted_agents = $agentic_agents;
				uasort(
					$agentic_sorted_agents,
					function ( $a, $b ) {
						return strcasecmp( $a->get_name(), $b->get_name() );
					}
				);
				?>
				<?php foreach ( $agentic_sorted_agents as $agentic_agent ) : ?>
						<option value="<?php echo esc_attr( $agentic_agent->get_id() ); ?>" 
								data-icon="<?php echo esc_attr( $agentic_agent->get_icon() ); ?>"
								data-welcome="<?php echo esc_attr( $agentic_agent->get_welcome_message() ); ?>"
								<?php selected( $agentic_agent->get_id(), $agentic_current_agent_id ); ?>>
							<?php echo esc_html( $agentic_agent->get_icon() . ' ' . $agentic_agent->get_name() ); ?>
						</option>
					<?php endforeach; ?>
					<?php if ( current_user_can( 'manage_options' ) ) : ?>
					<option value="load-more" data-action="load-more">+ Load more . . .</option>
					<?php endif; ?>
				</select>
			</div>
			<?php else : ?>
			<div class="agentic-agent-avatar">
				<?php echo esc_html( $agentic_current_agent ? $agentic_current_agent->get_icon() : '🤖' ); ?>
			</div>
			<?php endif; ?>
			<div class="agentic-agent-details">
				<?php if ( $agentic_current_agent ) : ?>
					<div class="agentic-agent-meta">
						Version <?php echo esc_html( $agentic_current_agent->get_version() ?? '1.0.0' ); ?>
						<span class="agent-meta-separator">|</span>
						By
						<?php
						$agentic_author     = $agentic_current_agent->get_author() ?? 'Unknown';
						$agentic_author_uri = $agentic_current_agent->get_author_uri();
						if ( $agentic_author_uri ) :
							?>
							<a href="<?php echo esc_url( $agentic_author_uri ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $agentic_author ); ?></a>
						<?php else : ?>
							<?php echo esc_html( $agentic_author ); ?>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<div class="agentic-chat-actions">
			<div class="agentic-status-leds" id="agentic-status-leds" aria-live="polite" title="System status">
				<span class="agentic-led agentic-led-unknown" data-led="ai" title="AI service: checking…">
					<span class="agentic-led-dot"></span><span class="agentic-led-label"><?php esc_html_e( 'AI', 'agent-builder' ); ?></span>
				</span>
				<span class="agentic-led agentic-led-unknown" data-led="credits" title="Credits: checking…">
					<span class="agentic-led-dot"></span><span class="agentic-led-label"><?php esc_html_e( 'Credits', 'agent-builder' ); ?></span>
				</span>
			</div>
			<button id="agentic-history-btn" class="agentic-btn-secondary" title="Chat History">
				<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
			</button>
			<button id="agentic-clear-chat" class="agentic-btn-secondary" title="New Chat">
				<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
			</button>
		</div>
	</div>

	<div id="agentic-history-panel" class="agentic-history-panel" style="display: none;">
		<div class="agentic-history-header">
			<h2>Chat History</h2>
			<button id="agentic-history-close" class="agentic-history-close" title="Close">&times;</button>
		</div>
		<div id="agentic-history-list" class="agentic-history-list">
			<div class="agentic-history-loading">Loading sessions…</div>
		</div>
	</div>

	<div id="agentic-messages" class="agentic-chat-messages">
		<?php if ( $agentic_current_agent ) : ?>
		<div class="agentic-message agentic-message-agent">
			<div class="agentic-message-content">
				<?php
				$agentic_welcome = $agentic_current_agent->get_welcome_message();
				// Convert markdown bold, links, and list items for display.
				$agentic_welcome = esc_html( $agentic_welcome );
				$agentic_welcome = preg_replace( '/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $agentic_welcome );
				$agentic_welcome = preg_replace( '/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2" target="_blank">$1</a>', $agentic_welcome );
				$agentic_welcome = preg_replace( '/^- /m', '&bull; ', $agentic_welcome );
				$agentic_welcome = nl2br( $agentic_welcome );
				echo wp_kses_post( $agentic_welcome, 'post' );
				?>
				
			<?php
				// Agent-routing shortcuts (WordPress Assistant): compact pills that jump to a
				// teammate, filtered to only agents actually accessible on this install.
			if ( 'wordpress-assistant' === $agentic_current_agent->get_id() && method_exists( $agentic_current_agent, 'get_agent_shortcuts' ) ) :
				$agentic_quick_shortcuts = array_filter(
					(array) $agentic_current_agent->get_agent_shortcuts(),
					function ( $s ) use ( $agentic_agents ) {
						return isset( $s['agent_id'], $agentic_agents[ $s['agent_id'] ] );
					}
				);
				if ( ! empty( $agentic_quick_shortcuts ) ) :
					?>
					<div class="agentic-quick-actions">
					<?php foreach ( $agentic_quick_shortcuts as $agentic_shortcut ) : ?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=agentic-chat&agent=' . $agentic_shortcut['agent_id'] ) ); ?>" class="agentic-quick-action-btn">
								<?php echo esc_html( $agentic_shortcut['label'] ); ?>
							</a>
						<?php endforeach; ?>
					</div>
						<?php
					endif;
				endif;

				// Suggested starter prompts (every agent) - removes blank-input paralysis.
				$agentic_prompts = $agentic_current_agent->get_suggested_prompts();
			if ( ! empty( $agentic_prompts ) ) :
				?>
					<p class="agentic-starter-label"><?php esc_html_e( 'Try asking', 'agent-builder' ); ?></p>
					<div class="agentic-suggested-prompts">
					<?php foreach ( $agentic_prompts as $agentic_prompt ) : ?>
						<button class="agentic-prompt-btn" data-prompt="<?php echo esc_attr( $agentic_prompt ); ?>">
							<?php echo esc_html( $agentic_prompt ); ?>
						</button>
						<?php endforeach; ?>
					</div>
					<?php
				endif;
			?>
			</div>
		</div>
		<?php else : ?>
		<div class="agentic-empty-state">
			<div class="empty-state-icon">🤖</div>
			<h2>No Agents Activated</h2>
			<p>Activate an AI agent to start chatting. Agents can help you with content, SEO, security, and more.</p>
			
			<?php if ( current_user_can( 'manage_options' ) ) : ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=agentic-agents' ) ); ?>" class="activate-agents-btn">
				Activate Agents in Dashboard
			</a>
			<?php else : ?>
			<p class="contact-admin">Contact your site administrator to activate AI agents.</p>
			<?php endif; ?>
		</div>
		<?php endif; ?>
	</div>

	<?php if ( $agentic_current_agent ) : ?>
	<div id="agentic-consent-banner" class="agentic-consent-banner" style="display:none;">
		<p id="agentic-consent-text"></p>
		<button type="button" id="agentic-consent-accept" class="agentic-consent-accept"><?php esc_html_e( 'I Understand', 'agent-builder' ); ?></button>
	</div>
	<div class="agentic-chat-input-container">
		<div class="agentic-typing-indicator" id="agentic-typing" style="display: none;">
			<span></span>
			<span></span>
			<span></span>
			<span id="agentic-typing-text">Agent is thinking...</span>
		</div>
		<div id="agentic-image-preview" class="agentic-image-preview" style="display:none;">
			<img id="agentic-preview-img" src="" alt="Preview" />
			<button type="button" id="agentic-remove-image" class="agentic-remove-image" title="<?php esc_attr_e( 'Remove image', 'agent-builder' ); ?>">&times;</button>
		</div>
		<form id="agentic-chat-form" class="agentic-chat-form">
			<input type="file" id="agentic-file-input" accept="image/jpeg,image/png,image/gif,image/webp" style="display:none;" />
			<button type="button" id="agentic-attach-btn" class="agentic-attach-btn" title="<?php esc_attr_e( 'Attach image', 'agent-builder' ); ?>">
				<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.44 11.05-9.19 9.19a6 6 0 0 1-8.49-8.49l8.57-8.57A4 4 0 1 1 18 8.84l-8.59 8.57a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
			</button>
			<textarea 
				id="agentic-input" 
				class="agentic-chat-input" 
				placeholder="Ask <?php echo esc_attr( $agentic_current_agent->get_name() ); ?> a question..."
				rows="1"
			></textarea>
			<button type="button" id="agentic-voice-btn" class="agentic-voice-btn" title="<?php esc_attr_e( 'Voice input', 'agent-builder' ); ?>" style="display:none;">
				<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3Z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" x2="12" y1="19" y2="22"/></svg>
			</button>
			<button type="button" id="agentic-tts-btn" class="agentic-tts-btn" title="<?php esc_attr_e( 'Read aloud', 'agent-builder' ); ?>" style="display:none;">
				<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M15.54 8.46a5 5 0 0 1 0 7.07"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14"/></svg>
			</button>
			<button type="submit" class="agentic-send-btn" id="agentic-send" title="<?php esc_attr_e( 'Send message', 'agent-builder' ); ?>">
				<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
					<line x1="22" x2="11" y1="2" y2="13"/>
					<polygon points="22 2 15 22 11 13 2 9 22 2"/>
				</svg>
				<span class="screen-reader-text"><?php esc_html_e( 'Send message', 'agent-builder' ); ?></span>
			</button>
		</form>
	</div>
	<?php endif; ?>

	<?php
	$agentic_provider_labels = array(
		'openai'    => 'OpenAI',
		'anthropic' => 'Anthropic',
		'xai'       => 'xAI',
		'google'    => 'Google',
		'mistral'   => 'Mistral',
		'ollama'    => 'Ollama',
		'agentic'   => 'Agentic AI',
		'meta'      => 'Meta Llama',
		'cohere'    => 'Cohere',
		'deepseek'  => 'DeepSeek',
		'kimi'      => 'Kimi',
	);
	$agentic_pm_provider     = get_option( 'agentic_llm_provider', 'agentic' );
	$agentic_pm_model        = get_option( 'agentic_model', '' );
	$agentic_pm_ov_provider  = \Agentic\Agent_Settings::get( $agentic_current_agent_id, 'override_provider' );
	$agentic_pm_ov_model     = \Agentic\Agent_Settings::get( $agentic_current_agent_id, 'override_model' );
	if ( ! empty( $agentic_pm_ov_provider ) ) {
		$agentic_pm_provider = $agentic_pm_ov_provider;
	}
	if ( ! empty( $agentic_pm_ov_model ) ) {
		$agentic_pm_model = $agentic_pm_ov_model;
	}
	$agentic_pm_label      = $agentic_provider_labels[ $agentic_pm_provider ] ?? ucfirst( $agentic_pm_provider );
	$agentic_show_branding     = '1' !== get_option( 'agentic_chat_whitelabel', '1' );
	$agentic_show_whatsapp_cta = '1' === get_option( 'agentic_show_whatsapp_cta', '0' );
	?>
	<div class="agentic-chat-footer">
		<?php if ( $agentic_show_branding ) : ?>
		<span class="agentic-footer-info">
			<?php esc_html_e( 'Powered by Agent Builder', 'agent-builder' ); ?>
			<?php
			if ( $agentic_pm_label ) :
				?>
				- <?php echo esc_html( $agentic_pm_label ); ?><?php endif; ?>
				<?php
				if ( $agentic_pm_model ) :
					?>
				- <?php echo esc_html( $agentic_pm_model ); ?><?php endif; ?>
		</span>
		<?php endif; ?>
		<span class="agentic-footer-stats" id="agentic-stats"></span>
	</div>
	<?php if ( $agentic_current_agent && $agentic_show_whatsapp_cta ) : ?>
	<a class="agentic-whatsapp-cta" href="https://agentic-plugin.com/whatsapp/?utm_source=free-plugin&amp;utm_medium=chat&amp;utm_campaign=whatsapp-connector" target="_blank" rel="noopener">
		<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2 22l5.25-1.38c1.45.79 3.08 1.21 4.79 1.21h.01c5.46 0 9.91-4.45 9.91-9.91 0-2.65-1.03-5.14-2.9-7.01A9.82 9.82 0 0 0 12.04 2Zm0 1.67c2.2 0 4.27.86 5.83 2.42a8.2 8.2 0 0 1 2.42 5.82c0 4.54-3.7 8.24-8.25 8.24-1.48 0-2.93-.4-4.19-1.15l-.3-.18-3.12.82.83-3.04-.2-.31a8.2 8.2 0 0 1-1.26-4.38c0-4.54 3.7-8.24 8.24-8.24Zm-2.72 4.4c-.13 0-.35.05-.53.25-.18.2-.7.68-.7 1.67 0 .98.72 1.93.82 2.06.1.13 1.4 2.14 3.4 3 .47.2.84.33 1.13.42.47.15.9.13 1.24.08.38-.06 1.17-.48 1.33-.94.16-.46.16-.86.11-.94-.05-.08-.18-.13-.38-.23-.2-.1-1.17-.58-1.35-.64-.18-.07-.31-.1-.44.1-.13.2-.5.64-.62.77-.11.13-.23.15-.43.05-.2-.1-.84-.31-1.6-.99-.59-.53-.99-1.18-1.1-1.38-.12-.2-.01-.31.09-.41.09-.09.2-.23.3-.35.1-.12.13-.2.2-.34.06-.13.03-.25-.02-.35-.05-.1-.44-1.08-.62-1.48-.16-.38-.32-.33-.44-.34l-.38-.01Z"/></svg>
		<span><?php echo wp_kses( __( '<strong>Continue on WhatsApp</strong> — control your whole site from your phone', 'agent-builder' ), array( 'strong' => array() ) ); ?></span>
		<span class="agentic-whatsapp-pro">Pro</span>
	</a>
	<?php endif; ?>
</div>


