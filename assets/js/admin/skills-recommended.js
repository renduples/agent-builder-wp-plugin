/**
 * Skills page: recommended skills grid (agentic-plugin.com catalogue).
 *
 * Data: window.agenticSkillsRecommended { i18n }.
 */
(function() {
	'use strict';
	var DATA = window.agenticSkillsRecommended || {};
	var SKILLS_API = 'https://agentic-plugin.com/wp-json/agentic/v1/skills';
	var grid = document.getElementById('agentic-recommended-grid');
	var importForm = document.getElementById('agentic-rec-import-form');
	var importField = document.getElementById('agentic-rec-data');

	fetch(SKILLS_API + '/featured')
	.then(function(r) { return r.ok ? r.json() : []; })
	.then(function(skills) {
		if (!skills.length) {
			// Fallback: try search endpoint
			return fetch(SKILLS_API + '?per_page=12')
			.then(function(r) { return r.ok ? r.json() : { skills: [] }; })
			.then(function(d) { return d.skills || []; });
		}
		return skills;
	})
	.then(function(skills) {
		if (!skills || !skills.length) {
			grid.innerHTML = '<p class="agentic-text-dim">' + escHtml(DATA.i18n.noRecommendedSkillsAvailableYet) + '</p>';
			return;
		}

		var html = '<div class="agentic-skills-js-grid">';
		skills.forEach(function(skill) {
			var channels = (skill.channels || []).map(function(c) {
				return '<span class="agentic-badge-indigo-sm">' + escHtml(c) + '</span>';
			}).join(' ');

			html += '<div class="agentic-skill-card-js">';
			html += '<div class="agentic-skill-card-head">';
			html += '<strong class="agentic-skill-card-title">' + escHtml(skill.name) + '</strong>';
			if (skill.featured) html += '<span class="agentic-badge-featured">Featured</span>';
			html += '</div>';
			html += '<p class="agentic-skill-card-desc">' + escHtml(skill.description || '') + '</p>';
			html += '<div class="agentic-skill-card-tags">';
			html += channels;
			html += '<span class="agentic-skill-card-ver">v' + escHtml(skill.version || '1.0.0') + '</span>';
			html += '</div>';
			html += '<div class="agentic-skill-card-btns">';
			html += '<button type="button" class="button button-small agentic-rec-import" data-slug="' + escAttr(skill.slug) + '" data-skill=\'' + escAttr(JSON.stringify(skill)) + '\'>' + escHtml(DATA.i18n.import) + '</button>';
			html += '</div>';
			html += '</div>';
		});
		html += '</div>';
		grid.innerHTML = html;

		grid.querySelectorAll('.agentic-rec-import').forEach(function(btn) {
			btn.addEventListener('click', async function() {
				if (!await agenticUI.confirm(DATA.i18n.importThisSkill, { confirmText: DATA.i18n.import })) return;
				var slug = this.dataset.slug;
				var skillData = JSON.parse(this.dataset.skill);
				this.disabled = true;
				this.textContent = DATA.i18n.importing;

				// Fetch full content from our API.
				fetch(SKILLS_API + '/' + encodeURIComponent(slug))
				.then(function(r) { return r.ok ? r.json() : null; })
				.then(function(detail) {
					var importData = {
						name: detail ? detail.name : skillData.name,
						description: detail ? detail.description : skillData.description,
						content: detail ? (detail.content || '') : '',
						version: detail ? detail.version : skillData.version,
						author: detail ? detail.author : (skillData.author || ''),
						slug: slug,
						source: 'agentic',
					};
					importField.value = JSON.stringify(importData);
					importForm.submit();
				})
				.catch(function() {
					importField.value = JSON.stringify(skillData);
					importForm.submit();
				});

				// Track the import.
				fetch(SKILLS_API + '/' + encodeURIComponent(slug) + '/import', { method: 'POST' }).catch(function(){});
			});
		});
	})
	.catch(function() {
		grid.innerHTML = '<p class="agentic-text-dim">' + escHtml(DATA.i18n.couldNotLoadRecommendedSkills) + '</p>';
	});

	function escHtml(str) { var d = document.createElement('div'); d.textContent = str; return d.innerHTML; }
	function escAttr(str) { return str.replace(/&/g,'&amp;').replace(/'/g,'&#39;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
})();
