/**
 * Settings page: "Reset to defaults" links.
 */
document.querySelectorAll('.agentic-reset-defaults').forEach(function(link) {
	link.addEventListener('click', async function(e) {
		e.preventDefault();
		if (!await agenticUI.confirm('Reset these settings to their fresh-install defaults? This cannot be undone.', { danger: true, confirmText: 'Reset' })) return;
		document.getElementById('agentic-reset-section').value = this.getAttribute('data-section');
		this.closest('form').querySelector('[name="agentic_save_settings"]').click();
	});
});
