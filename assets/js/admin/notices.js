/**
 * Dismiss handlers for Agent Builder admin notices.
 *
 * Each notice carries what it needs in data attributes (nonce, count, slugs,
 * dismiss URL), so several notices on one screen all work from this one
 * script. Requires wp-util (wp.ajax).
 */
( function () {
	'use strict';

	// Notices dismissed through an admin-ajax action: id => [ action, extra data-* keys ].
	var ajaxNotices = {
		'agentic-setup-notice': [ 'agentic_dismiss_setup_notice', [] ],
		'agentic-activation-degraded-notice': [ 'agentic_dismiss_activation_degraded_notice', [] ],
		'agentic-shadowed-agent-notice': [ 'agentic_dismiss_shadowed_agent_notice', [ 'slugs' ] ],
		'agentic-pending-approval-notice': [ 'agentic_dismiss_pending_approval_notice', [ 'count' ] ]
	};

	Object.keys( ajaxNotices ).forEach( function ( id ) {
		var notice = document.getElementById( id );
		if ( ! notice ) {
			return;
		}
		var action = ajaxNotices[ id ][ 0 ];
		var extra  = ajaxNotices[ id ][ 1 ];
		notice.addEventListener( 'click', function ( e ) {
			if ( ! e.target.classList.contains( 'notice-dismiss' ) ) {
				return;
			}
			if ( ! window.wp || ! window.wp.ajax ) {
				return;
			}
			var data = { nonce: notice.dataset.nonce };
			extra.forEach( function ( key ) {
				data[ key ] = notice.dataset[ key ];
			} );
			window.wp.ajax.post( action, data );
		} );
	} );

	// Notices dismissed by requesting a nonce'd URL (quota notice).
	Array.prototype.forEach.call( document.querySelectorAll( '.notice[data-dismiss-url]' ), function ( n ) {
		// The dismiss button is added by WordPress' common.js after load, so
		// listen on the notice and match the button on click.
		n.addEventListener( 'click', function ( e ) {
			if ( ! e.target.classList.contains( 'notice-dismiss' ) ) {
				return;
			}
			var xhr = new XMLHttpRequest();
			xhr.open( 'GET', n.dataset.dismissUrl );
			xhr.send();
		} );
	} );
} )();
