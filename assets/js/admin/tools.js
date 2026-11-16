(function() {
	'use strict';
	var D = window.agenticTools || {};
	var COPY = D.copy || {};
	var HIGH_REASONS = D.highReasons || {};

	function highReason( name ) {
		return HIGH_REASONS[ name ] || COPY.highReasonDefault;
	}

	function highBody( title, name ) {
		return title + ' ' + COPY.highLead + '\n\n'
			+ COPY.highWhy.replace( '%s', highReason( name ) ) + '\n\n'
			+ COPY.highGuard + '\n\n'
			+ COPY.highNote;
	}

	function extremeBody( title ) {
		return title + ' ' + COPY.extremeLead + '\n\n' + COPY.extremeBody;
	}

	document.querySelectorAll('.agentic-tool-toggle').forEach(function(toggle) {
		toggle.addEventListener('change', function() {
			var toolName = this.dataset.tool;
			var enabled  = this.checked;
			var risk     = ( this.dataset.risk || 'none' ).toLowerCase();
			var title    = this.dataset.title || toolName;
			var row      = this.closest('tr');
			var label    = this.closest('.agentic-toggle');
			var el       = this;

			function postToggle( nextEnabled ) {
				label.classList.add('is-saving');

				var data = new FormData();
				data.append('action', 'agentic_toggle_tool');
				data.append('tool', toolName);
				data.append('enabled', nextEnabled ? '1' : '0');
				data.append('_wpnonce', D.toggleNonce);

				fetch(ajaxurl, {
					method: 'POST',
					credentials: 'same-origin',
					body: data,
				})
				.then(function(response) { return response.json(); })
				.then(function(result) {
					label.classList.remove('is-saving');
					if (result.success) {
						row.style.opacity = nextEnabled ? '1' : '0.6';
						label.title = nextEnabled ? COPY.clickDisable : COPY.clickEnable;
					} else {
						el.checked = !nextEnabled;
						if ( window.agenticUI && typeof agenticUI.toast === 'function' ) {
							agenticUI.toast(result.data || 'Failed to update tool status.', 'error');
						}
					}
				})
				.catch(function() {
					label.classList.remove('is-saving');
					el.checked = !nextEnabled;
				});
			}

			// Gate HIGH/EXTREME off→on before any POST so the checkbox never
			// flashes on. Disable (on→off) and low/medium never prompt.
			if ( enabled && ( 'high' === risk || 'extreme' === risk ) ) {
				el.checked = false;
				if ( 'extreme' === risk ) {
					if ( window.agenticUI && typeof agenticUI.alert === 'function' ) {
						agenticUI.alert( extremeBody( title ), {
							title: COPY.extremeTitle,
							confirmText: COPY.close
						} );
					}
					return;
				}
				var ask = ( window.agenticUI && typeof agenticUI.confirm === 'function' )
					? agenticUI.confirm( highBody( title, toolName ), {
						title: COPY.highTitle,
						confirmText: COPY.enable,
						cancelText: COPY.cancel
					} )
					: Promise.resolve( false );
				ask.then( function( ok ) {
					if ( ! ok ) {
						return;
					}
					el.checked = true;
					postToggle( true );
				} );
				return;
			}

			postToggle( enabled );
		});
	});

	// Inbound third-party ability toggles (block-list backed).
	var inboundNonce = D.inboundNonce;
	document.querySelectorAll('.agentic-inbound-toggle').forEach(function(toggle) {
			toggle.addEventListener('change', function() {
				var ability = this.dataset.ability;
				var enabled = this.checked;
				var row     = this.closest('tr');
				var label   = this.closest('.agentic-toggle');

				label.classList.add('is-saving');

				var data = new FormData();
				data.append('action', 'agentic_toggle_inbound_ability');
				data.append('ability', ability);
				data.append('enabled', enabled ? '1' : '0');
				data.append('_wpnonce', inboundNonce);

				fetch(ajaxurl, {
					method: 'POST',
					credentials: 'same-origin',
					body: data,
				})
				.then(function(response) { return response.json(); })
				.then(function(result) {
					label.classList.remove('is-saving');
					if (result.success) {
						row.style.opacity = enabled ? '1' : '0.6';
						label.title = enabled
							? COPY.abilityClickDisable
							: COPY.abilityClickEnable;
					} else {
						toggle.checked = !enabled;
						agenticUI.toast(result.data || 'Failed to update ability status.', 'error');
					}
				})
				.catch(function() {
					label.classList.remove('is-saving');
					toggle.checked = !enabled;
				});
			});
	});
})();
