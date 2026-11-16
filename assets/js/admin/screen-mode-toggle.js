/**
 * Per-screen Basic/Advanced toggle.
 *
 * Data: window.agenticScreenMode = { restUrl, nonce, toggles: [ { id, screen } ] }.
 * Each toggle element (by id) holds buttons with a data-mode attribute; a
 * click stores the screen's mode through the admin-page REST action and
 * reloads.
 */
( function () {
	'use strict';
	var cfg = window.agenticScreenMode || {};
	( cfg.toggles || [] ).forEach( function ( item ) {
		var toggle = document.getElementById( item.id );
		if ( ! toggle ) {
			return;
		}
		Array.prototype.forEach.call( toggle.querySelectorAll( 'button' ), function ( btn ) {
			btn.addEventListener( 'click', function () {
				if ( btn.disabled ) {
					return;
				}
				Array.prototype.forEach.call( toggle.querySelectorAll( 'button' ), function ( b ) {
					b.disabled = true;
				} );
				fetch( cfg.restUrl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': cfg.nonce
					},
					body: JSON.stringify( {
						action_name: 'set_screen_mode',
						screen: item.screen,
						mode: btn.getAttribute( 'data-mode' )
					} )
				} ).then( function () {
					window.location.reload();
				} );
			} );
		} );
	} );
} )();
