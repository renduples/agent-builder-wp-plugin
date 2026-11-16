/**
 * Settings > LLM: "Get Latest Pricing" button.
 *
 * Data: window.agenticSettingsLlm { ajaxUrl, nonce, i18n }.
 */
(function() {
	var DATA = window.agenticSettingsLlm || {};
	var btn = document.getElementById('agentic-update-pricing-btn');
	if (!btn) return;

	btn.addEventListener('click', function() {
		btn.disabled = true;
		btn.textContent = DATA.i18n.updating;

		var fd = new FormData();
		fd.append('action', 'agentic_update_model_pricing');
		fd.append('nonce', DATA.nonce);

		fetch(DATA.ajaxUrl, {
			method: 'POST',
			body: fd,
			credentials: 'same-origin'
		})
		.then(function(r) { return r.json(); })
		.then(function(res) {
			var notice = document.getElementById('agentic-llm-update-notice');
			if (res.success) {
				notice.className = 'notice notice-success is-dismissible';
				notice.querySelector('p').textContent = res.data.message || 'Pricing updated.';
				notice.style.display = '';
				setTimeout(function() { location.reload(); }, 1200);
			} else {
				notice.className = 'notice notice-error is-dismissible';
				notice.querySelector('p').textContent = res.data || 'Failed to update pricing.';
				notice.style.display = '';
				btn.disabled = false;
				btn.textContent = DATA.i18n.getLatestPricing;
			}
		})
		.catch(function() {
			btn.disabled = false;
			btn.textContent = DATA.i18n.getLatestPricing;
		});
	});
})();
