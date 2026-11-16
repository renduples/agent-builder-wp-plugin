(function () {
	'use strict';

	var D = window.agenticRunTask || {};
	var L = D.i18n || {};

	function escText(str) {
		var d = document.createElement('div');
		d.textContent = str || '';
		return d.innerHTML;
	}

	/**
	 * Lightweight Markdown-to-HTML renderer.
	 * Handles: ### h3, #### h4, **bold**, `code`, ordered/unordered lists, paragraphs.
	 * Escapes HTML first to prevent XSS, then applies formatting.
	 */
	function renderMarkdown(text) {
		// Convert literal \n sequences to actual newlines.
		text = text.replace(/\\n/g, '\n');

		// Escape HTML entities.
		var esc = text
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');

		var lines = esc.split('\n');
		var html = [];
		var inUl = false;
		var inOl = false;

		function closeLists() {
			if (inUl) { html.push('</ul>'); inUl = false; }
			if (inOl) { html.push('</ol>'); inOl = false; }
		}

		function inlineFormat(s) {
			// Bold: **text**
			s = s.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
			// Inline code: `text`
			s = s.replace(/`([^`]+)`/g, '<code>$1</code>');
			// Severity color hints.
			s = s.replace(/<strong>(Critical[^<]*)<\/strong>/gi, '<strong class="agentic-severity-critical">$1</strong>');
			s = s.replace(/<strong>(High[^<]*)<\/strong>/gi, '<strong class="agentic-severity-high">$1</strong>');
			s = s.replace(/<strong>(Medium[^<]*)<\/strong>/gi, '<strong class="agentic-severity-medium">$1</strong>');
			s = s.replace(/<strong>(Low[^<]*)<\/strong>/gi, '<strong class="agentic-severity-low">$1</strong>');
			return s;
		}

		for (var i = 0; i < lines.length; i++) {
			var line = lines[i];

			// Headers.
			var h4 = line.match(/^####\s+(.+)$/);
			if (h4) { closeLists(); html.push('<h4>' + inlineFormat(h4[1]) + '</h4>'); continue; }

			var h3 = line.match(/^###\s+(.+)$/);
			if (h3) { closeLists(); html.push('<h3>' + inlineFormat(h3[1]) + '</h3>'); continue; }

			// Unordered list: - item or * item.
			var ul = line.match(/^\s*[-*]\s+(.+)$/);
			if (ul) {
				if (inOl) { html.push('</ol>'); inOl = false; }
				if (!inUl) { html.push('<ul>'); inUl = true; }
				html.push('<li>' + inlineFormat(ul[1]) + '</li>');
				continue;
			}

			// Ordered list: 1. item.
			var ol = line.match(/^\s*\d+\.\s+(.+)$/);
			if (ol) {
				if (inUl) { html.push('</ul>'); inUl = false; }
				if (!inOl) { html.push('<ol>'); inOl = true; }
				html.push('<li>' + inlineFormat(ol[1]) + '</li>');
				continue;
			}

			// Blank line.
			if (line.trim() === '') {
				closeLists();
				continue;
			}

			// Regular paragraph.
			closeLists();
			html.push('<p>' + inlineFormat(line) + '</p>');
		}

		closeLists();
		return html.join('\n');
	}

	var btn      = document.getElementById('agentic-execute-btn');
	var preRun   = document.getElementById('agentic-pre-run');
	var running  = document.getElementById('agentic-running');
	var timer    = document.getElementById('agentic-timer');
	var results  = document.getElementById('agentic-results');

	btn.addEventListener('click', function () {
		// Hide button, show spinner + timer.
		preRun.style.display  = 'none';
		running.style.display = 'block';

		// Live timer.
		var start = Date.now();
		var tick = setInterval(function () {
			timer.textContent = Math.floor((Date.now() - start) / 1000) + 's';
		}, 250);

		// AJAX request.
		var body = new FormData();
		body.append('action', 'agentic_run_task');
		body.append('_ajax_nonce', D.nonce);
		body.append('agent', D.agent);
		body.append('task', D.task);

		fetch(ajaxurl, { method: 'POST', body: body, credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (json) {
				clearInterval(tick);
				running.style.display = 'none';
				results.style.display = 'block';

				var notice = document.getElementById('agentic-result-notice');
				var dur    = document.getElementById('agentic-result-duration');
				var outW   = document.getElementById('agentic-result-output-wrap');
				var outT   = document.getElementById('agentic-result-output');

				if (json.success) {
					notice.innerHTML = '<div class="notice notice-success agentic-js-notice-success">' +
						'<p class="agentic-js-notice-success p"><strong>&#9989; ' + escText(L.completed) + '</strong></p></div>';
					dur.textContent = json.data.duration + ' ' + L.seconds;
					if (json.data.response) {
						outT.innerHTML = renderMarkdown(json.data.response);
						outW.style.display = 'block';
					}
				} else {
					var msg = (json.data && json.data.message) ? json.data.message : L.unknownError;
					notice.innerHTML = '<div class="notice notice-error agentic-js-notice-error">' +
						'<p class="agentic-js-notice-error p"><strong>&#10060; ' + escText(L.failed) + '</strong></p>' +
						'<p class="agentic-js-notice-error p+p">' + msg.replace(/</g, '&lt;') + '</p></div>';
					if (json.data && json.data.duration) {
						dur.textContent = json.data.duration + ' ' + L.seconds;
					} else {
						dur.textContent = Math.floor((Date.now() - start) / 1000) + ' ' + L.seconds;
					}
				}
			})
			.catch(function (err) {
				clearInterval(tick);
				running.style.display = 'none';
				results.style.display = 'block';
				document.getElementById('agentic-result-notice').innerHTML =
					'<div class="notice notice-error agentic-js-notice-error">' +
					'<p class="agentic-js-notice-error p"><strong>&#10060; ' + escText(L.requestFailed) + '</strong></p>' +
					'<p class="agentic-js-notice-error p+p">' + (err.message || '').replace(/</g, '&lt;') + '</p></div>';
				document.getElementById('agentic-result-duration').textContent =
					Math.floor((Date.now() - start) / 1000) + ' ' + L.seconds;
			});
	});
})();
