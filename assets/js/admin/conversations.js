(function() {
	var restUrl = window.agenticConversations.restUrl;
	var nonce   = window.agenticConversations.nonce;
	var loaded  = {};

	function escHtml(str) {
		var d = document.createElement('div');
		d.textContent = str || '';
		return d.innerHTML;
	}

	function formatTime(ts) {
		if (!ts) return '';
		try { return new Date(ts).toLocaleString([], { month:'short', day:'numeric', hour:'2-digit', minute:'2-digit' }); }
		catch (e) { return ts; }
	}

	/**
	 * Impressive Conversations UI renderer.
	 * Shows clean transcript + a beautiful reasoning trace timeline powered by
	 * the new observability data (tool_choice + chat_complete with real reasoning).
	 */
	function renderMessages(container, sid) {
		if (loaded[sid]) return;
		loaded[sid] = true;

		container.innerHTML = '<div class="agentic-trace-empty">Loading full trace…</div>';

		fetch(restUrl + encodeURIComponent(sid), { headers: { 'X-WP-Nonce': nonce } })
		.then(function(r) { return r.json(); })
		.then(function(data) {
			var history = data.history || [];
			var trace   = data.trace || [];

			var html = '';

			// ── Premium header ─────────────────────────────────────────────
			html += '<div class="agentic-trace-header">';
			html += '<h4>Conversation Trace</h4>';
			html += '<div class="agentic-trace-meta">';
			html += '<span>' + history.length + ' turns</span>';
			if (trace.length) html += '<span>' + trace.length + ' decisions logged</span>';
			html += '</div>';
			html += '</div>';

			// ── Clean transcript (improved bubbles) ────────────────────────
			if (history.length) {
				html += '<div style="margin-bottom:18px;border-bottom:1px solid #f0f0f1;padding-bottom:12px;">';
				history.forEach(function(msg) {
					var isUser = msg.role === 'user';
					var time   = formatTime(msg.time);
					html += '<div class="agentic-msg-bubble ' + (isUser ? 'agentic-msg-bubble--user' : 'agentic-msg-bubble--agent') + '">';
					html += '<div class="agentic-msg-header"><strong class="agentic-msg-sender">' + (isUser ? 'User' : 'Agent') + '</strong><span class="agentic-msg-time">' + escHtml(time) + '</span></div>';

					if (!isUser && msg.tools_used && msg.tools_used.length) {
						html += '<div class="agentic-msg-tools">';
						msg.tools_used.forEach(function(t){ html += '<code class="agentic-tool-tag">' + escHtml(t) + '</code>'; });
						html += '</div>';
					}
					html += '<div class="agentic-msg-body">' + escHtml(msg.content || '') + '</div>';
					html += '</div>';
				});
				html += '</div>';
			}

			// ── IMPRESSIVE REASONING TRACE TIMELINE (the star) ─────────────
			if (trace.length) {
				html += '<div style="margin-bottom:6px;"><strong style="font-size:13px;color:#1d2327;">Agent Reasoning &amp; Decisions</strong></div>';
				html += '<div class="agentic-trace-timeline">';

				trace.forEach(function(step) {
					var type = 'thinking';
					if (step.action === 'tool_choice') type = 'tool';
					if (step.action && step.action.indexOf('complete') !== -1) type = 'final';

					var icon = type === 'thinking' ? 'dashicons-lightbulb' : (type === 'tool' ? 'dashicons-admin-tools' : 'dashicons-yes-alt');

					html += '<div class="agentic-trace-step agentic-trace-step--' + type + '">';

					// Header
					html += '<div class="agentic-trace-step-header">';
					html += '<span class="dashicons ' + icon + '"></span> ';
					html += '<span>' + (type === 'thinking' ? 'Thinking' : (type === 'tool' ? 'Tool Decision' : 'Final Response')) + '</span>';
					html += '<span class="time">' + escHtml(formatTime(step.time)) + '</span>';
					html += '</div>';

					// Reasoning — prominent and beautiful
					if (step.reasoning) {
						html += '<div class="agentic-trace-reasoning">' + escHtml(step.reasoning) + '</div>';
					}

					// Tools
					if (step.tools && step.tools.length) {
						html += '<div class="agentic-trace-tools">';
						step.tools.forEach(function(t) {
							html += '<code>' + escHtml(t) + '</code>';
						});
						html += '</div>';
					}

					// Meta row
					var metaParts = [];
					if (step.tokens) metaParts.push(step.tokens + ' tokens');
					if (step.cost) metaParts.push('$' + step.cost.toFixed(4));
					if (step.provider) metaParts.push(step.provider);
					if (step.iterations) metaParts.push(step.iterations + ' iterations');

					if (metaParts.length) {
						html += '<div class="agentic-trace-meta-row">' + metaParts.join(' · ') + '</div>';
					}

					html += '</div>';
				});

				html += '</div>';
			} else if (history.length) {
				html += '<div class="agentic-trace-empty">No detailed reasoning trace captured for this older session.</div>';
			}

			if (!history.length && !trace.length) {
				html = '<p class="agentic-text-muted">No messages or trace found for this session.</p>';
			}

			container.innerHTML = html;
		})
		.catch(function() {
			container.innerHTML = '<p class="agentic-status-error" style="padding:12px 0;">Failed to load the full conversation trace.</p>';
		});
	}

	// Wire every <details> — now renders the impressive trace experience.
	document.querySelectorAll('.agentic-conv-details').forEach(function(det) {
		var sid       = det.dataset.session;
		var container = document.createElement('div');
		container.className = 'agentic-conv-messages';
		det.appendChild(container);

		det.addEventListener('toggle', function() {
			if (det.open) renderMessages(container, sid);
		});
	});
})();
