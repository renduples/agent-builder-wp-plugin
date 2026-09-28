import { useMemo, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	Modal,
	Notice,
	ToggleControl,
	SearchControl,
} from '@wordpress/components';
import { InfoTip } from '../../shared/components';
import { RISK_EXPLANATIONS } from '../constants';
import TabBar from './TabBar';

const HIGH_RISK_REASONS = {
	force_password_reset: __( 'can affect account access', 'agent-builder' ),
	wc_create_refund: __( 'moves money', 'agent-builder' ),
	delete_form: __( 'can permanently remove data', 'agent-builder' ),
	git_push: __( 'changes the deployed codebase', 'agent-builder' ),
	git_pull: __( 'changes the deployed codebase', 'agent-builder' ),
	git_commit: __( 'changes the deployed codebase', 'agent-builder' ),
};

function highRiskReason( toolId ) {
	return HIGH_RISK_REASONS[ toolId ] || RISK_EXPLANATIONS.high;
}

// Off→on gate for HIGH/EXTREME. Returns null when the toggle should proceed
// immediately (turning off, or low/medium/none). Consult before the optimistic
// toggle+fetch so the switch never flashes on.
function toolEnableGate( row, enabling ) {
	if ( ! enabling ) {
		return null;
	}
	const risk = ( row.risk_level || 'none' ).toLowerCase();
	if ( risk !== 'high' && risk !== 'extreme' ) {
		return null;
	}
	return {
		name: row.id,
		title: row.title || row.id,
		risk,
	};
}

function ToolRiskEnableModal( { gate, onCancel, onEnable } ) {
	if ( ! gate ) {
		return null;
	}
	const name = gate.title || gate.name;
	const isExtreme = gate.risk === 'extreme';

	return (
		<Modal
			title={
				isExtreme
					? __( 'This tool cannot be enabled', 'agent-builder' )
					: __( 'Enable high-risk tool?', 'agent-builder' )
			}
			onRequestClose={ onCancel }
			className="agentic-tool-risk-modal"
		>
			{ isExtreme ? (
				<>
					<p>
						<strong>{ name }</strong>{ ' ' }
						{ __( 'is marked Extreme Risk.', 'agent-builder' ) }
					</p>
					<p>
						{ __(
							'Extreme-risk tools are hidden from agents entirely and blocked from running, because they are too risky for normal use.',
							'agent-builder'
						) }
					</p>
					<div className="agentic-tool-risk-modal__actions">
						<Button variant="primary" onClick={ onCancel }>
							{ __( 'Close', 'agent-builder' ) }
						</Button>
					</div>
				</>
			) : (
				<>
					<p>
						<strong>{ name }</strong>{ ' ' }
						{ __(
							'can make a significant change to your site.',
							'agent-builder'
						) }
					</p>
					<p>
						{ sprintf(
							/* translators: %s: plain-language reason this tool is high-risk */
							__( 'Why this is high-risk: %s', 'agent-builder' ),
							highRiskReason( gate.name )
						) }
					</p>
					<p>
						{ __(
							'If an agent uses this tool later, the action will still wait for human review in the Approvals queue before it runs.',
							'agent-builder'
						) }
					</p>
					<p className="agentic-react-muted">
						{ __(
							'Enabling this tool makes it available to eligible agents. It does not run the tool immediately.',
							'agent-builder'
						) }
					</p>
					<div className="agentic-tool-risk-modal__actions">
						<Button variant="secondary" onClick={ onCancel }>
							{ __( 'Cancel', 'agent-builder' ) }
						</Button>
						<Button variant="primary" onClick={ onEnable }>
							{ __( 'Enable tool', 'agent-builder' ) }
						</Button>
					</div>
				</>
			) }
		</Modal>
	);
}


function ToolsBasicProfiles( { data, reload } ) {
	const [ busy, setBusy ] = useState( '' );
	const [ err, setErr ] = useState( '' );
	const [ ok, setOk ] = useState( '' );
	const profiles = data.profiles || [];
	const active = data.active_profile || '';

	const apply = ( profileId ) => {
		if ( busy ) {
			return;
		}
		setBusy( profileId );
		setErr( '' );
		setOk( '' );
		apiFetch( {
			path: 'agentic/v1/admin-page',
			method: 'POST',
			data: {
				action_name: 'apply_tools_profile',
				profile: profileId,
			},
		} )
			.then( ( res ) => {
				const r = res?.result || {};
				setOk(
					sprintf(
						/* translators: 1: enabled count, 2: disabled count */
						__(
							'Profile applied: %1$d tools on, %2$d tools off.',
							'agent-builder'
						),
						r.enabled ?? 0,
						r.disabled ?? 0
					)
				);
				reload( { silent: true } );
			} )
			.catch( ( e ) =>
				setErr(
					e.message ||
						__( 'Could not apply profile.', 'agent-builder' )
				)
			)
			.finally( () => setBusy( '' ) );
	};

	return (
		<div className="agentic-react-tools-basic">
			{ /* data.description already renders once under the page <h1>
			   (AdminPage in shared/components.js) — repeating it here duplicated
			   the same sentence twice in a row for every Basic-mode visitor. */ }
			<p className="agentic-react-muted">
				{ __(
					'These profiles control which tools every agent may use. Approvals still apply for riskier actions.',
					'agent-builder'
				) }{ ' ' }
				{ __(
					'Switch to Advanced (top right) for the full tool-by-tool list.',
					'agent-builder'
				) }
			</p>

			{ err && (
				<Notice status="error" isDismissible={ false }>
					{ err }
				</Notice>
			) }
			{ ok && (
				<Notice status="success" isDismissible>
					{ ok }
				</Notice>
			) }

			{ active === 'custom' && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'Tools were customized outside a profile. Choose a card below to reset to a simple safety level.',
						'agent-builder'
					) }
				</Notice>
			) }

			<div className="agentic-react-profile-grid">
				{ profiles.map( ( p ) => (
					<button
						key={ p.id }
						type="button"
						className={
							'agentic-react-profile-card' +
							( p.active || active === p.id
								? ' is-active'
								: '' ) +
							( busy === p.id ? ' is-busy' : '' )
						}
						disabled={ !! busy }
						onClick={ () => apply( p.id ) }
					>
						<span className="agentic-react-profile-card__icon">
							{ p.icon || '•' }
						</span>
						<span className="agentic-react-profile-card__label">
							{ p.label }
						</span>
						<span className="agentic-react-profile-card__summary">
							{ p.summary }
						</span>
						<span className="agentic-react-profile-card__detail">
							{ p.detail }
						</span>
						<span className="agentic-react-profile-card__risk">
							<span
								className={
									'agentic-react-risk agentic-react-risk--' +
									( p.max_risk || 'low' )
								}
							>
								{ sprintf(
									/* translators: %s: risk level */
									__( 'Up to %s risk', 'agent-builder' ),
									p.max_risk || 'low'
								) }
							</span>
						</span>
						{ ( p.active || active === p.id ) && (
							<span className="agentic-react-profile-card__badge">
								{ __( 'Current', 'agent-builder' ) }
							</span>
						) }
					</button>
				) ) }
			</div>

			<p className="agentic-react-muted agentic-react-tools-basic__stats">
				{ sprintf(
					/* translators: 1: enabled tools, 2: disabled tools, 3: max risk */
					__(
						'Right now: %1$d tools on, %2$d off · highest enabled risk: %3$s',
						'agent-builder'
					),
					data.enabled_count ?? 0,
					data.disabled_count ?? 0,
					data.enabled_max_risk || 'none'
				) }
			</p>
		</div>
	);
}

function ToolsView( { data, reload, patchData } ) {
	const [ q, setQ ] = useState( '' );
	const [ riskFilter, setRiskFilter ] = useState( 'all' );
	const [ busy, setBusy ] = useState( '' );
	const [ err, setErr ] = useState( '' );
	const [ gate, setGate ] = useState( null );
	const activeTab = data.tab || 'all';
	const isAdvanced = !! data.is_advanced;
	const categoryHref = ( slug ) => {
		const found = ( data.tabs || [] ).find( ( t ) => t.id === slug );
		return (
			found?.url ||
			`admin.php?page=agentic-tools&tab=${ encodeURIComponent( slug ) }`
		);
	};

	// Hooks must run unconditionally on every render (Rules of Hooks) — this
	// screen can now flip basic/advanced in place via ScreenModeToggle
	// without a full page reload (previously it always required navigating
	// away and back, which remounted the component fresh and masked any
	// hook ordered after the early return below).
	const riskCounts = useMemo( () => {
		const counts = {
			all: 0,
			none: 0,
			low: 0,
			medium: 0,
			high: 0,
			extreme: 0,
		};
		( data.rows || [] ).forEach( ( r ) => {
			const risk = ( r.risk_level || 'none' ).toLowerCase();
			counts.all += 1;
			if ( counts[ risk ] !== undefined ) {
				counts[ risk ] += 1;
			} else {
				counts.none += 1;
			}
		} );
		return counts;
	}, [ data.rows ] );

	const riskFilters = useMemo(
		() => [
			{ id: 'all', label: __( 'All risks', 'agent-builder' ) },
			{ id: 'none', label: __( 'None', 'agent-builder' ) },
			{ id: 'low', label: __( 'Low', 'agent-builder' ) },
			{ id: 'medium', label: __( 'Medium', 'agent-builder' ) },
			{ id: 'high', label: __( 'High', 'agent-builder' ) },
			{ id: 'extreme', label: __( 'Extreme', 'agent-builder' ) },
		],
		[]
	);

	const rows = useMemo( () => {
		let list = data.rows || [];
		if ( riskFilter !== 'all' ) {
			list = list.filter( ( r ) => {
				const risk = ( r.risk_level || 'none' ).toLowerCase();
				return risk === riskFilter;
			} );
		}
		const n = q.trim().toLowerCase();
		if ( ! n ) {
			return list;
		}
		return list.filter( ( r ) =>
			[
				r.title,
				r.subtitle,
				r.category,
				r.category_label,
				r.id,
				r.risk_level,
			]
				.join( ' ' )
				.toLowerCase()
				.includes( n )
		);
	}, [ data.rows, q, riskFilter ] );

	const setRowEnabled = ( name, enabled ) => {
		if ( typeof patchData === 'function' ) {
			patchData( ( d ) => ( {
				...d,
				rows: ( d.rows || [] ).map( ( r ) =>
					r.id === name ? { ...r, enabled } : r
				),
			} ) );
		}
	};

	const toggle = ( name, enabled ) => {
		setBusy( name );
		setErr( '' );
		// Optimistic: flip the switch without a full-page reload/spinner.
		setRowEnabled( name, enabled );
		apiFetch( {
			path: 'agentic/v1/admin-page',
			method: 'POST',
			data: {
				action_name: 'toggle_tool',
				name,
				enabled,
			},
		} )
			.then( () => {
				// Background refresh keeps counts/tabs in sync; silent = no jump.
				if ( typeof reload === 'function' ) {
					reload( { silent: true } );
				}
			} )
			.catch( ( e ) => {
				setRowEnabled( name, ! enabled );
				setErr(
					e.message || __( 'Toggle failed.', 'agent-builder' )
				);
			} )
			.finally( () => setBusy( '' ) );
	};

	const requestToggle = ( row, enabled ) => {
		const pending = toolEnableGate( row, enabled );
		if ( pending ) {
			setGate( pending );
			return;
		}
		toggle( row.id, enabled );
	};

	const riskModal = (
		<ToolRiskEnableModal
			gate={ gate }
			onCancel={ () => setGate( null ) }
			onEnable={ () => {
				const pending = gate;
				setGate( null );
				if ( pending && pending.risk !== 'extreme' ) {
					toggle( pending.name, true );
				}
			} }
		/>
	);

	// Basic Interface mode: simple ability profiles only (not the tool table).
	if ( ! isAdvanced ) {
		return (
			<>
				<ToolsBasicProfiles data={ data } reload={ reload } />
				{ riskModal }
			</>
		);
	}

	return (
		<>
			<TabBar tabs={ data.tabs } active={ activeTab } className="agentic-react-tabs--tools" />
			{ err && (
				<Notice status="error" isDismissible={ false }>
					{ err }
				</Notice>
			) }

			<>
				<p className="agentic-react-muted" style={ { marginTop: 0 } }>
					{ __(
						'You are in Advanced view (full tool list).',
						'agent-builder'
					) }
				</p>
				<div className="agentic-react-tools-toolbar">
					<SearchControl
						value={ q }
						onChange={ setQ }
						placeholder={ __( 'Search tools…', 'agent-builder' ) }
						__nextHasNoMarginBottom
					/>
					<span className="agentic-react-muted">
						{ rows.length === 1
							? __( '1 tool', 'agent-builder' )
							: `${ rows.length } ${ __(
									'tools',
									'agent-builder'
							  ) }` }
					</span>
				</div>
				<nav
						className="agentic-react-risk-filters"
						aria-label={ __( 'Filter by risk', 'agent-builder' ) }
					>
						{ riskFilters.map( ( f ) => {
							const count = riskCounts[ f.id ] ?? 0;
							// Hide empty risk levels except "all" and the active filter.
							if (
								f.id !== 'all' &&
								f.id !== riskFilter &&
								count === 0
							) {
								return null;
							}
							return (
								<button
									key={ f.id }
									type="button"
									className={
										'agentic-react-risk-filters__btn' +
										( f.id !== 'all'
											? ` agentic-react-risk--${ f.id }`
											: '' ) +
										( riskFilter === f.id
											? ' is-active'
											: '' )
									}
									onClick={ () => setRiskFilter( f.id ) }
									aria-pressed={ riskFilter === f.id }
								>
									{ f.label }
									<span className="agentic-react-risk-filters__count">
										{ count }
									</span>
								</button>
							);
						} ) }
					</nav>
					{ ! rows.length ? (
						<p className="agentic-react-muted">
							{ q || riskFilter !== 'all'
								? __(
										'No tools match this search or risk filter.',
										'agent-builder'
								  )
								: __(
										'No tools in this category.',
										'agent-builder'
								  ) }
						</p>
					) : (
						<div className="agentic-react-table-wrap">
							<table className="agentic-react-table">
								<thead>
									<tr>
										<th>
											{ __( 'Enabled', 'agent-builder' ) }
										</th>
										<th>{ __( 'Tool', 'agent-builder' ) }</th>
										{ activeTab === 'all' && (
											<th>
												{ __(
													'Category',
													'agent-builder'
												) }
											</th>
										) }
										<th>
											{ __( 'Risk', 'agent-builder' ) }
										</th>
										<th>
											{ __( 'Source', 'agent-builder' ) }
										</th>
									</tr>
								</thead>
								<tbody>
									{ rows.map( ( r ) => (
										<tr
											key={ r.id }
											style={ {
												opacity: r.enabled ? 1 : 0.6,
											} }
										>
											<td style={ { width: 90 } }>
												<ToggleControl
													label={
														<span className="screen-reader-text">
															{ sprintf(
																/* translators: %s: tool name */
																__(
																	'Enabled: %s',
																	'agent-builder'
																),
																r.title || r.id
															) }
														</span>
													}
													checked={ !! r.enabled }
													disabled={ busy === r.id }
													onChange={ ( v ) =>
														requestToggle( r, v )
													}
													__nextHasNoMarginBottom
												/>
											</td>
											<td>
												<strong>{ r.title }</strong>
												{ r.subtitle && (
													<div className="agentic-react-muted">
														{ r.subtitle.slice(
															0,
															140
														) }
														{ r.subtitle.length >
														140
															? '…'
															: '' }
													</div>
												) }
											</td>
											{ activeTab === 'all' && (
												<td>
													<a
														href={ categoryHref(
															r.category
														) }
													>
														{ r.category_label ||
															r.category }
													</a>
												</td>
											) }
											<td>
												<span
													className={
														'agentic-react-risk agentic-react-risk--' +
														( r.risk_level ||
															'none' )
													}
												>
													{ r.risk_level ||
														__(
															'none',
															'agent-builder'
														) }
												</span>
												<InfoTip
													text={
														RISK_EXPLANATIONS[
															r.risk_level ||
																'none'
														]
													}
												/>
											</td>
											<td>{ r.source }</td>
										</tr>
									) ) }
								</tbody>
							</table>
						</div>
					) }
				</>
			{ riskModal }
			</>
		);
	}


export default ToolsView;
