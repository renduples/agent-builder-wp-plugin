/**
 * Settings > Agents tab: per-agent provider -> model dropdowns.
 *
 * Data: window.agenticSettingsTabAgents { providerModels, defaultProvider, defaultModel }.
 */
(function () {
	var agenticData            = window.agenticSettingsTabAgents || {};
	var agenticProviderModels  = agenticData.providerModels || {};
	var agenticDefaultProvider = agenticData.defaultProvider || '';
	var agenticDefaultModel    = agenticData.defaultModel || '';

	function agenticPopulateModels( providerSel ) {
		var row    = providerSel.closest( 'tr' );
		var slug   = providerSel.value || agenticDefaultProvider;
		var models = agenticProviderModels[ slug ] || [];

		[
			{ sel: row.querySelector( '.agentic-agent-model-select' ),        firstLabel: agenticDefaultModel + ' (default)' },
			{ sel: row.querySelector( '.agentic-agent-vision-model-select' ), firstLabel: 'Same as chat' },
		].forEach( function( cfg ) {
			if ( ! cfg.sel ) return;
			var saved = cfg.sel.getAttribute( 'data-saved-model' ) || '';
			var list  = models.slice();
			if ( saved && list.indexOf( saved ) === -1 ) list.unshift( saved );
			var options = '<option value="">' + cfg.firstLabel + '</option>';
			list.forEach( function( m ) {
				options += '<option value="' + m + '"' + ( m === saved ? ' selected' : '' ) + '>' + m + '</option>';
			} );
			cfg.sel.innerHTML = options;
			cfg.sel.setAttribute( 'data-saved-model', '' );
		} );
	}

	document.querySelectorAll( '.agentic-agent-provider-select' ).forEach( function( sel ) {
		sel.addEventListener( 'change', function () { agenticPopulateModels( this ); } );
	} );
}());
