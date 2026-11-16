<?php
/**
 * Modal widget template.
 *
 * Variables available:
 *   $agents      (array)  Slug → agent instance map for eligible agents.
 *   $first_slug  (string) Slug of the first (primary) agent.
 *   $first_agent (object) Agent instance for the primary agent.
 *   $position    (string) Widget placement class, e.g. 'bottom-right'.
 *   $whitelabel  (bool)   Whether the "Powered by" footer is hidden.
 *
 * @package AgentBuilder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!-- Agentic Modal Widget -->
<div id="agentic-modal-widget" class="agentic-modal-widget agentic-modal-<?php echo esc_attr( $position ); ?>" style="display:none;">
	<div class="agentic-chat-container agentic-chat-frontend agentic-chat-popup" data-agentic-chat-root="1"
			data-agent-id="<?php echo esc_attr( $first_slug ); ?>">
		<div class="agentic-chat-header">
			<div class="agentic-agent-info">
				<span class="agentic-agent-avatar"><?php echo esc_html( $first_agent->get_icon() ); ?></span>
				<div class="agentic-agent-details">
					<h4 id="agentic-agent-name"><?php echo esc_html( $first_agent->get_name() ); ?></h4>
				</div>
			</div>
			<div class="agentic-header-actions">
				<button type="button" class="agentic-header-btn agentic-new-chat-btn" title="<?php esc_attr_e( 'New Chat', 'siteloom' ); ?>">
					<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
				</button>
				<button type="button" class="agentic-header-btn agentic-modal-close-btn" title="<?php esc_attr_e( 'Close', 'siteloom' ); ?>">
					<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
				</button>
			</div>
		</div>

		<div id="agentic-messages" class="agentic-chat-messages">
			<div class="agentic-message agentic-message-agent">
				<div class="agentic-message-content">
					<?php echo wp_kses_post( nl2br( preg_replace( '/\*\*([^*]+)\*\*/', '<strong>$1</strong>', esc_html( $first_agent->get_welcome_message() ) ) ) ); ?>
				</div>
			</div>
		</div>

		<div id="agentic-typing" class="agentic-typing-indicator" style="display: none;">
			<span></span><span></span><span></span>
			<span id="agentic-typing-text"><?php esc_html_e( 'Agent is thinking...', 'siteloom' ); ?></span>
		</div>

		<div id="agentic-image-preview" class="agentic-image-preview" style="display:none;">
			<img id="agentic-preview-img" src="" alt="Preview" />
			<button type="button" id="agentic-remove-image" class="agentic-remove-image" title="<?php esc_attr_e( 'Remove image', 'siteloom' ); ?>">&times;</button>
		</div>

		<div id="agentic-consent-banner" class="agentic-consent-banner" style="display:none;">
			<p id="agentic-consent-text"></p>
			<button type="button" id="agentic-consent-accept" class="agentic-consent-accept"><?php esc_html_e( 'I Understand', 'siteloom' ); ?></button>
		</div>

		<?php if ( \Agentic\Turnstile::is_required() ) : ?>
		<div id="agentic-turnstile" style="display:none"></div>
		<?php endif; ?>

		<form id="agentic-chat-form" class="agentic-chat-form">
			<div class="agentic-input-wrapper">
				<input type="file" id="agentic-file-input" accept="image/jpeg,image/png,image/gif,image/webp" style="display:none;" />
				<button type="button" id="agentic-attach-btn" class="agentic-attach-btn" title="<?php esc_attr_e( 'Attach image', 'siteloom' ); ?>">
					<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.44 11.05-9.19 9.19a6 6 0 0 1-8.49-8.49l8.57-8.57A4 4 0 1 1 18 8.84l-8.59 8.57a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
				</button>
				<textarea
					id="agentic-input"
					placeholder="<?php esc_attr_e( 'Type your message...', 'siteloom' ); ?>"
					rows="1"
				></textarea>
				<button type="button" id="agentic-voice-btn" class="agentic-voice-btn" title="<?php esc_attr_e( 'Voice input', 'siteloom' ); ?>" style="display:none;">
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
</div>

<?php if ( count( $agents ) > 1 ) : ?>
<!-- Agent picker for modal -->
<div id="agentic-modal-picker" class="agentic-modal-picker agentic-modal-<?php echo esc_attr( $position ); ?>" style="display:none;">
	<div class="agentic-modal-picker-header">
		<strong><?php esc_html_e( 'Choose an agent', 'siteloom' ); ?></strong>
		<button type="button" class="agentic-modal-picker-close">&times;</button>
	</div>
	<?php foreach ( $agents as $agentic_slug => $agentic_agent ) : ?>
	<button type="button" class="agentic-modal-picker-item" data-agent="<?php echo esc_attr( $agentic_slug ); ?>"
		data-name="<?php echo esc_attr( $agentic_agent->get_name() ); ?>"
		data-icon="<?php echo esc_attr( $agentic_agent->get_icon() ); ?>"
		data-welcome="<?php echo esc_attr( $agentic_agent->get_welcome_message() ); ?>">
		<?php echo esc_html( $agentic_agent->get_icon() . ' ' . $agentic_agent->get_name() ); ?>
	</button>
	<?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Floating trigger button -->
<button id="agentic-modal-trigger" class="agentic-modal-trigger agentic-modal-<?php echo esc_attr( $position ); ?>"
	data-agent-count="<?php echo count( $agents ); ?>"
	title="<?php echo count( $agents ) > 1 ? esc_attr__( 'Chat with AI', 'siteloom' ) : esc_attr( $first_agent->get_name() ); ?>">
	<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m3 21 1.9-5.7a8.5 8.5 0 1 1 3.8 3.8z"/></svg>
</button>
