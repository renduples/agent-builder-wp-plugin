/**
 * Agent Builder — Plugin Deactivation Modal
 *
 * Intercepts the "Deactivate" link on the Plugins page and replaces it with
 * a two-step modal:
 *   Step 1: Data retention choice (keep / delete) — always shown.
 *   Step 2: Cancellation feedback form — shown only when the site owner has
 *           opted in to service consent (agenticDeactivate.canSendFeedback).
 *
 * After the user completes the modal the browser follows the original deactivation
 * URL (WordPress handles the actual deactivation).
 */
/* global agenticDeactivate */
( function () {
	'use strict';

	var link       = null;
	var modal      = null;
	var overlay    = null;
	var deactivateUrl = '';
	var i18n          = ( typeof agenticDeactivate !== 'undefined' && agenticDeactivate.i18n ) || {};

	/* Escape a translated string before it goes into innerHTML. */
	function escHtml( str ) {
		var div = document.createElement( 'div' );
		div.textContent = String( str );
		return div.innerHTML;
	}

	/* ─── Reasons shown in the cancellation feedback step ─── */
	var REASONS = [
		{ value: 'switching_plugin',  label: 'Switching to a different plugin' },
		{ value: 'missing_feature',   label: 'Missing a feature I need' },
		{ value: 'too_complex',       label: 'Too complex to set up' },
		{ value: 'pricing',           label: 'Price / cost concerns' },
		{ value: 'bugs',              label: 'Bugs or technical issues' },
		{ value: 'no_longer_needed',  label: 'No longer needed' },
		{ value: 'temporary',         label: 'Just deactivating temporarily' },
		{ value: 'other',             label: 'Other' },
	];

	/* ─── Build the modal DOM ─── */
	function buildModal() {
		overlay = document.createElement( 'div' );
		overlay.id = 'agentic-deactivate-overlay';
		overlay.setAttribute( 'role', 'dialog' );
		overlay.setAttribute( 'aria-modal', 'true' );
		overlay.setAttribute( 'aria-labelledby', 'agentic-deactivate-title' );

		modal = document.createElement( 'div' );
		modal.id = 'agentic-deactivate-modal';

		modal.innerHTML =
			'<div class="adm-header">' +
				'<span class="adm-icon">⚙️</span>' +
				'<h2 id="agentic-deactivate-title">Deactivate Agent Builder</h2>' +
				'<button class="adm-close" aria-label="Cancel">&times;</button>' +
			'</div>' +

			/* Step 1 — data retention */
			'<div class="adm-step" id="adm-step-1">' +
				'<p class="adm-lead">What should happen to your plugin data?</p>' +
				'<div class="adm-data-options">' +
					'<label class="adm-option adm-option--selected" id="adm-keep-label">' +
						'<input type="radio" name="adm_data" value="keep" checked>' +
						'<span class="adm-option-icon">🗄️</span>' +
						'<span class="adm-option-body">' +
							'<strong>Keep my data</strong>' +
							'<small>Conversation history, logs, API keys and settings are preserved. Everything will be here if you reactivate.</small>' +
						'</span>' +
					'</label>' +
					'<label class="adm-option" id="adm-delete-label">' +
						'<input type="radio" name="adm_data" value="delete">' +
						'<span class="adm-option-icon">🗑️</span>' +
						'<span class="adm-option-body">' +
							'<strong>Delete all plugin data</strong>' +
							'<small>' + escHtml( i18n.deleteDescription || 'When you delete the plugin, conversations, logs, API keys, settings, every Agent Builder database table (including the agents and skills you created or bought), automatic backups and knowledge files are permanently removed. Agent folders you built in wp-content/agentic-agents are kept.' ) + '</small>' +
						'</span>' +
					'</label>' +
				'</div>' +
				'<div class="adm-actions">' +
					'<button class="button adm-cancel-btn">Cancel</button>' +
					'<button class="button button-primary adm-next-btn" id="adm-step1-next">Continue</button>' +
				'</div>' +
			'</div>' +

			/* Step 2 — cancellation feedback (only when consent has been given) */
			( agenticDeactivate.canSendFeedback ?
			'<div class="adm-step adm-hidden" id="adm-step-2">' +
				'<p class="adm-lead">Help us improve — why are you deactivating? <em>(Optional)</em></p>' +
				'<div class="adm-reasons">' +
					REASONS.map( function ( r ) {
						return '<label class="adm-reason">' +
							'<input type="radio" name="adm_reason" value="' + r.value + '">' +
							'<span>' + r.label + '</span>' +
						'</label>';
					} ).join( '' ) +
				'</div>' +
				'<div class="adm-detail-wrap adm-hidden">' +
					'<textarea id="adm-reason-detail" class="adm-detail" rows="3" ' +
						'placeholder="Tell us more (optional)…" maxlength="500"></textarea>' +
				'</div>' +
				'<p class="adm-privacy-note">This is voluntary and opt-in — nothing is sent unless you click &ldquo;Submit &amp; deactivate.&rdquo; Doing so sends your selected reason and any notes above to agentic-plugin.com, used only to improve the plugin.</p>' +
				'<div class="adm-actions">' +
					'<button class="button adm-skip-btn">Skip &amp; deactivate</button>' +
					'<button class="button button-primary adm-submit-btn">Submit &amp; deactivate</button>' +
				'</div>' +
			'</div>'
			: '' ) +

			'<div class="adm-spinner adm-hidden"><span class="spinner is-active"></span></div>';

		overlay.appendChild( modal );
		document.body.appendChild( overlay );

		bindEvents();
	}

	/* ─── Event wiring ─── */
	function bindEvents() {
		/* Close on overlay click */
		overlay.addEventListener( 'click', function ( e ) {
			if ( e.target === overlay ) closeModal();
		} );

		/* Close / cancel buttons */
		modal.querySelector( '.adm-close' ).addEventListener( 'click', closeModal );
		modal.querySelectorAll( '.adm-cancel-btn' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', closeModal );
		} );

		/* Keyboard: Escape */
		document.addEventListener( 'keydown', onKeyDown );

		/* Option card highlighting */
		modal.querySelectorAll( 'input[name="adm_data"]' ).forEach( function ( radio ) {
			radio.addEventListener( 'change', function () {
				modal.querySelectorAll( 'label.adm-option' ).forEach( function ( l ) {
					l.classList.remove( 'adm-option--selected' );
				} );
				radio.closest( 'label' ).classList.add( 'adm-option--selected' );
			} );
		} );

		/* Step 1 → Step 2 (or deactivate) */
		var nextBtn = modal.querySelector( '#adm-step1-next' );
		if ( nextBtn ) {
			nextBtn.addEventListener( 'click', function () {
				var deleteChoice = modal.querySelector( 'input[name="adm_data"]:checked' );
				var deleteData   = deleteChoice && deleteChoice.value === 'delete';

				if ( deleteData ) {
					/* Confirm destructive action */
					agenticUI.confirm( i18n.deleteConfirm || 'When you delete the plugin, all Agent Builder data, including the agents and skills you created or bought and all automatic backups, will be permanently deleted. This cannot be undone. Continue?', { danger: true, confirmText: i18n.deleteConfirmButton || 'Delete everything' } ).then( function ( ok ) {
						if ( ! ok ) {
							return;
						}
						if ( agenticDeactivate.canSendFeedback ) {
							showStep( 2 );
						} else {
							doDeactivate( true, '', '' );
						}
					} );
					return;
				}

				if ( agenticDeactivate.canSendFeedback ) {
					showStep( 2 );
				} else {
					doDeactivate( deleteData, '', '' );
				}
			} );
		}

		if ( agenticDeactivate.canSendFeedback ) {
			/* Reason radio → show textarea for "other" */
			modal.querySelectorAll( 'input[name="adm_reason"]' ).forEach( function ( r ) {
				r.addEventListener( 'change', function () {
					modal.querySelectorAll( '.adm-reason' ).forEach( function ( l ) {
						l.classList.remove( 'adm-reason--selected' );
					} );
					r.closest( 'label' ).classList.add( 'adm-reason--selected' );

					var detailWrap = modal.querySelector( '.adm-detail-wrap' );
					if ( r.value === 'other' || r.value === 'missing_feature' || r.value === 'bugs' ) {
						detailWrap.classList.remove( 'adm-hidden' );
					} else {
						detailWrap.classList.add( 'adm-hidden' );
					}
				} );
			} );

			/* Skip → deactivate without feedback */
			modal.querySelector( '.adm-skip-btn' ).addEventListener( 'click', function () {
				var deleteData = getDeleteChoice();
				doDeactivate( deleteData, '', '' );
			} );

			/* Submit feedback → deactivate */
			modal.querySelector( '.adm-submit-btn' ).addEventListener( 'click', function () {
				var deleteData = getDeleteChoice();
				var reasonEl   = modal.querySelector( 'input[name="adm_reason"]:checked' );
				var reason     = reasonEl ? reasonEl.value : '';
				var detail     = ( modal.querySelector( '#adm-reason-detail' ) || {} ).value || '';
				doDeactivate( deleteData, reason, detail );
			} );
		}
	}

	function getDeleteChoice() {
		var el = modal.querySelector( 'input[name="adm_data"]:checked' );
		return el && el.value === 'delete';
	}

	function showStep( n ) {
		modal.querySelectorAll( '.adm-step' ).forEach( function ( s ) {
			s.classList.add( 'adm-hidden' );
		} );
		var step = modal.querySelector( '#adm-step-' + n );
		if ( step ) step.classList.remove( 'adm-hidden' );
	}

	function onKeyDown( e ) {
		if ( e.key === 'Escape' ) closeModal();
	}

	function closeModal() {
		overlay.classList.remove( 'adm-visible' );
		document.removeEventListener( 'keydown', onKeyDown );
		setTimeout( function () {
			if ( overlay.parentNode ) overlay.parentNode.removeChild( overlay );
		}, 200 );
	}

	/* ─── Core deactivation with optional AJAX ─── */
	function doDeactivate( deleteData, reason, detail ) {
		var spinner = modal.querySelector( '.adm-spinner' );
		spinner.classList.remove( 'adm-hidden' );
		modal.querySelectorAll( 'button' ).forEach( function ( b ) { b.disabled = true; } );

		var data = new URLSearchParams();
		data.append( 'action',  'agentic_plugin_deactivate' );
		data.append( 'nonce',   agenticDeactivate.nonce );
		data.append( 'delete_data', deleteData ? '1' : '0' );
		data.append( 'reason',  reason );
		data.append( 'detail',  detail );

		fetch( agenticDeactivate.ajaxUrl, {
			method:      'POST',
			credentials: 'same-origin',
			body:        data,
		} )
		.catch( function () { /* best-effort */ } )
		.finally( function () {
			/* Always follow through with WordPress deactivation */
			window.location.href = deactivateUrl;
		} );
	}

	/* ─── Intercept the Deactivate link ─── */
	function init() {
		var rows = document.querySelectorAll( 'tr[data-slug="agent-builder"]' );
		rows.forEach( function ( row ) {
			var deactivateLink = row.querySelector( 'a[href*="action=deactivate"]' );
			if ( ! deactivateLink ) return;

			link = deactivateLink;
			deactivateLink.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				deactivateUrl = deactivateLink.href;
				buildModal();
				/* Trigger the transition after the first paint so the CSS
				   animation fires. A timer (unlike requestAnimationFrame) still
				   runs when the browser throttles a background window. */
				setTimeout( function () {
					overlay.classList.add( 'adm-visible' );
					modal.querySelector( '.adm-close' ).focus();
				}, 20 );
			} );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
