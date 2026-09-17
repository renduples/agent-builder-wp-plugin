<?php
/**
 * Publish — Basic view
 *
 * Instead of the 8 technical Deployment tabs, Basic mode embeds a chat with
 * the bundled Agent Orchestrator, which has real tools to do everything
 * those tabs do — shortcodes, scheduled tasks, event listeners, admin-bar
 * launchers, editor sidebar, frontend modal, Gutenberg blocks, and CLI
 * settings — conversationally.
 *
 * Included by admin/deployment.php — do not load directly.
 *
 * @package    Agent_Builder
 * @subpackage Admin
 * @since      3.3.67
 *
 * php version 8.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$agentic_orchestrator_slug = 'agent-orchestrator';
$agentic_orchestrator      = \Agentic_Agent_Registry::get_instance()->get_agent_instance( $agentic_orchestrator_slug );

if ( ! $agentic_orchestrator ) {
	?>
	<div class="notice notice-info inline">
		<p>
			<?php esc_html_e( 'The Agent Orchestrator is still activating.', 'agent-builder' ); ?>
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
	AGENT_BUILDER_VERSION,
	true
);

$agentic_basic_chat_features = agent_builder_get_effective_chat_features( $agentic_orchestrator_slug );

wp_localize_script(
	'agentic-chat',
	'agenticChat',
	array(
		'restUrl'        => rest_url( 'agentic/v1/' ),
		'nonce'          => wp_create_nonce( 'wp_rest' ),
		'userId'         => get_current_user_id(),
		'userName'       => wp_get_current_user()->display_name,
		'audio'          => $agentic_basic_chat_features['audio'],
		'vision'         => $agentic_basic_chat_features['vision'],
		'costs'          => $agentic_basic_chat_features['costs'],
		'tts'            => $agentic_basic_chat_features['tts'],
		'ttsVoice'       => get_option( 'agentic_tts_voice', 'journey-f' ),
		'consentEnabled' => get_option( 'agentic_chat_consent_enabled', false ) ? '1' : '0',
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
// to Agent Orchestrator, regardless of URL params, cookies, or how many
// other agents this user can access.
$agentic_force_agent = $agentic_orchestrator_slug;
include AGENT_BUILDER_DIR . 'templates/chat-interface.php';
