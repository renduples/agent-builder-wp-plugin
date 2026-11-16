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

// Form submission ("save provider") and the "skip setup" link are handled on
// admin_init by \Agentic\Setup_Wizard_Handler::maybe_handle(), before any
// output, so they can finish with a server-side redirect.

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

// Build $agent_builder_providers by merging registry data (name, icon, key_url) with wizard-only metadata.
$agent_builder_providers = array();
foreach ( \Agentic\Provider_Registry::get_all() as $agentic_reg_p ) {
	$agentic_pslug                             = $agentic_reg_p['slug'];
	$agentic_wmeta                             = $agentic_wizard_meta[ $agentic_pslug ] ?? array();
	$agent_builder_providers[ $agentic_pslug ] = array_merge(
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
function agent_builder_setup_provider_models( string $slug ): array {
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
		'models'         => agent_builder_setup_provider_models( 'xai' ),
	),
	'openai'    => array(
		'tagline'        => 'Industry standard with the broadest ecosystem',
		'pricing_detail' => 'GPT-4o: $2.50 / 1M input · $10 / 1M output',
		'rate_limits'    => '500 req/min (Tier 1)',
		'best_for'       => 'Code generation · function calling · broad tooling',
		'models'         => agent_builder_setup_provider_models( 'openai' ),
	),
	'google'    => array(
		'tagline'        => 'Generous free tier with up to 2M token context window',
		'pricing_detail' => 'Free: 15 req/min · Flash: $0.075 / 1M tokens',
		'rate_limits'    => '15 req/min (free) · 1,000+ req/min (paid)',
		'best_for'       => 'Long documents · image analysis · low cost',
		'models'         => agent_builder_setup_provider_models( 'google' ),
	),
	'anthropic' => array(
		'tagline'        => 'Best for nuanced writing, analysis and complex reasoning',
		'pricing_detail' => 'Sonnet 3.5: $3 / 1M input · $15 / 1M output',
		'rate_limits'    => '50 req/min (Tier 1)',
		'best_for'       => 'Writing · analysis · complex reasoning',
		'models'         => agent_builder_setup_provider_models( 'anthropic' ),
	),
	'mistral'   => array(
		'tagline'        => 'Strong multilingual support, hosted in Europe',
		'pricing_detail' => 'Large: $2 / 1M input · $6 / 1M output',
		'rate_limits'    => '500 req/min',
		'best_for'       => 'Multilingual · EU data residency · cost-efficient',
		'models'         => agent_builder_setup_provider_models( 'mistral' ),
	),
	'llama'     => array(
		'tagline'        => 'Access Meta\'s open-source Llama models via API',
		'pricing_detail' => 'Usage-based · pay only for what you use',
		'rate_limits'    => 'Varies by plan',
		'best_for'       => 'Open-source · research · privacy-conscious devs',
		'models'         => agent_builder_setup_provider_models( 'llama' ),
	),
	'cohere'    => array(
		'tagline'        => 'Enterprise-ready AI with strong multilingual capabilities',
		'pricing_detail' => 'Free trial: 20 req/min · then usage-based',
		'rate_limits'    => '20 req/min (trial) · 10,000 req/min (production)',
		'best_for'       => 'Enterprise · multilingual · RAG pipelines',
		'models'         => agent_builder_setup_provider_models( 'cohere' ),
	),
	'kimi'      => array(
		'tagline'        => 'Moonshot AI — long context, strong coding & bilingual models',
		'pricing_detail' => 'K2.5 ~$0.60 / 1M input · K3 $3 / 1M input',
		'rate_limits'    => 'Varies by top-up tier',
		'best_for'       => 'Long context · coding · Chinese/English · agents',
		'models'         => agent_builder_setup_provider_models( 'kimi' ),
	),
	'deepseek'  => array(
		'tagline'        => 'Strong coding & reasoning models at aggressive prices',
		'pricing_detail' => 'Flash from ~$0.14 / 1M input · Pro for deeper reasoning',
		'rate_limits'    => 'Varies by account tier',
		'best_for'       => 'Coding · reasoning · cost-efficient agents',
		'models'         => agent_builder_setup_provider_models( 'deepseek' ),
	),
	'agentic'   => array(
		'tagline'        => 'A WordPress-optimised AI model hosted by agent-builder.com',
		'pricing_detail' => 'Free daily credits included',
		'rate_limits'    => 'Shared pool · requests can be slow at times',
		'best_for'       => 'Best for WordPress backend and development tasks',
		'models'         => agent_builder_setup_provider_models( 'agentic' ),
	),
	'ollama'    => array(
		'tagline'        => 'Run open-source models privately on your own server',
		'pricing_detail' => 'Completely free — you pay only for hardware',
		'rate_limits'    => 'Limited only by your hardware',
		'best_for'       => 'Privacy · offline use · custom models',
		'models'         => agent_builder_setup_provider_models( 'ollama' ),
	),
);

wp_enqueue_style( 'agentic-setup', AGENT_BUILDER_URL . 'assets/css/setup.css', array(), AGENT_BUILDER_VERSION );

?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php esc_html_e( 'Connect an AI Provider — Siteloom', 'siteloom' ); ?></title>
	<?php wp_head(); ?>
</head>
<body class="wp-admin">

<div class="setup-wrap">

	<!-- Header -->
	<div class="setup-header">
		<div class="setup-logo" aria-hidden="true">
			<img src="<?php echo esc_url( AGENT_BUILDER_URL . 'assets/icon.svg' ); ?>" width="48" height="48" alt="<?php esc_attr_e( 'Siteloom logo', 'siteloom' ); ?>">
		</div>
		<h1><?php esc_html_e( 'Welcome to Siteloom AI Agents', 'siteloom' ); ?></h1>
		<p><?php esc_html_e( 'Connect to your preferred AI Provider to get started. This takes only a few minutes.', 'siteloom' ); ?></p>
	</div>

	<!-- Step indicators -->
	<div class="setup-steps" id="setup-progress">
		<div class="setup-step-item active" id="progress-1">
			<div class="step-num">1</div>
			<div class="step-label"><?php esc_html_e( 'Choose provider', 'siteloom' ); ?></div>
		</div>
		<div class="step-connector"></div>
		<div class="setup-step-item" id="progress-2">
			<div class="step-num">2</div>
			<div class="step-label"><?php esc_html_e( 'Get API key', 'siteloom' ); ?></div>
		</div>
		<div class="step-connector"></div>
		<div class="setup-step-item" id="progress-3">
			<div class="step-num">3</div>
			<div class="step-label"><?php esc_html_e( 'Connect', 'siteloom' ); ?></div>
		</div>
		<div class="step-connector"></div>
		<div class="setup-step-item" id="progress-4">
			<div class="step-num">4</div>
			<div class="step-label"><?php esc_html_e( 'Test', 'siteloom' ); ?></div>
		</div>
	</div>

	<form method="post" action="" id="setup-form">
		<?php wp_nonce_field( 'agentic_setup', 'agentic_setup_nonce' ); ?>
		<input type="hidden" name="agent_builder_llm_provider" id="hidden_provider" value="">
		<input type="hidden" name="agentic_llm_api_key" id="hidden_api_key" value="">

		<div class="setup-card">

			<!-- ── Phase 1: Do you have an account? ─────────────────── -->
			<div class="setup-card-inner" id="phase-account">
				<div class="account-question">
					<h2><?php esc_html_e( 'Do you already have an account with an AI Provider?', 'siteloom' ); ?></h2>
					<p><?php esc_html_e( 'At least one AI Provider is needed in order for your AI Agents to respond to requests.', 'siteloom' ); ?></p>
					<p><strong><?php esc_html_e( 'Popular AI Providers:', 'siteloom' ); ?></strong></p>
					<ul class="provider-list">
						<?php foreach ( $agent_builder_providers as $agentic_p_slug => $agentic_p ) : ?>
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
							<?php esc_html_e( 'Yes, I have an account', 'siteloom' ); ?>
						</button>
						<button type="button" class="btn-account" onclick="showPhase('provider-pick', false)">
							<span class="btn-icon">🆕</span>
							<?php esc_html_e( "No, I'll create one now", 'siteloom' ); ?>
						</button>
					</div>
				</div>
			</div>

			<!-- ── Phase 2: Pick a provider ──────────────────────────── -->
			<div class="setup-card-inner" id="phase-provider-pick" style="display:none">
				<h2 class="agentic-setup-title" id="provider-pick-title">
					<?php esc_html_e( 'Choose your AI provider', 'siteloom' ); ?>
				</h2>
				<p class="agentic-setup-subtitle" id="provider-pick-sub">
					<?php esc_html_e( 'Select the provider you want to connect.', 'siteloom' ); ?>
				</p>
				<div class="provider-grid">
					<?php foreach ( $agent_builder_providers as $agentic_slug => $agentic_p ) : ?>
					<div class="provider-card" id="card-<?php echo esc_attr( $agentic_slug ); ?>" role="button" tabindex="0" aria-pressed="false" onclick="selectProvider('<?php echo esc_js( $agentic_slug ); ?>')" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();selectProvider('<?php echo esc_js( $agentic_slug ); ?>');}">
						<div class="provider-icon">
							<?php
							echo wp_kses(
								agent_builder_setup_provider_icon( $agentic_slug ),
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
			<?php foreach ( $agent_builder_providers as $agentic_slug => $agentic_p ) : ?>
			<div class="setup-card-inner provider-steps-section" id="phase-steps-<?php echo esc_attr( $agentic_slug ); ?>">
				<div class="provider-steps-header">
					<h2>
						<?php
						echo wp_kses(
							agent_builder_setup_provider_icon( $agentic_slug, 24 ),
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
					<label for="key-input-agentic"><?php esc_html_e( 'Email address', 'siteloom' ); ?></label>
					<div class="key-input-row" id="key-input-row-agentic">
						<input
							type="email"
							id="key-input-agentic"
							placeholder="you@example.com"
							autocomplete="email"
							oninput="onKeyInput('agentic')"
						>
						<button type="button" class="btn-secondary btn-save" id="btn-save-agentic" style="display:none" onclick="saveKey()"><?php esc_html_e( 'Register & Connect', 'siteloom' ); ?></button>
						<button type="button" class="btn-primary btn-continue-test" id="btn-continue-agentic" style="display:none" onclick="goNext()"><?php esc_html_e( 'Continue to Testing →', 'siteloom' ); ?></button>
					</div>
					<div class="agentic-wizard-consent" id="agentic-wizard-consent-wrap" style="display:none">
						<label><input type="checkbox" id="agentic-wizard-consent-tos"> 
						<?php
						echo wp_kses(
							sprintf( /* translators: %s: Terms link */ __( 'I agree to the <a href="https://agentic-plugin.com/terms-of-service/" target="_blank" rel="noopener">Terms of Service</a>', 'siteloom' ), '' ),
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
							sprintf( /* translators: %s: Privacy link */ __( 'I agree to the <a href="https://agentic-plugin.com/privacy-policy/" target="_blank" rel="noopener">Privacy Policy</a>', 'siteloom' ), '' ),
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
					<label><?php esc_html_e( 'Ollama Server URL', 'siteloom' ); ?></label>
					<p class="key-hint"><?php esc_html_e( 'Leave as default unless you changed the port or are using a remote server.', 'siteloom' ); ?></p>
					<div class="ollama-url-row">
						<input
							type="text"
							id="key-input-ollama"
							value="http://localhost:11434"
							placeholder="http://localhost:11434"
							autocomplete="off"
						>
						<button type="button" class="btn-primary agentic-mt-8" onclick="saveKey()"><?php esc_html_e( 'Connect to Ollama', 'siteloom' ); ?></button>
					</div>
					<?php else : ?>
					<label for="key-input-<?php echo esc_attr( $agentic_slug ); ?>"><?php esc_html_e( 'Paste your API key here once you have followed the steps below.', 'siteloom' ); ?></label>
					<div class="key-input-row" id="key-input-row-<?php echo esc_attr( $agentic_slug ); ?>">
						<input
							type="password"
							id="key-input-<?php echo esc_attr( $agentic_slug ); ?>"
							placeholder="<?php echo esc_attr( $agentic_p['placeholder'] ); ?>"
							autocomplete="off"
							oninput="onKeyInput('<?php echo esc_js( $agentic_slug ); ?>')"
						>
						<button type="button" class="btn-secondary btn-save" id="btn-save-<?php echo esc_attr( $agentic_slug ); ?>" style="display:none" onclick="saveKey()"><?php esc_html_e( 'Save', 'siteloom' ); ?></button>
					<button type="button" class="btn-primary btn-continue-test" id="btn-continue-<?php echo esc_attr( $agentic_slug ); ?>" style="display:none" onclick="goNext()"><?php esc_html_e( 'Continue to Testing →', 'siteloom' ); ?></button>
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
					<button type="button" class="btn-primary" onclick="openProviderKey('<?php echo esc_js( $agentic_slug ); ?>')"><?php esc_html_e( 'Get Key', 'siteloom' ); ?></button>
					<p class="get-key-note"><?php esc_html_e( 'Opens new window so you can perform the steps above. Remember to return here and save the key — only shown once.', 'siteloom' ); ?></p>
				</div>
				<?php endif; ?>
			</div><!-- .provider-steps-section -->
			<?php endforeach; ?>

			<!-- ── Phase 4: Test connection ──────────────────────────── -->
			<div class="setup-card-inner" id="phase-test" style="display:none">

				<!-- Model Selection -->
				<div class="wizard-pref-section">
					<h2><?php esc_html_e( 'Choose a model', 'siteloom' ); ?></h2>
					<p class="wizard-pref-hint"><?php esc_html_e( 'Each model has different strengths. You can change this any time in Settings.', 'siteloom' ); ?></p>
					<div id="wizard-model-grid" class="wizard-model-grid"></div>
				</div>

				<!-- Agent Mode -->
				<div class="wizard-pref-section">
					<h2><?php esc_html_e( 'Choose an agent mode', 'siteloom' ); ?></h2>
					<p class="wizard-pref-hint"><?php esc_html_e( 'Controls how much authority your AI agents have. You can change this any time in Settings.', 'siteloom' ); ?></p>
					<div class="wizard-mode-grid">
						<label class="wizard-mode-card" data-mode="supervised">
							<input type="radio" name="wizard_mode" value="supervised" checked>
							<div class="wizard-mode-header">
								<span class="wizard-mode-icon">👁️</span>
								<strong><?php esc_html_e( 'Supervised', 'siteloom' ); ?></strong>
								<span class="wizard-mode-badge"><?php esc_html_e( 'Recommended', 'siteloom' ); ?></span>
							</div>
							<p><?php esc_html_e( 'In Supervised mode, changes the AI proposes wait for your approval before they are saved or published.', 'siteloom' ); ?></p>
						</label>
						<label class="wizard-mode-card" data-mode="autonomous">
							<input type="radio" name="wizard_mode" value="autonomous">
							<div class="wizard-mode-header">
								<span class="wizard-mode-icon">⚡</span>
								<strong><?php esc_html_e( 'Autonomous', 'siteloom' ); ?></strong>
							</div>
							<p><?php esc_html_e( 'The AI executes tasks immediately without asking for approval. Best for experienced users who trust the agent\'s judgment.', 'siteloom' ); ?></p>
						</label>
						<label class="wizard-mode-card" data-mode="disabled">
							<input type="radio" name="wizard_mode" value="disabled">
							<div class="wizard-mode-header">
								<span class="wizard-mode-icon">💬</span>
								<strong><?php esc_html_e( 'Chat only', 'siteloom' ); ?></strong>
							</div>
							<p><?php esc_html_e( 'Agents can answer questions and give advice, but cannot read data from or make any changes to your site.', 'siteloom' ); ?></p>
						</label>
					</div>
				</div>

				<div class="wizard-test-section">
					<h2><?php esc_html_e( 'Test your connection', 'siteloom' ); ?></h2>
					<p><?php esc_html_e( 'Click below to verify your AI provider is responding correctly.', 'siteloom' ); ?></p>
					<button type="button" class="btn-primary" id="btn-test-connection" onclick="runWizardTest()">
						<?php esc_html_e( 'Test Connection', 'siteloom' ); ?>
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
						<h2><?php esc_html_e( 'Your WordPress site just got much smarter.', 'siteloom' ); ?></h2>
						<p><?php esc_html_e( 'Go ahead, talk to WordPress!!', 'siteloom' ); ?></p>
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
							<div class="wizard-chat-title-text"><?php esc_html_e( 'WordPress Assistant', 'siteloom' ); ?></div>
						</div>
						<div class="wizard-chat-messages" id="wizard-chat-messages">
							<div class="wizard-chat-msg wizard-chat-msg--agent">
								<span class="wizard-chat-bubble"><?php echo wp_kses_post( $agentic_wizard_greeting ); ?></span>
							</div>
						</div>
						<div class="wizard-suggested-prompts" id="wizard-suggested-prompts"></div>
						<div class="wizard-chat-input-wrap">
							<input type="text" id="wizard-chat-input" placeholder="<?php esc_attr_e( 'Ask me anything about your WordPress site…', 'siteloom' ); ?>" autocomplete="off" onkeydown="if(event.key==='Enter')sendWizardMessage()">
							<button type="button" id="wizard-mic-btn" class="wizard-mic-btn" title="<?php esc_attr_e( 'Voice input', 'siteloom' ); ?>" style="display:none;">
								<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3Z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" x2="12" y1="19" y2="22"/></svg>
							</button>
							<button type="button" id="wizard-chat-send" class="btn-primary" onclick="sendWizardMessage()"><?php esc_html_e( 'Send', 'siteloom' ); ?></button>
						</div>
					</div>

					<div class="wizard-exit-row">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=agent-builder' ) ); ?>" class="btn-secondary"><?php esc_html_e( 'Exit Wizard', 'siteloom' ); ?></a>
					</div>
				</div>
			</div><!-- #phase-test -->

			<div class="setup-card-footer" id="setup-footer">
				<div class="footer-left"></div>
				<div class="footer-right">
					<button type="button" class="btn-secondary" id="btn-back" style="display:none" onclick="goBack()">
						← <?php esc_html_e( 'Back', 'siteloom' ); ?>
					</button>
					<button type="button" class="btn-secondary" id="btn-next" style="display:none" onclick="goNext()">
						<?php esc_html_e( 'Next', 'siteloom' ); ?> →
					</button>
				</div>
			</div><!-- .setup-card-footer -->

		</div><!-- .setup-card -->
	</form>

</div><!-- .setup-wrap -->

<!-- Lightbox -->
<div class="lightbox" id="lightbox" onclick="closeLightbox()">
	<img src="" alt="<?php esc_attr_e( 'Enlarged preview', 'siteloom' ); ?>" id="lightbox-img">
</div>

<?php
\Agentic\Inline_Assets::script(
	'setup',
	array(
		'restNonce'          => wp_create_nonce( 'wp_rest' ),
		'restUrl'            => rest_url( 'agentic/v1/' ),
		'ajaxUrl'            => admin_url( 'admin-ajax.php' ),
		'ajaxNonce'          => wp_create_nonce( 'agentic_test_connection' ),
		'signupNonce'        => wp_create_nonce( 'agentic_signup' ),
		'providerSlugs'      => array_values( array_map( 'strval', array_keys( $agent_builder_providers ) ) ),
		'providerSignupUrls' => array_map( fn( $agentic_p ) => $agentic_p['signup_url'], $agent_builder_providers ),
		'providerNames'      => array_map( fn( $agentic_p ) => $agentic_p['name'], $agent_builder_providers ),
		'providerKeyUrls'    => array_map( fn( $agentic_p ) => $agentic_p['key_url'] ?? '', $agent_builder_providers ),
		'providerInfo'       => $agentic_provider_info,
		'suggestedPrompts'   => array_values( $agentic_wizard_prompts ),
		'agentShortcuts'     => array_values( $agentic_wizard_shortcuts ),
		'chatPageUrl'        => admin_url( 'admin.php?page=agentic-chat' ),
	),
	'agenticSetupPage',
	array( 'agentic-ui' )
);
?>

<?php wp_footer(); ?>
</body>
</html>
<?php
// ── Provider icon helper ──────────────────────────────────────────────────────

/**
 * Returns an inline SVG icon (or img tag) for the given provider icon value.
 * Defined in agentbuilder.php (always loaded).
 *
 * @see agent_builder_setup_provider_icon()
 */
