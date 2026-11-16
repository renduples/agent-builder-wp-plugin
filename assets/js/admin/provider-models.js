/**
 * Provider edit form: load the provider's model list into the default-model
 * field (and the Available Models textarea), with a manual refresh button.
 *
 * Data: window.agenticProviderModels = { ajaxUrl, slug, nonce, autoFetch, i18n }.
 */
( function () {
	'use strict';
	var cfg       = window.agenticProviderModels || {};
	var i18n      = cfg.i18n || {};
	var slug      = cfg.slug;
	var nonce     = cfg.nonce;
	var autoFetch = !! cfg.autoFetch;

	function getField() { return document.getElementById( 'provider_default_model' ); }

	function setLoading( on ) {
		var spinner = document.getElementById( 'agentic-model-loading' );
		var field   = getField();
		if ( spinner ) spinner.style.display = on ? 'inline' : 'none';
		if ( field )   field.disabled = on;
	}

	function setStatus( msg, isError ) {
		var el = document.getElementById( 'agentic-model-status' );
		if ( ! el ) return;
		el.textContent   = msg;
		el.style.color   = isError ? '#b32d2e' : '#646970';
		el.style.display = msg ? 'block' : 'none';
	}

	function populateSelect( models, currentVal ) {
		var field = getField();
		if ( ! field ) return;
		if ( currentVal && models.indexOf( currentVal ) < 0 ) models.unshift( currentVal );
		// Sync the Available Models textarea so saving the form persists the fetched list.
		var ta = document.getElementById( 'provider_models' );
		if ( ta ) ta.value = models.join( '\n' );
		var sel  = document.createElement( 'select' );
		sel.id   = 'provider_default_model';
		sel.name = 'provider_default_model';
		models.forEach( function ( m ) {
			var opt         = document.createElement( 'option' );
			opt.value       = m;
			opt.textContent = m;
			if ( m === currentVal ) opt.selected = true;
			sel.appendChild( opt );
		} );
		field.parentNode.replaceChild( sel, field );
	}

	function fetchModels( force ) {
		var field      = getField();
		var currentVal = field ? field.value : '';
		setLoading( true );
		setStatus( '' );
		var data = new FormData();
		data.append( 'action',   'agentic_fetch_provider_models' );
		data.append( 'nonce',    nonce );
		data.append( 'provider', slug );
		if ( force ) data.append( 'force', '1' );
		fetch( cfg.ajaxUrl || window.ajaxurl, { method: 'POST', body: data } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( res ) {
				setLoading( false );
				if ( res.success && res.data && res.data.models && res.data.models.length ) {
					populateSelect( res.data.models, currentVal );
					setStatus( res.data.cached ? i18n.cached : '' );
				} else {
					setStatus( ( res.data && res.data.message ) || i18n.loadFailed, true );
				}
			} )
			.catch( function () {
				setLoading( false );
				setStatus( i18n.requestFailed, true );
			} );
	}

	function init() {
		var btn = document.getElementById( 'agentic-model-refresh' );
		if ( btn ) btn.addEventListener( 'click', function ( e ) { e.preventDefault(); fetchModels( true ); } );
		if ( autoFetch ) fetchModels( false );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
