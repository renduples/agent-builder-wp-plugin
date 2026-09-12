/**
 * Chat Playground inspector — captures the plugin REST exchange for the
 * most recent message. Loaded only on Advanced-mode Agent Chat.
 *
 * This is POST agentic/v1/chat (request body + SSE `end` event or JSON
 * body), not the raw LLM provider payload. handle_chat() does not return
 * that payload; wrapping fetch here is additive and does not change the
 * execution path.
 */
(function () {
	'use strict';

	if (window.__agenticPlaygroundFetchWrapped) {
		return;
	}

	var requestEl = document.getElementById('agentic-playground-request');
	var responseEl = document.getElementById('agentic-playground-response');
	if (!requestEl || !responseEl || typeof window.fetch !== 'function') {
		return;
	}

	window.__agenticPlaygroundFetchWrapped = true;

	function pretty(value) {
		try {
			return JSON.stringify(value, null, 2);
		} catch (e) {
			return String(value);
		}
	}

	function sanitizeBody(raw) {
		var data;
		if (raw == null || raw === '') {
			return raw;
		}
		try {
			data = typeof raw === 'string' ? JSON.parse(raw) : raw;
		} catch (e) {
			return typeof raw === 'string' ? raw : pretty(raw);
		}
		if (data && typeof data === 'object' && data.image) {
			data = Object.assign({}, data);
			data.image = '[omitted image attachment]';
		}
		return data;
	}

	function setJson(el, value) {
		el.textContent = typeof value === 'string' ? value : pretty(value);
	}

	function readSseEnd(response) {
		if (!response.body || typeof response.body.getReader !== 'function') {
			return response.text().then(function (text) {
				return { note: 'Stream body was not readable', raw: text.slice(0, 4000) };
			});
		}
		var reader = response.body.getReader();
		var dec = new TextDecoder();
		var buf = '';
		var lastEnd = null;
		var types = [];

		function pull() {
			return reader.read().then(function (chunk) {
				if (chunk.done) {
					return lastEnd || { note: 'No end event in stream', events: types };
				}
				buf += dec.decode(chunk.value, { stream: true });
				var sep;
				while ((sep = buf.indexOf('\n\n')) !== -1) {
					var frame = buf.slice(0, sep);
					buf = buf.slice(sep + 2);
					var lines = frame.split('\n');
					for (var i = 0; i < lines.length; i++) {
						if (lines[i].indexOf('data:') !== 0) {
							continue;
						}
						var json = lines[i].slice(5).trim();
						try {
							var evt = JSON.parse(json);
							if (evt && evt.type) {
								types.push(evt.type);
							}
							if (evt && (evt.type === 'end' || evt.type === 'error')) {
								lastEnd = evt;
							}
						} catch (e) {
							// Ignore a malformed SSE frame; keep reading.
						}
					}
				}
				return pull();
			});
		}

		return pull();
	}

	var origFetch = window.fetch;
	window.fetch = function (input, init) {
		var url = typeof input === 'string' ? input : (input && input.url) || '';
		var method = (init && init.method) || (typeof input !== 'string' && input && input.method) || 'GET';
		var isChat = /agentic\/v1\/chat\/?(\?|$)/.test(url) && String(method).toUpperCase() === 'POST';
		if (!isChat) {
			return origFetch.apply(this, arguments);
		}

		setJson(requestEl, sanitizeBody(init && init.body));
		responseEl.textContent = 'Waiting for response…';

		return origFetch.apply(this, arguments).then(function (response) {
			var clone = response.clone();
			var ct = (clone.headers && clone.headers.get('Content-Type')) || '';
			if (ct.indexOf('text/event-stream') !== -1) {
				readSseEnd(clone).then(function (evt) {
					setJson(responseEl, evt);
				}).catch(function (err) {
					setJson(responseEl, { error: String(err) });
				});
			} else {
				clone.text().then(function (text) {
					try {
						setJson(responseEl, JSON.parse(text));
					} catch (e) {
						setJson(responseEl, text || '(empty response)');
					}
				});
			}
			return response;
		});
	};
})();
