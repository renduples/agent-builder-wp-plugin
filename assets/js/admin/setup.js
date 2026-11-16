/**
 * Setup wizard (admin.php?page=agentic-setup).
 *
 * Moved out of admin/setup.php. Per-request data comes from the
 * window.agenticSetupPage object added by Inline_Assets::script().
 * Functions stay global: the markup calls them from onclick attributes.
 */
/* global agenticSetupPage, agenticUI */
var hasAccount = false;
var selectedProvider = null;
var wizardKeySaved = false;
var wizardSavedProvider = null;
var wizardSavedKey = null;
var wizardSessionId = 'wizard-' + Math.random().toString(36).substr(2, 9);
var wizardChatHistory = [];
var wizardChatNonce = agenticSetupPage.restNonce;
var wizardRestUrl    = agenticSetupPage.restUrl;
var ajaxUrl = agenticSetupPage.ajaxUrl;
var ajaxNonce = agenticSetupPage.ajaxNonce;
var signupNonce = agenticSetupPage.signupNonce;
var providerSignupUrls = agenticSetupPage.providerSignupUrls;
var providerNames      = agenticSetupPage.providerNames;
var providerInfoData       = agenticSetupPage.providerInfo;
var wizardSuggestedPrompts = agenticSetupPage.suggestedPrompts;
var wizardAgentShortcuts   = agenticSetupPage.agentShortcuts;
var wizardChatPageUrl      = agenticSetupPage.chatPageUrl;

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
	agenticSetupPage.providerSlugs.forEach(function(slug) {
		phases.push('phase-steps-' + slug);
	});

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
	agenticSetupPage.providerSlugs.forEach(function(slug) {
		phases.push('phase-steps-' + slug);
	});

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
	agenticSetupPage.providerSlugs.forEach(function(slug) {
		phases.push('phase-steps-' + slug);
	});

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

var providerKeyUrls = agenticSetupPage.providerKeyUrls;

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
		fd.append('agree_terms', '1');
		fd.append('agree_privacy', '1');
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
