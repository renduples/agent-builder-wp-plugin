/**
 * [agentic_chat style="popup"]: start each popup chat hidden and toggle it
 * from its trigger button (button[data-target] -> container id). Works for
 * any number of popup chats on the page.
 */
( function () {
	'use strict';
	function init() {
		Array.prototype.forEach.call( document.querySelectorAll( '.agentic-chat-trigger[data-target]' ), function ( trigger ) {
			if ( trigger.dataset.agenticPopupReady ) {
				return;
			}
			var container = document.getElementById( trigger.getAttribute( 'data-target' ) );
			if ( ! container ) {
				return;
			}
			trigger.dataset.agenticPopupReady = '1';
			container.classList.add( 'agentic-chat-hidden' );
			trigger.addEventListener( 'click', function () {
				container.classList.toggle( 'agentic-chat-hidden' );
			} );
		} );
	}
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
