/**
 * AgentStructor — shared UI primitives (toasts + accessible confirm/alert modals).
 *
 * Exposes a single global `window.agenticUI` so admin pages and the chat UI can
 * replace blocking native alert()/confirm() calls with non-blocking, accessible
 * components.
 *
 *   agenticUI.toast( message, type, opts )  → transient notification (no return)
 *   agenticUI.confirm( message, opts )      → Promise<boolean>
 *   agenticUI.alert( message, opts )        → Promise<void>
 *
 * Declarative confirmation: any element carrying `data-agentic-confirm="message"`
 * is intercepted automatically:
 *   - <a data-agentic-confirm="…">            → confirm, then navigate to href
 *   - <form data-agentic-confirm="…">         → confirm, then re-submit (submitter preserved)
 * Optional: data-agentic-confirm-title, data-agentic-confirm-danger,
 *           data-agentic-confirm-ok, data-agentic-confirm-cancel.
 *
 * @package Agent_Builder
 */
/* global agenticUIL10n */
( function () {
	'use strict';

	if ( window.agenticUI ) {
		return;
	}

	var L10N = ( typeof agenticUIL10n === 'object' && agenticUIL10n ) ? agenticUIL10n : {};
	var T = {
		ok: L10N.ok || 'OK',
		confirm: L10N.confirm || 'Confirm',
		cancel: L10N.cancel || 'Cancel',
		dismiss: L10N.dismiss || 'Dismiss',
		areYouSure: L10N.areYouSure || 'Are you sure?'
	};

	var MAX_TOASTS = 4;

	/* ───────────────────────── Toasts ───────────────────────── */

	function toastContainer() {
		var el = document.getElementById( 'agentic-ui-toasts' );
		if ( ! el ) {
			el = document.createElement( 'div' );
			el.id = 'agentic-ui-toasts';
			el.className = 'agentic-ui-toasts';
			el.setAttribute( 'role', 'region' );
			el.setAttribute( 'aria-live', 'polite' );
			el.setAttribute( 'aria-label', L10N.notifications || 'Notifications' );
			document.body.appendChild( el );
		}
		return el;
	}

	function toast( message, type, opts ) {
		opts = opts || {};
		type = type || 'info';
		var container = toastContainer();

		// Cap the number of simultaneously visible toasts.
		while ( container.children.length >= MAX_TOASTS ) {
			container.removeChild( container.firstChild );
		}

		var isUrgent = ( 'error' === type || 'warning' === type );
		var node = document.createElement( 'div' );
		node.className = 'agentic-ui-toast agentic-ui-toast--' + type;
		node.setAttribute( 'role', isUrgent ? 'alert' : 'status' );

		var text = document.createElement( 'span' );
		text.className = 'agentic-ui-toast__msg';
		text.textContent = String( message );
		node.appendChild( text );

		var close = document.createElement( 'button' );
		close.type = 'button';
		close.className = 'agentic-ui-toast__close';
		close.setAttribute( 'aria-label', T.dismiss );
		close.innerHTML = '&times;';
		node.appendChild( close );

		var timer = null;
		function remove() {
			if ( timer ) {
				window.clearTimeout( timer );
				timer = null;
			}
			node.classList.add( 'agentic-ui-toast--leaving' );
			window.setTimeout( function () {
				if ( node.parentNode ) {
					node.parentNode.removeChild( node );
				}
			}, 200 );
		}

		close.addEventListener( 'click', remove );
		container.appendChild( node );
		// Trigger enter transition on next frame.
		window.requestAnimationFrame( function () {
			node.classList.add( 'agentic-ui-toast--in' );
		} );

		var duration = ( typeof opts.duration === 'number' ) ? opts.duration : ( isUrgent ? 6000 : 4000 );
		if ( duration > 0 ) {
			timer = window.setTimeout( remove, duration );
			// Pause auto-dismiss on hover for readability.
			node.addEventListener( 'mouseenter', function () {
				if ( timer ) {
					window.clearTimeout( timer );
					timer = null;
				}
			} );
			node.addEventListener( 'mouseleave', function () {
				if ( ! timer && duration > 0 ) {
					timer = window.setTimeout( remove, duration );
				}
			} );
		}

		return remove;
	}

	/* ───────────────────────── Modal (confirm / alert) ───────────────────────── */

	var FOCUSABLE = 'a[href], button:not([disabled]), textarea, input, select, [tabindex]:not([tabindex="-1"])';
	var modalOpen = false;

	function buildModal( message, opts, withCancel ) {
		return new Promise( function ( resolve ) {
			// Guard: never stack modals. Resolve the new one falsy/empty so callers
			// relying on it don't hang; in practice modals are short-lived.
			if ( modalOpen ) {
				resolve( withCancel ? false : undefined );
				return;
			}
			modalOpen = true;

			var lastFocus = document.activeElement;

			var overlay = document.createElement( 'div' );
			overlay.className = 'agentic-ui-overlay';

			var dialog = document.createElement( 'div' );
			dialog.className = 'agentic-ui-modal' + ( opts.danger ? ' agentic-ui-modal--danger' : '' );
			dialog.setAttribute( 'role', withCancel ? 'dialog' : 'alertdialog' );
			dialog.setAttribute( 'aria-modal', 'true' );

			var titleId = 'agentic-ui-modal-title';
			var bodyId = 'agentic-ui-modal-body';
			dialog.setAttribute( 'aria-labelledby', titleId );
			dialog.setAttribute( 'aria-describedby', bodyId );

			var title = document.createElement( 'h2' );
			title.className = 'agentic-ui-modal__title';
			title.id = titleId;
			title.textContent = opts.title || T.areYouSure;
			dialog.appendChild( title );

			var body = document.createElement( 'div' );
			body.className = 'agentic-ui-modal__body';
			body.id = bodyId;
			body.textContent = String( message );
			dialog.appendChild( body );

			var actions = document.createElement( 'div' );
			actions.className = 'agentic-ui-modal__actions';

			var cancelBtn = null;
			if ( withCancel ) {
				cancelBtn = document.createElement( 'button' );
				cancelBtn.type = 'button';
				cancelBtn.className = 'button agentic-ui-modal__btn agentic-ui-modal__btn--cancel';
				cancelBtn.textContent = opts.cancelText || T.cancel;
				actions.appendChild( cancelBtn );
			}

			var okBtn = document.createElement( 'button' );
			okBtn.type = 'button';
			okBtn.className = 'button button-primary agentic-ui-modal__btn agentic-ui-modal__btn--ok'
				+ ( opts.danger ? ' agentic-ui-modal__btn--danger' : '' );
			okBtn.textContent = opts.confirmText || ( withCancel ? T.confirm : T.ok );
			actions.appendChild( okBtn );

			dialog.appendChild( actions );
			overlay.appendChild( dialog );
			document.body.appendChild( overlay );
			document.body.classList.add( 'agentic-ui-modal-open' );

			function close( result ) {
				document.removeEventListener( 'keydown', onKey, true );
				document.body.classList.remove( 'agentic-ui-modal-open' );
				if ( overlay.parentNode ) {
					overlay.parentNode.removeChild( overlay );
				}
				modalOpen = false;
				if ( lastFocus && typeof lastFocus.focus === 'function' ) {
					lastFocus.focus();
				}
				resolve( result );
			}

			function onKey( e ) {
				if ( 'Escape' === e.key ) {
					e.preventDefault();
					close( withCancel ? false : undefined );
					return;
				}
				if ( 'Tab' === e.key ) {
					// Containment: keep focus within the dialog.
					var nodes = dialog.querySelectorAll( FOCUSABLE );
					if ( ! nodes.length ) {
						e.preventDefault();
						return;
					}
					var first = nodes[ 0 ];
					var last = nodes[ nodes.length - 1 ];
					if ( e.shiftKey && document.activeElement === first ) {
						e.preventDefault();
						last.focus();
					} else if ( ! e.shiftKey && document.activeElement === last ) {
						e.preventDefault();
						first.focus();
					}
				}
			}

			okBtn.addEventListener( 'click', function () {
				close( withCancel ? true : undefined );
			} );
			if ( cancelBtn ) {
				cancelBtn.addEventListener( 'click', function () {
					close( false );
				} );
			}
			overlay.addEventListener( 'click', function ( e ) {
				if ( e.target === overlay ) {
					close( withCancel ? false : undefined );
				}
			} );
			document.addEventListener( 'keydown', onKey, true );

			// Initial focus: Cancel for confirms (safer default), OK for alerts.
			window.requestAnimationFrame( function () {
				overlay.classList.add( 'agentic-ui-overlay--in' );
				( withCancel && cancelBtn ? cancelBtn : okBtn ).focus();
			} );
		} );
	}

	function confirm( message, opts ) {
		return buildModal( message, opts || {}, true );
	}

	function alert( message, opts ) {
		return buildModal( message, opts || {}, false );
	}

	/* ───────────────── Declarative confirmation interceptor ───────────────── */

	function optsFromEl( el ) {
		return {
			title: el.getAttribute( 'data-agentic-confirm-title' ) || '',
			confirmText: el.getAttribute( 'data-agentic-confirm-ok' ) || '',
			cancelText: el.getAttribute( 'data-agentic-confirm-cancel' ) || '',
			danger: el.hasAttribute( 'data-agentic-confirm-danger' )
		};
	}

	// Links: intercept the primary click, confirm, then navigate. Modified clicks
	// (new tab/window) are intentionally funnelled through the same confirm +
	// same-tab navigation because these are destructive actions where preserving
	// the confirmation gate matters more than the new-tab affordance.
	document.addEventListener( 'click', function ( e ) {
		var link = e.target.closest ? e.target.closest( 'a[data-agentic-confirm]' ) : null;
		if ( ! link ) {
			return;
		}
		e.preventDefault();
		var href = link.getAttribute( 'href' );
		confirm( link.getAttribute( 'data-agentic-confirm' ), optsFromEl( link ) ).then( function ( ok ) {
			if ( ok && href ) {
				window.location.href = href;
			}
		} );
	} );

	// Forms: intercept submit, confirm, then re-submit preserving the submitter
	// (so submit buttons with name=value still post their action) and native
	// validation via requestSubmit().
	document.addEventListener( 'submit', function ( e ) {
		var form = e.target;
		if ( ! form || ! form.hasAttribute || ! form.hasAttribute( 'data-agentic-confirm' ) ) {
			return;
		}
		if ( form._agenticConfirmed ) {
			form._agenticConfirmed = false;
			return;
		}
		e.preventDefault();
		var submitter = e.submitter || null;
		confirm( form.getAttribute( 'data-agentic-confirm' ), optsFromEl( form ) ).then( function ( ok ) {
			if ( ! ok ) {
				return;
			}
			form._agenticConfirmed = true;
			if ( form.requestSubmit ) {
				form.requestSubmit( submitter || undefined );
			} else {
				// Legacy fallback: mirror the submitter as a hidden field so its
				// name=value still posts, then submit natively.
				if ( submitter && submitter.name ) {
					var hidden = document.createElement( 'input' );
					hidden.type = 'hidden';
					hidden.name = submitter.name;
					hidden.value = submitter.value || '';
					form.appendChild( hidden );
				}
				form.submit();
			}
		} );
	}, true );

	window.agenticUI = {
		toast: toast,
		confirm: confirm,
		alert: alert
	};
} )();
