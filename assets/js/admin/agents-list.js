/**
 * Agents list (Advanced mode): bulk-action helpers and one-click agent updates.
 *
 * Data: window.agenticAgentsList = { ajaxUrl, hasUpdates, i18n }.
 * agentic_confirm_bulk() stays global: the bulk "Apply" buttons call it from
 * their onclick attribute.
 */
/* exported agentic_confirm_bulk */
var agenticAgentsListCfg = window.agenticAgentsList || {};

( function () {
	// Keep top/bottom bulk selects in sync.
	var selTop    = document.getElementById( 'bulk-action-selector-top' );
	var selBottom = document.getElementById( 'bulk-action-selector-bottom' );
	if ( selTop && selBottom ) {
		selTop.addEventListener( 'change', function () { selBottom.value = selTop.value; } );
		selBottom.addEventListener( 'change', function () { selTop.value = selBottom.value; } );
	}

	// Keep top/bottom checkboxes in sync.
	var cbAll1 = document.getElementById( 'cb-select-all-1' );
	var cbAll2 = document.getElementById( 'cb-select-all-2' );
	function syncCheckAll( from, to ) {
		if ( from && to ) {
			from.addEventListener( 'change', function () {
				to.checked = from.checked;
				document.querySelectorAll( '#the-list input[type="checkbox"]' ).forEach( function ( cb ) {
					cb.checked = from.checked;
				} );
			} );
		}
	}
	syncCheckAll( cbAll1, cbAll2 );
	syncCheckAll( cbAll2, cbAll1 );
} )();

function agentic_confirm_bulk( btn ) { // eslint-disable-line no-unused-vars, camelcase
	var i18n = agenticAgentsListCfg.i18n || {};
	var sel  = document.getElementById( 'bulk-action-selector-top' );
	if ( ! sel || sel.value === '-1' ) {
		agenticUI.toast( i18n.selectAction, 'warning' );
		return false;
	}
	var checked = document.querySelectorAll( '#the-list input[type="checkbox"]:checked' );
	if ( ! checked.length ) {
		agenticUI.toast( i18n.selectAgent, 'warning' );
		return false;
	}
	if ( sel.value === 'delete' ) {
		// Block the synchronous submit; re-submit programmatically once confirmed.
		agenticUI.confirm( i18n.confirmDelete, { danger: true, confirmText: i18n.delete } ).then( function ( ok ) {
			if ( ok ) {
				btn.closest( 'form' ).submit();
			}
		} );
		return false;
	}
	return true;
}

( function () {
	if ( ! agenticAgentsListCfg.hasUpdates ) {
		return;
	}
	var i18n = agenticAgentsListCfg.i18n || {};
	// One-click agent update handler.
	document.querySelectorAll( '.agentic-update-now' ).forEach( function ( link ) {
		link.addEventListener( 'click', function ( e ) {
			e.preventDefault();

			var slug   = this.dataset.slug;
			var nonce  = this.dataset.nonce;
			var zipUrl = this.dataset.url;
			var row    = document.getElementById( slug + '-update' );
			var self   = this;

			self.textContent = i18n.updating;
			self.style.pointerEvents = 'none';

			var body = new URLSearchParams( {
				action:    'agentic_agent_update',
				slug:      slug,
				zip_url:   zipUrl,
				_wpnonce:  nonce,
			} );

			fetch( agenticAgentsListCfg.ajaxUrl || window.ajaxurl, {
				method:  'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body:    body.toString(),
			} )
			.then( function ( r ) { return r.json(); } )
			.then( function ( data ) {
				if ( data.success ) {
					if ( row ) {
						row.innerHTML =
							'<td colspan="6"><div class="update-message notice inline notice-success notice-alt">' +
							'<p>' + data.data.message + '</p></div></td>';
					}
					// Reload after a short delay so the updated version number shows.
					setTimeout( function () { location.reload(); }, 1500 );
				} else {
					agenticUI.toast( i18n.updateFailed + ( data.data || i18n.unknownError ), 'error' );
					self.textContent = i18n.updateNow;
					self.style.pointerEvents = '';
				}
			} )
			.catch( function () {
				agenticUI.toast( i18n.networkError, 'error' );
				self.textContent = i18n.updateNow;
				self.style.pointerEvents = '';
			} );
		} );
	} );
}() );
