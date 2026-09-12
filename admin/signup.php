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
			<?php esc_html_e( 'Connect your AI agents to an LLM Provider with just one click.', 'agent-builder' ); ?>
			<br><?php esc_html_e( 'Free daily credits, no payment info required.', 'agent-builder' ); ?>
		</p>

		<form id="agentic-signup-form" method="post">
			<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $agentic_signup_nonce ); ?>">
			<input type="hidden" name="plugin_version" value="<?php echo esc_attr( $agentic_signup_version ); ?>">

			<!-- ── Registration ─────────────────────────────── -->
			<h3 class="agentic-signup-section-heading"><?php esc_html_e( 'Registration', 'agent-builder' ); ?></h3>

			<div class="agentic-signup-field" id="agentic-signup-email-field">
				<label for="agentic-signup-email"><?php esc_html_e( 'Email Address', 'agent-builder' ); ?></label>
				<input type="email" id="agentic-signup-email" name="email" value="<?php echo esc_attr( $agentic_signup_email ); ?>">
				<p class="agentic-field-note"><?php esc_html_e( 'Free daily credits and API key will be sent to this email address.', 'agent-builder' ); ?></p>
				<p class="agentic-inline-error" id="agentic-email-error" hidden></p>
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

			<!-- ── Consent ──────────────────────────────────── -->
			<div class="agentic-signup-consent" id="agentic-signup-consent">
				<label>
					<input type="checkbox" id="agentic-agree-terms" name="agree_terms" value="1">
					<?php
					printf(
						/* translators: 1: opening link tag, 2: closing link tag */
						esc_html__( 'I agree to the %1$sTerms & Conditions%2$s', 'agent-builder' ),
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
				<p class="agentic-inline-error" id="agentic-consent-error" hidden></p>
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
				esc_html__( '%1$sSkip — I will pay for tokens with my own LLM Provider.%2$s', 'agent-builder' ),
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

<script>
(function () {
	var form         = document.getElementById( 'agentic-signup-form' );
	var btn          = document.getElementById( 'agentic-signup-btn' );
	var msgEl        = document.getElementById( 'agentic-signup-msg' );
	var terms        = document.getElementById( 'agentic-agree-terms' );
	var privacy      = document.getElementById( 'agentic-agree-privacy' );
	var modeSelect   = document.getElementById( 'agentic-signup-agent-mode' );
	var uiModeSelect = document.getElementById( 'agentic-signup-ui-mode' );
	var loadingBadge = document.getElementById( 'agentic-models-loading' );
	var emailField   = document.getElementById( 'agentic-signup-email-field' );
	var emailInput   = document.getElementById( 'agentic-signup-email' );
	var emailError   = document.getElementById( 'agentic-email-error' );
	var consentError = document.getElementById( 'agentic-consent-error' );
	var pricingUrl   = <?php echo wp_json_encode( $agentic_pricing_url ); ?>;

	// ── Explanation metadata ──────────────────────────────────────────────────

	var modeExplanations = {
		supervised: {
			title: <?php echo wp_json_encode( __( 'Supervised', 'agent-builder' ) ); ?>,
			body : <?php echo wp_json_encode( __( 'Agents propose actions which are queued for your review before they run. Recommended for most sites.', 'agent-builder' ) ); ?>
		},
		autonomous: {
			title: <?php echo wp_json_encode( __( 'Autonomous', 'agent-builder' ) ); ?>,
			body : <?php echo wp_json_encode( __( 'Agents act immediately without waiting for approval. Use only on sites where you fully trust the agents.', 'agent-builder' ) ); ?>
		},
		disabled: {
			title: <?php echo wp_json_encode( __( 'Disabled', 'agent-builder' ) ); ?>,
			body : <?php echo wp_json_encode( __( 'Chat only — all tools and site actions are disabled. Agents cannot modify content or call external services.', 'agent-builder' ) ); ?>
		}
	};

	var uiModeExplanations = {
		basic: {
			title: <?php echo wp_json_encode( __( 'Basic', 'agent-builder' ) ); ?>,
			body : <?php echo wp_json_encode( __( 'Guided, plain language, best for getting started', 'agent-builder' ) ); ?>
		},
		advanced: {
			title: <?php echo wp_json_encode( __( 'Advanced', 'agent-builder' ) ); ?>,
			body : <?php echo wp_json_encode( __( 'Full console, best if you\'ve used developer tools like this before', 'agent-builder' ) ); ?>
		}
	};

	var llmShortLabels = {
		'gemini-2.5-flash'      : 'Flash',
		'gemini-2.5-flash-lite' : 'Flash Lite',
		'gemini-2.5-pro'        : 'Pro',
		'gemma-4-26b'           : 'Gemma 4'
	};

	var llmDescriptions = {
		'gemini-2.5-flash'      : <?php echo wp_json_encode( __( 'Fast and capable. Best balance of speed and quality for most tasks.', 'agent-builder' ) ); ?>,
		'gemini-2.5-flash-lite' : <?php echo wp_json_encode( __( 'Fastest and most efficient. Ideal for simple, high-volume queries.', 'agent-builder' ) ); ?>,
		'gemini-2.5-pro'        : <?php echo wp_json_encode( __( 'Most powerful. Best for complex reasoning and demanding tasks.', 'agent-builder' ) ); ?>,
		'gemma-4-26b'           : <?php echo wp_json_encode( __( 'Lightweight open model. Very efficient and low credit cost.', 'agent-builder' ) ); ?>
	};

	var ttsData = {
		'journey-f' : { title: 'Journey Female',  family: 'journey',  body: <?php echo wp_json_encode( __( 'Neural voice. Natural, expressive, and highly lifelike.', 'agent-builder' ) ); ?> },
		'journey-d' : { title: 'Journey Male',    family: 'journey',  body: <?php echo wp_json_encode( __( 'Neural voice. Natural, expressive, and highly lifelike.', 'agent-builder' ) ); ?> },
		'journey-o' : { title: 'Journey Neutral', family: 'journey',  body: <?php echo wp_json_encode( __( 'Neural voice. Gender-neutral, natural-sounding speech.', 'agent-builder' ) ); ?> },
		'neural2-c' : { title: 'Neural2 Female',  family: 'neural2',  body: <?php echo wp_json_encode( __( 'High-quality neural voice. Clear and professional.', 'agent-builder' ) ); ?> },
		'neural2-d' : { title: 'Neural2 Male',    family: 'neural2',  body: <?php echo wp_json_encode( __( 'High-quality neural voice. Clear and professional.', 'agent-builder' ) ); ?> },
		'standard-f': { title: 'Standard Female', family: 'standard', body: <?php echo wp_json_encode( __( 'Standard synthesised voice. Lightweight and low credit usage.', 'agent-builder' ) ); ?> },
		'standard-b': { title: 'Standard Male',   family: 'standard', body: <?php echo wp_json_encode( __( 'Standard synthesised voice. Lightweight and low credit usage.', 'agent-builder' ) ); ?> }
	};

	var videoDescriptions = {
		'veo-2': <?php echo wp_json_encode( __( 'AI video generation, visual only. Reliable and efficient.', 'agent-builder' ) ); ?>,
		'veo-3': <?php echo wp_json_encode( __( 'AI video with native audio. Higher quality, higher credit cost.', 'agent-builder' ) ); ?>
	};

	var videoIdNormalise = { 'veo2': 'veo-2', 'veo3': 'veo-3' };

	// Live pricing (populated after fetch).
	var chatOps   = {};
	var videoOps  = {};
	var ttsOps    = {};
	var freeQuota = {};

	// ── Helpers ───────────────────────────────────────────────────────────────

	function escHtml( s ) {
		return String( s ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' );
	}

	function fmtN( n ) {
		return parseFloat( n.toFixed( 4 ) ).toString();
	}

	function renderExpl( elId, title, body, quotaLine, costLine ) {
		var el   = document.getElementById( elId );
		if ( ! el ) return;
		var html = '<strong>' + escHtml( title ) + '</strong>';
		if ( body )      html += '<p>' + escHtml( body ) + '</p>';
		if ( quotaLine ) html += '<p class="explain-quota">' + escHtml( quotaLine ) + '</p>';
		if ( costLine )  html += '<p class="explain-cost">'  + escHtml( costLine  ) + '</p>';
		el.innerHTML = html;
	}

	// ── Per-field explanation updaters ────────────────────────────────────────

	function updateModeExpl() {
		var exp = modeExplanations[ modeSelect.value ];
		if ( exp ) renderExpl( 'agentic-explain-mode', exp.title, exp.body, null, null );
	}

	function updateUiModeExpl() {
		var exp = uiModeExplanations[ uiModeSelect.value ];
		if ( exp ) renderExpl( 'agentic-explain-ui-mode', exp.title, exp.body, null, null );
	}

	function updateChatExpl( selId, explId ) {
		var val  = document.getElementById( selId ).value;
		var name = llmShortLabels[ val ]   || val;
		var desc = llmDescriptions[ val ]  || '';
		var quotaLine = '';
		var costLine  = '';
		if ( freeQuota.chat_queries_day !== undefined ) {
			quotaLine = 'Free daily quota: ' + freeQuota.chat_queries_day + ' queries · ' + freeQuota.chat_tokens_day.toLocaleString() + ' tokens';
		}
		if ( chatOps[ val ] ) {
			costLine = 'Top-up: ' + fmtN( chatOps[ val ].in * 1000 ) + ' cr / 1K in · ' + fmtN( chatOps[ val ].out * 1000 ) + ' cr / 1K out';
		}
		renderExpl( explId, name, desc, quotaLine, costLine );
	}

	function updateTtsExpl() {
		var val  = document.getElementById( 'agentic-signup-tts-voice' ).value;
		var meta = ttsData[ val ] || { title: val, family: '', body: '' };
		var quotaLine = '';
		var costLine  = '';
		if ( freeQuota.tts_chars_day !== undefined ) {
			quotaLine = 'Free daily quota: ' + freeQuota.tts_chars_day.toLocaleString() + ' characters';
		}
		if ( ttsOps[ meta.family ] !== undefined ) {
			costLine = 'Top-up: ' + fmtN( ttsOps[ meta.family ] ) + ' credits / 1,000 characters';
		}
		renderExpl( 'agentic-explain-tts', meta.title, meta.body, quotaLine, costLine );
	}

	function updateVideoExpl() {
		var val  = document.getElementById( 'agentic-signup-video-model' ).value;
		var name = val === 'veo-2' ? 'Veo 2' : val === 'veo-3' ? 'Veo 3' : val;
		var desc = videoDescriptions[ val ] || '';
		var quotaLine = '';
		var costLine  = '';
		if ( freeQuota.videogen_day !== undefined ) {
			quotaLine = freeQuota.videogen_day === 0
				? 'Free daily quota: not included in free plan'
				: 'Free daily quota: ' + freeQuota.videogen_day + ' videos / day';
		}
		if ( videoOps[ val ] !== undefined ) {
			costLine = 'Top-up: ' + videoOps[ val ] + ' credits / second';
		}
		renderExpl( 'agentic-explain-video', name, desc, quotaLine, costLine );
	}

	// Render immediately with fallback data (no quota/cost lines yet).
	updateModeExpl();
	updateUiModeExpl();
	updateChatExpl( 'agentic-signup-model',        'agentic-explain-chat'   );
	updateChatExpl( 'agentic-signup-vision-model',  'agentic-explain-vision' );
	updateTtsExpl();
	updateVideoExpl();

	// ── Change events ─────────────────────────────────────────────────────────

	modeSelect.addEventListener( 'change', updateModeExpl );
	uiModeSelect.addEventListener( 'change', updateUiModeExpl );
	document.getElementById( 'agentic-signup-model' ).addEventListener( 'change', function () {
		updateChatExpl( 'agentic-signup-model', 'agentic-explain-chat' );
	} );
	document.getElementById( 'agentic-signup-vision-model' ).addEventListener( 'change', function () {
		updateChatExpl( 'agentic-signup-vision-model', 'agentic-explain-vision' );
	} );
	document.getElementById( 'agentic-signup-tts-voice'   ).addEventListener( 'change', updateTtsExpl   );
	document.getElementById( 'agentic-signup-video-model' ).addEventListener( 'change', updateVideoExpl );

	function clearConsentError() {
		if ( terms.checked && privacy.checked ) {
			consentError.hidden = true;
		}
	}
	terms.addEventListener( 'change', clearConsentError );
	privacy.addEventListener( 'change', clearConsentError );
	emailInput.addEventListener( 'input', function () {
		if ( emailInput.value.trim() ) {
			emailField.classList.remove( 'agentic-field-error' );
			emailError.hidden = true;
		}
	} );

	// ── Reset to optimised defaults ───────────────────────────────────────────

	var performanceDefaults = {
		'agentic-signup-agent-mode'  : 'supervised',
		'agentic-signup-ui-mode'     : 'basic',
		'agentic-signup-model'       : 'gemini-2.5-flash',
		'agentic-signup-vision-model': 'gemini-2.5-flash',
		'agentic-signup-tts-voice'   : 'journey-f',
		'agentic-signup-video-model' : 'veo-2'
	};

	document.getElementById( 'agentic-reset-defaults' ).addEventListener( 'click', function () {
		Object.keys( performanceDefaults ).forEach( function ( id ) {
			var el = document.getElementById( id );
			if ( el ) el.value = performanceDefaults[ id ];
		} );
		updateModeExpl();
		updateUiModeExpl();
		updateChatExpl( 'agentic-signup-model',        'agentic-explain-chat'   );
		updateChatExpl( 'agentic-signup-vision-model',  'agentic-explain-vision' );
		updateTtsExpl();
		updateVideoExpl();
	} );

	// ── Pricing endpoint fetch ────────────────────────────────────────────────

	function repopulateSelect( selectEl, options, defaultVal ) {
		var prev = selectEl.value;
		selectEl.innerHTML = '';
		options.forEach( function ( opt ) {
			var o         = document.createElement( 'option' );
			o.value       = opt.value;
			o.textContent = opt.label;
			selectEl.appendChild( o );
		} );
		var ids    = options.map( function ( o ) { return o.value; } );
		selectEl.value = ids.indexOf( prev ) !== -1       ? prev
						: ids.indexOf( defaultVal ) !== -1 ? defaultVal
						: ( ids[0] || '' );
	}

	fetch( pricingUrl, { cache: 'no-store' } )
		.then( function ( r ) { return r.ok ? r.json() : Promise.reject( r.status ); } )
		.then( function ( data ) {
			// Chat model list: extract unique IDs from operations.chat keys (modelId_in / modelId_out).
			var rawChat  = ( data.operations || {} ).chat || {};
			var modelIds = [];
			Object.keys( rawChat ).forEach( function ( key ) {
				if ( key.slice( -3 ) === '_in' ) {
					var id = key.slice( 0, -3 );
					chatOps[ id ] = { in: rawChat[ key ], out: 0 };
					modelIds.push( id );
				}
			} );
			Object.keys( rawChat ).forEach( function ( key ) {
				if ( key.slice( -4 ) === '_out' ) {
					var id = key.slice( 0, -4 );
					if ( chatOps[ id ] ) chatOps[ id ].out = rawChat[ key ];
				}
			} );

			if ( modelIds.length ) {
				var llmOpts = modelIds.map( function ( id ) {
					return { value: id, label: llmShortLabels[ id ] || id };
				} );
				repopulateSelect( document.getElementById( 'agentic-signup-model' ),        llmOpts, 'gemini-2.5-flash' );
				repopulateSelect( document.getElementById( 'agentic-signup-vision-model' ), llmOpts, 'gemini-2.5-flash' );
			}

			// Video models.
			var rawVideo = ( data.operations || {} ).video || {};
			Object.keys( rawVideo ).forEach( function ( rawId ) {
				var normId = videoIdNormalise[ rawId ] || rawId;
				videoOps[ normId ] = rawVideo[ rawId ];
			} );
			if ( Object.keys( videoOps ).length ) {
				var videoOpts = Object.keys( videoOps ).map( function ( id ) {
					return { value: id, label: id === 'veo-2' ? 'Veo 2' : id === 'veo-3' ? 'Veo 3' : id };
				} );
				repopulateSelect( document.getElementById( 'agentic-signup-video-model' ), videoOpts, 'veo-2' );
			}

			ttsOps    = ( data.operations || {} ).tts  || {};
			freeQuota = ( ( data.quotas   || {} ).free ) || {};

			// Re-render all explanations with live quota + cost data.
			updateChatExpl( 'agentic-signup-model',        'agentic-explain-chat'   );
			updateChatExpl( 'agentic-signup-vision-model',  'agentic-explain-vision' );
			updateTtsExpl();
			updateVideoExpl();

			if ( loadingBadge ) loadingBadge.style.display = 'none';
		} )
		.catch( function () {
			if ( loadingBadge ) loadingBadge.style.display = 'none';
		} );

	// ── Form submission ───────────────────────────────────────────────────────

	form.addEventListener( 'submit', function ( e ) {
		e.preventDefault();
		msgEl.textContent = '';
		msgEl.className   = 'agentic-signup-msg';
		emailField.classList.remove( 'agentic-field-error' );
		emailError.hidden   = true;
		consentError.hidden = true;

		if ( ! terms.checked || ! privacy.checked ) {
			consentError.textContent = <?php echo wp_json_encode( __( 'Please agree to the Terms & Conditions and Privacy Policy.', 'agent-builder' ) ); ?>;
			consentError.hidden      = false;
			return;
		}

		var email = emailInput.value.trim();
		if ( ! email ) {
			emailError.textContent = <?php echo wp_json_encode( __( 'Please enter your email address.', 'agent-builder' ) ); ?>;
			emailError.hidden      = false;
			emailField.classList.add( 'agentic-field-error' );
			return;
		}

		btn.disabled    = true;
		btn.textContent = <?php echo wp_json_encode( __( 'Registering…', 'agent-builder' ) ); ?>;

		fetch( ajaxurl, {
			method : 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body   : new URLSearchParams( {
				action        : 'agentic_signup_api_key',
				_ajax_nonce   : form.querySelector( '[name="_wpnonce"]' ).value,
				email         : email,
				site_name     : document.getElementById( 'agentic-signup-sitename' ).value.trim(),
				site_url      : document.getElementById( 'agentic-signup-siteurl' ).value.trim(),
				plugin_version: form.querySelector( '[name="plugin_version"]' ).value,
				agent_mode    : modeSelect.value,
				ui_mode       : uiModeSelect.value,
				chat_model    : document.getElementById( 'agentic-signup-model' ).value,
				vision_model  : document.getElementById( 'agentic-signup-vision-model' ).value,
				tts_voice     : document.getElementById( 'agentic-signup-tts-voice' ).value,
				video_model   : document.getElementById( 'agentic-signup-video-model' ).value
			} )
		} )
		.then( function ( r ) { return r.json(); } )
		.then( function ( data ) {
			if ( data.success ) {
				msgEl.textContent = <?php echo wp_json_encode( __( 'API key saved — redirecting…', 'agent-builder' ) ); ?>;
				msgEl.className   = 'agentic-signup-msg success';
				setTimeout( function () {
					window.location.href = <?php echo wp_json_encode( admin_url( 'admin.php?page=agentic-chat&agent=wordpress-assistant' ) ); ?>;
				}, 800 );
			} else {
				btn.disabled    = false;
				btn.textContent = <?php echo wp_json_encode( __( 'Get Free API Key', 'agent-builder' ) ); ?>;
				msgEl.textContent = ( data.data && data.data.message ) ? data.data.message : <?php echo wp_json_encode( __( 'Registration failed. Please try again.', 'agent-builder' ) ); ?>;
				msgEl.className   = 'agentic-signup-msg error';
			}
		} )
		.catch( function () {
			btn.disabled    = false;
			btn.textContent = <?php echo wp_json_encode( __( 'Get Free API Key', 'agent-builder' ) ); ?>;
			msgEl.textContent = <?php echo wp_json_encode( __( 'Connection error. Please try again.', 'agent-builder' ) ); ?>;
			msgEl.className   = 'agentic-signup-msg error';
		} );
	} );
}());
</script>
