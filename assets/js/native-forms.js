/**
 * Agentic Native Forms — front-end submission handler
 *
 * Intercepts .agentic-form submit events, POSTs to the REST submission
 * endpoint via fetch, handles success / validation errors / redirects.
 * Supports conditional logic show/hide and Turnstile widget integration.
 * No jQuery or build step required.
 */
( function () {
	'use strict';

	/**
	 * Initialise conditional logic for a form.
	 */
	function initConditionalLogic( form ) {
		var wrap = form.closest( '.agentic-form-wrap' );
		var formId = wrap ? wrap.getAttribute( 'data-form-id' ) : '';
		// Rules are passed by PHP (wp_add_inline_script) keyed by form ID.
		var all = window.agenticFormConditions || {};
		var rules = formId && Object.prototype.hasOwnProperty.call( all, formId ) ? all[ formId ] : null;

		if ( ! Array.isArray( rules ) || rules.length === 0 ) {
			return;
		}

		function evaluate() {
			var formData = new FormData( form );
			rules.forEach( function ( rule ) {
				if ( ! rule.target || ! rule.field ) {
					return;
				}
				var fieldValue = formData.get( rule.field ) || '';
				var match = false;

				switch ( rule.operator || 'equals' ) {
					case 'equals':
						match = fieldValue === ( rule.value || '' );
						break;
					case 'not_equals':
						match = fieldValue !== ( rule.value || '' );
						break;
					case 'contains':
						match = fieldValue.indexOf( rule.value || '' ) !== -1;
						break;
					case 'not_empty':
						match = fieldValue.trim() !== '';
						break;
					case 'empty':
						match = fieldValue.trim() === '';
						break;
				}

				var action = rule.action || 'show';
				var targetEl = form.querySelector( '[name="' + rule.target + '"]' );
				if ( ! targetEl ) {
					targetEl = form.querySelector( '[name="' + rule.target + '[]"]' );
				}
				var fieldWrap = targetEl ? targetEl.closest( '.agentic-form-field' ) : null;
				if ( ! fieldWrap ) {
					return;
				}

				if ( action === 'show' ) {
					fieldWrap.setAttribute( 'data-condition-hidden', match ? 'false' : 'true' );
				} else if ( action === 'hide' ) {
					fieldWrap.setAttribute( 'data-condition-hidden', match ? 'true' : 'false' );
				}
			} );
		}

		form.addEventListener( 'change', evaluate );
		form.addEventListener( 'input', evaluate );
		evaluate();
	}

	function handleFormSubmit( e ) {
		e.preventDefault();

		var form      = e.currentTarget;
		var wrap      = form.closest( '.agentic-form-wrap' );
		var submitUrl = form.dataset.submitUrl;
		var nonce     = form.dataset.nonce;
		var btn       = form.querySelector( '.agentic-form-btn' );
		var spinner   = form.querySelector( '.agentic-form-spinner' );
		var successEl = wrap ? wrap.querySelector( '.agentic-form-success' ) : null;
		var errorsEl  = wrap ? wrap.querySelector( '.agentic-form-errors' )  : null;

		if ( ! submitUrl ) {
			return;
		}

		// Reset state.
		if ( errorsEl ) {
			errorsEl.style.display = 'none';
			errorsEl.textContent   = '';
		}

		// Disable submit + show spinner.
		if ( btn )     { btn.disabled = true; }
		if ( spinner ) { spinner.style.display = 'inline-block'; }

		// Collect form data.
		var formData = new FormData( form );
		var body     = {};
		formData.forEach( function ( value, key ) {
			if ( Object.prototype.hasOwnProperty.call( body, key ) ) {
				// Multiple values (checkboxes) — convert to array.
				if ( ! Array.isArray( body[ key ] ) ) {
					body[ key ] = [ body[ key ] ];
				}
				body[ key ].push( value );
			} else {
				body[ key ] = value;
			}
		} );

		// Include Turnstile token if present.
		var turnstileInput = form.querySelector( '[name="cf-turnstile-response"]' );
		if ( turnstileInput && turnstileInput.value ) {
			body['cf-turnstile-response'] = turnstileInput.value;
		}

		fetch( submitUrl, {
			method:  'POST',
			headers: {
				'Content-Type':  'application/json',
				'X-WP-Nonce':    nonce,
			},
			body: JSON.stringify( body ),
		} )
		.then( function ( res ) {
			return res.json().then( function ( data ) {
				return { ok: res.ok, status: res.status, data: data };
			} );
		} )
		.then( function ( r ) {
			if ( r.ok && r.data.success ) {
				// Redirect if the server says so.
				if ( r.data.redirect && r.data.redirect !== '' ) {
					window.location.href = r.data.redirect;
					return;
				}

				// Hide the form, show success message.
				form.style.display = 'none';
				if ( successEl ) {
					if ( r.data.message ) {
						successEl.textContent = r.data.message;
					}
					successEl.style.display = 'block';
				}
			} else {
				// Show validation / server errors.
				var message = '';
				if ( r.data && r.data.errors && Array.isArray( r.data.errors ) ) {
					message = r.data.errors.join( '\n' );
				} else if ( r.data && r.data.message ) {
					message = r.data.message;
				} else {
					message = 'An error occurred. Please try again.';
				}

				if ( errorsEl ) {
					errorsEl.textContent   = message;
					errorsEl.style.display = 'block';
				}

				// Reset Turnstile widget on error.
				if ( typeof turnstile !== 'undefined' ) {
					var turnstileEl = form.querySelector( '.cf-turnstile' );
					if ( turnstileEl ) {
						turnstile.reset( turnstileEl );
					}
				}
			}
		} )
		.catch( function () {
			if ( errorsEl ) {
				errorsEl.textContent   = 'A network error occurred. Please check your connection and try again.';
				errorsEl.style.display = 'block';
			}
		} )
		.finally( function () {
			if ( btn )     { btn.disabled = false; }
			if ( spinner ) { spinner.style.display = 'none'; }
		} );
	}

	function init() {
		document.querySelectorAll( '.agentic-form' ).forEach( function ( form ) {
			form.addEventListener( 'submit', handleFormSubmit );
			initConditionalLogic( form );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
