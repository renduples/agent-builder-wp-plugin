<?php
/**
 * Onboarding Setup Wizard
 *
 * Shown once after plugin activation until the user connects an AI provider
 * or explicitly skips. Accessed at admin.php?page=agentic-setup.
 *
 * @package AgentBuilder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Handle form submission ────────────────────────────────────────────────────

if ( isset( $_POST['agentic_setup_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['agentic_setup_nonce'] ) ), 'agentic_setup' ) ) {
	$agentic_provider = sanitize_text_field( wp_unslash( $_POST['agentic_llm_provider'] ?? '' ) );
	$agentic_api_key  = sanitize_text_field( wp_unslash( $_POST['agentic_llm_api_key'] ?? '' ) );

	$agentic_allowed_providers = \Agentic\Provider_Registry::get_slugs();
	$agentic_no_key_providers  = \Agentic\Provider_Registry::get_no_key_slugs();

	if ( in_array( $agentic_provider, $agentic_allowed_providers, true ) && ( ! empty( $agentic_api_key ) || in_array( $agentic_provider, $agentic_no_key_providers, true ) ) ) {
		$agentic_reg_entry        = \Agentic\Provider_Registry::get( $agentic_provider );
		$agentic_provider_default = $agentic_reg_entry['default_model'] ?? 'gpt-4o';

		\Agentic\Provider_Registry::save_api_key( $agentic_provider, $agentic_api_key );
		update_option( 'agentic_llm_provider', $agentic_provider );
		update_option( 'agentic_model', $agentic_provider_default );
		update_option( 'agentic_onboarding_complete', true );

		$agentic_setup_audit = new \Agentic\Audit_Log();
		$agentic_setup_audit->log(
			'system',
			'settings_changed',
			'setup',
			array(
				'setting' => 'initial_setup',
				'changes' => array( 'provider' => $agentic_provider ),
			)
		);

		$agentic_redirect_url = admin_url( 'admin.php?page=agentic-chat&onboarding=1' );
		echo '<script>window.location.href=' . wp_json_encode( $agentic_redirect_url ) . ';</script>';
		exit;
	}
}

if ( isset( $_GET['skip_setup'] ) && check_admin_referer( 'agentic_skip_setup' ) ) {
	update_option( 'agentic_onboarding_complete', true );
	$agentic_redirect_url = admin_url( 'admin.php?page=agent-builder' );
	echo '<script>window.location.href=' . wp_json_encode( $agentic_redirect_url ) . ';</script>';
	exit;
}

// ── Provider data ─────────────────────────────────────────────────────────────
//
// `name`, `icon`, and `key_url` come from Provider_Registry (single source of truth).
// Wizard-only display fields (badge, signup_url, steps, etc.) are kept here since they
// are onboarding presentation content, not operational provider config.

$agentic_wizard_meta = array(
	'agentic'   => array(
		'badge'       => 'Free',
		'badge_class' => 'badge-free',
		'signup_url'  => 'https://agentic-plugin.com',
		'key_label'   => '',
		'placeholder' => '',
		'steps'       => array(
			array(
				'text'       => 'Enter your email address below to create a free Agentic AI account. You\'ll receive a unique API key tied to this site.',
				'screenshot' => '',
				'alt'        => '',
			),
		),
	),
	'xai'       => array(
		'badge'       => '$25 free credits',
		'badge_class' => 'badge-free',
		'signup_url'  => 'https://accounts.x.ai/sign-up',
		'key_label'   => '',
		'placeholder' => 'xai-…',
		'steps'       => array(
			array(
				'text'       => 'Create your account at accounts.x.ai/sign-up.',
				'screenshot' => 'https://agentic-plugin.com/wp-content/uploads/2026/02/xai-sign-up.png',
				'alt'        => 'xAI sign-up page',
			),
			array(
				'text'       => 'Once logged in, go to console.x.ai → API Keys → Create API Key. Copy the key.',
				'screenshot' => 'https://agentic-plugin.com/wp-content/uploads/2026/02/xai-create-key.png',
				'alt'        => 'xAI create API key',
			),
		),
	),
	'openai'    => array(
		'badge'       => 'Pay-as-you-go',
		'badge_class' => 'badge-paid',
		'signup_url'  => 'https://auth.openai.com/create-account',
		'key_label'   => '',
		'credit_note' => 'Note: you may need to add a payment method before the API will work.',
		'placeholder' => 'sk-…',
		'steps'       => array(
			array(
				'text'       => 'Create your account at auth.openai.com.',
				'screenshot' => 'https://agentic-plugin.com/wp-content/uploads/2026/02/openai-sign-up.png',
				'alt'        => 'OpenAI sign-up page',
			),
			array(
				'text'       => 'Confirm your age when prompted.',
				'screenshot' => 'https://agentic-plugin.com/wp-content/uploads/2026/02/openai-confirm-age.png',
				'alt'        => 'OpenAI confirm age',
			),
			array(
				'text'       => 'Go to platform.openai.com → API keys → Create new secret key. Copy it.',
				'screenshot' => 'https://agentic-plugin.com/wp-content/uploads/2026/02/openai-create-key.png',
				'alt'        => 'OpenAI create API key',
			),
		),
	),
	'google'    => array(
		'badge'       => 'Free tier available',
		'badge_class' => 'badge-free',
		'signup_url'  => 'https://aistudio.google.com',
		'key_label'   => '',
		'placeholder' => 'AIza…',
		'steps'       => array(
			array(
				'text'       => 'Go to aistudio.google.com, click Get Started, and sign in with your Google account. Accept the Terms of Service.',
				'screenshot' => 'https://agentic-plugin.com/wp-content/uploads/2026/02/gemini-sign-up.png',
				'alt'        => 'Google AI Studio sign-up',
			),
			array(
				'text'       => 'Click Get API key in the left sidebar → Create API key. Copy the key.',
				'screenshot' => 'https://agentic-plugin.com/wp-content/uploads/2026/02/gemini-create-key.png',
				'alt'        => 'Google AI Studio create key',
			),
		),
	),
	'anthropic' => array(
		'badge'       => 'Pay-as-you-go',
		'badge_class' => 'badge-paid',
		'signup_url'  => 'https://platform.claude.com/login?returnTo=%2F%3F',
		'key_label'   => '',
		'credit_note' => 'Note: you will need to add credits (minimum $5) before the API will work.',
		'placeholder' => 'sk-ant-…',
		'steps'       => array(
			array(
				'text'       => 'Create your account at platform.claude.com.',
				'screenshot' => 'https://agentic-plugin.com/wp-content/uploads/2026/02/claude-sign-up.png',
				'alt'        => 'Anthropic sign-up page',
			),
			array(
				'text'       => 'Confirm your age when prompted.',
				'screenshot' => 'https://agentic-plugin.com/wp-content/uploads/2026/02/claude-confirm-age.png',
				'alt'        => 'Anthropic confirm age',
			),
			array(
				'text'       => 'Choose your account type (Personal or API).',
				'screenshot' => 'https://agentic-plugin.com/wp-content/uploads/2026/02/claude-choose-account.png',
				'alt'        => 'Anthropic choose account type',
			),
			array(
				'text'       => 'Go to Settings → API Keys → Create Key. Copy it.',
				'screenshot' => 'https://agentic-plugin.com/wp-content/uploads/2026/02/claude-create-key.png',
				'alt'        => 'Anthropic create API key',
			),
		),
	),
	'mistral'   => array(
		'badge'       => 'Pay-as-you-go',
		'badge_class' => 'badge-paid',
		'signup_url'  => 'https://console.mistral.ai',
		'key_label'   => '',
		'placeholder' => '',
		'steps'       => array(
			array(
				'text'       => 'Create an account at console.mistral.ai.',
				'screenshot' => 'https://agentic-plugin.com/wp-content/uploads/2026/02/mistral-sign-up.png',
				'alt'        => 'Mistral sign-up page',
			),
			array(
				'text'       => 'Create a team when prompted.',
				'screenshot' => 'https://agentic-plugin.com/wp-content/uploads/2026/02/mistral-create-team.png',
				'alt'        => 'Mistral create team',
			),
			array(
				'text'       => 'Go to API Keys → Create new key. Copy it.',
				'screenshot' => 'https://agentic-plugin.com/wp-content/uploads/2026/02/mistral-create-key.png',
				'alt'        => 'Mistral create API key',
			),
		),
	),
	'llama'     => array(
		'badge'       => 'Pay-as-you-go',
		'badge_class' => 'badge-paid',
		'signup_url'  => 'https://llama.meta.com',
		'key_label'   => '',
		'placeholder' => '',
		'steps'       => array(
			array(
				'text'       => 'Create an account at <a href="https://llama.meta.com" target="_blank" rel="noopener">llama.meta.com</a>.',
				'screenshot' => '',
				'alt'        => '',
			),
			array(
				'text'       => 'Go to <a href="https://llama.meta.com/docs/getting_models/api" target="_blank" rel="noopener">llama.meta.com → Getting Models → API</a> and generate your API key. Copy it.',
				'screenshot' => '',
				'alt'        => '',
			),
		),
	),
	'cohere'    => array(
		'badge'       => 'Free trial available',
		'badge_class' => 'badge-free',
		'signup_url'  => 'https://dashboard.cohere.com/welcome/register',
		'key_label'   => '',
		'placeholder' => '',
		'steps'       => array(
			array(
				'text'       => 'Create an account at <a href="https://dashboard.cohere.com/welcome/register" target="_blank" rel="noopener">dashboard.cohere.com</a>.',
				'screenshot' => '',
				'alt'        => '',
			),
			array(
				'text'       => 'Go to <a href="https://dashboard.cohere.com/api-keys" target="_blank" rel="noopener">dashboard.cohere.com/api-keys</a> and create an API key. Copy it.',
				'screenshot' => '',
				'alt'        => '',
			),
		),
	),
	'kimi'      => array(
		'badge'       => 'Pay-as-you-go',
		'badge_class' => 'badge-paid',
		'signup_url'  => 'https://platform.kimi.ai/',
		'key_label'   => '',
		'credit_note' => 'Note: Kimi K3 requires a minimum $1 top-up. China endpoint: api.moonshot.cn.',
		'placeholder' => 'sk-…',
		'steps'       => array(
			array(
				'text'       => 'Create an account at <a href="https://platform.kimi.ai/" target="_blank" rel="noopener">platform.kimi.ai</a> (Moonshot AI).',
				'screenshot' => '',
				'alt'        => '',
			),
			array(
				'text'       => 'Go to <a href="https://platform.kimi.ai/console/api-keys" target="_blank" rel="noopener">Console → API Keys</a>, create a key, and copy it.',
				'screenshot' => '',
				'alt'        => '',
			),
		),
	),
	'deepseek'  => array(
		'badge'       => 'Pay-as-you-go',
		'badge_class' => 'badge-paid',
		'signup_url'  => 'https://platform.deepseek.com/',
		'key_label'   => '',
		'placeholder' => 'sk-…',
		'steps'       => array(
			array(
				'text'       => 'Create an account at <a href="https://platform.deepseek.com/" target="_blank" rel="noopener">platform.deepseek.com</a>.',
				'screenshot' => '',
				'alt'        => '',
			),
			array(
				'text'       => 'Go to <a href="https://platform.deepseek.com/api_keys" target="_blank" rel="noopener">API Keys</a>, create a key, and copy it.',
				'screenshot' => '',
				'alt'        => '',
			),
		),
	),
	'ollama'    => array(
		'badge'       => 'Free · runs locally',
		'badge_class' => 'badge-free',
		'signup_url'  => 'https://ollama.com/download',
		'key_label'   => 'No API key needed — Ollama runs on your server',
		'placeholder' => '',
		'steps'       => array(
			array(
				'text'       => 'Download and install Ollama from ollama.com/download.',
				'screenshot' => 'https://agentic-plugin.com/wp-content/uploads/2026/02/ollama-download.png',
				'alt'        => 'Ollama download page',
			),
			array(
				'text'       => 'Install and start Ollama. Then pull a model by running this command in your terminal:<br><code>ollama pull llama3.2</code><br>Ollama will be available at <code>http://localhost:11434</code>.',
				'screenshot' => '',
				'alt'        => '',
			),
		),
	),
);

// Build $agentic_providers by merging registry data (name, icon, key_url) with wizard-only metadata.
$agentic_providers = array();
foreach ( \Agentic\Provider_Registry::get_all() as $agentic_reg_p ) {
	$agentic_pslug                       = $agentic_reg_p['slug'];
	$agentic_wmeta                       = $agentic_wizard_meta[ $agentic_pslug ] ?? array();
	$agentic_providers[ $agentic_pslug ] = array_merge(
		array(
			'name'    => $agentic_reg_p['name'],
			'icon'    => $agentic_reg_p['icon'],
			'key_url' => $agentic_reg_p['key_url'],
		),
		$agentic_wmeta
	);
}
unset( $agentic_wizard_meta, $agentic_reg_p, $agentic_pslug, $agentic_wmeta );

// ── Provider information cards ───────────────────────────────────────────────

/**
 * Build a wizard model card array from a provider's DB record.
 *
 * @param string $slug Provider slug.
 * @return array<int, array{name: string, tags: string[], recommended?: bool}>
 */
function agentic_setup_provider_models( string $slug ): array {
	$provider = \Agentic\Provider_Registry::get( $slug );
	if ( ! $provider || empty( $provider['models'] ) ) {
		$default = $provider['default_model'] ?? '';
		return $default ? array(
			array(
				'name'        => $default,
				'tags'        => array( 'Default' ),
				'recommended' => true,
			),
		) : array();
	}
	$default_model = $provider['default_model'] ?? '';
	$cards         = array();
	foreach ( $provider['models'] as $model_id ) {
		$cards[] = array(
			'name'        => $model_id,
			'tags'        => array(),
			'recommended' => ( $model_id === $default_model ),
		);
	}
	return $cards;
}

$agentic_provider_info = array(
	'xai'       => array(
		'tagline'        => 'Includes $25 free credits — no card required',
		'pricing_detail' => 'Free credits to start · then ~$3 / 1M input tokens',
		'rate_limits'    => '60 req/min (free tier)',
		'best_for'       => 'Getting started · coding · fast responses',
		'models'         => agentic_setup_provider_models( 'xai' ),
	),
	'openai'    => array(
		'tagline'        => 'Industry standard with the broadest ecosystem',
		'pricing_detail' => 'GPT-4o: $2.50 / 1M input · $10 / 1M output',
		'rate_limits'    => '500 req/min (Tier 1)',
		'best_for'       => 'Code generation · function calling · broad tooling',
		'models'         => agentic_setup_provider_models( 'openai' ),
	),
	'google'    => array(
		'tagline'        => 'Generous free tier with up to 2M token context window',
		'pricing_detail' => 'Free: 15 req/min · Flash: $0.075 / 1M tokens',
		'rate_limits'    => '15 req/min (free) · 1,000+ req/min (paid)',
		'best_for'       => 'Long documents · image analysis · low cost',
		'models'         => agentic_setup_provider_models( 'google' ),
	),
	'anthropic' => array(
		'tagline'        => 'Best for nuanced writing, analysis and complex reasoning',
		'pricing_detail' => 'Sonnet 3.5: $3 / 1M input · $15 / 1M output',
		'rate_limits'    => '50 req/min (Tier 1)',
		'best_for'       => 'Writing · analysis · complex reasoning',
		'models'         => agentic_setup_provider_models( 'anthropic' ),
	),
	'mistral'   => array(
		'tagline'        => 'Strong multilingual support, hosted in Europe',
		'pricing_detail' => 'Large: $2 / 1M input · $6 / 1M output',
		'rate_limits'    => '500 req/min',
		'best_for'       => 'Multilingual · EU data residency · cost-efficient',
		'models'         => agentic_setup_provider_models( 'mistral' ),
	),
	'llama'     => array(
		'tagline'        => 'Access Meta\'s open-source Llama models via API',
		'pricing_detail' => 'Usage-based · pay only for what you use',
		'rate_limits'    => 'Varies by plan',
		'best_for'       => 'Open-source · research · privacy-conscious devs',
		'models'         => agentic_setup_provider_models( 'llama' ),
	),
	'cohere'    => array(
		'tagline'        => 'Enterprise-ready AI with strong multilingual capabilities',
		'pricing_detail' => 'Free trial: 20 req/min · then usage-based',
		'rate_limits'    => '20 req/min (trial) · 10,000 req/min (production)',
		'best_for'       => 'Enterprise · multilingual · RAG pipelines',
		'models'         => agentic_setup_provider_models( 'cohere' ),
	),
	'kimi'      => array(
		'tagline'        => 'Moonshot AI — long context, strong coding & bilingual models',
		'pricing_detail' => 'K2.5 ~$0.60 / 1M input · K3 $3 / 1M input',
		'rate_limits'    => 'Varies by top-up tier',
		'best_for'       => 'Long context · coding · Chinese/English · agents',
		'models'         => agentic_setup_provider_models( 'kimi' ),
	),
	'deepseek'  => array(
		'tagline'        => 'Strong coding & reasoning models at aggressive prices',
		'pricing_detail' => 'Flash from ~$0.14 / 1M input · Pro for deeper reasoning',
		'rate_limits'    => 'Varies by account tier',
		'best_for'       => 'Coding · reasoning · cost-efficient agents',
		'models'         => agentic_setup_provider_models( 'deepseek' ),
	),
	'agentic'   => array(
		'tagline'        => 'A WordPress-optimised AI model hosted by agent-builder.com',
		'pricing_detail' => 'Free daily credits included',
		'rate_limits'    => 'Shared pool · requests can be slow at times',
		'best_for'       => 'Best for WordPress backend and development tasks',
		'models'         => agentic_setup_provider_models( 'agentic' ),
	),
	'ollama'    => array(
		'tagline'        => 'Run open-source models privately on your own server',
		'pricing_detail' => 'Completely free — you pay only for hardware',
		'rate_limits'    => 'Limited only by your hardware',
		'best_for'       => 'Privacy · offline use · custom models',
		'models'         => agentic_setup_provider_models( 'ollama' ),
	),
);

wp_enqueue_style( 'agentic-setup', AGENT_BUILDER_URL . 'assets/css/setup.css', array(), AGENT_BUILDER_VERSION );

?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php esc_html_e( 'Connect an AI Provider — Agent Builder', 'agent-builder' ); ?></title>
	<?php wp_head(); ?>
</head>
<body class="wp-admin">

<div class="setup-wrap">

	<!-- Header -->
	<div class="setup-header">
		<div class="setup-logo" aria-hidden="true">
			<img src="<?php echo esc_url( AGENT_BUILDER_URL . 'assets/icon.svg' ); ?>" width="48" height="48" alt="<?php esc_attr_e( 'Agent Builder logo', 'agent-builder' ); ?>">
		</div>
		<h1><?php esc_html_e( 'Welcome to Agent Builder', 'agent-builder' ); ?></h1>
		<p><?php esc_html_e( 'Connect to your preferred AI Provider to get started. This takes only a few minutes.', 'agent-builder' ); ?></p>
	</div>

	<!-- Step indicators -->
	<div class="setup-steps" id="setup-progress">
		<div class="setup-step-item active" id="progress-1">
			<div class="step-num">1</div>
			<div class="step-label"><?php esc_html_e( 'Choose provider', 'agent-builder' ); ?></div>
		</div>
		<div class="step-connector"></div>
		<div class="setup-step-item" id="progress-2">
			<div class="step-num">2</div>
			<div class="step-label"><?php esc_html_e( 'Get API key', 'agent-builder' ); ?></div>
		</div>
		<div class="step-connector"></div>
		<div class="setup-step-item" id="progress-3">
			<div class="step-num">3</div>
			<div class="step-label"><?php esc_html_e( 'Connect', 'agent-builder' ); ?></div>
		</div>
		<div class="step-connector"></div>
		<div class="setup-step-item" id="progress-4">
			<div class="step-num">4</div>
			<div class="step-label"><?php esc_html_e( 'Test', 'agent-builder' ); ?></div>
		</div>
	</div>

	<form method="post" action="" id="setup-form">
		<?php wp_nonce_field( 'agentic_setup', 'agentic_setup_nonce' ); ?>
		<input type="hidden" name="agentic_llm_provider" id="hidden_provider" value="">
		<input type="hidden" name="agentic_llm_api_key" id="hidden_api_key" value="">

		<div class="setup-card">

			<!-- ── Phase 1: Do you have an account? ─────────────────── -->
			<div class="setup-card-inner" id="phase-account">
				<div class="account-question">
					<h2><?php esc_html_e( 'Do you already have an account with an AI Provider?', 'agent-builder' ); ?></h2>
					<p><?php esc_html_e( 'At least one AI Provider is needed in order for your AI Agents to respond to requests.', 'agent-builder' ); ?></p>
					<p><strong><?php esc_html_e( 'Popular AI Providers:', 'agent-builder' ); ?></strong></p>
					<ul class="provider-list">
						<?php foreach ( $agentic_providers as $agentic_p_slug => $agentic_p ) : ?>
						<li><?php echo esc_html( $agentic_p['name'] ); ?>
							<?php
							if ( ! empty( $agentic_p['badge'] ) ) :
								?>
							<span class="provider-list-badge"><?php echo esc_html( $agentic_p['badge'] ); ?></span><?php endif; ?></li>
						<?php endforeach; ?>
					</ul>
					<div class="account-buttons">
						<button type="button" class="btn-account" onclick="showPhase('provider-pick', true)">
							<span class="btn-icon">✅</span>
							<?php esc_html_e( 'Yes, I have an account', 'agent-builder' ); ?>
						</button>
						<button type="button" class="btn-account" onclick="showPhase('provider-pick', false)">
							<span class="btn-icon">🆕</span>
							<?php esc_html_e( "No, I'll create one now", 'agent-builder' ); ?>
						</button>
					</div>
				</div>
			</div>

			<!-- ── Phase 2: Pick a provider ──────────────────────────── -->
			<div class="setup-card-inner" id="phase-provider-pick" style="display:none">
				<h2 class="agentic-setup-title" id="provider-pick-title">
					<?php esc_html_e( 'Choose your AI provider', 'agent-builder' ); ?>
				</h2>
				<p class="agentic-setup-subtitle" id="provider-pick-sub">
					<?php esc_html_e( 'Select the provider you want to connect.', 'agent-builder' ); ?>
				</p>
				<div class="provider-grid">
					<?php foreach ( $agentic_providers as $agentic_slug => $agentic_p ) : ?>
					<div class="provider-card" id="card-<?php echo esc_attr( $agentic_slug ); ?>" role="button" tabindex="0" aria-pressed="false" onclick="selectProvider('<?php echo esc_js( $agentic_slug ); ?>')" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();selectProvider('<?php echo esc_js( $agentic_slug ); ?>');}">
						<div class="provider-icon">
							<?php
							echo wp_kses(
								agentic_setup_provider_icon( $agentic_slug ),
								array(
									'svg'     => array(
										'xmlns'      => array(),
										'viewbox'    => array(),
										'fill'       => array(),
										'width'      => array(),
										'height'     => array(),
										'role'       => array(),
										'aria-label' => array(),
									),
									'circle'  => array(
										'cx'   => array(),
										'cy'   => array(),
										'r'    => array(),
										'fill' => array(),
									),
									'path'    => array(
										'd'               => array(),
										'fill'            => array(),
										'stroke'          => array(),
										'stroke-width'    => array(),
										'stroke-linecap'  => array(),
										'stroke-linejoin' => array(),
									),
									'rect'    => array(
										'width'  => array(),
										'height' => array(),
										'rx'     => array(),
										'fill'   => array(),
										'x'      => array(),
										'y'      => array(),
									),
									'ellipse' => array(
										'cx'   => array(),
										'cy'   => array(),
										'rx'   => array(),
										'ry'   => array(),
										'fill' => array(),
									),
									'polygon' => array(
										'points' => array(),
										'fill'   => array(),
									),
									'text'    => array(
										'x'           => array(),
										'y'           => array(),
										'font-size'   => array(),
										'font-weight' => array(),
										'fill'        => array(),
										'font-family' => array(),
										'dominant-baseline' => array(),
										'text-anchor' => array(),
									),
									'img'     => array(
										'src'    => array(),
										'width'  => array(),
										'height' => array(),
										'style'  => array(),
										'alt'    => array(),
									),
								)
							);
							?>
						</div>
						<div class="provider-name"><?php echo esc_html( $agentic_p['name'] ); ?></div>
						<?php if ( ! empty( $agentic_p['badge'] ) ) : ?>
							<span class="badge <?php echo esc_attr( $agentic_p['badge_class'] ); ?>"><?php echo esc_html( $agentic_p['badge'] ); ?></span>
						<?php endif; ?>
					</div>
					<?php endforeach; ?>
				</div>

				<!-- Provider info panel — revealed when a card is clicked -->
				<div class="provider-info-panel" id="provider-info-panel">
					<div class="pip-header">
						<div id="pip-icon" class="agentic-pip-icon-wrap"></div>
						<div>
							<h3 id="pip-name"></h3>
							<p id="pip-tagline"></p>
						</div>
					</div>
					<div class="pip-stats">
						<div class="pip-stat">
							<div class="pip-stat-label">Pricing</div>
							<div class="pip-stat-value" id="pip-pricing"></div>
						</div>
						<div class="pip-stat">
							<div class="pip-stat-label">Rate Limits</div>
							<div class="pip-stat-value" id="pip-rate-limits"></div>
						</div>
						<div class="pip-stat">
							<div class="pip-stat-label">Best For</div>
							<div class="pip-stat-value" id="pip-best-for"></div>
						</div>
					</div>
					<div class="pip-models">
						<div class="pip-models-label">Available Models</div>
						<div class="pip-model-list" id="pip-model-list"></div>
					</div>
				</div>
			</div>

			<!-- ── Phase 3: Per-provider instructions + key entry ─────── -->
			<?php foreach ( $agentic_providers as $agentic_slug => $agentic_p ) : ?>
			<div class="setup-card-inner provider-steps-section" id="phase-steps-<?php echo esc_attr( $agentic_slug ); ?>">
				<div class="provider-steps-header">
					<h2>
						<?php
						echo wp_kses(
							agentic_setup_provider_icon( $agentic_slug, 24 ),
							array(
								'svg'     => array(
									'xmlns'      => array(),
									'viewbox'    => array(),
									'fill'       => array(),
									'width'      => array(),
									'height'     => array(),
									'role'       => array(),
									'aria-label' => array(),
								),
								'circle'  => array(
									'cx'   => array(),
									'cy'   => array(),
									'r'    => array(),
									'fill' => array(),
								),
								'path'    => array(
									'd'               => array(),
									'fill'            => array(),
									'stroke'          => array(),
									'stroke-width'    => array(),
									'stroke-linecap'  => array(),
									'stroke-linejoin' => array(),
								),
								'rect'    => array(
									'width'  => array(),
									'height' => array(),
									'rx'     => array(),
									'fill'   => array(),
									'x'      => array(),
									'y'      => array(),
								),
								'ellipse' => array(
									'cx'   => array(),
									'cy'   => array(),
									'rx'   => array(),
									'ry'   => array(),
									'fill' => array(),
								),
								'polygon' => array(
									'points' => array(),
									'fill'   => array(),
								),
								'text'    => array(
									'x'                 => array(),
									'y'                 => array(),
									'font-size'         => array(),
									'font-weight'       => array(),
									'fill'              => array(),
									'font-family'       => array(),
									'dominant-baseline' => array(),
									'text-anchor'       => array(),
								),
								'img'     => array(
									'src'    => array(),
									'width'  => array(),
									'height' => array(),
									'style'  => array(),
									'alt'    => array(),
								),
							)
						);
						?>
						<?php echo esc_html( $agentic_p['name'] ); ?>
					</h2>
				</div>

				<!-- Key entry -->
				<div class="key-section">
					<?php if ( 'agentic' === $agentic_slug ) : ?>
					<label for="key-input-agentic"><?php esc_html_e( 'Email address', 'agent-builder' ); ?></label>
					<div class="key-input-row" id="key-input-row-agentic">
						<input
							type="email"
							id="key-input-agentic"
							placeholder="you@example.com"
							autocomplete="email"
							oninput="onKeyInput('agentic')"
						>
						<button type="button" class="btn-secondary btn-save" id="btn-save-agentic" style="display:none" onclick="saveKey()"><?php esc_html_e( 'Register & Connect', 'agent-builder' ); ?></button>
						<button type="button" class="btn-primary btn-continue-test" id="btn-continue-agentic" style="display:none" onclick="goNext()"><?php esc_html_e( 'Continue to Testing →', 'agent-builder' ); ?></button>
					</div>
					<div class="agentic-wizard-consent" id="agentic-wizard-consent-wrap" style="display:none">
						<label><input type="checkbox" id="agentic-wizard-consent-tos"> 
						<?php
						echo wp_kses(
							sprintf( /* translators: %s: Terms link */ __( 'I agree to the <a href="https://agentic-plugin.com/terms-of-service/" target="_blank" rel="noopener">Terms of Service</a>', 'agent-builder' ), '' ),
							array(
								'a' => array(
									'href'   => array(),
									'target' => array(),
									'rel'    => array(),
								),
							)
						);
						?>
																						</label>
						<label><input type="checkbox" id="agentic-wizard-consent-privacy"> 
						<?php
						echo wp_kses(
							sprintf( /* translators: %s: Privacy link */ __( 'I agree to the <a href="https://agentic-plugin.com/privacy-policy/" target="_blank" rel="noopener">Privacy Policy</a>', 'agent-builder' ), '' ),
							array(
								'a' => array(
									'href'   => array(),
									'target' => array(),
									'rel'    => array(),
								),
							)
						);
						?>
																							</label>
					</div>
					<?php elseif ( 'ollama' === $agentic_slug ) : ?>
					<label><?php esc_html_e( 'Ollama Server URL', 'agent-builder' ); ?></label>
					<p class="key-hint"><?php esc_html_e( 'Leave as default unless you changed the port or are using a remote server.', 'agent-builder' ); ?></p>
					<div class="ollama-url-row">
						<input
							type="text"
							id="key-input-ollama"
							value="http://localhost:11434"
							placeholder="http://localhost:11434"
							autocomplete="off"
						>
						<button type="button" class="btn-primary agentic-mt-8" onclick="saveKey()"><?php esc_html_e( 'Connect to Ollama', 'agent-builder' ); ?></button>
					</div>
					<?php else : ?>
					<label for="key-input-<?php echo esc_attr( $agentic_slug ); ?>"><?php esc_html_e( 'Paste your API key here once you have followed the steps below.', 'agent-builder' ); ?></label>
					<div class="key-input-row" id="key-input-row-<?php echo esc_attr( $agentic_slug ); ?>">
						<input
							type="password"
							id="key-input-<?php echo esc_attr( $agentic_slug ); ?>"
							placeholder="<?php echo esc_attr( $agentic_p['placeholder'] ); ?>"
							autocomplete="off"
							oninput="onKeyInput('<?php echo esc_js( $agentic_slug ); ?>')"
						>
						<button type="button" class="btn-secondary btn-save" id="btn-save-<?php echo esc_attr( $agentic_slug ); ?>" style="display:none" onclick="saveKey()"><?php esc_html_e( 'Save', 'agent-builder' ); ?></button>
					<button type="button" class="btn-primary btn-continue-test" id="btn-continue-<?php echo esc_attr( $agentic_slug ); ?>" style="display:none" onclick="goNext()"><?php esc_html_e( 'Continue to Testing →', 'agent-builder' ); ?></button>
					</div>
					<?php endif; ?>

					<div class="test-status" id="test-status-<?php echo esc_attr( $agentic_slug ); ?>" role="status" aria-live="polite">
						<span class="test-icon"></span>
						<span class="test-msg"></span>
					</div>
				</div>

				<ul class="step-list" id="steps-list-<?php echo esc_attr( $agentic_slug ); ?>">
					<?php
					$agentic_step_num = 1;
					foreach ( $agentic_p['steps'] as $agentic_step ) :
						// For "has account" users, skip the sign-up step (first step).
						$agentic_is_signup_step = ( 1 === $agentic_step_num );
						?>
					<li class="<?php echo esc_attr( $agentic_is_signup_step ? 'signup-step' : '' ); ?>">
						<div class="step-n"><?php echo esc_html( (string) $agentic_step_num ); ?></div>
						<div class="step-body">
							<p>
							<?php
							echo wp_kses(
								$agentic_step['text'],
								array(
									'a'      => array(
										'href'   => array(),
										'target' => array(),
										'rel'    => array(),
									),
									'strong' => array(),
									'br'     => array(),
									'code'   => array(),
								)
							);
							?>
								</p>
							<?php if ( ! empty( $agentic_step['screenshot'] ) ) : ?>
							<div class="screenshot-wrap" onclick="openLightbox(this.querySelector('img').src)">
								<img
									src="<?php echo esc_url( $agentic_step['screenshot'] ); ?>"
									alt="<?php echo esc_attr( $agentic_step['alt'] ); ?>"
									class="step-screenshot"
									loading="lazy"
									onerror="this.closest('.screenshot-wrap').style.display='none'"
								>
								<span class="screenshot-zoom-icon" aria-hidden="true">
									<svg viewBox="0 0 20 20"><circle cx="8" cy="8" r="5"/><line x1="13" y1="13" x2="18" y2="18"/><line x1="6" y1="8" x2="10" y2="8"/><line x1="8" y1="6" x2="8" y2="10"/></svg>
								</span>
							</div>
							<?php endif; ?>
						</div>
					</li>
						<?php
						++$agentic_step_num;
					endforeach;
					?>
				</ul>
				<?php if ( ! empty( $agentic_p['credit_note'] ) ) : ?>
				<p class="credit-note"><?php echo esc_html( $agentic_p['credit_note'] ); ?></p>
				<?php endif; ?>
				<?php if ( ! empty( $agentic_p['key_url'] ) && 'ollama' !== $agentic_slug ) : ?>
				<div class="get-key-section">
					<button type="button" class="btn-primary" onclick="openProviderKey('<?php echo esc_js( $agentic_slug ); ?>')"><?php esc_html_e( 'Get Key', 'agent-builder' ); ?></button>
					<p class="get-key-note"><?php esc_html_e( 'Opens new window so you can perform the steps above. Remember to return here and save the key — only shown once.', 'agent-builder' ); ?></p>
				</div>
				<?php endif; ?>
			</div><!-- .provider-steps-section -->
			<?php endforeach; ?>

			<!-- ── Phase 4: Test connection ──────────────────────────── -->
			<div class="setup-card-inner" id="phase-test" style="display:none">

				<!-- Model Selection -->
				<div class="wizard-pref-section">
					<h2><?php esc_html_e( 'Choose a model', 'agent-builder' ); ?></h2>
					<p class="wizard-pref-hint"><?php esc_html_e( 'Each model has different strengths. You can change this any time in Settings.', 'agent-builder' ); ?></p>
					<div id="wizard-model-grid" class="wizard-model-grid"></div>
				</div>

				<!-- Agent Mode -->
				<div class="wizard-pref-section">
					<h2><?php esc_html_e( 'Choose an agent mode', 'agent-builder' ); ?></h2>
					<p class="wizard-pref-hint"><?php esc_html_e( 'Controls how much authority your AI agents have. You can change this any time in Settings.', 'agent-builder' ); ?></p>
					<div class="wizard-mode-grid">
						<label class="wizard-mode-card" data-mode="supervised">
							<input type="radio" name="wizard_mode" value="supervised" checked>
							<div class="wizard-mode-header">
								<span class="wizard-mode-icon">👁️</span>
								<strong><?php esc_html_e( 'Supervised', 'agent-builder' ); ?></strong>
								<span class="wizard-mode-badge"><?php esc_html_e( 'Recommended', 'agent-builder' ); ?></span>
							</div>
							<p><?php esc_html_e( 'The AI proposes changes — you review and approve before anything is saved or published. Nothing happens without your sign-off.', 'agent-builder' ); ?></p>
						</label>
						<label class="wizard-mode-card" data-mode="autonomous">
							<input type="radio" name="wizard_mode" value="autonomous">
							<div class="wizard-mode-header">
								<span class="wizard-mode-icon">⚡</span>
								<strong><?php esc_html_e( 'Autonomous', 'agent-builder' ); ?></strong>
							</div>
							<p><?php esc_html_e( 'The AI executes tasks immediately without asking for approval. Best for experienced users who trust the agent\'s judgment.', 'agent-builder' ); ?></p>
						</label>
						<label class="wizard-mode-card" data-mode="disabled">
							<input type="radio" name="wizard_mode" value="disabled">
							<div class="wizard-mode-header">
								<span class="wizard-mode-icon">💬</span>
								<strong><?php esc_html_e( 'Chat only', 'agent-builder' ); ?></strong>
							</div>
							<p><?php esc_html_e( 'Agents can answer questions and give advice, but cannot read data from or make any changes to your site.', 'agent-builder' ); ?></p>
						</label>
					</div>
				</div>

				<div class="wizard-test-section">
					<h2><?php esc_html_e( 'Test your connection', 'agent-builder' ); ?></h2>
					<p><?php esc_html_e( 'Click below to verify your AI provider is responding correctly.', 'agent-builder' ); ?></p>
					<button type="button" class="btn-primary" id="btn-test-connection" onclick="runWizardTest()">
						<?php esc_html_e( 'Test Connection', 'agent-builder' ); ?>
					</button>
					<div class="test-status" id="test-status-wizard" role="status" aria-live="polite">
						<span class="test-icon"></span>
						<span class="test-msg"></span>
					</div>
				</div>

				<!-- Congrats + chatbox — revealed on success -->
				<div id="wizard-congrats" style="display:none">
					<div class="wizard-congrats-banner">
						<div class="wizard-congrats-icon">🎊</div>
						<h2><?php esc_html_e( 'Your WordPress site just got much smarter.', 'agent-builder' ); ?></h2>
						<p><?php esc_html_e( 'Go ahead, talk to WordPress!!', 'agent-builder' ); ?></p>
					</div>

					<?php
					$agentic_wizard_agent     = Agentic_Agent_Registry::get_instance()->get_agent_instance( 'wordpress-assistant' );
					$agentic_wizard_greeting  = $agentic_wizard_agent ? $agentic_wizard_agent->get_welcome_message() : "Hi! I'm your WordPress Assistant. Ask me anything about your site.";
					$agentic_wizard_prompts   = $agentic_wizard_agent ? $agentic_wizard_agent->get_suggested_prompts() : array();
					$agentic_wizard_shortcuts = ( $agentic_wizard_agent && method_exists( $agentic_wizard_agent, 'get_agent_shortcuts' ) )
						? $agentic_wizard_agent->get_agent_shortcuts()
						: array();
					?>
					<div class="wizard-chatbox">
						<div class="wizard-chat-titlebar">
							<div class="wizard-win-dot wizard-win-dot--red"></div>
							<div class="wizard-win-dot wizard-win-dot--yellow"></div>
							<div class="wizard-win-dot wizard-win-dot--green"></div>
							<div class="wizard-chat-title-text"><?php esc_html_e( 'WordPress Assistant', 'agent-builder' ); ?></div>
						</div>
						<div class="wizard-chat-messages" id="wizard-chat-messages">
							<div class="wizard-chat-msg wizard-chat-msg--agent">
								<span class="wizard-chat-bubble"><?php echo wp_kses_post( $agentic_wizard_greeting ); ?></span>
							</div>
						</div>
						<div class="wizard-suggested-prompts" id="wizard-suggested-prompts"></div>
						<div class="wizard-chat-input-wrap">
							<input type="text" id="wizard-chat-input" placeholder="<?php esc_attr_e( 'Ask me anything about your WordPress site…', 'agent-builder' ); ?>" autocomplete="off" onkeydown="if(event.key==='Enter')sendWizardMessage()">
							<button type="button" id="wizard-mic-btn" class="wizard-mic-btn" title="<?php esc_attr_e( 'Voice input', 'agent-builder' ); ?>" style="display:none;">
								<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3Z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" x2="12" y1="19" y2="22"/></svg>
							</button>
							<button type="button" id="wizard-chat-send" class="btn-primary" onclick="sendWizardMessage()"><?php esc_html_e( 'Send', 'agent-builder' ); ?></button>
						</div>
					</div>

					<div class="wizard-exit-row">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=agent-builder' ) ); ?>" class="btn-secondary"><?php esc_html_e( 'Exit Wizard', 'agent-builder' ); ?></a>
					</div>
				</div>
			</div><!-- #phase-test -->

			<div class="setup-card-footer" id="setup-footer">
				<div class="footer-left"></div>
				<div class="footer-right">
					<button type="button" class="btn-secondary" id="btn-back" style="display:none" onclick="goBack()">
						← <?php esc_html_e( 'Back', 'agent-builder' ); ?>
					</button>
					<button type="button" class="btn-secondary" id="btn-next" style="display:none" onclick="goNext()">
						<?php esc_html_e( 'Next', 'agent-builder' ); ?> →
					</button>
				</div>
			</div><!-- .setup-card-footer -->

		</div><!-- .setup-card -->
	</form>

</div><!-- .setup-wrap -->

<!-- Lightbox -->
<div class="lightbox" id="lightbox" onclick="closeLightbox()">
	<img src="" alt="<?php esc_attr_e( 'Enlarged preview', 'agent-builder' ); ?>" id="lightbox-img">
</div>

<script>
var hasAccount = false;
var selectedProvider = null;
var wizardKeySaved = false;
var wizardSavedProvider = null;
var wizardSavedKey = null;
var wizardSessionId = 'wizard-' + Math.random().toString(36).substr(2, 9);
var wizardChatHistory = [];
var wizardChatNonce = <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;
var wizardRestUrl    = <?php echo wp_json_encode( rest_url( 'agentic/v1/' ) ); ?>;
var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
var ajaxNonce = <?php echo wp_json_encode( wp_create_nonce( 'agentic_test_connection' ) ); ?>;
var signupNonce = <?php echo wp_json_encode( wp_create_nonce( 'agentic_signup' ) ); ?>;
var providerSignupUrls = <?php echo wp_json_encode( array_map( fn( $agentic_p ) => $agentic_p['signup_url'], $agentic_providers ) ); ?>;
var providerNames      = <?php echo wp_json_encode( array_map( fn( $agentic_p ) => $agentic_p['name'], $agentic_providers ) ); ?>;
var providerInfoData       = <?php echo wp_json_encode( $agentic_provider_info ); ?>;
var wizardSuggestedPrompts = <?php echo wp_json_encode( array_values( $agentic_wizard_prompts ) ); ?>;
var wizardAgentShortcuts   = <?php echo wp_json_encode( array_values( $agentic_wizard_shortcuts ) ); ?>;
var wizardChatPageUrl      = <?php echo wp_json_encode( admin_url( 'admin.php?page=agentic-chat' ) ); ?>;

function showPhase(phase, userHasAccount) {
	hasAccount = userHasAccount;

	// Update provider selection title/subtitle based on choice.
	if (phase === 'provider-pick') {
		var title = document.getElementById('provider-pick-title');
		var sub = document.getElementById('provider-pick-sub');
		if (userHasAccount) {
			title.textContent = 'Which provider do you use?';
			sub.textContent = 'Select the provider whose API key you already have.';
		} else {
			title.textContent = 'Choose a provider to sign up with';
			sub.textContent = 'We recommend Agentic AI (Experimental) for getting started — for faster replies switch to a paid Provider';
		}
	}

	// Hide all phases.
	var phases = ['phase-account', 'phase-provider-pick', 'phase-test'];
	<?php foreach ( array_keys( $agentic_providers ) as $agentic_slug ) : ?>
	phases.push('phase-steps-<?php echo esc_attr( $agentic_slug ); ?>');
	<?php endforeach; ?>

	phases.forEach(function(id) {
		var el = document.getElementById(id);
		if (el) el.style.display = 'none';
	});

	var target = document.getElementById('phase-' + phase);
	if (target) target.style.display = 'block';

	// Update progress indicators.
	var p1 = document.getElementById('progress-1');
	var p2 = document.getElementById('progress-2');
	var p3 = document.getElementById('progress-3');
	var p4 = document.getElementById('progress-4');

	// Reset.
	[p1, p2, p3, p4].forEach(function(el) {
		el.classList.remove('active', 'done');
	});

	if (phase === 'account') {
		p1.classList.add('active');
	} else if (phase === 'provider-pick') {
		p1.classList.add('done');
		p2.classList.add('active');
	} else if (phase === 'test') {
		p1.classList.add('done');
		p2.classList.add('done');
		p3.classList.add('done');
		p4.classList.add('active');
		// Populate model cards for the selected provider.
		buildModelCards(wizardSavedProvider || selectedProvider);
	} else {
		p1.classList.add('done');
		p2.classList.add('done');
		p3.classList.add('active');
	}

	// Update footer nav buttons.
	var btnBack = document.getElementById('btn-back');
	var btnNext = document.getElementById('btn-next');

	// Reset.
	[btnBack, btnNext].forEach(function(b) { b.style.display = 'none'; });

	if (phase === 'provider-pick') {
		btnBack.style.display = 'inline-block';
	} else if (phase.indexOf('steps-') === 0) {
		btnBack.style.display = 'inline-block';
		// Show Next if this provider's key was already saved.
		var slug = phase.replace('steps-', '');
		if (wizardKeySaved && wizardSavedProvider === slug) {
			btnNext.style.display = 'inline-block';
		}
	} else if (phase === 'test') {
		btnBack.style.display = 'inline-block';
	}
}

function goBack() {
	var phases = ['phase-account', 'phase-provider-pick', 'phase-test'];
	<?php foreach ( array_keys( $agentic_providers ) as $agentic_slug ) : ?>
	phases.push('phase-steps-<?php echo esc_attr( $agentic_slug ); ?>');
	<?php endforeach; ?>

	var current = null;
	phases.forEach(function(id) {
		var el = document.getElementById(id);
		if (el && el.style.display !== 'none') current = id;
	});

	if (!current) return;
	if (current === 'phase-provider-pick') {
		showPhase('account', hasAccount);
	} else if (current.indexOf('phase-steps-') === 0) {
		showPhase('provider-pick', hasAccount);
	} else if (current === 'phase-test') {
		showPhase('steps-' + (wizardSavedProvider || selectedProvider), hasAccount);
	}
}

function goNext() {
	var phases = ['phase-account', 'phase-provider-pick', 'phase-test'];
	<?php foreach ( array_keys( $agentic_providers ) as $agentic_slug ) : ?>
	phases.push('phase-steps-<?php echo esc_attr( $agentic_slug ); ?>');
	<?php endforeach; ?>

	var current = null;
	phases.forEach(function(id) {
		var el = document.getElementById(id);
		if (el && el.style.display !== 'none') current = id;
	});

	if (!current) return;

	if (current === 'phase-provider-pick') {
		if (!selectedProvider) return;
		showPhase('steps-' + selectedProvider, hasAccount);
	} else if (current.indexOf('phase-steps-') === 0 && wizardKeySaved) {
		showPhase('test', hasAccount);
	}

	var card = document.querySelector('.setup-card');
	if (card) card.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

var providerKeyUrls = <?php echo wp_json_encode( array_map( fn( $agentic_p ) => $agentic_p['key_url'] ?? '', $agentic_providers ) ); ?>;

function openProviderKey(slug) {
	var url = providerKeyUrls[slug] || providerSignupUrls[slug];
	if (url) window.open(url, '_blank', 'noopener');
	// Scroll to top and focus the key input.
	var card = document.getElementById('phase-steps-' + slug);
	if (card) card.scrollIntoView({ behavior: 'smooth', block: 'start' });
	setTimeout(function() {
		var input = document.getElementById('key-input-' + slug);
		if (input) input.focus();
	}, 400);
}

function selectProvider(slug) {
	// Deselect all cards.
	document.querySelectorAll('.provider-card').forEach(function(c) {
		c.classList.remove('selected');
		c.setAttribute('aria-pressed', 'false');
	});
	var selectedCard = document.getElementById('card-' + slug);
	selectedCard.classList.add('selected');
	selectedCard.setAttribute('aria-pressed', 'true');

	selectedProvider = slug;

	// Show/hide sign-up steps based on whether user has account.
	var signupSteps = document.querySelectorAll('#steps-list-' + slug + ' .signup-step');
	signupSteps.forEach(function(li) {
		li.style.display = hasAccount ? 'none' : 'flex';
	});

	// Renumber visible steps.
	var visibleSteps = document.querySelectorAll('#steps-list-' + slug + ' li:not([style*="display: none"])');
	visibleSteps.forEach(function(li, idx) {
		var numEl = li.querySelector('.step-n');
		if (numEl) numEl.textContent = idx + 1;
	});

	// Populate and reveal the provider info panel.
	var info  = providerInfoData[slug];
	var panel = document.getElementById('provider-info-panel');
	if (info && panel) {
		document.getElementById('pip-name').textContent     = providerNames[slug] || slug;
		document.getElementById('pip-tagline').textContent  = info.tagline || '';
		document.getElementById('pip-pricing').textContent  = info.pricing_detail || '';
		document.getElementById('pip-rate-limits').textContent = info.rate_limits || '';
		document.getElementById('pip-best-for').textContent = info.best_for || '';

		// Copy the provider icon from the card.
		var pipIcon = document.getElementById('pip-icon');
		var srcCard = document.getElementById('card-' + slug);
		if (srcCard && pipIcon) {
			var iconEl = srcCard.querySelector('.provider-icon');
			pipIcon.innerHTML = iconEl ? iconEl.innerHTML : '';
		}

		// Render model chips.
		var modelList = document.getElementById('pip-model-list');
		modelList.innerHTML = '';
		(info.models || []).forEach(function(m) {
			var chip = document.createElement('div');
			chip.className = 'pip-model-chip' + (m.recommended ? ' recommended' : '');
			var tagsHtml = (m.tags || []).map(function(t) {
				return '<span class="pip-tag">' + t + '</span>';
			}).join('');
			chip.innerHTML = '<span>' + m.name + '</span>' + tagsHtml;
			modelList.appendChild(chip);
		});

		panel.classList.add('visible');
	}

	// Stay on provider-pick and show Next so user can confirm or change choice.
	document.getElementById('btn-back').style.display = 'inline-block';
	document.getElementById('btn-next').style.display = 'inline-block';
}

function onKeyInput(slug) {
	var input = document.getElementById('key-input-' + slug);
	var btnSave = document.getElementById('btn-save-' + slug);
	var hasValue = input && input.value.trim().length > 3;
	if (btnSave) btnSave.style.display = hasValue ? 'inline-block' : 'none';
	if (slug === 'agentic') {
		var consentWrap = document.getElementById('agentic-wizard-consent-wrap');
		if (consentWrap) consentWrap.style.display = hasValue ? 'block' : 'none';
	}
	setTestStatus(slug, '');
}

function saveKey() {
	var slug = selectedProvider;
	if (!slug) return;

	var key;
	if (slug === 'ollama') {
		key = (document.getElementById('key-input-ollama').value.trim()) || 'http://localhost:11434';
	} else {
		var input = document.getElementById('key-input-' + slug);
		if (!input || !input.value.trim()) return;
		key = input.value.trim();
	}

	var btn = document.getElementById('btn-save-' + slug);
	if (btn) { btn.textContent = 'Saving…'; btn.disabled = true; }

	function onSaved(key) {
		if (btn) {
			btn.textContent = '✓ Saved!';
			btn.style.background = '#00a32a';
			btn.style.color = '#fff';
			btn.style.border = '1px solid #00a32a';
		}
		wizardKeySaved = true;
		wizardSavedProvider = slug;
		wizardSavedKey = key;
		document.getElementById('btn-next').style.display = 'inline-block';
		var contBtn = document.getElementById('btn-continue-' + slug);
		if (contBtn) contBtn.style.display = 'inline-block';
	}

	function onError(msg) {
		if (btn) { btn.textContent = slug === 'agentic' ? 'Register & Connect' : 'Save'; btn.disabled = false; btn.style = ''; }
		setTestStatus(slug, 'error', msg || 'Failed to save. Please try again.');
	}

	if (slug === 'agentic') {
		var tos     = document.getElementById('agentic-wizard-consent-tos');
		var privacy = document.getElementById('agentic-wizard-consent-privacy');
		if (!tos || !tos.checked || !privacy || !privacy.checked) {
			onError('Please agree to the Terms of Service and Privacy Policy.');
			return;
		}
		var fd = new FormData();
		fd.append('action', 'agentic_signup_api_key');
		fd.append('_ajax_nonce', signupNonce);
		fd.append('email', key);
		fd.append('site_name', '');
		fd.append('domain', window.location.hostname);
		fd.append('plugin_version', '');
		fetch(ajaxUrl, { method: 'POST', body: fd })
			.then(function(r) { return r.json(); })
			.then(function(data) {
				if (data.success) {
					onSaved(data.data.api_key || 'registered');
				} else {
					onError((data.data && data.data.message) ? data.data.message : null);
				}
			})
			.catch(function() { onError('Network error. Please try again.'); });
		return;
	}

	var formData = new FormData();
	formData.append('action', 'agentic_wizard_save_key');
	formData.append('nonce', ajaxNonce);
	formData.append('provider', slug);
	formData.append('api_key', key);

	fetch(ajaxUrl, { method: 'POST', body: formData })
		.then(function(r) { return r.json(); })
		.then(function(data) {
			if (data.success) { onSaved(key); }
			else { onError((data.data && data.data.message) ? data.data.message : null); }
		})
		.catch(function() { onError('Network error. Please try again.'); });
}

function setTestStatus(slug, state, msg) {
	var el = document.getElementById('test-status-' + slug);
	if (!el) return;
	el.className = 'test-status ' + (state || '');
	var icon = { testing: '⏳', success: '✅', error: '❌' };
	el.querySelector('.test-icon').textContent = icon[state] || '';
	el.querySelector('.test-msg').textContent = msg || '';
}

// ── Model & mode picker ───────────────────────────────────────────────────────
var wizardSelectedModel = null;
var wizardSelectedMode  = 'supervised';

function buildModelCards(provider) {
	var grid = document.getElementById('wizard-model-grid');
	if (!grid) return;
	var info = (typeof providerInfoData !== 'undefined') ? providerInfoData[provider] : null;
	if (!info || !info.models || !info.models.length) {
		grid.innerHTML = '<p class="agentic-text-dim agentic-text-xs">No models listed for this provider — you can select one in Settings after setup.</p>';
		return;
	}
	grid.innerHTML = '';
	wizardSelectedModel = null;
	info.models.forEach(function(m) {
		var card = document.createElement('div');
		card.className = 'wizard-model-card' + (m.recommended ? ' selected' : '');
		card.dataset.model = m.name;
		if (m.recommended && !wizardSelectedModel) wizardSelectedModel = m.name;
		var tags = (m.tags || []).map(function(t) {
			return '<span class="wizard-model-tag' + (t === 'Recommended' || m.recommended && (m.tags||[])[0] === t ? '' : '') + '">' + t + '</span>';
		}).join('');
		if (m.recommended) tags = '<span class="wizard-model-tag tag-recommended">Recommended</span>' + tags;
		card.innerHTML = '<div class="wizard-model-check">✓</div><div class="wizard-model-name">' + m.name + '</div><div class="wizard-model-tags">' + tags + '</div>';
		card.addEventListener('click', function() {
			document.querySelectorAll('.wizard-model-card').forEach(function(c) { c.classList.remove('selected'); });
			card.classList.add('selected');
			wizardSelectedModel = m.name;
		});
		grid.appendChild(card);
	});
	if (!wizardSelectedModel && info.models.length) wizardSelectedModel = info.models[0].name;
}

document.querySelectorAll('.wizard-mode-card').forEach(function(card) {
	card.addEventListener('click', function() {
		var radio = card.querySelector('input[type="radio"]');
		if (radio) { radio.checked = true; wizardSelectedMode = radio.value; }
	});
});

function saveWizardPreferences(onDone) {
	var fd = new FormData();
	fd.append('action', 'agentic_wizard_save_preferences');
	fd.append('nonce', ajaxNonce);
	fd.append('model', wizardSelectedModel || '');
	fd.append('mode', wizardSelectedMode);
	fetch(ajaxUrl, { method: 'POST', body: fd }).then(onDone).catch(onDone);
}

function runWizardTest() {
	if (!wizardSavedProvider || !wizardSavedKey) return;

	var btn = document.getElementById('btn-test-connection');
	btn.disabled = true;
	btn.textContent = 'Testing…';

	var statusEl = document.getElementById('test-status-wizard');
	statusEl.className = 'test-status testing';
	statusEl.querySelector('.test-icon').textContent = '⏳';
	statusEl.querySelector('.test-msg').textContent = 'Connecting to ' + (providerNames[wizardSavedProvider] || wizardSavedProvider) + '…';

	var formData = new FormData();
	formData.append('action', 'agentic_test_connection');
	formData.append('nonce', ajaxNonce);
	formData.append('provider', wizardSavedProvider);
	formData.append('api_key', wizardSavedKey);

	fetch(ajaxUrl, { method: 'POST', body: formData })
		.then(function(r) { return r.json(); })
		.then(function(data) {
			if (data.success) {
				statusEl.className = 'test-status success';
				statusEl.querySelector('.test-icon').textContent = '✅';
				statusEl.querySelector('.test-msg').textContent = 'Connected! Saving your preferences…';
				btn.textContent = 'Test Connection';
				btn.disabled = false;
				saveWizardPreferences(function() {
					statusEl.querySelector('.test-msg').textContent = 'Connected successfully!';
					setTimeout(function() {
						var congrats = document.getElementById('wizard-congrats');
						congrats.style.display = 'block';
						congrats.scrollIntoView({ behavior: 'smooth', block: 'start' });
						document.getElementById('btn-next').style.display = 'none';
						document.getElementById('btn-back').style.display = 'none';
					}, 400);
				});
			} else {
				statusEl.className = 'test-status error';
				statusEl.querySelector('.test-icon').textContent = '❌';
				var msg = (data.data && data.data.message) ? data.data.message : 'Connection failed. Check your API key.';
				statusEl.querySelector('.test-msg').textContent = msg;
				btn.textContent = 'Try Again';
				btn.disabled = false;
			}
		})
		.catch(function() {
			statusEl.className = 'test-status error';
			statusEl.querySelector('.test-icon').textContent = '❌';
			statusEl.querySelector('.test-msg').textContent = 'Network error. Please try again.';
			btn.textContent = 'Try Again';
			btn.disabled = false;
		});
}

// Render agent shortcut pills (navigate to chat page with ?agent=) or fall back to text prompts
(function() {
	var container = document.getElementById('wizard-suggested-prompts');
	if (!container) return;

	// Prefer agent shortcuts — navigate to chat page.
	if (wizardAgentShortcuts && wizardAgentShortcuts.length) {
		wizardAgentShortcuts.forEach(function(shortcut) {
			var btn = document.createElement('button');
			btn.type = 'button';
			btn.textContent = shortcut.icon + ' ' + shortcut.label;
			btn.title = 'Open ' + shortcut.label;
			btn.addEventListener('click', function() {
				window.location.href = wizardChatPageUrl + '&agent=' + encodeURIComponent(shortcut.agent_id);
			});
			container.appendChild(btn);
		});
		return;
	}

	// Fallback: text prompts that fill the wizard chat input.
	if (wizardSuggestedPrompts && wizardSuggestedPrompts.length) {
		wizardSuggestedPrompts.forEach(function(prompt) {
			var btn = document.createElement('button');
			btn.type = 'button';
			btn.textContent = prompt;
			btn.addEventListener('click', function() {
				container.style.display = 'none';
				var input = document.getElementById('wizard-chat-input');
				input.value = prompt;
				sendWizardMessage();
			});
			container.appendChild(btn);
		});
	}
})();

function appendWizardMessage(role, text, isThinking, id) {
	var messages = document.getElementById('wizard-chat-messages');
	var div = document.createElement('div');
	div.className = 'wizard-chat-msg wizard-chat-msg--' + role + (isThinking ? ' wizard-chat-msg--thinking' : '');
	if (id) div.id = id;
	var bubble = document.createElement('span');
	bubble.className = 'wizard-chat-bubble';
	bubble.textContent = text;
	div.appendChild(bubble);
	messages.appendChild(div);
	messages.scrollTop = messages.scrollHeight;
}

function sendWizardMessage() {
	var input = document.getElementById('wizard-chat-input');
	var msg = input.value.trim();
	if (!msg) return;
	input.value = '';
	// Hide prompts once user starts chatting
	var promptsEl = document.getElementById('wizard-suggested-prompts');
	if (promptsEl) promptsEl.style.display = 'none';

	appendWizardMessage('user', msg);

	var sendBtn = document.getElementById('wizard-chat-send');
	sendBtn.disabled = true;
	input.disabled = true;

	var thinkingId = 'wiz-think-' + Date.now();
	appendWizardMessage('agent', 'Thinking…', true, thinkingId);

	wizardChatHistory.push({ role: 'user', content: msg });

	fetch(wizardRestUrl + 'chat', {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': wizardChatNonce
		},
		body: JSON.stringify({
			message: msg,
			session_id: wizardSessionId,
			agent_id: 'wordpress-assistant',
			history: wizardChatHistory.slice(-10)
		})
	})
	.then(function(r) { return r.json(); })
	.then(function(data) {
		var el = document.getElementById(thinkingId);
		if (el) el.remove();
		var reply = (data && data.response) ? data.response : ((data && data.error && data.response) ? data.response : 'Sorry, I could not get a response.');
		appendWizardMessage('agent', reply);
		wizardChatHistory.push({ role: 'assistant', content: reply });
		sendBtn.disabled = false;
		input.disabled = false;
		input.focus();
	})
	.catch(function() {
		var el = document.getElementById(thinkingId);
		if (el) el.remove();
		appendWizardMessage('agent', 'Sorry, something went wrong. Please try again.');
		sendBtn.disabled = false;
		input.disabled = false;
	});
}

// Voice input for wizard chat
(function() {
	var micBtn = document.getElementById('wizard-mic-btn');
	if (!micBtn) return;
	var SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
	micBtn.style.display = '';
	if (!SpeechRecognition) {
		micBtn.title = 'Voice input requires Chrome, Edge, or Safari';
		micBtn.style.opacity = '0.4';
		micBtn.addEventListener('click', function() {
			agenticUI.toast('Voice input is not supported in this browser. Try Chrome, Edge, or Safari.', 'warning');
		});
		return;
	}
	var recognition = null;
	var isListening = false;
	var input = document.getElementById('wizard-chat-input');
	micBtn.addEventListener('click', function() {
		if (isListening) { recognition.stop(); return; }
		recognition = new SpeechRecognition();
		recognition.lang = document.documentElement.lang || 'en-US';
		recognition.interimResults = true;
		recognition.continuous = false;
		recognition.maxAlternatives = 1;
		var existingText = input.value;
		recognition.onstart = function() {
			isListening = true;
			micBtn.classList.add('wizard-mic-active');
			input.placeholder = 'Listening…';
		};
		recognition.onresult = function(event) {
			var interim = '', final = '';
			for (var i = 0; i < event.results.length; i++) {
				if (event.results[i].isFinal) { final += event.results[i][0].transcript; }
				else { interim += event.results[i][0].transcript; }
			}
			input.value = existingText + (existingText ? ' ' : '') + (final || interim);
		};
		recognition.onend = function() {
			isListening = false;
			micBtn.classList.remove('wizard-mic-active');
			input.placeholder = input.getAttribute('data-orig-placeholder') || 'Ask me anything about your WordPress site…';
			input.focus();
		};
		recognition.onerror = function(event) {
			isListening = false;
			micBtn.classList.remove('wizard-mic-active');
			input.placeholder = input.getAttribute('data-orig-placeholder') || 'Ask me anything about your WordPress site…';
			if (event.error === 'not-allowed') {
				agenticUI.toast('Microphone access denied. This may require HTTPS.', 'warning');
			} else if (event.error !== 'aborted' && event.error !== 'no-speech') {
				console.warn('Speech recognition error:', event.error);
			}
		};
		if (!input.getAttribute('data-orig-placeholder')) {
			input.setAttribute('data-orig-placeholder', input.placeholder);
		}
		recognition.start();
	});
})();

function openLightbox(src) {
	document.getElementById('lightbox-img').src = src;
	document.getElementById('lightbox').classList.add('open');
}

function closeLightbox() {
	document.getElementById('lightbox').classList.remove('open');
}

document.addEventListener('keydown', function(e) {
	if (e.key === 'Escape') closeLightbox();
});
</script>

<?php wp_footer(); ?>
</body>
</html>
<?php
// ── Provider icon helper ──────────────────────────────────────────────────────

/**
 * Returns an inline SVG icon (or img tag) for the given provider icon value.
 * Defined in agentbuilder.php (always loaded).
 *
 * @see agentic_setup_provider_icon()
 */
