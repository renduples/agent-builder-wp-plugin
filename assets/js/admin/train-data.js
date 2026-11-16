( function () {
	'use strict';
	var toggle = document.getElementById( 'agentic-knowledge-mode-toggle' );
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
			fetch( window.agenticTrainData.restUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': window.agenticTrainData.nonce
				},
				body: JSON.stringify( {
					action_name: 'set_screen_mode',
					screen: 'knowledge',
					mode: btn.getAttribute( 'data-mode' )
				} )
			} ).then( function () {
				window.location.reload();
			} );
		} );
	} );
} )();
