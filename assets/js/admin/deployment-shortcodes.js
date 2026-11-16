(function() {
	'use strict';
	var agent       = document.getElementById('agentic_sc_agent');
	var placeholder = document.getElementById('agentic_sc_placeholder');
	var style       = document.getElementById('agentic_sc_style');
	var height      = document.getElementById('agentic_sc_height');
	var showHeader  = document.getElementById('agentic_sc_show_header');
	var preview     = document.getElementById('agentic-sc-preview-code');

	function updatePreview() {
		if (!agent || !preview) return;
		var parts = [];
		if (agent.value) parts.push('agent="' + agent.value + '"');
		if (style && style.value && style.value !== 'inline') parts.push('style="' + style.value + '"');
		if (height && height.value && height.value !== '500px') parts.push('height="' + height.value + '"');
		if (placeholder && placeholder.value) parts.push('placeholder="' + placeholder.value + '"');
		if (showHeader && !showHeader.checked) parts.push('show_header="false"');
		preview.textContent = '[agentic_chat' + (parts.length ? ' ' + parts.join(' ') : '') + ']';
	}

	[agent, placeholder, style, height, showHeader].filter(Boolean).forEach(function(el) {
		el.addEventListener('input', updatePreview);
		el.addEventListener('change', updatePreview);
	});
	if (agent) updatePreview();

	document.querySelectorAll('.agentic-sc-copyable').forEach(function(el) {
		el.addEventListener('click', function() {
			var sc = this.dataset.shortcode;
			if (navigator.clipboard) {
				navigator.clipboard.writeText(sc).then(function() {
					el.style.background = '#d1fae5';
					setTimeout(function() { el.style.background = ''; }, 1000);
				});
			} else {
				var ta = document.createElement('textarea');
				ta.value = sc; document.body.appendChild(ta); ta.select();
				document.execCommand('copy'); document.body.removeChild(ta);
				el.style.background = '#d1fae5';
				setTimeout(function() { el.style.background = ''; }, 1000);
			}
		});
	});
})();
