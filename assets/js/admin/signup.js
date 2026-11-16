/**
 * Quick Start / Agentic AI sign-up page (admin.php?page=agentic-signup).
 *
 * Moved out of admin/signup.php. Per-request data and translated strings
 * come from window.agenticSignupPage (added by Inline_Assets::script()).
 */
/* global agenticSignupPage, ajaxurl */
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
	var consentText  = document.getElementById( 'agentic-consent-error-text' );
	var pricingUrl   = agenticSignupPage.pricingUrl;

	// ── Explanation metadata ──────────────────────────────────────────────────

	var modeExplanations = {
		supervised: {
			title: agenticSignupPage.i18n.modeSupervised,
			body : agenticSignupPage.i18n.modeSupervisedBody
		},
		autonomous: {
			title: agenticSignupPage.i18n.modeAutonomous,
			body : agenticSignupPage.i18n.modeAutonomousBody
		},
		disabled: {
			title: agenticSignupPage.i18n.modeDisabled,
			body : agenticSignupPage.i18n.modeDisabledBody
		}
	};

	var uiModeExplanations = {
		basic: {
			title: agenticSignupPage.i18n.uiBasic,
			body : agenticSignupPage.i18n.uiBasicBody
		},
		advanced: {
			title: agenticSignupPage.i18n.uiAdvanced,
			body : agenticSignupPage.i18n.uiAdvancedBody
		}
	};

	var llmShortLabels = {
		'gemini-2.5-flash'      : 'Flash',
		'gemini-2.5-flash-lite' : 'Flash Lite',
		'gemini-2.5-pro'        : 'Pro',
		'gemma-4-26b'           : 'Gemma 4'
	};

	// Models shown by their short name in the main list, in this order.
	var friendlyModels   = [ 'gemini-2.5-flash', 'gemini-2.5-flash-lite', 'gemini-2.5-pro' ];
	var otherModelsLabel = agenticSignupPage.i18n.otherModels;

	var llmDescriptions = {
		'gemini-2.5-flash'      : agenticSignupPage.i18n.llmFlash,
		'gemini-2.5-flash-lite' : agenticSignupPage.i18n.llmFlashLite,
		'gemini-2.5-pro'        : agenticSignupPage.i18n.llmPro,
		'gemma-4-26b'           : agenticSignupPage.i18n.llmGemma
	};

	var ttsData = {
		'journey-f' : { title: 'Journey Female',  family: 'journey',  body: agenticSignupPage.i18n.voiceNeural },
		'journey-d' : { title: 'Journey Male',    family: 'journey',  body: agenticSignupPage.i18n.voiceNeural },
		'journey-o' : { title: 'Journey Neutral', family: 'journey',  body: agenticSignupPage.i18n.voiceNeutral },
		'neural2-c' : { title: 'Neural2 Female',  family: 'neural2',  body: agenticSignupPage.i18n.voiceNeural2 },
		'neural2-d' : { title: 'Neural2 Male',    family: 'neural2',  body: agenticSignupPage.i18n.voiceNeural2 },
		'standard-f': { title: 'Standard Female', family: 'standard', body: agenticSignupPage.i18n.voiceStandard },
		'standard-b': { title: 'Standard Male',   family: 'standard', body: agenticSignupPage.i18n.voiceStandard }
	};

	var videoDescriptions = {
		'veo-2': agenticSignupPage.i18n.videoVeo2,
		'veo-3': agenticSignupPage.i18n.videoVeo3
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

	function renderExpl( elId, title, body, quotaLine, costLine, quotaNone ) {
		var el   = document.getElementById( elId );
		if ( ! el ) return;
		var html = '<strong>' + escHtml( title ) + '</strong>';
		if ( body )      html += '<p>' + escHtml( body ) + '</p>';
		if ( quotaLine ) html += '<p class="explain-quota' + ( quotaNone ? ' is-none' : '' ) + '">' + escHtml( quotaLine ) + '</p>';
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
		var quotaNone = false;
		if ( freeQuota.videogen_day !== undefined ) {
			quotaNone = freeQuota.videogen_day === 0;
			quotaLine = quotaNone
				? agenticSignupPage.i18n.quotaNone
				: 'Free daily quota: ' + freeQuota.videogen_day + ' videos / day';
		}
		if ( videoOps[ val ] !== undefined ) {
			costLine = 'Top-up: ' + videoOps[ val ] + ' credits / second';
		}
		renderExpl( 'agentic-explain-video', name, desc, quotaLine, costLine, quotaNone );
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
		if ( terms.checked ) {
			terms.removeAttribute( 'aria-invalid' );
		}
		if ( privacy.checked ) {
			privacy.removeAttribute( 'aria-invalid' );
		}
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
		var prev   = selectEl.value;
		var groups = {};
		selectEl.innerHTML = '';
		options.forEach( function ( opt ) {
			var o         = document.createElement( 'option' );
			o.value       = opt.value;
			o.textContent = opt.label;
			if ( opt.group ) {
				if ( ! groups[ opt.group ] ) {
					groups[ opt.group ]       = document.createElement( 'optgroup' );
					groups[ opt.group ].label = opt.group;
				}
				groups[ opt.group ].appendChild( o );
			} else {
				selectEl.appendChild( o );
			}
		} );
		Object.keys( groups ).forEach( function ( label ) {
			selectEl.appendChild( groups[ label ] );
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
				// Friendly names (Flash, Flash Lite, Pro) are the main list;
				// every other model the service offers is still selectable,
				// grouped under "Other models" by its own name.
				var friendlyOpts = [];
				var otherOpts    = [];
				modelIds.forEach( function ( id ) {
					if ( friendlyModels.indexOf( id ) !== -1 ) {
						friendlyOpts.push( { value: id, label: llmShortLabels[ id ] } );
					} else {
						otherOpts.push( { value: id, label: llmShortLabels[ id ] || id, group: otherModelsLabel } );
					}
				} );
				friendlyOpts.sort( function ( a, b ) {
					return friendlyModels.indexOf( a.value ) - friendlyModels.indexOf( b.value );
				} );
				var llmOpts = friendlyOpts.concat( otherOpts );
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
			consentText.textContent = agenticSignupPage.i18n.consentRequired;
			consentError.hidden     = false;
			terms.setAttribute( 'aria-invalid', terms.checked ? 'false' : 'true' );
			privacy.setAttribute( 'aria-invalid', privacy.checked ? 'false' : 'true' );
			( terms.checked ? privacy : terms ).focus();
			return;
		}

		var email = emailInput.value.trim();
		if ( ! email ) {
			emailError.textContent = agenticSignupPage.i18n.emailRequired;
			emailError.hidden      = false;
			emailField.classList.add( 'agentic-field-error' );
			return;
		}

		btn.disabled    = true;
		btn.textContent = agenticSignupPage.i18n.registering;

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
				video_model   : document.getElementById( 'agentic-signup-video-model' ).value,
				agree_terms   : terms.checked ? '1' : '',
				agree_privacy : privacy.checked ? '1' : ''
			} )
		} )
		.then( function ( r ) { return r.json(); } )
		.then( function ( data ) {
			if ( data.success ) {
				msgEl.textContent = agenticSignupPage.i18n.keySaved;
				msgEl.className   = 'agentic-signup-msg success';
				setTimeout( function () {
					window.location.href = agenticSignupPage.dashboardUrl;
				}, 800 );
			} else {
				btn.disabled    = false;
				btn.textContent = agenticSignupPage.i18n.getFreeKey;
				msgEl.textContent = ( data.data && data.data.message ) ? data.data.message : agenticSignupPage.i18n.registrationFailed;
				msgEl.className   = 'agentic-signup-msg error';
			}
		} )
		.catch( function () {
			btn.disabled    = false;
			btn.textContent = agenticSignupPage.i18n.getFreeKey;
			msgEl.textContent = agenticSignupPage.i18n.connectionError;
			msgEl.className   = 'agentic-signup-msg error';
		} );
	} );
}());
