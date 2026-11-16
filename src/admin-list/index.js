/**
 * Generic React list shell for Agents / Tools / Skills / Approvals pages.
 * Renders a WP components Card around server-provided rows (localized).
 */
import { createRoot, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, SearchControl } from '@wordpress/components';
import { AdminPage, Panel } from '../shared/components';

function AdminListApp() {
	const cfg = window.agenticAdminList || {};
	const [ q, setQ ] = useState( '' );
	const rows = useMemo( () => {
		const all = Array.isArray( cfg.rows ) ? cfg.rows : [];
		const needle = q.trim().toLowerCase();
		if ( ! needle ) {
			return all;
		}
		return all.filter( ( r ) => {
			const hay = [ r.title, r.subtitle, r.status, r.id ]
				.filter( Boolean )
				.join( ' ' )
				.toLowerCase();
			return hay.includes( needle );
		} );
	}, [ cfg.rows, q ] );

	return (
		<div className="wrap agentic-admin">
			<AdminPage
				title={ cfg.title || __( 'Items', 'siteloom' ) }
				description={ cfg.description || '' }
				wide
				actions={
					cfg.primaryAction ? (
						<Button variant="primary" href={ cfg.primaryAction.url }>
							{ cfg.primaryAction.label }
						</Button>
					) : null
				}
			>
				<Panel title={ cfg.panelTitle || cfg.title }>
					{ cfg.searchable !== false && (
						<div style={ { marginBottom: 16 } }>
							<SearchControl
								value={ q }
								onChange={ setQ }
								placeholder={ __(
									'Search…',
									'siteloom'
								) }
								__nextHasNoMarginBottom
							/>
						</div>
					) }
					{ ! rows.length ? (
						<p className="agentic-react-muted">
							{ cfg.emptyText ||
								__( 'No items found.', 'siteloom' ) }
						</p>
					) : (
						<div className="agentic-react-table-wrap">
							<table className="agentic-react-table">
								<thead>
									<tr>
										{ ( cfg.columns || [] ).map( ( col ) => (
											<th key={ col.key }>{ col.label }</th>
										) ) }
									</tr>
								</thead>
								<tbody>
									{ rows.map( ( row ) => (
										<tr key={ row.id }>
											{ ( cfg.columns || [] ).map(
												( col ) => (
													<td key={ col.key }>
														{ col.key ===
														'statusLed' ? (
															<span
																className={
																	'agentic-react-led' +
																	( row.active
																		? ' is-on'
																		: '' )
																}
															/>
														) : col.key ===
														  'actions' ? (
															row.actions?.map(
																( act, i ) => (
																	<span
																		key={
																			act.url ||
																			i
																		}
																	>
																		{ i >
																			0 &&
																			' · ' }
																		<a
																			href={
																				act.url
																			}
																		>
																			{
																				act.label
																			}
																		</a>
																	</span>
																)
															)
														) : (
															row[ col.key ]
														) }
													</td>
												)
											) }
										</tr>
									) ) }
								</tbody>
							</table>
						</div>
					) }
				</Panel>
			</AdminPage>
		</div>
	);
}

document.addEventListener( 'DOMContentLoaded', () => {
	const el = document.getElementById( 'agentic-admin-list-root' );
	if ( el ) {
		createRoot( el ).render( <AdminListApp /> );
	}
} );
