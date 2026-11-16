(function($){
	'use strict';

	const D       = window.agenticDeployTriggers || {};
	const L       = D.i18n || {};
	const ajaxUrl = D.ajaxUrl;
	const nonce   = D.nonce;

	function getHook() {
		const sel = $('#agentic-trigger-hook').val();
		return sel === '_custom'
			? $('#agentic-trigger-custom-hook').val().trim()
			: sel;
	}

	function resetForm() {
		$('#agentic-trigger-editing-id').val('');
		$('#agentic-trigger-agent').val('');
		$('#agentic-trigger-hook').val('');
		$('#agentic-trigger-custom').hide();
		$('#agentic-trigger-custom-hook').val('');
		$('#agentic-trigger-prompt').val('');
		$('#agentic-trigger-name').val('');
		$('#agentic-trigger-priority').val('10');
		$('#agentic-trigger-save').text(L.addTrigger);
		$('#agentic-trigger-cancel').hide();
		$('#agentic-trigger-status').text('');
	}

	$('#agentic-trigger-cancel').on('click', resetForm);

	$('#agentic-trigger-save').on('click', function(){
		const $btn    = $(this);
		const $status = $('#agentic-trigger-status');
		const hook    = getHook();
		const agent   = $('#agentic-trigger-agent').val();

		if ( ! agent ) { $status.css('color','#d63638').text(L.chooseAgent); return; }
		if ( ! hook )  { $status.css('color','#d63638').text(L.chooseHook); return; }

		$btn.prop('disabled', true).text(L.saving);
		$status.text('');

		$.post(ajaxUrl, {
			action      : 'agentic_save_user_trigger',
			_ajax_nonce : nonce,
			trigger_id  : $('#agentic-trigger-editing-id').val(),
			agent_slug  : agent,
			hook        : hook,
			prompt      : $('#agentic-trigger-prompt').val(),
			name        : $('#agentic-trigger-name').val(),
			priority    : $('#agentic-trigger-priority').val(),
		})
		.done(function(resp){
			if (resp.success) {
				// Reload page to show new/updated row.
				window.location.reload();
			} else {
				$status.css('color','#d63638').text(resp.data || L.saveError);
				$btn.prop('disabled', false).text(L.addTrigger);
			}
		})
		.fail(function(){
			$status.css('color','#d63638').text(L.requestFailed);
			$btn.prop('disabled', false).text(L.addTrigger);
		});
	});

	$(document).on('click', '.agentic-trigger-delete', async function(){
		if ( ! await agenticUI.confirm(L.confirmRemove, { danger: true }) ) return;

		const $btn = $(this);
		const id   = $btn.data('id');
		$btn.prop('disabled', true);

		$.post(ajaxUrl, {
			action      : 'agentic_delete_user_trigger',
			_ajax_nonce : nonce,
			trigger_id  : id,
		})
		.done(function(resp){
			if (resp.success) {
				$('#agentic-trigger-row-' + id).fadeOut(300, function(){ $(this).remove(); });
			} else {
				$btn.prop('disabled', false);
				agenticUI.toast(resp.data || L.deleteError, 'error');
			}
		})
		.fail(function(){
			$btn.prop('disabled', false);
		});
	});

})(jQuery);
