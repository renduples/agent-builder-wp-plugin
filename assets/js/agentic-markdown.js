/**
 * Shared markdown renderer for the vanilla chat surfaces and the React embed.
 *
 * Single implementation of the small, safe markdown pipeline previously
 * duplicated in assets/js/chat.js, assets/js/chat-overlay.js and
 * src/shared/chat-embed.js. Loaded as a plain <script> dependency by the two
 * vanilla surfaces (exposing window.AgenticMarkdown), and bundled by webpack
 * for the React embed and the Tasks run details (module.exports).
 *
 * Two per-surface knobs:
 *   - delegateLinks: render [→ Agent](agentic-delegate:id) as a button
 *     (chat.js only — the overlay and embed have no agent handoff).
 *   - codeBlock: 'copy' wraps fenced code in the copy-button + language-class
 *     block used by chat.js/chat-overlay.js; 'plain' emits the bare
 *     <pre><code> the React embed uses (it has no copy-button affordance).
 *
 * Block structure is parsed line by line: paragraphs, headings, rules,
 * blockquotes, tables, fenced and indented code, and bullet ("-", "*", "+")
 * and numbered lists, nested by indentation. Inline formatting (bold, italic,
 * code, links) never spans a line break, so a "*" bullet can't pair up with an
 * italic marker on another line.
 *
 * Escaping is DOM-based (textContent → innerHTML) in the browser, which escapes
 * &, <, > but not the quote characters; safeLinkHref() re-escapes quotes on
 * the URL attribute. Without a DOM (unit tests under node) the same three
 * characters are escaped by string replacement.
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
		if (typeof document === 'undefined') {
			return String(text).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
		}
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
		// A relative input must stay on this host: "/\\evil.com" resolves off-site.
		if (!/^[a-z][a-z0-9+.-]*:/i.test(String(href).trim()) && url.host !== location.host) return null;
		return url.href;
	}

	// "- item", "* item", "+ item", "1. item", "1) item" (marker, then a space).
	var LIST_ITEM_RE = /^([ \t]*)([-*+]|\d{1,9}[.)])[ \t]+(.*)$/;
	// "---", "***", "___", "* * *" (three or more of one character).
	var HR_RE = /^ {0,3}([-*_])(?:[ \t]*\1){2,}[ \t]*$/;
	var HEADING_RE = /^ {0,3}(#{1,6})[ \t]+(.*?)(?:[ \t]+#+)?[ \t]*$/;
	// Blockquote marker; the text is already escaped, so ">" is "&gt;".
	var QUOTE_RE = /^ {0,3}&gt;[ \t]?(.*)$/;
	var TABLE_ROW_RE = /^[ \t]*\|.*\|[ \t]*$/;
	var TABLE_SEP_RE = /^[ \t]*\|[ \t]*:?-{2,}:?[ \t]*(\|[ \t]*:?-{2,}:?[ \t]*)*\|?[ \t]*$/;

	// Width of a line's leading whitespace (a tab counts as four spaces).
	function indentOf(line) {
		var m = line.match(/^[ \t]*/)[0];
		return m.replace(/\t/g, '    ').length;
	}

	function isBlank(line) {
		return /^[ \t]*$/.test(line);
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
		var html = escapeHtml(String(text).replace(/\r\n?/g, '\n'));

		// Placeholders hold rendered fragments (code blocks, inline code, links)
		// aside so no later substitution rewrites their contents. The nonce makes
		// a token the text itself could contain vanishingly unlikely.
		// Letters and digits only, so no emphasis rule can touch a token.
		var nonce = 'AGMD' + Math.random().toString(36).slice(2) + Date.now().toString(36) + 'Q';
		var held = [];
		var blockTokens = {};
		function hold(fragment, isBlock) {
			held.push(fragment);
			var token = nonce + (held.length - 1) + 'Q';
			if (isBlock) blockTokens[token] = true;
			return token;
		}
		var tokenOnlyRe = new RegExp('^[ \\t]*(' + nonce + '\\d+Q)[ \\t]*$');

		// Fenced code blocks first (a fence may be indented, e.g. under a list
		// item), so the raw code is never rewritten into markdown markup.
		html = html.replace(/(^|\n)([ \t]*)```([\w+-]*)[ \t]*\n([\s\S]*?)\n?[ \t]*```[ \t]*(?=\n|$)/g, function (m, lead, indent, lang, code) {
			var block;
			if (options.codeBlock === 'plain') {
				block = '<pre><code>' + code + '</code></pre>';
			} else {
				block = '<div class="agentic-code-wrap"><button class="agentic-copy-btn" title="Copy">Copy</button><pre><code class="language-' + lang + '">' + code + '</code></pre></div>';
			}
			return lead + indent + hold(block, true);
		});

		// Inline code — held aside so "*" or "_" inside it stays literal.
		html = html.replace(/`([^`\n]+)`/g, function (m, code) {
			return hold('<code>' + code + '</code>');
		});

		// Inline formatting for one line of text (never crosses a line break).
		function inline(s) {
			// Agent delegation links — rendered as buttons, not anchors (chat.js only).
			// Convention: [→ Agent Name](agentic-delegate:agent-id)
			if (options.delegateLinks) {
				s = s.replace(/\[([^\]\n]+)\]\(agentic-delegate:([a-z0-9-]+)\)/g, function (m, label, agent) {
					return hold('<button class="agentic-delegate-btn" data-agent="' + agent + '">' + emphasis(label) + '</button>');
				});
			}
			// Standard links — scheme-validated and attribute-escaped.
			s = s.replace(/\[([^\]\n]+)\]\(([^)\s]+)\)/g, function (m, label, url) {
				var href = safeLinkHref(url);
				if (href === null) return label; // unsafe scheme — plain text
				return hold('<a href="' + href + '" target="_blank" rel="noopener">' + emphasis(label) + '</a>');
			});
			return emphasis(s);
		}

		// Bold and italic, "*" and "_" forms. Markers must hug the text
		// ("2 * 3 * 4" stays literal), and "_" only counts at a word boundary so
		// snake_case names such as get_agent_list stay intact.
		function emphasis(s) {
			s = s.replace(/\*\*(?=[^\s*])([^*]*?[^\s*])\*\*/g, '<strong>$1</strong>');
			s = s.replace(/(^|[^\w])__(?=[^\s_])([^_]*?[^\s_])__(?!\w)/g, '$1<strong>$2</strong>');
			s = s.replace(/\*(?=[^\s*])([^*]*?[^\s*])\*/g, '<em>$1</em>');
			s = s.replace(/(^|[\s(>"'])_(?=[^\s_])([^_]*?[^\s_])_(?!\w)/g, '$1<em>$2</em>');
			return s;
		}

		var lines = html.split('\n');
		var out = [];
		var para = [];

		function flushPara() {
			if (para.length) {
				out.push('<p>' + para.map(inline).join('<br>') + '</p>');
				para = [];
			}
		}

		// Parse a (possibly nested) list starting at lines[start]; returns the
		// index of the first line after it.
		function parseList(start) {
			var stack = []; // { indent, tag }
			var res = '';
			var i = start;

			function openList(indent, marker) {
				var ordered = /\d/.test(marker);
				var tag = ordered ? 'ol' : 'ul';
				var num = ordered ? parseInt(marker, 10) : 1;
				stack.push({ indent: indent, tag: tag });
				res += '<' + tag + (ordered && num !== 1 ? ' start="' + num + '"' : '') + '>';
			}
			function closeTop() {
				res += '</li></' + stack.pop().tag + '>';
			}
			function top() {
				return stack[stack.length - 1];
			}

			while (i < lines.length) {
				var line = lines[i];
				var m = LIST_ITEM_RE.exec(line);

				if (m && !HR_RE.test(line)) {
					var indent = indentOf(m[1]);
					var tag = /\d/.test(m[2]) ? 'ol' : 'ul';
					if (!stack.length) {
						openList(indent, m[2]);
					} else {
						// Back out of deeper levels this item is shallower than.
						while (stack.length > 1 && indent < top().indent - 1) {
							closeTop();
						}
						if (indent >= top().indent + 2) {
							openList(indent, m[2]); // nested inside the open item
						} else if (top().tag !== tag) {
							// Same level, other kind: end this list, start a sibling.
							closeTop();
							if (stack.length) {
								openList(indent, m[2]);
							} else {
								out.push(res);
								res = '';
								openList(indent, m[2]);
							}
						} else {
							res += '</li>';
						}
					}
					res += '<li>' + inline(m[3]);
					i++;
					continue;
				}

				if (isBlank(line)) {
					// A blank line ends the list unless the list carries on below it
					// (another item, or an indented continuation of this one).
					var j = i + 1;
					while (j < lines.length && isBlank(lines[j])) j++;
					if (j < lines.length && (LIST_ITEM_RE.test(lines[j]) && !HR_RE.test(lines[j]) || indentOf(lines[j]) >= 2)) {
						i = j;
						continue;
					}
					break;
				}

				// Indented text under an item continues that item (never code).
				if (indentOf(line) >= 2) {
					var token = tokenOnlyRe.exec(line);
					res += token && blockTokens[token[1]] ? token[1] : '<br>' + inline(line.replace(/^[ \t]+/, ''));
					i++;
					continue;
				}

				break;
			}

			while (stack.length) closeTop();
			out.push(res);
			return i;
		}

		var i = 0;
		while (i < lines.length) {
			var line = lines[i];
			var m;

			if (isBlank(line)) {
				flushPara();
				i++;
				continue;
			}

			// A held block (fenced code) on its own line.
			m = tokenOnlyRe.exec(line);
			if (m && blockTokens[m[1]]) {
				flushPara();
				out.push(m[1]);
				i++;
				continue;
			}

			if (HR_RE.test(line)) {
				flushPara();
				out.push('<hr>');
				i++;
				continue;
			}

			m = HEADING_RE.exec(line);
			if (m) {
				flushPara();
				out.push('<h' + m[1].length + '>' + inline(m[2]) + '</h' + m[1].length + '>');
				i++;
				continue;
			}

			if (LIST_ITEM_RE.test(line)) {
				flushPara();
				i = parseList(i);
				continue;
			}

			if (QUOTE_RE.test(line)) {
				flushPara();
				var quoted = [];
				while (i < lines.length && QUOTE_RE.test(lines[i])) {
					quoted.push(inline(QUOTE_RE.exec(lines[i])[1]));
					i++;
				}
				out.push('<blockquote>' + quoted.join('<br>') + '</blockquote>');
				continue;
			}

			if (TABLE_ROW_RE.test(line) && i + 1 < lines.length && TABLE_SEP_RE.test(lines[i + 1])) {
				flushPara();
				var cells = function (row) {
					return row.trim().replace(/^\||\|$/g, '').split('|').map(function (c) {
						return inline(c.trim());
					});
				};
				var table = '<table><thead><tr>' + cells(line).map(function (c) { return '<th>' + c + '</th>'; }).join('') + '</tr></thead>';
				i += 2;
				var body = '';
				while (i < lines.length && TABLE_ROW_RE.test(lines[i])) {
					body += '<tr>' + cells(lines[i]).map(function (c) { return '<td>' + c + '</td>'; }).join('') + '</tr>';
					i++;
				}
				out.push(table + (body ? '<tbody>' + body + '</tbody>' : '') + '</table>');
				continue;
			}

			// Indented code: 4+ spaces (or a tab), outside a list, not continuing
			// a paragraph. Fewer spaces are just text.
			if (!para.length && /^( {4}|\t)/.test(line)) {
				var code = [];
				while (i < lines.length && (/^( {4}|\t)/.test(lines[i]) || (isBlank(lines[i]) && i + 1 < lines.length && /^( {4}|\t)/.test(lines[i + 1])))) {
					code.push(lines[i].replace(/^( {4}|\t)/, ''));
					i++;
				}
				out.push(options.codeBlock === 'plain'
					? '<pre><code>' + code.join('\n') + '</code></pre>'
					: '<div class="agentic-code-wrap"><button class="agentic-copy-btn" title="Copy">Copy</button><pre><code class="language-">' + code.join('\n') + '</code></pre></div>');
				continue;
			}

			para.push(line.replace(/^[ \t]+/, ''));
			i++;
		}
		flushPara();

		html = out.join('');

		// Restore held fragments (a link label may itself hold inline code, so
		// repeat until none are left).
		var tokenRe = new RegExp(nonce + '(\\d+)Q', 'g');
		for (var pass = 0; pass < 4 && html.indexOf(nonce) !== -1; pass++) {
			html = html.replace(tokenRe, function (tok, n) {
				var f = held[parseInt(n, 10)];
				return f === undefined ? tok : f;
			});
		}

		return html;
	}

	return { render: render, safeCardHref: safeCardHref };
}));
