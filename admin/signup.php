<?php
/**
 * Agentic AI sign-up page.
 *
 * @package Agent_Builder
 * @since   2.9.8
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'You do not have permission to access this page.', 'agentstructor' ) );
}

$agentic_signup_site_url = home_url();
$agentic_signup_name     = get_bloginfo( 'name' );
$agentic_signup_email    = get_option( 'admin_email', '' );
$agentic_signup_version  = AGENT_BUILDER_VERSION;
$agentic_signup_nonce    = wp_create_nonce( 'agentic_signup' );
$agentic_pricing_url     = \Agentic\Service_Registry::url( 'agentic-api' ) . '/wp-json/agentic/v1/model-pricing';

// Prefer the address this site already registered with, so reconnecting
// returns the existing key and its license instead of opening a new account.
$agentic_hosted_email = (string) get_option( 'agent_builder_hosted_account_email', '' );
if ( '' !== $agentic_hosted_email ) {
	$agentic_signup_email = $agentic_hosted_email;
}

$agentic_license_missing = \Agentic\Provider_Registry::hosted_license_missing();

// Fallback lists rendered before the endpoint responds (short labels, 1-2 words).
$agentic_fallback_llm_models   = array(
	'gemini-2.5-flash'      => 'Flash',
	'gemini-2.5-flash-lite' => 'Flash Lite',
	'gemini-2.5-pro'        => 'Pro',
);
$agentic_fallback_video_models = array(
	'veo-2' => 'Veo 2',
	'veo-3' => 'Veo 3',
);
$agentic_signup_tts_voices     = array(
	'journey-f'  => 'Journey Female',
	'journey-d'  => 'Journey Male',
	'journey-o'  => 'Journey Neutral',
	'neural2-c'  => 'Neural2 Female',
	'neural2-d'  => 'Neural2 Male',
	'standard-f' => 'Standard Female',
	'standard-b' => 'Standard Male',
);
?>
<div class="agentic-signup-wrap">
	<div class="agentic-signup-card">
		<div class="agentic-logo">
			<img src="<?php echo esc_url( AGENT_BUILDER_URL . 'assets/icon.svg' ); ?>" alt="<?php esc_attr_e( 'AgentStructor', 'agentstructor' ); ?>">
			<span>AgentStructor</span>
		</div>

		<h1><?php esc_html_e( 'Connect to Agentic AI', 'agentstructor' ); ?></h1>
		<p class="subtitle">
			<?php esc_html_e( 'Connect AgentStructor to an AI service with just one click.', 'agentstructor' ); ?>
			<br><?php esc_html_e( 'Free daily credits, no payment info required.', 'agentstructor' ); ?>
		</p>

		<?php if ( $agentic_license_missing ) : ?>
			<div class="notice notice-warning inline" id="agentic-license-missing-notice">
				<p>
					<strong><?php esc_html_e( 'Your Agentic AI connection is missing its license key.', 'agentstructor' ); ?></strong>
					<?php esc_html_e( 'This site has an API key but no license key, so the hosted provider cannot be used yet. Connect again with the same email address and the existing key will be re-issued together with its license.', 'agentstructor' ); ?>
				</p>
			</div>
		<?php endif; ?>

		<form id="agentic-signup-form" method="post">
			<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $agentic_signup_nonce ); ?>">
			<input type="hidden" name="plugin_version" value="<?php echo esc_attr( $agentic_signup_version ); ?>">

			<!-- ── Registration ─────────────────────────────── -->
			<h3 class="agentic-signup-section-heading"><?php esc_html_e( 'Registration', 'agentstructor' ); ?></h3>

			<div class="agentic-signup-field" id="agentic-signup-email-field">
				<label for="agentic-signup-email"><?php esc_html_e( 'Email Address', 'agentstructor' ); ?></label>
				<input type="email" id="agentic-signup-email" name="email" value="<?php echo esc_attr( $agentic_signup_email ); ?>">
				<p class="agentic-field-note"><?php esc_html_e( 'Free daily credits and API key will be sent to this email address.', 'agentstructor' ); ?></p>
				<p class="agentic-inline-error" id="agentic-email-error" role="alert" hidden></p>
			</div>

			<div class="agentic-signup-field">
				<label for="agentic-signup-sitename"><?php esc_html_e( 'Site Name', 'agentstructor' ); ?></label>
				<input type="text" id="agentic-signup-sitename" name="site_name" value="<?php echo esc_attr( $agentic_signup_name ); ?>">
			</div>

			<div class="agentic-signup-field">
				<label for="agentic-signup-siteurl"><?php esc_html_e( 'Site URL', 'agentstructor' ); ?></label>
				<input type="url" id="agentic-signup-siteurl" name="site_url" value="<?php echo esc_attr( $agentic_signup_site_url ); ?>">
				<p class="agentic-field-note"><?php esc_html_e( 'Set this to the production domain you are registering for.', 'agentstructor' ); ?></p>
			</div>

			<!-- ── Configuration ────────────────────────────── -->
			<h3 class="agentic-signup-section-heading">
				<?php esc_html_e( 'Configuration', 'agentstructor' ); ?>
				<button type="button" id="agentic-reset-defaults" class="agentic-reset-btn"><?php esc_html_e( 'Reset', 'agentstructor' ); ?></button>
			</h3>

			<div class="agentic-config-grid">
				<div class="agentic-config-col-head"><?php esc_html_e( 'Setting', 'agentstructor' ); ?></div>
				<div class="agentic-config-col-head"><?php esc_html_e( 'Explanation', 'agentstructor' ); ?></div>

				<!-- Security Mode -->
				<div class="agentic-config-cell agentic-config-setting">
					<label for="agentic-signup-agent-mode"><?php esc_html_e( 'Security Mode', 'agentstructor' ); ?></label>
					<select id="agentic-signup-agent-mode" name="agent_mode">
						<option value="supervised" selected><?php esc_html_e( 'Supervised', 'agentstructor' ); ?></option>
						<option value="autonomous"><?php esc_html_e( 'Autonomous', 'agentstructor' ); ?></option>
						<option value="disabled"><?php esc_html_e( 'Disabled', 'agentstructor' ); ?></option>
					</select>
				</div>
				<div class="agentic-config-cell agentic-config-explanation" id="agentic-explain-mode"></div>

				<!-- Interface Mode -->
				<div class="agentic-config-cell agentic-config-setting">
					<label for="agentic-signup-ui-mode"><?php esc_html_e( 'How would you like AgentStructor to work?', 'agentstructor' ); ?></label>
					<select id="agentic-signup-ui-mode" name="ui_mode">
						<option value="basic" selected><?php esc_html_e( 'Basic', 'agentstructor' ); ?></option>
						<option value="advanced"><?php esc_html_e( 'Advanced', 'agentstructor' ); ?></option>
					</select>
				</div>
				<div class="agentic-config-cell agentic-config-explanation" id="agentic-explain-ui-mode"></div>

				<!-- Chat Model -->
				<div class="agentic-config-cell agentic-config-setting">
					<label for="agentic-signup-model">
						<?php esc_html_e( 'Chat Model', 'agentstructor' ); ?>
						<span id="agentic-models-loading" class="agentic-model-loading-badge"><?php esc_html_e( 'Loading…', 'agentstructor' ); ?></span>
					</label>
					<select id="agentic-signup-model" name="chat_model">
						<?php foreach ( $agentic_fallback_llm_models as $agentic_m_id => $agentic_m_label ) : ?>
						<option value="<?php echo esc_attr( $agentic_m_id ); ?>"><?php echo esc_html( $agentic_m_label ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="agentic-config-cell agentic-config-explanation" id="agentic-explain-chat"></div>

				<!-- Vision Model -->
				<div class="agentic-config-cell agentic-config-setting">
					<label for="agentic-signup-vision-model"><?php esc_html_e( 'Vision Model', 'agentstructor' ); ?></label>
					<select id="agentic-signup-vision-model" name="vision_model">
						<?php foreach ( $agentic_fallback_llm_models as $agentic_m_id => $agentic_m_label ) : ?>
						<option value="<?php echo esc_attr( $agentic_m_id ); ?>"><?php echo esc_html( $agentic_m_label ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="agentic-config-cell agentic-config-explanation" id="agentic-explain-vision"></div>

				<!-- TTS Voice -->
				<div class="agentic-config-cell agentic-config-setting">
					<label for="agentic-signup-tts-voice"><?php esc_html_e( 'Voice', 'agentstructor' ); ?></label>
					<select id="agentic-signup-tts-voice" name="tts_voice">
						<?php foreach ( $agentic_signup_tts_voices as $agentic_voice_id => $agentic_voice_label ) : ?>
						<option value="<?php echo esc_attr( $agentic_voice_id ); ?>"><?php echo esc_html( $agentic_voice_label ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="agentic-config-cell agentic-config-explanation" id="agentic-explain-tts"></div>

				<!-- Video Model -->
				<div class="agentic-config-cell agentic-config-setting">
					<label for="agentic-signup-video-model"><?php esc_html_e( 'Video Model', 'agentstructor' ); ?></label>
					<select id="agentic-signup-video-model" name="video_model">
						<?php foreach ( $agentic_fallback_video_models as $agentic_v_id => $agentic_v_label ) : ?>
						<option value="<?php echo esc_attr( $agentic_v_id ); ?>"><?php echo esc_html( $agentic_v_label ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="agentic-config-cell agentic-config-explanation" id="agentic-explain-video"></div>
			</div>

			<p class="agentic-field-note agentic-credits-note" id="agentic-credits-note">
				<strong><?php esc_html_e( 'What are credits?', 'agentstructor' ); ?></strong>
				<?php esc_html_e( 'Agentic AI measures usage in credits ("cr"); 1 credit is US$0.01. Your free daily quota is used first. After that, each request uses credits at the top-up rate shown for the model or voice you pick. The Credits light in the Chat header tells you when credits are running low or have run out.', 'agentstructor' ); ?>
			</p>

			<!-- ── Consent ──────────────────────────────────── -->
			<div class="agentic-signup-consent" id="agentic-signup-consent">
				<label>
					<input type="checkbox" id="agentic-agree-terms" name="agree_terms" value="1">
					<?php
					printf(
						/* translators: 1: opening link tag, 2: closing link tag */
						esc_html__( 'I agree to the %1$sTerms of Service%2$s', 'agentstructor' ),
						'<a href="https://agentic-plugin.com/terms-of-service/" target="_blank" rel="noopener">',
						'</a>'
					);
					?>
				</label>
				<label>
					<input type="checkbox" id="agentic-agree-privacy" name="agree_privacy" value="1">
					<?php
					printf(
						/* translators: 1: opening link tag, 2: closing link tag */
						esc_html__( 'I agree to the %1$sPrivacy Policy%2$s', 'agentstructor' ),
						'<a href="https://agentic-plugin.com/privacy-policy/" target="_blank" rel="noopener">',
						'</a>'
					);
					?>
				</label>
				<div class="notice notice-error inline agentic-consent-error" id="agentic-consent-error" role="alert" hidden>
					<p id="agentic-consent-error-text"></p>
				</div>
			</div>

			<div class="agentic-signup-actions">
				<button type="submit" id="agentic-signup-btn" class="button button-primary">
					<?php esc_html_e( 'Get Free API Key', 'agentstructor' ); ?>
				</button>
			</div>

			<div class="agentic-signup-msg" id="agentic-signup-msg"></div>
		</form>

		<div class="agentic-signup-skip">
			<?php
			printf(
				/* translators: 1: opening link tag, 2: closing link tag */
				esc_html__( '%1$sSkip — I will pay for tokens with my own AI provider.%2$s', 'agentstructor' ),
				'<a href="' . esc_url( admin_url( 'admin.php?page=agentic-setup' ) ) . '">',
				'</a>'
			);
			?>
			<br><small><?php esc_html_e( '(xAI, OpenAI, Anthropic etc.)', 'agentstructor' ); ?></small>
		</div>
	</div>

	<div class="agentic-signup-footer">
		<?php esc_html_e( 'AgentStructor supports multiple AI providers. You can add as many as you like later.', 'agentstructor' ); ?>
	</div>
</div>

<?php
\Agentic\Inline_Assets::script(
	'signup',
	array(
		'pricingUrl'   => $agentic_pricing_url,
		'dashboardUrl' => admin_url( 'admin.php?page=agent-builder' ),
		'i18n'         => array(
			'modeSupervised'     => __( 'Supervised', 'agentstructor' ),
			'modeSupervisedBody' => __( 'Agents propose actions which are queued for your review before they run. Recommended for most sites.', 'agentstructor' ),
			'modeAutonomous'     => __( 'Autonomous', 'agentstructor' ),
			'modeAutonomousBody' => __( 'Agents act immediately without waiting for approval. Use only on sites where you fully trust the agents.', 'agentstructor' ),
			'modeDisabled'       => __( 'Disabled', 'agentstructor' ),
			'modeDisabledBody'   => __( 'Chat only — all tools and site actions are disabled. Agents cannot modify content or call external services.', 'agentstructor' ),
			'uiBasic'            => __( 'Basic', 'agentstructor' ),
			'uiBasicBody'        => __( 'Guided, plain language, best for getting started', 'agentstructor' ),
			'uiAdvanced'         => __( 'Advanced', 'agentstructor' ),
			'uiAdvancedBody'     => __( 'Full console, best if you\'ve used developer tools like this before', 'agentstructor' ),
			'otherModels'        => __( 'Other models', 'agentstructor' ),
			'llmFlash'           => __( 'Fast and capable. Best balance of speed and quality for most tasks.', 'agentstructor' ),
			'llmFlashLite'       => __( 'Fastest and most efficient. Ideal for simple, high-volume queries.', 'agentstructor' ),
			'llmPro'             => __( 'Most powerful. Best for complex reasoning and demanding tasks.', 'agentstructor' ),
			'llmGemma'           => __( 'Lightweight open model. Very efficient and low credit cost.', 'agentstructor' ),
			'voiceNeural'        => __( 'Neural voice. Natural, expressive, and highly lifelike.', 'agentstructor' ),
			'voiceNeutral'       => __( 'Neural voice. Gender-neutral, natural-sounding speech.', 'agentstructor' ),
			'voiceNeural2'       => __( 'High-quality neural voice. Clear and professional.', 'agentstructor' ),
			'voiceStandard'      => __( 'Standard synthesised voice. Lightweight and low credit usage.', 'agentstructor' ),
			'videoVeo2'          => __( 'AI video generation, visual only. Reliable and efficient.', 'agentstructor' ),
			'videoVeo3'          => __( 'AI video with native audio. Higher quality, higher credit cost.', 'agentstructor' ),
			'quotaNone'          => __( 'Free daily quota: not included in the free plan', 'agentstructor' ),
			'consentRequired'    => __( 'Please agree to the Terms of Service and Privacy Policy.', 'agentstructor' ),
			'emailRequired'      => __( 'Please enter your email address.', 'agentstructor' ),
			'registering'        => __( 'Registering…', 'agentstructor' ),
			'keySaved'           => __( 'API key saved — redirecting…', 'agentstructor' ),
			'getFreeKey'         => __( 'Get Free API Key', 'agentstructor' ),
			'registrationFailed' => __( 'Registration failed. Please try again.', 'agentstructor' ),
			'connectionError'    => __( 'Connection error. Please try again.', 'agentstructor' ),
		),
	),
	'agenticSignupPage'
);
?>
