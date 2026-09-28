import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

function usePageData( page, tab ) {
	const [ state, setState ] = useState( {
		loading: true,
		error: '',
		data: null,
	} );
	// Extra query args (e.g. period for Activity) from the current admin URL.
	const extraQuery = useMemo( () => {
		try {
			const sp = new URLSearchParams( window.location.search );
			const period = sp.get( 'period' );
			return period ? { period } : {};
		} catch ( e ) {
			return {};
		}
	}, [] );

	const reload = useCallback(
		( opts = {} ) => {
			const silent = !! opts.silent;
			if ( ! silent ) {
				setState( ( s ) => ( { ...s, loading: true, error: '' } ) );
			}
			const period =
				opts.period ||
				extraQuery.period ||
				new URLSearchParams( window.location.search ).get( 'period' ) ||
				'';
			let path =
				`agentic/v1/admin-page?page=${ encodeURIComponent( page ) }` +
				( tab ? `&tab=${ encodeURIComponent( tab ) }` : '' );
			if ( period ) {
				path += `&period=${ encodeURIComponent( period ) }`;
			}
			apiFetch( { path } )
				.then( ( data ) =>
					setState( { loading: false, error: '', data } )
				)
				.catch( ( err ) =>
					setState( {
						loading: false,
						error:
							err.message ||
							__( 'Could not load page.', 'agent-builder' ),
						data: silent ? state.data : null,
					} )
				);
		},
		// state.data only used on silent failure fallback — omit from deps to keep reload stable.
		// eslint-disable-next-line react-hooks/exhaustive-deps
		[ page, tab, extraQuery.period ]
	);

	/** Patch page data in place (no loading flash). */
	const patchData = useCallback( ( updater ) => {
		setState( ( s ) => {
			if ( ! s.data ) {
				return s;
			}
			const next =
				typeof updater === 'function' ? updater( s.data ) : updater;
			return { ...s, data: next };
		} );
	}, [] );

	useEffect( () => {
		reload();
	}, [ reload ] );

	return [ state, reload, setState, patchData ];
}

export { usePageData };
