/**
 * Shared AgentStructor admin chrome, printed from admin_footer on every
 * Agentic admin page: places the Advanced-mode secondary nav rail and the
 * site-wide Basic/Advanced switch at the top of .wrap, wires the switch, and
 * appends the page footer links on classic (non-React) pages.
 *
 * Data: window.agenticAdminChrome = { restUrl, nonce, footerHtml }.
 * footerHtml is server-built, escaped markup (build_admin_footer_html()).
 */
( function () {
	'use strict';
	var cfg = window.agenticAdminChrome || {};

	function retry( fn ) {
		if ( ! fn() ) {
			document.addEventListener( 'DOMContentLoaded', fn );
			window.setTimeout( fn, 0 );
			window.setTimeout( fn, 400 );
		}
	}

	function ensureWrap() {
		var wrap = document.querySelector( '#wpbody-content .wrap' );
		if ( ! wrap ) {
			var root = document.getElementById( 'agentic-dashboard-app-root' );
			if ( ! root || ! root.parentNode ) {
				return null;
			}
			wrap = document.createElement( 'div' );
			wrap.className = 'wrap agentic-admin';
			root.parentNode.insertBefore( wrap, root );
			wrap.appendChild( root );
		}
		return wrap;
	}

	// ── Secondary nav rail (Advanced mode) ──────────────────────────────
	function agenticPlaceSecondaryNav() {
		var nav = document.getElementById( 'agentic-secondary-nav' );
		if ( ! nav || nav.classList.contains( 'is-placed' ) ) {
			return true;
		}
		var wrap = ensureWrap();
		if ( ! wrap ) {
			return false;
		}
		wrap.insertBefore( nav, wrap.firstChild );
		wrap.classList.add( 'agentic-has-secondary-nav' );
		nav.classList.add( 'is-placed' );
		return true;
	}
	retry( agenticPlaceSecondaryNav );

	// ── Site-wide Basic/Advanced switch ─────────────────────────────────
	// Placed after the rail so this insertBefore(firstChild) lands above it.
	function agenticPlaceGlobalModeSwitch() {
		var el = document.getElementById( 'agentic-global-mode-switch' );
		if ( ! el || el.classList.contains( 'is-placed' ) ) {
			return true;
		}
		var wrap = ensureWrap();
		if ( ! wrap ) {
			return false;
		}
		wrap.insertBefore( el, wrap.firstChild );
		wrap.classList.add( 'agentic-has-global-mode' );
		el.classList.add( 'is-placed' );
		return true;
	}

	function initGlobalModeSwitch() {
		var toggle = document.getElementById( 'agentic-global-mode-switch' );
		if ( ! toggle ) {
			return;
		}
		retry( agenticPlaceGlobalModeSwitch );

		// One Basic/Advanced switch per page: screens that render their own
		// per-screen toggle (Agents, Tools, Approvals, some Settings tabs,
		// ...) keep that one, and the site-wide switch steps aside there.
		// It stays on every other screen (e.g. the Dashboard). Those
		// toggles may be rendered later by React, so watch briefly.
		function agenticHasScreenToggle() {
			var el = document.getElementById( 'agentic-global-mode-switch' );
			var found = document.querySelectorAll( '.agentic-screen-mode-toggle' );
			for ( var i = 0; i < found.length; i++ ) {
				if ( ! el || ! el.contains( found[ i ] ) ) {
					return true;
				}
			}
			return false;
		}
		function agenticYieldToScreenToggle() {
			var el = document.getElementById( 'agentic-global-mode-switch' );
			if ( el && agenticHasScreenToggle() ) {
				el.hidden = true;
				return true;
			}
			return false;
		}
		if ( ! agenticYieldToScreenToggle() && window.MutationObserver ) {
			var agenticModeObserver = new MutationObserver( function () {
				if ( agenticYieldToScreenToggle() ) {
					agenticModeObserver.disconnect();
				}
			} );
			agenticModeObserver.observe( document.body, { childList: true, subtree: true } );
			window.setTimeout( function () {
				agenticModeObserver.disconnect();
			}, 10000 );
		}

		function enableAll() {
			Array.prototype.forEach.call( toggle.querySelectorAll( 'button[data-mode]' ), function ( b ) {
				b.disabled = false;
			} );
		}

		Array.prototype.forEach.call( toggle.querySelectorAll( 'button[data-mode]' ), function ( btn ) {
			btn.addEventListener( 'click', function () {
				if ( btn.disabled || btn.classList.contains( 'button-primary' ) ) {
					return;
				}
				Array.prototype.forEach.call( toggle.querySelectorAll( 'button[data-mode]' ), function ( b ) {
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
						action_name: 'set_ui_mode',
						mode: btn.getAttribute( 'data-mode' )
					} )
				} ).then( function ( res ) {
					if ( ! res.ok ) {
						enableAll();
						return;
					}
					window.location.reload();
				} ).catch( enableAll );
			} );
		} );
	}
	initGlobalModeSwitch();

	// ── Page footer links ───────────────────────────────────────────────
	function agenticAppendPageFooter() {
		if ( ! cfg.footerHtml || document.querySelector( '.agentic-page-footer' ) ) {
			return true;
		}
		// React admin-pages (and similar) render their own footer from localized data.
		if ( document.getElementById( 'agentic-admin-pages-root' )
			|| document.getElementById( 'agentic-settings-app-root' )
			|| document.getElementById( 'agentic-dashboard-app-root' ) ) {
			return true;
		}
		var wrap = document.querySelector( '.wrap' );
		if ( ! wrap ) {
			return false;
		}
		var footerDiv = document.createElement( 'div' );
		footerDiv.innerHTML = cfg.footerHtml;
		if ( footerDiv.firstChild ) {
			wrap.appendChild( footerDiv.firstChild );
		}
		return true;
	}
	// Classic pages: DOM ready. React shells skip inject (component owns footer).
	retry( agenticAppendPageFooter );
} )();
