/**
 * Settings page JavaScript for LLM provider configuration
 */

// LLM Provider configuration
// LLM provider UI configuration.
// Model lists are fetched dynamically from the provider table via the REST API.
// The 'models' objects here are intentionally empty — they existed as hardcoded
// fallbacks but the provider DB table is now the single source of truth.
const llmProviders = {
	openai: {
		name: 'OpenAI',
		docs: 'https://platform.openai.com/api-keys',
		modelDocs: 'https://platform.openai.com/docs/models',
		models: {},
		default: 'gpt-4o',
		steps: [
			'Click the "Get API Key" button above to visit OpenAI Platform',
			'Sign up or log in to your OpenAI account',
			'Navigate to the API Keys section',
			'Click "Create new secret key" and give it a name',
			'Copy the generated API key and paste it below'
		]
	},
	anthropic: {
		name: 'Anthropic',
		docs: 'https://console.anthropic.com/settings/keys',
		modelDocs: 'https://docs.anthropic.com/en/docs/about-claude/models/overview',
		models: {},
		default: 'claude-3-5-sonnet-20241022',
		steps: [
			'Click the "Get API Key" button above to visit Anthropic Console',
			'Sign up or log in to your Anthropic account',
			'Go to Settings → API Keys',
			'Click "Create Key" and provide a name',
			'Copy the generated API key and paste it below'
		]
	},
	xai: {
		name: 'xAI',
		docs: 'https://console.x.ai/',
		modelDocs: 'https://docs.x.ai/docs/models',
		models: {},
		default: 'grok-3',
		steps: [
			'Click the "Get API Key" button above to visit xAI Console',
			'Sign up or log in to your xAI account',
			'Navigate to the API Keys section',
			'Click "Create API Key" and follow the prompts',
			'Copy the generated API key and paste it below'
		]
	},
	google: {
		name: 'Google',
		docs: 'https://makersuite.google.com/app/apikey',
		modelDocs: 'https://ai.google.dev/gemini-api/docs/models',
		models: {},
		default: 'gemini-2.0-flash-exp',
		steps: [
			'Click the "Get API Key" button above to visit Google AI Studio',
			'Sign in with your Google account',
			'Click "Get API key" in the left sidebar',
			'Click "Create API key" and select your Google Cloud project',
			'Copy the generated API key and paste it below'
		]
	},
	mistral: {
		name: 'Mistral',
		docs: 'https://console.mistral.ai/api-keys/',
		modelDocs: 'https://docs.mistral.ai/getting-started/models/models_overview/',
		models: {},
		default: 'mistral-large-latest',
		steps: [
			'Click the "Get API Key" button above to visit Mistral AI Console',
			'Sign up or log in to your Mistral AI account',
			'Go to the API Keys section',
			'Click "Create new key" and provide a name',
			'Copy the generated API key and paste it below'
		]
	},
	ollama: {
		name: 'Ollama',
		docs: 'https://ollama.com',
		modelDocs: 'https://ollama.com/library',
		models: {},
		default: 'llama3.2',
		steps: [
			'Download and install Ollama from ollama.com',
			'Pull a model: <code>ollama pull llama3.2</code>',
			'Ollama runs a server at http://localhost:11434 by default',
			'Enter the server URL in the field below (no API key needed)',
		]
	},
	llama: {
		name: 'Meta Llama',
		docs: 'https://llama.developer.meta.com/',
		modelDocs: 'https://llama.developer.meta.com/docs/models',
		models: {},
		default: 'Llama-3.3-70B-Instruct',
		steps: [
			'Click the "Get API Key" button above to visit the Meta Llama API console',
			'Sign in with your Meta account',
			'Create a new API key in your dashboard',
			'Copy the key and paste it below',
		]
	},
	cohere: {
		name: 'Cohere',
		docs: 'https://dashboard.cohere.com/api-keys',
		modelDocs: 'https://docs.cohere.com/docs/models',
		models: {},
		default: 'command-r-plus-08-2024',
		steps: [
			'Click the "Get API Key" button above to visit Cohere Dashboard',
			'Sign up or log in to your Cohere account',
			'Go to API Keys in the left sidebar',
			'Click "New Trial Key" or "New Production Key"',
			'Copy the generated key and paste it below',
		]
	},
	agentic: {
		name: 'Agentic AI (Free)',
		docs: 'https://agentic-plugin.com/documentation/',
		modelDocs: 'https://agentic-plugin.com/documentation/',
		models: {},
		default: 'gemini-2.5-flash',
		noApiKey: true,
		steps: []
	}
};

// Per-provider state — populated in DOMContentLoaded from agenticSettings (via wp_localize_script).
let agenticProviderKeys         = {};
let agenticProviderModels       = {};
let agenticProviderVisionModels = {};
let agenticGlobalDefault        = 'agentic';
let agenticCurrentProvider      = '';

// Per-session cache to avoid repeated API calls for the same provider.
const modelCache = {};

/**
 * Fetch the available models for a provider from the server (provider DB table).
 * Returns null on failure so callers can fall back to the provider's default model.
 */
async function fetchProviderModels(provider) {
	if (modelCache[provider]) {
		return modelCache[provider];
	}
	try {
		// agenticSettings is passed by PHP (wp_add_inline_script) with rest_url().
		if (typeof agenticSettings === 'undefined' || !agenticSettings.restUrl) {
			return null;
		}
		const restUrl = agenticSettings.restUrl;
		const nonce = agenticSettings.nonce || '';
		const response = await fetch(restUrl + 'models?provider=' + encodeURIComponent(provider), {
			headers: { 'X-WP-Nonce': nonce }
		});
		if (response.ok) {
			const data = await response.json();
			if (data.success && data.models && data.models.length > 0) {
				modelCache[provider] = data.models;
				return data.models;
			}
		}
	} catch (e) {
		// Fall through to hardcoded fallback.
	}
	return null;
}

async function updateLLMFields() {
	const providerSelect = document.getElementById('agentic_llm_provider');
	const modelSelect = document.getElementById('agentic_model');
	const visionModelSelect = document.getElementById('agentic_vision_model');
	const apiHelp = document.getElementById('agentic-api-key-help');
	const modelHelp = document.getElementById('agentic-model-help');
	const getApiKeyBtn = document.getElementById('agentic-get-api-key');
	const instructionsDiv = document.getElementById('agentic-api-key-instructions');
	const stepsOl = document.getElementById('agentic-api-steps');

	if (!providerSelect || !modelSelect || !apiHelp || !modelHelp) {
		return;
	}

	const provider = providerSelect.value;
	const config = llmProviders[provider] || llmProviders.openai;
	// Priority: PHP-set initial value (page load) → JS-remembered selection → provider default.
	const currentModel = modelSelect.dataset.currentModel
		|| agenticProviderModels[provider]
		|| config.default
		|| '';
	const currentVisionModel = visionModelSelect ? (
		visionModelSelect.dataset.currentModel
		|| agenticProviderVisionModels[provider]
		|| currentModel
		|| ''
	) : '';

	// Update Get API Key button link
	if (getApiKeyBtn) {
		getApiKeyBtn.href = config.docs;
	}

	// Update Global Default checkbox — checked when the selected provider is already the global default.
	const setDefaultCheckbox = document.getElementById('agentic-set-as-default');
	if (setDefaultCheckbox) {
		setDefaultCheckbox.checked = (provider === agenticGlobalDefault);
	}

	// Show/hide Ollama URL row, Agentic info row, and API key row based on provider.
	const agenticInfoRow = document.getElementById('agentic-info-row');
	const ollamaRow = document.getElementById('agentic-ollama-url-row');
	const apiKeyRow = document.getElementById('agentic-api-key-row');
	const isOllama  = provider === 'ollama';
	const isAgentic = provider === 'agentic';
	const noApiKey  = isOllama || isAgentic;
	if (agenticInfoRow) agenticInfoRow.style.display = isAgentic ? '' : 'none';
	if (ollamaRow) ollamaRow.style.display = isOllama ? '' : 'none';
	if (apiKeyRow) apiKeyRow.style.display  = noApiKey ? 'none' : '';
	if (getApiKeyBtn) getApiKeyBtn.style.display = noApiKey ? 'none' : '';

	// Show/hide remove-provider button — visible only when the provider has a stored key.
	const removeBtn = document.getElementById('agentic-remove-provider');
	if (removeBtn) {
		const hasStoredKey = !!(agenticProviderKeys[provider] || '').trim();
		removeBtn.style.display = (!noApiKey && hasStoredKey) ? '' : 'none';
	}
	const removeOllamaBtn = document.getElementById('agentic-remove-ollama');
	if (removeOllamaBtn) {
		removeOllamaBtn.style.display = isOllama ? '' : 'none';
	}

	// Update API key help text
	apiHelp.innerHTML = `Your ${config.name} API key. <a href="#" id="agentic-show-instructions" style="text-decoration: underline;">Need help getting an API key?</a>`;

	// Update instructions steps
	if (stepsOl && config.steps) {
		stepsOl.innerHTML = config.steps.map(step => `<li style="margin-bottom: 4px;">${step}</li>`).join('');
	}

	// Update model help text
	modelHelp.innerHTML = '👁 = supports image/vision';

	// Update model docs link.
	const modelDocsLink = document.getElementById('agentic-model-docs-link');
	if (modelDocsLink && config.modelDocs) {
		modelDocsLink.href = config.modelDocs;
	}

	// Re-attach event listener for show instructions link
	setTimeout(() => {
		const showInstructionsLink = document.getElementById('agentic-show-instructions');
		if (showInstructionsLink && instructionsDiv) {
			showInstructionsLink.addEventListener('click', function(e) {
				e.preventDefault();
				const isVisible = instructionsDiv.style.display !== 'none';
				instructionsDiv.style.display = isVisible ? 'none' : 'block';
				showInstructionsLink.textContent = isVisible ? 'Need help getting an API key?' : 'Hide instructions';
			});
		}
	}, 0);

	// Show a loading placeholder while fetching from the provider API.
	modelSelect.innerHTML = '<option disabled>Loading models…</option>';
	modelSelect.disabled = true;
	if (visionModelSelect) {
		visionModelSelect.innerHTML = '<option disabled>Loading models…</option>';
		visionModelSelect.disabled = true;
	}

	const fetched = await fetchProviderModels(provider);
	modelSelect.disabled = false;
	modelSelect.innerHTML = '';

	// Use live API results when available; otherwise fall back to the provider's default model.
	let modelsToRender;
	if (fetched) {
		modelsToRender = fetched.map(m => ({ id: m.id, label: m.label || m.id, vision: !!m.vision }));
	} else if (Object.keys(config.models || {}).length > 0) {
		modelsToRender = Object.entries(config.models).map(([id, info]) => ({ id, label: info.label, vision: info.vision }));
	} else {
		modelsToRender = [{ id: config.default, label: config.default, vision: false }];
	}

	for (const model of modelsToRender) {
		const option = document.createElement('option');
		option.value = model.id;
		option.textContent = model.vision ? model.label + ' 👁' : model.label;
		option.selected = (model.id === currentModel);
		modelSelect.appendChild(option);
	}

	// If the previously saved model is not in the list, add it so the saved value is preserved.
	const isCurrentInList = [...modelSelect.options].some(o => o.value === currentModel);
	if (currentModel && !isCurrentInList) {
		const option = document.createElement('option');
		option.value = currentModel;
		option.textContent = currentModel + ' (saved)';
		option.selected = true;
		modelSelect.insertBefore(option, modelSelect.firstChild);
	}

	// Populate vision model dropdown from the same model list.
	if (visionModelSelect) {
		visionModelSelect.disabled = false;
		visionModelSelect.innerHTML = '';
		for (const model of modelsToRender) {
			const option = document.createElement('option');
			option.value = model.id;
			option.textContent = model.vision ? model.label + ' 👁' : model.label;
			option.selected = (model.id === currentVisionModel);
			visionModelSelect.appendChild(option);
		}
		const isVisionInList = [...visionModelSelect.options].some(o => o.value === currentVisionModel);
		if (currentVisionModel && !isVisionInList) {
			const option = document.createElement('option');
			option.value = currentVisionModel;
			option.textContent = currentVisionModel + ' (saved)';
			option.selected = true;
			visionModelSelect.insertBefore(option, visionModelSelect.firstChild);
		}
	}

	// Consume the one-time PHP-set value; subsequent calls use JS state.
	if (modelSelect) modelSelect.dataset.currentModel = '';
	if (visionModelSelect) visionModelSelect.dataset.currentModel = '';
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
	// Populate per-provider state from PHP-localized data.
	if (typeof agenticSettings !== 'undefined') {
		agenticProviderKeys         = Object.assign({}, agenticSettings.providerKeys         || {});
		agenticProviderModels       = Object.assign({}, agenticSettings.providerModels       || {});
		agenticProviderVisionModels = Object.assign({}, agenticSettings.providerVisionModels || {});
		if (agenticSettings.globalDefault) agenticGlobalDefault = agenticSettings.globalDefault;
	}

	const providerSelect = document.getElementById('agentic_llm_provider');
	if (providerSelect) {
		agenticCurrentProvider = providerSelect.value;
	}

	// Track chat model selections so switching back restores the last chosen model.
	const modelSelectEl = document.getElementById('agentic_model');
	if (modelSelectEl) {
		modelSelectEl.addEventListener('change', function() {
			if (agenticCurrentProvider) {
				agenticProviderModels[agenticCurrentProvider] = this.value;
			}
		});
	}

	// Track vision model selections per provider.
	const visionModelSelectEl = document.getElementById('agentic_vision_model');
	if (visionModelSelectEl) {
		visionModelSelectEl.addEventListener('change', function() {
			if (agenticCurrentProvider) {
				agenticProviderVisionModels[agenticCurrentProvider] = this.value;
			}
		});
	}

	updateLLMFields();

	// After initial render the PHP data-current-model has been consumed — clear it so
	// subsequent provider switches use agenticProviderModels/agenticProviderVisionModels instead.
	if (modelSelectEl) modelSelectEl.dataset.currentModel = '';
	if (visionModelSelectEl) visionModelSelectEl.dataset.currentModel = '';

	if (providerSelect) {
		providerSelect.addEventListener('change', function() {
			const ms           = document.getElementById('agentic_model');
			const vms          = document.getElementById('agentic_vision_model');
			const keyInput     = document.getElementById('agentic_llm_api_key');
			const testResult   = document.getElementById('agentic-test-result');

			// Persist the current model and key for the outgoing provider.
			if (agenticCurrentProvider) {
				if (ms)       agenticProviderModels[agenticCurrentProvider]       = ms.value;
				if (vms)      agenticProviderVisionModels[agenticCurrentProvider]  = vms.value;
				if (keyInput) agenticProviderKeys[agenticCurrentProvider]          = keyInput.value;
			}

			agenticCurrentProvider = this.value;

			// Restore key for the incoming provider.
			if (keyInput) {
				keyInput.value = agenticProviderKeys[this.value] || '';
			}

					// Always default to Supervised mode when switching providers.
					const modeSelect = document.getElementById('agentic_agent_mode');
					if (modeSelect) {
						modeSelect.value = 'supervised';
						modeSelect.dispatchEvent(new Event('change'));
					}
			updateLLMFields();
		});
	}

	// Remove provider handler — deletes the stored API key for the current provider.
	function handleRemoveProvider(provider) {
		const config = llmProviders[provider] || llmProviders.openai;
		const label  = config.name || provider;
		agenticUI.confirm(`Remove ${label}? This will delete the stored API key and model preference for this provider.`, { danger: true, confirmText: 'Remove' }).then(function (ok) {
			if (!ok) {
				return;
			}

			const body = new URLSearchParams({
				action: 'agentic_remove_provider',
				nonce: typeof agenticSettings !== 'undefined' ? agenticSettings.settingsNonce : '',
				provider: provider
			});

			fetch(ajaxurl, { method: 'POST', body })
				.then(r => r.json())
				.then(data => {
					if (data.success) {
						// Clear JS-side state and reload to reflect changes.
						delete agenticProviderKeys[provider];
						delete agenticProviderModels[provider];
						location.reload();
					} else {
						agenticUI.toast(data.data?.message || 'Failed to remove provider.', 'error');
					}
				})
				.catch(() => agenticUI.toast('Connection error. Please try again.', 'error'));
		});
	}

	const removeBtnEl = document.getElementById('agentic-remove-provider');
	if (removeBtnEl) {
		removeBtnEl.addEventListener('click', function(e) {
			e.preventDefault();
			handleRemoveProvider(agenticCurrentProvider);
		});
	}

	const removeOllamaBtnEl = document.getElementById('agentic-remove-ollama');
	if (removeOllamaBtnEl) {
		removeOllamaBtnEl.addEventListener('click', function(e) {
			e.preventDefault();
			handleRemoveProvider('ollama');
		});
	}

	// Update agent mode help text
	const agentModeSelect = document.getElementById('agentic_agent_mode');
	const agentModeHelp = document.getElementById('agentic-agent-mode-help');
	
	const modeDescriptions = {
		disabled: 'Agent chat only, no file/database actions',
		supervised: 'Documentation updates are autonomous, code changes require approval',
		autonomous: 'All actions are executed immediately (use with caution)'
	};

	function updateAgentModeHelp() {
		if (agentModeSelect && agentModeHelp) {
			const mode = agentModeSelect.value;
			agentModeHelp.textContent = modeDescriptions[mode] || '';
		}
	}

	if (agentModeSelect) {
		updateAgentModeHelp();
		agentModeSelect.addEventListener('change', updateAgentModeHelp);
	}

	// (The legacy System Requirements checker and Developer-account blocks
	// were removed: their buttons no longer exist in any template and the
	// AJAX actions they called are not registered.)

	// ── Per-Agent Overrides Table ──────────────────────────────────────────────
	// When a provider select changes in the agent overrides table, repopulate
	// the model dropdown for that row using the same llmProviders config.
	const overridesTable = document.getElementById('agentic-agent-overrides-table');
	if (overridesTable) {

		/**
		 * Populate a model <select> for a single agent row.
		 *
		 * @param {HTMLSelectElement} providerSel    The provider <select> in this row.
		 * @param {HTMLSelectElement} modelSel       The chat model <select> in this row.
		 * @param {HTMLSelectElement|null} visionSel The vision model <select> in this row (optional).
		 * @param {string} savedModel      Previously-saved chat model value.
		 * @param {string} savedVisionModel Previously-saved vision model value.
		 */
		async function populateAgentModelSelect(providerSel, modelSel, visionSel, savedModel, savedVisionModel) {
			const provider = providerSel.value;
			const blankOpt       = modelSel.querySelector('option[value=""]');
			const blankHtml      = blankOpt ? blankOpt.outerHTML : '<option value="">— Global default —</option>';
			const blankVisionOpt = visionSel && visionSel.querySelector('option[value=""]');
			const blankVisionHtml = blankVisionOpt ? blankVisionOpt.outerHTML : '<option value="">— Same as chat —</option>';

			if (!provider) {
				modelSel.innerHTML = blankHtml;
				if (visionSel) visionSel.innerHTML = blankVisionHtml;
				return;
			}

			const config = llmProviders[provider] || null;
			modelSel.innerHTML = blankHtml + '<option disabled>Loading…</option>';
			modelSel.disabled = true;
			if (visionSel) { visionSel.innerHTML = blankVisionHtml + '<option disabled>Loading…</option>'; visionSel.disabled = true; }

			// Try live API fetch first.
			const fetched = await fetchProviderModels(provider);
			modelSel.disabled = false;
			modelSel.innerHTML = blankHtml;
			if (visionSel) { visionSel.disabled = false; visionSel.innerHTML = blankVisionHtml; }

			let modelsToRender;
			if (fetched) {
				modelsToRender = fetched.map(m => ({ id: m.id, label: m.label || m.id, vision: !!m.vision }));
			} else if (config && Object.keys(config.models || {}).length > 0) {
				modelsToRender = Object.entries(config.models).map(([id, info]) => ({ id, label: info.label, vision: info.vision }));
			} else {
				modelsToRender = config && config.default ? [{ id: config.default, label: config.default, vision: false }] : [];
			}

			for (const model of modelsToRender) {
				// Chat model option.
				const opt = document.createElement('option');
				opt.value = model.id;
				opt.textContent = model.vision ? model.label + ' 👁' : model.label;
				opt.selected = (model.id === savedModel);
				modelSel.appendChild(opt);
				// Vision model option.
				if (visionSel) {
					const vopt = document.createElement('option');
					vopt.value = model.id;
					vopt.textContent = model.vision ? model.label + ' 👁' : model.label;
					vopt.selected = (model.id === savedVisionModel);
					visionSel.appendChild(vopt);
				}
			}

			// If saved models not in list, add them.
			if (savedModel && ![...modelSel.options].some(o => o.value === savedModel)) {
				const opt = document.createElement('option');
				opt.value = savedModel;
				opt.textContent = savedModel;
				opt.selected = true;
				modelSel.appendChild(opt);
			}
			if (visionSel && savedVisionModel && ![...visionSel.options].some(o => o.value === savedVisionModel)) {
				const vopt = document.createElement('option');
				vopt.value = savedVisionModel;
				vopt.textContent = savedVisionModel;
				vopt.selected = true;
				visionSel.appendChild(vopt);
			}
		}

		// Initial population of model dropdowns for rows that already have a saved provider.
		overridesTable.querySelectorAll('tr[data-agent-slug]').forEach(function(row) {
			const providerSel  = row.querySelector('.agentic-agent-provider-select');
			const modelSel     = row.querySelector('.agentic-agent-model-select');
			const visionSel    = row.querySelector('.agentic-agent-vision-model-select');
			if (!providerSel || !modelSel) return;
			const savedModel       = modelSel.dataset.savedModel || '';
			const savedVisionModel = visionSel ? (visionSel.dataset.savedModel || '') : '';
			if (providerSel.value) {
				populateAgentModelSelect(providerSel, modelSel, visionSel, savedModel, savedVisionModel);
			}
		});

		// Handle provider change for each row.
		overridesTable.addEventListener('change', function(e) {
			const providerSel = e.target.closest('.agentic-agent-provider-select');
			if (!providerSel) return;
			const row       = providerSel.closest('tr[data-agent-slug]');
			const modelSel  = row && row.querySelector('.agentic-agent-model-select');
			const visionSel = row && row.querySelector('.agentic-agent-vision-model-select');
			if (!modelSel) return;
			populateAgentModelSelect(providerSel, modelSel, visionSel, '', '');
		});

		// Reset row button clears all selects back to "— Global default —".
		overridesTable.addEventListener('click', function(e) {
			const btn = e.target.closest('.agentic-reset-agent-row');
			if (!btn) return;
			const row = btn.closest('tr[data-agent-slug]');
			if (!row) return;
			row.querySelectorAll('select').forEach(function(sel) {
				sel.value = '';
			});
			// Clear model dropdowns back to blank-only.
			const modelSel  = row.querySelector('.agentic-agent-model-select');
			const visionSel = row.querySelector('.agentic-agent-vision-model-select');
			if (modelSel)  modelSel.innerHTML  = '<option value="">— Global default —</option>';
			if (visionSel) visionSel.innerHTML = '<option value="">— Same as chat —</option>';
		});
	}
});
