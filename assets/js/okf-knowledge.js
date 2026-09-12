/**
 * Knowledge Wiki (OKF) admin UI
 */
(function ($) {
	'use strict';

	const root = document.getElementById('agentic-kn-wiki');
	if (!root) {
		return;
	}

	const ajaxUrl = root.dataset.ajax;
	const nonce = root.dataset.nonce;
	let concepts = [];
	let currentId = null;
	let isNew = false;
	let idTouched = false;

	function slugify(text) {
		return (text || '')
			.toLowerCase()
			.replace(/[^a-z0-9]+/g, '-')
			.replace(/^-+|-+$/g, '');
	}

	const $scope = $('#agentic-okf-scope');
	const $list = $('#agentic-okf-list');
	const $count = $('#agentic-okf-count');
	const $empty = $('#agentic-okf-empty');
	const $emptyFiltered = $('#agentic-okf-empty-filtered');
	const $title = $('#agentic-okf-title');
	const $editor = $('#agentic-okf-editor');
	const $placeholder = $('#agentic-okf-placeholder');
	const $status = $('#agentic-okf-status-msg');

	function scope() {
		return $scope.val() || 'site';
	}

	function post(action, data) {
		return $.post(ajaxUrl, Object.assign({ action: action, _ajax_nonce: nonce, scope: scope() }, data || {}));
	}

	function setStatus(msg, isError) {
		$status
			.text(msg || '')
			.toggleClass('is-error', !!isError)
			.toggleClass('is-ok', !!msg && !isError);
	}

	const $typeSelect = $('#agentic-okf-type');
	const $typeCustom = $('#agentic-okf-type-custom');

	function setTypeValue(type) {
		const t = (type || 'FAQ').trim() || 'FAQ';
		const match = $typeSelect.find('option').filter(function () {
			return $(this).val().toLowerCase() === t.toLowerCase();
		}).first();
		if (match.length && match.val() !== '__custom__') {
			$typeSelect.val(match.val());
			$typeCustom.val('').attr('hidden', 'hidden').hide();
			return;
		}
		$typeSelect.val('__custom__');
		$typeCustom.val(t).removeAttr('hidden').show();
	}

	function getTypeValue() {
		if ($typeSelect.val() === '__custom__') {
			return $typeCustom.val().trim() || 'FAQ';
		}
		return ($typeSelect.val() || 'FAQ').trim();
	}

	function syncTypeCustomVisibility() {
		if ($typeSelect.val() === '__custom__') {
			$typeCustom.removeAttr('hidden').show().trigger('focus');
		} else {
			$typeCustom.val('').attr('hidden', 'hidden').hide();
		}
	}

	function renderList(filter) {
		const q = (filter || '').toLowerCase().trim();
		$list.empty();
		const visible = concepts.filter(function (c) {
			if (!q) {
				return true;
			}
			return (
				(c.title || '').toLowerCase().indexOf(q) !== -1 ||
				(c.id || '').toLowerCase().indexOf(q) !== -1 ||
				(c.description || '').toLowerCase().indexOf(q) !== -1 ||
				(c.type || '').toLowerCase().indexOf(q) !== -1
			);
		});
		$count.text(String(concepts.length));
		$empty.prop('hidden', concepts.length > 0);
		$emptyFiltered.prop('hidden', concepts.length === 0 || visible.length > 0);

		visible.forEach(function (c) {
			const $type = $('<span/>').addClass('agentic-kn-list-type').text(c.type || 'Concept');
			if (c.example) {
				$type.append(
					$('<span/>').addClass('agentic-kn-list-example').text(' Example')
				);
			}
			if (c.always_on) {
				$type.append(
					$('<span/>').addClass('agentic-kn-list-example').text(' Always on')
				);
			}
			const li = $('<li/>')
				.addClass(
					'agentic-kn-list-item' +
						(c.id === currentId ? ' is-active' : '') +
						(c.stale ? ' is-stale' : '') +
						(c.example ? ' is-example' : '')
				)
				.attr('data-id', c.id)
				.append(
					$type,
					$('<span/>').addClass('agentic-kn-list-title').text(c.title || c.id),
					$('<span/>').addClass('agentic-kn-list-desc').text(c.description || '')
				);
			$list.append(li);
		});
	}

	function loadList() {
		return post('agentic_okf_list').done(function (res) {
			if (!res || !res.success) {
				setStatus((res && res.data) || 'Failed to load wiki.', true);
				return;
			}
			concepts = res.data.concepts || [];
			renderList($('#agentic-okf-search').val());
		});
	}

	function openEditor(data, asNew) {
		isNew = !!asNew;
		idTouched = !asNew && !!(data.id);
		currentId = data.id || null;
		$placeholder.attr('hidden', 'hidden').hide();
		$editor.removeAttr('hidden').show();
		$('#agentic-okf-id').val(data.id || '').prop('readonly', !asNew);
		$('#agentic-okf-title').val(data.title || (data.frontmatter && data.frontmatter.title) || '');
		setTypeValue((data.frontmatter && data.frontmatter.type) || data.type || 'FAQ');
		$('#agentic-okf-description').val((data.frontmatter && data.frontmatter.description) || data.description || '');
		const tags = (data.frontmatter && data.frontmatter.tags) || data.tags || [];
		$('#agentic-okf-tags').val(Array.isArray(tags) ? tags.join(', ') : tags);
		$('#agentic-okf-status').val((data.frontmatter && data.frontmatter.status) || data.status || 'stable');
		$('#agentic-okf-stale').val((data.frontmatter && data.frontmatter.stale_after) || '');
		$('#agentic-okf-resource').val((data.frontmatter && data.frontmatter.resource) || '');
		$('#agentic-okf-body').val(data.body || '');
		const isExample =
			!!data.example ||
			(data.frontmatter &&
				(data.frontmatter.example === true ||
					data.frontmatter.example === 'true' ||
					data.frontmatter.example === '1'));
		$('#agentic-okf-example').prop('checked', isExample);
		const alwaysOn =
			(data.frontmatter &&
				(data.frontmatter.always_on === true ||
					data.frontmatter.always_on === 'true' ||
					data.frontmatter.always_on === '1' ||
					data.frontmatter.inject === 'always')) ||
			data.always_on === true;
		$('#agentic-okf-always-on').prop('checked', !!alwaysOn);
		const titleText = asNew ? 'New concept' : data.title || data.id || 'Concept';
		$('#agentic-okf-editor-title').text(isExample ? 'Example — ' + titleText : titleText);
		$editor.toggleClass('is-example', isExample);
		renderList($('#agentic-okf-search').val());
		setStatus(isExample ? 'Demo example — agents cannot use this until you uncheck Example.' : '');
	}

	function newConcept() {
		openEditor(
			{
				id: '',
				title: '',
				type: 'FAQ',
				description: '',
				body: '',
				example: false,
				always_on: false,
				frontmatter: { type: 'FAQ', status: 'stable' },
			},
			true
		);
		$('#agentic-okf-title').trigger('focus');
	}

	function loadConcept(id) {
		post('agentic_okf_get', { id: id }).done(function (res) {
			if (!res || !res.success) {
				setStatus((res && res.data) || 'Could not open concept.', true);
				return;
			}
			openEditor(res.data, false);
		});
	}

	function saveConcept() {
		const payload = {
			id: $('#agentic-okf-id').val().trim(),
			title: $('#agentic-okf-title').val().trim(),
			type: getTypeValue(),
			description: $('#agentic-okf-description').val().trim(),
			tags: $('#agentic-okf-tags').val(),
			status: $('#agentic-okf-status').val(),
			stale_after: $('#agentic-okf-stale').val(),
			resource: $('#agentic-okf-resource').val().trim(),
			body: $('#agentic-okf-body').val(),
			example: $('#agentic-okf-example').is(':checked') ? '1' : '0',
			always_on: $('#agentic-okf-always-on').is(':checked') ? '1' : '0',
		};
		if (!payload.title) {
			setStatus('Title is required.', true);
			$title.addClass('agentic-kn-field-invalid').trigger('focus');
			return;
		}
		$title.removeClass('agentic-kn-field-invalid');
		if (!payload.id) {
			payload.id = slugify(payload.title);
			$('#agentic-okf-id').val(payload.id);
		}
		post('agentic_okf_save', payload).done(function (res) {
			if (!res || !res.success) {
				setStatus((res && res.data) || 'Save failed.', true);
				return;
			}
			concepts = res.data.concepts || concepts;
			currentId = res.data.id || payload.id;
			isNew = false;
			$('#agentic-okf-id').prop('readonly', true);
			renderList($('#agentic-okf-search').val());
			setStatus('Saved.');
		});
	}

	function deleteConcept() {
		if (!currentId || isNew) {
			$editor.attr('hidden', 'hidden').hide();
			$placeholder.removeAttr('hidden').show();
			currentId = null;
			return;
		}
		if (!window.confirm('Delete this concept? This cannot be undone.')) {
			return;
		}
		post('agentic_okf_delete', { id: currentId }).done(function (res) {
			if (!res || !res.success) {
				setStatus((res && res.data) || 'Delete failed.', true);
				return;
			}
			concepts = res.data.concepts || [];
			currentId = null;
			$editor.attr('hidden', 'hidden').hide();
			$placeholder.removeAttr('hidden').show();
			renderList($('#agentic-okf-search').val());
			setStatus('Deleted.');
		});
	}

	function exportBundle() {
		post('agentic_okf_export').done(function (res) {
			if (!res || !res.success) {
				setStatus((res && res.data) || 'Export failed.', true);
				return;
			}
			const blob = new Blob([res.data.content], { type: 'text/markdown;charset=utf-8' });
			const url = URL.createObjectURL(blob);
			const a = document.createElement('a');
			a.href = url;
			a.download = res.data.filename || 'okf-export.md';
			document.body.appendChild(a);
			a.click();
			a.remove();
			URL.revokeObjectURL(url);
			setStatus('Export downloaded.');
		});
	}

	function importPersona() {
		const agent = scope();
		if (!agent || agent === 'site') {
			setStatus('Select an agent wiki first, then import that agent’s persona knowledge text.', true);
			return;
		}
		post('agentic_okf_import_persona', { agent: agent }).done(function (res) {
			if (!res || !res.success) {
				setStatus((res && res.data) || 'Import failed.', true);
				return;
			}
			concepts = res.data.concepts || [];
			renderList();
			setStatus(res.data.message || 'Imported.');
		});
	}

	$list.on('click', '.agentic-kn-list-item', function () {
		loadConcept($(this).data('id'));
	});
	$typeSelect.on('change', syncTypeCustomVisibility);

	$title.on('input', function () {
		$title.removeClass('agentic-kn-field-invalid');
	});

	// Fill ID from Title when the user leaves the Title field (new concepts only).
	$('#agentic-okf-title').on('blur', function () {
		if (!isNew || idTouched) {
			return;
		}
		const slug = slugify($(this).val());
		if (slug) {
			$('#agentic-okf-id').val(slug);
		}
	});
	$('#agentic-okf-id').on('input', function () {
		if (isNew) {
			idTouched = true;
		}
	});
	$('#agentic-okf-new, #agentic-okf-new-2').on('click', newConcept);
	$('#agentic-okf-save').on('click', saveConcept);
	$('#agentic-okf-delete').on('click', deleteConcept);
	$('#agentic-okf-export').on('click', exportBundle);
	$('#agentic-okf-import-persona').on('click', importPersona);
	$scope.on('change', function () {
		currentId = null;
		$editor.attr('hidden', 'hidden').hide();
		$placeholder.removeAttr('hidden').show();
		loadList();
	});
	$('#agentic-okf-search').on('input', function () {
		renderList($(this).val());
	});

	loadList();
})(jQuery);
