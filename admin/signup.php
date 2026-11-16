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
	wp_die( esc_html__( 'You do not have permission to access this page.', 'agent-builder' ) );
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
			<img src="<?php echo esc_url( AGENT_BUILDER_URL . 'assets/icon.svg' ); ?>" alt="<?php esc_attr_e( 'Agent Builder', 'agent-builder' ); ?>">
			<span>Agent Builder</span>
		</div>

		<h1><?php esc_html_e( 'Connect to Agentic AI', 'agent-builder' ); ?></h1>
		<p class="subtitle">
			<?php esc_html_e( 'Connect Agent Builder to an AI service with just one click.', 'agent-builder' ); ?>
			<br><?php esc_html_e( 'Free daily credits, no payment info required.', 'agent-builder' ); ?>
		</p>

		<?php if ( $agentic_license_missing ) : ?>
			<div class="notice notice-warning inline" id="agentic-license-missing-notice">
				<p>
					<strong><?php esc_html_e( 'Your Agentic AI connection is missing its license key.', 'agent-builder' ); ?></strong>
					<?php esc_html_e( 'This site has an API key but no license key, so the hosted provider cannot be used yet. Connect again with the same email address and the existing key will be re-issued together with its license.', 'agent-builder' ); ?>
				</p>
			</div>
		<?php endif; ?>

		<form id="agentic-signup-form" method="post">
			<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $agentic_signup_nonce ); ?>">
			<input type="hidden" name="plugin_version" value="<?php echo esc_attr( $agentic_signup_version ); ?>">

			<!-- ── Registration ─────────────────────────────── -->
			<h3 class="agentic-signup-section-heading"><?php esc_html_e( 'Registration', 'agent-builder' ); ?></h3>

			<div class="agentic-signup-field" id="agentic-signup-email-field">
				<label for="agentic-signup-email"><?php esc_html_e( 'Email Address', 'agent-builder' ); ?></label>
				<input type="email" id="agentic-signup-email" name="email" value="<?php echo esc_attr( $agentic_signup_email ); ?>">
				<p class="agentic-field-note"><?php esc_html_e( 'Free daily credits and API key will be sent to this email address.', 'agent-builder' ); ?></p>
				<p class="agentic-inline-error" id="agentic-email-error" role="alert" hidden></p>
			</div>

			<div class="agentic-signup-field">
				<label for="agentic-signup-sitename"><?php esc_html_e( 'Site Name', 'agent-builder' ); ?></label>
				<input type="text" id="agentic-signup-sitename" name="site_name" value="<?php echo esc_attr( $agentic_signup_name ); ?>">
			</div>

			<div class="agentic-signup-field">
				<label for="agentic-signup-siteurl"><?php esc_html_e( 'Site URL', 'agent-builder' ); ?></label>
				<input type="url" id="agentic-signup-siteurl" name="site_url" value="<?php echo esc_attr( $agentic_signup_site_url ); ?>">
				<p class="agentic-field-note"><?php esc_html_e( 'Set this to the production domain you are registering for.', 'agent-builder' ); ?></p>
			</div>

			<!-- ── Configuration ────────────────────────────── -->
			<h3 class="agentic-signup-section-heading">
				<?php esc_html_e( 'Configuration', 'agent-builder' ); ?>
				<button type="button" id="agentic-reset-defaults" class="agentic-reset-btn"><?php esc_html_e( 'Reset', 'agent-builder' ); ?></button>
			</h3>

			<div class="agentic-config-grid">
				<div class="agentic-config-col-head"><?php esc_html_e( 'Setting', 'agent-builder' ); ?></div>
				<div class="agentic-config-col-head"><?php esc_html_e( 'Explanation', 'agent-builder' ); ?></div>

				<!-- Security Mode -->
				<div class="agentic-config-cell agentic-config-setting">
					<label for="agentic-signup-agent-mode"><?php esc_html_e( 'Security Mode', 'agent-builder' ); ?></label>
					<select id="agentic-signup-agent-mode" name="agent_mode">
						<option value="supervised" selected><?php esc_html_e( 'Supervised', 'agent-builder' ); ?></option>
						<option value="autonomous"><?php esc_html_e( 'Autonomous', 'agent-builder' ); ?></option>
						<option value="disabled"><?php esc_html_e( 'Disabled', 'agent-builder' ); ?></option>
					</select>
				</div>
				<div class="agentic-config-cell agentic-config-explanation" id="agentic-explain-mode"></div>

				<!-- Interface Mode -->
				<div class="agentic-config-cell agentic-config-setting">
					<label for="agentic-signup-ui-mode"><?php esc_html_e( 'How would you like Agent Builder to work?', 'agent-builder' ); ?></label>
					<select id="agentic-signup-ui-mode" name="ui_mode">
						<option value="basic" selected><?php esc_html_e( 'Basic', 'agent-builder' ); ?></option>
						<option value="advanced"><?php esc_html_e( 'Advanced', 'agent-builder' ); ?></option>
					</select>
				</div>
				<div class="agentic-config-cell agentic-config-explanation" id="agentic-explain-ui-mode"></div>

				<!-- Chat Model -->
				<div class="agentic-config-cell agentic-config-setting">
					<label for="agentic-signup-model">
						<?php esc_html_e( 'Chat Model', 'agent-builder' ); ?>
						<span id="agentic-models-loading" class="agentic-model-loading-badge"><?php esc_html_e( 'Loading…', 'agent-builder' ); ?></span>
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
					<label for="agentic-signup-vision-model"><?php esc_html_e( 'Vision Model', 'agent-builder' ); ?></label>
					<select id="agentic-signup-vision-model" name="vision_model">
						<?php foreach ( $agentic_fallback_llm_models as $agentic_m_id => $agentic_m_label ) : ?>
						<option value="<?php echo esc_attr( $agentic_m_id ); ?>"><?php echo esc_html( $agentic_m_label ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="agentic-config-cell agentic-config-explanation" id="agentic-explain-vision"></div>

				<!-- TTS Voice -->
				<div class="agentic-config-cell agentic-config-setting">
					<label for="agentic-signup-tts-voice"><?php esc_html_e( 'Voice', 'agent-builder' ); ?></label>
					<select id="agentic-signup-tts-voice" name="tts_voice">
						<?php foreach ( $agentic_signup_tts_voices as $agentic_voice_id => $agentic_voice_label ) : ?>
						<option value="<?php echo esc_attr( $agentic_voice_id ); ?>"><?php echo esc_html( $agentic_voice_label ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="agentic-config-cell agentic-config-explanation" id="agentic-explain-tts"></div>

				<!-- Video Model -->
				<div class="agentic-config-cell agentic-config-setting">
					<label for="agentic-signup-video-model"><?php esc_html_e( 'Video Model', 'agent-builder' ); ?></label>
					<select id="agentic-signup-video-model" name="video_model">
						<?php foreach ( $agentic_fallback_video_models as $agentic_v_id => $agentic_v_label ) : ?>
						<option value="<?php echo esc_attr( $agentic_v_id ); ?>"><?php echo esc_html( $agentic_v_label ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="agentic-config-cell agentic-config-explanation" id="agentic-explain-video"></div>
			</div>

			<p class="agentic-field-note agentic-credits-note" id="agentic-credits-note">
				<strong><?php esc_html_e( 'What are credits?', 'agent-builder' ); ?></strong>
				<?php esc_html_e( 'Agentic AI measures usage in credits ("cr"); 1 credit is US$0.01. Your free daily quota is used first. After that, each request uses credits at the top-up rate shown for the model or voice you pick. The Credits light in the Chat header tells you when credits are running low or have run out.', 'agent-builder' ); ?>
			</p>

			<!-- ── Consent ──────────────────────────────────── -->
			<div class="agentic-signup-consent" id="agentic-signup-consent">
				<label>
					<input type="checkbox" id="agentic-agree-terms" name="agree_terms" value="1">
					<?php
					printf(
						/* translators: 1: opening link tag, 2: closing link tag */
						esc_html__( 'I agree to the %1$sTerms of Service%2$s', 'agent-builder' ),
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
						esc_html__( 'I agree to the %1$sPrivacy Policy%2$s', 'agent-builder' ),
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
					<?php esc_html_e( 'Get Free API Key', 'agent-builder' ); ?>
				</button>
			</div>

			<div class="agentic-signup-msg" id="agentic-signup-msg"></div>
		</form>

		<div class="agentic-signup-skip">
			<?php
			printf(
				/* translators: 1: opening link tag, 2: closing link tag */
				esc_html__( '%1$sSkip — I will pay for tokens with my own AI provider.%2$s', 'agent-builder' ),
				'<a href="' . esc_url( admin_url( 'admin.php?page=agentic-setup' ) ) . '">',
				'</a>'
			);
			?>
			<br><small><?php esc_html_e( '(xAI, OpenAI, Anthropic etc.)', 'agent-builder' ); ?></small>
		</div>
	</div>

	<div class="agentic-signup-footer">
		<?php esc_html_e( 'Agent Builder supports multiple AI providers. You can add as many as you like later.', 'agent-builder' ); ?>
	</div>
</div>

<?php
\Agentic\Inline_Assets::script(
	'signup',
	array(
		'pricingUrl'   => $agentic_pricing_url,
		'dashboardUrl' => admin_url( 'admin.php?page=agent-builder' ),
		'i18n'         => array(
			'modeSupervised'     => __( 'Supervised', 'agent-builder' ),
			'modeSupervisedBody' => __( 'Agents propose actions which are queued for your review before they run. Recommended for most sites.', 'agent-builder' ),
			'modeAutonomous'     => __( 'Autonomous', 'agent-builder' ),
			'modeAutonomousBody' => __( 'Agents act immediately without waiting for approval. Use only on sites where you fully trust the agents.', 'agent-builder' ),
			'modeDisabled'       => __( 'Disabled', 'agent-builder' ),
			'modeDisabledBody'   => __( 'Chat only — all tools and site actions are disabled. Agents cannot modify content or call external services.', 'agent-builder' ),
			'uiBasic'            => __( 'Basic', 'agent-builder' ),
			'uiBasicBody'        => __( 'Guided, plain language, best for getting started', 'agent-builder' ),
			'uiAdvanced'         => __( 'Advanced', 'agent-builder' ),
			'uiAdvancedBody'     => __( 'Full console, best if you\'ve used developer tools like this before', 'agent-builder' ),
			'otherModels'        => __( 'Other models', 'agent-builder' ),
			'llmFlash'           => __( 'Fast and capable. Best balance of speed and quality for most tasks.', 'agent-builder' ),
			'llmFlashLite'       => __( 'Fastest and most efficient. Ideal for simple, high-volume queries.', 'agent-builder' ),
			'llmPro'             => __( 'Most powerful. Best for complex reasoning and demanding tasks.', 'agent-builder' ),
			'llmGemma'           => __( 'Lightweight open model. Very efficient and low credit cost.', 'agent-builder' ),
			'voiceNeural'        => __( 'Neural voice. Natural, expressive, and highly lifelike.', 'agent-builder' ),
			'voiceNeutral'       => __( 'Neural voice. Gender-neutral, natural-sounding speech.', 'agent-builder' ),
			'voiceNeural2'       => __( 'High-quality neural voice. Clear and professional.', 'agent-builder' ),
			'voiceStandard'      => __( 'Standard synthesised voice. Lightweight and low credit usage.', 'agent-builder' ),
			'videoVeo2'          => __( 'AI video generation, visual only. Reliable and efficient.', 'agent-builder' ),
			'videoVeo3'          => __( 'AI video with native audio. Higher quality, higher credit cost.', 'agent-builder' ),
			'quotaNone'          => __( 'Free daily quota: not included in the free plan', 'agent-builder' ),
			'consentRequired'    => __( 'Please agree to the Terms of Service and Privacy Policy.', 'agent-builder' ),
			'emailRequired'      => __( 'Please enter your email address.', 'agent-builder' ),
			'registering'        => __( 'Registering…', 'agent-builder' ),
			'keySaved'           => __( 'API key saved — redirecting…', 'agent-builder' ),
			'getFreeKey'         => __( 'Get Free API Key', 'agent-builder' ),
			'registrationFailed' => __( 'Registration failed. Please try again.', 'agent-builder' ),
			'connectionError'    => __( 'Connection error. Please try again.', 'agent-builder' ),
		),
	),
	'agenticSignupPage'
);
?>
