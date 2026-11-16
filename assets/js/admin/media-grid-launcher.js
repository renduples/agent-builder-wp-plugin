/**
 * Media Library Grid View: insert the "Ask AI about media" launcher into the
 * Backbone-rendered toolbar, and re-insert it whenever the toolbar is
 * re-rendered (a MutationObserver instead of relying on timing).
 *
 * Data: window.agenticMediaLauncher = { html } — server-built, escaped markup
 * (Admin_Surfaces::media_launcher_html()).
 */
( function () {
	'use strict';
	var cfg          = window.agenticMediaLauncher || {};
	var launcherHtml = cfg.html || '';
	if ( ! launcherHtml ) {
		return;
	}

	function insert() {
		var toolbar = document.querySelector( '.media-toolbar-secondary' );
		if ( ! toolbar || toolbar.querySelector( '.agentic-launcher-wrap' ) ) {
			return;
		}
		var wrap = document.createElement( 'div' );
		wrap.innerHTML = launcherHtml;
		toolbar.appendChild( wrap.firstElementChild );
	}

	insert();

	// Observe body, not the toolbar node itself — Backbone may replace the
	// element outright rather than just clearing its contents. insert()
	// re-queries the toolbar fresh each time.
	new MutationObserver( insert ).observe( document.body, { childList: true, subtree: true } );
} )();
