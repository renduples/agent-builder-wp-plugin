/**
 * Skills page: community hub browser (ClawHub search + GitHub skill repos).
 *
 * Data: window.agenticSkillsHub { sources, i18n }.
 */
(function() {
	'use strict';

	var DATA    = window.agenticSkillsHub || {};
	var SOURCES = DATA.sources || {};

	var switcherEl      = document.getElementById('agentic-hub-source-switcher');
	var searchWrap       = document.getElementById('agentic-hub-search-wrap');
	var searchInput      = document.getElementById('agentic-hub-search');
	var searchBtn        = document.getElementById('agentic-hub-search-btn');
	var resultsDiv       = document.getElementById('agentic-hub-results');
	var importForm       = document.getElementById('agentic-hub-import-form');
	var importDataField  = document.getElementById('agentic-hub-data');
	var CLAWHUB_API      = 'https://wry-manatee-359.convex.site/api/v1';
	var cache            = {};

	function escHtml(str) {
		var div = document.createElement('div');
		div.textContent = str;
		return div.innerHTML;
	}

	function escAttr(str) {
		return String(str).replace(/&/g, '&amp;').replace(/'/g, '&#39;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
	}

	// Minimal client-side reader for the two YAML frontmatter fields we
	// need, mirroring Skills_Registry::parse_front_matter_identity().
	// Handles both a plain single-line description and YAML's block
	// scalar form ("description: |" / "description: |-" / "description: >"
	// followed by indented lines) — real skills in the wild use both;
	// Anthropic's own claude-api skill is block-scalar.
	function parseFrontMatter(raw) {
		var out = { name: '', description: '' };
		var m = raw.match(/^---\s*\n([\s\S]*?)\n---\s*\n/);
		if (!m) return out;
		var fm = m[1];
		var nm = fm.match(/^name:\s*(.+)$/m);
		if (nm) out.name = nm[1].trim().replace(/^["']|["']$/g, '');

		var block = fm.match(/^description:\s*[|>][-+]?[ \t]*\n((?:[ \t]+.*\n?)+)/m);
		if (block) {
			out.description = block[1].split('\n')
				.filter(function (l) { return l.trim() !== ''; })
				.map(function (l) { return l.trim(); })
				.join(' ');
		} else {
			var dm = fm.match(/^description:\s*["']?(.*?)["']?\s*$/m);
			if (dm) out.description = dm[1].trim();
		}
		return out;
	}

	function renderResults(items, emptyMessage) {
		if (!items.length) {
			resultsDiv.innerHTML = '<p class="agentic-text-dim">' + escHtml(emptyMessage) + '</p>';
			return;
		}
		var html = '<table class="widefat striped"><thead><tr>' +
			'<th>' + escHtml(DATA.i18n.name) + '</th>' +
			'<th>' + escHtml(DATA.i18n.description) + '</th>' +
			'<th class="agentic-col-min-90">' + escHtml(DATA.i18n.action) + '</th>' +
			'</tr></thead><tbody>';
		items.forEach(function(item) {
			html += '<tr>';
			html += '<td><strong>' + escHtml(item.name) + '</strong><br><code class="agentic-code-xs">' + escHtml(item.slug || '') + '</code></td>';
			html += '<td>' + escHtml(item.description || '') + '</td>';
			html += '<td><button type="button" class="button agentic-hub-import-btn" data-skill=\'' + escAttr(JSON.stringify(item)) + '\'>' + escHtml(DATA.i18n.import) + '</button></td>';
			html += '</tr>';
		});
		html += '</tbody></table>';
		resultsDiv.innerHTML = html;
		wireImportButtons();
	}

	function wireImportButtons() {
		resultsDiv.querySelectorAll('.agentic-hub-import-btn').forEach(function(btn) {
			btn.addEventListener('click', async function() {
				if (!await agenticUI.confirm(DATA.i18n.importThisSkill, { confirmText: DATA.i18n.import })) return;
				var thisBtn = this;
				var skillData = JSON.parse(thisBtn.dataset.skill);
				thisBtn.disabled = true;
				thisBtn.textContent = DATA.i18n.importing;

				if ('clawhub' === skillData.source) {
					finishClawhubImport(skillData);
				} else {
					submitImport(skillData);
				}
			});
		});
	}

	function submitImport(skillData) {
		importDataField.value = JSON.stringify(skillData);
		importForm.submit();
	}

	// ClawHub search results only carry summary metadata — fetch full
	// detail + the actual SKILL.md body (from the openclaw/skills GitHub
	// repo ClawHub indexes) only once the user commits to importing.
	function finishClawhubImport(skillData) {
		var slug = skillData.slug;
		fetch(CLAWHUB_API + '/skills/' + encodeURIComponent(slug))
		.then(function(r) { return r.ok ? r.json() : null; })
		.then(function(detail) {
			if (detail && detail.skill) {
				skillData.description = detail.skill.summary || skillData.description;
				if (detail.latestVersion) {
					skillData.version = detail.latestVersion.version || skillData.version;
				}
				if (detail.owner) {
					skillData.author = detail.owner.displayName || detail.owner.handle || '';
				}
			}
			var ownerHandle = (detail && detail.owner) ? (detail.owner.handle || '') : '';
			var canonicalSlug = (detail && detail.skill) ? (detail.skill.slug || slug) : slug;
			if (ownerHandle) {
				// Browser-side text fetch (a Markdown doc, not a script/asset) for the explicit, user-initiated "import a community skill" action.
				return fetch('https://raw.githubusercontent.com/openclaw/skills/main/skills/' + encodeURIComponent(ownerHandle) + '/' + encodeURIComponent(canonicalSlug) + '/SKILL.md')
				.then(function(r) { return r.ok ? r.text() : ''; })
				.then(function(md) { skillData.content = md || ''; })
				.catch(function() {});
			}
		})
		.then(function() { submitImport(skillData); })
		.catch(function() { submitImport(skillData); }); // Import with search data only.
	}

	function doClawhubSearch() {
		var query = searchInput.value.trim();
		if (!query) return;

		resultsDiv.innerHTML = '<p class="agentic-text-dim">' + escHtml(DATA.i18n.searching) + '</p>';
		searchBtn.disabled = true;

		fetch(CLAWHUB_API + '/search?q=' + encodeURIComponent(query), {
			method: 'GET',
			headers: { 'Accept': 'application/json' },
		})
		.then(function(r) {
			if (!r.ok) throw new Error('HTTP ' + r.status);
			return r.json();
		})
		.then(function(data) {
			searchBtn.disabled = false;
			var skills = data.results || data.items || [];
			var items = skills.map(function(skill) {
				return {
					name: skill.displayName || skill.slug || 'Untitled',
					description: skill.summary || '',
					slug: skill.slug || '',
					version: skill.version || '1.0.0',
					source: 'clawhub',
				};
			});
			renderResults(items, DATA.i18n.noSkillsFoundTryA);
		})
		.catch(function(err) {
			searchBtn.disabled = false;
			resultsDiv.innerHTML = '<div class="notice notice-error"><p>' + escHtml(DATA.i18n.failedToConnectToClawhub) + ' (' + escHtml(err.message || '') + ')</p></div>';
		});
	}

	function loadGithubSource(key, src) {
		if (cache[key]) {
			renderResults(cache[key], DATA.i18n.noSkillsFoundInThis);
			return;
		}
		resultsDiv.innerHTML = '<p class="agentic-text-dim">' + escHtml(DATA.i18n.loading) + '</p>';

		fetch('https://api.github.com/repos/' + src.owner + '/' + src.repo + '/git/trees/' + src.branch + '?recursive=1', {
			headers: { 'Accept': 'application/vnd.github+json' },
		})
		.then(function(r) {
			if (!r.ok) throw new Error('HTTP ' + r.status);
			return r.json();
		})
		.then(function(data) {
			var slugs = (data.tree || [])
				.map(function(e) { return e.path || ''; })
				.filter(function(p) { return /^skills\/[^\/]+\/SKILL\.md$/.test(p); })
				.map(function(p) { return p.split('/')[1]; });

			return Promise.all(slugs.map(function(slug) {
				// Browser-side text fetch (a Markdown doc, not a script/asset) for the explicit, user-initiated "browse community skills" action.
				var rawUrl = 'https://raw.githubusercontent.com/' + src.owner + '/' + src.repo + '/' + src.branch + '/skills/' + slug + '/SKILL.md';
				return fetch(rawUrl)
					.then(function(r) { return r.ok ? r.text() : ''; })
					.then(function(raw) {
						if (!raw) return null;
						var fm = parseFrontMatter(raw);
						return {
							slug: slug,
							name: fm.name || slug,
							description: fm.description || '',
							content: raw,
							version: '1.0.0',
							author: src.label,
							source: key,
						};
					});
			}));
		})
		.then(function(items) {
			items = items.filter(Boolean);
			items.sort(function(a, b) { return a.name.localeCompare(b.name); });
			cache[key] = items;
			renderResults(items, DATA.i18n.noSkillsFoundInThis);
		})
		.catch(function(err) {
			resultsDiv.innerHTML = '<div class="notice notice-error"><p>' +
				'' + escHtml(DATA.i18n.failedToLoadSkillsFrom) + ' ' + escHtml(src.label) + '. (' + escHtml(err.message || '') + ')</p></div>';
		});
	}

	function switchSource(key) {
		var src = SOURCES[key];
		if (!src) return;
		if (switcherEl) {
			switcherEl.querySelectorAll('[data-source]').forEach(function(b) {
				b.classList.toggle('is-active', b.dataset.source === key);
			});
		}
		if ('github' === src.type) {
			// .hidden alone isn't enough here — agentic-flex-gap-8-mb-20
			// sets display:flex in an author stylesheet, which beats the
			// browser's default [hidden]{display:none} UA rule regardless
			// of the hidden attribute/property. Toggle display directly.
			searchWrap.hidden = true;
			searchWrap.style.display = 'none';
			loadGithubSource(key, src);
		} else {
			searchWrap.hidden = false;
			searchWrap.style.display = '';
			resultsDiv.innerHTML = '<p class="agentic-text-dim">' + escHtml(DATA.i18n.enterASearchTermAbove) + '</p>';
		}
	}

	if (switcherEl) {
		switcherEl.querySelectorAll('[data-source]').forEach(function(btn) {
			btn.addEventListener('click', function() { switchSource(this.dataset.source); });
		});
	}

	searchBtn.addEventListener('click', doClawhubSearch);
	searchInput.addEventListener('keydown', function(e) {
		if (e.key === 'Enter') { e.preventDefault(); doClawhubSearch(); }
	});

	switchSource('wordpress');
})();
