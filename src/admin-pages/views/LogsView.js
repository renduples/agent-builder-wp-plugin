import { useMemo, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import { Button, Notice, SearchControl } from '@wordpress/components';
import TabBar from './TabBar';

function LogsView( { data, reload } ) {
	const [ q, setQ ] = useState( '' );
	const [ kind, setKind ] = useState( 'all' );
	const period = data.period || 'week';
	const stats = data.stats || {};

	const kindCounts = useMemo( () => {
		const c = { all: 0, tool: 0, approval: 0, chat: 0, settings: 0, other: 0, security: 0 };
		( data.rows || [] ).forEach( ( r ) => {
			const k = r.kind || 'other';
			c.all += 1;
			if ( c[ k ] !== undefined ) {
				c[ k ] += 1;
			} else {
				c.other += 1;
			}
		} );
		return c;
	}, [ data.rows ] );

	const rows = useMemo( () => {
		let list = data.rows || [];
		if ( kind !== 'all' ) {
			list = list.filter( ( r ) => ( r.kind || 'other' ) === kind );
		}
		const n = q.trim().toLowerCase();
		if ( ! n ) {
			return list;
		}
		return list.filter( ( r ) =>
			[ r.title, r.subtitle, r.detail, r.raw_action, r.kind ]
				.join( ' ' )
				.toLowerCase()
				.includes( n )
		);
	}, [ data.rows, kind, q ] );

	const setPeriod = ( p ) => {
		const url = new URL( window.location.href );
		url.searchParams.set( 'period', p );
		window.history.replaceState( {}, '', url.toString() );
		reload( { period: p } );
	};

	return (
		<>
			{ /* data.description already renders once under the page <h1>
			 * (see AdminPage in shared/components.js) — repeating it here
			 * as a lead paragraph duplicated the same sentence twice in a
			 * row on this screen. */ }
			<p className="agentic-react-muted" style={ { marginTop: 0 } }>
				{ __(
					'This is a friendly activity feed. Technical names stay in Advanced detail when useful.',
					'agent-builder'
				) }
			</p>

			{ data.tab === 'audit' && data.integrity && (
				<p className="agentic-react-muted">
					{ __( 'Log integrity:', 'agent-builder' ) }{ ' ' }
					<span
						className={
							'agentic-react-badge' +
							( data.integrity.valid ? '' : ' agentic-react-badge--danger' )
						}
						title={
							data.integrity.valid
								? sprintf(
										/* translators: %d: number of log entries checked */
										__( '%d entries checked, chain intact.', 'agent-builder' ),
										data.integrity.checked || 0
								  )
								: sprintf(
										/* translators: %d: id of the first entry where the hash chain broke */
										__( 'Chain broken at entry #%d — an entry was edited or deleted after the fact.', 'agent-builder' ),
										data.integrity.broken_at_id || 0
								  )
						}
					>
						{ data.integrity.valid
							? __( 'Verified', 'agent-builder' )
							: __( 'Tampering detected', 'agent-builder' ) }
					</span>
				</p>
			) }

			{ /* Summary metrics */ }
			<div className="agentic-react-activity-stats">
				<div className="agentic-react-activity-stat">
					<span className="agentic-react-activity-stat__val">
						{ stats.total ?? rows.length }
					</span>
					<span className="agentic-react-activity-stat__lbl">
						{ __( 'Events', 'agent-builder' ) }
					</span>
				</div>
				{ data.tab === 'audit' && (
					<>
						<div className="agentic-react-activity-stat">
							<span className="agentic-react-activity-stat__val">
								{ stats.tools ?? 0 }
							</span>
							<span className="agentic-react-activity-stat__lbl">
								{ __( 'Tools used', 'agent-builder' ) }
							</span>
						</div>
						<div className="agentic-react-activity-stat">
							<span className="agentic-react-activity-stat__val">
								{ stats.approvals ?? 0 }
							</span>
							<span className="agentic-react-activity-stat__lbl">
								{ __( 'Approvals', 'agent-builder' ) }
							</span>
						</div>
						{ Number( stats.tokens ) > 0 && (
							<div className="agentic-react-activity-stat">
								<span className="agentic-react-activity-stat__val">
									{ Number( stats.tokens ).toLocaleString() }
								</span>
								<span className="agentic-react-activity-stat__lbl">
									{ __( 'Tokens', 'agent-builder' ) }
								</span>
							</div>
						) }
					</>
				) }
			</div>

			<TabBar tabs={ data.tabs } active={ data.tab } />

			{ /* Period */ }
			<nav
				className="agentic-react-risk-filters"
				aria-label={ __( 'Time period', 'agent-builder' ) }
			>
				{ ( data.period_options || [] ).map( ( p ) => (
					<button
						key={ p.id }
						type="button"
						className={
							'agentic-react-risk-filters__btn' +
							( period === p.id ? ' is-active' : '' )
						}
						onClick={ () => setPeriod( p.id ) }
						aria-pressed={ period === p.id }
					>
						{ p.label }
					</button>
				) ) }
			</nav>

			{ data.tab === 'audit' && (
				<nav
					className="agentic-react-risk-filters"
					aria-label={ __( 'Activity type', 'agent-builder' ) }
				>
					{ ( data.kind_filters || [] ).map( ( f ) => {
						const count = kindCounts[ f.id ] ?? 0;
						if ( f.id !== 'all' && f.id !== kind && count === 0 ) {
							return null;
						}
						return (
							<button
								key={ f.id }
								type="button"
								className={
									'agentic-react-risk-filters__btn' +
									( kind === f.id ? ' is-active' : '' )
								}
								onClick={ () => setKind( f.id ) }
								aria-pressed={ kind === f.id }
							>
								{ f.label }
								<span className="agentic-react-risk-filters__count">
									{ count }
								</span>
							</button>
						);
					} ) }
				</nav>
			) }

			<div className="agentic-react-tools-toolbar">
				<SearchControl
					value={ q }
					onChange={ setQ }
					placeholder={ __( 'Search activity…', 'agent-builder' ) }
					__nextHasNoMarginBottom
				/>
				<span className="agentic-react-muted">
					{ rows.length === 1
						? __( '1 event', 'agent-builder' )
						: `${ rows.length } ${ __( 'events', 'agent-builder' ) }` }
				</span>
				{ data.export_url && (
					<Button
						variant="secondary"
						href={ data.export_url }
						title={ __(
							'Download this log as a CSV file — handy to attach when emailing support about an issue.',
							'agent-builder'
						) }
					>
						{ __( 'Export CSV', 'agent-builder' ) }
					</Button>
				) }
			</div>

			{ ! rows.length ? (
				<div className="agentic-react-approvals-empty">
					<p>
						<strong>
							{ __( 'Nothing here yet', 'agent-builder' ) }
						</strong>
					</p>
					<p className="agentic-react-muted">
						{ q || kind !== 'all'
							? __(
									'No events match this filter. Try another period or clear search.',
									'agent-builder'
							  )
							: __(
									'When agents chat or use tools, their activity will show up here.',
									'agent-builder'
							  ) }
					</p>
				</div>
			) : (
				<ul className="agentic-react-activity-timeline">
					{ rows.map( ( r ) => (
						<li
							key={ r.id || r.when + r.title }
							className={
								'agentic-react-activity-item kind-' +
								( r.kind || 'other' )
							}
						>
							<span
								className="agentic-react-activity-item__icon"
								aria-hidden="true"
							>
								{ r.icon || '•' }
							</span>
							<div className="agentic-react-activity-item__body">
								<div className="agentic-react-activity-item__title">
									<strong>{ r.title }</strong>
									{ r.kind && (
										<span
											className={
												'agentic-react-activity-kind agentic-react-activity-kind--' +
												r.kind
											}
										>
											{ r.kind }
										</span>
									) }
								</div>
								<div className="agentic-react-muted">
									{ r.subtitle ? `${ r.subtitle } · ` : '' }
									{ r.when_human || r.when }
								</div>
								{ r.detail && (
									<p className="agentic-react-activity-item__detail">
										{ r.detail }
									</p>
								) }
								{ data.is_advanced && r.raw_action && (
									<code className="agentic-react-activity-item__raw">
										{ r.raw_action }
									</code>
								) }
							</div>
						</li>
					) ) }
				</ul>
			) }
		</>
	);
}


export default LogsView;
