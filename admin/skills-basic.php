<?php
/**
 * Skills — Basic view
 *
 * Instead of the raw list/new/edit/hub forms, Basic mode embeds a chat with
 * the bundled Skills Assistant, which has real tools to draft, edit, and
 * manage skills — and to find/import skills other people have published —
 * conversationally.
 *
 * Included by admin/skills.php — do not load directly.
 *
 * @package    Agent_Builder
 * @subpackage Admin
 * @since      3.3.75
 *
 * php version 8.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$agentic_skills_assistant_slug = 'skills-assistant';
$agentic_skills_assistant      = \Agentic_Agent_Registry::get_instance()->get_agent_instance( $agentic_skills_assistant_slug );

if ( ! $agentic_skills_assistant ) {
	?>
	<div class="notice notice-info inline">
		<p>
			<?php esc_html_e( 'The Skills Assistant is still activating.', 'agent-builder' ); ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=agentic-agents' ) ); ?>">
				<?php esc_html_e( 'Check the Agents page', 'agent-builder' ); ?>
			</a>
			<?php esc_html_e( 'or switch to Advanced above in the meantime.', 'agent-builder' ); ?>
		</p>
	</div>
	<?php
	return;
}

wp_enqueue_style(
	'agentic-chat',
	AGENT_BUILDER_URL . 'assets/css/chat.css',
	array(),
	AGENT_BUILDER_VERSION
);
\Agentic\Chat_Assets::maybe_add_chat_theme_overrides();

wp_enqueue_script(
	'agentic-chat',
	AGENT_BUILDER_URL . 'assets/js/chat.js',
	array( 'agentic-ui' ),
	(string) filemtime( AGENT_BUILDER_DIR . 'assets/js/chat.js' ),
	true
);

$agentic_skills_chat_features = agent_builder_get_effective_chat_features( $agentic_skills_assistant_slug );

wp_localize_script(
	'agentic-chat',
	'agenticChat',
	array(
		'restUrl'        => rest_url( 'agentic/v1/' ),
		'nonce'          => wp_create_nonce( 'wp_rest' ),
		'userId'         => get_current_user_id(),
		'userName'       => wp_get_current_user()->display_name,
		'audio'          => $agentic_skills_chat_features['audio'],
		'vision'         => $agentic_skills_chat_features['vision'],
		'costs'          => $agentic_skills_chat_features['costs'],
		'tts'            => $agentic_skills_chat_features['tts'],
		'ttsVoice'       => get_option( 'agent_builder_tts_voice', 'journey-f' ),
		'consentEnabled' => get_option( 'agent_builder_chat_consent_enabled', false ) ? '1' : '0',
		'consentText'    => \Agentic\GDPR::get_consent_text(),
		'isAdmin'        => current_user_can( 'manage_options' ) ? '1' : '0',
		'adminUrl'       => admin_url(),
		'adminAgentsUrl' => admin_url( 'admin.php?page=agentic-agents' ),
		'initialMessage' => '',
		'handoffFrom'    => '',
		'handoffContext' => '',
		'slashCommands'  => \Agentic\Chat_Assets::get_slash_commands_for_js(),
		'i18n'           => agent_builder_chat_i18n(),
	)
);

// Forces chat-interface.php to skip the agent-switcher and always resolve
// to Skills Assistant, regardless of URL params, cookies, or how many other
// agents this user can access.
$agentic_force_agent = $agentic_skills_assistant_slug;
include AGENT_BUILDER_DIR . 'templates/chat-interface.php';
