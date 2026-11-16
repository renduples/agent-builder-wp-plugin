/**
 * Approvals & Backups admin page.
 *
 * Data: window.agenticApprovals { restBase, nonce }.
 */
jQuery(document).ready(function($) {
	var restBase = window.agenticApprovals.restBase;
	var nonce    = window.agenticApprovals.nonce;

	function ajaxHeaders(xhr) {
		xhr.setRequestHeader('X-WP-Nonce', nonce);
	}

	// --- Approvals tab ---

	function unlockNextStep(item) {
		var group = item.closest('.agentic-approval-group');
		if (!group.length) return;

		// Find the first pending-step placeholder and convert it to real buttons.
		var next = group.find('.agentic-step-pending').first();
		if (next.length) {
			var nextId = next.data('id');
			next.replaceWith(
				'<button class="button button-small button-primary agentic-approve-btn" data-id="' + nextId + '">Approve</button> ' +
				'<button class="button button-small agentic-reject-btn" data-id="' + nextId + '">Reject</button>'
			);
			// Re-bind click handlers on the new buttons.
			group.find('.agentic-approve-btn[data-id="' + nextId + '"]').on('click', approveHandler);
			group.find('.agentic-reject-btn[data-id="' + nextId + '"]').on('click', rejectHandler);
		}
	}

	function approveHandler() {
		var btn  = $(this);
		var id   = btn.data('id');
		var item = btn.closest('.agentic-approval-item');

		item.css('opacity', '0.6');
		btn.prop('disabled', true).text('Approving...');

		$.ajax({
			url: restBase + 'approvals/' + id,
			method: 'POST',
			beforeSend: ajaxHeaders,
			data: { action: 'approve' },
			success: function() {
				item.css({ 'opacity': '1', 'background': '#f0fdf4' });
				btn.text('Done').css('color', '#16a34a');
				item.find('.agentic-reject-btn').remove();
				unlockNextStep(item);
				// Remove from group after a moment.
				setTimeout(function() {
					item.slideUp(300, function() {
						$(this).remove();
						var group = btn.closest('.agentic-approval-group');
						if (group.length && group.find('.agentic-approval-item').length === 0) {
							group.fadeOut(300, function() {
								$(this).remove();
								if ($('.agentic-approval-group').length === 0) location.reload();
							});
						}
					});
				}, 600);
			},
			error: function(xhr) {
				agenticUI.toast('Error: ' + (xhr.responseJSON?.error || xhr.responseJSON?.message || 'Failed to approve action'), 'error');
				item.css('opacity', '1');
				btn.prop('disabled', false).text('Approve');
			}
		});
	}

	function rejectHandler() {
		var btn  = $(this);
		var id   = btn.data('id');
		var item = btn.closest('.agentic-approval-item');

		item.css('opacity', '0.6');
		btn.prop('disabled', true).text('Rejecting...');

		$.ajax({
			url: restBase + 'approvals/' + id,
			method: 'POST',
			beforeSend: ajaxHeaders,
			data: { action: 'reject' },
			success: function() {
				item.slideUp(300, function() {
					$(this).remove();
					unlockNextStep(item);
					var group = btn.closest('.agentic-approval-group');
					if (group.length && group.find('.agentic-approval-item').length === 0) {
						group.fadeOut(300, function() {
							$(this).remove();
							if ($('.agentic-approval-group').length === 0) location.reload();
						});
					}
				});
			},
			error: function(xhr) {
				agenticUI.toast('Error: ' + (xhr.responseJSON?.error || xhr.responseJSON?.message || 'Failed to reject action'), 'error');
				item.css('opacity', '1');
				btn.prop('disabled', false).text('Reject');
			}
		});
	}

	$('.agentic-approve-btn').on('click', approveHandler);

	$('.agentic-reject-btn').on('click', rejectHandler);

	// --- Approve All (sequential, in order) ---

	$('.agentic-approve-all-btn').on('click', async function() {
		var btn  = $(this);
		var ids  = btn.data('ids');
		var group = btn.closest('.agentic-approval-group');

		if (!ids || !ids.length) return;
		if (!await agenticUI.confirm('Approve all ' + ids.length + ' actions in this group? They will be executed in the correct order.', { confirmText: 'Approve all' })) return;

		btn.prop('disabled', true).text('Approving...');
		group.find('.agentic-reject-all-btn').prop('disabled', true);

		var index = 0;
		function approveNext() {
			if (index >= ids.length) {
				group.fadeOut(400, function() {
					$(this).remove();
					if ($('.agentic-approval-group').length === 0) location.reload();
				});
				return;
			}

			var id   = ids[index];
			var item = group.find('.agentic-approval-item[data-id="' + id + '"]');
			var step = item.find('.agentic-approve-btn');

			item.css('opacity', '0.6');
			step.prop('disabled', true).text('...');
			btn.text('Approving ' + (index + 1) + '/' + ids.length + '...');

			$.ajax({
				url: restBase + 'approvals/' + id,
				method: 'POST',
				beforeSend: ajaxHeaders,
				data: { action: 'approve' },
				success: function() {
					item.css({ 'opacity': '1', 'background': '#f0fdf4' });
					step.text('Done').css('color', '#16a34a');
					index++;
					approveNext();
				},
				error: function(xhr) {
					var msg = xhr.responseJSON?.error || xhr.responseJSON?.message || 'Failed';
					item.css('opacity', '1');
					step.text('Failed').css('color', '#dc2626');
					agenticUI.toast('Error approving step ' + (index + 1) + ': ' + msg, 'error');
					btn.prop('disabled', false).html('<span class="dashicons dashicons-yes-alt agentic-di-va-m2"></span> Approve All');
					group.find('.agentic-reject-all-btn').prop('disabled', false);
				}
			});
		}
		approveNext();
	});

	// --- Reject All ---

	$('.agentic-reject-all-btn').on('click', async function() {
		var btn  = $(this);
		var ids  = btn.data('ids');
		var group = btn.closest('.agentic-approval-group');

		if (!ids || !ids.length) return;
		if (!await agenticUI.confirm('Reject all ' + ids.length + ' actions in this group?', { danger: true, confirmText: 'Reject all' })) return;

		btn.prop('disabled', true).text('Rejecting...');
		group.find('.agentic-approve-all-btn').prop('disabled', true);

		var completed = 0;
		ids.forEach(function(id) {
			$.ajax({
				url: restBase + 'approvals/' + id,
				method: 'POST',
				beforeSend: ajaxHeaders,
				data: { action: 'reject' },
				success: function() {
					completed++;
					if (completed >= ids.length) {
						group.fadeOut(400, function() {
							$(this).remove();
							if ($('.agentic-approval-group').length === 0) location.reload();
						});
					}
				},
				error: function() { completed++; }
			});
		});
	});

	// --- Backups tab ---

	$('.agentic-restore-btn').on('click', async function() {
		var btn  = $(this);
		var file = btn.data('file');
		var row  = btn.closest('.agentic-backup-row');

		if (!await agenticUI.confirm('Restore this file? The current version will be backed up first.', { confirmText: 'Restore' })) {
			return;
		}

		row.addClass('restoring');
		btn.prop('disabled', true).text('Restoring...');

		$.ajax({
			url: restBase + 'backups/restore',
			method: 'POST',
			beforeSend: ajaxHeaders,
			contentType: 'application/json',
			data: JSON.stringify({ file: file }),
			success: function(response) {
				row.removeClass('restoring');
				btn.prop('disabled', false).html('<span class="dashicons dashicons-backup agentic-di-md agentic-di-va-m3"></span> Restore');

				var notice = $('<div class="notice notice-success is-dismissible agentic-my-10"><p></p></div>');
				notice.find('p').text(response.message);
				$('#agentic-approvals-notices').after(notice);

				// Reload to show the new backup entry created during restore.
				setTimeout(function() { location.reload(); }, 1500);
			},
			error: function(xhr) {
				agenticUI.toast('Error: ' + (xhr.responseJSON?.message || 'Restore failed'), 'error');
				row.removeClass('restoring');
				btn.prop('disabled', false).html('<span class="dashicons dashicons-backup agentic-di-md agentic-di-va-m3"></span> Restore');
			}
		});
	});

	$('.agentic-delete-backup-btn').on('click', async function() {
		var btn  = $(this);
		var file = btn.data('file');
		var row  = btn.closest('.agentic-backup-row');

		if (!await agenticUI.confirm('Delete this backup permanently?', { danger: true, confirmText: 'Delete' })) {
			return;
		}

		btn.prop('disabled', true);

		$.ajax({
			url: restBase + 'backups/' + encodeURIComponent(file),
			method: 'DELETE',
			beforeSend: ajaxHeaders,
			success: function() {
				row.fadeOut(400, function() {
					$(this).remove();
					if ($('.agentic-backup-row').length === 0 && $('.agentic-table-backup-row').length === 0) {
						location.reload();
					}
				});
			},
			error: function(xhr) {
				agenticUI.toast('Error: ' + (xhr.responseJSON?.message || 'Delete failed'), 'error');
				btn.prop('disabled', false);
			}
		});
	});

	// --- Database table backups ---

	$('.agentic-restore-table-btn').on('click', async function() {
		var btn  = $(this);
		var file = btn.data('file');
		var row  = btn.closest('.agentic-table-backup-row');

		if (!await agenticUI.confirm('Restore this table? The current table will be backed up first, then replaced with this snapshot.', { confirmText: 'Restore' })) {
			return;
		}

		row.addClass('restoring');
		btn.prop('disabled', true).text('Restoring...');

		$.ajax({
			url: restBase + 'backups/restore-table',
			method: 'POST',
			beforeSend: ajaxHeaders,
			contentType: 'application/json',
			data: JSON.stringify({ file: file }),
			success: function(response) {
				var notice = $('<div class="notice notice-success is-dismissible agentic-my-10"><p></p></div>');
				notice.find('p').text(response.message);
				$('#agentic-approvals-notices').after(notice);
				setTimeout(function() { location.reload(); }, 1500);
			},
			error: function(xhr) {
				agenticUI.toast('Error: ' + (xhr.responseJSON?.message || 'Table restore failed'), 'error');
				row.removeClass('restoring');
				btn.prop('disabled', false).html('<span class="dashicons dashicons-backup agentic-di-md agentic-di-va-m3"></span> Restore');
			}
		});
	});

	$('.agentic-delete-table-backup-btn').on('click', async function() {
		var btn  = $(this);
		var file = btn.data('file');
		var row  = btn.closest('.agentic-table-backup-row');

		if (!await agenticUI.confirm('Delete this table backup permanently?', { danger: true, confirmText: 'Delete' })) {
			return;
		}

		btn.prop('disabled', true);

		$.ajax({
			url: restBase + 'backups/table/' + encodeURIComponent(file),
			method: 'DELETE',
			beforeSend: ajaxHeaders,
			success: function() {
				row.fadeOut(400, function() {
					$(this).remove();
					if ($('.agentic-table-backup-row').length === 0 && $('.agentic-backup-row').length === 0) {
						location.reload();
					}
				});
			},
			error: function(xhr) {
				agenticUI.toast('Error: ' + (xhr.responseJSON?.message || 'Delete failed'), 'error');
				btn.prop('disabled', false);
			}
		});
	});
});
