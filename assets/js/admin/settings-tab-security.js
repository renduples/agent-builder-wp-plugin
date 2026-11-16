/**
 * Settings > Security tab.
 *
 * Data: window.agenticSettingsTabSecurity { agentsTabUrl, agentModeNonce, ajaxUrl }.
 */
( function () {
	var DATA = window.agenticSettingsTabSecurity || {};

	// Agent (security) mode selector: live description + instant save.
	(function(){
		var sel = document.getElementById('agent_builder_agent_mode');
		if (!sel) return;
		var agentsTabUrl = String(DATA.agentsTabUrl || '').replace(/"/g, '&quot;').replace(/</g, '&lt;');
		var status = document.getElementById('agentic-mode-status');
		var desc = document.getElementById('agentic-mode-description');
		var descriptions = {
			disabled: 'Agents can chat with users but cannot execute any actions on your site. Tools and abilities are completely disabled.',
			supervised: 'Agents propose actions which are queued for your review. Nothing is modified until you approve it. This is the recommended setting for most sites.',
			autonomous: 'Agents execute actions immediately without waiting for approval. Supervised is the recommended global setting — override Security Mode for Agents you trust <a href="' + agentsTabUrl + '">here</a>.'
		};
		function updateDesc() { desc.innerHTML = descriptions[sel.value] || ''; }
		updateDesc();
		if (!sel) return;
		sel.addEventListener('change', function(){
			updateDesc();
			status.textContent = 'Saving…';
			status.style.color = '#787c82';
			var fd = new FormData();
			fd.append('action', 'agentic_save_agent_mode');
			fd.append('nonce', DATA.agentModeNonce);
			fd.append('mode', sel.value);
			fetch(DATA.ajaxUrl, {
				method: 'POST', body: fd, credentials: 'same-origin'
			}).then(function(r){ return r.json(); }).then(function(res){
				if (res.success) {
					status.textContent = 'Saved.';
					status.style.color = '#00a32a';
				} else {
					status.textContent = res.data || 'Error saving.';
					status.style.color = '#b32d2e';
				}
				setTimeout(function(){ status.textContent = ''; }, 3000);
			}).catch(function(){
				status.textContent = 'Network error.';
				status.style.color = '#b32d2e';
			});
		});
	})();

	// Consent notice: show the text row only while the toggle is on.
	(function() {
		var cb   = document.getElementById('agent_builder_chat_consent_enabled');
		var row  = document.getElementById('agentic_consent_text_row');
		if (!cb || !row) return;
		cb.addEventListener('change', function() { row.style.display = this.checked ? '' : 'none'; });
	})();

	// Tool permission grants: revoke buttons.
	(function() {
		document.querySelectorAll('.agentic-revoke-grant').forEach(function(btn) {
			btn.addEventListener('click', function() {
				var tool   = btn.dataset.tool;
				var url    = btn.dataset.url;
				var nonce  = btn.dataset.nonce;
				btn.disabled = true;
				btn.textContent = 'Revoking…';
				fetch(url, {
					method: 'DELETE',
					headers: { 'X-WP-Nonce': nonce },
					credentials: 'same-origin'
				}).then(function(r) { return r.json(); }).then(function(res) {
					if (res.success) {
						var row = document.getElementById('agentic-grant-row-' + tool);
						if (row) row.remove();
						var tbody = document.querySelector('#agentic-tool-grants-table tbody');
						if (tbody && tbody.children.length === 0) {
							document.getElementById('agentic-tool-grants-table').outerHTML =
								'<p class="description">No always-allow grants have been set.</p>';
						}
					} else {
						btn.disabled = false;
						btn.textContent = 'Revoke';
						agenticUI.toast('Failed to revoke grant.', 'error');
					}
				}).catch(function() {
					btn.disabled = false;
					btn.textContent = 'Revoke';
					agenticUI.toast('Network error. Please try again.', 'error');
				});
			});
		});
	})();
}() );
