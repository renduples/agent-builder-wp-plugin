/**
 * Shared markdown renderer for the vanilla chat surfaces and the React embed.
 *
 * Single implementation of the small, safe markdown pipeline previously
 * duplicated in assets/js/chat.js, assets/js/chat-overlay.js and
 * src/shared/chat-embed.js. Loaded as a plain <script> dependency by the two
 * vanilla surfaces (exposing window.AgenticMarkdown), and bundled by webpack
 * for the React embed (module.exports).
 *
 * Two per-surface knobs preserve each caller's output byte-for-byte:
 *   - delegateLinks: render [→ Agent](agentic-delegate:id) as a button
 *     (chat.js only — the overlay and embed have no agent handoff).
 *   - codeBlock: 'copy' wraps fenced code in the copy-button + language-class
 *     block used by chat.js/chat-overlay.js; 'plain' emits the bare
 *     <pre><code> the React embed uses (it has no copy-button affordance).
 *
 * Escaping is DOM-based (textContent → innerHTML), which escapes &, <, > but
 * not the quote characters; safeLinkHref() re-escapes quotes on the URL
 * attribute. This matches the two vanilla renderers exactly and is visually
 * identical for the embed's previous string-based escaping.
 */
(function (root, factory) {
	if (typeof module === 'object' && module.exports) {
		module.exports = factory();
	} else {
		root.AgenticMarkdown = factory();
	}
}(typeof self !== 'undefined' ? self : this, function () {
	'use strict';

	// Escape HTML via the DOM — escapes &, <, > but NOT the quote characters
	// (safeLinkHref below re-escapes quotes on the href attribute).
	function escapeHtml(text) {
		var div = document.createElement('div');
		div.textContent = text;
		return div.innerHTML;
	}

	// Validate a link URL against a safe-scheme allowlist and make it safe to
	// interpolate into href="…". Returns the attribute-safe URL, or null when
	// the scheme is disallowed (javascript:, data:, vbscript:, etc.). The URL
	// argument is already HTML-escaped by escapeHtml() — which escapes &, <, >
	// but NOT the quote characters — so this only needs to escape " and ' on top
	// of that. Scheme detection mirrors the URL Standard: strip ASCII
	// tab/newline/CR and surrounding C0 control + space first, so a
	// "java\tscript:"-style obfuscation can't masquerade as a relative URL.
	function safeLinkHref(url) {
		var stripped = url.replace(/[\t\n\r]/g, '').replace(/^[\x00-\x20]+|[\x00-\x20]+$/g, '');
		var scheme = stripped.match(/^([a-z][a-z0-9+.\-]*):/i);
		if (scheme) {
			var name = scheme[1].toLowerCase();
			if (name !== 'http' && name !== 'https' && name !== 'mailto') return null;
		}
		return url.replace(/"/g, '&quot;').replace(/'/g, '&#039;');
	}

	// Validate a card link (result cards' "Open"/"Edit"/"View" buttons) against an
	// http/https-only allowlist using the WHATWG URL parser, which also rejects
	// control-character obfuscation (e.g. "java\tscript:"). Unlike safeLinkHref()
	// this does NOT HTML-escape — the result is assigned to a DOM property
	// (a.href), not interpolated into markup. Returns the normalized absolute URL,
	// or null when the input is unparseable or its scheme is not http:/https:.
	function safeCardHref(href) {
		if (!href) return null;
		var url;
		try {
			url = new URL(href, location.href);
		} catch (e) {
			return null;
		}
		if (url.protocol !== 'http:' && url.protocol !== 'https:') return null;
		return url.href;
	}

	/**
	 * Render markdown to safe HTML.
	 *
	 * @param {string}  text     Markdown source.
	 * @param {Object}  options  Optional per-surface flags.
	 * @param {boolean} options.delegateLinks Render agent-delegation links as buttons.
	 * @param {string}  options.codeBlock     'copy' (default) or 'plain'.
	 * @return {string} HTML.
	 */
	function render(text, options) {
		options = options || {};
		if (!text) return '';

		// Escape HTML first.
		var html = escapeHtml(text);

		// Extract fenced code blocks into placeholders FIRST — before any other
		// substitution (headers, lists, bold, links, tables) — so the raw code is
		// never rewritten into markdown markup, and the single-newline pass below
		// can't turn the code's own line breaks into <br>. Restored at the very end.
		var codeNonce = 'agentic_code_' + Math.random().toString(36).slice(2) + Date.now().toString(36) + '_';
		var codeBlocks = [];
		html = html.replace(/```(\w*)\n([\s\S]*?)```/g, function (block, lang, code) {
			codeBlocks.push({ lang: lang, code: code });
			return codeNonce + (codeBlocks.length - 1);
		});

		// Inline code.
		html = html.replace(/`([^`]+)`/g, '<code>$1</code>');

		// Tables — process before headers/lists to avoid conflicts.
		html = html.replace(/(^\|.+\|$\n?)+/gm, function (tableBlock) {
			var rows = tableBlock.trim().split('\n');
			if (rows.length < 2) return tableBlock;

			var sepIndex = -1;
			for (var si = 0; si < rows.length; si++) {
				if (/^\|[\s:]*-{2,}[\s:]*\|/.test(rows[si])) { sepIndex = si; break; }
			}
			if (sepIndex === -1) return tableBlock;

			var tableHtml = '<table>';
			tableHtml += '<thead>';
			for (var hdr = 0; hdr < sepIndex; hdr++) {
				var hcells = rows[hdr].split('|').slice(1, -1);
				tableHtml += '<tr>' + hcells.map(function (c) { return '<th>' + c.trim() + '</th>'; }).join('') + '</tr>';
			}
			tableHtml += '</thead>';
			if (sepIndex + 1 < rows.length) {
				tableHtml += '<tbody>';
				for (var bdy = sepIndex + 1; bdy < rows.length; bdy++) {
					if (!rows[bdy].trim()) continue;
					var bcells = rows[bdy].split('|').slice(1, -1);
					tableHtml += '<tr>' + bcells.map(function (c) { return '<td>' + c.trim() + '</td>'; }).join('') + '</tr>';
				}
				tableHtml += '</tbody>';
			}
			tableHtml += '</table>';
			return tableHtml;
		});

		// Horizontal rules.
		html = html.replace(/^-{3,}$/gm, '<hr>');

		// Headers (most specific first).
		html = html.replace(/^#### (.*$)/gm, '<h4>$1</h4>');
		html = html.replace(/^### (.*$)/gm, '<h3>$1</h3>');
		html = html.replace(/^## (.*$)/gm, '<h2>$1</h2>');
		html = html.replace(/^# (.*$)/gm, '<h1>$1</h1>');

		// Bold and italic.
		html = html.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
		html = html.replace(/\*([^*]+)\*/g, '<em>$1</em>');

		// Agent delegation links — rendered as buttons, not anchors (chat.js only).
		// Convention: [→ Agent Name](agentic-delegate:agent-id)
		if (options.delegateLinks) {
			html = html.replace(/\[([^\]]+)\]\(agentic-delegate:([a-z0-9-]+)\)/g,
				'<button class="agentic-delegate-btn" data-agent="$2">$1</button>');
		}

		// Standard links — scheme-validated and attribute-escaped.
		html = html.replace(/\[([^\]]+)\]\(([^)]+)\)/g, function (match, label, url) {
			var href = safeLinkHref(url);
			if (href === null) return label; // unsafe scheme — plain text
			return '<a href="' + href + '" target="_blank" rel="noopener">' + label + '</a>';
		});

		// Lists.
		html = html.replace(/^\s*[-*]\s+(.*)$/gm, '<li>$1</li>');
		html = html.replace(/(<li>.*<\/li>\n?)+/g, '<ul>$&</ul>');
		// Drop the newline the <ul> wrapper leaves between items, so a later
		// single-newline pass doesn't inject a stray <br> between <li>s — both
		// between items and between the last item and the closing </ul>.
		html = html.replace(/<\/li>\n<li>/g, '</li><li>');
		html = html.replace(/(<\/li>)\n(<\/ul>)/g, '$1$2');

		// Numbered lists — wrap each run of "1. item" lines in an <ol> directly.
		html = html.replace(/(?:^[ \t]*\d+\.\s+.*(?:\n|$))+/gm, function (block) {
			var items = block.split('\n').filter(function (line) {
				return /^[ \t]*\d+\.\s+/.test(line);
			});
			return '<ol>' + items.map(function (line) {
				return '<li>' + line.replace(/^[ \t]*\d+\.\s+/, '') + '</li>';
			}).join('') + '</ol>';
		});

		// Blockquotes.
		html = html.replace(/^>\s+(.*)$/gm, '<blockquote>$1</blockquote>');

		// Paragraphs.
		html = html.replace(/\n\n/g, '</p><p>');
		html = '<p>' + html + '</p>';
		html = html.replace(/<p><\/p>/g, '');
		html = html.replace(/<p>(<h[1-6]>)/g, '$1');
		html = html.replace(/(<\/h[1-6]>)<\/p>/g, '$1');
		html = html.replace(/<p>(<ul>)/g, '$1');
		html = html.replace(/(<\/ul>)<\/p>/g, '$1');
		html = html.replace(/<p>(<ol>)/g, '$1');
		html = html.replace(/(<\/ol>)<\/p>/g, '$1');
		html = html.replace(/<p>(<blockquote>)/g, '$1');
		html = html.replace(/(<\/blockquote>)<\/p>/g, '$1');
		html = html.replace(/<p>(<table>)/g, '$1');
		html = html.replace(/(<\/table>)<\/p>/g, '$1');
		html = html.replace(/<p>(<hr>)/g, '$1');
		html = html.replace(/(<hr>)<\/p>/g, '$1');
		// Unwrap the code placeholder from its paragraph — the real block is
		// restored after the newline pass below, so the token, not the block,
		// must escape <p>.
		html = html.replace(new RegExp('<p>(' + codeNonce + '\\d+)</p>', 'g'), '$1');

		// Preserve single line breaks inside a paragraph (the model's "line1\n
		// line2" and "- item" lists after a colon). Code blocks are still held
		// aside as placeholders, so their inner newlines survive as real newlines.
		html = html.replace(/\n/g, '<br>');

		// Restore the code blocks now that every substitution and the newline pass
		// have run, so none of them ever saw the raw code content.
		html = html.replace(new RegExp(codeNonce + '(\\d+)', 'g'), function (m, i) {
			var b = codeBlocks[parseInt(i, 10)];
			// A token this render could only have inserted, so a match is always
			// one of our own; keep the guard anyway against index drift.
			if (b === undefined) return m;
			if (options.codeBlock === 'plain') {
				return '<pre><code>' + b.code + '</code></pre>';
			}
			return '<div class="agentic-code-wrap"><button class="agentic-copy-btn" title="Copy">Copy</button><pre><code class="language-' + b.lang + '">' + b.code + '</code></pre></div>';
		});

		return html;
	}

	return { render: render, safeCardHref: safeCardHref };
}));
