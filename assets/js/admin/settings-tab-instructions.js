/**
 * Settings > Instructions tab: persona agent switcher.
 */
var agenticPersonaSelect = document.getElementById('agentic-persona-agent-select');
if (agenticPersonaSelect) agenticPersonaSelect.addEventListener('change', function() {
	var url = new URL(window.location.href);
	url.searchParams.set('edit_persona', this.value);
	window.location.href = url.toString();
});
