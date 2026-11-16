(function($){
	'use strict';

	const D       = window.agenticDeployScheduled || {};
	const L       = D.i18n || {};
	const ajaxUrl = D.ajaxUrl;
	const nonce   = D.nonce;

	function resetForm() {
		$('#agentic-sched-editing-id').val('');
		$('#agentic-sched-agent').val('');
		$('#agentic-sched-recurrence').val('daily');
		$('#agentic-sched-prompt').val('');
		$('#agentic-sched-name').val('');
		$('#agentic-sched-description').val('');
		$('#agentic-sched-save').text(L.addSchedule);
		$('#agentic-sched-cancel').hide();
		$('#agentic-sched-status').text('');
	}

	$('#agentic-sched-cancel').on('click', resetForm);

	$('#agentic-sched-save').on('click', function(){
		const $btn    = $(this);
		const $status = $('#agentic-sched-status');
		const agent   = $('#agentic-sched-agent').val();
		const prompt  = $('#agentic-sched-prompt').val().trim();

		if ( ! agent ) {
			$status.css('color','#d63638').text(L.chooseAgent);
			return;
		}
		if ( ! prompt ) {
			$status.css('color','#d63638').text(L.enterPrompt);
			return;
		}

		$btn.prop('disabled', true).text(L.saving);
		$status.text('');

		$.post(ajaxUrl, {
			action      : 'agentic_save_user_scheduled_task',
			_ajax_nonce : nonce,
			task_id     : $('#agentic-sched-editing-id').val(),
			agent_slug  : agent,
			schedule    : $('#agentic-sched-recurrence').val(),
			prompt      : prompt,
			name        : $('#agentic-sched-name').val(),
			description : $('#agentic-sched-description').val(),
		})
		.done(function(resp){
			if (resp.success) {
				window.location.reload();
			} else {
				$status.css('color','#d63638').text(resp.data || L.saveError);
				$btn.prop('disabled', false).text(L.addSchedule);
			}
		})
		.fail(function(){
			$status.css('color','#d63638').text(L.requestFailed);
			$btn.prop('disabled', false).text(L.addSchedule);
		});
	});

	$(document).on('click', '.agentic-sched-delete', async function(){
		const confirmMsg = L.confirmDelete;
		if ( window.agenticUI && typeof agenticUI.confirm === 'function' ) {
			if ( ! await agenticUI.confirm(confirmMsg, { danger: true }) ) return;
		} else if ( ! window.confirm(confirmMsg) ) {
			return;
		}

		const $btn = $(this);
		const id   = $btn.data('id');
		$btn.prop('disabled', true);

		$.post(ajaxUrl, {
			action      : 'agentic_delete_user_scheduled_task',
			_ajax_nonce : nonce,
			task_id     : id,
		})
		.done(function(resp){
			if (resp.success) {
				$('#agentic-sched-row-' + id).fadeOut(300, function(){ $(this).remove(); });
			} else {
				$btn.prop('disabled', false);
				if ( window.agenticUI && agenticUI.toast ) {
					agenticUI.toast(resp.data || L.deleteError, 'error');
				} else {
					alert(resp.data || L.deleteError);
				}
			}
		})
		.fail(function(){
			$btn.prop('disabled', false);
		});
	});

})(jQuery);
